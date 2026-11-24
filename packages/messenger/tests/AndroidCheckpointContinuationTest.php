<?php
declare(strict_types=1);

namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\{AndroidLoginProfile,AndroidLoginService,CookieJar,SessionStore,WebLoginService};
use MVO\Messenger\{MessengerClient,MessengerException};
use MVO\Messenger\Transport\{AndroidLoginTransport,HttpClient,HttpResponse,WebLoginResponse,WebLoginTransport};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

/** Explicit legacy HTTP continuation opt-in; default native-only is tested separately.
 * Every HTTP peer is an inert fixture.
 * @phpstan-type CheckpointWebStep array{method:string,url:string,response:WebLoginResponse,cookies?:list<array<string,mixed>>,after?:callable():void}|array{method:string,url:string,error:MessengerException,cookies?:list<array<string,mixed>>,after?:callable():void}
 */
final class AndroidCheckpointContinuationTest extends TestCase
{
    private const UID='100000000000123';
    private const URL='https://www.facebook.com/two_step_verification/?flow=two_factor_login&encrypted_context=SYNTHETIC_CHECKPOINT_CONTEXT&next=%2Fmessages%2F';
    private const GRAPH='https://www.facebook.com/api/graphql/';
    private string $directory;
    private ?MessengerClient $client=null;
    private ?AndroidLoginService $service=null;
    private ?SessionStore $store=null;
    private static string $publicKey;

