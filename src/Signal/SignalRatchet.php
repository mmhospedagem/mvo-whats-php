<?php
declare(strict_types=1);

namespace MVO\Signal;

use MVO\Crypto\Crypto;

/**
 * Signal v3 Double Ratchet, ported from upstream signal/session/SignalRatchet.ts.
 * States are copied and returned; callers persist only authenticated results.
 * @phpstan-import-type KeyPair from SignalState
 * @phpstan-import-type MessageKey from SignalState
 * @phpstan-import-type ReceiveChain from SignalState
 * @phpstan-import-type Session from SignalState
 * @phpstan-import-type Snapshot from SignalState
 */
final class SignalRatchet
{
    public const MAX_SKIPPED_KEYS = 2000;
    public const MAX_PREVIOUS_SESSIONS = 40;

    /**
     * @param KeyPair $pair
     * @return array{root:string,key:string}
     */
    public static function calculate(string $root,array $pair,string $remote): array
    {
        $secret = Crypto::x25519($pair['private'],SignalState::rawPublicKey($remote));
        $expanded = Crypto::hkdf($secret,64,'WhisperRatchet',$root);
        return ['root'=>substr($expanded,0,32),'key'=>substr($expanded,32,32)];
    }

    /** @return array{nextKey:string,messageKey:MessageKey} */
    public static function deriveMessageKey(int $index,string $chainKey): array
    {
        Crypto::length($chainKey,32);
        $expanded = Crypto::hkdf(Crypto::hmac($chainKey,"\x01"),80,'WhisperMessageKeys');
        return [
            'nextKey'=>Crypto::hmac($chainKey,"\x02"),
            'messageKey'=>['index'=>$index,'cipherKey'=>substr($expanded,0,32),'macKey'=>substr($expanded,32,32),'iv'=>substr($expanded,64,16)],
        ];
    }

    /**
     * @param ReceiveChain $chain
     * @return array{chain:ReceiveChain,key:MessageKey}
     */
    private static function selectMessageKey(array $chain,int $counter): array
    {
        if ($counter-$chain['index'] > self::MAX_SKIPPED_KEYS) throw new \UnexpectedValueException('Signal message too far in future');
        if ($counter < $chain['index']) {
            foreach ($chain['unused'] as $index=>$key) {
                if ($key['index'] !== $counter) continue;
                array_splice($chain['unused'],$index,1);
                return ['chain'=>$chain,'key'=>$key];
            }
            throw new \UnexpectedValueException('Duplicate Signal message');
        }
        for ($index=$chain['index'];$index<=$counter;$index++) {
            $derived = self::deriveMessageKey($index,$chain['key']);
            $chain['key'] = $derived['nextKey'];
            $chain['index'] = $index+1;
            if ($index === $counter) return ['chain'=>$chain,'key'=>$derived['messageKey']];
            $chain['unused'][] = $derived['messageKey'];
            if (count($chain['unused'])>self::MAX_SKIPPED_KEYS) array_shift($chain['unused']);
        }
        throw new \LogicException('No Signal message key derived');
    }

    /**
     * @param Session $session
     * @return array{0:Session,1:array{type:'msg'|'pkmsg',ciphertext:string}}
     */
    public static function encrypt(array $session,string $plaintext): array
    {
        if ($session['send']['index']>0xffffffff) throw new \OverflowException('Signal send chain exhausted');
        if (strlen($plaintext)>4190000) throw new \LengthException('Signal plaintext too large');
        $derived = self::deriveMessageKey($session['send']['index'],$session['send']['key']);
        $key = $derived['messageKey'];
        $ciphertext = openssl_encrypt($plaintext,'aes-256-cbc',$key['cipherKey'],OPENSSL_RAW_DATA,$key['iv']);
        if ($ciphertext === false) throw new \RuntimeException('Signal AES-CBC encryption failed');
        $message = SignalCodec::writeMessage($session['send']['pair']['public'],$key['index'],$session['previousCounter'],$ciphertext);
        $message .= substr(Crypto::hmac($key['macKey'],$session['localIdentity'].$session['remoteIdentity'].$message),0,8);
        $type = 'msg';
        if ($session['pending'] !== null) {
            $pending = $session['pending'];
            $message = SignalCodec::writePreKeyMessage($session['localRegistration'],$pending['preKeyId'],$pending['signedPreKeyId'],$pending['baseKey'],$session['localIdentity'],$message);
            $type = 'pkmsg';
        }
        $session['send']['index'] = $key['index']+1;
        $session['send']['key'] = $derived['nextKey'];
        return [$session,['type'=>$type,'ciphertext'=>$message]];
    }

