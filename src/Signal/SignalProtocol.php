<?php
declare(strict_types=1);

namespace MVO\Signal;

use MVO\Crypto\{Crypto,XEdDsa};
use MVO\Protocol\{BinaryData,Jid};

/**
 * Native Signal v3 direct-device sessions: X3DH + Double Ratchet + pkmsg/msg.
 * Keys, identities and ratchets persist atomically in the session's SQLite file.
 * @phpstan-import-type KeyPair from SignalState
 * @phpstan-import-type Session from SignalState
 * @phpstan-type Bundle array{registrationId:int,identityKey:string,signedPreKey:array{id:int,public:string,signature:string},preKey?:array{id:int,public:string}}
 */
final class SignalProtocol
{
    /** @var KeyPair */
    private array $identity;
    /** @var KeyPair */
    private array $signed;
    private int $registrationId;
    private int $signedPreKeyId;

    /** @param array<string,mixed> $credentials */
    public function __construct(private readonly SignalStore $store,array $credentials)
    {
        $this->identity = self::credentialPair($credentials['identityKeyPair'] ?? null);
        $this->signed = self::credentialPair($credentials['signedPreKey'] ?? null);
        $registration = $credentials['registrationId'] ?? null;
        $signed = $credentials['signedPreKey'];
        if (!is_int($registration) || $registration<1 || $registration>0xffffffff) throw new \InvalidArgumentException('Invalid Signal registration ID');
        if (!is_array($signed) || !is_int($signed['id'] ?? null) || $signed['id']<1 || $signed['id']>0xffffff) throw new \InvalidArgumentException('Invalid Signal signed prekey ID');
        if (!is_string($signed['signature'] ?? null) || !XEdDsa::verify(SignalState::rawPublicKey($this->identity['public']),$this->signed['public'],$signed['signature'])) throw new \InvalidArgumentException('Invalid local Signal signed prekey signature');
        $this->registrationId = $registration;
        $this->signedPreKeyId = $signed['id'];
        $this->rememberSignedPreKey($signed);
    }

    /** Save before upload so a lost ACK cannot lose the private counterpart of a published key.
     * @param array<string,mixed> $key */
    public function rememberSignedPreKey(array $key):void
    {
        $pair=self::credentialPair($key);$id=$key['id'] ?? null;$signature=$key['signature'] ?? null;
        if(!is_int($id)||$id<1||$id>0xffffff||!is_string($signature)||!XEdDsa::verify(SignalState::rawPublicKey($this->identity['public']),$pair['public'],$signature))throw new \InvalidArgumentException('Invalid local signed prekey');
        $bytes=BinaryData::encode(['id'=>$id,...$pair,'signature'=>$signature]);$address=$this->signedKeyAddress($id);$old=$this->store->get('signal-local-signed-prekeys',$address);
        if($old!==null && !hash_equals($old,$bytes))throw new \LogicException('Cannot replace a retained signed prekey ID');$this->store->set('signal-local-signed-prekeys',$address,$bytes);
    }
    /** Updates future inbound sessions; previous signed keys remain available for delayed pkmsg.
     * @param array<string,mixed> $key */
    public function replaceSignedPreKey(array $key):void {$this->rememberSignedPreKey($key);$this->signed=self::credentialPair($key);$this->signedPreKeyId=$key['id'];}

    public function hasSession(string $jid): bool { return $this->store->getSession(self::address($jid)) !== null; }

