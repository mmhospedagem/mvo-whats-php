<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\PageInboxContextLoader;
use MVO\Messenger\Auth\WebSession;
use MVO\Messenger\Transport\HttpResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** All documents and responses are inert synthetic fixtures; no Facebook account or network is used. */
final class PageInboxContextLoaderTest extends TestCase
{
    public function testDirectDocumentUsesTheSameOwnerAndNativePageActor():void
    {
        $session=new InboxContextLoaderSession([new HttpResponse(200,self::html())]);
        $loader=new PageInboxContextLoader($session);$context=$loader->load('30003','opaque:+/= selection');
        self::assertSame('30003',$context->actorId);self::assertSame('30003',$context->pageId);self::assertSame('20002',$context->profileActorId);
        self::assertSame('10001',$context->privateRequestFields()['__user']);self::assertSame('PRIVATE_SYNTHETIC_DOCUMENT_CSRF',$context->privateRequestFields()['fb_dtsg']);
        self::assertSame([['method'=>'GET','url'=>'https://business.facebook.com/latest/inbox/all?asset_id=30003&selected_item_id=opaque%3A%2B%2F%3D%20selection','form'=>[]]],$session->requests);
        self::assertStringNotContainsString('PRIVATE_SYNTHETIC',print_r($loader,true));
    }

    public function testDocumentIsEphemeralRedactedAndProvidesOriginalHtmlOnlyToExplicitReaders():void
    {
        $html=self::html();$session=new InboxContextLoaderSession([new HttpResponse(200,$html)]);
        $document=(new PageInboxContextLoader($session))->document('30003');
        self::assertSame($html,$document->html());self::assertSame('30003',$document->context->pageId);
        self::assertSame('https://business.facebook.com/latest/inbox/all?asset_id=30003',$document->url);
        self::assertStringNotContainsString('PRIVATE_SYNTHETIC_DOCUMENT_CSRF',print_r($document,true));
        try{json_encode($document,JSON_THROW_ON_ERROR);self::fail('A private bootstrap document was JSON serialized');}catch(\LogicException){}
        try{serialize($document);self::fail('A private bootstrap document was PHP serialized');}catch(\LogicException){}
        self::assertCount(1,$session->requests);
    }

    public static function legitimateRedirects():iterable
    {
        yield 'root relative'=>['/latest/inbox/all/?asset_id=30003&business_id=40004'];
        yield 'absolute'=>['https://business.facebook.com/latest/inbox/all/?business_id=40004&asset_id=30003'];
        yield 'path relative'=>['all/?asset_id=30003&business_id=40004'];
        yield 'network relative'=>['//business.facebook.com/latest/inbox/all/?asset_id=30003&business_id=40004'];
        yield 'query relative'=>['?asset_id=30003&business_id=40004'];
        yield 'route confirms missing URL asset'=>['/latest/inbox/all/?business_id=40004'];
    }

    #[DataProvider('legitimateRedirects')]
    public function testOneLegitimateRedirectIsFollowedAndStillRequiresNativeScopeProof(string $location):void
    {
        $session=new InboxContextLoaderSession([new HttpResponse(302,'PRIVATE_REDIRECT_HTML',['location'=>[$location]]),new HttpResponse(200,self::html())]);
        $context=(new PageInboxContextLoader($session))->load('30003');
        self::assertSame('30003',$context->actorId);self::assertCount(2,$session->requests);
        foreach($session->requests as $request){self::assertSame('GET',$request['method']);self::assertSame('business.facebook.com',parse_url($request['url'],PHP_URL_HOST));self::assertSame([],$request['form']);}
    }

    public static function unsafeRedirects():iterable
    {
        foreach([
            'https://example.invalid/latest/inbox/all?asset_id=30003',
            'https://business.facebook.com.example.invalid/latest/inbox/all?asset_id=30003',
            'http://business.facebook.com/latest/inbox/all?asset_id=30003',
            'https://user:PRIVATE_PASSWORD@business.facebook.com/latest/inbox/all?asset_id=30003',
            'https://business.facebook.com:443/latest/inbox/all?asset_id=30003',
            '/login/?next=%2Flatest%2Finbox%2Fall',
            '/checkpoint/?next=%2Flatest%2Finbox%2Fall',
            '/two_step_verification/two_factor/',
            '/latest/inbox/all?asset_id=30003&next=https%3A%2F%2Fwww.facebook.com%2Fcheckpoint%2F',
            '/latest/inbox/all?asset_id=30003&redirect_uri=%252Flogin%252F',
            '/latest/inbox/all?asset_id=30003&asset_id=90009',
            '/latest/inbox/all?asset_id%5B%5D=30003',
            '/latest/inbox/all?asset_id=30003&asset%5Fid=30003',
            '/latest/inbox/all?asset_id=30003;asset_id=90009',
            '/latest/inbox/all?asset_id=30003#checkpoint',
            '/latest/inbox/%61ll?asset_id=30003',
            "/latest/inbox/all?asset_id=30003\r\nLocation: https://example.invalid/",
            '/latest/inbox/all?asset_id=30003&business_id=%XX',
            '/latest/inbox/all?asset_id=30003&business_id=%00',
        ]as $index=>$location)yield 'unsafe '.$index=>[$location];
    }

