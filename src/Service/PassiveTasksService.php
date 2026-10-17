<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Crypto\{Crypto,XEdDsa};
use MVO\Protocol\{BinaryData,BinaryNode};
use MVO\Signal\{SignalProtocol,SignalState,SignalStore};

/** Post-login key digest/recovery and 27-day signed-prekey maintenance, using real IQ acknowledgements. */
final class PassiveTasksService
{
    /** @var \Closure(BinaryNode,float):BinaryNode */
    private \Closure $query;
    /** @var \Closure():array<string,mixed> */
    private \Closure $credentials;
    /** @var \Closure(array<string,mixed>):void */
    private \Closure $save;
    /** @var \Closure():void */
    private \Closure $upload;
    /** @var \Closure():float */
    private \Closure $clock;
    private bool $digestPending=false;
    private bool $busy=false;
    private float $digestRetryAt=0;
    private float $nextMaintenance=0;
    /** @param callable(BinaryNode,float):BinaryNode $query
     * @param callable():array<string,mixed> $credentials
     * @param callable(array<string,mixed>):void $saveCredentials
     * @param callable():void $uploadPreKeys Uploads local private counterparts before setting serverHasPreKeys.
     * @param callable():float|null $clock */
    public function __construct(callable $query,private readonly SignalStore $store,private readonly SignalProtocol $signal,callable $credentials,callable $saveCredentials,callable $uploadPreKeys,?callable $clock=null)
    {
        $this->query=\Closure::fromCallable($query);$this->credentials=\Closure::fromCallable($credentials);$this->save=\Closure::fromCallable($saveCredentials);$this->upload=\Closure::fromCallable($uploadPreKeys);$this->clock=$clock===null ? static fn():float=>microtime(true) : \Closure::fromCallable($clock);
    }
    /** @return array{valid:bool,shouldReupload:bool,reason:string,preKeyCount:int} */
    public function validateDigest():array
    {
        $response=($this->query)(new BinaryNode('iq',['type'=>'get','xmlns'=>'encrypt','to'=>'s.whatsapp.net'],[new BinaryNode('digest')]),30.0);
        if(($response->attrs['type'] ?? '')==='error'&&($response->child('error')?->attrs['code'] ?? '')==='404')return self::result('missing_remote_digest',0,true);
        ServiceTransport::assertResult($response,'signal.digest');$d=$response->child('digest') ?? throw new \UnexpectedValueException('Missing Signal digest');$s=$d->child('skey');$ids=[];
        $registration=self::bytes($d->child('registration'),4);$type=self::bytes($d->child('type'),1);$identity=self::bytes($d->child('identity'),32);$id=self::bytes($s?->child('id'),3);$public=self::bytes($s?->child('value'),32);$signature=self::bytes($s?->child('signature'),64);
        $list=$d->child('list') ?? throw new \UnexpectedValueException('Missing digest prekey list');foreach(ServiceTransport::children($list) as $key){if(count($ids)>=1000)throw new \LengthException('Digest prekey limit');$ids[]=self::uint(self::bytes($key,3));}
        $hash=$d->child('hash')?->content;if(!is_string($hash)||strlen($hash)<1||strlen($hash)>20)throw new \UnexpectedValueException('Invalid Signal digest hash length');$count=count($ids);
        if($type!=="\x05")return self::result('unsupported_key_bundle_type',$count,false);$credentials=($this->credentials)();$local=$credentials['signedPreKey'] ?? null;$pair=$credentials['identityKeyPair'] ?? null;
        if(!is_array($local)||!is_array($pair))return self::result('missing_local_state',$count,false);
        if(($credentials['registrationId'] ?? null)!==self::uint($registration))return self::result('registration_mismatch',$count,true);
        if(!hash_equals(SignalState::rawPublicKey($pair['public']),$identity))return self::result('identity_mismatch',$count,true);
        if(($local['id'] ?? null)!==self::uint($id)||!hash_equals(SignalState::rawPublicKey($local['public']),$public)||!hash_equals($local['signature'],$signature))return self::result('signed_prekey_mismatch',$count,true);
        $input=$identity.$public.$signature;foreach($ids as $keyId){$key=$keyId===0 ? null : $this->store->getPreKey($keyId);if($key===null)return self::result('missing_local_prekey',$count,true);$input.=SignalState::rawPublicKey($key['public']);}
        if(!hash_equals(substr(hash('sha1',$input,true),0,strlen($hash)),$hash))return self::result('hash_mismatch',$count,true);return self::result('ok',$count,false);
    }
    /** @return array{valid:bool,shouldReupload:bool,reason:string,preKeyCount:int} */
    public function validateAndRecover():array
    {
        $result=$this->validateDigest();if($result['shouldReupload']){$credentials=($this->credentials)();$credentials['serverHasPreKeys']=false;($this->save)($credentials);($this->upload)();}return $result;
    }
    /** A pending upload survives failure/restart and is retried with the same key ID and bytes.
     * @return array{rotated:bool,shouldDigestKey:bool,errorCode?:int} */
    public function rotateIfDue(bool $force=false):array
    {
        $now=(int)floor(($this->clock)()*1000);$interval=27*86400000;$last=$this->store->get('signal-maintenance','rotationTimestamp');$pending=$this->store->get('signal-maintenance','pendingRotation');
        $retryAt=$this->store->get('signal-maintenance','rotationRetryAt');if(!$force && $retryAt!==null && $now<(int)$retryAt)return ['rotated'=>false,'shouldDigestKey'=>false];
        if($pending===null&&!$force){if($last===null){$this->store->set('signal-maintenance','rotationTimestamp',(string)$now);return ['rotated'=>false,'shouldDigestKey'=>false];}if($now-(int)$last<$interval)return ['rotated'=>false,'shouldDigestKey'=>false];}
        $credentials=($this->credentials)();
        if($pending!==null){$key=BinaryData::decode($pending);if(!is_array($key))throw new \UnexpectedValueException('Invalid pending signed prekey');}
        else{$current=$credentials['signedPreKey']['id'] ?? 0;if(!is_int($current))throw new \UnexpectedValueException('Invalid signed prekey ID');$current=max($current,(int)($this->store->get('signal-maintenance','lastAllocatedSignedId') ?? '0'));if($current>=0xffffff)throw new \OverflowException('Signed prekey IDs exhausted');$pair=Crypto::x25519KeyPair();$key=['id'=>$current+1,...$pair,'signature'=>XEdDsa::sign($credentials['identityKeyPair']['private'],"\x05".$pair['public'])];
            $this->store->transaction(function()use($key):void{$this->signal->rememberSignedPreKey($key);$this->store->set('signal-maintenance','lastAllocatedSignedId',(string)$key['id']);$this->store->set('signal-maintenance','pendingRotation',BinaryData::encode($key));});}
        $response=($this->query)(new BinaryNode('iq',['type'=>'set','xmlns'=>'encrypt','to'=>'s.whatsapp.net'],[new BinaryNode('rotate',[],[self::signedNode($key)])]),30.0);
        if($response->tag!=='iq')throw new \UnexpectedValueException('Invalid signed prekey rotation response');$type=$response->attrs['type'] ?? '';
        if($type==='result'){$credentials=($this->credentials)();$credentials['signedPreKey']=$key;($this->save)($credentials);$this->signal->replaceSignedPreKey($key);$this->store->transaction(function()use($now):void{$this->store->delete('signal-maintenance','pendingRotation');$this->store->delete('signal-maintenance','rotationRetryAt');$this->store->set('signal-maintenance','rotationTimestamp',(string)$now);});return ['rotated'=>true,'shouldDigestKey'=>false];}
        if($type!=='error')throw new \UnexpectedValueException('Invalid signed prekey rotation response type');$raw=$response->child('error')?->attrs['code'] ?? ''; $code=ctype_digit($raw) ? (int)$raw : null;
        $shouldDigest=$code!==406 && !($code!==null&&$code>=500);$timestamp=$code!==null&&$code>=500 ? $now-$interval+86400000 : $now;
        // Retain candidate private keys even on a negative ACK; the server may already know this ID.
        $this->store->transaction(function()use($timestamp,$code,$now,$interval):void{if($code===406)$this->store->delete('signal-maintenance','pendingRotation');$this->store->set('signal-maintenance','rotationRetryAt',(string)($code!==null&&$code>=500 ? $now+86400000 : $now+$interval));$this->store->set('signal-maintenance','rotationTimestamp',(string)$timestamp);});
        return ['rotated'=>false,'shouldDigestKey'=>$shouldDigest]+($code===null ? [] : ['errorCode'=>$code]);
    }
    /** @return array{digest:array{valid:bool,shouldReupload:bool,reason:string,preKeyCount:int},rotation:array{rotated:bool,shouldDigestKey:bool,errorCode?:int}} */
    public function run():array
    {
        if($this->busy)throw new \LogicException('Signal maintenance is already running');$this->busy=true;try{$rotation=$this->store->get('signal-maintenance','pendingRotation')!==null ? $this->rotateIfDue() : null;$digest=$this->validateAndRecover();$rotation ??= $this->rotateIfDue();if($rotation['shouldDigestKey'])$digest=$this->validateAndRecover();return ['digest'=>$digest,'rotation'=>$rotation];}finally{$this->busy=false;$this->nextMaintenance=($this->clock)()+3600;}
    }
    public function handleNode(BinaryNode $node):bool
    {
        if($node->tag!=='notification'||($node->attrs['type'] ?? '')!=='encrypt'||$node->child('digest')===null)return false;$this->digestPending=true;return true;
    }
    public function tick():void
    {
        if($this->busy)return;$now=($this->clock)();$this->busy=true;
        try{
            if($this->digestPending && $now>=$this->digestRetryAt){$this->digestPending=false;try{$this->validateAndRecover();}catch(\Throwable $e){$this->digestPending=true;$this->digestRetryAt=$now+60;throw $e;}}
            if($now<$this->nextMaintenance)return;$this->nextMaintenance=$now+3600;$rotation=$this->rotateIfDue();if($rotation['shouldDigestKey'])$this->validateAndRecover();
        }finally{$this->busy=false;}
    }
    /** @param array<string,mixed> $key */
    private static function signedNode(array $key):BinaryNode {return new BinaryNode('skey',[],[new BinaryNode('id',[],substr(pack('N',$key['id']),1)),new BinaryNode('value',[],SignalState::rawPublicKey($key['public'])),new BinaryNode('signature',[],$key['signature'])]);}
    private static function bytes(?BinaryNode $node,int $length):string {if(!is_string($node?->content)||strlen($node->content)!==$length)throw new \UnexpectedValueException('Malformed Signal digest field');return $node->content;}
    private static function uint(string $bytes):int {$n=0;foreach(str_split($bytes) as $b)$n=($n<<8)|ord($b);return $n;}
    /** @return array{valid:bool,shouldReupload:bool,reason:string,preKeyCount:int} */
    private static function result(string $reason,int $count,bool $reupload):array {return ['valid'=>$reason==='ok','shouldReupload'=>$reupload,'reason'=>$reason,'preKeyCount'=>$count];}
}
