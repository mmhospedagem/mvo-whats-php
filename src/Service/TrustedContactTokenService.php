<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Protocol\{BinaryData,BinaryNode,Jid};
use MVO\Signal\SignalStore;

/** Durable receiver/sender privacy tokens. Token bytes are never included in emitted diagnostics. */
final class TrustedContactTokenService
{
    /** @var \Closure(BinaryNode,float):BinaryNode */
    private \Closure $query;
    /** @var \Closure():array<string,mixed> */
    private \Closure $credentials;
    /** @var \Closure():float */
    private \Closure $clock;
    /** @var (\Closure():array<string,int>)|null */
    private ?\Closure $overrides;
    /** @var array<string,true> */
    private array $issuing=[];
    /** @param callable(BinaryNode,float):BinaryNode $query
     * @param callable():array<string,mixed> $credentials
     * @param callable():float|null $clock
     * @param callable():array<string,int>|null $configOverrides */
    public function __construct(callable $query,private readonly SignalStore $store,callable $credentials,?callable $clock=null,?callable $configOverrides=null)
    {
        $this->query=\Closure::fromCallable($query);$this->credentials=\Closure::fromCallable($credentials);$this->clock=$clock===null ? static fn():float=>microtime(true) : \Closure::fromCallable($clock);$this->setConfigOverrides($configOverrides);
    }
    /** @param callable():array<string,int>|null $configOverrides */
    public function setConfigOverrides(?callable $configOverrides):void {$this->overrides=$configOverrides===null ? null : \Closure::fromCallable($configOverrides);}
    public function resolveReceiverTokenNode(string $jid):?BinaryNode
    {
        $jid=self::user($jid);if($jid===null)return null;$record=$this->record($jid);$token=$record['tcToken'] ?? null;$timestamp=$record['tcTokenTimestamp'] ?? null;$config=$this->config();
        if(!is_string($token)||$token===''||!is_int($timestamp)||self::expired($timestamp,(int)($this->clock)(),$config['durationS'],$config['numBuckets']))return null;return new BinaryNode('tctoken',[],$token);
    }
    public function resolveTokenForMessage(string $jid):?BinaryNode
    {
        if(self::user($jid)===null)return null;$receiver=$this->resolveReceiverTokenNode($jid);if($receiver!==null)return $receiver;
        $salt=$this->store->get('privacy-token','nctSalt') ?? $this->store->get('history-meta','nctSalt');$me=($this->credentials)()['meLid'] ?? null;
        if(!is_string($salt)||$salt===''||!is_string($me))return null;return new BinaryNode('cstoken',[],hash_hmac('sha256',$me,$salt,true));
    }
    public function maybeIssueSenderToken(string $jid):void
    {
        $jid=self::user($jid);if($jid===null || isset($this->issuing[$jid]))return;$now=(int)($this->clock)();$config=$this->config();$record=$this->record($jid);$previous=$record['tcTokenSenderTimestamp'] ?? null;
        if(is_int($previous)&&$previous>0&&intdiv($previous,$config['senderDurationS'])===intdiv($now,$config['senderDurationS']))return;
        $this->issuing[$jid]=true;try{$this->issue($jid,$now);$this->write($jid,['tcTokenSenderTimestamp'=>$now]);}finally{unset($this->issuing[$jid]);}
    }
    public function queueSenderToken(string $jid):void {$jid=self::user($jid);if($jid!==null && $this->store->get('privacy-token-pending',$jid)===null)$this->store->set('privacy-token-pending',$jid,'0');}
    public function drainPending():void
    {
        foreach($this->store->entries('privacy-token-pending',10) as $jid=>$retryAt){if((int)$retryAt>(int)($this->clock)())continue;try{$this->maybeIssueSenderToken((string)$jid);$this->store->delete('privacy-token-pending',(string)$jid);}catch(\Throwable $e){$this->store->set('privacy-token-pending',(string)$jid,(string)((int)($this->clock)()+60));throw $e;}}
    }
    public function reissueOnIdentityChange(string $jid):void
    {
        $jid=self::user($jid);if($jid===null)return;$timestamp=$this->record($jid)['tcTokenSenderTimestamp'] ?? null;$config=$this->config();
        if(is_int($timestamp)&&$timestamp>0&&!self::expired($timestamp,(int)($this->clock)(),$config['senderDurationS'],$config['senderNumBuckets']))$this->issue($jid,$timestamp);
    }
    /** Authenticated transport notification only; malformed or unsupported rows are ignored. */
    public function handleNode(BinaryNode $node):bool
    {
        if($node->tag!=='notification' || ($node->attrs['type'] ?? '')!=='privacy_token')return false;$jid=self::user($node->attrs['from'] ?? '');if($jid===null)return true;
        foreach(ServiceTransport::children($node->child('tokens'),'token') as $token){$t=$token->attrs['t'] ?? '';if(($token->attrs['type'] ?? '')!=='trusted_contact'||!ctype_digit($t)||!is_string($token->content)||$token->content===''||strlen($token->content)>65536)continue;
            $timestamp=filter_var($t,FILTER_VALIDATE_INT);if($timestamp===false || $timestamp<0)continue;$this->write($jid,['tcToken'=>$token->content,'tcTokenTimestamp'=>$timestamp]);}return true;
    }
    /** @param list<array<string,mixed>> $conversations */
    public function hydrateFromHistorySync(array $conversations):void
    {
        foreach($conversations as $chat){$jid=self::user($chat['jid'] ?? $chat['id'] ?? '');if($jid===null)continue;$fields=[];
            if(is_string($chat['tcToken'] ?? null))$fields['tcToken']=$chat['tcToken'];foreach(['tcTokenTimestamp','tcTokenSenderTimestamp'] as $field)if(is_int($chat[$field] ?? null))$fields[$field]=$chat[$field];if($fields!==[])$this->write($jid,$fields);}
    }
    public function hydrateNctSaltFromHistorySync(string $salt):void {$this->handleNctSaltSync($salt);}
    public function handleNctSaltSync(?string $salt):void
    {
        if($salt===null){$this->store->set('privacy-token','nctSalt','');$this->store->delete('history-meta','nctSalt');return;}if($salt===''||strlen($salt)>65536)throw new \InvalidArgumentException('Invalid NCT salt');$this->store->set('privacy-token','nctSalt',$salt);
    }
    /** Bucket expiry intentionally includes the boundary timestamp, matching upstream. */
    public static function expired(int $timestamp,int $now,int $duration,int $buckets):bool {if($duration<1||$buckets<1)throw new \InvalidArgumentException('Invalid token bucket configuration');return $timestamp<(intdiv($now,$duration)-$buckets)*$duration;}
    /** @return array{durationS:int,numBuckets:int,senderDurationS:int,senderNumBuckets:int} */
    private function config():array
    {
        $c=['durationS'=>604800,'numBuckets'=>4,'senderDurationS'=>604800,'senderNumBuckets'=>4];$overrides=$this->overrides===null ? [] : ($this->overrides)();
        foreach($c as $key=>$default){$v=$overrides[$key] ?? $default;if(!is_int($v)||$v<1)throw new \UnexpectedValueException('Invalid server privacy token configuration');$c[$key]=str_ends_with($key,'DurationS')||$key==='durationS' ? min($v,15552000) : $v;}return $c;
    }
    private function issue(string $jid,int $timestamp):void
    {
        $response=($this->query)(new BinaryNode('iq',['type'=>'set','xmlns'=>'privacy','to'=>'s.whatsapp.net'],[new BinaryNode('tokens',[],[new BinaryNode('token',['jid'=>$jid,'t'=>(string)$timestamp,'type'=>'trusted_contact'])])]),30.0);ServiceTransport::assertResult($response,'privacy.issueToken');
    }
    /** @return array<string,mixed> */
    private function record(string $jid):array
    {
        $bytes=$this->store->get('privacy-token-records',$jid);$record=$bytes===null ? [] : BinaryData::decode($bytes);if(!is_array($record))throw new \UnexpectedValueException('Invalid privacy token record');
        $history=$this->store->get('history-chats',$jid);if($history!==null){$chat=BinaryData::decode($history);if(is_array($chat)){
            if(is_int($chat['tcTokenTimestamp'] ?? null)&&$chat['tcTokenTimestamp']>($record['tcTokenTimestamp'] ?? -1))foreach(['tcToken','tcTokenTimestamp'] as $field)if(isset($chat[$field]))$record[$field]=$chat[$field];
            if(is_int($chat['tcTokenSenderTimestamp'] ?? null)&&$chat['tcTokenSenderTimestamp']>($record['tcTokenSenderTimestamp'] ?? -1))$record['tcTokenSenderTimestamp']=$chat['tcTokenSenderTimestamp'];
        }}return $record;
    }
    /** @param array<string,mixed> $fields */
    private function write(string $jid,array $fields):void {$this->store->set('privacy-token-records',$jid,BinaryData::encode([...$this->record($jid),...$fields]));}
    private static function user(string $jid):?string
    {
        try{$parsed=Jid::parse($jid)->canonical();return in_array($parsed->server,['s.whatsapp.net','lid'],true) ? $parsed->bare() : null;}catch(\InvalidArgumentException){return null;}
    }
}