    #[DataProvider('unsafeRedirects')]
    public function testUnsafeHostAuthenticationRouteNestedRedirectOrAmbiguousScopeIsNeverRequested(string $location):void
    {
        $session=new InboxContextLoaderSession([new HttpResponse(302,'PRIVATE_REDIRECT_HTML',['location'=>[$location]])]);
        try{(new PageInboxContextLoader($session))->load('30003');self::fail('Unsafe document redirect was followed');}
        catch(FacebookException $error){self::assertSame('facebook_inbox_unsafe_redirect',$error->reason);self::assertStringNotContainsString('PRIVATE_',$error->getMessage());self::assertStringNotContainsString($location,$error->getMessage());}
        self::assertCount(1,$session->requests);
    }

    public function testChangedPageAndSelectedItemAreRejectedBeforeSecondRequest():void
    {
        foreach([
            ['/latest/inbox/all/?asset_id=90009','30003',null,'facebook_inbox_page_mismatch'],
            ['/latest/inbox/all/?asset_id=30003&selected_item_id=60006','30003','50005','facebook_inbox_selection_mismatch'],
            ['/latest/inbox/all/?asset_id=30003','30003','50005','facebook_inbox_selection_mismatch'],
            ['/latest/inbox/all/?asset_id=30003&selected_item_id=50005','30003',null,'facebook_inbox_selection_mismatch'],
        ]as [$location,$page,$selection,$reason]){
            $session=new InboxContextLoaderSession([new HttpResponse(302,'',['location'=>[$location]])]);
            try{(new PageInboxContextLoader($session))->load($page,$selection);self::fail('Changed document scope was followed');}catch(FacebookException $error){self::assertSame($reason,$error->reason);}
            self::assertCount(1,$session->requests);
        }
        $session=new InboxContextLoaderSession([new HttpResponse(302,'',['location'=>['/latest/inbox/all/?asset_id=30003&selected_item_id=50005']]),new HttpResponse(200,self::html())]);
        self::assertSame('30003',(new PageInboxContextLoader($session))->load('30003','50005')->pageId);self::assertCount(2,$session->requests);
    }

    public function testCanonicalRedirectLoopIsRejectedWithoutRepeatingARequest():void
    {
        $session=new InboxContextLoaderSession([
            new HttpResponse(302,'',['location'=>['/latest/inbox/all/?asset_id=30003&business_id=40004']]),
            new HttpResponse(302,'',['location'=>['https://business.facebook.com/latest/inbox/all/?business_id=40004&asset_id=30003']]),
        ]);
        try{(new PageInboxContextLoader($session))->load('30003');self::fail('Loop was not rejected');}catch(FacebookException $error){self::assertSame('facebook_inbox_redirect_loop',$error->reason);}
        self::assertCount(2,$session->requests);
    }

    public function testAtMostThreeValidatedRedirectsAreFollowed():void
    {
        $responses=[];for($i=1;$i<=4;++$i)$responses[]=new HttpResponse(302,'',['location'=>['/latest/inbox/all/?asset_id=30003&nav_ref=synthetic'.$i]]);
        $session=new InboxContextLoaderSession($responses);
        try{(new PageInboxContextLoader($session))->load('30003');self::fail('Redirect limit was not enforced');}catch(FacebookException $error){self::assertSame('facebook_inbox_redirect_limit',$error->reason);}
        self::assertCount(4,$session->requests);
    }

