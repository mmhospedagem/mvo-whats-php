<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Auth\CredentialStore;
use MVO\Message\MessageService;
use MVO\Protocol\{BinaryCodec,BinaryNode,Jid};
use MVO\Signal\{SignalProtocol,SignalStore};

/** Fully encrypted service-to-service group delivery, with a fake IQ transport. */
final class GroupMessageServiceTest extends TestCase
{
    private string $directory;
    private array $stores = [];
    private array $signals = [];
    private array $credentials = [];
    private array $events = [];
    private array $sent = [];
    private array $messages = [];
    private array $members = [];
    private bool $timeout = false;
    private bool $callbackFailure = false;
    private bool $announcement = false;
    private bool $admin = false;
    private ?\Closure $messageObserver=null;
    private array $metadataRequests=[];
    private ?\Closure $metadataObserver=null;
    private ?int $remoteRegistrationOverride=null;
    private bool $invalidBundleSignature=false;
    private const GROUP = '120363555555@g.us';
    private const ALICE = '551199999111:2@s.whatsapp.net';
    private const BOB = '551199999222:3@s.whatsapp.net';

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir().'/mvo-group-service-'.bin2hex(random_bytes(6)); mkdir($this->directory, 0700);
        foreach ([self::ALICE,self::BOB] as $index=>$jid) {
            $credentials = CredentialStore::fresh(); $credentials['registered'] = true; $credentials['meJid'] = $jid; $credentials['serverHasPreKeys'] = true;
            $credentials['signedIdentity'] = ['details'=>'fixture','accountSignatureKey'=>random_bytes(32),'accountSignature'=>random_bytes(64),'deviceSignature'=>random_bytes(64)];
            $this->credentials[$jid] = $credentials;
            $this->stores[$jid] = new SignalStore($this->directory.'/'.$index.'.sqlite');
            $this->signals[$jid] = new SignalProtocol($this->stores[$jid], $credentials);
            $this->members[] = Jid::parse($jid)->bare();
            $this->events[$jid] = $this->sent[$jid] = $this->messages[$jid] = [];
        }
    }
    protected function tearDown(): void
    {
        foreach ($this->stores as $store) $store->close();
        $this->signals = $this->stores = []; gc_collect_cycles();
        foreach (glob($this->directory.'/*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
    }
    private function service(string $local,?\MVO\Media\MediaTransfer $media=null): MessageService
    {
        return new MessageService($this->signals[$local], $this->stores[$local], fn():array => $this->credentials[$local],
            function (array $credentials) use ($local):void { $this->credentials[$local] = $credentials; },
            function (BinaryNode $node, float $timeout) use ($local):BinaryNode { return $this->query($local, $node); },
            function (BinaryNode $node) use ($local):void { $this->sent[$local][] = $node; },
            function (string $event, array $payload) use ($local):void {
                if ($event === 'message' && $this->callbackFailure) throw new \RuntimeException('application callback failed');
                $this->events[$local][] = [$event,$payload];
            },$media);
    }
    private function query(string $local, BinaryNode $node): BinaryNode
    {
        if ($node->tag === 'message') {
            self::assertNotNull($this->stores[$local]->get('message-outbox', $node->attrs['id']), 'Outbox must commit before transport');
            $this->messages[$local][] = $node;
            if($this->messageObserver!==null)($this->messageObserver)($node,count($this->messages[$local]));
            if ($this->timeout) throw new \RuntimeException('send timeout');
            return new BinaryNode('ack', ['class'=>'message','id'=>$node->attrs['id']]);
        }
        if (($node->attrs['xmlns'] ?? '') === 'w:g2') {
            $this->metadataRequests[]=$node->attrs['to'];$members=array_map(fn(string $jid):BinaryNode => new BinaryNode('participant', ['jid'=>$jid,'type'=>$this->admin?'admin':'participant']), $this->members);
            if($this->metadataObserver!==null)($this->metadataObserver)($node,count($this->metadataRequests));
            if($this->announcement)$members[]=new BinaryNode('announcement');
            return new BinaryNode('iq', ['type'=>'result'], [new BinaryNode('group', ['id'=>$node->attrs['to'],'addressing_mode'=>'pn','subject'=>$node->attrs['to']===self::GROUP?'Equipe A':'Equipe B'], $members)]);
        }
        if (($node->attrs['xmlns'] ?? '') === 'usync') {
            $users = [];
            foreach ($node->child('usync')->child('list')->content as $user) {
                $jid = $user->attrs['jid']; $devices = [];
                foreach ([self::ALICE,self::BOB] as $device) if (Jid::parse($device)->bare() === $jid) $devices[] = new BinaryNode('device', ['id'=>(string)Jid::parse($device)->device]);
                $users[] = new BinaryNode('user', ['jid'=>$jid], [new BinaryNode('devices', [], [new BinaryNode('device-list', [], $devices)])]);
            }
            return new BinaryNode('iq', ['type'=>'result'], [new BinaryNode('usync', [], [new BinaryNode('list', [], $users)])]);
        }
        if (($node->attrs['xmlns'] ?? '') === 'encrypt') {
            $users = [];
            foreach ($node->child('key')->content as $user) {
                $jid = $user->attrs['jid']; $peer = $this->credentials[$jid]; $signed = $peer['signedPreKey'];
                if($this->invalidBundleSignature)$signed['signature'][0]=chr(ord($signed['signature'][0])^1);
                $users[] = new BinaryNode('user', ['jid'=>$jid], [new BinaryNode('registration', [], pack('N',$this->remoteRegistrationOverride??$peer['registrationId'])),new BinaryNode('identity', [], $peer['identityKeyPair']['public']),
                    new BinaryNode('skey', [], [new BinaryNode('id', [], substr(pack('N',$signed['id']),1)),new BinaryNode('value', [], $signed['public']),new BinaryNode('signature', [], $signed['signature'])])]);
            }
            return new BinaryNode('iq', ['type'=>'result'], [new BinaryNode('list', [], $users)]);
        }
        throw new \LogicException('Unexpected IQ');
    }
    private static function incoming(BinaryNode $sent, string $sender, string $recipient, ?string $id = null): BinaryNode
    {
        $children = [];
        foreach ($sent->child('participants')?->content ?? [] as $to) if ($to->attrs['jid'] === $recipient) $children[] = $to->child('enc');
        $children[] = $sent->child('enc');
        return new BinaryNode('message', ['id'=>$id ?? $sent->attrs['id'],'from'=>self::GROUP,'participant'=>$sender,'t'=>'1791980000','notify'=>'Fixture'], $children);
    }
    private function restart(string $jid, int $index): void
    {
        $this->stores[$jid]->close();
        $this->stores[$jid] = new SignalStore($this->directory.'/'.$index.'.sqlite');
        $this->signals[$jid] = new SignalProtocol($this->stores[$jid], $this->credentials[$jid]);
    }

    public function testServiceGroupSendIncomingDedupAndRestartPersistAcknowledgedDistribution(): void
    {
        $alice = $this->service(self::ALICE); $bob = $this->service(self::BOB);
        $result = $alice->sendMessage(self::GROUP, 'Grupo em PHP');
        self::assertSame('server_ack', $result['status']); self::assertSame($result, $alice->getMessageStatus($result['id']));
        $incoming = self::incoming($this->messages[self::ALICE][0], self::ALICE, self::BOB);
        self::assertTrue($bob->handleNode($incoming));
        self::assertSame('message', $this->events[self::BOB][0][0]); $event = $this->events[self::BOB][0][1];
        self::assertSame('Grupo em PHP', $event['text']); self::assertSame(self::GROUP, $event['chat']);
        self::assertSame(Jid::parse(self::ALICE)->bare(), $event['participant']); self::assertSame($event['participant'], $event['key']['participant']);
        self::assertSame(self::GROUP, $this->sent[self::BOB][0]->attrs['to']); self::assertSame(self::ALICE, $this->sent[self::BOB][0]->attrs['participant']);
        unset($alice,$bob); $this->restart(self::ALICE,0); $this->restart(self::BOB,1);
        $alice = $this->service(self::ALICE); $bob = $this->service(self::BOB);
        $bob->handleNode($incoming); self::assertCount(1, $this->events[self::BOB]);
        $alice->sendMessage(self::GROUP, ['type'=>'location','latitude'=>-23.5,'longitude'=>-46.6,'name'=>'São Paulo']);
        $second = $this->messages[self::ALICE][1]; self::assertNull($second->child('participants'));
        $bob->handleNode(self::incoming($second,self::ALICE,self::BOB));
        self::assertSame('location', $this->events[self::BOB][1][1]['type']);
    }

    public function testNativeButtonsUseCompanionMetadataAndAuthenticatedGroupContent():void
    {
        $alice=$this->service(self::ALICE); $bob=$this->service(self::BOB);
        $alice->sendMessage(self::GROUP,['type'=>'buttons','contentText'=>'Escolha','buttons'=>[['buttonId'=>'confirmar','buttonText'=>['displayText'=>'Confirmar']]]]);
        $node=$this->messages[self::ALICE][0];
        self::assertSame('false',$node->attrs['device_fanout']);
        self::assertSame('2',$node->child('biz')->attrs['actual_actors']);
        self::assertSame('mixed',$node->child('biz')->child('interactive')->child('native_flow')->attrs['name']);
        $bob->handleNode(self::incoming($node,self::ALICE,self::BOB));
        $event=$this->events[self::BOB][0][1];
        self::assertSame('interactive',$event['type']);
        self::assertSame('Escolha',$event['data']['body']['text']);
        self::assertSame('quick_reply',$event['data']['nativeFlowMessage']['buttons'][0]['name']);
        self::assertArrayNotHasKey('messageVersion',$event['data']['nativeFlowMessage']);
        self::assertSame(32,strlen($event['proto']['messageContextInfo']['messageSecret']));
    }

    public function testTimedOutGroupMessageRetryUsesSameCiphertextAfterRestart(): void
    {
        $alice = $this->service(self::ALICE); $this->timeout = true;
        try { $alice->sendMessage(self::GROUP, 'Retomar'); self::fail('Timeout expected'); }
        catch (\RuntimeException $error) { self::assertSame('send timeout',$error->getMessage()); }
        $first = $this->messages[self::ALICE][0]; $id = $first->attrs['id'];
        self::assertSame('unknown', $alice->getMessageStatus($id)['status']);
        unset($alice); $this->restart(self::ALICE,0); $alice = $this->service(self::ALICE); $this->timeout = false;
        self::assertSame('server_ack', $alice->retryMessage($id)['status']);
        self::assertSame((new BinaryCodec())->encode($first), (new BinaryCodec())->encode($this->messages[self::ALICE][1]));
        $alice->retryMessage($id); self::assertCount(2, $this->messages[self::ALICE]);
        $bob = $this->service(self::BOB); $bob->handleNode(self::incoming($first,self::ALICE,self::BOB));
        self::assertSame('Retomar', $this->events[self::BOB][0][1]['text']);
        $alice->sendMessage(self::GROUP, 'Próxima'); self::assertNull($this->messages[self::ALICE][2]->child('participants'));
        $bob->handleNode(self::incoming($this->messages[self::ALICE][2],self::ALICE,self::BOB));
        self::assertSame('Próxima', $this->events[self::BOB][1][1]['text']);
    }

    public function testFailedApplicationDeliveryReplaysDurableGroupInboxAfterRestart(): void
    {
        $alice = $this->service(self::ALICE); $bob = $this->service(self::BOB);
        $alice->sendMessage(self::GROUP, 'Recuperar evento'); $incoming = self::incoming($this->messages[self::ALICE][0],self::ALICE,self::BOB);
        $this->callbackFailure = true;
        try { $bob->handleNode($incoming); self::fail('Callback failure expected'); }
        catch (\RuntimeException $error) { self::assertSame('application callback failed', $error->getMessage()); }
        self::assertSame([], $this->sent[self::BOB]);
        unset($bob); $this->restart(self::BOB,1); $this->callbackFailure = false; $bob = $this->service(self::BOB);
        $bob->drainPending(); self::assertSame('Recuperar evento', $this->events[self::BOB][0][1]['text']); self::assertTrue($this->events[self::BOB][0][1]['replayed']);
        $bob->handleNode($incoming); self::assertCount(1, $this->events[self::BOB]); self::assertSame('receipt', $this->sent[self::BOB][0]->tag);
    }

    public function testGroupStatusV2TextUsesNativeEnvelopeAndDecryptsForTheActualGroup():void
    {
        $alice=$this->service(self::ALICE);$bob=$this->service(self::BOB);
        $batch=$alice->sendGroupStatuses([self::GROUP,self::GROUP],['type'=>'text','text'=>'Olá grupo','backgroundColor'=>'#0000aa','textColor'=>'#ffffff','font'=>0],['listName'=>'MVO - Equipe','listEmoji'=>'⭐']);
        self::assertTrue($batch['complete']);self::assertSame(1,$batch['requestedCount']);self::assertSame(1,$batch['sentCount']);$node=$this->messages[self::ALICE][0];
        self::assertSame(self::GROUP,$node->attrs['to']);self::assertSame('text',$node->attrs['type']);self::assertSame('true',$node->child('meta')->attrs['is_group_status']);self::assertSame('skmsg',$node->child('enc')->attrs['type']);
        $bob->handleNode(self::incoming($node,self::ALICE,self::BOB));$event=$this->events[self::BOB][0][1];
        self::assertSame('Olá grupo',$event['text']);self::assertSame('group-status',$event['statusInteraction']['type']);
        $body=$event['proto']['groupStatusMessageV2']['message']['extendedTextMessage'];self::assertTrue($body['contextInfo']['isGroupStatus']);self::assertSame(['audienceType'=>1,'listName'=>'MVO - Equipe','listEmoji'=>'⭐'],$body['contextInfo']['statusAudienceMetadata']);self::assertSame(0xff0000aa,$body['backgroundArgb']);
        self::assertArrayNotHasKey('ephemeralMessage',$event['proto']);self::assertArrayNotHasKey('expiration',$body['contextInfo']);
        self::assertCount(1,$alice->statusStore()->listInteractiveStatuses(['type'=>'group-status','groupJid'=>self::GROUP]));
        self::assertSame('Olá grupo',$alice->statusStore()->getGroupStatus(self::GROUP,$batch['results'][0]['id'])['text']);
    }
    public function testGroupStatusBatchStopsAtUncertainSecondGroupAndDoesNotRepeatAcknowledgedGroup():void
    {
        $this->messageObserver=static function(BinaryNode $node,int $count):void{if($count===2)throw new \RuntimeException('timeout');};
        $alice=$this->service(self::ALICE);$groups=[self::GROUP,'12036322222@g.us','12036333333@g.us'];$batch=$alice->sendGroupStatuses($groups,'Batch');
        self::assertFalse($batch['complete']);self::assertSame(1,$batch['sentCount']);self::assertSame(['server_ack','unknown'],array_column($batch['results'],'status'));self::assertSame([$groups[2]],$batch['skippedGroups']);self::assertCount(2,$this->messages[self::ALICE]);
        self::assertNotSame($batch['results'][0]['id'],$batch['results'][1]['id']);self::assertSame('unknown',$alice->getMessageStatus($batch['results'][1]['id'])['status']);
    }
    public function testGroupStatusGuardWithdrawalStopsTheBatchBeforeNextGroup():void
    {
        $authorized=true;$this->messageObserver=static function()use(&$authorized):void{$authorized=false;};$alice=$this->service(self::ALICE);
        $alice->setGroupStatusGuard(static function()use(&$authorized):void{if(!$authorized)throw new \LogicException('suspended');});
        $batch=$alice->sendGroupStatuses([self::GROUP,'12036322222@g.us','12036333333@g.us'],'Batch');
        self::assertSame(['server_ack','not_executed'],array_column($batch['results'],'status'));self::assertSame(1,$batch['sentCount']);self::assertCount(1,$this->messages[self::ALICE]);self::assertSame(['12036333333@g.us'],$batch['skippedGroups']);
    }
    public function testNestedDeniedMessageCannotChangeAlreadyWrittenOriginalToNotExecuted():void
    {
        $this->messageObserver=static function():never{throw new \MVO\Service\GroupStatusNotExecuted('ANOTHER_MESSAGE_ID',new \LogicException('suspended'));};
        $batch=$this->service(self::ALICE)->sendGroupStatuses(self::GROUP,'Original');self::assertSame('unknown',$batch['results'][0]['status']);self::assertSame('unknown',$batch['failure']['outcome']);
    }
    public function testGroupStatusServerRejectionPreservesServerCodeAndCannotBeRevoked():void
    {
        $this->messageObserver=static function():never{throw new \MVO\Service\ServiceException('message','479','Rejected');};$alice=$this->service(self::ALICE);
        $batch=$alice->sendGroupStatuses([self::GROUP,'12036322222@g.us'],'Rejected');self::assertSame('rejected',$batch['results'][0]['status']);self::assertSame('479',$batch['failure']['serverCode']);self::assertCount(1,$this->messages[self::ALICE]);
        $this->messageObserver=null;try{$alice->revokeGroupStatus(self::GROUP,$batch['results'][0]['id']);self::fail('Allowed rejected publication revoke');}catch(\LogicException $error){self::assertStringContainsString('acknowledged',$error->getMessage());}self::assertCount(1,$this->messages[self::ALICE]);
    }
    public function testInitialPreflightDiagnosticProvesNoPublicationAndSkipsAllOtherGroups():void
    {
        $this->metadataObserver=static function(BinaryNode $node):void{if($node->attrs['to']==='12036322222@g.us')throw new \UnexpectedValueException('Group metadata has no participant roster');};$alice=$this->service(self::ALICE);
        $groups=[self::GROUP,'12036322222@g.us','12036333333@g.us'];$batch=$alice->sendGroupStatuses($groups,'Diagnostic');self::assertSame(0,$batch['sentCount']);self::assertCount(1,$batch['results']);self::assertSame('not_executed',$batch['results'][0]['status']);self::assertSame('group_preparation_failed',$batch['failure']['code']);self::assertSame(['stage'=>'metadata','reason'=>'metadata_roster_empty'],$batch['failure']['preflight']);self::assertSame([self::GROUP,'12036333333@g.us'],$batch['skippedGroups']);self::assertSame([],$this->messages[self::ALICE]);self::assertNull($alice->getMessageStatus($batch['results'][0]['id']));
    }
    public function testGroupStatusAcceptsZeroRegistrationFromFourByteAuthenticatedKeyBundle():void
    {
        $this->remoteRegistrationOverride=0;$alice=$this->service(self::ALICE);$bob=$this->service(self::BOB);$batch=$alice->sendGroupStatuses(self::GROUP,'Status com registro remoto zero');
        self::assertTrue($batch['complete']);self::assertSame(1,$batch['sentCount']);self::assertSame('server_ack',$batch['results'][0]['status']);self::assertSame(0,$this->stores[self::ALICE]->getSession(self::BOB)['remoteRegistration']);
        $bob->handleNode(self::incoming($this->messages[self::ALICE][0],self::ALICE,self::BOB));self::assertSame('Status com registro remoto zero',$this->events[self::BOB][0][1]['text']);
    }
    public function testZeroRegistrationWithInvalidSignedKeyFailsBeforeGroupStatusWrite():void
    {
        $this->remoteRegistrationOverride=0;$this->invalidBundleSignature=true;$alice=$this->service(self::ALICE);$batch=$alice->sendGroupStatuses(self::GROUP,'Must not send');
        self::assertFalse($batch['complete']);self::assertSame('not_executed',$batch['results'][0]['status']);self::assertSame(['stage'=>'signal_sessions','reason'=>'signal_signature_invalid'],$batch['failure']['preflight']);self::assertSame([],$this->messages[self::ALICE]);self::assertNull($alice->getMessageStatus($batch['results'][0]['id']));self::assertNull($this->stores[self::ALICE]->getSession(self::BOB));
    }
    public function testFreshLaterGroupPlanDiagnosticPreservesEarlierAcknowledgedPublication():void
    {
        $this->metadataObserver=static function(BinaryNode $node,int $count):void{if($node->attrs['to']==='12036322222@g.us'&&$count>=5)throw new \UnexpectedValueException('Group metadata has no participant roster');};$alice=$this->service(self::ALICE);$batch=$alice->sendGroupStatuses([self::GROUP,'12036322222@g.us','12036333333@g.us'],'Diagnostic');self::assertSame(['server_ack','not_executed'],array_column($batch['results'],'status'));self::assertSame(1,$batch['sentCount']);self::assertSame(['stage'=>'metadata','reason'=>'metadata_roster_empty'],$batch['failure']['preflight']);self::assertSame(['12036333333@g.us'],$batch['skippedGroups']);self::assertCount(1,$this->messages[self::ALICE]);self::assertNull($alice->getMessageStatus($batch['results'][1]['id']));
    }
    public function testNestedPreparationFailureAfterWriteCannotBeReportedAsPreflightNotExecuted():void
    {
        $this->messageObserver=static function():never{throw \MVO\Message\GroupPreparationException::fromFailure('12036322222@g.us','device_discovery',new \UnexpectedValueException('Device discovery response missing usync list'));};$alice=$this->service(self::ALICE);$batch=$alice->sendGroupStatuses(self::GROUP,'Written');self::assertSame('unknown',$batch['results'][0]['status']);self::assertSame('unknown',$batch['failure']['outcome']);self::assertArrayNotHasKey('preflight',$batch['failure']);self::assertCount(1,$this->messages[self::ALICE]);
    }
    public function testRemovalDuringBatchPreventsAnyFurtherGroupWrite():void
    {
        $this->messageObserver=function():void{$this->members=[Jid::parse(self::BOB)->bare()];};$alice=$this->service(self::ALICE);
        $batch=$alice->sendGroupStatuses([self::GROUP,'12036322222@g.us'],'Roster changed');self::assertSame(['server_ack','not_executed'],array_column($batch['results'],'status'));self::assertSame('group_status_not_allowed',$batch['results'][1]['reason']);self::assertCount(1,$this->messages[self::ALICE]);
    }
    /** Real WaClient query/sendNode boundary with deterministic local Signal peers. */
    private function guardedClient(object $control):array
    {
        $client=new \MVO\WaClient(authDirectory:sys_get_temp_dir().'/mvo-group-guard-'.bin2hex(random_bytes(5)),automaticMaintenance:false);
        $session=new class($control,fn(BinaryNode $node):BinaryNode=>$this->query(self::ALICE,$node))implements \MVO\Transport\NodeSession{
            private array $incoming=[];
            public function __construct(private object $control,private \Closure $reply){}
            public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string{return '';}
            public function close():void{}
            public function sendNode(BinaryNode $node):void{$this->control->writes[]=$node;$reply=($this->reply)($node);$this->incoming[]=new BinaryNode($reply->tag,[...$reply->attrs,'id'=>$node->attrs['id']],$reply->content);}
            public function receiveNode(float $timeoutSeconds=1.0):?BinaryNode{return array_shift($this->incoming);}
        };
        foreach(['session'=>$session,'state'=>'connected','running'=>true,'lastPing'=>hrtime(true)/1e9]as$field=>$value)(new \ReflectionProperty(\MVO\WaClient::class,$field))->setValue($client,$value);
        $client->setGroupStatusGuard(static function()use($control):void{if(!$control->active)throw new \LogicException('Suspended');});
        $service=new MessageService($this->signals[self::ALICE],$this->stores[self::ALICE],fn():array=>$this->credentials[self::ALICE],static function():void{},function(BinaryNode $node,float $timeout)use($client,$control):BinaryNode{if($node->tag==='message'&&$control->suspendBeforeMessage){$control->suspendBeforeMessage=false;$control->active=false;}return $client->query($node,$timeout);},$client->sendNode(...),static function():void{});
        $service->setGroupStatusGuard(static function()use($control):void{if(!$control->active)throw new \LogicException('Suspended');});
        return [$client,$service];
    }
    private function closeGuardedClient(\MVO\WaClient $client):void
    {
        $directory=$client->sessionDirectory();$client->stop();foreach(new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST)as$file){if($file->isDir())rmdir($file->getPathname());else unlink($file->getPathname());}rmdir($directory);$parent=dirname($directory);if(is_dir($parent))rmdir($parent);
    }
    public function testWaClientAuthorizationDeniedAtActualTransportBoundaryDoesNotWriteMessage():void
    {
        $control=(object)['active'=>true,'writes'=>[],'suspendBeforeMessage'=>true];[$client,$service]=$this->guardedClient($control);
        try{$result=(new \ReflectionMethod(\MVO\WaClient::class,'withGroupStatusAuthorization'))->invoke($client,fn():array=>$service->sendGroupStatuses(self::GROUP,'Blocked'));self::assertSame('not_executed',$result['results'][0]['status']);self::assertSame([],$this->messages[self::ALICE]);self::assertSame([] ,array_values(array_filter($control->writes,static fn(BinaryNode $node):bool=>$node->tag==='message')));}
        finally{$this->closeGuardedClient($client);}
    }
    public function testWaClientNestedDeniedMessageAfterParentWriteLeavesParentUnknown():void
    {
        $control=(object)['active'=>true,'writes'=>[],'suspendBeforeMessage'=>false];[$client,$service]=$this->guardedClient($control);
        $client->on('node',static function(array $event)use($client,$control):void{if($event['node']->tag==='ack'){$control->active=false;$client->sendNode(new BinaryNode('message',['id'=>'NESTED_OTHER_MESSAGE']));}});
        try{$result=(new \ReflectionMethod(\MVO\WaClient::class,'withGroupStatusAuthorization'))->invoke($client,fn():array=>$service->sendGroupStatuses(self::GROUP,'Written'));self::assertSame('unknown',$result['results'][0]['status']);self::assertCount(1,$this->messages[self::ALICE]);self::assertCount(1,array_filter($control->writes,static fn(BinaryNode $node):bool=>$node->tag==='message'));}
        finally{$this->closeGuardedClient($client);}
    }
    public function testAnnouncementPermissionIsCheckedBeforeAnyMediaUpload():void
    {
        $uploads=0;$media=new \MVO\Media\MediaTransfer(static fn():never=>throw new \LogicException('No media query expected'),static function()use(&$uploads):array{$uploads++;return ['status'=>200,'body'=>'{}'];});$this->announcement=true;
        try{$this->service(self::ALICE,$media)->sendGroupStatuses(self::GROUP,['type'=>'image','media'=>['bytes'=>'image']]);self::fail('Admin restriction was ignored');}catch(\LogicException $error){self::assertStringContainsString('administrators',$error->getMessage());}
        self::assertSame(0,$uploads);self::assertSame([],$this->messages[self::ALICE]);
    }
    public function testImageIsUploadedOnceBeforeAnyGroupSendAndRetainsEncryptedMediaDescriptor():void
    {
        $uploads=0;$media=new \MVO\Media\MediaTransfer(static fn():BinaryNode=>new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>'fixture','ttl'=>'100'],[new BinaryNode('host',['hostname'=>'mmg.whatsapp.net'])])]),function(array $request)use(&$uploads):array{self::assertSame([],$this->messages[self::ALICE]);$uploads++;return ['status'=>200,'body'=>'{"url":"https://mmg.whatsapp.net/image","direct_path":"/image"}'];});
        $alice=$this->service(self::ALICE,$media);$batch=$alice->sendGroupStatuses([self::GROUP,'12036322222@g.us'],['type'=>'image','media'=>['bytes'=>'image fixture'],'mimetype'=>'image/jpeg','caption'=>'Imagem']);
        self::assertTrue($batch['complete']);self::assertSame(1,$uploads);self::assertCount(2,$this->messages[self::ALICE]);
        $mediaKeys=[];$display=[];foreach($batch['results']as$result){$record=$alice->statusStore()->getGroupStatus($result['groupJid'],$result['id']);$image=$record['proto']['groupStatusMessageV2']['message']['imageMessage'];self::assertTrue($image['contextInfo']['isGroupStatus']);self::assertSame('Imagem',$image['caption']);$mediaKeys[]=$image['mediaKey'];$display[]=$image['contextInfo']['statusAudienceMetadata']['listName'];}
        self::assertSame(['Equipe A','Equipe B'],$display);
        self::assertSame($mediaKeys[0],$mediaKeys[1]);self::assertSame(32,strlen($mediaKeys[0]));self::assertSame('image',$this->messages[self::ALICE][0]->child('enc')->attrs['mediatype']);
    }
    public function testOwnGroupStatusCanBeRevokedWithoutExposingAnotherGroupOrOrdinaryMessages():void
    {
        $alice=$this->service(self::ALICE);$batch=$alice->sendGroupStatuses(self::GROUP,'Revogar');$id=$batch['results'][0]['id'];$deleted=$alice->revokeGroupStatus(self::GROUP,$id);
        self::assertTrue($deleted['complete']);self::assertSame(['group_status','linked_devices'],array_column($deleted['results'],'step'));self::assertCount(3,$this->messages[self::ALICE]);
        self::assertSame('true',$this->messages[self::ALICE][1]->child('meta')->attrs['is_group_status']);self::assertNull($this->messages[self::ALICE][2]->child('meta'));
        self::assertTrue($alice->statusStore()->getGroupStatus(self::GROUP,$id)['revoked']);self::assertSame([],$alice->statusStore()->listInteractiveStatuses(['type'=>'group-status','groupJid'=>self::GROUP]));
        $revoked=$alice->statusStore()->getGroupStatus(self::GROUP,$id);self::assertSame([],$revoked['proto']);self::assertNull($revoked['text']);self::assertSame('revoked',$revoked['type']);self::assertStringNotContainsString('Revogar',json_encode($revoked));
        $inventory=$alice->statusStore()->listInteractiveStatuses(['type'=>'group-status','groupJid'=>self::GROUP,'includeRevoked'=>true]);self::assertCount(1,$inventory);self::assertTrue($inventory[0]['revoked']);self::assertSame([],$inventory[0]['proto']);self::assertStringNotContainsString('Revogar',json_encode($inventory));
        self::assertSame([],$alice->history()->store->getMessage(self::GROUP,$id)['proto']);self::assertArrayHasKey('groupStatusMessageV2',$alice->history()->store->getStoredMessage(self::GROUP,$id)['proto']);
        try{$alice->revokeGroupStatus('12036322222@g.us',$id);self::fail('Allowed another group');}catch(\LogicException){}
        $ordinary=$alice->sendMessage(self::GROUP,'ordinary');try{$alice->revokeGroupStatus(self::GROUP,$ordinary['id']);self::fail('Allowed ordinary message');}catch(\LogicException){}
    }
    public function testDurableSingleGroupStatusUsesReservedIdAndRefusesAnyPersistedReplay():void
    {
        $alice=$this->service(self::ALICE);$bob=$this->service(self::BOB);$id='3EB0ABCDEF012345678901';
        $result=$alice->sendGroupStatusWithId(self::GROUP,['type'=>'text','text'=>'Reserved durable status'],$id);self::assertSame($id,$result['id']);self::assertSame('server_ack',$result['status']);self::assertSame($id,$this->messages[self::ALICE][0]->attrs['id']);
        $bob->handleNode(self::incoming($this->messages[self::ALICE][0],self::ALICE,self::BOB));self::assertSame('Reserved durable status',$this->events[self::BOB][0][1]['text']);self::assertSame('group-status',$this->events[self::BOB][0][1]['statusInteraction']['type']);
        try{$alice->sendGroupStatusWithId(self::GROUP,'Do not duplicate',$id);self::fail('Persisted ID was replayed');}catch(\LogicException){self::assertCount(1,$this->messages[self::ALICE]);}
    }
    public function testGroupStatusPreflightFailureRetainsReservedIdForSafeExplicitResume():void
    {
        $this->members=[];$alice=$this->service(self::ALICE);$id='3EB0111111111111111111';$failed=$alice->sendGroupStatusWithId(self::GROUP,'First attempt',$id);self::assertSame($id,$failed['id']);self::assertSame('not_executed',$failed['status']);self::assertNull($alice->getMessageStatus($id));self::assertSame([],$this->messages[self::ALICE]);
        $this->members=[Jid::parse(self::ALICE)->bare(),Jid::parse(self::BOB)->bare()];$sent=$alice->sendGroupStatusWithId(self::GROUP,'First attempt',$id);self::assertSame($id,$sent['id']);self::assertSame('server_ack',$sent['status']);self::assertCount(1,$this->messages[self::ALICE]);
    }

}