    /**
     * Establish once, or explicitly refresh with a new authenticated bundle.
     * A changed trusted identity requires an explicit application trust decision.
     * @param Bundle $bundle
     */
    public function ensureSession(string $jid,array $bundle,bool $force = false): void
    {
        $jid = self::address($jid);
        $remoteIdentity = SignalState::publicKey($bundle['identityKey']);
        $remoteSigned = SignalState::publicKey($bundle['signedPreKey']['public']);
        // Remote registration IDs are unsigned 32-bit wire values, including zero.
        // A valid signed prekey and the existing trust check still authenticate
        // the peer; registration ID is metadata, not an identity/trust decision.
        if (!is_int($bundle['registrationId']) || $bundle['registrationId']<0 || $bundle['registrationId']>0xffffffff) throw new \UnexpectedValueException('Invalid remote Signal registration ID');
        if ($bundle['signedPreKey']['id']<1 || $bundle['signedPreKey']['id']>0xffffff) throw new \UnexpectedValueException('Invalid remote Signal signed prekey ID');
        if (!XEdDsa::verify(SignalState::rawPublicKey($remoteIdentity),$remoteSigned,$bundle['signedPreKey']['signature'])) throw new \UnexpectedValueException('Invalid remote Signal signed prekey signature');
        if (isset($bundle['preKey']) && ($bundle['preKey']['id']<1 || $bundle['preKey']['id']>0xffffff)) throw new \UnexpectedValueException('Invalid remote Signal prekey ID');
        $remoteOneTime = isset($bundle['preKey']) ? SignalState::publicKey($bundle['preKey']['public']) : null;
        $this->store->transaction(function() use ($jid,$bundle,$remoteIdentity,$remoteSigned,$remoteOneTime,$force): void {
            $this->store->trustIdentity($jid,$remoteIdentity);
            $existing = $this->store->getSession($jid);
            if ($existing !== null) $this->verifyLocalIdentity($existing);
            if ($existing !== null && !$force) {
                if (!hash_equals($existing['remoteIdentity'],$remoteIdentity)) throw new \UnexpectedValueException('Signal session identity mismatch');
                return;
            }
            $base = SignalState::keyPair();
            $secret = str_repeat("\xff",32)
                .Crypto::x25519($this->identity['private'],SignalState::rawPublicKey($remoteSigned))
                .Crypto::x25519($base['private'],SignalState::rawPublicKey($remoteIdentity))
                .Crypto::x25519($base['private'],SignalState::rawPublicKey($remoteSigned));
            if ($remoteOneTime !== null) $secret .= Crypto::x25519($base['private'],SignalState::rawPublicKey($remoteOneTime));
            $expanded = Crypto::hkdf($secret,64,'WhisperText');
            $sendPair = SignalState::keyPair();
            $send = SignalRatchet::calculate(substr($expanded,0,32),$sendPair,$remoteSigned);
            $session = [
                'localIdentity'=>$this->identity['public'],'remoteIdentity'=>$remoteIdentity,
                'localRegistration'=>$this->registrationId,'remoteRegistration'=>$bundle['registrationId'],
                'root'=>$send['root'],
                'send'=>['pair'=>$sendPair,'index'=>0,'key'=>$send['key']],
                'receive'=>[['public'=>$remoteSigned,'index'=>0,'key'=>substr($expanded,32,32),'unused'=>[]]],
                'pending'=>['preKeyId'=>$bundle['preKey']['id'] ?? null,'signedPreKeyId'=>$bundle['signedPreKey']['id'],'baseKey'=>$base['public']],
                'previousCounter'=>0,'baseKey'=>$base['public'],'previous'=>[],
            ];
            if ($existing !== null) $session['previous'] = array_slice([SignalRatchet::snapshot($existing),...$existing['previous']],0,SignalRatchet::MAX_PREVIOUS_SESSIONS);
            $this->store->putSession($jid,$session);
        });
    }

    /** @return array{type:'pkmsg'|'msg',ciphertext:string} */
    public function encrypt(string $jid,string $plaintext): array
    {
        $jid = self::address($jid);
        return $this->store->transaction(function() use ($jid,$plaintext): array {
            $session = $this->store->getSession($jid);
            if ($session === null) throw new \UnexpectedValueException('Signal session not found for '.$jid);
            $this->verifyLocalIdentity($session);
            $this->store->trustIdentity($jid,$session['remoteIdentity']);
            [$updated,$encrypted] = SignalRatchet::encrypt($session,$plaintext);
            $this->store->putSession($jid,$updated);
            return $encrypted;
        });
    }

