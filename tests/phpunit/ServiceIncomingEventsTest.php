<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Service\{IncomingEvents,OfflineResumeService};

final class ServiceIncomingEventsTest extends TestCase
{
    public static function presence(): iterable
    {
        yield 'online'=>[['from'=>'8@lid'],['type'=>'available']];
        yield 'timestamp'=>[['from'=>'8@lid','type'=>'unavailable','last'=>'123'],['type'=>'unavailable','lastSeen'=>['kind'=>'timestamp','unixSeconds'=>123]]];
        yield 'hidden'=>[['from'=>'8@lid','type'=>'unavailable','last'=>'deny'],['type'=>'unavailable','lastSeen'=>['kind'=>'privacy_denied']]];
        yield 'never'=>[['from'=>'8@lid','type'=>'unavailable','last'=>'none'],['type'=>'unavailable','lastSeen'=>['kind'=>'never_online']]];
        yield 'unknown'=>[['from'=>'8@lid','type'=>'unavailable','last'=>'error'],['type'=>'unavailable','lastSeen'=>['kind'=>'unknown']]];
        yield 'invalid'=>[['from'=>'8@lid','type'=>'unavailable','last'=>'-4'],['type'=>'unavailable','lastSeen'=>['kind'=>'unknown']]];
        yield 'group'=>[['from'=>'1@g.us','count'=>'6'],['type'=>'available','groupOnlineCount'=>6]];
        yield 'group unavailable'=>[['from'=>'1@g.us','type'=>'unavailable','last'=>'deny'],['type'=>'unavailable','groupOnlineCount'=>0]];
    }
    #[DataProvider('presence')]
    public function testPresencePrivacyAndGroups(array $attrs,array $expected): void
    {
        $r=IncomingEvents::parse(new BinaryNode('presence',$attrs));self::assertSame('presence',$r[0]['event']);foreach($expected as $k=>$v) self::assertSame($v,$r[0]['data'][$k]);
    }
    public function testChatstateAudioAndUnknownChildren(): void
    {
        $r=IncomingEvents::parse(new BinaryNode('chatstate',['from'=>'1@g.us','participant'=>'8@lid'],[new BinaryNode('composing',['media'=>'audio'])]));self::assertSame('chatstate',$r[0]['event']);self::assertSame('audio',$r[0]['data']['media']);self::assertSame('8@lid',$r[0]['data']['participantJid']);self::assertSame([],IncomingEvents::parse(new BinaryNode('chatstate',[],[new BinaryNode('unknown')])));
    }
    public static function groupToggles(): iterable
    {
        yield ['locked','restrict',true];yield ['unlocked','restrict',false];yield ['announcement','announce',true];yield ['not_announcement','announce',false];yield ['no_frequently_forwarded','no_frequently_forwarded',true];yield ['frequently_forwarded_ok','no_frequently_forwarded',false];yield ['suspended','suspend',true];yield ['unsuspended','suspend',false];yield ['group_history','group_history',true];yield ['no_group_history','group_history',false];yield ['allow_admin_reports','allow_admin_reports',true];yield ['not_allow_admin_reports','allow_admin_reports',false];
    }
    #[DataProvider('groupToggles')]
    public function testGroupNotificationToggles(string $tag,string $action,bool $enabled): void
    {
        $r=IncomingEvents::parse(new BinaryNode('notification',['type'=>'w:gp2','from'=>'1@g.us','participant'=>'8@lid','t'=>'123'],[new BinaryNode($tag)]));self::assertSame('group',$r[0]['event']);self::assertSame($action,$r[0]['data']['action']);self::assertSame($enabled,$r[0]['data']['enabled']);self::assertSame('8@lid',$r[0]['data']['authorJid']);
    }
    public function testGroupParticipantsAndDescription(): void
    {
        $r=IncomingEvents::parse(new BinaryNode('notification',['type'=>'w:gp2','from'=>'1@g.us','offline'=>'0'],[new BinaryNode('add',[],[new BinaryNode('participant',['jid'=>'8@lid','type'=>'admin'])]),new BinaryNode('description',['id'=>'d1'],[new BinaryNode('delete')]) ]));self::assertCount(2,$r);self::assertTrue($r[0]['data']['offline']);self::assertTrue($r[0]['data']['participants'][0]['isAdmin']);self::assertFalse($r[1]['data']['enabled']);self::assertNull($r[1]['data']['description']);
    }
    public function testPictureAndOwnUsernameRouteSeparately(): void
    {
        $r=IncomingEvents::parse(new BinaryNode('notification',['type'=>'picture','from'=>'8@lid'],[new BinaryNode('set',['id'=>'123','jid'=>'8@lid','author'=>'9@lid'])]));self::assertSame('picture',$r[0]['event']);self::assertSame(123,$r[0]['data']['pictureId']);
        $r=IncomingEvents::parse(new BinaryNode('notification',['type'=>'account_sync'],[new BinaryNode('username',['username'=>'@user'])]));self::assertSame('own_username',$r[0]['event']);self::assertSame('user',$r[0]['data']['username']);self::assertSame('account_sync',$r[1]['event']);
    }
    public function testNewsletterReceivePlaintextAndEditWithStandardMessageEvent(): void
    {
        $plain=SchemaCodec::encode('Message',['extendedTextMessage'=>['text'=>'Canal']]);$r=IncomingEvents::parse(new BinaryNode('message',['from'=>'1@newsletter','id'=>'MSG1','server_id'=>'45','t'=>'100','is_sender'=>'true'],[new BinaryNode('plaintext',[],$plain)]));self::assertSame(['message','newsletter_message'],array_column($r,'event'));self::assertSame('Canal',$r[0]['data']['text']);self::assertSame(45,$r[0]['data']['serverId']);self::assertTrue($r[0]['data']['fromMe']);self::assertSame('1@newsletter',$r[0]['data']['key']['remoteJid']);
        $r=IncomingEvents::parse(new BinaryNode('message',['from'=>'1@newsletter','id'=>'MSG1','edit'=>'3'],[new BinaryNode('plaintext',[],$plain)]));self::assertSame('newsletter_update',$r[0]['event']);self::assertSame('edit',$r[0]['data']['update']['kind']);
    }
    public function testNewsletterLiveUpdatesSynthesizesParentContext(): void
    {
        $plain=SchemaCodec::encode('Message',['conversation'=>'Live']);$n=new BinaryNode('notification',['from'=>'1@newsletter','id'=>'OUT','t'=>'100'],[new BinaryNode('live_updates',[],[new BinaryNode('messages',['t'=>'200'],[new BinaryNode('message',['id'=>'INNER','server_id'=>'4'],[new BinaryNode('plaintext',[],$plain)])])])]);$r=IncomingEvents::parse($n);self::assertSame('INNER',$r[0]['data']['id']);self::assertSame('1@newsletter',$r[0]['data']['chat']);self::assertSame(200,$r[0]['data']['timestampSeconds']);
    }
    public function testNewsletterAggregateVotesAndReactions(): void
    {
        $n=new BinaryNode('message',['from'=>'1@newsletter','server_id'=>'45'],[new BinaryNode('reactions',[],[new BinaryNode('reaction',['code'=>'👍','count'=>'3'])]),new BinaryNode('votes',[],[new BinaryNode('vote',['count'=>'2'],str_repeat('a',32))]),new BinaryNode('views_count',['count'=>'12'])]);$r=IncomingEvents::parse($n);self::assertSame(['reaction','poll_vote','counters'],array_map(static fn(array $e):string=>$e['data']['update']['kind'],$r));self::assertSame(3,$r[0]['data']['update']['reactions'][0]['count']);self::assertSame(2,$r[1]['data']['update']['votes'][0]['count']);self::assertSame(12,$r[2]['data']['update']['views']);
    }
    public function testInvalidNewsletterAggregationAndMalformedProtobufNeverEmitMessage(): void
    {
        $nodes=[new BinaryNode('message',['from'=>'1@newsletter'],[new BinaryNode('votes',[],[new BinaryNode('vote',[],'bad')])]),new BinaryNode('message',['from'=>'1@newsletter'],[new BinaryNode('plaintext',[],"\xff")]),new BinaryNode('message',['from'=>'1@newsletter'],[new BinaryNode('reactions',[],[new BinaryNode('reaction',['code'=>'x','count'=>'-1'])])])];foreach($nodes as $n) { $r=IncomingEvents::parse($n);self::assertSame('service_error',$r[0]['event']); }
    }
    public function testMexJsonAndBusinessNotificationRoute(): void
    {
        $r=IncomingEvents::parse(new BinaryNode('notification',['type'=>'mex'],[new BinaryNode('update',['op_name'=>'UsernameSetNotification'],'{"data":{"value":"abc"}}')]));self::assertSame('mex_notification',$r[0]['event']);self::assertSame('abc',$r[0]['data']['data']['value']);$r=IncomingEvents::parse(new BinaryNode('notification',['type'=>'w:biz'],[new BinaryNode('profile',['jid'=>'8@lid'])]));self::assertSame('business',$r[0]['event']);
    }
    public function testMexArgoNotificationsDecodeAndMalformedNotificationsAreReported(): void
    {
        // Inline self-describing empty object, then no errors.
        $bytes="\x06\x04\x00\x00";
        $r=IncomingEvents::parse(new BinaryNode('notification',['type'=>'mex'],[new BinaryNode('update',['format'=>'argo','op_name'=>'Update'],$bytes)]));self::assertSame('mex_notification',$r[0]['event']);self::assertSame([],$r[0]['data']['data']);
        $r=IncomingEvents::parse(new BinaryNode('notification',['type'=>'mex'],[new BinaryNode('update',['format'=>'argo'],'invalid')]));self::assertSame('service_error',$r[0]['event']);
    }
    public function testOfflineBatchesDebounceAndWaitForServerTerminalEvenAfterZero(): void
    {
        $time=100.0;$sent=[];$events=[];$resume=new OfflineResumeService(static function(BinaryNode $n)use(&$sent):void {$sent[]=$n;},static function(array $e)use(&$events):void {$events[]=$e;},static function()use(&$time):float{return $time;});
        $resume->handleNode(new BinaryNode('ib',[],[new BinaryNode('offline_preview',['count'=>'1'])]));self::assertTrue($resume->isResuming());self::assertCount(1,$sent);self::assertSame('200',$sent[0]->child('offline_batch')->attrs['count']);$resume->handleNode(new BinaryNode('message',['offline'=>'0']));$resume->tick();self::assertCount(1,$sent);$time+=0.11;$resume->tick();self::assertCount(2,$sent);self::assertFalse($resume->isComplete());$resume->handleNode(new BinaryNode('ib',[],[new BinaryNode('offline',['count'=>'2'])]));self::assertTrue($resume->isComplete());self::assertSame(['status'=>'complete','totalStanzas'=>1,'remainingStanzas'=>0,'forced'=>false,'serverStanzaCount'=>2],$events[array_key_last($events)]);
    }
    public function testOfflineTimeoutReportsRemainingAsForced(): void
    {
        $time=0.0;$events=[];$resume=new OfflineResumeService(static function(BinaryNode $n):void {},static function(array $e)use(&$events):void {$events[]=$e;},static function()use(&$time):float{return $time;});$resume->handleOfflinePreview(4);$time=61;$resume->tick();self::assertTrue($resume->isComplete());self::assertSame(4,$events[1]['remainingStanzas']);self::assertTrue($events[1]['forced']);$resume->reset();self::assertFalse($resume->isComplete());self::assertFalse($resume->isResuming());
    }
    public function testOfflineBatchTransportRetriesAreBounded(): void
    {
        $time=0.0;$events=[];$attempts=0;$resume=new OfflineResumeService(static function(BinaryNode $n)use(&$attempts):void {$attempts++;throw new \RuntimeException('test transport');},static function(array $e)use(&$events):void {$events[]=$e;},static function()use(&$time):float{return $time;});$resume->handleOfflinePreview(2);for($i=0;$i<8;$i++) {$time+=0.2;$resume->tick();}self::assertSame(4,$attempts);self::assertFalse($events[array_key_last($events)]['willRetry']);self::assertTrue($resume->isResuming());$time=61;$resume->tick();self::assertTrue($resume->isComplete());self::assertTrue($events[array_key_last($events)]['forced']);
    }
}