    public function testMissingLocationHttpFailuresAndTransportErrorsRemainSafe():void
    {
        foreach([[302,'facebook_inbox_unsafe_redirect',false],[401,'facebook_authentication_required',false],[403,'facebook_authentication_required',false],
            [429,'facebook_rate_limited',true],[500,'facebook_http_failed',true],[502,'facebook_http_failed',true],[404,'facebook_http_failed',false]]as [$status,$reason,$retryable]){
            $session=new InboxContextLoaderSession([new HttpResponse($status,'PRIVATE_SYNTHETIC_ERROR_RESPONSE')]);
            try{(new PageInboxContextLoader($session))->load('30003');self::fail('Unsuccessful HTTP response became a usable context');}
            catch(FacebookException $error){self::assertSame($reason,$error->reason);self::assertSame($retryable,$error->retryable);self::assertStringNotContainsString('PRIVATE_SYNTHETIC',$error->getMessage());}
            self::assertCount(1,$session->requests);
        }
        $session=new InboxContextLoaderSession([new \RuntimeException('PRIVATE_SYNTHETIC_EXCEPTION_WITH_COOKIES')]);
        try{(new PageInboxContextLoader($session))->load('30003');self::fail('Transport failure was not translated');}catch(FacebookException $error){self::assertSame('facebook_transport_failed',$error->reason);self::assertTrue($error->retryable);self::assertStringNotContainsString('PRIVATE_SYNTHETIC',$error->getMessage());}
    }

    public function testHttp200LoginPageOrDifferentAccountDoesNotCreateContext():void
    {
        foreach(['<html><form action="/login/">PRIVATE_SYNTHETIC_LOGIN_HTML</form></html>',self::html('90009')]as $html){
            $session=new InboxContextLoaderSession([new HttpResponse(200,$html)]);
            try{(new PageInboxContextLoader($session))->load('30003');self::fail('An unverified document became a native Page context');}catch(FacebookException $error){self::assertSame('facebook_identity_unconfirmed',$error->reason);self::assertStringNotContainsString('PRIVATE_SYNTHETIC',$error->getMessage());}
        }
    }

    public function testInvalidInputPerformsNoRequest():void
    {
        foreach([['0',null],['030003',null],['30003',''],['30003',str_repeat('x',4097)],['30003',"bad\0selection"],['30003',"bad\nselection"]]as [$page,$selection]){
            $session=new InboxContextLoaderSession([]);
            try{(new PageInboxContextLoader($session))->load($page,$selection);self::fail('Invalid native input was accepted');}catch(\InvalidArgumentException){}
            self::assertSame([],$session->requests);
        }
    }

    private static function html(string $account='10001'):string
    {
        $view=['props'=>['localScopes'=>[['id'=>'30003','type'=>'page']]]];
        $root=['initialRouteInfo'=>['route'=>['canonicalRouteName'=>'comet.bizweb.BusinessCometBizSuiteInboxAllMessagesRoute','params'=>['asset_id'=>'30003'],'rootView'=>$view,'hostableView'=>$view]],
            'expectedPreloaders'=>[['queryName'=>'BusinessCometBizSuiteInboxRootQuery','actorID'=>'30003','variables'=>['page_id'=>'30003','business_assets'=>null]],
                ['queryName'=>'BusinessCometContextCardInboxContainerQuery','actorID'=>'30003','variables'=>['shouldSkipPageQuery'=>false]]]];
        $data=['define'=>[['CurrentUserInitialData',[],['USER_ID'=>$account,'ACCOUNT_ID'=>$account],0],
            ['RelayAPIConfigDefaults',[],['graphURI'=>'/api/graphql/','useXController'=>true,'actorID'=>$account],0],
            ['DTSGInitialData',[],['token'=>'PRIVATE_SYNTHETIC_DOCUMENT_CSRF'],0]],
            'require'=>[['HasteSupportData','handle',null,[['gkxData'=>['22417'=>['result'=>false]],'qexData'=>['1407'=>['r'=>true]]]]],
                ['CometPlatformRootClient@synthetic','initialize',['BusinessCometRootConfig'],[$root]]],
            '__bbox'=>['result'=>['data'=>['biz_inbox_config'=>['page_id'=>'30003','profile_plus_id_for_delegate_page'=>'20002','is_messenger_enabled'=>true]]]]];
        return '<script type="application/json">'.json_encode($data,JSON_THROW_ON_ERROR).'</script>';
    }
}

final class InboxContextLoaderSession implements WebSession
{
    /** @var list<array{method:string,url:string,form:array<string,string>}> */ public array $requests=[];
    /** @param list<HttpResponse|\Throwable> $responses */
    public function __construct(private array $responses){}
    public function accountId():string{return '10001';}
    public function privateRequestFields():array{throw new \LogicException('The loader must use the native document context, not unrelated Messenger request fields');}
    public function request(string $method,string $url,array $form=[]):HttpResponse
    {
        $this->requests[]=['method'=>$method,'url'=>$url,'form'=>$form];$response=array_shift($this->responses);
        if($response instanceof \Throwable)throw $response;
        if(!$response instanceof HttpResponse)throw new \LogicException('Unexpected synthetic document request');
        return $response;
    }
}
