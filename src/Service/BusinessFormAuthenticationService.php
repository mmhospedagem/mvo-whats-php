<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Protocol\{BinaryNode,Jid};

/** Native SMB authentication observed in the Business app; server authorization is still required. */
final class BusinessFormAuthenticationService
{
    private const OPERATION='business.forms.authenticate';
    /** @var \Closure(float):void */
    private \Closure $poll;
    /** @var \Closure():bool */
    private \Closure $isConnected;
    /** @var \Closure():float */
    private \Closure $clock;
    private ?string $owner=null;
    private ?string $accessToken=null;
    private ?string $nonce=null;
    private ?float $deadline=null;
    private bool $busy=false;
    private int $generation=0;
    private string $state='idle';
    private ?string $serverCode=null;
    private bool $recoveryRequired=false;
    /** @var array<string,array{until:float,escalated:bool,code:string}> */
    private array $lockouts=[];
    /** @var array<string,true> Only hashes, bounded in memory, including across invalidate(). */
    private array $seenNonces=[];

    /**
     * @param callable(float):void $poll Runs the owning session loop; never an application listener.
     * @param callable():bool $isConnected
     * @param callable():float|null $clock Monotonic seconds, not a wall-clock timestamp.
     */
    public function __construct(
        private readonly ServiceTransport $transport,
        callable $poll,
        callable $isConnected,
        ?callable $clock=null,
        private readonly float $timeout=32.0,
    ) {
        if(!is_finite($timeout)||$timeout<=0||$timeout>32.0)throw new \InvalidArgumentException('Business form authentication timeout must be greater than zero and at most 32 seconds');
        $this->poll=\Closure::fromCallable($poll);
        $this->isConnected=\Closure::fromCallable($isConnected);
        $this->clock=$clock===null?static fn():float=>hrtime(true)/1e9:\Closure::fromCallable($clock);
    }

    /** Gets an opaque token for the current account. Never persists it or retries an authentication. */
    public function token():string
    {
        if($this->busy)throw new \LogicException('Business form authentication is already pending');
        $owner=$this->context();
        if($owner===null)throw new ServiceException(self::OPERATION,null,'A connected session with its own phone identity is required');
        if($this->accessToken!==null)return $this->accessToken;
        $lockout=$this->lockouts[$owner]??null;
        if($lockout!==null&&$lockout['until']>$this->now()){
            $this->state='cooldown';$this->serverCode=$lockout['code'];
            throw new ServiceException(self::OPERATION,$lockout['code'],'Business authentication is in the server lockout cooldown');
        }
        if($this->recoveryRequired)throw new ServiceException(self::OPERATION,null,'Business account recovery must be completed in the official application');
        $startedAt=$this->now();$this->owner=$owner;$this->busy=true;$this->deadline=$startedAt+$this->timeout;
        $this->nonce=null;$this->serverCode=null;$this->state='requesting_nonce';
        $generation=$this->generation;
        try{
            $first=$this->request('118',null,$generation);
            $this->assertActive($generation);
            $result=$this->single($first,'result');
            $status=$this->single($result,'status');
            $value=ServiceTransport::text($status);
            if($value==='RecoveryRequired'){
                $this->recoveryRequired=true;$this->state='recovery_required';
                throw new ServiceException(self::OPERATION,null,'Business account recovery must be completed in the official application');
            }
            if($value!=='Success')throw new ServiceException(self::OPERATION,null,'Invalid Business authentication start response');
            $this->state='waiting_nonce';$this->deadline=$this->now()+$this->timeout;
            while($this->nonce===null){
                $this->assertActive($generation);
                ($this->poll)(min(0.25,$this->remaining()));
            }
            $this->assertActive($generation);
            $this->state='exchanging_token';$this->deadline=$this->now()+$this->timeout;
            $reply=$this->request('104',[new BinaryNode('parameters',[],[new BinaryNode('code',[],$this->nonce)])],$generation);
            $this->assertActive($generation);
            $person=$this->single($reply,'business_person');
            $token=ServiceTransport::text($this->single($reply,'access_token'));
            $cookies=ServiceTransport::text($this->single($reply,'session_cookies'));
            if(preg_match('/^[1-9][0-9]{0,19}$/D',$person?->attrs['id']??'')!==1||$token===null||$token===''||strlen($token)>65536||preg_match('/[\x00-\x1f\x7f]/',$token)===1||$cookies===null||!self::validCookies($cookies)){
                throw new ServiceException(self::OPERATION,null,'Invalid Business authentication token response');
            }
            $this->accessToken=$token;$this->state='authenticated';
            unset($this->lockouts[$owner]);
            return $token;
        }catch(\Throwable $error){
            $code=$error instanceof ServiceException&&preg_match('/^[0-9]{1,5}$/D',$error->serverCode??'')===1?$error->serverCode:null;
            if($generation===$this->generation){
                $this->accessToken=null;$this->serverCode=$code;
                if(in_array($code,['429','431'],true)){
                    // HSx/H6b: first lockout is 600000 ms, subsequent lockout 86400000 ms.
                    $escalated=$this->lockouts[$owner]['escalated']??false;
                    $this->lockouts[$owner]=['until'=>$this->now()+($escalated?86400.0:600.0),'escalated'=>true,'code'=>$code];
                    $this->state='cooldown';
                }elseif(!$this->recoveryRequired&&!in_array($this->state,['disconnected','identity_changed','timed_out','invalidated'],true))$this->state='failed';
            }
            if($error instanceof ServiceException)throw new ServiceException(self::OPERATION,$code,$this->safeFailureReason());
            if($error instanceof \LogicException)throw $error;
            throw new ServiceException(self::OPERATION,null,$this->safeFailureReason());
        }finally{
            $this->nonce=null;$this->deadline=null;$this->busy=false;
        }
    }

