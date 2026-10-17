<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\History\StatusStore;
use MVO\Message\MessageService;
use MVO\Protocol\{BinaryCodec,BinaryData,BinaryNode,Jid};
use MVO\Signal\{SignalProtocol,SignalStore};
use PHPUnit\Framework\TestCase;

/** Real Signal and sender-key crypto, durable SQLite and a local IQ transport. */
final class StatusProtocolTest extends TestCase
{
    private string $directory;
    private array $stores=[];
    private array $signals=[];
    private array $credentials=[];
    private array $events=[];
    private array $messages=[];
    private array $sent=[];
    private bool $timeout=false;
    private bool $receiptFailure=false;
    private bool $callbackFailure=false;
    private const ALICE='100:2@lid';
    private const BOB='200:3@lid';
    private const BOB_OTHER='200:4@lid';
    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-status-protocol-'.bin2hex(random_bytes(6));mkdir($this->directory,0700);
        foreach([self::ALICE,self::BOB,self::BOB_OTHER] as $index=>$jid) {
            $credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['serverHasPreKeys']=true;
            $credentials['meJid']=($index===0?'5511000000000:2':($index===1?'5522000000000:3':'5522000000000:4')).'@s.whatsapp.net';$credentials['meLid']=$jid;
            $credentials['signedIdentity']=['details'=>'fixture','accountSignatureKey'=>random_bytes(32),'accountSignature'=>random_bytes(64),'deviceSignature'=>random_bytes(64)];
            $this->credentials[$jid]=$credentials;$this->stores[$jid]=new SignalStore($this->directory.'/'.$index.'.sqlite');$this->signals[$jid]=new SignalProtocol($this->stores[$jid],$credentials);
            $this->events[$jid]=$this->messages[$jid]=$this->sent[$jid]=[];
            foreach(['5511000000000@s.whatsapp.net'=>'100@lid','5522000000000@s.whatsapp.net'=>'200@lid'] as $pn=>$lid){$this->stores[$jid]->set('message-pn-to-lid',$pn,$lid);$this->stores[$jid]->set('message-lid-to-pn',$lid,$pn);}
        }
    }
    protected function tearDown():void {foreach($this->stores as $store)$store->close();$this->signals=$this->stores=[];gc_collect_cycles();foreach(glob($this->directory.'/*')?:[] as $file)unlink($file);rmdir($this->directory);}
    private function service(string $local):MessageService
    {
        return new MessageService($this->signals[$local],$this->stores[$local],fn():array=>$this->credentials[$local],function(array $credentials)use($local):void{$this->credentials[$local]=$credentials;},
            fn(BinaryNode $node):BinaryNode=>$this->query($local,$node),function(BinaryNode $node)use($local):void {if($this->receiptFailure&&$node->tag==='receipt'&&in_array($node->attrs['type']??null,['read','read-self'],true))throw new \RuntimeException('Receipt offline');$this->sent[$local][]=$node;},
            function(string $name,array $payload)use($local):void {if($this->callbackFailure&&$name==='status')throw new \RuntimeException('Application callback failed');$this->events[$local][]=[$name,$payload];});
    }
    private function query(string $local,BinaryNode $node):BinaryNode
    {
        if($node->tag==='message') {
            self::assertNotNull($this->stores[$local]->get('message-outbox',$node->attrs['id']));$this->messages[$local][]=$node;
            $record=json_decode($this->stores[$local]->get('message-outbox',$node->attrs['id']),true,32,JSON_THROW_ON_ERROR);
            if(!isset($node->attrs['edit'])){self::assertNotNull($this->stores[$local]->get('status-audiences',$node->attrs['id']));self::assertContains($record['metadata']['status'],['pending','unknown']);}
            if($this->timeout)throw new \RuntimeException('Uncertain status transport');return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        }
        if(($node->attrs['xmlns']??null)==='usync') {
            $users=[];foreach($node->child('usync')->child('list')->content as $user){$jid=$user->attrs['jid'];$devices=[];foreach([self::ALICE,self::BOB,self::BOB_OTHER] as $device)if(Jid::parse($device)->bare()===$jid)$devices[]=new BinaryNode('device',['id'=>(string)Jid::parse($device)->device]);$users[]=new BinaryNode('user',['jid'=>$jid],[new BinaryNode('devices',[],[new BinaryNode('device-list',[],$devices)])]);}
            return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('usync',[],[new BinaryNode('list',[],$users)])]);
        }
        if(($node->attrs['xmlns']??null)==='encrypt') {
            $users=[];foreach($node->child('key')->content as $user){$jid=$user->attrs['jid'];$peer=$this->credentials[$jid];$signed=$peer['signedPreKey'];$users[]=new BinaryNode('user',['jid'=>$jid],[new BinaryNode('registration',[],pack('N',$peer['registrationId'])),new BinaryNode('identity',[],$peer['identityKeyPair']['public']),new BinaryNode('skey',[],[new BinaryNode('id',[],substr(pack('N',$signed['id']),1)),new BinaryNode('value',[],$signed['public']),new BinaryNode('signature',[],$signed['signature'])])]);}
            return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('list',[],$users)]);
        }
        throw new \LogicException('Unexpected status IQ');
    }
    private function incoming(BinaryNode $sent,string $sender,string $receiver):BinaryNode {$children=[];foreach($sent->child('participants')?->content??[] as $to)if(($to->attrs['jid']??null)===$receiver&&$to->child('enc')!==null)$children[]=$to->child('enc');if($sent->child('enc')!==null)$children[]=$sent->child('enc');return new BinaryNode('message',['id'=>$sent->attrs['id'],'from'=>StatusStore::CHAT,'participant'=>$sender,'t'=>(string)time()],$children);}
    private function restart(string $jid,int $index):void {$this->stores[$jid]->close();$this->stores[$jid]=new SignalStore($this->directory.'/'.$index.'.sqlite');$this->signals[$jid]=new SignalProtocol($this->stores[$jid],$this->credentials[$jid]);}
    private function receipt(string $id,string $device,string $type='read',?int $time=null):BinaryNode {return new BinaryNode('receipt',['id'=>$id,'from'=>StatusStore::CHAT,'participant'=>$device,'type'=>$type,'t'=>(string)($time??time())]);}
    public function testStatusAudienceCommitsBeforeTransportAndEncryptedReceiveKeepsMessageEvent():void
    {
        $alice=$this->service(self::ALICE);$bob=$this->service(self::BOB);self::assertSame($alice->statusStore(),$alice->statusStore());
        $result=$alice->sendBroadcast(StatusStore::CHAT,'Status nativo',['5522000000000'],['id'=>'story','statusSetting'=>'allowlist']);
        self::assertSame(['200@lid'],$alice->statusStore()->getAudience('story')['recipients']);self::assertSame('allowlist',$alice->statusStore()->getAudience('story')['statusSetting']);
        self::assertSame('Status nativo',$this->events[self::ALICE][1][1]['text']);
        self::assertSame('server_ack',$result['status']);self::assertSame('100@lid',$alice->statusStore()->getStatus('story')['author']);
        $incoming=$this->incoming($this->messages[self::ALICE][0],self::ALICE,self::BOB);$bob->handleNode($incoming);
        self::assertSame(['message','status'],array_column($this->events[self::BOB],0));$status=$bob->statusStore()->getStatus('story','100@lid');self::assertSame('Status nativo',$status['text']);self::assertFalse($status['viewed']);
        self::assertSame('received',$this->events[self::BOB][1][1]['action']);self::assertSame('100@lid',$status['key']['participant']);
        self::assertSame([],array_filter($this->sent[self::BOB],static fn(BinaryNode $node):bool=>($node->attrs['type']??null)==='read'));
        $bob->handleNode($incoming);self::assertCount(2,$this->events[self::BOB]);
    }
    public function testUncertainPublicationRetainsAudienceCiphertextAndRetryAfterRestart():void
    {
        $alice=$this->service(self::ALICE);$this->timeout=true;try{$alice->sendBroadcast(StatusStore::CHAT,'Pending',['200@lid'],['id'=>'pending']);self::fail('Expected timeout');}catch(\RuntimeException){}
        self::assertSame('unknown',$alice->statusStore()->getStatus('pending')['status']);$first=(new BinaryCodec())->encode($this->messages[self::ALICE][0]);unset($alice);$this->restart(self::ALICE,0);$this->timeout=false;$alice=$this->service(self::ALICE);
        self::assertSame(['200@lid'],$alice->statusStore()->getAudience('pending')['recipients']);self::assertSame('server_ack',$alice->retryMessage('pending')['status']);self::assertSame($first,(new BinaryCodec())->encode($this->messages[self::ALICE][1]));
    }
    public function testOwnRevocationCommitsOnlyAfterAckAndDoesNotCreatePhantomPublication():void
    {
        $alice=$this->service(self::ALICE);$bob=$this->service(self::BOB);$alice->sendBroadcast(StatusStore::CHAT,'Story',['200@lid'],['id'=>'story']);$bob->handleNode($this->incoming($this->messages[self::ALICE][0],self::ALICE,self::BOB));
        $this->timeout=true;try{$alice->sendBroadcast(StatusStore::CHAT,['type'=>'revoke','target'=>['id'=>'story','fromMe'=>true,'participant'=>'100@lid']],['200@lid'],['id'=>'revoke']);self::fail('Expected timeout');}catch(\RuntimeException){}
        self::assertNotNull($alice->statusStore()->getStatus('story'));self::assertCount(1,$alice->statusStore()->listStatuses());self::assertNull($alice->statusStore()->getAudience('revoke'));
        $this->timeout=false;$alice->retryMessage('revoke');self::assertNull($alice->statusStore()->getStatus('story'));self::assertSame([],$alice->statusStore()->listStatuses());
        $bob->handleNode($this->incoming($this->messages[self::ALICE][2],self::ALICE,self::BOB));self::assertNull($bob->statusStore()->getStatus('story','100@lid'));self::assertTrue($bob->statusStore()->getStatus('story','100@lid',false,true)['revoked']);
        self::assertSame('revoked',array_values(array_filter($this->events[self::BOB],static fn(array $row):bool=>$row[0]==='status'))[1][1]['action']);
    }
    public function testOnlyEligibleRemoteReadReceiptsBecomeDeduplicatedViewers():void
    {
        $alice=$this->service(self::ALICE);$alice->sendBroadcast(StatusStore::CHAT,'Story',['200@lid'],['id'=>'story']);$time=time();
        foreach([['900:3@lid','read'],[self::BOB,'delivery'],[self::BOB,'read-self'],[self::ALICE,'read']] as [$device,$type])$alice->handleNode($this->receipt('story',$device,$type,$time));self::assertSame([],$alice->statusStore()->listViewers('story'));
        $alice->handleNode($this->receipt('story',self::BOB,'read',$time));$alice->handleNode($this->receipt('story',self::BOB_OTHER,'read',$time+5));$alice->handleNode($this->receipt('story',self::BOB,'delivery',$time+10));
        self::assertSame([['jid'=>'200@lid','timestamp'=>$time,'status'=>'read']],$alice->statusStore()->listViewers('story'));unset($alice);$this->restart(self::ALICE,0);self::assertCount(1,$this->service(self::ALICE)->statusStore()->listViewers('story'));
    }
    public function testFailedLocalReadTransportIsPendingAndMarksViewedOnlyWhenDrainSucceeds():void
    {
        $alice=$this->service(self::ALICE);$bob=$this->service(self::BOB);$alice->sendBroadcast(StatusStore::CHAT,'Story',['200@lid'],['id'=>'story']);$bob->handleNode($this->incoming($this->messages[self::ALICE][0],self::ALICE,self::BOB));$this->receiptFailure=true;
        $bob->sendReceipt([$bob->statusStore()->getStatus('story','100@lid')['key']]);self::assertFalse($bob->statusStore()->getStatus('story','100@lid')['viewed']);
        foreach($this->stores[self::BOB]->entries('receipt-queue') as $key=>$bytes){$record=BinaryData::decode($bytes);$record['nextAttempt']=0;$this->stores[self::BOB]->set('receipt-queue',$key,BinaryData::encode($record));}
        unset($bob);$this->restart(self::BOB,1);$bob=$this->service(self::BOB);$this->receiptFailure=false;$bob->drainPending();self::assertTrue($bob->statusStore()->getStatus('story','100@lid')['viewed']);
        $views=array_values(array_filter($this->events[self::BOB],static fn(array $row):bool=>$row[0]==='status'&&$row[1]['action']==='viewed'));self::assertCount(1,$views);self::assertSame('sent',$views[0][1]['receiptStatus']);
    }
    public function testStatusCallbackFailureIsDurablyReplayedAfterRestart():void
    {
        $alice=$this->service(self::ALICE);$bob=$this->service(self::BOB);$alice->sendBroadcast(StatusStore::CHAT,'Story',['200@lid'],['id'=>'story']);$this->callbackFailure=true;
        try{$bob->handleNode($this->incoming($this->messages[self::ALICE][0],self::ALICE,self::BOB));self::fail('Expected callback failure');}catch(\RuntimeException){}
        self::assertNotNull($bob->statusStore()->getStatus('story','100@lid'));unset($bob);$this->restart(self::BOB,1);$this->callbackFailure=false;$bob=$this->service(self::BOB);$bob->drainPending();
        $statuses=array_values(array_filter($this->events[self::BOB],static fn(array $row):bool=>$row[0]==='status'));self::assertCount(1,$statuses);self::assertTrue($statuses[0][1]['replayed']);$bob->drainPending();self::assertCount(3,$this->events[self::BOB]);
    }
    public function testAuthenticatedPeerCannotRevokeAnotherAuthorsStatus():void
    {
        $alice=$this->service(self::ALICE);$bob=$this->service(self::BOB);$alice->sendBroadcast(StatusStore::CHAT,'Story',['200@lid'],['id'=>'story']);
        $bob->sendBroadcast(StatusStore::CHAT,['type'=>'revoke','target'=>['id'=>'story','fromMe'=>false,'participant'=>'100@lid']],['100@lid'],['id'=>'forged-revoke']);
        $alice->handleNode($this->incoming($this->messages[self::BOB][0],self::BOB,self::ALICE));self::assertNotNull($alice->statusStore()->getStatus('story'));self::assertFalse($alice->statusStore()->getStatus('story')['revoked']);
    }
    public function testTamperedCiphertextCannotCreateStatusOrRevokeTombstone():void
    {
        $alice=$this->service(self::ALICE);$bob=$this->service(self::BOB);$alice->sendBroadcast(StatusStore::CHAT,'Story',['200@lid'],['id'=>'story']);
        $node=$this->incoming($this->messages[self::ALICE][0],self::ALICE,self::BOB);$children=[];foreach($node->content as $child)$children[]=new BinaryNode($child->tag,$child->attrs,is_string($child->content)?substr($child->content,0,-1).chr(ord(substr($child->content,-1))^1):$child->content);
        $bob->handleNode(new BinaryNode($node->tag,$node->attrs,$children));self::assertNull($bob->statusStore()->getStatus('story','100@lid'));self::assertSame([],$this->stores[self::BOB]->entries('status-revoked'));self::assertSame([],array_filter($this->events[self::BOB],static fn(array $row):bool=>$row[0]==='status'));
    }
    public function testOnlyPairedPrivateReadReceiptCanSynchronizeOurView():void
    {
        $alice=$this->service(self::ALICE);$bob=$this->service(self::BOB);$alice->sendBroadcast(StatusStore::CHAT,'Story',['200@lid'],['id'=>'story']);$bob->handleNode($this->incoming($this->messages[self::ALICE][0],self::ALICE,self::BOB));
        $attrs=['id'=>'story','from'=>self::ALICE,'participant'=>'100@lid','recipient'=>StatusStore::CHAT,'type'=>'read-self','t'=>(string)time()];$bob->handleNode(new BinaryNode('receipt',$attrs));self::assertFalse($bob->statusStore()->getStatus('story','100@lid')['viewed']);
        $attrs['from']='200:0@lid';$bob->handleNode(new BinaryNode('receipt',$attrs));self::assertTrue($bob->statusStore()->getStatus('story','100@lid')['viewed']);self::assertSame([],$alice->statusStore()->listViewers('story'));
        $events=array_values(array_filter($this->events[self::BOB],static fn(array $row):bool=>$row[0]==='status'&&$row[1]['action']==='viewed'));self::assertCount(1,$events);self::assertSame('paired_receipt',$events[0][1]['source']);
    }
    public function testViewerAliasesDeduplicateAndWrongReceiptChatDoesNotCount():void
    {
        $alice=$this->service(self::ALICE);$alice->sendBroadcast(StatusStore::CHAT,'Story',['200@lid'],['id'=>'story']);$time=time();
        $alice->handleNode(new BinaryNode('receipt',['id'=>'story','from'=>'999-888@g.us','participant'=>self::BOB,'type'=>'read','t'=>(string)$time]));self::assertSame([],$alice->statusStore()->listViewers('story'));
        $alice->handleNode($this->receipt('story',self::BOB,'read',$time));$alice->handleNode($this->receipt('story','5522000000000:4@s.whatsapp.net','read',$time+2));self::assertSame([['jid'=>'200@lid','timestamp'=>$time,'status'=>'read']],$alice->statusStore()->listViewers('story'));
    }
    public function testRevokeAfterPnOwnHistoryEchoAndRestartUsesDurableLidPublication():void
    {
        $pn='5511000000000@s.whatsapp.net';$id='';for($index=0;$index<1000;$index++){$id='echo-'.$index;if(strcmp(hash('sha256',$id."\0".$pn),hash('sha256',$id."\0"))<0)break;}
        $alice=$this->service(self::ALICE);$alice->sendBroadcast(StatusStore::CHAT,'Original',['200@lid'],['id'=>$id]);$history=$alice->history()->store;
        $local=$history->getMessage(StatusStore::CHAT,$id,'100@lid');$history->deleteMessage(StatusStore::CHAT,$id,'100@lid');unset($local['participant'],$local['key']['participant']);$history->upsertMessage($local);
        $history->recordWebMessage(['key'=>['id'=>$id,'fromMe'=>true,'participant'=>$pn],'messageTimestamp'=>time(),'message'=>['conversation'=>'Own phone echo']],StatusStore::CHAT,$pn);
        unset($alice,$history);$this->restart(self::ALICE,0);$alice=$this->service(self::ALICE);self::assertSame('100@lid',$alice->statusStore()->getStatus($id)['author']);self::assertCount(1,$alice->statusStore()->listStatuses());
        $facade=new \MVO\Service\StatusService(static fn():\MVO\AppState\AppStateService=>throw new \LogicException('No app-state expected'),
            fn(string $to,string|array $content,array $recipients,array $options):array=>$alice->sendBroadcast($to,$content,$recipients,$options),fn():StatusStore=>$alice->statusStore());
        self::assertSame('server_ack',$facade->revokeStatus($id)['status']);self::assertNull($alice->statusStore()->getStatus($id));self::assertCount(1,$alice->statusStore()->listStatuses(['includeRevoked'=>true]));
    }
}
