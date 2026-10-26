<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Message\{MessageCodec,MessageService};
use MVO\Protocol\{BinaryCodec,BinaryData,BinaryNode};
use MVO\Signal\{SignalProtocol,SignalStore};
use PHPUnit\Framework\TestCase;

/** Real local Signal peers; every transport callback is synthetic. */
final class RecipientRetryTrustTest extends TestCase
{
    private const LOCAL='5511000000000:5@s.whatsapp.net';
    private const REMOTE='5522000000000@s.whatsapp.net';
    private string $path;
    private SignalStore $store;
    private SignalStore $remoteStore;
    private SignalProtocol $signal;
    private SignalProtocol $remoteSignal;
    private array $credentials;
    private array $remoteCredentials;
    private array $fetchedCredentials;
    private array $key;
    private array $retryWrites=[];
    private array $events=[];
    private int $bundleReads=0;
    private ?\Closure $onRetry=null;

    protected function setUp():void
    {
        $path=tempnam(sys_get_temp_dir(),'mvo-retry-trust-');
        if($path===false)throw new \RuntimeException('Cannot create local Signal database');
        $this->path=$path;
        $this->credentials=self::credentials(self::LOCAL);
        $this->remoteCredentials=$this->fetchedCredentials=self::credentials(self::REMOTE);
        $this->store=new SignalStore($this->path);
        $this->remoteStore=new SignalStore(':memory:');
        $this->key=$this->remoteStore->generatePreKeys(1)[0];
        $this->signal=new SignalProtocol($this->store,$this->credentials);
        $this->remoteSignal=new SignalProtocol($this->remoteStore,$this->remoteCredentials);
    }

    protected function tearDown():void
    {
        $this->store->close();$this->remoteStore->close();
        unset($this->signal,$this->remoteSignal,$this->store,$this->remoteStore);
        gc_collect_cycles();
        foreach([$this->path,$this->path.'-wal',$this->path.'-shm']as$file)if(is_file($file))unlink($file);
    }

    private static function credentials(string $jid):array
    {
        $credentials=CredentialStore::fresh();
        $credentials['registered']=true;$credentials['meJid']=$jid;$credentials['serverHasPreKeys']=true;
        $credentials['signedIdentity']=['details'=>'fixture','accountSignatureKey'=>random_bytes(32),
            'accountSignature'=>random_bytes(64),'deviceSignature'=>random_bytes(64)];
        return $credentials;
    }