    public function decrypt(string $jid,string $type,string $ciphertext): string
    {
        $jid = self::address($jid);
        if ($type !== 'msg' && $type !== 'pkmsg') throw new \InvalidArgumentException('Unsupported Signal message type: '.$type);
        // Parse before entering the write transaction; no input can mutate state here.
        $parsed = $type === 'pkmsg' ? SignalCodec::readPreKeyMessage($ciphertext) : SignalCodec::readMessage($ciphertext);
        return $this->store->transaction(function() use ($jid,$type,$parsed): string {
            $current = $this->store->getSession($jid);
            if ($current !== null) $this->verifyLocalIdentity($current);
            $usedPreKey = null;
            if ($type === 'pkmsg') {
                // Keys here exist because readPreKeyMessage parsed this envelope.
                /** @var array{ratchetKey:string,counter:int,previousCounter:int,ciphertext:string,body:string,registrationId:int,identityKey:string,baseKey:string,signedPreKeyId:int,preKeyId:?int} $parsed */
                $this->store->trustIdentity($jid,$parsed['identityKey']);
                $session = self::matchingSession($current,$parsed['baseKey']);
                if ($session === null) {
                    $signed=$this->signed;
                    if ($parsed['signedPreKeyId'] !== $this->signedPreKeyId) {
                        $saved=$this->store->get('signal-local-signed-prekeys',$this->signedKeyAddress($parsed['signedPreKeyId']));
                        if($saved===null)throw new \UnexpectedValueException('Signal signed prekey not found');$record=BinaryData::decode($saved);
                        if(!is_array($record)||!is_string($record['signature'] ?? null))throw new \UnexpectedValueException('Invalid retained signed prekey');
                        $signed=self::credentialPair($record);
                        if(!XEdDsa::verify(SignalState::rawPublicKey($this->identity['public']),$signed['public'],$record['signature']))throw new \UnexpectedValueException('Retained signed prekey belongs to another identity');
                    }
                    $oneTime = $parsed['preKeyId'] === null ? null : $this->store->getPreKey($parsed['preKeyId']);
                    if ($parsed['preKeyId'] !== null && $oneTime === null) throw new \UnexpectedValueException('Signal one-time prekey not found');
                    $secret = str_repeat("\xff",32)
                        .Crypto::x25519($signed['private'],SignalState::rawPublicKey($parsed['identityKey']))
                        .Crypto::x25519($this->identity['private'],SignalState::rawPublicKey($parsed['baseKey']))
                        .Crypto::x25519($signed['private'],SignalState::rawPublicKey($parsed['baseKey']));
                    if ($oneTime !== null) $secret .= Crypto::x25519($oneTime['private'],SignalState::rawPublicKey($parsed['baseKey']));
                    $expanded = Crypto::hkdf($secret,64,'WhisperText');
                    $session = [
                        'localIdentity'=>$this->identity['public'],'remoteIdentity'=>$parsed['identityKey'],
                        'localRegistration'=>$this->registrationId,'remoteRegistration'=>$parsed['registrationId'],
                        'root'=>substr($expanded,0,32),
                        'send'=>['pair'=>$signed,'index'=>0,'key'=>substr($expanded,32,32)],
                        'receive'=>[],'pending'=>null,'previousCounter'=>0,'baseKey'=>$parsed['baseKey'],
                        'previous'=>$current === null ? [] : array_slice([SignalRatchet::snapshot($current),...$current['previous']],0,SignalRatchet::MAX_PREVIOUS_SESSIONS),
                    ];
                    $usedPreKey = $parsed['preKeyId'];
                }
                [$updated,$plaintext] = SignalRatchet::decrypt($session,$parsed);
            } else {
                if ($current === null) throw new \UnexpectedValueException('Signal session not found for '.$jid);
                [$updated,$plaintext] = SignalRatchet::decryptWithFallback($current,$parsed);
            }
            $this->store->trustIdentity($jid,$updated['remoteIdentity']);
            $this->store->putSession($jid,$updated);
            if ($usedPreKey !== null) $this->store->consumePreKey($usedPreKey);
            return $plaintext;
        });
    }

    /**
     * @param Session|null $current
     * @return Session|null
     */
    private static function matchingSession(?array $current,string $base): ?array
    {
        if ($current === null) return null;
        if (hash_equals($current['baseKey'],$base)) return $current;
        foreach ($current['previous'] as $index=>$previous) {
            if (!hash_equals($previous['baseKey'],$base)) continue;
            $remaining = $current['previous'];
            array_splice($remaining,$index,1);
            return [...$previous,'previous'=>array_slice([SignalRatchet::snapshot($current),...$remaining],0,SignalRatchet::MAX_PREVIOUS_SESSIONS)];
        }
        return null;
    }

    /** @param Session $session */
    private function verifyLocalIdentity(array $session): void
    {
        if ($session['localRegistration'] !== $this->registrationId || !hash_equals($session['localIdentity'],$this->identity['public'])) {
            throw new \UnexpectedValueException('Stored Signal session belongs to a different local identity');
        }
    }

    /** @return KeyPair */
    private static function credentialPair(mixed $value): array
    {
        if (!is_array($value) || !is_string($value['private'] ?? null) || !is_string($value['public'] ?? null)) throw new \InvalidArgumentException('Missing Signal credential keypair');
        return SignalState::normalizePair(['private'=>$value['private'],'public'=>$value['public']]);
    }

    private static function address(string $jid): string
    {
        $address = Jid::parse($jid)->canonical();
        if (!in_array($address->server,['s.whatsapp.net','lid','bot'],true)) throw new \InvalidArgumentException('Signal sessions require a direct-device or bot JID');
        return (string)$address;
    }
    private function signedKeyAddress(int $id):string {return hash('sha256',$this->identity['public']).':'.$id;}
}
