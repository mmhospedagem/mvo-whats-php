<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Protocol\BinaryNode;
use MVO\Service\{MexService,NewsletterService,ServiceException,ServiceTransport};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NewsletterServiceTest extends TestCase
{
    private array $queries=[];
    private array $responses=[];
    private NewsletterService $service;
    protected function setUp(): void
    {
        $wire=new ServiceTransport(function(BinaryNode $node):BinaryNode {
            $this->queries[]=$node;
            $response=array_shift($this->responses);if($response instanceof BinaryNode) return $response;
            return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('result',[],json_encode(['data'=>$response],JSON_THROW_ON_ERROR))]);
        },static function():void {},static fn():array=>[]);
        $this->service=new NewsletterService($wire,new MexService($wire));
    }
    private function variables(): array { return json_decode($this->queries[array_key_last($this->queries)]->child('query')->content,true,512,JSON_THROW_ON_ERROR)['variables']; }
    public function testFetchMetadataPreservesNativeShapeAndFlags(): void
    {
        $metadata=['id'=>'123','thread_metadata'=>['name'=>['text'=>'Canal'],'subscribers_count'=>'9007199254740993']];$this->responses[]=['xwa2_newsletter'=>$metadata];
        self::assertSame($metadata,$this->service->fetch('123@newsletter',['viewRole'=>'OWNER','fetchViewerMetadata'=>false,'fetchFullImage'=>false,'fetchCreationTime'=>false,'fetchWamoSub'=>true]));
        self::assertSame(['input'=>['key'=>'123@newsletter','type'=>'JID','view_role'=>'OWNER'],'fetch_viewer_metadata'=>false,'fetch_full_image'=>false,'fetch_creation_time'=>false,'fetch_wamo_sub'=>true,'fetch_status_metadata'=>false,'fetch_pinned_messages'=>true],$this->variables());
        $this->responses[]=['xwa2_newsletter'=>$metadata];$this->service->fetchByInvite('https://www.whatsapp.com/channel/Ab_cd-123/');
        self::assertSame(['key'=>'Ab_cd-123','type'=>'INVITE','view_role'=>'SUBSCRIBER'],$this->variables()['input']);self::assertFalse($this->variables()['fetch_full_image']);
    }
    public function testFollowerInvitationUsesAuthenticatedChannelIdentityAndCorrectV2Field():void
    {
        $this->responses[]=['xwa2_newsletter'=>['id'=>'123','thread_metadata'=>['name'=>['text'=>'Canal MVO']]]];
        $content=$this->service->followerInviteContent('123@newsletter',['caption'=>'Siga nosso canal']);
        $built=\MVO\Message\ContentBuilder::build($content,'222@lid');$decoded=\MVO\Message\ContentDecoder::decode($built['bytes']);
        self::assertSame('newsletterFollowerInviteMessageV2',$decoded['contentType']);self::assertSame('Canal MVO',$decoded['channelInvite']['name']);self::assertSame('123@newsletter',$decoded['channelInvite']['jid']);self::assertCount(1,$this->queries);self::assertSame('123@newsletter',$this->variables()['input']['key']);
        $this->responses[]=['xwa2_newsletter'=>['id'=>'999','thread_metadata'=>['name'=>['text'=>'Wrong channel']]]];$this->expectException(\UnexpectedValueException::class);$this->service->followerInviteContent('123@newsletter');
    }
    public function testFollowerInvitationRejectsForgedIdentityBeforeNetwork():void
    {
        try{$this->service->followerInviteContent('123@newsletter',['newsletterName'=>'Forged']);self::fail('Caller name accepted');}catch(\InvalidArgumentException){self::assertSame([],$this->queries);}
    }
    public function testSubscribedEmptyListIsActualResponseAndNullIsRejected(): void
    {
        $this->responses[]=['xwa2_newsletter_subscribed'=>[]];self::assertSame([],$this->service->listSubscribed());
        $this->expectException(\UnexpectedValueException::class);$this->service->listSubscribed();
    }
    public function testUpdateReactionsAreNestedAndRemovalUsesEmptyPicture(): void
    {
        $this->responses[]=['xwa2_newsletter_update'=>['id'=>'123']];
        self::assertSame(['id'=>'123'],$this->service->update('123@newsletter',['name'=>'MVO','description'=>'','picture'=>null,'reactionCodesSetting'=>'BLOCKLIST']));
        self::assertSame(['newsletter_id'=>'123@newsletter','updates'=>['name'=>'MVO','description'=>'','picture'=>'','settings'=>['reaction_codes'=>['value'=>'BLOCKLIST']]]],$this->variables());
    }
    public function testCreateAndPhotoHelpersEncodeOnlyJpegBytes(): void
    {
        $jpeg="\xff\xd8\xff\xe0JPEG";$this->responses[]=['xwa2_newsletter_create'=>['id'=>'123']];$this->service->create('MVO','Canal de teste',$jpeg);
        self::assertSame(['input'=>['name'=>'MVO','description'=>'Canal de teste','picture'=>base64_encode($jpeg)]],$this->variables());
        foreach(['setName'=>['Nova marca',['name'=>'Nova marca']],'setDescription'=>['',['description'=>'']],'setPicture'=>[$jpeg,['picture'=>base64_encode($jpeg)]],'setReactionCodes'=>['NONE',['settings'=>['reaction_codes'=>['value'=>'NONE']]]]] as $method=>[$input,$updates]) {
            $this->responses[]=['xwa2_newsletter_update'=>['id'=>'123']];$this->service->$method('123@newsletter',$input);self::assertSame($updates,$this->variables()['updates']);
        }
        $this->responses[]=['xwa2_newsletter_update'=>['id'=>'123']];$this->service->removePicture('123@newsletter');self::assertSame(['picture'=>''],$this->variables()['updates']);
    }
    public function testDiscoveryVariablesAndNullResultsRemainVisible(): void
    {
        self::assertNull($this->service->searchDirectory(['searchText'=>'MVO','categories'=>['BUSINESS'],'limit'=>12,'startCursor'=>'next/+==']));
        self::assertSame(['input'=>['search_text'=>'MVO','categories'=>['BUSINESS'],'limit'=>12,'start_cursor'=>'next/+=='],'fetch_status_metadata'=>false],$this->variables());
        $this->service->fetchDirectoryList(['view'=>'TRENDING','countryCodes'=>['BR'],'categories'=>['BUSINESS'],'limit'=>25,'startCursor'=>'cursor']);
        self::assertSame(['input'=>['view'=>'TRENDING','filters'=>['country_codes'=>['BR'],'categories'=>['BUSINESS']],'limit'=>25,'start_cursor'=>'cursor'],'fetch_status_metadata'=>false],$this->variables());
        $this->service->fetchDirectoryCategoriesPreview(['BUSINESS'],'BR',10);self::assertSame(['categories'=>['BUSINESS'],'per_category_limit'=>10,'country_code'=>'BR'],$this->variables()['input']);
        $this->service->fetchRecommended(['countryCodes'=>['BR'],'limit'=>7]);self::assertSame(['limit'=>7,'country_codes'=>['BR']],$this->variables()['input']);
        $this->service->fetchSimilar('123@newsletter',['countryCodes'=>['BR'],'limit'=>8]);self::assertSame(['newsletter_id'=>'123@newsletter','limit'=>8,'country_codes'=>['BR']],$this->variables()['input']);
        $this->service->fetchIsDomainPreviewable(['mvo.host']);self::assertSame(['url_domains'=>['mvo.host']],$this->variables());
    }
    public function testAdminQueriesAndMutationsKeepDistinctInputs(): void
    {
        foreach(['fetchAdminInfo','fetchAdminCapabilities','fetchPendingInvites','fetchEnforcements'] as $method) { self::assertNull($this->service->$method('123@newsletter'));self::assertSame(['newsletter_id'=>'123@newsletter'],$this->variables()); }
        $this->service->fetchFollowers('123@newsletter',37);self::assertSame(['newsletter_id'=>'123@newsletter','count'=>37],$this->variables()['input']);
        $metrics=[['type'=>'FOLLOWERS']];$this->service->fetchInsights('123@newsletter',$metrics);self::assertSame(['newsletter_id'=>'123@newsletter','metrics'=>$metrics],$this->variables()['input']);
        $this->service->fetchPollVoters('123@newsletter',45,'encodedhash',17);self::assertSame(['newsletter_id'=>'123@newsletter','server_id'=>'45','vote_hash'=>'encodedhash','limit'=>17],$this->variables()['input']);
        $this->service->fetchMessageReactionSenders('123@newsletter',45);self::assertSame(['id'=>'123@newsletter','server_id'=>'45'],$this->variables()['input']);
        foreach(['changeOwner','demoteAdmin'] as $method) { $this->service->$method('123@newsletter','+5511999999999');self::assertSame(['newsletter_id'=>'123@newsletter','user_id'=>'5511999999999@s.whatsapp.net'],$this->variables()); }
        foreach(['createAdminInvite','revokeAdminInvite'] as $method) { $this->responses[]=$method==='createAdminInvite'?['xwa2_newsletter_admin_invite_create'=>['id'=>'123','invite_expiration_time'=>'1700003600']]:null;$this->service->$method('123@newsletter','98765@lid');self::assertSame(['newsletter_id'=>'123@newsletter','user_id'=>'98765@lid'],$this->variables()); }
        $this->service->acceptAdminInvite('123@newsletter');self::assertSame(['newsletter_id'=>'123@newsletter'],$this->variables());
        $this->responses[]=['xwa2_newsletter_leave_v2'=>['id'=>'123']];
        $this->service->unfollow('123@newsletter');self::assertSame(['newsletter_id'=>'123@newsletter'],$this->variables());
        $this->responses[]=['xwa2_newsletter_update_user_setting'=>['id'=>'123']];
        $this->service->mute('123@newsletter',false,true);self::assertSame(['newsletter_id'=>'123@newsletter','type'=>'MUTE_ADMIN_ACTIVITY','value'=>'OFF'],$this->variables()['input']);
    }
    public function testAdminInvitesResolvePhoneToLidBeforeMex(): void
    {
        foreach(['createAdminInvite','revokeAdminInvite'] as $method) {
            $this->responses[]=new BinaryNode('iq',['type'=>'result'],[new BinaryNode('usync',[],[new BinaryNode('list',[],[new BinaryNode('user',['jid'=>'5511999999999@s.whatsapp.net'],[new BinaryNode('lid',['val'=>'98765'])])])])]);
            $this->responses[]=$method==='createAdminInvite'?['xwa2_newsletter_admin_invite_create'=>['id'=>'123','invite_expiration_time'=>1700003600]]:null;$start=count($this->queries);$this->service->$method('123@newsletter','+5511999999999');
            self::assertCount($start+2,$this->queries);self::assertSame('usync',$this->queries[$start]->attrs['xmlns']);self::assertSame('w:mex',$this->queries[$start+1]->attrs['xmlns']);self::assertSame(['newsletter_id'=>'123@newsletter','user_id'=>'98765@lid'],$this->variables());
        }
    }
    public function testUnresolvedAdminLidNeverIssuesMutation(): void
    {
        $this->responses[]=new BinaryNode('iq',['type'=>'result'],[new BinaryNode('usync',[],[new BinaryNode('list',[],[])])]);
        try{$this->service->createAdminInvite('123@newsletter','5511999999999');self::fail('Unresolved LID was sent');}catch(\UnexpectedValueException|\InvalidArgumentException){self::assertCount(1,$this->queries);self::assertSame('usync',$this->queries[0]->attrs['xmlns']);}
    }
    public function testPinAndContentLabelsUsePublicPersistedOperationVariables(): void
    {
        foreach(['pinMessages','unpinMessages'] as $method) { self::assertNull($this->service->$method('123@newsletter',[42,'43',42]));self::assertSame(['newsletter_id'=>'123@newsletter','input'=>['message_ids'=>['42','43']]],$this->variables()); }
        foreach(['addPaidPartnershipLabel','addAiContentLabel'] as $method) { $this->service->$method('123@newsletter',42,'STATUS');self::assertSame(['newsletter_id'=>'123@newsletter','server_id'=>'42','message_type'=>'STATUS'],$this->variables()); }
    }
    public function testFollowDeletedAndSuspendedStateAreExplicitFailures(): void
    {
        $this->responses[]=['xwa2_newsletter_join_v2'=>['id'=>'123','state'=>['type'=>'NON_EXISTING']]];
        try{$this->service->follow('123@newsletter');self::fail('Deleted channel was accepted');}catch(ServiceException $error){self::assertSame('404',$error->serverCode);}
        $this->responses[]=['xwa2_newsletter_join_v2'=>['id'=>'123','state'=>['type'=>'SUSPENDED']]];
        try{$this->service->follow('123@newsletter');self::fail('Suspended channel was accepted');}catch(ServiceException $error){self::assertSame('423',$error->serverCode);}
        $this->expectException(\UnexpectedValueException::class);$this->service->delete('123@newsletter');
    }
    #[DataProvider('missingResponseFacts')]
    public function testMissingRequiredResponseFactsCannotLookSuccessful(string $method,array $arguments,?array $data): void
    {
        $this->responses[]=$data;$this->expectException(\UnexpectedValueException::class);$this->service->$method(...$arguments);
    }
    public static function missingResponseFacts(): iterable
    {
        yield ['fetch',['123@newsletter'],['xwa2_newsletter'=>[]]];
        yield ['create',['Canal'],['xwa2_newsletter_create'=>[]]];
        yield ['update',['123@newsletter',['name'=>'Canal']],['xwa2_newsletter_update'=>['id'=>123]]];
        yield ['follow',['123@newsletter'],null];yield ['delete',['123@newsletter'],['xwa2_newsletter_delete_v2'=>['id'=>'']]];
        yield ['unfollow',['123@newsletter'],null];yield ['unfollow',['123@newsletter'],['xwa2_newsletter_leave_v2'=>[]]];
        yield ['mute',['123@newsletter',true],null];yield ['mute',['123@newsletter',true],['xwa2_newsletter_update_user_setting'=>true]];
        yield ['createAdminInvite',['123@newsletter','98765@lid'],null];
        yield ['createAdminInvite',['123@newsletter','98765@lid'],['xwa2_newsletter_admin_invite_create'=>['id'=>'123']]];
        yield ['createAdminInvite',['123@newsletter','98765@lid'],['xwa2_newsletter_admin_invite_create'=>['id'=>'123','invite_expiration_time'=>'not-a-timestamp']]];
    }
    public function testNormalizerKeepsMissingValuesNullAndDoesNotTruncateCounters(): void
    {
        $metadata=['id'=>'123','thread_metadata'=>['name'=>['text'=>'MVO'],'description'=>['text'=>''],'invite'=>'Ab_123','subscribers_count'=>'9007199254740993','creation_time'=>1700000000,'picture'=>['direct_path'=>'/image'],'settings'=>['reaction_codes'=>['value'=>'ALL']]],'viewer_metadata'=>['role'=>'OWNER']];
        $normalized=NewsletterService::normalizeMetadata($metadata);self::assertSame('123@newsletter',$normalized['jid']);self::assertSame('MVO',$normalized['name']);self::assertSame('',$normalized['description']);self::assertSame('9007199254740993',$normalized['subscribers']);self::assertSame('https://whatsapp.com/channel/Ab_123',$normalized['inviteUrl']);self::assertSame($metadata,$normalized['raw']);
        $empty=NewsletterService::normalizeMetadata([]);foreach($empty as $key=>$value) if($key!=='raw') self::assertNull($value,$key);
        $this->responses[]=['xwa2_newsletter'=>$metadata];self::assertSame('https://whatsapp.com/channel/Ab_123',$this->service->inviteUrl('123@newsletter'));
    }
    #[DataProvider('invalidInputs')]
    public function testInvalidInputsNeverReachTransport(string $method,array $arguments): void
    {
        try{$this->service->$method(...$arguments);self::fail('Invalid input accepted');}catch(\InvalidArgumentException){self::assertSame([],$this->queries);}
    }
    public static function invalidInputs(): iterable
    {
        yield ['fetch',['abc@newsletter']];yield ['fetch',['123@newsletter',['viewRole'=>'SUPERADMIN']]];yield ['fetch',['123@newsletter',['fetchFullImage'=>'true']]];yield ['fetch',['123@newsletter',['unknown'=>1]]];
        yield ['fetchByInvite',['https://evil.invalid/channel/abc']];yield ['fetchByInvite',['https://whatsapp.com/channel/abc?token=x']];yield ['fetchDehydrated',['bad invite']];
        yield ['searchDirectory',[['limit'=>0]]];yield ['searchDirectory',[['limit'=>'10']]];yield ['searchDirectory',[['limit'=>null]]];yield ['searchDirectory',[['searchText'=>null]]];yield ['searchDirectory',[['categories'=>null]]];yield ['searchDirectory',[['categories'=>['x'=>1]]]];yield ['searchDirectory',[['startCursor'=>null]]];yield ['searchDirectory',[['unknown'=>true]]];
        yield ['fetchRecommended',[['countryCodes'=>['br']]]];yield ['fetchSimilar',['1@newsletter',['limit'=>1001]]];yield ['fetchDirectoryList',[[]]];yield ['fetchDirectoryList',[['view'=>'ALL']]];yield ['fetchDirectoryCategoriesPreview',[[]]];yield ['fetchDirectoryCategoriesPreview',[['BUSINESS'],'BRA']];
        yield ['fetchIsDomainPreviewable',[['https://mvo.host']]];yield ['fetchIsDomainPreviewable',[[]]];
        yield ['create',[' ']];yield ['create',['MVO',null,'not jpeg']];yield ['update',['1@newsletter',['description'=>null]]];yield ['update',['1@newsletter',['name'=>'']]];yield ['update',['1@newsletter',['name'=>'MVO','typo'=>true]]];yield ['update',['1@newsletter',['reactionCodesSetting'=>'SOME']]];yield ['update',['1@newsletter',['picture'=>[]]]];
        yield ['fetchFollowers',['1@newsletter',1001]];yield ['fetchInsights',['1@newsletter',[]]];yield ['fetchInsights',['1@newsletter',['followers']]];yield ['fetchInsights',['1@newsletter',[[]]]];yield ['fetchPollVoters',['1@newsletter',0,'hash']];yield ['fetchMessageReactionSenders',['1@newsletter',-1]];
        yield ['pinMessages',['1@newsletter',[]]];yield ['pinMessages',['1@newsletter',['42abc']]];yield ['pinMessages',['1@newsletter',[0]]];yield ['addPaidPartnershipLabel',['1@newsletter',2,'TEXT']];yield ['addAiContentLabel',['1@newsletter',0]];
        yield ['acceptTos',[['']]];yield ['queryTosState',[['id'=>1]]];yield ['fetchMessages',['1@newsletter',50,1,2]];yield ['fetchMessages',['1@newsletter',50,null,null,'BOGUS']];yield ['fetchStatuses',['1@newsletter',101]];yield ['fetchMessageUpdates',['1@newsletter',50,-1,1]];
        yield ['sendViewReceipt',['1@newsletter',['2']]];yield ['votePoll',['1@newsletter',1,['invalid']]];yield ['react',['1@newsletter',1,'']];
    }
}
