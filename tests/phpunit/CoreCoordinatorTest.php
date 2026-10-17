<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;
use MVO\Crypto\{Crypto,XEdDsa};
use MVO\History\HistoryStore;
use MVO\Protocol\{BinaryData,BinaryNode};
use MVO\Service\{AbPropsService,PassiveTasksService,ServiceTransport,TrustedContactTokenService};
use MVO\Signal\{SignalProtocol,SignalStore};

final class CoreCoordinatorTest extends TestCase
{
    private string $directory;
    private SignalStore $store;
    private SignalProtocol $signal;
    /** @var array<string,mixed> */
    private array $credentials;
    private float $now=1700000000;
    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-core-'.bin2hex(random_bytes(6));mkdir($this->directory);$this->store=new SignalStore($this->directory.'/state.sqlite');$this->credentials=CredentialStore::fresh();$this->credentials['meLid']='42:7@lid';$this->credentials['meJid']='5511000000000:7@s.whatsapp.net';$this->signal=new SignalProtocol($this->store,$this->credentials);
    }
    protected function tearDown():void {$this->store->close();gc_collect_cycles();foreach(glob($this->directory.'/*') ?: [] as $file)unlink($file);rmdir($this->directory);}
    /** @param callable(BinaryNode,float):BinaryNode $query */
    private function tokens(callable $query):TrustedContactTokenService {return new TrustedContactTokenService($query,$this->store,fn():array=>$this->credentials,fn():float=>$this->now);}
    /** @param callable(BinaryNode,float):BinaryNode $query
     * @param callable():void|null $upload */
    private function passive(callable $query,?callable $upload=null):PassiveTasksService {return new PassiveTasksService($query,$this->store,$this->signal,fn():array=>$this->credentials,function(array $c):void{$this->credentials=$c;},$upload ?? static function():void{},fn():float=>$this->now);}
    private static function ok():BinaryNode {return new BinaryNode('iq',['type'=>'result']);}
    public function testAbPropsFullDeltaTypedDefaultsAndPersistence():void
    {
        $catalogue=AbPropsService::catalogue();self::assertCount(2151,$catalogue);$queries=[];$responses=[new BinaryNode('iq',['type'=>'result'],[new BinaryNode('props',['hash'=>'H1','refresh_id'=>'8','refresh'=>'2'],[
            new BinaryNode('prop',['config_code'=>(string)$catalogue['acp_removal']['code'],'config_value'=>'True']),
            new BinaryNode('prop',['config_code'=>(string)$catalogue['tctoken_duration']['code'],'config_value'=>'-12ms']),
            new BinaryNode('prop',['config_code'=>'99999999','config_value'=>'1'])])]),
            new BinaryNode('iq',['type'=>'result'],[new BinaryNode('props',['delta_update'=>'true','hash'=>'H2'],[new BinaryNode('prop',['config_code'=>(string)$catalogue['tctoken_duration']['code'],'config_value'=>'not an int'])])]),
            new BinaryNode('iq',['type'=>'result'],[new BinaryNode('props',['delta_update'=>'false'])])];
        $service=new AbPropsService(function(BinaryNode $node,float $timeout)use(&$queries,&$responses):BinaryNode{$queries[]=$node;return array_shift($responses);},$this->store,fn():float=>$this->now);
        self::assertFalse($service->getConfigValue('acp_removal'));$result=$service->sync();self::assertSame(['received'=>3,'applied'=>2,'isDelta'=>false,'refresh'=>600],$result);self::assertSame('abt',$queries[0]->attrs['xmlns']);self::assertSame(['protocol'=>'1'],$queries[0]->child('props')->attrs);self::assertTrue($service->getConfigValue('acp_removal'));self::assertSame(-12,$service->getConfigValue('tctoken_duration'));
        $service->sync();self::assertSame(['protocol'=>'1','hash'=>'H1','refresh_id'=>'8'],$queries[1]->child('props')->attrs);self::assertTrue($service->getConfigValue('acp_removal'));self::assertSame($catalogue['tctoken_duration']['defaultValue'],$service->getConfigValue('tctoken_duration'));
        $restored=new AbPropsService(static fn(BinaryNode $n,float $t):BinaryNode=>self::ok(),$this->store);self::assertTrue($restored->getConfigValue('acp_removal'));self::assertSame('H2',$restored->getState()['hash']);$service->sync();self::assertFalse($service->getConfigValue('acp_removal'));
    }
    public function testAbPropsRetriesAndTickAreBounded():void
    {
        $queries=0;$service=new AbPropsService(function(BinaryNode $n,float $t)use(&$queries):BinaryNode{$queries++;if($queries<3)throw new \RuntimeException('offline');return self::ok();},clock:fn():float=>$this->now);$service->tick();self::assertSame(3,$queries);$service->tick();self::assertSame(3,$queries);$this->now+=86400;$service->tick();self::assertSame(4,$queries);
        $this->expectException(\InvalidArgumentException::class);$service->getConfigValue('invented_feature');
    }
    public function testTrustedReceiverExpiryBoundaryAndCsFallback():void
    {
        $tokens=$this->tokens(static fn(BinaryNode $n,float $t):BinaryNode=>self::ok());$duration=604800;$cutoff=(intdiv((int)$this->now,$duration)-4)*$duration;
        $tokens->hydrateFromHistorySync([['jid'=>'12@lid','tcToken'=>"\xffreceiver",'tcTokenTimestamp'=>$cutoff]]);self::assertSame("\xffreceiver",$tokens->resolveReceiverTokenNode('12:3@lid')->content);
        $tokens->hydrateFromHistorySync([['jid'=>'12@lid','tcTokenTimestamp'=>$cutoff-1]]);self::assertNull($tokens->resolveReceiverTokenNode('12@lid'));$tokens->hydrateNctSaltFromHistorySync('salt');self::assertSame('cstoken',$tokens->resolveTokenForMessage('12@lid')->tag);self::assertSame(hash_hmac('sha256','42:7@lid','salt',true),$tokens->resolveTokenForMessage('12@lid')->content);self::assertNull($tokens->resolveTokenForMessage('123@g.us'));
        $tokens->handleNctSaltSync(null);self::assertNull($tokens->resolveTokenForMessage('12@lid'));
    }
    public function testPrivacyNotificationHistoryHydrationAndMalformedRows():void
    {
        $tokens=$this->tokens(static fn(BinaryNode $n,float $t):BinaryNode=>self::ok());(new HistoryStore($this->store))->upsertChat(['jid'=>'12@lid','tcToken'=>'historical','tcTokenTimestamp'=>(int)$this->now]);self::assertSame('historical',$tokens->resolveReceiverTokenNode('12@lid')->content);
        self::assertTrue($tokens->handleNode(new BinaryNode('notification',['type'=>'privacy_token','from'=>'12:2@lid'],[new BinaryNode('tokens',[],[
            new BinaryNode('token',['type'=>'trusted_contact','t'=>(string)(int)$this->now],'updated'),new BinaryNode('token',['type'=>'other','t'=>'3'],'wrong'),new BinaryNode('token',['type'=>'trusted_contact','t'=>'bad'],'wrong')])])));self::assertSame('updated',$tokens->resolveReceiverTokenNode('12@lid')->content);
        $restored=$this->tokens(static fn(BinaryNode $n,float $t):BinaryNode=>self::ok());self::assertSame('updated',$restored->resolveReceiverTokenNode('12@lid')->content);
    }
    public function testSenderTokenBucketDedupFailureAndIdentityReissue():void
    {
        $queries=[];$tokens=$this->tokens(function(BinaryNode $node,float $timeout)use(&$queries):BinaryNode{$queries[]=$node;return self::ok();});$tokens->maybeIssueSenderToken('12:8@lid');$tokens->maybeIssueSenderToken('12@lid');self::assertCount(1,$queries);$token=$queries[0]->child('tokens')->child('token');self::assertSame(['jid'=>'12@lid','t'=>(string)(int)$this->now,'type'=>'trusted_contact'],$token->attrs);self::assertSame('privacy',$queries[0]->attrs['xmlns']);
        $original=$this->now;$this->now+=1;$tokens->reissueOnIdentityChange('12@lid');self::assertSame((string)(int)$original,$queries[1]->child('tokens')->child('token')->attrs['t']);$this->now+=604800;$tokens->maybeIssueSenderToken('12@lid');self::assertCount(3,$queries);
        $failed=$this->tokens(static fn(BinaryNode $n,float $t):BinaryNode=>new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'500'])]));try{$failed->maybeIssueSenderToken('13@lid');self::fail('Expected a server error');}catch(\MVO\Service\ServiceException){}self::assertNull($this->store->get('privacy-token-records','13@lid'));
    }
    public function testServiceTransportInjectsReceiverTokensButNotCsFallback():void
    {
        $tokens=$this->tokens(static fn(BinaryNode $n,float $t):BinaryNode=>self::ok());$tokens->hydrateFromHistorySync([['jid'=>'12@lid','tcToken'=>'receiver','tcTokenTimestamp'=>(int)$this->now]]);$tokens->hydrateNctSaltFromHistorySync('salt');$sent=[];$transport=new ServiceTransport(function(BinaryNode $n,float $t)use(&$sent):BinaryNode{$sent[]=$n;return self::ok();},function(BinaryNode $n)use(&$sent):void{$sent[]=$n;},fn():array=>$this->credentials);$transport->configurePrivacyTokens($tokens);
        $transport->send(new BinaryNode('presence',['type'=>'subscribe','to'=>'12@lid']));self::assertSame('receiver',$sent[0]->child('tctoken')->content);
        $transport->request('picture',new BinaryNode('iq',['type'=>'get','xmlns'=>'w:profile:picture','target'=>'12@lid'],[new BinaryNode('picture',['type'=>'image'])]));self::assertSame('receiver',$sent[1]->child('picture')->child('tctoken')->content);
        $transport->usync([new BinaryNode('status')],[new BinaryNode('user',['jid'=>'12@lid']),new BinaryNode('user',['jid'=>'13@lid'])]);$users=$sent[2]->child('usync')->child('list')->content;self::assertSame('receiver',$users[0]->child('tctoken')->content);self::assertNull($users[1]->child('cstoken'));
    }
    /** @param list<int> $ids */
    private function digest(array $ids=[],?string $hashOverride=null):BinaryNode
    {
        $signed=$this->credentials['signedPreKey'];$identity=$this->credentials['identityKeyPair']['public'];$hash=$identity.$signed['public'].$signed['signature'];foreach($ids as $id)$hash.=substr($this->store->getPreKey($id)['public'],1);
        return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('digest',[],[new BinaryNode('registration',[],pack('N',$this->credentials['registrationId'])),new BinaryNode('type',[],"\x05"),new BinaryNode('identity',[],$identity),new BinaryNode('skey',[],[new BinaryNode('id',[],substr(pack('N',$signed['id']),1)),new BinaryNode('value',[],$signed['public']),new BinaryNode('signature',[],$signed['signature'])]),new BinaryNode('list',[],array_map(static fn(int $id):BinaryNode=>new BinaryNode('id',[],substr(pack('N',$id),1)),$ids)),new BinaryNode('hash',[],$hashOverride ?? hash('sha1',$hash,true))])]);
    }
    public function testDigestUsesServerOrderAndRejectsDrift():void
    {
        $keys=$this->store->generatePreKeys(2);$response=$this->digest([$keys[1]['id'],$keys[0]['id']]);$queries=[];$service=$this->passive(function(BinaryNode $n,float $t)use(&$queries,&$response):BinaryNode{$queries[]=$n;return $response;});self::assertSame(['valid'=>true,'shouldReupload'=>false,'reason'=>'ok','preKeyCount'=>2],$service->validateDigest());self::assertSame('encrypt',$queries[0]->attrs['xmlns']);self::assertNotNull($queries[0]->child('digest'));
        $response=$this->digest([$keys[0]['id']],str_repeat('x',20));self::assertSame('hash_mismatch',$service->validateDigest()['reason']);$response=$this->digest([$keys[0]['id']]);$this->store->consumePreKey($keys[0]['id']);self::assertSame('missing_local_prekey',$service->validateDigest()['reason']);
    }
    public function testDigest404RecoveryAndMalformedDigestDoesNotReupload():void
    {
        $uploads=0;$service=$this->passive(static fn(BinaryNode $n,float $t):BinaryNode=>new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'404'])]),function()use(&$uploads):void{$uploads++;self::assertFalse($this->credentials['serverHasPreKeys']);});self::assertSame('missing_remote_digest',$service->validateAndRecover()['reason']);self::assertSame(1,$uploads);
        $bad=$this->passive(static fn(BinaryNode $n,float $t):BinaryNode=>new BinaryNode('iq',['type'=>'result'],[new BinaryNode('digest')]));$this->expectException(\UnexpectedValueException::class);$bad->validateAndRecover();
    }
    public function testRotationFirstRunDueBoundaryLostAckAndPersistentCandidate():void
    {
        $queries=[];$fail=true;$service=$this->passive(function(BinaryNode $node,float $timeout)use(&$queries,&$fail):BinaryNode{$queries[]=$node;if($fail)throw new \RuntimeException('lost ACK');return self::ok();});self::assertFalse($service->rotateIfDue()['rotated']);self::assertSame([],$queries);$this->now+=27*86400;
        $old=$this->credentials['signedPreKey'];try{$service->rotateIfDue();self::fail('Expected lost ACK');}catch(\RuntimeException $e){self::assertSame('lost ACK',$e->getMessage());}self::assertSame($old,$this->credentials['signedPreKey']);self::assertNotNull($this->store->get('signal-maintenance','pendingRotation'));
        $fail=false;$restored=$this->passive(function(BinaryNode $node,float $timeout)use(&$queries):BinaryNode{$queries[]=$node;return self::ok();});self::assertTrue($restored->rotateIfDue()['rotated']);self::assertSame($queries[0]->child('rotate')->child('skey')->child('value')->content,$queries[1]->child('rotate')->child('skey')->child('value')->content);self::assertSame($old['id']+1,$this->credentials['signedPreKey']['id']);self::assertTrue(XEdDsa::verify($this->credentials['identityKeyPair']['public'],"\x05".$this->credentials['signedPreKey']['public'],$this->credentials['signedPreKey']['signature']));self::assertNull($this->store->get('signal-maintenance','pendingRotation'));
    }
    public function testRotationServerErrorBackoffAndRejectedIdsAreNeverReused():void
    {
        $calls=0;$response=new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'503'])]);$service=$this->passive(function(BinaryNode $n,float $t)use(&$calls,&$response):BinaryNode{$calls++;return $response;});self::assertSame(['rotated'=>false,'shouldDigestKey'=>false,'errorCode'=>503],$service->rotateIfDue(true));self::assertFalse($service->rotateIfDue()['rotated']);self::assertSame(1,$calls);$this->now+=86400;$response=self::ok();self::assertTrue($service->rotateIfDue()['rotated']);self::assertSame(2,$calls);
        $id=$this->credentials['signedPreKey']['id'];$response=new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'406'])]);$service->rotateIfDue(true);$response=self::ok();self::assertTrue($service->rotateIfDue(true)['rotated']);self::assertSame($id+2,$this->credentials['signedPreKey']['id']);
    }
    public function testDelayedPkmsgStillDecryptsAfterSignedKeyRotationAndRestart():void
    {
        $senderStore=new SignalStore($this->directory.'/sender.sqlite');$senderCredentials=CredentialStore::fresh();$sender=new SignalProtocol($senderStore,$senderCredentials);$old=$this->credentials['signedPreKey'];$sender->ensureSession('42@lid',['registrationId'=>$this->credentials['registrationId'],'identityKey'=>$this->credentials['identityKeyPair']['public'],'signedPreKey'=>['id'=>$old['id'],'public'=>$old['public'],'signature'=>$old['signature']]]);$message=$sender->encrypt('42@lid','queued before key rotation');
        $this->passive(static fn(BinaryNode $n,float $t):BinaryNode=>self::ok())->rotateIfDue(true);$restarted=new SignalProtocol($this->store,$this->credentials);self::assertSame('queued before key rotation',$restarted->decrypt('43@lid',$message['type'],$message['ciphertext']));$senderStore->close();
    }
    public function testMaintenanceTickBacksOffDigestErrorsAndRotatesLongRunningWorkers():void
    {
        $queries=[];$fail=true;$service=$this->passive(function(BinaryNode $n,float $t)use(&$queries,&$fail):BinaryNode{$queries[]=$n;if($n->child('digest')!==null){if($fail)throw new \RuntimeException('offline');return $this->digest();}return self::ok();});$service->tick();self::assertSame([],$queries);
        self::assertTrue($service->handleNode(new BinaryNode('notification',['type'=>'encrypt'],[new BinaryNode('digest')])));try{$service->tick();self::fail('Expected digest failure');}catch(\RuntimeException $e){self::assertSame('offline',$e->getMessage());}$service->tick();self::assertCount(1,$queries);$this->now+=60;$fail=false;$service->tick();self::assertCount(2,$queries);
        $this->now+=27*86400;$service->tick();self::assertNotNull($queries[2]->child('rotate'));self::assertCount(3,$queries);
    }
    public function testSenderTokenQueuePersistsUntilAcknowledged():void
    {
        $fail=true;$calls=0;$tokens=$this->tokens(function(BinaryNode $n,float $t)use(&$fail,&$calls):BinaryNode{$calls++;if($fail)throw new \RuntimeException('offline');return self::ok();});$tokens->queueSenderToken('12@lid');try{$tokens->drainPending();self::fail('Expected failed IQ');}catch(\RuntimeException $e){self::assertSame('offline',$e->getMessage());}self::assertNotNull($this->store->get('privacy-token-pending','12@lid'));$tokens->drainPending();self::assertSame(1,$calls);$this->now+=60;$fail=false;$tokens->drainPending();self::assertSame(2,$calls);self::assertNull($this->store->get('privacy-token-pending','12@lid'));
    }
}