    private function service():MessageService
    {
        return new MessageService($this->signal,$this->store,fn():array=>$this->credentials,static function():void{},
            function(BinaryNode $node):BinaryNode{
                if(($node->attrs['xmlns']??'')==='usync')return new BinaryNode('iq',['type'=>'result'],[
                    new BinaryNode('usync',[],[new BinaryNode('list',[],[new BinaryNode('user',['jid'=>self::REMOTE],[
                        new BinaryNode('devices',[],[new BinaryNode('device-list',[],[new BinaryNode('device',['id'=>'0'])])]),
                    ])])]),
                ]);
                if(($node->attrs['xmlns']??'')==='encrypt'){
                    ++$this->bundleReads;
                    return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('list',[],[
                        new BinaryNode('user',['jid'=>self::REMOTE],[
                            new BinaryNode('registration',[],pack('N',$this->fetchedCredentials['registrationId'])),
                            ...self::keys($this->fetchedCredentials,$this->key),
                        ]),
                    ])]);
                }
                self::assertSame('message',$node->tag,'Unexpected synthetic transport query');
                if($node->child('enc')!==null){
                    $this->retryWrites[]=(new BinaryCodec())->encode($node);
                    if($this->onRetry!==null)($this->onRetry)($node);
                }
                return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
            },static function():void{},function(string $name,array $payload):void{$this->events[]=[$name,$payload];});
    }

    private static function keys(array $credentials,array $key):array
    {
        $signed=$credentials['signedPreKey'];
        return [new BinaryNode('identity',[],$credentials['identityKeyPair']['public']),
            new BinaryNode('skey',[],[new BinaryNode('id',[],substr(pack('N',$signed['id']),1)),
                new BinaryNode('value',[],$signed['public']),new BinaryNode('signature',[],$signed['signature'])]),
            new BinaryNode('key',[],[new BinaryNode('id',[],substr(pack('N',$key['id']),1)),new BinaryNode('value',[],$key['public'])])];
    }

    private function request(string $id,int $count,?array $credentials=null,bool $includeKeys=true):BinaryNode
    {
        $credentials??=$this->remoteCredentials;
        return new BinaryNode('receipt',['id'=>$id,'from'=>self::REMOTE,'type'=>'retry'],[
            new BinaryNode('retry',['id'=>$id,'count'=>(string)$count]),
            new BinaryNode('registration',[],pack('N',$credentials['registrationId'])),
            ...($includeKeys?[new BinaryNode('keys',[],self::keys($credentials,$this->key))]:[]),
        ]);
    }

    private static function jobKey(string $id):string{return hash('sha256',$id."\0".self::REMOTE);}

    private function restart():void
    {
        $this->store->close();
        $this->store=new SignalStore($this->path);
        $this->signal=new SignalProtocol($this->store,$this->credentials);
    }

    private function retryErrors():array{return array_values(array_map(static fn(array $event):array=>$event[1],array_filter(
        $this->events,static fn(array $event):bool=>$event[0]==='message_error'&&($event[1]['reason']??'')==='recipient_retry_failed')));}

    public function testChangedIdentityIsDurablyBlockedWithoutTrustMutationOrAddressLeak():void
    {
        $service=$this->service();$id=$service->sendText(self::REMOTE,'Synthetic trust test')['id'];
        $before=$this->store->getSession(self::REMOTE);$trusted=$this->store->getIdentity(self::REMOTE);
        $outbox=$this->store->get('message-outbox',$id);
        $replacement=self::credentials(self::REMOTE);$request=$this->request($id,2,$replacement);
        $service->handleNode($request);$service->drainPending();
        self::assertSame([],$this->retryWrites);
        self::assertSame($trusted,$this->store->getIdentity(self::REMOTE));
        self::assertSame($before,$this->store->getSession(self::REMOTE));
        self::assertSame($outbox,$this->store->get('message-outbox',$id));
        self::assertSame([],$this->store->entries('recipient-retry-pending'));
        $blocked=BinaryData::decode($this->store->get('recipient-retry-blocked',self::jobKey($id)));
        self::assertSame((new BinaryCodec())->encode($request),$blocked['request']);
        self::assertSame('signal_trusted_identity_changed',$blocked['blockedReason']);
        $errors=$this->retryErrors();self::assertCount(1,$errors);
        self::assertSame('signal_trusted_identity_changed',$errors[0]['code']);
        self::assertSame('verify_recipient_identity',$errors[0]['action']);
        self::assertSame('blocked',$errors[0]['retryStatus']);
        self::assertSame('not_executed',$errors[0]['retryOutcome']);self::assertFalse($errors[0]['retryable']);
        $json=json_encode($errors,JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString(self::REMOTE,$json);
        self::assertStringNotContainsString('5522000000000',$json);
        self::assertStringNotContainsString(base64_encode($replacement['identityKeyPair']['public']),$json);
        unset($service);$this->restart();$service=$this->service();
        for($count=1;$count<=4;++$count){$service->handleNode($this->request($id,$count,$replacement));$service->drainPending();}
        self::assertCount(1,$this->retryErrors(),'Repeated counts/restarts must not repeat the permanent failure');
        self::assertSame([],$this->retryWrites);self::assertSame(1,$this->bundleReads);
        self::assertSame($trusted,$this->store->getIdentity(self::REMOTE));
        self::assertSame($blocked,BinaryData::decode($this->store->get('recipient-retry-blocked',self::jobKey($id))));
    }

    public function testFetchedChangedIdentityUsesTheSameTrustRefusal():void
    {
        $service=$this->service();$id=$service->sendText(self::REMOTE,'Fetch trust test')['id'];
        $before=$this->store->getSession(self::REMOTE);$trusted=$this->store->getIdentity(self::REMOTE);
        $this->fetchedCredentials=self::credentials(self::REMOTE);
        $service->handleNode($this->request($id,3,$this->fetchedCredentials,false));$service->drainPending();
        self::assertSame(2,$this->bundleReads);self::assertSame([],$this->retryWrites);
        self::assertSame($before,$this->store->getSession(self::REMOTE));self::assertSame($trusted,$this->store->getIdentity(self::REMOTE));
        self::assertSame('signal_trusted_identity_changed',$this->retryErrors()[0]['code']);
    }

    public function testInvalidSignatureIsNotAnIdentityDecisionAndLaterValidCountCanRecover():void
    {
        $service=$this->service();$id=$service->sendText(self::REMOTE,'Signed retry test')['id'];
        $before=$this->store->getSession(self::REMOTE);$trusted=$this->store->getIdentity(self::REMOTE);
        $invalid=$this->remoteCredentials;$invalid['signedPreKey']['signature']=str_repeat("\0",64);
        $request=$this->request($id,2,$invalid);$service->handleNode($request);$service->drainPending();
        self::assertSame([],$this->retryWrites);self::assertSame($before,$this->store->getSession(self::REMOTE));
        self::assertSame($trusted,$this->store->getIdentity(self::REMOTE));self::assertSame([],$this->store->entries('recipient-retry-blocked'));
        self::assertSame('recipient_retry_failed',$this->retryErrors()[0]['code']);
        $service->handleNode($request);$service->drainPending();self::assertCount(1,$this->retryErrors());
        $plain=null;
        $this->onRetry=function(BinaryNode $node)use(&$plain):void{
            $enc=$node->child('enc');$plain=MessageCodec::decode(MessageCodec::unpad($this->remoteSignal->decrypt(self::LOCAL,$enc->attrs['type'],$enc->content)));
        };
        $service->handleNode($this->request($id,3));$service->drainPending();
        self::assertSame('Signed retry test',$plain['text']);self::assertCount(1,$this->retryWrites);
        self::assertSame([],$this->store->entries('recipient-retry-pending'));self::assertSame($trusted,$this->store->getIdentity(self::REMOTE));
    }

    public function testHigherCountCannotReplaceUncertainPreparedCiphertextAcrossRestart():void
    {
        $service=$this->service();$id=$service->sendText(self::REMOTE,'Uncertain retry test')['id'];
        $this->onRetry=static function():never{throw new \RuntimeException('Synthetic missing ACK with private address '.self::REMOTE);};
        $service->handleNode($this->request($id,1));$service->drainPending();
        $key=self::jobKey($id);$pending=$this->store->get('recipient-retry-pending',$key);
        $ratchet=$this->store->getSession(self::REMOTE);
        $service->handleNode($this->request($id,4,self::credentials(self::REMOTE)));
        self::assertSame($pending,$this->store->get('recipient-retry-pending',$key));
        $job=BinaryData::decode($pending);$job['nextAttempt']=0;$this->store->set('recipient-retry-pending',$key,BinaryData::encode($job));
        self::assertStringNotContainsString(self::REMOTE,json_encode($this->retryErrors(),JSON_THROW_ON_ERROR));
        unset($service);$this->restart();$service=$this->service();$this->onRetry=null;$service->drainPending();
        self::assertCount(2,$this->retryWrites);self::assertSame($this->retryWrites[0],$this->retryWrites[1]);
        self::assertSame($ratchet,$this->store->getSession(self::REMOTE));
        self::assertSame([],$this->store->entries('recipient-retry-pending'));self::assertSame([],$this->store->entries('recipient-retry-blocked'));
    }

    public function testExhaustedUncertainWritesRetainCiphertextWithoutSpinningOrReencrypting():void
    {
        $service=$this->service();$id=$service->sendText(self::REMOTE,'Exhaustion test')['id'];
        $this->onRetry=static function():never{throw new \RuntimeException('Synthetic missing ACK');};
        $service->handleNode($this->request($id,1));$key=self::jobKey($id);$ratchet=null;
        for($attempt=1;$attempt<=4;++$attempt){
            $job=BinaryData::decode($this->store->get('recipient-retry-pending',$key));$job['nextAttempt']=0;
            $this->store->set('recipient-retry-pending',$key,BinaryData::encode($job));$service->drainPending();
            $ratchet??=$this->store->getSession(self::REMOTE);self::assertSame($ratchet,$this->store->getSession(self::REMOTE));
        }
        self::assertCount(4,$this->retryWrites);self::assertCount(1,array_unique($this->retryWrites));
        $blocked=BinaryData::decode($this->store->get('recipient-retry-blocked',$key));
        self::assertSame($this->retryWrites[0],$blocked['prepared']);
        self::assertSame('unknown',$this->retryErrors()[3]['retryOutcome']);self::assertSame('blocked',$this->retryErrors()[3]['retryStatus']);
        unset($service);$this->restart();$service=$this->service();
        $service->handleNode($this->request($id,4));$service->drainPending();$service->drainPending();
        self::assertCount(4,$this->retryWrites);self::assertCount(4,$this->retryErrors());self::assertSame($ratchet,$this->store->getSession(self::REMOTE));
        self::assertSame([],$this->store->entries('recipient-retry-pending'));
    }

    public function testBlockedRequestsDoNotStarveOtherPendingRetries():void
    {
        $service=$this->service();$id=$service->sendText(self::REMOTE,'No starvation')['id'];
        for($i=0;$i<101;++$i)$this->store->set('recipient-retry-blocked','blocked-'.$i,BinaryData::encode(['blockedReason'=>'signal_trusted_identity_changed']));
        $service->handleNode($this->request($id,1));$service->drainPending();
        self::assertCount(1,$this->retryWrites);self::assertSame([],$this->store->entries('recipient-retry-pending'));
        self::assertCount(101,$this->store->entries('recipient-retry-blocked'));
    }

    private static function allowBlockedCleanup(MessageService $service):void
    {
        (new \ReflectionProperty($service,'recipientRetryBlockedCleanupAt'))->setValue($service,0);
    }

    public function testExpiredBlockIsPrunedWithoutChangingOutboxPendingOrSignal():void
    {
        $service=$this->service();$id=$service->sendText(self::REMOTE,'Retention test')['id'];
        $service->handleNode($this->request($id,2,self::credentials(self::REMOTE)));$service->drainPending();
        self::assertNotNull($this->store->get('recipient-retry-blocked',self::jobKey($id)));
        $outbox=json_decode($this->store->get('message-outbox',$id),true,32,JSON_THROW_ON_ERROR);
        $outbox['metadata']['timestamp']=time()-604801;
        $outboxBytes=json_encode($outbox,JSON_THROW_ON_ERROR);$this->store->set('message-outbox',$id,$outboxBytes);
        $future=BinaryData::encode(['request'=>(new BinaryCodec())->encode($this->request('unrelated-future-retry',1)),
            'id'=>'unrelated-future-retry','requester'=>self::REMOTE,'count'=>1,'nextAttempt'=>time()+3600,'attempt'=>0]);
        $this->store->set('recipient-retry-pending','unrelated-pending',$future);
        $ratchet=$this->store->getSession(self::REMOTE);$identity=$this->store->getIdentity(self::REMOTE);
        self::allowBlockedCleanup($service);$service->drainPending();
        self::assertNull($this->store->get('recipient-retry-blocked',self::jobKey($id)));
        self::assertSame($outboxBytes,$this->store->get('message-outbox',$id));
        self::assertSame($future,$this->store->get('recipient-retry-pending','unrelated-pending'));
        self::assertSame($ratchet,$this->store->getSession(self::REMOTE));self::assertSame($identity,$this->store->getIdentity(self::REMOTE));
        $service->handleNode($this->request($id,4));$service->drainPending();
        self::assertSame([],$this->retryWrites,'Expired block cleanup must not make its old request eligible again');
        self::assertCount(1,$this->retryErrors());
    }

    public function testMissingOutboxPrunesBlockWhileAnEligibleMessageRemainsBlocked():void
    {
        $service=$this->service();$replacement=self::credentials(self::REMOTE);
        $gone=$service->sendText(self::REMOTE,'Orphan retention test')['id'];
        $service->handleNode($this->request($gone,2,$replacement));$service->drainPending();
        $fresh=$service->sendText(self::REMOTE,'Eligible retention test')['id'];
        $service->handleNode($this->request($fresh,2,$replacement));$service->drainPending();
        $keep=$this->store->get('recipient-retry-blocked',self::jobKey($fresh));self::assertNotNull($keep);
        $this->store->delete('message-outbox',$gone);
        self::allowBlockedCleanup($service);$service->drainPending();
        self::assertNull($this->store->get('recipient-retry-blocked',self::jobKey($gone)));
        self::assertSame($keep,$this->store->get('recipient-retry-blocked',self::jobKey($fresh)));
        $service->handleNode($this->request($fresh,4,$replacement));$service->drainPending();
        self::assertCount(2,$this->retryErrors());self::assertSame([],$this->retryWrites);
    }

    public function testRetentionIsThrottledAndPagesPastFreshBlocksWithoutStarvation():void
    {
        $service=$this->service();$id=$service->sendText(self::REMOTE,'Paged retention test')['id'];
        for($i=1;$i<=37;++$i)$this->store->set('recipient-retry-blocked',sprintf('%03d',$i),BinaryData::encode([
            'id'=>$i<=17?$id:'missing-outbox-'.$i,'blockedReason'=>'signal_trusted_identity_changed',
        ]));
        $service->drainPending();self::assertCount(37,$this->store->entries('recipient-retry-blocked'));
        $service->drainPending();self::assertCount(37,$this->store->entries('recipient-retry-blocked'),'Immediate polls must not repeat retention scans');
        self::allowBlockedCleanup($service);$service->drainPending();
        self::assertCount(22,$this->store->entries('recipient-retry-blocked'),'Second page must progress beyond retained rows, inspecting at most 16');
        self::allowBlockedCleanup($service);$service->drainPending();self::assertCount(17,$this->store->entries('recipient-retry-blocked'));
        self::allowBlockedCleanup($service);$service->drainPending();self::assertCount(17,$this->store->entries('recipient-retry-blocked'));
        self::assertSame([],$this->retryWrites);self::assertSame([],$this->retryErrors());
    }
}
