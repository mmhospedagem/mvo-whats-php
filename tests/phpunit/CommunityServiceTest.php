<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Mcp\ClientRuntime;
use MVO\Protocol\BinaryNode;
use MVO\Service\{CommunityService,IncomingEvents,ServiceException,Services};
use MVO\WaClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Local IQ/MEX fixtures only. These tests never create or modify a real WhatsApp community. */
final class CommunityServiceTest extends TestCase
{
    private array $queries=[];
    private array $responses=[];
    private function services():Services
    {
        return new Services(function(BinaryNode $node,float $timeout):BinaryNode {
            $this->queries[]=$node;return array_shift($this->responses) ?? self::iqResult();
        },static function(BinaryNode $node):void {},static fn():array=>[]);
    }
    private function service():CommunityService { return $this->services()->community(); }
    private static function iqResult(array $nodes=[]):BinaryNode { return new BinaryNode('iq',['type'=>'result'],$nodes); }
    private static function group(string $id='1',array $nodes=[]):BinaryNode { return new BinaryNode('group',['id'=>$id,'subject'=>'Comunidade MVO'],$nodes); }
    private function last():BinaryNode { return $this->queries[array_key_last($this->queries)]; }
    private static function jpeg():string
    {
        return base64_decode('/9j/4AAQSkZJRgABAQAAAQABAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////2wBDAf//////////////////////////////////////////////////////////////////////////////////////wAARCAABAAEDASIAAhEBAxEB/8QAFQABAQAAAAAAAAAAAAAAAAAAAAb/xAAUEAEAAAAAAAAAAAAAAAAAAAAA/9oADAMBAAIQAxAAAAF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABBQJ//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPwF//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPwF//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQAGPwJ//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPyF//9oADAMBAAIAAwAAABAf/8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAwEBPxB//8QAFBEBAAAAAAAAAAAAAAAAAAAAAP/aAAgBAgEBPxB//8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxB//9k=',true);
    }

