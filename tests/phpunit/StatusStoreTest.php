<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\{HistoryStore,StatusStore};
use MVO\Protocol\BinaryData;
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class StatusStoreTest extends TestCase
{
    private string $path;
    private SignalStore $signal;
    private HistoryStore $history;
    private StatusStore $statuses;
    private const NOW=1900000000;
    protected function setUp():void {$this->path=tempnam(sys_get_temp_dir(),'mvo-status-');$this->open();}
    private function open():void {$this->signal=new SignalStore($this->path);$this->history=new HistoryStore($this->signal);$this->statuses=new StatusStore($this->signal,$this->history,static fn():int=>self::NOW);}
    protected function tearDown():void {$this->signal->close();foreach([$this->path,$this->path.'-wal',$this->path.'-shm'] as $file)if(is_file($file))unlink($file);}
    private function story(string $id,string $author='200@lid',bool $own=false,int $age=0):array {return ['id'=>$id,'chat'=>StatusStore::CHAT,'participant'=>$author,'from'=>$author,'fromMe'=>$own,'timestamp'=>self::NOW-$age,'proto'=>['conversation'=>$id],'type'=>'text','text'=>$id];}
    public function testSessionHistoryScopesAndExactTwentyFourHourExpiration():void
    {
        foreach([$this->story('new'),$this->story('own','100@lid',true),$this->story('edge','200@lid',false,86400),$this->story('old','200@lid',false,86401)] as $row)$this->history->recordEvent($row);
        self::assertCount(2,$this->statuses->listStatuses());self::assertSame(['own'],array_column($this->statuses->listStatuses(['scope'=>'own']),'id'));
        self::assertSame(['new'],array_column($this->statuses->listStatuses(['scope'=>'received']),'id'));
        self::assertCount(4,$this->statuses->listStatuses(['includeExpired'=>true]));self::assertNull($this->statuses->getStatus('edge'));
        $edge=$this->statuses->getStatus('edge',null,true);self::assertTrue($edge['isExpired']);self::assertSame(self::NOW,$edge['expiresAt']);self::assertSame('session_history',$edge['source']);
        self::assertSame(['old'],array_column($this->statuses->listStatuses(['includeExpired'=>true,'beforeTimestamp'=>self::NOW-86400]),'id'));
        self::assertCount(1,$this->statuses->listStatuses(['author'=>'100@lid','limit'=>1]));
    }
    public function testAuthorScopedIdCollisionAndOwnPreference():void
    {
        foreach(['200@lid','300@lid'] as $author)$this->history->recordEvent($this->story('same',$author));
        self::assertCount(2,$this->statuses->listStatuses());self::assertSame('300@lid',$this->statuses->getStatus('same','300:7@lid')['author']);
        try{$this->statuses->getStatus('same');self::fail('Ambiguous IDs must require an author');}catch(\InvalidArgumentException){}
        $this->history->recordEvent($this->story('same','100@lid',true));self::assertSame('100@lid',$this->statuses->getStatus('same')['author']);
    }
    public function testRevokeBeforeOriginalProducesDurableTombstoneAndCannotRevokeAnotherAuthor():void
    {
        $event=$this->story('revoke','200@lid');$event['proto']=['protocolMessage'=>['type'=>'REVOKE','key'=>['remoteJid'=>StatusStore::CHAT,'id'=>'original','participant'=>'200@lid']]];$event['type']='protocol';
        $this->history->recordEvent($event);$this->history->recordEvent($this->story('original','200@lid'));$this->history->recordEvent($this->story('original','300@lid'));
        self::assertNull($this->statuses->getStatus('original','200@lid'));self::assertTrue($this->statuses->getStatus('original','200@lid',false,true)['revoked']);
        self::assertFalse($this->statuses->getStatus('original','300@lid')['revoked']);self::assertCount(1,$this->statuses->listStatuses());
        $event['proto']['protocolMessage']['key']['participant']='300@lid';self::assertNull($this->statuses->recordEvent($event));self::assertFalse($this->statuses->getStatus('original','300@lid')['revoked']);
        $this->signal->close();$this->open();self::assertNull($this->statuses->getStatus('original','200@lid'));
        $this->history->recordEvent($this->story('original','200@lid'));self::assertNull($this->statuses->getStatus('original','200@lid'));
    }
    public function testForeignChatProtocolAndReactionAreNotPublications():void
    {
        $bad=$this->story('foreign');$bad['proto']=['protocolMessage'=>['type'=>'REVOKE','key'=>['remoteJid'=>'100-200@g.us','id'=>'story']]];
        $this->history->recordEvent($this->story('story'));$this->history->recordEvent($bad);
        foreach([['reactionMessage'=>['text'=>'👍']],['senderKeyDistributionMessage'=>['groupId'=>StatusStore::CHAT]],['protocolMessage'=>['type'=>'MESSAGE_EDIT']]] as $index=>$proto){$row=$this->story('control'.$index);$row['proto']=$proto;$this->history->recordEvent($row);}
        self::assertSame(['story'],array_column($this->statuses->listStatuses(),'id'));self::assertFalse($this->statuses->getStatus('story')['revoked']);
    }
    public function testPrivacyAndMuteReadOnlyAuthenticatedProjectionAndAliases():void
    {
        self::assertNull($this->statuses->getPrivacy());self::assertNull($this->statuses->isUserMuted('200@lid'));
        $setting=['schema'=>'StatusPrivacy','mode'=>1,'userJid'=>['200@lid']];$this->signal->set('appstate-settings','StatusPrivacy',BinaryData::encode($setting));self::assertSame($setting,$this->statuses->getPrivacy());
        $this->history->upsertContact(['jid'=>'200@lid','statusMuted'=>true]);$this->history->upsertContact(['jid'=>'300@lid','statusMuted'=>false]);
        $this->signal->set('message-pn-to-lid','5520000000000@s.whatsapp.net','200@lid');$this->signal->set('message-lid-to-pn','200@lid','5520000000000@s.whatsapp.net');
        self::assertTrue($this->statuses->isUserMuted('5520000000000'));self::assertFalse($this->statuses->isUserMuted('300@lid'));
        self::assertSame(['200@lid'],array_column($this->statuses->listMuted(),'jid'));self::assertSame([],$this->statuses->listMuted(100,'200@lid'));
        $this->history->recordEvent($this->story('alias'));self::assertSame('200@lid',$this->statuses->getStatus('alias','5520000000000')['author']);self::assertTrue($this->statuses->getStatus('alias')['muted']);
    }
    public function testLocalDeletionIsNotServerRevocationAndViewSurvivesReimport():void
    {
        $this->history->recordEvent($this->story('story'));$this->statuses->markViewed('story','200@lid',self::NOW-2);
        $this->history->deleteMessage(StatusStore::CHAT,'story','200@lid');self::assertNull($this->statuses->getStatus('story'));
        $this->history->recordEvent($this->story('story'));$this->statuses->markViewed('story','200@lid',self::NOW);
        $row=$this->statuses->getStatus('story');self::assertFalse($row['revoked']);self::assertTrue($row['viewed']);self::assertSame(self::NOW-2,$row['viewedAt']);
    }
    public function testAudienceAndProjectionParticipateInSignalTransactionRollbackAndRestart():void
    {
        try{$this->signal->transaction(function():void {$this->statuses->recordAudience('rollback','100@lid',['200@lid'],'contacts',self::NOW);$this->history->recordEvent($this->story('rollback','100@lid',true));throw new \RuntimeException('rollback');});}catch(\RuntimeException){}
        self::assertNull($this->statuses->getAudience('rollback'));self::assertSame([],$this->statuses->listStatuses());
        $this->statuses->recordAudience('story','100@lid',['300:7@lid','200@lid','300@lid'],'allowlist',self::NOW);$this->history->recordEvent($this->story('story','100@lid',true));
        $this->signal->close();$this->open();$audience=$this->statuses->getAudience('story');self::assertSame(['200@lid','300@lid'],$audience['recipients']);self::assertSame('allowlist',$audience['statusSetting']);self::assertTrue($this->statuses->getStatus('story')['fromMe']);
    }
    public function testOwnPnHistoryEchoPrefersLocalLidPublicationAndDoesNotExtendItsLifetime():void
    {
        $pn='5511000000000@s.whatsapp.net';$lid='100@lid';$id='';
        for($index=0;$index<1000;$index++){$id='own-echo-'.$index;if(strcmp(hash('sha256',$id."\0".$pn),hash('sha256',$id."\0"))<0)break;}
        $this->signal->set('message-pn-to-lid',$pn,$lid);$this->signal->set('message-lid-to-pn',$lid,$pn);
        $local=$this->story($id,$lid,true);unset($local['participant']);$local['text']='Original locally persisted';$this->history->upsertMessage($local);
        $this->statuses->recordAudience($id,$lid,['200@lid'],'contacts',self::NOW);
        $this->history->recordWebMessage(['key'=>['id'=>$id,'fromMe'=>true,'participant'=>$pn],'messageTimestamp'=>self::NOW+500,'message'=>['conversation'=>'Synced echo']],StatusStore::CHAT,$pn);
        self::assertCount(2,$this->history->listMessages(StatusStore::CHAT));
        foreach([null,$pn,$lid] as $author){$record=$this->statuses->getStatus($id,$author);self::assertSame($lid,$record['author']);self::assertSame('Original locally persisted',$record['text']);self::assertSame(self::NOW,$record['timestamp']);self::assertSame($lid,$record['key']['participant']);}
        self::assertCount(1,$this->statuses->listStatuses());self::assertCount(1,$this->statuses->listStatuses(['limit'=>1,'author'=>$pn]));
        $this->signal->close();$this->open();self::assertCount(1,$this->statuses->listStatuses());self::assertSame($lid,$this->statuses->getStatus($id)['author']);
        $this->statuses->markRevoked($id,$lid,self::NOW);self::assertNull($this->statuses->getStatus($id));self::assertCount(1,$this->statuses->listStatuses(['includeRevoked'=>true]));
    }
    public function testOwnAliasDeduplicationDoesNotMergeReceivedAuthorsOrAliasRows():void
    {
        foreach(['5511000000000@s.whatsapp.net'=>'100@lid','5522000000000@s.whatsapp.net'=>'200@lid'] as $pn=>$lid){$this->signal->set('message-pn-to-lid',$pn,$lid);$this->signal->set('message-lid-to-pn',$lid,$pn);}
        foreach(['100@lid','5511000000000@s.whatsapp.net'] as $author)$this->history->recordEvent($this->story('same',$author,true));
        foreach(['200@lid','5522000000000@s.whatsapp.net','300@lid'] as $author)$this->history->recordEvent($this->story('same',$author,false));
        self::assertCount(1,$this->statuses->listStatuses(['scope'=>'own']));self::assertCount(3,$this->statuses->listStatuses(['scope'=>'received']));self::assertCount(4,$this->statuses->listStatuses());
        self::assertSame('100@lid',$this->statuses->getStatus('same')['author']);self::assertSame('300@lid',$this->statuses->getStatus('same','300@lid')['author']);
    }
    public function testExplicitReceivedAuthorWinsOverItsKnownAlternateIdentity():void
    {
        $pn='5520000000000@s.whatsapp.net';$lid='200@lid';
        $this->signal->set('message-pn-to-lid',$pn,$lid);$this->signal->set('message-lid-to-pn',$lid,$pn);
        $this->history->recordEvent($this->story('same-received',$pn));$this->history->recordEvent($this->story('same-received',$lid));
        self::assertSame($pn,$this->statuses->getStatus('same-received',$pn)['author']);
        self::assertSame($lid,$this->statuses->getStatus('same-received',$lid)['author']);
    }
    #[\PHPUnit\Framework\Attributes\DataProvider('invalidFilters')]
    public function testInvalidFiltersAreRejected(array $filters):void {$this->expectException(\InvalidArgumentException::class);$this->statuses->listStatuses($filters);}
    public static function invalidFilters():array {return [[['scope'=>'remote']],[['limit'=>0]],[['limit'=>1001]],[['limit'=>'10']],[['includeExpired'=>1]],[['beforeTimestamp'=>-1]],[['author'=>'status@broadcast']],[['unknown'=>true]]];}
}
