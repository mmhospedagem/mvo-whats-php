<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Crypto\Crypto;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Service\{Services,ServiceTransport,ServiceException,BotService};

final class ServiceProtocolTest extends TestCase
{
    private array $queries=[];
    private array $sent=[];
    private array $responses=[];
    private function services(array $credentials=[]): Services
    {
        return new Services(function(BinaryNode $n,float $timeout):BinaryNode {
            $this->queries[]=$n;
            if($this->responses!==[]) return array_shift($this->responses);
            if($n->tag==='message') return new BinaryNode('ack',['class'=>'message','id'=>$n->attrs['id']]);
            return new BinaryNode('iq',['type'=>'result']);
        },function(BinaryNode $n):void { $this->sent[]=$n; },static fn():array=>$credentials);
    }
    private static function iqResult(array $nodes=[]): BinaryNode { return new BinaryNode('iq',['type'=>'result'],$nodes); }
    private static function shape(BinaryNode $n): array { return ['tag'=>$n->tag,'attrs'=>$n->attrs,'content'=>is_array($n->content) ? array_map(self::shape(...),$n->content) : $n->content]; }
    private function last(): BinaryNode { return $this->queries[array_key_last($this->queries)]; }
    public function testPresenceAndChatstateUseActualWire(): void
    {
        $s=$this->services(['meDisplayName'=>'MVO']);$s->presence()->send('available');$s->presence()->sendChatstate('123@lid','composing','audio');$s->presence()->sendChatstate('123@lid','paused');$s->presence()->subscribe('123@lid','Nome','test',new BinaryNode('tctoken',[],"\0\1"));
        self::assertSame(['type'=>'available','name'=>'MVO'],$this->sent[0]->attrs);
        self::assertSame(['tag'=>'chatstate','attrs'=>['to'=>'123@lid'],'content'=>[['tag'=>'composing','attrs'=>['media'=>'audio'],'content'=>null]]],self::shape($this->sent[1]));
        self::assertSame('paused',$this->sent[2]->content[0]->tag);self::assertSame("\0\1",$this->sent[3]->child('tctoken')->content);self::assertCount(0,$this->queries);
    }
    public function testGroupCreationAndRichMetadata(): void
    {
        $this->responses[] = self::iqResult([new BinaryNode('group',['id'=>'123-456','subject'=>'Equipe','creator'=>'8@lid','creator_pn'=>'55@s.whatsapp.net','addressing_mode'=>'lid','size'=>'2','creation'=>'100'],[new BinaryNode('participant',['jid'=>'8@lid','type'=>'superadmin','phone_number'=>'55@s.whatsapp.net']),new BinaryNode('description',['id'=>'desc'],[new BinaryNode('body',[],'Descrição')]),new BinaryNode('announcement'),new BinaryNode('locked'),new BinaryNode('ephemeral',['expiration'=>'86400','trigger'=>'1'])])]);
        $g=$this->services()->group()->createGroup('Equipe',['9@lid'],['description'=>'Descrição']);
        self::assertSame('123-456@g.us',$g['jid']);self::assertSame('Descrição',$g['desc']);self::assertTrue($g['announce']);self::assertTrue($g['restrict']);self::assertSame(86400,$g['ephemeral']);self::assertTrue($g['participants'][0]['isSuperAdmin']);
        $n=$this->last();self::assertSame(['type'=>'set','to'=>'g.us','xmlns'=>'w:g2'],$n->attrs);self::assertSame(['subject'=>'Equipe'],$n->child('create')->attrs);self::assertSame('9@lid',$n->child('create')->child('participant')->attrs['jid']);self::assertMatchesRegularExpression('/^\d+$/',$n->child('create')->child('description')->attrs['id']);
    }
    public function testCommunityCreationPreservesMembershipAndSubgroupOptions(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('group',['id'=>'1','subject'=>'Comunidade'],[new BinaryNode('parent',['default_membership_approval_mode'=>'request_required'])])]);
        $g=$this->services()->group()->createCommunity('Comunidade',['createGeneralChat'=>true,'allowNonAdminSubGroupCreation'=>true]);self::assertTrue($g['isParentGroup']);self::assertTrue($g['isClosedCommunity']);
        $c=$this->last()->child('create');self::assertSame('request_required',$c->child('parent')->attrs['default_membership_approval_mode']);self::assertNotNull($c->child('create_general_chat'));self::assertNotNull($c->child('allow_non_admin_sub_group_creation'));
    }
    public function testPartialMemberFailuresRemainVisible(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('add',[],[new BinaryNode('participant',['jid'=>'8@lid']),new BinaryNode('participant',['jid'=>'9@lid','error'=>'403']),new BinaryNode('participant',['jid'=>'10@lid'],[new BinaryNode('error',['code'=>'409'])])])]);
        $r=$this->services()->group()->addParticipants('1@g.us',['8@lid','9@lid','10@lid']);self::assertSame([200,403,409],array_column($r,'code'));self::assertSame(['ok','error','error'],array_column($r,'status'));self::assertCount(3,$this->last()->child('add')->content);
    }
    public function testCommunityLinksReturnRejectedSubgroups(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('links',[],[new BinaryNode('link',[],[new BinaryNode('group',['jid'=>'2@g.us']),new BinaryNode('group',['jid'=>'3@g.us'],[new BinaryNode('error',['code'=>'403'])])])])]);
        $r=$this->services()->group()->linkSubGroups('1@g.us',['2@g.us','3@g.us']);self::assertSame(['2@g.us'],$r['linkedJids']);self::assertSame([['jid'=>'3@g.us','error'=>403]],$r['failed']);self::assertSame('sub_group',$this->last()->child('links')->child('link')->attrs['link_type']);
    }
    public static function groupWire(): iterable
    {
        yield 'subject'=>['setSubject',['1@g.us','Novo'],'1@g.us','subject',[], 'Novo'];
        yield 'announcement on'=>['setSetting',['1@g.us','announcement',true],'1@g.us','announcement',[],null];
        yield 'announcement off'=>['setSetting',['1@g.us','announcement',false],'1@g.us','not_announcement',[],null];
        yield 'lock'=>['setSetting',['1@g.us','restrict',true],'1@g.us','locked',[],null];
        yield 'unlock'=>['setSetting',['1@g.us','restrict',false],'1@g.us','unlocked',[],null];
        yield 'ephemeral off'=>['setSetting',['1@g.us','ephemeral',false],'1@g.us','not_ephemeral',[],null];
        yield 'member add'=>['setMemberAddMode',['1@g.us','admin_add'],'1@g.us','member_add_mode',[],'admin_add'];
        yield 'member link'=>['setMemberLinkMode',['1@g.us','all_member_link'],'1@g.us','member_link_mode',[],'all_member_link'];
        yield 'history sharing'=>['setMemberShareGroupHistoryMode',['1@g.us','all_member_share'],'1@g.us','member_share_group_history_mode',[],'all_member_share'];
        yield 'expiration'=>['setEphemeralDuration',['1@g.us',604800,1],'1@g.us','ephemeral',['expiration'=>'604800','trigger'=>'1'],null];
        yield 'deactivate'=>['deactivateCommunity',['1@g.us'],'1@g.us','delete_parent',[],null];
        yield 'linked join'=>['joinLinkedGroup',['1@g.us','2@g.us','linked'],'1@g.us','join_linked_group',['jid'=>'2@g.us','type'=>'linked'],null];
    }
    #[DataProvider('groupWire')]
    public function testGroupBuilderMatchesReference(string $method,array $args,string $to,string $tag,array $attrs,mixed $content): void
    {
        $this->services()->group()->$method(...$args);$n=$this->last();self::assertSame(['type'=>'set','to'=>$to,'xmlns'=>'w:g2'],$n->attrs);self::assertSame($attrs,$n->child($tag)->attrs);self::assertSame($content,$n->child($tag)->content);
    }
    public function testDescriptionClearAndComparePreviousId(): void
    {
        $this->services()->group()->setDescription('1@g.us',null,'old');$d=$this->last()->child('description');self::assertSame('true',$d->attrs['delete']);self::assertSame('old',$d->attrs['prev']);self::assertNull($d->content);
    }
    public function testMembershipActionsAreNested(): void
    {
        $s=$this->services()->group();$s->approveMembershipRequests('1@g.us',['8@lid']);$s->rejectMembershipRequests('1@g.us',['9@lid']);$s->cancelMembershipRequests('1@g.us',['10@lid']);
        self::assertSame('8@lid',$this->queries[0]->child('membership_requests_action')->child('approve')->child('participant')->attrs['jid']);self::assertSame('9@lid',$this->queries[1]->child('membership_requests_action')->child('reject')->child('participant')->attrs['jid']);self::assertSame('10@lid',$this->queries[2]->child('cancel_membership_requests')->child('participant')->attrs['jid']);
    }
    public function testIqRejectionIsNeverSuccess(): void
    {
        $this->responses[]=new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'403','text'=>'not-authorized'])]);
        try { $this->services()->group()->setSubject('1@g.us','Teste');self::fail('Expected refusal'); } catch(ServiceException $e) { self::assertSame('403',$e->serverCode);self::assertSame('group.setSubject',$e->operation);self::assertSame(403,$e->getCode()); }
    }
    public function testPrivacyCategoryParsingSkipsInvalidServerValues(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('privacy',[],[new BinaryNode('category',['name'=>'last','value'=>'contacts']),new BinaryNode('category',['name'=>'readreceipts','value'=>'contacts']),new BinaryNode('category',['name'=>'unknown','value'=>'all'])])]);
        self::assertSame(['lastSeen'=>'contacts'],$this->services()->privacy()->getPrivacySettings());
    }
    public function testPrivacyListRetriesOnlyOnceWithNewDhashAndUsesLid(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('usync',[],[new BinaryNode('list',[],[new BinaryNode('user',['jid'=>'55@s.whatsapp.net'],[new BinaryNode('lid',['val'=>'8@lid'])])])])]);
        $this->responses[]=self::iqResult([new BinaryNode('privacy',[],[new BinaryNode('list',['name'=>'last','dhash'=>'old'])])]);
        $this->responses[]=new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'409'])]);
        $this->responses[]=self::iqResult([new BinaryNode('privacy',[],[new BinaryNode('list',['name'=>'last','dhash'=>'new'])])]);
        $this->responses[]=self::iqResult([new BinaryNode('privacy',[],[new BinaryNode('category',['name'=>'last','dhash'=>'final'])])]);
        self::assertSame('final',$this->services(['meLid'=>'99@lid'])->privacy()->setDisallowedList('lastSeen',['55@s.whatsapp.net']));self::assertCount(5,$this->queries);
        $first=$this->queries[2]->child('privacy');$retry=$this->queries[4]->child('privacy');self::assertSame(['addressing_mode'=>'lid'],$retry->attrs);self::assertSame('old',$first->child('category')->attrs['dhash']);self::assertSame('new',$retry->child('category')->attrs['dhash']);self::assertSame(['action'=>'add','jid'=>'8@lid','pn_jid'=>'55@s.whatsapp.net'],$retry->child('category')->child('user')->attrs);
    }
    public function testBlockUsesMigratedIdentityAndUnblockUsesLidOnly(): void
    {
        $s=$this->services()->privacy();$s->blockUser('8@lid');self::assertSame(['action'=>'block','jid'=>'8@lid','unknown_identifier'=>'true'],$this->last()->child('item')->attrs);$s->unblockUser('8@lid');self::assertSame(['action'=>'unblock','jid'=>'8@lid'],$this->last()->child('item')->attrs);
    }
    public function testProfilePictureWireAndRawJpeg(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('picture',['id'=>'1','url'=>'https://example.invalid/p','direct_path'=>'/p','type'=>'preview'])]);$p=$this->services()->profile();self::assertSame(['url'=>'https://example.invalid/p','directPath'=>'/p','id'=>'1','type'=>'preview'],$p->getProfilePicture('8@lid','preview','old'));self::assertSame('8@lid',$this->last()->attrs['target']);self::assertSame(['type'=>'preview','query'=>'url','id'=>'old'],$this->last()->child('picture')->attrs);
        $p->setProfilePicture("\xff\xd8JPEG\xff\xd9",'1@g.us');self::assertSame("\xff\xd8JPEG\xff\xd9",$this->last()->child('picture')->content);$p->deleteProfilePicture();self::assertNull($this->last()->content);
    }
    public function testProfileUsyncParsesPrivacyAndModernStatus(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('usync',[],[new BinaryNode('list',[],[new BinaryNode('user',['jid'=>'8@lid'],[new BinaryNode('status',['code'=>'401'])])])])]);
        self::assertSame(['status'=>''],$this->services()->profile()->getStatus('8@lid'));$u=$this->last()->child('usync');self::assertSame(['index'=>'0','last'=>'true','mode'=>'query','context'=>'interactive'],array_diff_key($u->attrs,['sid'=>true]));self::assertNotNull($u->child('query')->child('status'));
    }
    public function testUsernameResolveWithSecretKeyAndMetadata(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('usync',[],[new BinaryNode('list',[],[new BinaryNode('user',['jid'=>'8@lid'],[new BinaryNode('contact',['username'=>'someone','type'=>'in']),new BinaryNode('business',['pn_jid'=>'55@s.whatsapp.net'])])])])]);
        $r=$this->services()->profile()->resolveUsername('@someone:1234');self::assertSame(['status'=>'found','jid'=>'8@lid','username'=>'someone','isBusiness'=>true,'pnJid'=>'55@s.whatsapp.net'],$r);self::assertSame(['username'=>'someone','pin'=>'1234'],$this->last()->child('usync')->child('list')->child('user')->child('contact')->attrs);
    }
    public function testMexPersistedOperationAndGraphQlEnvelope(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('result',[],json_encode(['data'=>['xwa2_username_get'=>['username_info'=>['username'=>'someone','state'=>'SET','pin'=>null]]]],JSON_THROW_ON_ERROR))]);
        $s=$this->services();self::assertCount(130,$s->mex()->operations());self::assertContains('NewsletterLabelAiContent',$s->mex()->operations());self::assertSame('someone',$s->profile()->getOwnUsername()['username']);$n=$this->last();self::assertSame('w:mex',$n->attrs['xmlns']);$wire=json_decode($n->child('query')->content,true,512,JSON_THROW_ON_ERROR);self::assertSame($n->child('query')->attrs['query_id'],$wire['queryId']);self::assertSame([], $wire['variables']);
    }
    public function testMexRejectsGraphQlErrorsAndArgo(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('result',[], '{"errors":[{"message":"denied","extensions":{"error_code":403}}],"data":null}')]);try { $this->services()->mex()->run('GetUsername');self::fail('Expected GraphQL rejection'); }catch(ServiceException $e) { self::assertSame('403',$e->serverCode); }
        $this->responses[]=self::iqResult([new BinaryNode('result',['format'=>'argo'],'binary')]);$this->expectException(\UnexpectedValueException::class);$this->services()->mex()->run('GetUsername');
    }
    public function testMexCapturedArgoErrorsArePropagatedWithRealServerCode(): void
    {
        $capture=base64_decode('BK4CVGhlIHNjaGVtYSBzcGVjaWZpZXMgdGhlIGZpZWxkIGlzIG5vbi1udWxsLCBidXQgYSBudWxsIHZhbHVlIHdhcyByZXR1cm5lZCBieSB0aGUgYmFja2VuZGNvZGVudWxsX3ZhbHVlQmFkIFJlcXVlc3RlcnJvcl9jb2RlaXNfcmV0cnlhYmxlc2V2ZXJpdHlDUklUSUNBTAgAAKAGNAEEsAEDAgAEAggIFBYDAgAEBhQMGAAQCBAD',true);
        $this->responses[]=self::iqResult([new BinaryNode('result',['format'=>'argo'],$capture)]);
        try { $this->services()->mex()->dispatch('25094190163544446','16428758503015954638431529919',['use_case'=>'CANONICAL','request_time'=>'17'],'WWWGetCertificates');self::fail('Expected GraphQL error'); }
        catch(ServiceException $e) { self::assertSame('null_value,400',$e->serverCode);self::assertStringContainsString('Bad Request',$e->getMessage());self::assertStringContainsString('CRITICAL',$e->getMessage()); }
    }
    public function testNewsletterUsesPlaintextAndWaitsForAck(): void
    {
        $r=$this->services()->newsletter()->send('123@newsletter','Olá','MSG1');self::assertSame('server_ack',$r['status']);self::assertSame(['to'=>'123@newsletter','id'=>'MSG1','type'=>'text'],$this->last()->attrs);self::assertNull($this->last()->child('enc'));$m=SchemaCodec::decode('Message',$this->last()->child('plaintext')->content);self::assertSame('Olá',$m['extendedTextMessage']['text']);self::assertArrayNotHasKey('messageContextInfo',$m);
    }
    public function testNewsletterEditReactionVotesReceiptAndPaging(): void
    {
        $s=$this->services()->newsletter();$s->editMessage('123@newsletter','MSG1','Atualizado');self::assertSame('3',$this->last()->attrs['edit']);$s->react('123@newsletter',1,'👍');self::assertSame('reaction',$this->sent[0]->attrs['type']);self::assertSame(['code'=>'👍'],$this->sent[0]->child('reaction')->attrs);$s->votePoll('123@newsletter',1,[str_repeat('a',32)]);self::assertSame(str_repeat('a',32),$this->sent[1]->child('votes')->child('vote')->content);$s->sendViewReceipt('123@newsletter',[1,2]);self::assertSame('view',$this->sent[2]->attrs['type']);self::assertCount(2,$this->sent[2]->child('list')->content);$s->fetchMessages('123@newsletter',20,80);self::assertSame(['type'=>'jid','jid'=>'123@newsletter','count'=>'20','before'=>'80'],$this->last()->child('messages')->attrs);
    }
    public function testNewsletterRejectsEncryptedMediaInsteadOfShippingUnusableKey(): void
    {
        $this->expectException(\InvalidArgumentException::class);$this->services()->newsletter()->send('123@newsletter',['type'=>'raw','message'=>['imageMessage'=>['url'=>'https://example.invalid/p','mediaKey'=>str_repeat('a',32)]]]);
    }
    public function testNewsletterAcknowledgementMismatchDoesNotClaimSuccess(): void
    {
        $this->responses[]=new BinaryNode('ack',['id'=>'wrong','class'=>'message']);$this->expectException(ServiceException::class);$this->services()->newsletter()->send('123@newsletter','Hi','MSG1');
    }
    public function testNewsletterMuteAndQueryShapes(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('result',[],'{"data":{"xwa2_newsletter_update_user_setting":{"id":"123@newsletter","state":{"type":"ACTIVE"}}}}')]);$this->services()->newsletter()->mute('123@newsletter',true,true);$j=json_decode($this->last()->child('query')->content,true,512,JSON_THROW_ON_ERROR);self::assertSame(['newsletter_id'=>'123@newsletter','type'=>'MUTE_ADMIN_ACTIVITY','value'=>'ON'],$j['variables']['input']);
    }
    public function testBusinessHoursOmitTimesFor24HoursAndDecodeNameCertificate(): void
    {
        $s=$this->services()->business();$s->editBusinessProfile(['address'=>'Rua','websites'=>[],'businessHours'=>['timezone'=>'America/Sao_Paulo','config'=>[['dayOfWeek'=>'mon','mode'=>'open_24h','openTime'=>540],['dayOfWeek'=>'tue','mode'=>'specific_hours','openTime'=>540,'closeTime'=>1080]]]]);$b=$this->last()->child('business_profile');self::assertSame(['v'=>'3','mutation_type'=>'delta'],$b->attrs);self::assertNull($b->child('website')->content);self::assertSame(['day_of_week'=>'mon','mode'=>'open_24h'],$b->child('business_hours')->content[0]->attrs);self::assertSame('540',$b->child('business_hours')->content[1]->attrs['open_time']);
        $cert=SchemaCodec::encode('VerifiedNameCertificate',['details'=>SchemaCodec::encode('VerifiedNameCertificate.Details',['verifiedName'=>'MVO','issuer'=>'smb:wa','serial'=>123])]);$this->responses[]=self::iqResult([new BinaryNode('verified_name',['verified_level'=>'high'],$cert)]);$r=$s->getVerifiedName('8@lid');self::assertSame('MVO',$r['name']);self::assertTrue($r['isSmb']);self::assertSame('123',$r['serial']);
    }
    public function testEmailFlowWireAndParsing(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('email',['verified'=>'true'],[new BinaryNode('email_address',[],'user@example.com'),new BinaryNode('confirmed',[],'true')])]);$e=$this->services()->email();self::assertSame(['email'=>'user@example.com','verified'=>true,'confirmed'=>true],$e->setEmail('user@example.com','settings'));self::assertSame('urn:xmpp:whatsapp:account',$this->last()->attrs['xmlns']);self::assertSame('settings',$this->last()->child('email')->child('context')->content);$e->requestVerificationCode('pt','BR');self::assertSame('BR',$this->last()->child('verify_email')->child('lc')->content);$e->verifyCode('123456');self::assertSame('123456',$this->last()->child('verify_email')->child('code')->content);$e->confirm();self::assertNotNull($this->last()->child('confirm_email'));
    }
    public function testBotDiscoveryAndAuthenticatedChunkVector(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('bot',[],[new BinaryNode('default',['jid'=>'13135550002@s.whatsapp.net']),new BinaryNode('section',['name'=>'AI'],[new BinaryNode('bot',['jid'=>'13135550002@s.whatsapp.net','persona_id'=>'867051314767696$abc','count'=>'1'])])])]);$bots=$this->services()->bot()->listBots();self::assertSame('867051314767696@bot',$bots[0]['fbidJid']);self::assertTrue($bots[0]['isDefault']);
        $secret=str_repeat("\x41",32);$salt='CHUNK1';$target='8@lid';$author='867051314767696@bot';$iv=str_repeat("\x02",12);
        $base=hash_hkdf('sha256',$secret,32,'Bot Message');$key=hash_hkdf('sha256',$base,32,$salt.$target.$author);$plain=SchemaCodec::encode('Message',['conversation'=>'Resposta']);$cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$salt."\0".$author,16).$tag;
        self::assertSame(['conversation'=>'Resposta'],BotService::decryptChunk($secret,$salt,$target,$author,$iv,$cipher));
        $this->expectException(\UnexpectedValueException::class);BotService::decryptChunk($secret,$salt,'9@lid',$author,$iv,$cipher);
    }
    public static function invalidInputs(): iterable
    {
        yield ['presence','send',['online']];yield ['presence','sendChatstate',['1@newsletter','composing']];yield ['presence','sendChatstate',['1@lid','paused','audio']];yield ['presence','sendChatstate',['1@lid','composing','video']];yield ['presence','subscribe',['status@broadcast']];
        yield ['group','setSubject',['1@lid','bad']];yield ['group','setSetting',['1@g.us','unknown',true]];yield ['group','setMemberAddMode',['1@g.us','anyone']];yield ['group','setEphemeralDuration',['1@g.us',-1]];yield ['group','addParticipants',['1@g.us',[]]];yield ['group','leaveGroup',[[]]];yield ['group','linkSubGroups',['1@g.us',[]]];yield ['group','queryGroupInviteInfo',['https://chat.whatsapp.com/a']];
        yield ['privacy','setPrivacySetting',['readReceipts','contacts']];yield ['privacy','setPrivacySetting',['defenseMode','all']];yield ['privacy','getDisallowedList',['online']];yield ['privacy','setDisallowedList',['lastSeen',[],[]]];yield ['privacy','blockUser',['1@g.us']];
        yield ['profile','getProfilePicture',['1@lid','full']];yield ['profile','setDisappearingMode',[-1]];yield ['profile','resolveUsername',['abc..def']];yield ['profile','resolveUsername',['www.name']];yield ['profile','resolveUsername',['somewhatsapp']];yield ['profile','resolveUsername',['someone.com']];yield ['profile','resolveUsername',['12345']];yield ['profile','setUsernameKey',['123']];
        yield ['newsletter','fetch',['1@lid']];yield ['newsletter','send',['1@newsletter',['type'=>'location','degreesLatitude'=>1,'degreesLongitude'=>2]]];yield ['newsletter','react',['1@newsletter',0,'a']];yield ['newsletter','fetchMessages',['1@newsletter',0]];yield ['newsletter','fetchMessageUpdates',['1@newsletter']];yield ['newsletter','acceptTos',[[]]];
        yield ['business','editBusinessProfile',[['websites'=>['a','b','c']]]];yield ['business','editBusinessProfile',[['latitude'=>100]]];yield ['business','editBusinessProfile',[['businessHours'=>['config'=>[['dayOfWeek'=>'mon','mode'=>'closed']]]]]];
        yield ['email','setEmail',['bad']];yield ['email','verifyCode',['12abcd']];yield ['email','requestVerificationCode',['p','BR']];
    }
    #[DataProvider('invalidInputs')]
    public function testInvalidInputIsRejectedBeforeNetwork(string $service,string $method,array $args): void
    {
        try { $this->services()->$service()->$method(...$args);self::fail('Expected validation failure'); } catch(\InvalidArgumentException $e) { self::assertCount(0,$this->queries);self::assertCount(0,$this->sent); }
    }
}
