<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Crypto\Crypto;
use MVO\Protocol\{BinaryNode,Jid,SchemaCodec};

/** Meta AI prompt envelopes and independently authenticated streaming response chunks. */
final class BotTransport
{
    public const BLOKS_VERSION='98153664e5d904059a94ea08a59fc7eeaf977544cec64ab3ab793c0ce001eb92';
    public const DEFAULT_CAPABILITIES=['RICH_RESPONSE_HEADING','RICH_RESPONSE_NESTED_LIST','RICH_RESPONSE_TABLE','RICH_RESPONSE_CODE',
        'RICH_RESPONSE_STRUCTURED_RESPONSE','RICH_RESPONSE_SUB_HEADING','RICH_RESPONSE_LATEX','RICH_RESPONSE_INLINE_REELS',
        'RICH_RESPONSE_LATEX_INLINE','RICH_RESPONSE_UNIFIED_RESPONSE','RICH_RESPONSE_SOURCES_IN_MESSAGE','RICH_RESPONSE_UNIFIED_TEXT_COMPONENT',
        'RICH_RESPONSE_UNIFIED_SOURCES','RICH_RESPONSE_UNIFIED_DOMAIN_CITATIONS','RICH_RESPONSE_UR_INLINE_REELS_ENABLED'];

    public static function fbid(string $jid):string
    {
        $jid=Jid::parse($jid)->canonical();
        if ($jid->bare()==='13135550002@s.whatsapp.net') return '867051314767696@bot';
        if ($jid->server!=='bot' || $jid->device!==0 || !ctype_digit($jid->user)) throw new \InvalidArgumentException('Use a bot FBID JID returned by bot discovery');
        return $jid->bare();
    }

    /** @param array<string,mixed> $message
     * @param array<string,mixed> $options
     * @return array{to:string,message:array<string,mixed>,threadId:?string} */
    public static function prompt(string $to,array $message,array $options=[]):array
    {
        $target=Jid::recipient($to)->canonical(); $direct=$target->server==='bot';
        $bot=self::fbid($direct ? $target->bare() : ($options['botJid'] ?? throw new \InvalidArgumentException('Mention prompt requires botJid')));
        $secret=$message['messageContextInfo']['messageSecret'] ?? random_bytes(32);
        Crypto::length($secret,32);
        if ($direct) {
            $thread=$options['aiThreadId'] ?? self::uuid();
            if (!is_string($thread) || $thread==='' || strlen($thread)>256) throw new \InvalidArgumentException('Invalid AI thread ID');
            $metadata=['capabilityMetadata'=>['capabilities'=>$options['capabilities'] ?? self::DEFAULT_CAPABILITIES],
                'botThreadInfo'=>['clientInfo'=>['type'=>$options['aiThreadType'] ?? 'DEFAULT']]];
            if (isset($options['personaId'])) $metadata['personaId']=$options['personaId'];
            $message['messageContextInfo']=[...($message['messageContextInfo'] ?? []),'messageSecret'=>$secret,'botMetadata'=>$metadata,
                'threadId'=>[['threadType'=>'AI_THREAD','threadKey'=>['remoteJid'=>$bot,'fromMe'=>true,'id'=>$thread]]]];
            SchemaCodec::encode('Message',$message);
            return ['to'=>$bot,'message'=>$message,'threadId'=>$thread];
        }
        if (!in_array($target->server,['g.us','s.whatsapp.net','lid'],true)) throw new \InvalidArgumentException('Invalid bot mention chat');
        if (isset($message['conversation'])) {
            $message['extendedTextMessage']=['text'=>$message['conversation']]; unset($message['conversation']);
        }
        $field=null;
        foreach (['extendedTextMessage','imageMessage','videoMessage','documentMessage'] as $candidate) if (isset($message[$candidate])) { $field=$candidate; break; }
        if ($field===null) throw new \InvalidArgumentException('Bot mentions support text or caption-bearing media');
        $mentions=[$bot];
        foreach ($options['extraMentionedJids'] ?? [] as $jid) {
            if (!is_string($jid)) throw new \InvalidArgumentException('Invalid bot mention');
            $mentions[]=Jid::parse($jid)->canonical()->bare();
        }
        $message[$field]['contextInfo']=[...($message[$field]['contextInfo'] ?? []),'mentionedJid'=>array_values(array_unique($mentions)),
            'botMessageSharingInfo'=>['botEntryPointOrigin'=>'INVOKE_META_AI_GROUP','forwardScore'=>0]];
        // Persona, capabilities and direct thread metadata cause mention requests to be dropped.
        unset($message['messageContextInfo']);
        $wrapped=['messageContextInfo'=>['messageSecret'=>$secret,'threadId'=>[],'botMetadata'=>[
            'botRenderingConfigMetadata'=>['bloksVersioningId'=>self::BLOKS_VERSION,'pixelDensity'=>2.8125]]],
            'botInvokeMessage'=>['message'=>$message]];
        SchemaCodec::encode('Message',$wrapped);
        return ['to'=>$target->bare(),'message'=>$wrapped,'threadId'=>null];
    }

    /** @param array<string,mixed> $message */
    public static function invokedBot(array $message):?string
    {
        $inner=$message['botInvokeMessage']['message'] ?? null;
        if (!is_array($inner)) return null;
        foreach (['extendedTextMessage','imageMessage','videoMessage','documentMessage'] as $field) {
            foreach ($inner[$field]['contextInfo']['mentionedJid'] ?? [] as $jid) if (is_string($jid) && str_ends_with($jid,'@bot')) return self::fbid($jid);
        }
        return null;
    }

