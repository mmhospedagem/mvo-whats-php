<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee\Signal;

use MVO\Messenger\Auth\SessionStore;
use MVO\Messenger\E2ee\{Crypto,Protocol\Address};
use MVO\Messenger\MessengerException;

/** Uses the account owner's existing transaction and lock; never opens another auth database.
 * @phpstan-import-type Session from SignalState
 * @phpstan-import-type KeyPair from SignalState
 */
final class SignalStore
{
    private int $depth=0;private bool $rollbackOnly=false;
    public function __construct(private readonly SessionStore $owner,private readonly string $accountId)
    {
        if(preg_match('/^[1-9][0-9]{0,24}$/D',$accountId)!==1)throw new \InvalidArgumentException('Use the authenticated Messenger account ID');
        if($owner->get('account_id')!==$accountId)throw new MessengerException('messenger_device_identity_mismatch','The encrypted state belongs to another account');
    }
    public function accountId():string{$this->guard();return $this->accountId;}
    private function guard():void
    {
        if(!$this->owner->ownsLock())throw new \LogicException('Encrypted state requires the session owner lock');
        if($this->owner->get('account_id')!==$this->accountId)throw new MessengerException('messenger_device_identity_mismatch','The encrypted state account changed');
    }
    /** Internal private protocol storage, not diagnostics. */
    public function get(string $namespace,string $key):?string
    {
        $this->guard();$value=$this->owner->get(self::key($namespace,$key));
        if($value===null||$value==='')return null;
        if(!str_starts_with($value,'v:'))throw new \UnexpectedValueException('Corrupt Messenger encrypted record');
        $decoded=base64_decode(substr($value,2),true);
        if($decoded===false||base64_encode($decoded)!==substr($value,2))throw new \UnexpectedValueException('Corrupt Messenger encrypted record encoding');
        return $decoded;
    }
    public function set(string $namespace,string $key,#[\SensitiveParameter]string $value):void{$this->guard();$this->owner->set(self::key($namespace,$key),'v:'.base64_encode($value));}
    public function delete(string $namespace,string $key):void{$this->guard();$this->owner->set(self::key($namespace,$key),'');}
    private static function key(string $namespace,string $key):string
    {
        if($namespace===''||strlen($namespace)>128||strlen($key)>512)throw new \InvalidArgumentException('Invalid encrypted state namespace');
        return 'e2ee.runtime.v1.'.hash('sha256',$namespace."\0".$key);
    }
    /** @template T
     * @param callable(self):T $operation
     * @return T
     */
    public function transaction(callable $operation):mixed
    {
        $this->guard();
        if($this->depth>0){++$this->depth;try{return $operation($this);}catch(\Throwable $e){$this->rollbackOnly=true;throw $e;}finally{--$this->depth;}}
        $this->depth=1;$this->rollbackOnly=false;
        try{return $this->owner->transaction(function()use($operation){$result=$operation($this);if($this->rollbackOnly)throw new \RuntimeException('Encrypted transaction rolled back after nested failure');return $result;});}
        finally{$this->depth=0;$this->rollbackOnly=false;}
    }
    /** @return Session|null */
    public function getSession(string $jid):?array
    {
        $raw=$this->get('sessions',Address::device($jid));if($raw===null)return null;
        $record=SignalState::unpack(json_decode($raw,true,128,JSON_THROW_ON_ERROR));
        if(!is_array($record)||!isset($record['root'],$record['send'],$record['previous']))throw new \UnexpectedValueException('Invalid Messenger ratchet record');
        /** @var Session $record */return $record;
    }
    /** @param Session $session */
    public function putSession(string $jid,#[\SensitiveParameter]array $session):void{$this->set('sessions',Address::device($jid),json_encode(SignalState::pack($session),JSON_THROW_ON_ERROR));}
    public function getIdentity(string $jid):?string{return $this->get('trusted-identities',Address::device($jid));}
    /** Explicit first trust after the consumer checks an authenticated roster/fingerprint.
     * Existing trusted identities are never replaced or sessions silently deleted.
     */
    public function approveIdentity(string $jid,string $identity,string $expectedFingerprint):void
    {
        $identity=SignalState::publicKey($identity);
        if(!preg_match('/^[a-f0-9]{64}$/D',$expectedFingerprint)||!hash_equals(hash('sha256',SignalState::rawPublicKey($identity)),$expectedFingerprint))throw new \InvalidArgumentException('Review the exact peer identity fingerprint');
        $this->transaction(function()use($jid,$identity){$old=$this->getIdentity($jid);if($old!==null&&!hash_equals($old,$identity))throw new MessengerException('messenger_peer_identity_changed','Reconcile this peer identity change; automatic replacement is forbidden');$this->set('trusted-identities',Address::device($jid),$identity);});
    }
    /** Verify trust only: an incoming prekey message must never auto-trust a stranger. */
    public function trustIdentity(string $jid,string $identity):void
    {
        $identity=SignalState::publicKey($identity);$old=$this->getIdentity($jid);
        if($old===null)throw new MessengerException('messenger_peer_trust_required','Review and explicitly approve this device identity before encrypted messaging');
        if(!hash_equals($old,$identity))throw new MessengerException('messenger_peer_identity_changed','The encrypted peer identity differs from its trusted fingerprint');
    }
    /** @return KeyPair|null */
    public function getPreKey(int $id):?array
    {
        self::prekeyId($id);$raw=$this->get('prekeys',(string)$id);if($raw===null)return null;
        $pair=SignalState::unpack(json_decode($raw,true,16,JSON_THROW_ON_ERROR));
        if(!is_array($pair)||!is_string($pair['private']??null)||!is_string($pair['public']??null))throw new \UnexpectedValueException('Invalid saved Messenger prekey');
        return SignalState::normalizePair(['private'=>$pair['private'],'public'=>$pair['public']]);
    }
    /** @param KeyPair $pair */
    public function putPreKey(int $id,#[\SensitiveParameter]array $pair):void
    {
        self::prekeyId($id);$pair=SignalState::normalizePair($pair);
        $this->transaction(function()use($id,$pair){$old=$this->getPreKey($id);if($old!==null&&$old!==$pair)throw new \LogicException('Cannot replace allocated Messenger prekey');$this->set('prekeys',(string)$id,json_encode(SignalState::pack($pair),JSON_THROW_ON_ERROR));$next=(int)($this->get('meta','nextPreKey')??'1');$this->set('meta','nextPreKey',(string)max($next,$id+1));$pending=$this->pendingIds();if(!in_array($id,$pending,true)){$pending[]=$id;$this->set('meta','pendingPreKeys',json_encode($pending,JSON_THROW_ON_ERROR));}});
    }
    /** Save private counterparts before a caller can publish the public prekeys.
     * @return list<array{id:int,private:string,public:string}>
     */
    public function generatePreKeys(int $count):array
    {
        if($count<1||$count>1000)throw new \InvalidArgumentException('Use 1 to 1000 prekeys');
        return $this->transaction(function()use($count){$next=(int)($this->get('meta','nextPreKey')??'1');if($next<1||$next>0xffffff-$count+1)throw new \OverflowException('Messenger prekey IDs exhausted');$out=[];for($id=$next;$id<$next+$count;++$id){$pair=Crypto::x25519KeyPair();$this->putPreKey($id,$pair);$out[]=['id'=>$id,...$pair];}return $out;});
    }
    /** @return list<int> */
    private function pendingIds():array
    {
        $raw=$this->get('meta','pendingPreKeys');$ids=$raw===null?[]:json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($ids)||!array_is_list($ids))throw new \UnexpectedValueException('Invalid pending Messenger prekeys');foreach($ids as $id){if(!is_int($id))throw new \UnexpectedValueException('Invalid pending prekey ID');self::prekeyId($id);}return $ids;
    }
    /** @return list<array{id:int,public:string}> */
    public function pendingPreKeys():array{$out=[];foreach($this->pendingIds()as$id){$pair=$this->getPreKey($id);if($pair!==null)$out[]=['id'=>$id,'public'=>SignalState::rawPublicKey($pair['public'])];}return $out;}
    /** Only call after the matching server IQ result.
     * @param list<int> $ids
     */
    public function markPreKeysUploaded(array $ids):void{foreach($ids as $id)self::prekeyId($id);$this->transaction(fn()=>$this->set('meta','pendingPreKeys',json_encode(array_values(array_diff($this->pendingIds(),$ids)),JSON_THROW_ON_ERROR)));}
    public function consumePreKey(int $id):void{$this->transaction(function()use($id){self::prekeyId($id);$this->delete('prekeys',(string)$id);$this->markPreKeysUploaded([$id]);});}
    private static function prekeyId(int $id):void{if($id<1||$id>0xffffff)throw new \InvalidArgumentException('Invalid Messenger prekey ID');}
    public function __debugInfo():array{return ['encrypted_state'=>'[PRIVATE]'];}
    public function __serialize():array{throw new \LogicException('Do not serialize encrypted state storage');}
}