    /**
     * @param Session $session
     * @param array{ratchetKey:string,counter:int,previousCounter:int,ciphertext:string,body:string} $message
     * @return array{0:Session,1:string}
     */
    public static function decrypt(array $session,array $message): array
    {
        $chainIndex = null;
        foreach ($session['receive'] as $index=>$chain) {
            if (hash_equals($chain['public'],$message['ratchetKey'])) { $chainIndex = $index; break; }
        }
        if ($chainIndex === null) {
            $ratchet = self::calculate($session['root'],$session['send']['pair'],$message['ratchetKey']);
            $selected = self::selectMessageKey(['public'=>$message['ratchetKey'],'index'=>0,'key'=>$ratchet['key'],'unused'=>[]],$message['counter']);
        } else {
            $selected = self::selectMessageKey($session['receive'][$chainIndex],$message['counter']);
        }
        $key = $selected['key'];
        $mac = substr(Crypto::hmac($key['macKey'],$session['remoteIdentity'].$session['localIdentity'].substr($message['body'],0,-8)),0,8);
        if (!hash_equals($mac,substr($message['body'],-8))) throw new \UnexpectedValueException('Invalid Signal message MAC');
        $plaintext = openssl_decrypt($message['ciphertext'],'aes-256-cbc',$key['cipherKey'],OPENSSL_RAW_DATA,$key['iv']);
        if ($plaintext === false) throw new \UnexpectedValueException('Invalid Signal AES-CBC padding');

        // Only generate/advance the sending ratchet after authenticating the message.
        if ($chainIndex === null) {
            $newPair = SignalState::keyPair();
            $send = self::calculate($ratchet['root'],$newPair,$message['ratchetKey']);
            $session['root'] = $send['root'];
            $session['previousCounter'] = max($session['send']['index']-1,0);
            $session['send'] = ['pair'=>$newPair,'index'=>0,'key'=>$send['key']];
            $session['pending'] = null;
            $session['receive'] = array_slice($session['receive'],-4);
            $session['receive'][] = $selected['chain'];
        } else {
            $session['receive'][$chainIndex] = $selected['chain'];
        }
        return [$session,$plaintext];
    }

    /**
     * @param Session $session
     * @return Snapshot
     */
    public static function snapshot(array $session): array
    {
        unset($session['previous']);
        return $session;
    }

    /**
     * @param Session $session
     * @param array{ratchetKey:string,counter:int,previousCounter:int,ciphertext:string,body:string} $message
     * @return array{0:Session,1:string}
     */
    public static function decryptWithFallback(array $session,array $message): array
    {
        try { return self::decrypt($session,$message); }
        catch (\UnexpectedValueException $originalError) {
            foreach ($session['previous'] as $index=>$previous) {
                try {
                    [$updated,$plaintext] = self::decrypt([...$previous,'previous'=>[]],$message);
                    $remaining = $session['previous'];
                    array_splice($remaining,$index,1);
                    $updated['previous'] = array_slice([self::snapshot($session),...$remaining],0,self::MAX_PREVIOUS_SESSIONS);
                    return [$updated,$plaintext];
                } catch (\UnexpectedValueException) { continue; }
            }
            throw $originalError;
        }
    }
}