    public function testLazyFacadeUsesOneExistingTransportAndDoesNotConnect():void
    {
        $services=$this->services();self::assertSame($services->community(),$services->community());self::assertSame([],$this->queries);
        $client=new WaClient('community-local',sys_get_temp_dir().'/mvo-community-local');
        try { $client->community();self::fail('Disconnected client must not expose authenticated services'); }
        catch(\LogicException $error) { self::assertSame('WhatsApp is not connected',$error->getMessage());self::assertFalse($client->isConnected()); }
    }
    public function testListIncludesOnlyServerConfirmedParents():void
    {
        $this->responses[]=self::iqResult([new BinaryNode('groups',[],[
            self::group('1',[new BinaryNode('parent',['default_membership_approval_mode'=>'request_required'])]),
            self::group('2',[new BinaryNode('linked_parent',['jid'=>'1@g.us']),new BinaryNode('default_sub_group')]),
            self::group('3'),self::group('4',[new BinaryNode('parent')])])]);
        $result=$this->service()->queryAllCommunities();self::assertSame(['1@g.us','4@g.us'],array_column($result,'jid'));
        self::assertSame('request_required',$result[0]['defaultMembershipApprovalMode']);self::assertTrue($result[0]['isClosedCommunity']);self::assertFalse($result[1]['isClosedCommunity']);
        self::assertSame(['type'=>'get','to'=>'g.us','xmlns'=>'w:g2'],$this->last()->attrs);self::assertNotNull($this->last()->child('participating')->child('participants'));
    }
    public function testGetMetadataDoesNotRelabelAnOrdinaryGroup():void
    {
        $this->responses[]=self::iqResult([self::group()]);$this->expectException(\UnexpectedValueException::class);$this->service()->queryCommunityMetadata('1@g.us');
    }
    public function testCreateParentPreservesDescriptionAndOptions():void
    {
        $parent=new BinaryNode('parent',['default_membership_approval_mode'=>'request_required']);$this->responses[]=self::iqResult([self::group('1',[$parent])]);
        $result=$this->service()->createCommunity('Comunidade MVO',['description'=>'Clientes','allowNonAdminSubGroupCreation'=>true,'createGeneralChat'=>true]);
        self::assertTrue($result['isParentGroup']);$create=$this->last()->child('create');self::assertSame('request_required',$create->child('parent')->attrs['default_membership_approval_mode']);
        self::assertSame('Clientes',$create->child('description')->child('body')->content);self::assertNotNull($create->child('allow_non_admin_sub_group_creation'));self::assertNotNull($create->child('create_general_chat'));self::assertNull($create->child('linked_parent'));
    }
    public function testOpenCommunityUsesOpenParentWithoutForcingClosedMode():void
    {
        $this->responses[]=self::iqResult([self::group('1',[new BinaryNode('parent')])]);
        self::assertFalse($this->service()->createCommunity('Aberta',['membershipApprovalMode'=>'open'])['isClosedCommunity']);self::assertSame([],$this->last()->child('create')->child('parent')->attrs);
    }
    public function testSubgroupIsLinkedInTheSameCreateRequest():void
    {
        $this->responses[]=self::iqResult([self::group('2',[new BinaryNode('linked_parent',['jid'=>'1@g.us']),new BinaryNode('general_chat')])]);
        $result=$this->service()->createSubGroup('1@g.us','Atendimento',['+55 (11) 90000-0001','9@lid'],['description'=>'Suporte']);
        self::assertCount(1,$this->queries);self::assertSame('1@g.us',$result['linkedParentJid']);self::assertTrue($result['generalSubgroup']);$create=$this->last()->child('create');
        self::assertSame('1@g.us',$create->child('linked_parent')->attrs['jid']);self::assertNull($create->child('parent'));self::assertSame('5511900000001@s.whatsapp.net',$create->child('participant')->attrs['jid']);
    }
    public function testDescriptionFetchesCurrentVersionBeforeCompareAndSet():void
    {
        $this->responses[]=self::iqResult([self::group('1',[new BinaryNode('description',['id'=>'version-7'],[new BinaryNode('body',[],'Antiga')])])]);
        $this->responses[]=self::iqResult();$this->service()->setDescription('1@g.us','Nova');
        self::assertCount(2,$this->queries);self::assertSame('get',$this->queries[0]->attrs['type']);self::assertSame('version-7',$this->last()->child('description')->attrs['prev']);self::assertSame('Nova',$this->last()->child('description')->child('body')->content);
    }
    public function testExplicitDescriptionVersionAvoidsReadAndEmptyDescriptionDeletes():void
    {
        $this->service()->setDescription('1@g.us','','version-7');self::assertCount(1,$this->queries);$node=$this->last()->child('description');self::assertSame('true',$node->attrs['delete']);self::assertSame('version-7',$node->attrs['prev']);self::assertNull($node->content);
    }
    public function testSubgroupListsKeepAnnouncementsGeneralAndHiddenIdentity():void
    {
        $this->responses[]=self::iqResult([new BinaryNode('sub_groups',[],[
            self::group('2',[new BinaryNode('linked_parent',['jid'=>'1@g.us']),new BinaryNode('default_sub_group'),new BinaryNode('announcement')]),
            self::group('3',[new BinaryNode('general_chat')]),self::group('4',[new BinaryNode('hidden_group')])])]);
        $groups=$this->service()->querySubGroups('1@g.us');self::assertCount(3,$groups);self::assertTrue($groups[0]['defaultSubgroup']);self::assertTrue($groups[0]['announce']);self::assertSame('1@g.us',$groups[0]['linkedParentJid']);self::assertTrue($groups[1]['generalSubgroup']);self::assertTrue($groups[2]['hiddenSubgroup']);self::assertNotNull($this->last()->child('sub_groups'));
    }
    public function testMissingSubgroupResponseIsNotAnEmptySuccessList():void
    {
        $this->expectException(\UnexpectedValueException::class);$this->service()->querySubGroups('1@g.us');
    }
    public function testLinkAndUnlinkRetainAllPerGroupFailuresAndHiddenWire():void
    {
        $this->responses[]=self::iqResult([new BinaryNode('links',[],[new BinaryNode('link',[],[
            new BinaryNode('group',['jid'=>'2@g.us']),new BinaryNode('group',['jid'=>'3@g.us','error'=>'403']),new BinaryNode('group',['jid'=>'4@g.us'],[new BinaryNode('error',['code'=>'409'])])])])]);
        $service=$this->service();$result=$service->linkSubGroups('1@g.us',['2@g.us',['jid'=>'3@g.us','hidden'=>true],['jid'=>'4@g.us','hidden'=>false]]);
        self::assertSame(['2@g.us'],$result['linkedJids']);self::assertSame([403,409],array_column($result['failed'],'error'));$nodes=$this->last()->child('links')->child('link')->content;self::assertNotNull($nodes[1]->child('hidden_group'));self::assertNull($nodes[2]->child('hidden_group'));
        $this->responses[]=self::iqResult([new BinaryNode('unlink',[],[new BinaryNode('group',['jid'=>'2@g.us']),new BinaryNode('group',['jid'=>'3@g.us','error'=>'403'])])]);
        $result=$service->unlinkSubGroups('1@g.us',['2@g.us','3@g.us'],true);self::assertSame(['2@g.us'],$result['unlinkedJids']);self::assertSame([['jid'=>'3@g.us','error'=>403]],$result['failed']);self::assertSame('true',$this->last()->child('unlink')->child('group')->attrs['remove_orphaned_members']);
    }
    public function testEmptyLinkAckDoesNotInventLinkedIds():void
    {
        self::assertSame(['linkedJids'=>[],'failed'=>[]],$this->service()->linkSubGroups('1@g.us',['2@g.us']));
    }
    public static function memberActions():iterable
    {
        yield ['addParticipants','add',false];yield ['removeParticipants','remove',false];yield ['promoteParticipants','promote',false];yield ['demoteParticipants','demote',false];
        yield ['approveMembershipRequests','approve',true];yield ['rejectMembershipRequests','reject',true];yield ['cancelMembershipRequests','cancel_membership_requests',false];
    }
    #[DataProvider('memberActions')]
    public function testMemberActionsPreserveServerResultForEachMember(string $method,string $action,bool $nested):void
    {
        $members=[new BinaryNode('participant',['jid'=>'9@lid']),new BinaryNode('participant',['jid'=>'10@lid','error'=>'403']),new BinaryNode('participant',['jid'=>'11@lid'],[new BinaryNode('error',['code'=>'409']),new BinaryNode('add_request',['code'=>'INVITE','expiration'=>'123'])])];
        $actionNode=new BinaryNode($action,[],$members);$this->responses[]=self::iqResult([$nested ? new BinaryNode('membership_requests_action',[],[$actionNode]) : $actionNode]);
        $result=$this->service()->$method('1@g.us',['9@lid','10@lid','11@lid']);self::assertSame([200,403,409],array_column($result,'code'));self::assertSame(['ok','error','error'],array_column($result,'status'));self::assertSame('INVITE',$result[2]['raw']->child('add_request')->attrs['code']);
        $wire=$nested ? $this->last()->child('membership_requests_action')->child($action) : $this->last()->child($action);self::assertCount(3,$wire->content);self::assertSame('set',$this->last()->attrs['type']);
    }
    public function testParticipantsNormalizePhonesAndDoNotDuplicate():void
    {
        $this->service()->addParticipants('1@g.us',['+55 (11) 90000-0001','5511900000001@s.whatsapp.net','9@lid']);
        self::assertSame(['5511900000001@s.whatsapp.net','9@lid'],array_map(static fn(BinaryNode $node):string=>$node->attrs['jid'],$this->last()->child('add')->content));
    }
    public function testMembershipReadAndLinkedParticipantsKeepActualUserIdentity():void
    {
        $this->responses[]=self::iqResult([new BinaryNode('membership_approval_requests',[],[new BinaryNode('membership_approval_request',['jid'=>'9@lid','request_time'=>'123','custom'=>'server-value'])])]);
        $service=$this->service();self::assertSame([['jid'=>'9@lid','request_time'=>'123','custom'=>'server-value']],$service->queryMembershipApprovalRequests('1@g.us'));
        $this->responses[]=self::iqResult([new BinaryNode('linked_groups_participants',[],[new BinaryNode('participant',['jid'=>'9@lid','phone_number'=>'5511900000001@s.whatsapp.net','type'=>'superadmin'])])]);
        $result=$service->queryLinkedGroupsParticipants('1@g.us');self::assertSame('9@lid',$result[0]['jid']);self::assertSame('5511900000001@s.whatsapp.net',$result[0]['phoneNumber']);self::assertTrue($result[0]['isSuperAdmin']);
    }
    public static function mutations():iterable
    {
        yield ['setSubject',['1@g.us','Novo'],'1@g.us','subject',[],'Novo'];
        yield ['setSetting',['1@g.us','allow_non_admin_sub_group_creation',true],'1@g.us','allow_non_admin_sub_group_creation',[],null];
        yield ['setSetting',['1@g.us','allow_non_admin_sub_group_creation',false],'1@g.us','not_allow_non_admin_sub_group_creation',[],null];
        yield ['setMemberAddMode',['1@g.us','admin_add'],'1@g.us','member_add_mode',[],'admin_add'];
        yield ['setMemberLinkMode',['1@g.us','all_member_link'],'1@g.us','member_link_mode',[],'all_member_link'];
        yield ['setMemberShareGroupHistoryMode',['1@g.us','admin_share'],'1@g.us','member_share_group_history_mode',[],'admin_share'];
        yield ['setEphemeralDuration',['1@g.us',86400,1],'1@g.us','ephemeral',['expiration'=>'86400','trigger'=>'1'],null];
        yield ['joinLinkedGroup',['1@g.us','2@g.us','linked'],'1@g.us','join_linked_group',['jid'=>'2@g.us','type'=>'linked'],null];
        yield ['deactivateCommunity',['1@g.us'],'1@g.us','delete_parent',[],null];
    }
    #[DataProvider('mutations')]
    public function testMutationsUseAuthenticatedGroupProtocol(string $method,array $arguments,string $to,string $tag,array $attrs,mixed $content):void
    {
        $this->service()->$method(...$arguments);$wire=$this->last();self::assertSame(['type'=>'set','to'=>$to,'xmlns'=>'w:g2'],$wire->attrs);self::assertSame($attrs,$wire->child($tag)->attrs);self::assertSame($content,$wire->child($tag)->content);
    }
    public function testApprovalModeAndLeaveUseNativeStanzas():void
    {
        $service=$this->service();$service->setSetting('1@g.us','membership_approval_mode',true);self::assertSame('on',$this->last()->child('membership_approval_mode')->child('group_join')->attrs['state']);
        $service->leaveCommunity('1@g.us');self::assertSame('g.us',$this->last()->attrs['to']);self::assertSame(['id'=>'1@g.us'],$this->last()->child('leave')->child('group')->attrs);
    }
    public function testIqAclFailureIsNeverTranslatedIntoSuccessfulMutation():void
    {
        $this->responses[]=new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'403','text'=>'not-authorized'])]);
        try { $this->service()->deactivateCommunity('1@g.us');self::fail('Expected ACL rejection'); }
        catch(ServiceException $error) { self::assertSame('403',$error->serverCode);self::assertSame('group.deactivateCommunity',$error->operation); }
    }
    public function testDescriptionReadAclFailureStopsBeforeMutation():void
    {
        $this->responses[]=new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'403'])]);
        try { $this->service()->setDescription('1@g.us','Nova');self::fail('Expected ACL rejection'); }
        catch(ServiceException $error) { self::assertSame('group.metadata',$error->operation);self::assertCount(1,$this->queries); }
    }
    public function testInviteFetchRevokePreviewAndPendingJoinNeverInventMembership():void
    {
        $service=$this->service();$this->responses[]=self::iqResult([new BinaryNode('invite',['code'=>'CODE'])]);self::assertSame('CODE',$service->queryInviteCode('1@g.us'));
        $participant=new BinaryNode('participant',['jid'=>'9@lid']);$this->responses[]=self::iqResult([new BinaryNode('invite',['code'=>'NEW']),$participant]);self::assertSame(['code'=>'NEW','affectedParticipants'=>[$participant]],$service->revokeInvite('1@g.us'));
        $this->responses[]=self::iqResult([self::group('1',[new BinaryNode('parent')])]);self::assertSame('1@g.us',$service->queryCommunityInviteInfo('https://chat.whatsapp.com/CODE')['jid']);self::assertSame('CODE',$this->last()->child('invite')->attrs['code']);
        $this->responses[]=self::iqResult([new BinaryNode('membership_approval_request',['jid'=>'1@g.us','request_time'=>'123'])]);$result=$service->joinCommunityViaInvite('CODE');self::assertSame('pending',$result['status']);self::assertSame('1@g.us',$result['jid']);self::assertArrayNotHasKey('participants',$result);self::assertSame('set',$this->last()->attrs['type']);
    }
    public function testJoinedInviteCanReturnMinimalRealGroupIdentity():void
    {
        $this->responses[]=self::iqResult([new BinaryNode('group',['jid'=>'1@g.us'])]);$result=$this->service()->joinCommunityViaInvite('CODE');self::assertSame('1@g.us',$result['jid']);self::assertSame('joined',$result['status']);
    }
    public function testCommunityInvitePreviewRejectsOrdinaryGroup():void
    {
        $this->responses[]=self::iqResult([self::group()]);$this->expectException(\UnexpectedValueException::class);$this->service()->queryCommunityInviteInfo('CODE');
    }
    public static function mexOperations():iterable
    {
        yield ['fetchSubGroups',['1@g.us'],'9935467776504344',['group_id'=>'1@g.us','query_context'=>'INTERACTIVE']];
        yield ['fetchSubgroupSuggestions',['1@g.us','2@g.us'],'23972005349071865',['group_id'=>'1@g.us','query_context'=>'INTERACTIVE','sub_group_hint_id'=>'2@g.us']];
        yield ['isInternalGroup',['1@g.us'],'34119218944390847',['id'=>'1@g.us']];
        yield ['submitGroupSuspensionAppeal',['1-2@g.us','Revisar','{"client":"PHP"}'],'25946115325088226',['input'=>['group_jid'=>'1-2','debug_info'=>'{"client":"PHP"}','appeal_reason'=>'Revisar']]];
    }
    #[DataProvider('mexOperations')]
    public function testMexRetainsRawServerDataAndCorrectOperationVariables(string $method,array $arguments,string $docId,array $variables):void
    {
        $serverData=['server_field'=>['value'=>null,'flag'=>false,'unknown'=>'preserved']];$this->responses[]=self::iqResult([new BinaryNode('result',[],json_encode(['data'=>$serverData],JSON_THROW_ON_ERROR))]);
        self::assertSame($serverData,$this->service()->$method(...$arguments));$query=$this->last()->child('query');self::assertSame($docId,$query->attrs['query_id']);self::assertSame($variables,json_decode($query->content,true,512,JSON_THROW_ON_ERROR)['variables']);self::assertSame('w:mex',$this->last()->attrs['xmlns']);
    }
    public function testMexNullDoesNotTurnIntoFakeEmptyResults():void
    {
        $this->responses[]=self::iqResult([new BinaryNode('result',[],'{"data":null}')]);self::assertNull($this->service()->fetchSubGroups('1@g.us'));
    }
    public function testOwnershipTransferUsesRealOwnerAndPropagatesGraphQlAclError():void
    {
        $this->responses[]=self::iqResult([new BinaryNode('result',[],'{"errors":[{"message":"admin only","extensions":{"error_code":403}}],"data":null}')]);
        try { $this->service()->transferCommunityOwnership('1@g.us','+55 (11) 90000-0001');self::fail('Expected MEX refusal'); }
        catch(ServiceException $error) { self::assertSame('403',$error->serverCode);self::assertSame('mex.TransferCommunityOwnership',$error->operation); }
        $wire=json_decode($this->last()->child('query')->content,true,512,JSON_THROW_ON_ERROR);self::assertSame(['input'=>['group_id'=>'1@g.us','role_updates'=>[['new_role'=>'SUPERADMIN_MEMBER','user_jid'=>'5511900000001@s.whatsapp.net']]]],$wire['variables']);
    }
    public function testPictureReadsWritesAndRemovalUseGroupTarget():void
    {
        $service=$this->service();$this->responses[]=self::iqResult([new BinaryNode('picture',['id'=>'7','url'=>'https://example.invalid/p','type'=>'image'])]);self::assertSame('https://example.invalid/p',$service->getProfilePicture('1@g.us')['url']);self::assertSame('1@g.us',$this->last()->attrs['target']);
        $this->responses[]=self::iqResult([new BinaryNode('picture',['id'=>'8'])]);self::assertSame('8',$service->setProfilePicture('1@g.us',self::jpeg()));self::assertSame(self::jpeg(),$this->last()->child('picture')->content);self::assertSame('1@g.us',$this->last()->attrs['target']);
        $service->removeProfilePicture('1@g.us');self::assertNull($this->last()->content);self::assertSame(['type'=>'set','to'=>'s.whatsapp.net','xmlns'=>'w:profile:picture','target'=>'1@g.us'],$this->last()->attrs);
    }
    public static function invalidInputs():iterable
    {
        yield 'invalid group'=>['setSubject',['8@lid','Novo']];yield 'device group'=>['setSubject',['1:2@g.us','Novo']];yield 'alpha group'=>['queryCommunityMetadata',['unknown@g.us']];
        yield 'empty subject'=>['createCommunity',[' ']];yield 'long subject'=>['createCommunity',[str_repeat('á',101)]];yield 'bad unicode'=>['setSubject',['1@g.us',"\xff"]];
        yield 'bad description before read'=>['setDescription',['1@g.us',str_repeat('a',2049)]];yield 'bad previous ID'=>['setDescription',['1@g.us','Nova','']];
        yield 'invalid option'=>['createCommunity',['MVO',['parent'=>false]]];yield 'override parent'=>['createSubGroup',['1@g.us','Novo',[],['linkedParentJid'=>'2@g.us']]];
        yield 'bad approval mode'=>['createCommunity',['MVO',['membershipApprovalMode'=>'everyone']]];yield 'flag wrong type'=>['createCommunity',['MVO',['createGeneralChat'=>'true']]];yield 'description wrong type'=>['createCommunity',['MVO',['description'=>[]]]];
        yield 'non-list members'=>['addParticipants',['1@g.us',['user'=>'9@lid']]];yield 'empty members'=>['removeParticipants',['1@g.us',[]]];yield 'mixed member types'=>['addParticipants',['1@g.us',['9@lid',9]]];yield 'group member'=>['promoteParticipants',['1@g.us',['2@g.us']]];yield 'device member'=>['demoteParticipants',['1@g.us',['9:1@lid']]];yield 'username member'=>['addParticipants',['1@g.us',['cliente.exemplo']]];
        yield 'self link'=>['linkSubGroups',['1@g.us',['1@g.us']]];yield 'duplicate link'=>['linkSubGroups',['1@g.us',['2@g.us',['jid'=>'2@g.us','hidden'=>true]]]];yield 'empty link'=>['linkSubGroups',['1@g.us',[]]];yield 'null hidden flag'=>['linkSubGroups',['1@g.us',[['jid'=>'2@g.us','hidden'=>null]]]];yield 'extra linked fields'=>['linkSubGroups',['1@g.us',[['jid'=>'2@g.us','admin'=>true]]]];yield 'invalid hint'=>['fetchSubgroupSuggestions',['1@g.us','8@lid']];
        yield 'self linked join'=>['joinLinkedGroup',['1@g.us','1@g.us']];yield 'bad join type'=>['joinLinkedGroup',['1@g.us','2@g.us',"bad\0value"]];
        yield 'bad add mode'=>['setMemberAddMode',['1@g.us','all']];yield 'bad link mode'=>['setMemberLinkMode',['1@g.us','all']];yield 'bad history mode'=>['setMemberShareGroupHistoryMode',['1@g.us','all']];yield 'bad setting'=>['setSetting',['1@g.us','invented',true]];yield 'negative seconds'=>['setEphemeralDuration',['1@g.us',-1]];yield 'negative trigger'=>['setEphemeralDuration',['1@g.us',10,-1]];
        yield 'bad invite URL'=>['joinCommunityViaInvite',['https://example.com/CODE']];yield 'query string invite'=>['queryCommunityInviteInfo',['https://chat.whatsapp.com/CODE?key=bad']];
        yield 'bad photo type'=>['getProfilePicture',['1@g.us','thumbnail']];yield 'bad JPEG'=>['setProfilePicture',['1@g.us',"\xff\xd8broken\xff\xd9"]];yield 'empty JPEG'=>['setProfilePicture',['1@g.us','']];
        yield 'bad owner'=>['transferCommunityOwnership',['1@g.us','2@g.us']];yield 'invalid appeal JSON'=>['submitGroupSuspensionAppeal',['1@g.us','Motivo','not-json']];yield 'non-object appeal JSON'=>['submitGroupSuspensionAppeal',['1@g.us','Motivo','[]']];yield 'empty appeal reason'=>['submitGroupSuspensionAppeal',['1@g.us','']];
    }
    #[DataProvider('invalidInputs')]
    public function testInvalidInputFailsBeforeAnyIo(string $method,array $arguments):void
    {
        try { $this->service()->$method(...$arguments);self::fail('Expected invalid input rejection'); }
        catch(\InvalidArgumentException $error) { self::assertNotSame('',$error->getMessage());self::assertSame([],$this->queries); }
    }
    public function testCommunityGroupEventsKeepSubgroupKindsAndMembershipRequests():void
    {
        $node=new BinaryNode('notification',['type'=>'w:gp2','from'=>'1@g.us','participant'=>'9@lid'],[
            new BinaryNode('link',['link_type'=>'sub_group'],[new BinaryNode('group',['jid'=>'2@g.us','subject'=>'Avisos'],[new BinaryNode('default_sub_group'),new BinaryNode('hidden_group')])]),
            new BinaryNode('membership_approval_requests',[],[new BinaryNode('membership_approval_request',['jid'=>'10@lid','request_time'=>'123'])]),
            new BinaryNode('membership_requests_action',[],[new BinaryNode('approve',[],[new BinaryNode('participant',['jid'=>'10@lid'])])]),new BinaryNode('delete_parent')]);
        $events=IncomingEvents::parse($node);self::assertSame(['group','group','group','group'],array_column($events,'event'));self::assertSame('2@g.us',$events[0]['data']['linkedGroups'][0]['jid']);self::assertTrue($events[0]['data']['linkedGroups'][0]['defaultSubgroup']);self::assertTrue($events[0]['data']['linkedGroups'][0]['hiddenSubgroup']);self::assertSame('10@lid',$events[1]['data']['requests'][0]['jid']);self::assertSame('approve',$events[2]['data']['requests'][0]['action']);self::assertFalse($events[3]['data']['isParentGroup']);
    }
    public function testMcpCommunityToolsAreAllowlistedAndDestructiveOperationsMarked():void
    {
        $clients=0;$runtime=new ClientRuntime(static function(string $id)use(&$clients):WaClient {$clients++;return new WaClient($id,sys_get_temp_dir().'/mvo-community-mcp');});
        $definitions=array_column($runtime->tools->definitions(),null,'name');self::assertCount(38,array_filter(array_keys($definitions),static fn(string $name):bool=>str_starts_with($name,'community_')));self::assertSame(0,$clients);
        self::assertTrue($definitions['community_query_all_communities']['annotations']['readOnlyHint']);self::assertTrue($definitions['community_deactivate_community']['annotations']['destructiveHint']);self::assertTrue($definitions['community_transfer_community_ownership']['annotations']['destructiveHint']);self::assertFalse($definitions['community_create_community']['annotations']['readOnlyHint']);self::assertArrayNotHasKey('community_transport',$definitions);
        self::assertSame(['community','subject'],$definitions['community_create_sub_group']['inputSchema']['required']);$runtime->close();
    }
}
