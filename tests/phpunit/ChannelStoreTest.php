<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\{ChannelStore,HistoryStore};
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class ChannelStoreTest extends TestCase
{
    private string $path;
    private SignalStore $store;
    protected function setUp():void {$this->path=tempnam(sys_get_temp_dir(),'mvo-channel-');$this->store=new SignalStore($this->path);}
    protected function tearDown():void {$this->store->close();foreach([$this->path,$this->path.'-wal',$this->path.'-shm']as$file)if(is_file($file))unlink($file);}
    private function channels():ChannelStore {return new ChannelStore($this->store,new HistoryStore($this->store));}

    public function testPartialMetadataAndSettingsRemainIndependentAcrossRestart():void
    {
        $channels=$this->channels();
        $channels->recordUpdate('1@newsletter','join',['id'=>'1@newsletter','thread_metadata'=>['name'=>['text'=>'Original','id'=>'n1'],'pinned_messages'=>[['message_id'=>'10','expiry_ts'=>'200']]]],100);
        $channels->recordUpdate('1@newsletter','metadata',['id'=>'1@newsletter','thread_metadata'=>['name'=>['text'=>'Atual'],'pinned_messages'=>[]]],120);
        $channels->recordUpdate('1@newsletter','metadata',['id'=>'1@newsletter','thread_metadata'=>['name'=>['text'=>'Atrasado']]],110);
        $channels->recordUpdate('1@newsletter','metadata',['id'=>'1@newsletter','thread_metadata'=>['description'=>['text'=>'Campo independente']]],110);
        $channels->recordUpdate('1@newsletter','user_setting',['id'=>'1@newsletter','setting'=>['type'=>'MUTE_FOLLOWER_ACTIVITY','value'=>'ON']],105);
        $channels->recordUpdate('1@newsletter','leave',['id'=>'1@newsletter'],125);
        $this->store->close();$this->store=new SignalStore($this->path);$record=$this->channels()->getChannel('1@newsletter');
        self::assertSame('Atual',$record['metadata']['thread_metadata']['name']['text']);
        self::assertSame('n1',$record['metadata']['thread_metadata']['name']['id']);
        self::assertSame('Campo independente',$record['metadata']['thread_metadata']['description']['text']);
        self::assertSame([],$record['metadata']['thread_metadata']['pinned_messages']);
        self::assertSame('ON',$record['userSettings']['MUTE_FOLLOWER_ACTIVITY']);self::assertFalse($record['following']);
        $this->channels()->recordUpdate('1@newsletter','join',['id'=>'1@newsletter'],101);self::assertFalse($this->channels()->getChannel('1@newsletter')['following']);
    }

    public function testObservedAdminRoleAndStateAreStoredWithoutInventingMetadata():void
    {
        $channels=$this->channels();$channels->recordUpdate('2@newsletter','admin_promote',['user'=>['id'=>'7@lid'],'user_new_role'=>'ADMIN'],100);
        $channels->recordUpdate('2@newsletter','state',['state'=>['type'=>'SUSPENDED']],110);
        $record=$channels->getChannel('2@newsletter');self::assertSame(['state'=>['type'=>'SUSPENDED']],$record['metadata']);
        self::assertSame('ADMIN',$record['observedAdminRoles']['7@lid']);self::assertArrayNotHasKey('following',$record);
        $channels->recordUpdate('2@newsletter','admin_demote',['user'=>['id'=>'7@lid'],'user_new_role'=>'SUBSCRIBER'],120);
        $channels->recordUpdate('2@newsletter','admin_promote',['user'=>['id'=>'7@lid'],'user_new_role'=>'ADMIN'],105);
        self::assertSame('SUBSCRIBER',$channels->getChannel('2@newsletter')['observedAdminRoles']['7@lid']);
    }

    public function testLegacyHistoryIsIndexedLazilyAndLookupCannotCrossChannels():void
    {
        $history=new HistoryStore($this->store);$history->upsertMessage(['chat'=>'1@newsletter','id'=>'POST','serverId'=>50,'timestamp'=>100,'text'=>'Local']);
        $channels=$this->channels();self::assertSame('POST',$channels->findByServerId('1@newsletter',50)['id']);
        self::assertSame('POST',$this->store->get('newsletter-message-index:1@newsletter','50'));
        self::assertNull($channels->findByServerId('2@newsletter',50));self::assertSame('Local',$channels->getMessage('1@newsletter','server:50')['text']);
        $this->expectException(\InvalidArgumentException::class);$channels->getMessage('1@newsletter','POST',51);
    }

    public function testLocalPaginationUsesExclusiveTimestampAndJidCursors():void
    {
        $history=new HistoryStore($this->store);$channels=$this->channels();
        foreach([1,2,3]as$i){$channels->recordUpdate($i.'@newsletter','metadata',['id'=>$i.'@newsletter']);$history->upsertMessage(['chat'=>'1@newsletter','id'=>'P'.$i,'timestamp'=>100+$i]);}
        self::assertSame(['1@newsletter','2@newsletter'],array_column($channels->listChannels(2),'jid'));
        self::assertSame(['3@newsletter'],array_column($channels->listChannels(2,'2@newsletter'),'jid'));
        self::assertSame(['P2','P1'],array_column($channels->listMessages('1@newsletter',2,103),'id'));
        self::assertNull($channels->getChannel('9@newsletter'));
    }
}
