<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\{AlbumInbox,HistoryStore};
use MVO\Protocol\BinaryData;
use MVO\Signal\SignalStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AlbumInboxTest extends TestCase
{
    private string $path;
    private SignalStore $store;
    private AlbumInbox $albums;
    private int $now=1900000000;
    private const CHAT='1-2@g.us';
    private const AUTHOR='200@lid';
    protected function setUp():void{$this->path=tempnam(sys_get_temp_dir(),'mvo-album-');$this->open();}
    private function open():void{$this->store=new SignalStore($this->path);$this->albums=new AlbumInbox($this->store,fn():int=>$this->now);}
    protected function tearDown():void{$this->store->close();foreach([$this->path,$this->path.'-wal',$this->path.'-shm']as$file)if(is_file($file))unlink($file);}
    private function event(string $id,array $proto,string $author=self::AUTHOR,string $chat=self::CHAT,bool $own=false):array
    {
        return ['id'=>$id,'chat'=>$chat,'from'=>$author,'authenticatedSender'=>$author,'fromMe'=>$own,'timestamp'=>$this->now,
            'participant'=>$author,'key'=>['id'=>$id,'remoteJid'=>$chat,'participant'=>$author,'fromMe'=>$own],'proto'=>$proto];
    }
    private function parent(string $id='P',int $images=1,int $videos=1,string $author=self::AUTHOR,string $chat=self::CHAT,bool $own=false):array
    {
        $event=$this->event($id,['albumMessage'=>['expectedImageCount'=>$images,'expectedVideoCount'=>$videos]],$author,$chat,$own);$event['authenticatedSender']=$author;return $event;
    }
    private function child(string $id,int $index,string $type='image',string $parent='P',string $author=self::AUTHOR,string $chat=self::CHAT,bool $own=false):array
    {
        $event=$this->event($id,[$type.'Message'=>['caption'=>$id,'mimetype'=>$type==='image'?'image/jpeg':'video/mp4','directPath'=>'/asset','mediaKey'=>str_repeat('k',32),'jpegThumbnail'=>str_repeat("\xff",1024)],
            'messageContextInfo'=>['messageAssociation'=>['associationType'=>'MEDIA_ALBUM','parentMessageKey'=>['id'=>$parent,'remoteJid'=>$chat,'fromMe'=>$own,'participant'=>$author],'messageIndex'=>$index]]],$author,$chat,$own);
        $event['authenticatedSender']=$author;return $event;
    }
    public function testChildrenBeforeParentCompleteInIndexOrderAndPersistOnlyReferences():void
    {
        $history=new HistoryStore($this->store);$second=$this->child('V',1,'video');$first=$this->child('I',0);$parent=$this->parent();
        foreach([$second,$first,$parent]as$event)$history->recordEvent($event);
        self::assertNull($this->albums->ingest($second));self::assertNull($this->albums->ingest($first));$album=$this->albums->ingest($parent);
        self::assertTrue($album['complete']);self::assertSame(['I','V'],array_column($album['items'],'messageId'));self::assertSame([0,1],array_column($album['items'],'index'));
        self::assertSame(['image','video'],array_column($album['items'],'type'));self::assertSame(2,$album['expectedCount']);self::assertSame(self::AUTHOR,$album['parentKey']['participant']);
        self::assertSame('P',$album['parent']['historyRef']['id']);self::assertSame('I',$history->getMessage(self::CHAT,'I',self::AUTHOR)['id']);
        $bytes=array_values($this->store->entries(AlbumInbox::NAMESPACE))[0];$stored=BinaryData::decode($bytes);
        self::assertArrayNotHasKey('proto',$stored['parent']);self::assertArrayNotHasKey('mediaKey',$stored['items'][0]);self::assertArrayNotHasKey('jpegThumbnail',$stored['items'][0]);self::assertArrayNotHasKey('directPath',$stored['items'][0]);
        self::assertNull($this->albums->ingest($parent));self::assertNull($this->albums->ingest($first));
    }
    public function testRestartKeepsPendingAssemblyAndCompletionReplayMarker():void
    {
        self::assertNull($this->albums->ingest($this->parent()));self::assertNull($this->albums->ingest($this->child('I',0)));
        $this->store->close();$this->open();$album=$this->albums->ingest($this->child('V',1,'video'));self::assertTrue($album['complete']);
        $this->store->close();$this->open();self::assertNull($this->albums->ingest($this->parent()));self::assertNull($this->albums->ingest($this->child('I',0)));self::assertNull($this->albums->ingest($this->child('V',1,'video')));
    }
    public function testParentIdsAreBoundToChatAuthorAndFromMe():void
    {
        foreach([self::AUTHOR,'300@lid']as$author)$this->albums->ingest($this->parent('P',1,0,$author));
        $a=$this->albums->ingest($this->child('I',0,'image','P',self::AUTHOR));$b=$this->albums->ingest($this->child('I',0,'image','P','300@lid'));
        self::assertSame(self::AUTHOR,$a['author']);self::assertSame('300@lid',$b['author']);self::assertCount(2,$this->store->entries(AlbumInbox::NAMESPACE));
        $this->albums->ingest($this->parent('P',1,0,self::AUTHOR,'3-4@g.us'));
        self::assertTrue($this->albums->ingest($this->child('I',0,'image','P',self::AUTHOR,'3-4@g.us'))['complete']);
        $this->albums->ingest($this->parent('P',1,0,self::AUTHOR,self::CHAT,true));
        self::assertTrue($this->albums->ingest($this->child('I',0,'image','P',self::AUTHOR,self::CHAT,true))['fromMe']);
        self::assertCount(4,$this->store->entries(AlbumInbox::NAMESPACE));
    }
    public static function forgedChildFields():iterable
    {
        yield 'different parent author'=>['parent.participant','300@lid'];
        yield 'different chat'=>['parent.remoteJid','3-4@g.us'];
        yield 'invalid wire direction'=>['parent.fromMe','true'];
        yield 'index negative'=>['index',-1];
        yield 'index exceeds budget'=>['index',100];
        yield 'index missing'=>['index',null];
        yield 'index string'=>['index','0'];
        yield 'key author forged'=>['key.participant','300@lid'];
        yield 'sender contradicts source'=>['authenticatedSender','300:2@lid'];
        yield 'child id forged'=>['key.id','other'];
    }
    #[DataProvider('forgedChildFields')]
    public function testForgedAssociationCannotConsumeAnAlbumSlot(string $field,mixed $value):void
    {
        $this->albums->ingest($this->parent('P',1,0));$child=$this->child('I',0);$bad=$child;
        if(str_starts_with($field,'parent.'))$bad['proto']['messageContextInfo']['messageAssociation']['parentMessageKey'][substr($field,7)]=$value;
        elseif(str_starts_with($field,'key.'))$bad['key'][substr($field,4)]=$value;
        elseif($field==='index')$bad['proto']['messageContextInfo']['messageAssociation']['messageIndex']=$value;
        else$bad[$field]=$value;
        self::assertNull($this->albums->ingest($bad));self::assertTrue($this->albums->ingest($child)['complete']);
    }
    public function testDuplicateIdsAndIndexesDoNotCompleteUntilUniqueContiguousItemsExist():void
    {
        $this->albums->ingest($this->parent('P',2,0));$first=$this->child('I',0);$this->albums->ingest($first);
        self::assertNull($this->albums->ingest($first));self::assertNull($this->albums->ingest($this->child('X',0)));self::assertNull($this->albums->ingest($this->child('I',1)));
        self::assertNull($this->albums->ingest($this->child('outside',2)));$album=$this->albums->ingest($this->child('J',1));self::assertSame(['I','J'],array_column($album['items'],'messageId'));
    }
    public function testExpectedTypesAndCountsCannotBeSatisfiedByWrongMediaOrSparseIndexes():void
    {
        $this->albums->ingest($this->child('outside',99));$this->albums->ingest($this->parent('P',1,1));$this->albums->ingest($this->child('I',0));
        self::assertNull($this->albums->ingest($this->child('wrong',1)));self::assertNull($this->albums->ingest($this->child('V',1,'video')));
        $stored=BinaryData::decode(array_values($this->store->entries(AlbumInbox::NAMESPACE))[0]);self::assertFalse($stored['emitted']);self::assertCount(2,$stored['items']);
        self::assertNull($this->albums->ingest($this->parent('oversized',101,0)));self::assertNull($this->albums->ingest($this->parent('empty',0,0)));self::assertCount(1,$this->store->entries(AlbumInbox::NAMESPACE));
    }
    public function testUnrelatedAndViewOnceMessagesDoNotCreateAlbumState():void
    {
        self::assertNull($this->albums->ingest($this->event('T',['conversation'=>'Text'])));
        $child=$this->child('I',0);$child['proto']['imageMessage']['viewOnce']=true;self::assertNull($this->albums->ingest($child));
        $child=$this->child('W',0);$child['proto']=['viewOnceMessage'=>['message'=>$child['proto']]];self::assertNull($this->albums->ingest($child));
        $child=$this->child('N',0);unset($child['from'],$child['authenticatedSender'],$child['participant'],$child['key']['participant']);self::assertNull($this->albums->ingest($child));
        self::assertSame([],$this->store->entries(AlbumInbox::NAMESPACE));
    }
    public function testDirectOwnEventsDoNotInventHistoryParticipants():void
    {
        $parent=$this->parent('P',1,0,self::AUTHOR,'400@lid',true);$child=$this->child('I',0,'image','P',self::AUTHOR,'400@lid',true);
        foreach([&$parent,&$child]as&$event){unset($event['participant'],$event['key']['participant']);}unset($event);
        unset($child['proto']['messageContextInfo']['messageAssociation']['parentMessageKey']['participant']);
        self::assertNull($this->albums->ingest($parent));$album=$this->albums->ingest($child);
        self::assertNull($album['parent']['historyRef']['participant']);self::assertArrayNotHasKey('participant',$album['parentKey']);self::assertTrue($album['fromMe']);
    }
    public function testReceivedWireParentKeyIsResolvedInSenderPerspectiveWithoutForeignChatAdmission():void
    {
        $parent=$this->parent('P',1,0,self::AUTHOR,self::AUTHOR);$child=$this->child('I',0,'image','P',self::AUTHOR,self::AUTHOR);
        $child['key']['recipientJid']='100:2@lid';$child['proto']['messageContextInfo']['messageAssociation']['parentMessageKey']['fromMe']=true;
        $child['proto']['messageContextInfo']['messageAssociation']['parentMessageKey']['remoteJid']='999@lid';
        $this->albums->ingest($parent);self::assertNull($this->albums->ingest($child));
        $child['proto']['messageContextInfo']['messageAssociation']['parentMessageKey']['remoteJid']='100@lid';$album=$this->albums->ingest($child);
        self::assertTrue($album['complete']);self::assertFalse($album['parentKey']['fromMe']);self::assertSame(self::AUTHOR,$album['parentKey']['remoteJid']);
    }
    public function testReceivedGroupParentFromMeIsNotTheLocalDirection():void
    {
        $this->albums->ingest($this->parent('P',1,0));$child=$this->child('I',0);$child['proto']['messageContextInfo']['messageAssociation']['parentMessageKey']['fromMe']=true;
        $album=$this->albums->ingest($child);self::assertTrue($album['complete']);self::assertFalse($album['fromMe']);self::assertFalse($album['parentKey']['fromMe']);
    }
    public function testTopLevelAuthenticatedRecipientAdmitsOnlyTheActualDirectRecipient():void
    {
        $parent=$this->parent('P',1,0,self::AUTHOR,self::AUTHOR);$child=$this->child('I',0,'image','P',self::AUTHOR,self::AUTHOR);
        $child['authenticatedRecipientJid']='100:2@lid';$child['authenticatedRecipientAlt']='5511000000000:2@s.whatsapp.net';
        $association=&$child['proto']['messageContextInfo']['messageAssociation'];$association['parentMessageKey']['fromMe']=true;
        $this->albums->ingest($parent);$association['parentMessageKey']['remoteJid']='999@lid';self::assertNull($this->albums->ingest($child));
        $association['parentMessageKey']['remoteJid']='5511000000000@s.whatsapp.net';$album=$this->albums->ingest($child);
        self::assertTrue($album['complete']);self::assertFalse($album['fromMe']);self::assertSame(self::AUTHOR,$album['chat']);self::assertArrayNotHasKey('recipientJid',$child['key']);
    }
    public function testTopLevelAuthenticatedRecipientCannotMoveGroupAlbumIntoAnotherChat():void
    {
        $this->albums->ingest($this->parent('P',1,0));$child=$this->child('I',0);$child['authenticatedRecipientJid']='100@lid';
        $association=&$child['proto']['messageContextInfo']['messageAssociation'];$association['parentMessageKey']['fromMe']=true;$association['parentMessageKey']['remoteJid']='100@lid';
        self::assertNull($this->albums->ingest($child));$association['parentMessageKey']['remoteJid']=self::CHAT;self::assertTrue($this->albums->ingest($child)['complete']);
    }
    public function testCompactProjectionDoesNotCopyOversizedCaptionsOrDeviceSpecificAuthors():void
    {
        $parent=$this->parent('P',1,0);$parent['authenticatedSender']='200:7@lid';$this->albums->ingest($parent);
        $child=$this->child('I',0);$child['authenticatedSender']='200:8@lid';$child['proto']['imageMessage']['caption']=str_repeat('x',9000);
        $album=$this->albums->ingest($child);self::assertSame(self::AUTHOR,$album['author']);self::assertTrue($album['items'][0]['hasCaption']);self::assertArrayNotHasKey('caption',$album['items'][0]);
    }
    public function testTtlUsesOriginalAgeDoesNotExtendOnReplayAndFreesCapacity():void
    {
        $old=$this->parent('stale',1,0);$old['timestamp']=$this->now-AlbumInbox::TTL;self::assertNull($this->albums->ingest($old));
        $almostOld=$this->parent('P',1,0);$almostOld['timestamp']=$this->now-AlbumInbox::TTL+2;$this->albums->ingest($almostOld);
        ++$this->now;self::assertNull($this->albums->ingest($almostOld));++$this->now;
        self::assertNull($this->albums->ingest($this->child('I',0)));$this->albums->ingest($this->parent('new',1,0));
        $records=array_map(BinaryData::decode(...),array_values($this->store->entries(AlbumInbox::NAMESPACE)));self::assertCount(2,$records);
        self::assertSame(['P','new'],array_values(array_unique(array_column($records,'parentId'))));
        $this->now+=AlbumInbox::TTL;$this->albums->ingest($this->parent('latest',1,0));self::assertCount(1,$this->store->entries(AlbumInbox::NAMESPACE));
    }
    public function testLocalBudgetRejectsNewBucketsWithoutEvictingCompletionMarker():void
    {
        $this->albums->ingest($this->parent('completed',1,0));self::assertNotNull($this->albums->ingest($this->child('I',0,'image','completed')));
        for($i=1;$i<AlbumInbox::MAX_RECORDS;++$i)$this->albums->ingest($this->parent('P'.$i,1,0));
        self::assertCount(AlbumInbox::MAX_RECORDS,$this->store->entries(AlbumInbox::NAMESPACE));self::assertNull($this->albums->ingest($this->parent('overflow',1,0)));
        self::assertNull($this->albums->ingest($this->parent('completed',1,0)));self::assertNull($this->albums->ingest($this->child('I',0,'image','completed')));
    }
    public function testCompletionMarkerAndAssemblyRollBackWithInboxTransaction():void
    {
        $this->albums->ingest($this->parent('P',1,0));$child=$this->child('I',0);
        try{$this->store->transaction(function()use($child):void{self::assertNotNull($this->albums->ingest($child));throw new \RuntimeException('inbox rollback');});}catch(\RuntimeException){}
        self::assertNotNull($this->albums->ingest($child));self::assertNull($this->albums->ingest($child));
    }
}
