<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\{ChannelStore,HistoryStore};
use MVO\Message\MessageCodec;
use MVO\Protocol\BinaryNode;
use MVO\Service\{IncomingEvents,NewsletterInbox};
use MVO\Signal\SignalStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NewsletterInboxTest extends TestCase
{
    private string $path;
    private SignalStore $store;
    protected function setUp():void {$this->path=tempnam(sys_get_temp_dir(),'mvo-channel-inbox-');$this->store=new SignalStore($this->path);}
    protected function tearDown():void {$this->store->close();foreach([$this->path,$this->path.'-wal',$this->path.'-shm']as$file)if(is_file($file))unlink($file);}
    /** @param array<string,mixed> $payload */
    private function notification(string $operation,string $root,array $payload):BinaryNode
    {
        return new BinaryNode('notification',['type'=>'mex','from'=>'s.whatsapp.net','id'=>'NOTIFY','t'=>'100'],[
            new BinaryNode('update',['op_name'=>$operation],json_encode(['data'=>[$root=>$payload]],JSON_THROW_ON_ERROR)),
        ]);
    }
    /** @return iterable<string,array{string,string,string}> */
    public static function notifications():iterable
    {
        yield 'join'=>['NotificationNewsletterJoin','xwa2_notify_newsletter_on_join','join'];
        yield 'leave'=>['NotificationNewsletterLeave','xwa2_notify_newsletter_on_leave','leave'];
        yield 'metadata'=>['NotificationNewsletterUpdate','xwa2_notify_newsletter_on_metadata_update','metadata'];
        yield 'setting'=>['NotificationNewsletterUserSettingChange','xwa2_notify_newsletter_on_user_setting_change','user_setting'];
        yield 'state'=>['NotificationNewsletterStateChange','xwa2_notify_newsletter_on_state_change','state'];
        yield 'promote'=>['NotificationNewsletterAdminPromote','xwa2_notify_newsletter_admin_promote','admin_promote'];
        yield 'demote'=>['NotificationNewsletterAdminDemote','xwa2_notify_newsletter_admin_demote','admin_demote'];
        yield 'owner'=>['NotificationNewsletterOwnerUpdate','xwa2_notify_newsletter_owner_on_metadata_update','owner_metadata'];
        yield 'admin'=>['NotificationNewsletterAdminMetadataUpdate','xwa2_notify_newsletter_on_admin_metadata_update','admin_metadata'];
    }
    #[DataProvider('notifications')]
    public function testKnownMexNotificationsAreTypedWithoutLosingGenericEnvelope(string $operation,string $root,string $action):void
    {
        $node=$this->notification($operation,$root,['id'=>'123','thread_metadata'=>['name'=>['text'=>'Canal']]]);$events=IncomingEvents::parse($node);
        self::assertSame(['mex_notification','channel_update'],array_column($events,'event'));self::assertSame($action,$events[1]['data']['action']);
        self::assertSame('123@newsletter',$events[1]['data']['chatJid']);self::assertSame('123',$events[1]['data']['data']['id']);
    }

    public function testMetadataProjectionAndFailedCallbackReplaySurviveRestart():void
    {
        $node=$this->notification('NotificationNewsletterJoin','xwa2_notify_newsletter_on_join',['id'=>'1@newsletter','thread_metadata'=>['name'=>['text'=>'Canal']]]);
        $inbox=new NewsletterInbox($this->store,new HistoryStore($this->store),static function():void {throw new \RuntimeException('callback unavailable');});
        try{$inbox->accept($node,IncomingEvents::parse($node));self::fail();}catch(\RuntimeException $e){self::assertSame('callback unavailable',$e->getMessage());}
        self::assertSame('Canal',(new ChannelStore($this->store,new HistoryStore($this->store)))->getChannel('1@newsletter')['metadata']['thread_metadata']['name']['text']);
        $this->store->close();$this->store=new SignalStore($this->path);$delivered=[];
        $inbox=new NewsletterInbox($this->store,new HistoryStore($this->store),static function(string $event,array $data)use(&$delivered):void{$delivered[]=[$event,$data['replayed']];});
        $inbox->drainPending();$inbox->accept($node,IncomingEvents::parse($node));
        self::assertSame([['channel_update',true]],$delivered);self::assertSame([],$this->store->entries('newsletter-inbox-pending'));
    }

    public function testHistoryPostWithCountersPreservesBodyAndDeduplicatesSubsequentPush():void
    {
        $message=new BinaryNode('message',['id'=>'POST','server_id'=>'10','t'=>'100'],[new BinaryNode('plaintext',[],MessageCodec::text('Completo')),new BinaryNode('views_count',['count'=>'12'])]);
        $response=new BinaryNode('iq',['type'=>'result'],[new BinaryNode('messages',[],[$message])]);$delivered=[];
        $history=new HistoryStore($this->store);$inbox=new NewsletterInbox($this->store,$history,static function(string $event)use(&$delivered):void{$delivered[]=$event;});
        $events=$inbox->ingestResponse($response,'1@newsletter');self::assertSame(['message','newsletter_message','newsletter_update'],array_column($events,'event'));
        $pushed=new BinaryNode('message',['from'=>'1@newsletter',...$message->attrs],$message->content);$inbox->accept($pushed,IncomingEvents::parse($pushed));
        self::assertSame(['message','newsletter_message','newsletter_update'],$delivered);self::assertSame('Completo',$history->getMessage('1@newsletter','POST')['text']);
        self::assertSame(12,$history->getMessage('1@newsletter','POST')['views']);self::assertSame('POST',(new ChannelStore($this->store,$history))->findByServerId('1@newsletter',10)['id']);
    }

    public function testIndependentOutOfOrderUpdatesAndEmptySnapshotRemoval():void
    {
        $history=new HistoryStore($this->store);$inbox=new NewsletterInbox($this->store,$history,static function():void{});
        foreach([
            ['100',[new BinaryNode('plaintext',[],MessageCodec::text('Post'))]],
            ['120',[new BinaryNode('views_count',['count'=>'20'])]],
            ['109',[new BinaryNode('forwards_count',['count'=>'4'])]],
            ['110',[new BinaryNode('reaction',['code'=>'👍','count'=>'2'])]],
            ['115',[new BinaryNode('reactions')]],
            ['105',[new BinaryNode('votes')]],
        ]as[$timestamp,$nodes]){$n=new BinaryNode('message',['from'=>'1@newsletter','id'=>'POST','server_id'=>'10','t'=>$timestamp],$nodes);$inbox->accept($n,IncomingEvents::parse($n));}
        $record=$history->getMessage('1@newsletter','POST');self::assertSame(20,$record['views']);self::assertSame([],$record['reactions']);self::assertSame([],$record['pollVotes']);
        self::assertSame(4,$record['forwards']);
        self::assertSame(115,$record['newsletterUpdateTimes']['reaction']);self::assertSame(120,$record['newsletterUpdatedAt']);
    }

    public function testRemoteStatusesAreMarkedAndServerOnlyIdentityIsRetained():void
    {
        $n=new BinaryNode('iq',['type'=>'result'],[new BinaryNode('statuses',['t'=>'100'],[
            new BinaryNode('status',['server_id'=>'12'],[new BinaryNode('plaintext',[],MessageCodec::text('Status do canal'))]),
        ])]);
        $events=IncomingEvents::parseNewsletterResponse($n,'1@newsletter');self::assertSame('server:12',$events[0]['data']['id']);self::assertTrue($events[0]['data']['isChannelStatus']);self::assertTrue($events[0]['data']['history']);
        self::assertSame(100,$events[0]['data']['timestampSeconds']);
    }

    public function testStatusAndPostWithSameServerIdentityNeverReplaceEachOther():void
    {
        $history=new HistoryStore($this->store);$inbox=new NewsletterInbox($this->store,$history,static function():void{});
        foreach(['messages'=>'message','statuses'=>'status']as$container=>$child){
            $node=new BinaryNode('iq',['type'=>'result'],[new BinaryNode($container,[],[new BinaryNode($child,['id'=>'POST','server_id'=>'10','t'=>'100'],[new BinaryNode('plaintext',[],MessageCodec::text($container))])])]);
            $inbox->ingestResponse($node,'1@newsletter');
        }
        $channels=new ChannelStore($this->store,$history);self::assertSame('messages',$channels->findByServerId('1@newsletter',10)['text']);
        self::assertSame('statuses',$channels->findByServerId('1@newsletter',10,true)['text']);
        self::assertCount(1,$channels->listMessages('1@newsletter'));self::assertSame('messages',$channels->getMessage('1@newsletter','POST')['text']);
        self::assertNull($channels->getMessage('1@newsletter','status:POST'));
    }

    public function testRepeatedAggregateValueAtNewerTimestampIsNotDeduplicated():void
    {
        $history=new HistoryStore($this->store);$inbox=new NewsletterInbox($this->store,$history,static function():void{});
        foreach([100=>2,101=>3,102=>2]as$timestamp=>$count){
            $n=new BinaryNode('message',['from'=>'1@newsletter','server_id'=>'10','t'=>(string)$timestamp],[new BinaryNode('reactions',[],[new BinaryNode('reaction',['code'=>'👍','count'=>(string)$count])])]);
            $inbox->accept($n,IncomingEvents::parse($n));
        }
        self::assertSame(2,(new ChannelStore($this->store,$history))->findByServerId('1@newsletter',10)['reactions'][0]['count']);
        self::assertCount(3,$this->store->entries('newsletter-inbox-done'));
    }

    public function testHistoryResponseCannotCrossChannelBoundary():void
    {
        $n=new BinaryNode('iq',['type'=>'result'],[new BinaryNode('messages',[],[new BinaryNode('message',['from'=>'2@newsletter','id'=>'P'])])]);
        $this->expectException(\UnexpectedValueException::class);IncomingEvents::parseNewsletterResponse($n,'1@newsletter');
    }

    public function testHistoryContainerCannotOverrideRequestedChannel():void
    {
        $n=new BinaryNode('iq',['type'=>'result'],[new BinaryNode('messages',['jid'=>'2@newsletter'],[new BinaryNode('message',['id'=>'P'])])]);
        $this->expectException(\UnexpectedValueException::class);IncomingEvents::parseNewsletterResponse($n,'1@newsletter');
    }

    public function testZeroAggregateCountsAreRejectedInsteadOfInventingRemovalSemantics():void
    {
        foreach([new BinaryNode('reactions',[],[new BinaryNode('reaction',['code'=>'👍','count'=>'0'])]),new BinaryNode('votes',[],[new BinaryNode('vote',['count'=>'0'],str_repeat('x',32))])]as$body){
            $n=new BinaryNode('message',['from'=>'1@newsletter','server_id'=>'10'],[$body]);self::assertSame('service_error',IncomingEvents::parse($n)[0]['event']);
        }
    }

    public function testMexErrorDoesNotProjectChannelMutation():void
    {
        $n=new BinaryNode('notification',['type'=>'mex'],[new BinaryNode('update',['op_name'=>'NotificationNewsletterJoin'],'{"errors":[{"message":"denied"}],"data":{"xwa2_notify_newsletter_on_join":{"id":"1@newsletter"}}}')]);
        self::assertSame(['mex_notification'],array_column(IncomingEvents::parse($n),'event'));
    }

    public function testMalformedMessageIdentitiesBecomeErrorsWithoutCrashingTheDurableInbox():void
    {
        $inbox=new NewsletterInbox($this->store,new HistoryStore($this->store),static function():void{});
        foreach([['from'=>'1@newsletter','server_id'=>'0'],['from'=>'1@newsletter','server_id'=>'invalid'],['from'=>'bad@newsletter','id'=>'P'],['from'=>'1@newsletter']]as$attrs){
            $n=new BinaryNode('message',$attrs,[new BinaryNode('plaintext',[],MessageCodec::text('Inválida'))]);$events=IncomingEvents::parse($n);
            self::assertSame('service_error',$events[0]['event']);self::assertTrue($inbox->accept($n,$events));
        }
        self::assertSame([],(new ChannelStore($this->store,new HistoryStore($this->store)))->listMessages('1@newsletter'));
    }
}