    /** The bot copy contains only the derived secret, never the parent secret.
     * @param array<string,mixed> $message
     * @return array<string,mixed> */
    public static function sidecarCopy(array $message):array
    {
        $context=$message['messageContextInfo'] ?? [];
        $secret=$context['messageSecret'] ?? null;
        if (!is_string($secret)) throw new \InvalidArgumentException('Bot invocation requires messageSecret');
        Crypto::length($secret,32); unset($context['messageSecret']);
        $context['botMessageSecret']=Crypto::hkdf($secret,32,'Bot Message');
        return [...$message,'messageContextInfo'=>$context];
    }

    /** @param array<string,mixed> $secret
     * @param array<string,mixed> $event
     * @param list<string> $ownCandidates
     * @return array<string,mixed>|null */
    public static function decryptChunk(array $secret,array $event,BinaryNode $node,array $ownCandidates):?array
    {
        $bot=$node->child('bot'); if ($bot===null) return null;
        $sec=ContentDecoder::unwrap($event['proto'] ?? [])['message']['secretEncryptedMessage'] ?? null;
        if (!is_array($sec) || !is_string($sec['encIv'] ?? null) || !is_string($sec['encPayload'] ?? null)) return null;
        $meta=$node->child('meta');
        $parent=$sec['targetMessageKey']['id'] ?? $meta?->attrs['target_id'] ?? null;
        if (!is_string($parent) || $parent==='') return null;
        $edit=$bot->attrs['edit'] ?? 'full';
        if (!in_array($edit,['first','inner','last','full'],true)) $edit='full';
        $editTarget=$bot->attrs['edit_target_id'] ?? null;
        $salt=in_array($edit,['inner','last'],true) ? $editTarget : ($event['id'] ?? null);
        if (!is_string($salt) || $salt==='') throw new \UnexpectedValueException('Bot chunk missing authenticated salt context');
        $author=Jid::parse($event['from'])->canonical()->bare();
        if (is_string($secret['expectedBot']??null)) {
            try { if (self::fbid($author)!==self::fbid($secret['expectedBot'])) return null; }
            catch (\InvalidArgumentException) { return null; }
        }
        $targets=$ownCandidates;
        if (isset($meta?->attrs['target_sender_jid'])) {
            $target=Jid::parse($meta->attrs['target_sender_jid'])->canonical()->bare();
            if (!in_array($target,$ownCandidates,true)) return null;
            $targets=[$target];
        }
        $botSecret=Crypto::hkdf($secret['secret'],32,'Bot Message');
        foreach ($targets as $target) {
            $key=Crypto::hkdf($botSecret,32,$salt.$target.$author);
            try { $plain=Crypto::decryptGcm($key,$sec['encIv'],$sec['encPayload'],$salt."\0".$author); }
            catch (\UnexpectedValueException) { continue; }
            $proto=SchemaCodec::decode('Message',$plain); $content=ContentDecoder::unwrap($proto)['message']; $responseMode='append';
            $protocol=$content['protocolMessage'] ?? null;
            if (is_array($protocol) && in_array($protocol['type'] ?? null,[14,'MESSAGE_EDIT'],true) && is_array($protocol['editedMessage'] ?? null)) {
                // AEAD authenticates all of these fields, and the edit must refer to this response stream.
                if (in_array($edit,['inner','last'],true) && ($protocol['key']['id'] ?? null)!==$editTarget) throw new \UnexpectedValueException('Bot edit target does not match its authenticated stream');
                if (isset($protocol['key']['remoteJid'])) {
                    $remote=Jid::parse($protocol['key']['remoteJid'])->canonical()->bare();
                    $boundChat=is_string($secret['targetChat']??null)?Jid::parse($secret['targetChat'])->canonical()->bare():null;
                    if (!in_array($remote,$ownCandidates,true) && $remote!==$author && $remote!==$boundChat) throw new \UnexpectedValueException('Bot edit target belongs to another chat');
                }
                $content=ContentDecoder::unwrap($protocol['editedMessage'])['message']; $responseMode='replace';
            }
            $decoded=ContentDecoder::decode(SchemaCodec::encode('Message',$content));
            $out=['targetMessageId'=>$parent,'editType'=>$edit,'editTargetId'=>$editTarget,'saltId'=>$salt,
                'plaintext'=>$plain,'proto'=>$proto,'text'=>$decoded['text'] ?? null,'type'=>$decoded['kind'],
                'data'=>$decoded['data'] ?? [],'context'=>$decoded['context'] ?? [],
                'fields'=>SchemaCodec::fieldNumbers($plain),'responseMode'=>$responseMode];
            if ($responseMode==='replace' && is_int($protocol['timestampMs'] ?? null) && $protocol['timestampMs']>0) $out['revisionTimestampMs']=$protocol['timestampMs'];
            if (isset($decoded['richResponse'])) { $out['richResponse']=$decoded['richResponse']; $out['responseMode']='replace'; }
            return $out;
        }
        throw new \UnexpectedValueException('Bot chunk authentication failed');
    }

    private static function uuid():string
    {
        $bytes=random_bytes(16); $bytes[6]=chr((ord($bytes[6])&15)|64); $bytes[8]=chr((ord($bytes[8])&63)|128);
        $hex=bin2hex($bytes); return substr($hex,0,8).'-'.substr($hex,8,4).'-'.substr($hex,12,4).'-'.substr($hex,16,4).'-'.substr($hex,20);
    }
}
