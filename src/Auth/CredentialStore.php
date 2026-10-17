<?php
declare(strict_types=1);
namespace MVO\Auth;

use MVO\Crypto\{Crypto,XEdDsa};
use MVO\Protocol\Jid;
use MVO\Store\SqliteStore;

/** Binary-safe credentials; one active connection per directory enforced by a file lock. */
final class CredentialStore
{
    private ?SqliteStore $store = null;
    /** @var resource|null */
    private $lock = null;
    public readonly string $directory;

    public function __construct(string $authDirectory, private readonly string $sessionId = 'default') {
        if (!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D',$sessionId)) throw new \InvalidArgumentException('Invalid session ID');
        $this->directory = rtrim($authDirectory,'/\\').DIRECTORY_SEPARATOR.$sessionId;
        if (!is_dir($this->directory) && !mkdir($this->directory,0700,true) && !is_dir($this->directory)) throw new \RuntimeException('Cannot create session directory');
    }
    public function acquire(): void {
        if ($this->lock !== null) return;
        $lock = fopen($this->directory.'/connection.lock','c+b');
        if ($lock === false) throw new \RuntimeException('Cannot open session lock');
        if (!flock($lock,LOCK_EX|LOCK_NB)) { fclose($lock); throw new \RuntimeException('Session already in use by another process'); }
        $this->lock = $lock;
    }
    public function release(): void {
        if ($this->lock !== null) { flock($this->lock,LOCK_UN); fclose($this->lock); $this->lock = null; }
    }
    public function ownsLock(): bool { return $this->lock !== null; }
    private function database(): SqliteStore {
        if($this->store!==null)return $this->store;$temporaryLock=false;
        try {return $this->store=new SqliteStore($this->directory.'/state.sqlite',$this->sessionId,function()use(&$temporaryLock):void{if(!$this->ownsLock()){$this->acquire();$temporaryLock=true;}});}
        finally {if($temporaryLock)$this->release();}
    }
    public function close(): void { $this->store?->close(); $this->store = null; $this->release(); }
    /** @return array<string,mixed>|null */
    public function load(): ?array {
        $bytes = $this->database()->get('auth','credentials');
        if ($bytes === null) return null;
        $decoded = self::unpack(json_decode($bytes,true,128,JSON_THROW_ON_ERROR));
        if (!is_array($decoded)) throw new \UnexpectedValueException('Invalid stored credentials');
        self::validate($decoded);
        return $decoded;
    }
    /** @param array<string,mixed> $credentials */
    public function save(array $credentials): void {
        self::validate($credentials);
        $bytes = json_encode(self::pack($credentials),JSON_THROW_ON_ERROR);
        $this->database()->transaction(fn(SqliteStore $store)=>$store->set('auth','credentials',$bytes));
    }
    /** @return array<string,mixed> */
    public static function fresh(): array {
        $identity = Crypto::x25519KeyPair(); $signed = Crypto::x25519KeyPair();
        return [
            'noiseKeyPair'=>Crypto::x25519KeyPair(), 'identityKeyPair'=>$identity,
            'signedPreKey'=>['id'=>1,...$signed,'signature'=>XEdDsa::sign($identity['private'],"\x05".$signed['public'])],
            'registrationId'=>random_int(1,16380),'advSecretKey'=>random_bytes(32),
            'registered'=>false,'meJid'=>null,'meLid'=>null,'loginCounter'=>0,'serverHasPreKeys'=>false
        ];
    }
    /** @param array<string,mixed> $c */
    private static function validate(array $c): void {
        foreach (['noiseKeyPair','identityKeyPair','signedPreKey'] as $name) {
            if (!isset($c[$name]) || !is_array($c[$name])) throw new \UnexpectedValueException('Missing credential keypair: '.$name);
            foreach (['private','public'] as $part) {
                if (!isset($c[$name][$part]) || !is_string($c[$name][$part])) throw new \UnexpectedValueException('Missing credential key: '.$name);
                Crypto::length($c[$name][$part],32);
            }
            if (!hash_equals(sodium_crypto_scalarmult_base($c[$name]['private']),$c[$name]['public'])) throw new \UnexpectedValueException('Inconsistent credential keypair');
        }
        if (!is_int($c['registrationId'] ?? null) || $c['registrationId']<1 || $c['registrationId']>16380) throw new \UnexpectedValueException('Invalid registration ID');
        if (!is_string($c['advSecretKey'] ?? null)) throw new \UnexpectedValueException('Missing ADV secret');
        Crypto::length($c['advSecretKey'],32);
        if (!is_int($c['signedPreKey']['id'] ?? null) || $c['signedPreKey']['id']<1 || $c['signedPreKey']['id']>0xffffff) throw new \UnexpectedValueException('Invalid signed prekey ID');
        if (!is_string($c['signedPreKey']['signature'] ?? null) || !XEdDsa::verify($c['identityKeyPair']['public'],"\x05".$c['signedPreKey']['public'],$c['signedPreKey']['signature'])) throw new \UnexpectedValueException('Invalid signed prekey signature');
        if (!is_bool($c['registered'] ?? null)) throw new \UnexpectedValueException('Missing registration state');
        if ($c['registered']) {
            if (!is_string($c['meJid'] ?? null)) throw new \UnexpectedValueException('Missing paired JID');
            Jid::parse($c['meJid']);
        }
    }
    private static function pack(mixed $value): mixed {
        if (is_string($value)) return ['$bytes'=>base64_encode($value)];
        if (is_array($value)) return array_map(self::pack(...),$value);
        if (is_int($value) || is_bool($value) || $value === null) return $value;
        throw new \InvalidArgumentException('Unsupported credential value');
    }
    private static function unpack(mixed $value): mixed {
        if (is_array($value) && array_keys($value) === ['$bytes']) {
            if (!is_string($value['$bytes'])) throw new \UnexpectedValueException('Invalid binary encoding');
            $bytes = base64_decode($value['$bytes'],true);
            if ($bytes === false) throw new \UnexpectedValueException('Invalid base64 credential');
            return $bytes;
        }
        if (is_array($value)) return array_map(self::unpack(...),$value);
        if (is_int($value) || is_bool($value) || $value === null) return $value;
        throw new \UnexpectedValueException('Invalid credential value');
    }
    public function __destruct() { $this->close(); }
}