    public static function setUpBeforeClass():void
    {
        $key=openssl_pkey_new(['private_key_type'=>OPENSSL_KEYTYPE_RSA,'private_key_bits'=>2048]);self::assertNotFalse($key);
        $details=openssl_pkey_get_details($key);self::assertIsArray($details);self::$publicKey=$details['key'];
    }
    protected function setUp():void{$this->directory=sys_get_temp_dir().'/mvo-native-checkpoint-'.bin2hex(random_bytes(8));mkdir($this->directory,0700);}
    protected function tearDown():void
    {
        $this->client?->stop();$this->client=null;$this->service?->cancel();$this->service=null;$this->store?->release();$this->store=null;gc_collect_cycles();
        $resolved=realpath($this->directory);$temporary=realpath(sys_get_temp_dir());self::assertIsString($resolved);self::assertIsString($temporary);
        self::assertStringStartsWith($temporary.DIRECTORY_SEPARATOR,$resolved);self::assertMatchesRegularExpression('/^mvo-native-checkpoint-[a-f0-9]{16}$/D',basename($resolved));
        $walk=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($resolved,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
        foreach($walk as$file)$file->isDir()&&!$file->isLink()?rmdir($file->getPathname()):unlink($file->getPathname());rmdir($resolved);
    }

    #[DataProvider('codeMethods')]
    public function testOfferedSmsAndEmailNeedExplicitDeliveryThenTwoIndependentIdentityChecks(string $type,string $native,string $contact):void
    {
        [$service,$web,$identity,$transport,$delegate]=$this->pending([
            ...self::pickerSteps(),self::graph(['xfb_two_step_verification_send_notification'=>['is_success'=>true]]),
            self::graph(['xfb_two_factor_login_validate_code'=>['is_code_valid'=>true]],self::cookies()),self::webIdentity(),
        ],[self::identity()]);
        self::assertCount(0,$web->requests,'Native405 must not navigate or send a notification automatically');
        self::assertTrue($service->challenge()['can_refresh']);
        $state=$service->refreshMethods();$deadline=$state['expires_at'];
        self::assertSame(['totp','sms','email','app_approval'],array_column($state['methods'],'type'));
        self::assertSame(['GET','POST'],array_column($web->requests,'method'));
        self::assertSame(self::URL,$web->requests[0]['url'],'Do not append flow_id, callbacks or other guessed parameters');
        self::assertSame([],$identity->requests);self::assertNull($this->store->loadCookies());
        $chosen=self::method($state,$type);$selected=$service->requestMethod($chosen['id']);
        self::assertSame($chosen['id'],$selected['selected_method']);self::assertTrue($selected['requires_code']);self::assertFalse($selected['can_check']);
        $send=json_decode($web->requests[2]['form']['variables'],true,flags:JSON_THROW_ON_ERROR);
        self::assertSame($native,$send['challenge']);self::assertSame($contact,$send['maskedContactPoint']);
        self::reason(fn()=>$service->requestMethod($chosen['id']),'login_method_cooldown');self::assertCount(3,$web->requests);
        self::assertSame($deadline,$service->challenge()['expires_at']);
        $jar=$service->confirm('00123456');self::assertSame(self::UID,$jar->accountId());
        $code=json_decode($web->requests[3]['form']['variables'],true,flags:JSON_THROW_ON_ERROR);
        self::assertSame('00123456',$code['code']['sensitive_string_value']);self::assertSame($native,$code['method']);
        self::assertCount(5,$web->requests);self::assertCount(1,$identity->requests);self::assertSame(2,$transport->requests);
        self::assertNull($service->challenge());self::assertNull($delegate->challenge());self::assertSame([],$web->cookies());
        self::assertNull($this->store->loadCookies(),'Only the owning client may persist a returned CookieJar');
        self::assertNull($this->store->get('account_id'));self::assertTrue($this->store->ownsLock());
        $this->assertNoPasswordReplay($web);
    }
    /** @return iterable<string,array{string,string,string}> */
    public static function codeMethods():iterable
    {
        yield 'SMS'=>['sms','SMS','***1234'];yield 'email'=>['email','EMAIL','o***@example.test'];
    }

    public function testAppApprovalIsSelectedExplicitlyAndPollingNeverMeansAuthenticationByItself():void
    {
        [$service,$web,$identity,$transport,$delegate]=$this->pending([
            ...self::pickerSteps(),self::graph(['xfb_two_step_verification_complete_if_approval_state_allowed'=>['state'=>'PENDING']]),
            self::graph(['xfb_two_step_verification_complete_if_approval_state_allowed'=>['state'=>'ALLOWED']],self::cookies()),self::webIdentity(),
        ],[self::identity()]);
        $state=$service->refreshMethods();$service->requestMethod(self::method($state,'app_approval')['id']);
        self::assertCount(2,$web->requests,'Selecting approval must not invent a notification mutation');
        self::assertTrue($service->challenge()['can_check']);self::assertFalse($service->challenge()['requires_code']);
        self::reason(fn()=>$service->confirm('001234'),'login_challenge_unavailable');
        $pending=$service->checkApproval();self::assertNull($pending);self::assertCount(3,$web->requests);self::assertSame([],$identity->requests);
        self::assertNull($this->store->loadCookies());self::reason(fn()=>$service->checkApproval(),'login_approval_cooldown');
        self::assertCount(3,$web->requests);self::expirePoll($delegate);
        $approved=$service->checkApproval();self::assertInstanceOf(CookieJar::class,$approved);self::assertSame(self::UID,$approved->accountId());self::assertCount(5,$web->requests);
        self::assertCount(1,$identity->requests);self::assertSame(2,$transport->requests);self::assertNull($service->challenge());
        $this->assertNoPasswordReplay($web);
    }

    #[DataProvider('completedPaths')]
    public function testKnownUidMismatchCannotReachTheNativeWrappersIdentityTransport(string $path):void
    {
        $wrong='999000000000111';
        $steps=$path==='get'?[['method'=>'GET','url'=>self::URL,'response'=>new WebLoginResponse(302,'',['location'=>['/messages/']]),'cookies'=>self::cookies($wrong)],self::webIdentity($wrong),self::webIdentity($wrong)]:[
            ...self::pickerSteps(),self::graph(['xfb_two_step_verification_complete_if_approval_state_allowed'=>['state'=>'ALLOWED']],self::cookies($wrong)),self::webIdentity($wrong),
        ];
        [$service,$web,$identity,$transport]=$this->pending($steps,[]);
        if($path==='get')self::reason(fn()=>$service->refreshMethods(),'login_identity_mismatch');
        else{$state=$service->refreshMethods();$service->requestMethod(self::method($state,'app_approval')['id']);self::reason(fn()=>$service->checkApproval(),'login_identity_mismatch');}
        self::assertSame([],$identity->requests,'A known UID mismatch must fail before the outer independent identity GET');
        self::assertNull($this->store->get('account_id'));self::assertNull($this->store->loadCookies());self::assertNull($service->takeCompletedLogin());
        self::assertSame(2,$transport->requests);$this->assertNoPasswordReplay($web);
    }
    /** @return iterable<string,array{string}> */
    public static function completedPaths():iterable{yield 'approved checkpoint GET'=>['get'];yield 'app approval'=>['approval'];}

    public function testUnlistedIdsAndForeignOwnerCannotCauseWebOperations():void
    {
        [$service,$web,$identity]=$this->pending(self::pickerSteps(),[]);$state=$service->refreshMethods();
        self::reason(fn()=>$service->requestMethod('sms'),'login_method_unavailable');self::assertCount(2,$web->requests);
        self::reason(fn()=>$service->refreshMethods(),'login_method_cooldown');self::assertCount(2,$web->requests);
        $this->store->release();
        foreach([fn()=>$service->refreshMethods(),fn()=>$service->requestMethod(self::method($state,'sms')['id']),fn()=>$service->checkApproval(),fn()=>$service->confirm('001234'),fn()=>$service->takeCompletedLogin()]as$operation){
            try{$operation();self::fail('An unlocked continuation cannot operate');}catch(\LogicException){}
        }
        self::assertCount(2,$web->requests);self::assertSame([],$identity->requests);
    }

    public function testUncertainDeliveryCannotBeReplayedByRefreshingOrChoosingAnotherMethod():void
    {
        [$service,$web,$identity,$native,$delegate]=$this->pending([
            ...self::pickerSteps(),['method'=>'POST','url'=>self::GRAPH,'error'=>new MessengerException('login_transport_failed','Synthetic timeout')],self::graph(self::methods()),
        ],[]);
        $state=$service->refreshMethods();self::reason(fn()=>$service->requestMethod(self::method($state,'sms')['id']),'login_method_outcome_uncertain');
        self::assertTrue($service->challenge()['outcome_uncertain']);self::assertFalse($service->challenge()['ready']);
        (new \ReflectionProperty($service,'checkpointRefreshAt'))->setValue($service,0);(new \ReflectionProperty($delegate,'methodRefreshAt'))->setValue($delegate,0);
        $state=$service->refreshMethods();self::reason(fn()=>$service->requestMethod(self::method($state,'email')['id']),'login_method_outcome_uncertain');
        self::reason(fn()=>$service->confirm('001234'),'login_method_outcome_uncertain');
        self::assertCount(4,$web->requests);self::assertSame([],$identity->requests);self::assertSame(2,$native->requests);
    }

    public function testUncertainCodeHasNoNativeOrWebReplay():void
    {
        [$service,$web,$identity,$native]=$this->pending([...self::pickerSteps(),['method'=>'POST','url'=>self::GRAPH,'error'=>new MessengerException('login_transport_failed','Synthetic code timeout')]],[]);
        $service->refreshMethods();self::reason(fn()=>$service->confirm('001234'),'login_two_factor_outcome_uncertain');
        self::assertTrue($service->challenge()['outcome_uncertain']);self::assertFalse($service->challenge()['ready']);
        self::reason(fn()=>$service->confirm('001234'),'login_challenge_unavailable');
        self::assertCount(3,$web->requests);self::assertSame([],$identity->requests);self::assertSame(2,$native->requests);
    }

    #[DataProvider('unsupportedDocuments')]
    public function testCaptchaGoogleAndUnknownDocumentsDoNotSubmitCodesOrCredentials(WebLoginResponse $response,?string $expectedReason):void
    {
        [$service,$web,$identity,$native]=$this->pending([['method'=>'GET','url'=>self::URL,'response'=>$response]],[]);
        if($expectedReason!==null)self::reason(fn()=>$service->refreshMethods(),$expectedReason);
        else{$state=$service->refreshMethods();self::assertSame('captcha',$state['type']);self::assertFalse($state['requires_code']);self::assertSame([],$state['methods']);}
        self::assertSame(['GET'],array_column($web->requests,'method'));self::assertSame([],$identity->requests);self::assertSame(2,$native->requests);
        self::assertNull($this->store->loadCookies());self::assertNotNull($service->challenge());
        $this->assertNoPasswordReplay($web);
    }
    /** @return iterable<string,array{WebLoginResponse,?string}> */
    public static function unsupportedDocuments():iterable
    {
        yield 'CAPTCHA'=>[new WebLoginResponse(200,'<input name="captcha_response" type="text">'),null];
        yield 'Google redirect'=>[new WebLoginResponse(302,'',['location'=>['https://accounts.google.com/v3/signin/identifier']]),'login_unsafe_redirect'];
        yield 'unknown checkpoint'=>[new WebLoginResponse(200,'<html>No recognized controls or identity</html>'),'login_method_unavailable'];
    }

    public function testDelegatedCaptchaRetainsTheUsableHumanBrowserFallback():void
    {
        [$service,$web,$identity,$native]=$this->pending([['method'=>'GET','url'=>self::URL,'response'=>new WebLoginResponse(200,'<input name="captcha_response">')]],[]);
        self::assertTrue($service->challenge()['browser_continuation_available']);$state=$service->refreshMethods();
        self::assertSame('captcha',$state['type']);self::assertFalse($state['ready']);self::assertFalse($state['requires_code']);
        self::assertTrue($state['browser_continuation_available'],'A real manual continuation remains available after HTTP adopts a CAPTCHA document');
        $handoff=$service->exportPrivateChallenge();self::assertSame(self::URL,$handoff['url']);self::assertSame($state['expires_at'],$handoff['expires_at']);
        self::assertSame([],$handoff['cookies']);self::assertCount(1,$web->requests);self::assertSame([],$identity->requests);self::assertSame(2,$native->requests);
    }

    public function testExistingHtmlCodeFormDoesNotRequireAnUnavailableMethodPicker():void
    {
        $form='<form method="post" action="/checkpoint/"><input type="hidden" name="nh" value="SYNTHETIC_NH"><input name="approvals_code" maxlength="6"></form>';
        [$service,$web,$identity,$native]=$this->pending([['method'=>'GET','url'=>self::URL,'response'=>new WebLoginResponse(200,$form)]],[]);
        $state=$service->refreshMethods();
        self::assertSame('two_factor',$state['type']);self::assertTrue($state['ready']);self::assertTrue($state['requires_code']);self::assertSame([],$state['methods']);
        self::assertCount(1,$web->requests);self::assertSame([],$identity->requests);self::assertSame(2,$native->requests);
    }

    public function testExpiryDuringCodeSubmissionStopsBeforeTheNextIdentityRequest():void
    {
        $deadline=0;
        $code=self::graph(['xfb_two_factor_login_validate_code'=>['is_code_valid'=>true]],self::cookies());
        $code['after']=static function()use(&$deadline):void{self::waitUntil($deadline);};
        [$service,$web,$identity,$native,$delegate]=$this->pending([...self::pickerSteps(),$code,self::webIdentity()],[]);
        $service->refreshMethods();$deadline=time()+1;
        (new \ReflectionProperty($service,'expiresAt'))->setValue($service,$deadline);
        (new \ReflectionProperty($delegate,'challengeExpiresAt'))->setValue($delegate,$deadline);
        self::reason(fn()=>$service->confirm('001234'),'login_challenge_expired');
        self::assertCount(3,$web->requests,'An expired checkpoint must not issue the following HTTPS identity request');
        self::assertSame([],$identity->requests);self::assertSame(2,$native->requests);self::assertNull($this->store->loadCookies());
    }

    public function testCodeRedirectToCaptchaPreservesTheOriginalDelegateDeadline():void
    {
        [$service,$web,$identity,$native,$delegate]=$this->pending([...self::pickerSteps(),
            self::graph(['xfb_two_factor_login_validate_code'=>['is_code_valid'=>true,'next'=>'/captcha/']]),
            ['method'=>'GET','url'=>'https://www.facebook.com/captcha/','response'=>new WebLoginResponse(200,'<input name="captcha_response">')],
        ],[]);
        $service->refreshMethods();$deadline=time()+30;
        (new \ReflectionProperty($service,'expiresAt'))->setValue($service,$deadline);
        (new \ReflectionProperty($delegate,'challengeExpiresAt'))->setValue($delegate,$deadline);
        self::reason(fn()=>$service->confirm('001234'),'login_captcha_required');
        self::assertSame($deadline,$service->challenge()['expires_at']);self::assertSame($deadline,$delegate->challenge()['expires_at']);
        self::assertFalse($service->challenge()['ready']);self::assertSame([],$identity->requests);self::assertSame(2,$native->requests);
    }

    public function testOuterIdentityRedirectAfterExpiryCannotIssueASecondRequest():void
    {
        $deadline=0;
        [$service,$web,$identity,$native,$delegate]=$this->pending([
            ...self::pickerSteps(),self::graph(['xfb_two_factor_login_validate_code'=>['is_code_valid'=>true]],self::cookies()),self::webIdentity(),
        ],[
            ['response'=>new HttpResponse(302,'',['location'=>['/messages/after-approval/']]),'after'=>static function()use(&$deadline):void{self::waitUntil($deadline);}],self::identity(),
        ]);
        $service->refreshMethods();$deadline=time()+1;
        (new \ReflectionProperty($service,'expiresAt'))->setValue($service,$deadline);
        (new \ReflectionProperty($delegate,'challengeExpiresAt'))->setValue($delegate,$deadline);
        self::reason(fn()=>$service->confirm('001234'),'login_challenge_expired');
        self::assertCount(1,$identity->requests,'An expired original checkpoint cannot follow another authenticated identity redirect');
        self::assertCount(4,$web->requests);self::assertSame(2,$native->requests);self::assertNull($this->store->loadCookies());self::assertNull($this->store->get('account_id'));
    }

    public function testOriginalDeadlineIsNotExtendedAndExpiryDestroysTheDelegateWithoutIo():void
    {
        [$service,$web,$identity,$native,$delegate]=$this->pending(self::pickerSteps(),[]);$deadline=time()+35;
        (new \ReflectionProperty($service,'expiresAt'))->setValue($service,$deadline);$state=$service->refreshMethods();
        self::assertSame($deadline,$state['expires_at']);self::assertSame($deadline,$delegate->challenge()['expires_at']);
        self::assertSame($deadline,$service->exportPrivateChallenge()['expires_at']);
        (new \ReflectionProperty($service,'expiresAt'))->setValue($service,time()-1);
        self::assertNull($service->challenge());self::assertNull($delegate->challenge());self::assertSame([],$web->cookies());
        self::reason(fn()=>$service->refreshMethods(),'login_challenge_expired');self::reason(fn()=>$service->confirm('001234'),'login_challenge_expired');
        self::assertCount(2,$web->requests);self::assertSame(2,$native->requests);self::assertSame([],$identity->requests);
    }

    #[DataProvider('initialIdentities')]
    public function testFacadeConsumesAlreadyApprovedGetPrivatelyAndPersistsOnlyTheVerifiedSession(?string $uid):void
    {
        [$service,$web,$identity,$native]=$this->pending([
            ['method'=>'GET','url'=>self::URL,'response'=>new WebLoginResponse(302,'',['location'=>['/messages/']]),'cookies'=>self::cookies()],self::webIdentity(),self::webIdentity(),
        ],[self::identity()],facade:true,data:array_filter(['url'=>self::URL,'flow_id'=>'SYNTHETIC_FLOW_ID','uid'=>$uid],static fn(mixed $value):bool=>$value!==null));
        $result=$this->client->refreshLoginMethods();self::assertSame(['authenticated'=>true],$result);
        self::assertSame('session_imported',$this->client->getState());self::assertNull($this->client->loginChallenge());self::assertFalse($this->client->isConnected());
        self::assertSame(self::UID,$this->store->get('account_id'));self::assertSame(self::UID,$this->store->loadCookies()?->accountId());
        self::assertCount(3,$web->requests);self::assertCount(1,$identity->requests);self::assertSame(2,$native->requests);
        $this->store->acquire();self::assertNull($service->takeCompletedLogin());$this->store->release();
        $public=json_encode([$result,$this->client->accountInfo(),$this->client->events()],JSON_THROW_ON_ERROR).print_r($service,true);
        foreach(['SYNTHETIC_PRIVATE_XS','SYNTHETIC_CHECKPOINT_CONTEXT','SYNTHETIC_FLOW_ID','SYNTHETIC_NATIVE_PASSWORD','owner@example.invalid']as$secret)self::assertStringNotContainsString($secret,$public);
        self::assertSame([],$web->cookies());$this->assertNoPasswordReplay($web);
    }
    /** @return iterable<string,array{?string}> */
    public static function initialIdentities():iterable{yield 'known native UID'=>[self::UID];yield 'first identity confirmed independently'=>[null];}

    public function testFacadeSmsConfirmationSavesThroughTheSameOwnerAndSession():void
    {
        [$service,$web,$identity,$native]=$this->pending([...self::pickerSteps(),self::graph(['xfb_two_step_verification_send_notification'=>['is_success'=>true]]),
            self::graph(['xfb_two_factor_login_validate_code'=>['is_code_valid'=>true]],self::cookies()),self::webIdentity(),
        ],[self::identity()],facade:true);
        $state=$this->client->refreshLoginMethods();$this->client->requestLoginMethod(self::method($state,'sms')['id']);
        self::assertFalse($this->client->accountInfo()['has_saved_session']);$this->client->confirmLogin('00123456');
        self::assertSame(self::UID,$this->store->loadCookies()?->accountId());self::assertSame('session_imported',$this->client->getState());
        self::assertFalse($this->client->isConnected());self::assertNull($service->challenge());self::assertFalse($this->store->ownsLock());
        self::assertCount(5,$web->requests);self::assertCount(1,$identity->requests);self::assertSame(2,$native->requests);
    }

    public function testFailedOuterHttpsCheckDoesNotSaveOrRearmAnAlreadyConsumedCode():void
    {
        [$service,$web,$identity,$native]=$this->pending([...self::pickerSteps(),self::graph(['xfb_two_factor_login_validate_code'=>['is_code_valid'=>true]],self::cookies()),self::webIdentity()],
            [new HttpResponse(200,'<html>No independent identity</html>')]);
        $service->refreshMethods();self::reason(fn()=>$service->confirm('001234'),'login_unconfirmed');
        self::assertNull($this->store->get('account_id'));self::assertNull($this->store->loadCookies());
        self::reason(fn()=>$service->confirm('001234'),'login_checkpoint_required');self::assertFalse($service->challenge()['can_refresh']);
        self::assertCount(4,$web->requests);self::assertCount(1,$identity->requests);self::assertSame(2,$native->requests);
    }

    public function testCancellationClearsThePrivateAdoptedCookieJarAndDisablesFurtherRequests():void
    {
        [$service,$web,$identity,$native,$delegate]=$this->pending(self::pickerSteps(),[]);$service->refreshMethods();
        self::assertNotSame([],$web->cookies());$service->cancel();self::assertNull($service->challenge());self::assertNull($delegate->challenge());
        self::assertSame([],$web->cookies());self::reason(fn()=>$service->refreshMethods(),'login_challenge_expired');
        self::assertNull($service->takeCompletedLogin());self::assertCount(2,$web->requests);self::assertSame(2,$native->requests);self::assertSame([],$identity->requests);
    }

    /** @param array<string,mixed> $data Deliberately incomplete or foreign checkpoint fields. */
    #[DataProvider('unusable405Contexts')]
    public function testBareAndMalformed405CannotStartMethodDiscovery(array $data):void
    {
        [$service,$web,$identity,$native]=$this->pending([],[],data:$data);
        self::reason(fn()=>$service->refreshMethods(),'login_method_unavailable');self::assertSame([],$web->requests);self::assertSame([],$identity->requests);
        self::assertFalse($service->challenge()['can_refresh']);self::assertSame(2,$native->requests);
    }
    /** @return iterable<string,array{array<string,mixed>}> */
    public static function unusable405Contexts():iterable
    {
        yield 'bare'=>[[]];yield 'missing flow'=>[['url'=>self::URL]];
        yield 'foreign'=>[['url'=>'https://example.invalid/checkpoint/','flow_id'=>'SYNTHETIC_FLOW_ID']];
    }

    /** @param list<CheckpointWebStep> $steps
     * @param list<HttpResponse|array{response:HttpResponse,after:callable():void}> $pages
     * @param array<string,mixed>|null $data
     * @return array{AndroidLoginService,CheckpointWebPeer,CheckpointIdentityPeer,CheckpointNativePeer,WebLoginService}
     */
    private function pending(array $steps,array $pages,bool $facade=false,?array $data=null):array
    {
        $identity=new CheckpointIdentityPeer($pages);$this->client=$facade?new MessengerClient('tenant',$this->directory,$identity):null;
        $this->store=$facade?(new \ReflectionProperty($this->client,'store'))->getValue($this->client):new SessionStore($this->directory,'tenant');
        self::assertInstanceOf(SessionStore::class,$this->store);if(!$facade)$this->store->acquire();
        $web=new CheckpointWebPeer($steps);$delegate=new WebLoginService($web,static fn(array $urls):array=>[]);
        $native=new CheckpointNativePeer([new HttpResponse(200,json_encode(['key_id'=>17,'public_key'=>self::$publicKey,'seconds_to_live'=>600],JSON_THROW_ON_ERROR)),
            new HttpResponse(400,json_encode(['error_code'=>405,'error_data'=>$data??['url'=>self::URL,'flow_id'=>'SYNTHETIC_FLOW_ID','uid'=>self::UID]],JSON_THROW_ON_ERROR))]);
        $service=$this->service=new AndroidLoginService($this->store,new CheckpointApplicationProfile(),$native,$identity,$delegate,allowWebCheckpoint:true);
        self::reason($facade?fn()=>$this->client->login('owner@example.invalid','SYNTHETIC_NATIVE_PASSWORD',$service):fn()=>$service->login('owner@example.invalid','SYNTHETIC_NATIVE_PASSWORD'),'login_checkpoint_required');
        return [$service,$web,$identity,$native,$delegate];
    }
    /** @param array<string,mixed> $state Public challenge metadata from the service.
     * @return array<string,mixed> One method actually offered by that metadata.
     */
    private static function method(array $state,string $type):array{foreach($state['methods']as$item)if($item['type']===$type)return $item;throw new \LogicException('Synthetic offered method missing');}
    private static function reason(callable $call,string $reason):void{try{$call();self::fail('Expected '.$reason);}catch(MessengerException$error){self::assertSame($reason,$error->reason);}}
    private static function waitUntil(int $deadline):void{while(time()<$deadline)usleep(1000);}
    private static function expirePoll(WebLoginService $service):void
    {
        (new \ReflectionProperty($service,'approvalCheckAt'))->setValue($service,0);$property=new \ReflectionProperty($service,'methodSpec');$state=$property->getValue($service);$state['next_poll_at']=0.0;$property->setValue($service,$state);
    }
    private function assertNoPasswordReplay(CheckpointWebPeer $web):void
    {
        foreach($web->requests as$request){self::assertArrayNotHasKey('password',$request['form']);self::assertArrayNotHasKey('pass',$request['form']);self::assertArrayNotHasKey('encpass',$request['form']);}
        self::assertStringNotContainsString('SYNTHETIC_NATIVE_PASSWORD',json_encode($web->requests,JSON_THROW_ON_ERROR));
    }
    /** @return list<CheckpointWebStep> */
    private static function pickerSteps():array{return [['method'=>'GET','url'=>self::URL,'response'=>new WebLoginResponse(200,self::page()),'cookies'=>[['name'=>'datr','value'=>'SYNTHETIC_DATR','domain'=>'.facebook.com']]],self::graph(self::methods())];}
    /** @param array<string,mixed> $data Synthetic Graph response payload.
     * @param list<array<string,mixed>>|null $cookies
     * @return array{method:string,url:string,response:WebLoginResponse,cookies?:list<array<string,mixed>>}
     */
    private static function graph(array $data,?array $cookies=null):array{$step=['method'=>'POST','url'=>self::GRAPH,'response'=>new WebLoginResponse(200,json_encode(['data'=>$data],JSON_THROW_ON_ERROR))];if($cookies!==null)$step['cookies']=$cookies;return $step;}
    private static function page():string
    {
        $data=['define'=>[['LSD',[],['token'=>'SYNTHETIC_LSD'],1],['RelayAPIConfigDefaults',[],['graphURI'=>'/api/graphql/'],2],['CurrentUserInitialData',[],['USER_ID'=>'0'],3]],'payload'=>json_encode(['data'=>['xfb_two_factor_login_default_method'=>['method'=>['method'=>'TOTP']]]],JSON_THROW_ON_ERROR)];$operations='';
        foreach(['TwoStepVerificationChallengePickerDialogQuery','useTwoStepVerificationSendCodeMutation','useTwoFactorLoginValidateCodeMutation','TwoStepVerificationUpdateLoginMutation']as$index=>$operation)$operations.='__d("'.$operation.'_facebookRelayOperation",[],(function(t,n,r,o,a,i){a.exports="2712345678901234'.$index.'"}),null);';
        return '<script type="application/json">'.json_encode($data,JSON_THROW_ON_ERROR).'</script><script>'.$operations.'</script>';
    }
    /** @return array<string,mixed> Synthetic picker, cooldown and approval-poll response. */
    private static function methods():array{return ['xfb_two_factor_login_methods'=>['methods'=>[['method'=>'TOTP'],['method'=>'SMS','method_representation'=>'***1234'],['method'=>'EMAIL','method_representation'=>'o***@example.test'],['method'=>'APPROVE_FROM_ANOTHER_DEVICE']]],'xfb_two_step_verification_send_rate_limit_cooldown'=>['rate_limits'=>[['challenge'=>'SMS','cooldown_seconds'=>600]]],'xfb_two_step_verification_afad_polling_interval'=>['polling_interval'=>7]];}
    /** @return list<array{name:string,value:string,domain:string}> */
    private static function cookies(string $uid=self::UID):array{return [['name'=>'c_user','value'=>$uid,'domain'=>'.facebook.com'],['name'=>'xs','value'=>'SYNTHETIC_PRIVATE_XS','domain'=>'.facebook.com']];}
    private static function identity(string $uid=self::UID):HttpResponse{return new HttpResponse(200,'<script type="application/json">'.json_encode(['define'=>[['CurrentUserInitialData',[],['USER_ID'=>$uid],1]]],JSON_THROW_ON_ERROR).'</script>');}
    /** @return array{method:string,url:string,response:WebLoginResponse} */
    private static function webIdentity(string $uid=self::UID):array{return ['method'=>'GET','url'=>'https://www.facebook.com/messages/','response'=>new WebLoginResponse(200,self::identity($uid)->body)];}
}

final class CheckpointApplicationProfile implements AndroidLoginProfile
{
    public function origin():string{return 'https://graph.facebook.com';}public function passwordVersion():int{return 2;}public function keyFetchFlow():string{return 'CONTROLLER_INITIALIZATION';}
    public function authorize(string $method,string $path,#[\SensitiveParameter]array $form):array{return ['form'=>$form,'headers'=>[]];}
}
final class CheckpointNativePeer implements AndroidLoginTransport
{
    public int $requests=0;
    /** @param list<HttpResponse> $responses */public function __construct(private array $responses){}
    public function request(string $method,#[\SensitiveParameter]string $url,#[\SensitiveParameter]array $form=[],#[\SensitiveParameter]array $headers=[]):HttpResponse{++$this->requests;return array_shift($this->responses)??throw new \LogicException('Unexpected native credential replay');}
}
final class CheckpointIdentityPeer implements HttpClient
{
    /** @var list<string> */public array $requests=[];
    /** @param list<HttpResponse|array{response:HttpResponse,after:callable():void}> $responses */public function __construct(private array $responses){}
    public function request(string $method,string $url,CookieJar $cookies,array $form=[]):HttpResponse
    {
        $this->requests[]=$url;$response=array_shift($this->responses)??throw new \LogicException('Unexpected independent identity request');
        if(is_array($response)){($response['after'])();return $response['response'];}return $response;
    }
}
/** @phpstan-import-type CheckpointWebStep from AndroidCheckpointContinuationTest */
final class CheckpointWebPeer implements WebLoginTransport
{
    /** @var list<array{method:string,url:string,form:array<string,string>}> */public array $requests=[];
    /** @var list<array<string,mixed>> */private array $jar=[];
    /** @param list<CheckpointWebStep> $steps */public function __construct(private array $steps){}
    public function request(string $method,#[\SensitiveParameter]string $url,#[\SensitiveParameter]array $form=[]):WebLoginResponse
    {
        $this->requests[]=compact('method','url','form');$step=array_shift($this->steps)??throw new \LogicException('Unexpected Web continuation request');
        TestCase::assertSame($step['method'],$method);TestCase::assertSame($step['url'],$url);
        if(isset($step['cookies']))$this->jar=$step['cookies'];if(isset($step['after']))($step['after'])();if(isset($step['error']))throw $step['error'];return $step['response'];
    }
    public function cookies():array{return $this->jar;}public function clear():void{$this->jar=[];}
}