    /** Parses only server business notifications during a pending attempt. Does no I/O or callbacks. */
    public function ingestServerNotification(BinaryNode $node):bool
    {
        if(!$this->busy||$this->deadline===null||!in_array($this->state,['requesting_nonce','waiting_nonce'],true)||$this->nonce!==null)return false;
        if($node->tag!=='notification'||($node->attrs['type']??'')!=='business'||($node->attrs['category']??null)==='peer'||(isset($node->attrs['from'])&&$node->attrs['from']!=='s.whatsapp.net'))return false;
        if($this->now()>=$this->deadline)return false;
        $nonces=ServiceTransport::children($node,'wa_ad_account_nonce');
        if(count($nonces)!==1)return false;
        $nonce=ServiceTransport::text($nonces[0]);
        if($nonce===null||strlen($nonce)<4||strlen($nonce)>64||preg_match('/[\x00-\x1f\x7f]/',$nonce)===1)return false;
        $hash=hash('sha256',$this->owner."\0".$nonce);
        if(isset($this->seenNonces[$hash]))return false;
        $this->seenNonces[$hash]=true;
        if(count($this->seenNonces)>128)unset($this->seenNonces[array_key_first($this->seenNonces)]);
        $this->nonce=$nonce;
        return true;
    }

    /** Clear account secrets and pending state. Account lockouts are not cleared by reconnecting. */
    public function invalidate():void
    {
        ++$this->generation;$this->accessToken=null;$this->owner=null;$this->nonce=null;
        $this->deadline=null;$this->recoveryRequired=false;$this->serverCode=null;$this->state='invalidated';
    }

