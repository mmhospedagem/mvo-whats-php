<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\SchemaCodec;

/** Authenticated secondary encryption for votes, reactions, event responses and edits. */
final class AddonCrypto
{
    public static function key(string $secret, string $parentId, string $author, string $sender, string $type): string
    {
        if (strlen($secret) !== 32) throw new \InvalidArgumentException('Message secret must be 32 bytes');
        foreach ([$parentId, $author, $sender, $type] as $value) {
            if (trim($value) === '') throw new \InvalidArgumentException('Addon identity fields cannot be empty');
        }
        return hash_hkdf('sha256', $secret, 32, $parentId.$author.$sender.$type);
    }

    private static function aad(string $parentId, string $sender, string $type): string
    {
        return in_array($type, ['Poll Vote','Event Response'], true) ? $parentId."\0".$sender : '';
    }

    /**
     * @return array{encPayload:string,encIv:string} */
    public static function encrypt(string $secret, string $parentId, string $author, string $sender, string $type, string $plaintext, ?string $iv = null): array
    {
        $iv ??= random_bytes(12);
        if (strlen($iv) !== 12) throw new \InvalidArgumentException('Addon IV must be 12 bytes');
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', self::key($secret,$parentId,$author,$sender,$type), OPENSSL_RAW_DATA, $iv, $tag, self::aad($parentId,$sender,$type), 16);
        if ($cipher === false) throw new \RuntimeException('Addon encryption failed');
        return ['encPayload'=>$cipher.$tag,'encIv'=>$iv];
    }

    public static function decrypt(string $secret, string $parentId, string $author, string $sender, string $type, string $ciphertext, string $iv): string
    {
        if (strlen($iv) !== 12 || strlen($ciphertext) < 16) throw new \InvalidArgumentException('Invalid addon ciphertext or IV');
        $plain = openssl_decrypt(substr($ciphertext,0,-16), 'aes-256-gcm', self::key($secret,$parentId,$author,$sender,$type), OPENSSL_RAW_DATA, $iv, substr($ciphertext,-16), self::aad($parentId,$sender,$type));
        if ($plain === false) throw new \UnexpectedValueException('Addon authentication failed');
        return $plain;
    }

    /**
     * PN/LID migration can change which identity a peer used to encrypt a vote.
     * Try matching LID, matching phone, then the first as-received sender pair.
     * Every attempted identity must authenticate the same AES-GCM ciphertext.
     * @param list<string> $parentCandidates
     * @param list<string> $senderCandidates
     */
    public static function decryptWithSenderFallback(string $secret, string $parentId, array $parentCandidates, array $senderCandidates, string $type, string $ciphertext, string $iv): string
    {
        $pairs = self::senderPairs($parentCandidates,$senderCandidates);
        if ($pairs === []) throw new \InvalidArgumentException('Addon sender candidates cannot be empty');
        $last = null;
        foreach ($pairs as [$author,$sender]) {
            try { return self::decrypt($secret,$parentId,$author,$sender,$type,$ciphertext,$iv); }
            catch (\UnexpectedValueException $error) { $last = $error; }
        }
        throw $last;
    }

    /**
     * @param list<string> $parents
     * @param list<string> $senders
     * @return list<array{string,string}>
     */
    public static function senderPairs(array $parents, array $senders): array
    {
        $normalize = static function(array $candidates):array {
            $out = [];
            foreach ($candidates as $candidate) {
                if (!is_string($candidate) || trim($candidate) === '' || !str_contains($candidate,'@')) continue;
                $candidate = ContentBuilder::bare($candidate);
                if (preg_match('/@(g\.us|broadcast|newsletter)$/',$candidate) === 1) continue;
                if (!in_array($candidate,$out,true)) $out[] = $candidate;
            }
            return $out;
        };
        $parents = $normalize($parents); $senders = $normalize($senders); $pairs = [];
        foreach (['@lid','@s.whatsapp.net',''] as $suffix) {
            $parent = null; $sender = null;
            foreach ($parents as $candidate) if ($suffix === '' || str_ends_with($candidate,$suffix)) { $parent = $candidate; break; }
            foreach ($senders as $candidate) if ($suffix === '' || str_ends_with($candidate,$suffix)) { $sender = $candidate; break; }
            if ($parent !== null && $sender !== null && !in_array([$parent,$sender],$pairs,true)) $pairs[] = [$parent,$sender];
        }
        return $pairs;
    }

    /**
     * @param array<string,mixed> $proto
     * @return array<string,mixed>|null */
    public static function identify(array $proto): ?array
    {
        $proto = ContentDecoder::unwrap($proto)['message'];
        foreach ([
            'encReactionMessage'=>['reaction','targetMessageKey','Enc Reaction','Message.ReactionMessage'],
            'encEventResponseMessage'=>['event-response','eventCreationMessageKey','Event Response','Message.EventResponseMessage'],
            'encCommentMessage'=>['comment','targetMessageKey','Enc Comment','Message.CommentMessage'],
            'pollUpdateMessage'=>['poll-vote','pollCreationMessageKey','Poll Vote','Message.PollVoteMessage'],
            'secretEncryptedMessage'=>['secret-encrypted','targetMessageKey','','Message'],
        ] as $field => [$kind,$keyField,$type,$payloadType]) {
            if (!isset($proto[$field]) || !is_array($proto[$field])) continue;
            $body = $proto[$field]; $enc = $field === 'pollUpdateMessage' ? ($body['vote'] ?? []) : $body;
            if ($field === 'secretEncryptedMessage') {
                [$kind,$type] = match ($body['secretEncType'] ?? 0) {
                    1=>['event-edit','Event Edit'], 2=>['edit','Message Edit'],
                    4=>['poll-edit','Poll Edit'], 5=>['poll-add-option','Poll Add Option'],
                    default=>['secret-encrypted',''],
                };
            }
            if ($type === '' || !isset($body[$keyField],$enc['encPayload'],$enc['encIv'])) continue;
            return ['kind'=>$kind,'key'=>$body[$keyField],'encPayload'=>$enc['encPayload'],'encIv'=>$enc['encIv'],'modificationType'=>$type,'payloadType'=>$payloadType];
        }
        return null;
    }

    /**
     * @return array<string,mixed> */
    public static function decodePlaintext(string $kind, string $plaintext): array
    {
        $type = match ($kind) {
            'poll-vote'=>'Message.PollVoteMessage', 'event-response'=>'Message.EventResponseMessage',
            'reaction'=>'Message.ReactionMessage', 'comment'=>'Message.CommentMessage', default=>'Message',
        };
        return SchemaCodec::decode($type, $plaintext);
    }
}