    /** @return array{state:string,authenticated:bool,pending:bool,recoveryRequired:bool,serverCode:?string,retryAfterSeconds:?int,supportVerified:bool} */
    public function status():array
    {
        $owner=$this->context();
        $lockout=$owner===null?null:($this->lockouts[$owner]??null);
        $retryAfter=$lockout!==null&&$lockout['until']>$this->now()?(int)ceil($lockout['until']-$this->now()):null;
        if($retryAfter!==null){$this->state='cooldown';$this->serverCode=$lockout['code'];}
        elseif($this->state==='cooldown'){$this->state='idle';$this->serverCode=null;}
        return ['state'=>$this->state,'authenticated'=>$this->accessToken!==null,'pending'=>$this->busy&&$this->deadline!==null,
            'recoveryRequired'=>$this->recoveryRequired,'serverCode'=>$this->serverCode,'retryAfterSeconds'=>$retryAfter,'supportVerified'=>false];
    }

    /** Avoid displaying opaque credentials in ordinary debug output. @return array<string,mixed> */
    public function __debugInfo():array{return $this->status();}

    /** @param list<BinaryNode>|null $children */
    private function request(string $smaxId,?array $children,int $generation):BinaryNode
    {
        $this->assertActive($generation);
        // DGC.A0Z completes Dul's generated native node with type=get and the server destination.
        return $this->transport->request(self::OPERATION,new BinaryNode('iq',[
            'xmlns'=>'fb:thrift_iq','smax_id'=>$smaxId,'from'=>$this->owner??'',
            'type'=>'get','to'=>'s.whatsapp.net','id'=>bin2hex(random_bytes(8)),
        ],$children),$this->remaining());
    }

    private function assertActive(int $generation):void
    {
        if($generation!==$this->generation)throw new ServiceException(self::OPERATION,null,'Business authentication was invalidated');
        if($this->context()===null||$generation!==$this->generation)throw new ServiceException(self::OPERATION,null,'Business authentication session changed or disconnected');
        if($this->deadline===null||$this->remaining()<=0){$this->state='timed_out';throw new ServiceException(self::OPERATION,null,'Business authentication timed out while waiting for the server');}
    }

    /** Returns only this transport's own PN, never a LID-derived phone identity. */
    private function context():?string
    {
        if(!(($this->isConnected)())){
            if($this->accessToken!==null||$this->owner!==null||$this->deadline!==null)$this->invalidate();
            $this->state='disconnected';return null;
        }
        $credentials=$this->transport->credentials();
        $jid=$credentials['meJid']??$credentials['me']['id']??$credentials['meId']??null;
        $phone=null;
        if(is_string($jid)){
            try{$parsed=Jid::parse($jid)->canonical();if($parsed->server==='s.whatsapp.net'&&preg_match('/^[1-9][0-9]{0,14}$/D',$parsed->user)===1)$phone=$parsed->bare();}catch(\InvalidArgumentException){}
        }
        if($this->owner!==null&&$this->owner!==$phone){$this->invalidate();$this->state='identity_changed';return null;}
        return $phone;
    }

    private function now():float
    {
        $now=($this->clock)();
        if(!is_finite($now))throw new \LogicException('Business authentication requires a finite monotonic clock');
        return $now;
    }
    private function remaining():float{return max(0.0,($this->deadline??$this->now())-$this->now());}

    private function safeFailureReason():string
    {
        return match($this->state){
            'recovery_required'=>'Business account recovery must be completed in the official application',
            'cooldown'=>'Business authentication is in the server lockout cooldown',
            'timed_out'=>'Business authentication timed out while waiting for the server',
            'disconnected','identity_changed','invalidated'=>'Business authentication session changed or disconnected',
            default=>'Business authentication was rejected or returned an invalid response',
        };
    }

    private function single(?BinaryNode $node,string $tag):?BinaryNode
    {
        $children=ServiceTransport::children($node,$tag);
        return count($children)===1?$children[0]:null;
    }

    private static function validCookies(string $json):bool
    {
        if(strlen($json)>1_048_576)return false;
        try{$cookies=json_decode($json,false,32,JSON_THROW_ON_ERROR);}catch(\JsonException){return false;}
        if(!is_array($cookies))return false;
        foreach($cookies as$cookie)if(!$cookie instanceof \stdClass)return false;
        return true;
    }
}
