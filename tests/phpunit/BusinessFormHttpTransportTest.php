<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Service\{BusinessFormGraphqlService,BusinessFormHttpTransport,ServiceException};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Injected exchanges only: no fixture in this file performs an HTTP request. */
final class BusinessFormHttpTransportTest extends TestCase
{
    private const TOKEN='fixture-user-token-not-an-app-key';
    private array $calls=[];
    private function transport(int $status=200,string $body='{"data":{"result":true}}'):BusinessFormHttpTransport
    {
        return new BusinessFormHttpTransport(static fn():string=>self::TOKEN,exchange:function(string $url,array $headers,string $payload,float $timeout)use($status,$body):array{$this->calls[]=[$url,$headers,$payload,$timeout];return ['status'=>$status,'body'=>$body];});
    }
    public function testNativeRawJsonPostEnvelopeAndFixedOriginMatchObservedContract():void
    {
        $data=['xwa_flexible_checkout_create_flow'=>['id'=>'9007199254740993','version'=>'V700','future'=>['keep'=>true]]];
        $transport=$this->transport(body:json_encode(['data'=>$data],JSON_THROW_ON_ERROR));$variables=['form_title'=>'Cadastro MVO','custom_fields'=>[]];self::assertSame($data,$transport->request(BusinessFormGraphqlService::PUBLISH_DOC_ID,$variables));
        [$url,$headers,$body,$timeout]=$this->calls[0];self::assertSame('https://graph.facebook.com/graphql',$url);self::assertSame(['Content-Type: application/json','Accept: application/json','Accept-Encoding: gzip'],$headers);self::assertSame(30.0,$timeout);
        self::assertSame(['variables'=>$variables,'access_token'=>self::TOKEN,'doc_id'=>26463307113337151,'lang'=>'pt_BR','Content-Type'=>'application/json'],json_decode($body,true,128,JSON_THROW_ON_ERROR));self::assertIsInt(json_decode($body,true)['doc_id']);
        foreach(['app_key','app_id','client_doc_id','application_secret','Authorization:']as$key){self::assertStringNotContainsString($key,$body);self::assertStringNotContainsString($key,implode("\n",$headers));}
    }
    public function testEmptyVariablesAreJsonObjectAndCustomTimeoutLanguageStayExplicit():void
    {
        $transport=new BusinessFormHttpTransport(static fn():string=>self::TOKEN,'en_US','socks5://127.0.0.1:1080',function(string $url,array $headers,string $body,float $timeout):array{self::assertSame('{}',json_encode(json_decode($body)->variables));self::assertSame('en_US',json_decode($body)->lang);self::assertSame(7.5,$timeout);return ['status'=>200,'body'=>'{"data":{}}'];},7.5);
        self::assertSame([],$transport->request(BusinessFormGraphqlService::GENERATE_DOC_ID,[]));
    }
    public function testGraphqlErrorIsSafeExplicitRefusalAndDoesNotEchoServerToken():void
    {
        $body=json_encode(['errors'=>[['message'=>'Secret '.self::TOKEN,'extensions'=>['error_code'=>'403']]],'data'=>null],JSON_THROW_ON_ERROR);$transport=$this->transport(body:$body);
        try{$transport->request(BusinessFormGraphqlService::PUBLISH_DOC_ID,[]);self::fail('Refusal accepted');}catch(ServiceException $error){self::assertSame('403',$error->serverCode);self::assertStringNotContainsString(self::TOKEN,$error->getMessage());self::assertSame('business.forms.graphql',$error->operation);}self::assertCount(1,$this->calls);
    }
    public function testHttp4xxErrorObjectUsesSafeServerCode():void
    {
        $transport=$this->transport(400,'{"error":{"message":"do not expose","code":190}}');try{$transport->request(BusinessFormGraphqlService::GENERATE_DOC_ID,[]);self::fail();}catch(ServiceException $error){self::assertSame('190',$error->serverCode);self::assertStringNotContainsString('do not expose',$error->getMessage());}
    }
    public function testUnsafeErrorCodeCannotEscapeThroughException():void
    {
        $transport=$this->transport(403,json_encode(['error'=>['code'=>self::TOKEN,'message'=>self::TOKEN]],JSON_THROW_ON_ERROR));try{$transport->request(BusinessFormGraphqlService::GENERATE_DOC_ID,[]);self::fail();}catch(ServiceException $error){self::assertSame('403',$error->serverCode);self::assertStringNotContainsString(self::TOKEN,$error->getMessage());}
    }
    #[DataProvider('unconfirmedResponses')]
    public function testPartialFailuresAndMalformedResponsesKeepOutcomeUnknown(int $status,string $body):void
    {
        $transport=$this->transport($status,$body);try{$transport->request(BusinessFormGraphqlService::PUBLISH_DOC_ID,[]);self::fail('Unconfirmed operation accepted');}catch(\RuntimeException $error){self::assertNotInstanceOf(ServiceException::class,$error);self::assertStringNotContainsString(self::TOKEN,$error->getMessage());}self::assertCount(1,$this->calls,'No redirect or retry through exchange');
    }
    public static function unconfirmedResponses():iterable
    {
        yield 'partial result'=>[200,'{"data":{"resource":{"id":"123"}},"errors":[{"message":"partial"}]}'];yield 'partial 4xx'=>[400,'{"data":{"resource":{"id":"123"}},"errors":[{"message":"partial"}]}'];yield 'server failure'=>[503,'{"error":{"code":503}}'];yield 'server failure with data'=>[500,'{"data":{"id":"123"}}'];yield 'redirect'=>[302,'{}'];yield 'non 200'=>[201,'{"data":{}}'];yield 'malformed'=>[200,'{'];yield 'root list'=>[200,'[]'];yield 'missing data'=>[200,'{}'];yield 'null data'=>[200,'{"data":null}'];yield 'list data'=>[200,'{"data":[]}'];yield 'scalar data'=>[200,'{"data":true}'];
    }
    public function testPlainHttp4xxIsRefusalRatherThanSuccessfulJson():void
    {
        $this->expectException(ServiceException::class);$this->transport(401,'Unauthorized')->request(BusinessFormGraphqlService::GENERATE_DOC_ID,[]);
    }
    public function testExchangeExceptionIsSanitizedAndNeverReplayed():void
    {
        $calls=0;$transport=new BusinessFormHttpTransport(static fn():string=>self::TOKEN,exchange:static function()use(&$calls):never{$calls++;throw new \RuntimeException(self::TOKEN);});
        try{$transport->request(BusinessFormGraphqlService::GENERATE_DOC_ID,[]);self::fail();}catch(\RuntimeException $error){self::assertStringNotContainsString(self::TOKEN,$error->getMessage());self::assertNull($error->getPrevious());}self::assertSame(1,$calls);
    }
    public function testInvalidDocOrPayloadDoesNotFetchTokenOrCallExchange():void
    {
        $tokens=0;$calls=0;$transport=new BusinessFormHttpTransport(static function()use(&$tokens):string{$tokens++;return self::TOKEN;},exchange:static function()use(&$calls):array{$calls++;return ['status'=>200,'body'=>'{}'];});
        foreach([['123',[]],[BusinessFormGraphqlService::GENERATE_DOC_ID,['list']],[BusinessFormGraphqlService::GENERATE_DOC_ID,['invalid-key'=>'x']],[BusinessFormGraphqlService::GENERATE_DOC_ID,['prompt'=>"\xff"]]]as[$doc,$variables]){try{$transport->request($doc,$variables);self::fail();}catch(\InvalidArgumentException){}}
        try{$transport->request(BusinessFormGraphqlService::GENERATE_DOC_ID,['prompt'=>str_repeat('x',BusinessFormHttpTransport::MAX_BYTES+1)]);self::fail();}catch(\LengthException){}self::assertSame(0,$tokens);self::assertSame(0,$calls);
    }
    #[DataProvider('badConfigurations')]
    public function testInvalidConfigurationIsRejected(string $language,?string $proxy,float $timeout):void
    {
        $this->expectException(\InvalidArgumentException::class);new BusinessFormHttpTransport(static fn():string=>self::TOKEN,$language,$proxy,static fn():array=>[], $timeout);
    }
    public static function badConfigurations():iterable
    {
        yield ['pt_BR',null,0.0];yield ['pt_BR',null,NAN];yield ['pt_BR',null,INF];yield ['pt_BR',null,301.0];yield ["pt_BR\r\nHeader",null,30.0];yield ['pt_BR','file:///tmp/proxy',30.0];yield ['pt_BR',"http://localhost\n",30.0];yield ['pt_BR','',30.0];
    }
    public function testMissingTokenAndProviderFailureDoNotDispatchOrExposeSecrets():void
    {
        foreach([static fn():string=>'',static fn():string=>"bad\ntoken",static fn():string=>throw new \RuntimeException(self::TOKEN)]as$provider){$calls=0;$transport=new BusinessFormHttpTransport($provider,exchange:static function()use(&$calls):array{$calls++;return [];});try{$transport->request(BusinessFormGraphqlService::GENERATE_DOC_ID,[]);self::fail();}catch(\LogicException|\RuntimeException $error){self::assertStringNotContainsString(self::TOKEN,$error->getMessage());}self::assertSame(0,$calls);}
    }
    public function testProviderRefusalKeepsOnlySanitizedTypeAndSafeCodeWithoutDispatch():void
    {
        foreach(['403','429','431',self::TOKEN]as$code){$calls=0;$original=new ServiceException('private.authentication',$code,self::TOKEN);$transport=new BusinessFormHttpTransport(static fn():string=>throw $original,exchange:static function()use(&$calls):array{$calls++;return [];});
            try{$transport->request(BusinessFormGraphqlService::GENERATE_DOC_ID,[]);self::fail('Provider refusal was hidden');}catch(ServiceException $error){self::assertSame('business.forms.authentication',$error->operation);self::assertSame($code===self::TOKEN?null:$code,$error->serverCode);self::assertSame('Business form authentication was refused',$error->reason);self::assertNotSame($original,$error);self::assertNull($error->getPrevious());self::assertStringNotContainsString(self::TOKEN,$error->getMessage());}
            self::assertSame(0,$calls);
        }
    }
    public function testOversizedOrInvalidExchangeReplyDoesNotReturnData():void
    {
        foreach([['status'=>200,'body'=>str_repeat('x',BusinessFormHttpTransport::MAX_BYTES+1)],['status'=>'200','body'=>'{}'],['status'=>200,'body'=>[]]]as$reply){$transport=new BusinessFormHttpTransport(static fn():string=>self::TOKEN,exchange:static fn():array=>$reply);try{$transport->request(BusinessFormGraphqlService::GENERATE_DOC_ID,[]);self::fail();}catch(\RuntimeException $error){self::assertStringContainsString('response',$error->getMessage());self::assertNotInstanceOf(ServiceException::class,$error);}}
    }
    public function testWithdrawnAuthorizationBeforeAuthenticationDoesNotFetchTokenOrSendHttp():void
    {
        $tokens=0;$calls=0;$failure=new \LogicException('Access withdrawn');
        $transport=new BusinessFormHttpTransport(static function()use(&$tokens):string{$tokens++;return self::TOKEN;},exchange:static function()use(&$calls):array{$calls++;return [];},authorizationGuard:static fn()=>throw $failure);
        try{$transport->request(BusinessFormGraphqlService::GENERATE_DOC_ID,[]);self::fail('Unauthorized request accepted');}catch(\LogicException $error){self::assertSame($failure,$error);}
        self::assertSame(0,$tokens);self::assertSame(0,$calls);
    }
    public function testWithdrawalDuringAuthenticationPreventsHttpAndPreservesApplicationDenial():void
    {
        foreach([false,true]as$authFails){$active=true;$calls=0;$failure=new \LogicException('Access withdrawn');
            $transport=new BusinessFormHttpTransport(static function()use(&$active,$authFails):string{$active=false;if($authFails)throw new ServiceException('native',null,'safe');return self::TOKEN;},exchange:static function()use(&$calls):array{$calls++;return [];},authorizationGuard:static function()use(&$active,$failure):void{if(!$active)throw $failure;});
            try{$transport->request(BusinessFormGraphqlService::GENERATE_DOC_ID,[]);self::fail('HTTP escaped after withdrawal');}catch(\LogicException $error){self::assertSame($failure,$error);}
            self::assertSame(0,$calls);
        }
    }

    public static function safeGraphqlErrorKinds():iterable
    {
        yield 'exact object'=>[['error'=>['code'=>1675012,'message'=>'missing_required_variable_value']],'missing_required_variable_value'];
        yield 'exact known fixed sentence'=>[['error'=>['code'=>1675012,'message'=>'A server error missing_required_variable_value occured. Check server logs for details.']],'missing_required_variable_value'];
        yield 'fixed sentence with private prefix'=>[['error'=>['code'=>1675012,'message'=>self::TOKEN.' A server error missing_required_variable_value occured. Check server logs for details.']],null];
        yield 'fixed sentence with private suffix'=>[['error'=>['code'=>1675012,'message'=>'A server error missing_required_variable_value occured. Check server logs for details. '.self::TOKEN]],null];
        yield 'unobserved corrected spelling'=>[['error'=>['code'=>1675012,'message'=>'A server error missing_required_variable_value occurred. Check server logs for details.']],null];
        yield 'exact errors list'=>[['errors'=>[['message'=>'missing_required_variable_value','extensions'=>['error_code'=>1675012]]]],'missing_required_variable_value'];
        yield 'numeric code alone'=>[['error'=>['code'=>1675012]],null];
        yield 'private prefix'=>[['error'=>['code'=>1675012,'message'=>self::TOKEN.' missing_required_variable_value']],null];
        yield 'private suffix'=>[['error'=>['code'=>1675012,'message'=>'missing_required_variable_value '.self::TOKEN]],null];
        yield 'newline'=>[['error'=>['code'=>1675012,'message'=>"missing_required_variable_value\n"]],null];
        yield 'case mismatch'=>[['error'=>['code'=>1675012,'message'=>'MISSING_REQUIRED_VARIABLE_VALUE']],null];
        yield 'non string message'=>[['error'=>['code'=>1675012,'message'=>['missing_required_variable_value']]],null];
        yield 'extension cannot supply arbitrary message'=>[['error'=>['code'=>1675012,'extensions'=>['message'=>'missing_required_variable_value']]],null];
        yield 'unselected second error'=>[['errors'=>[['code'=>1675012,'message'=>self::TOKEN],['message'=>'missing_required_variable_value']]],null];
    }
    #[DataProvider('safeGraphqlErrorKinds')]
    public function testOnlyExactKnownErrorSignatureProducesFixedDiagnostic(array $envelope,?string $kind):void
    {
        $transport=$this->transport(400,json_encode($envelope,JSON_THROW_ON_ERROR));
        try{$transport->request(BusinessFormGraphqlService::PUBLISH_DOC_ID,[]);self::fail('Explicit refusal accepted');}
        catch(ServiceException $error){
            self::assertSame('business.forms.graphql',$error->operation);self::assertSame('1675012',$error->serverCode);
            self::assertSame($kind,$error->errorKind);self::assertSame('Business GraphQL request was refused',$error->reason);
            self::assertStringNotContainsString(self::TOKEN,$error->getMessage());self::assertNull($error->getPrevious());
        }
        self::assertCount(1,$this->calls,'Diagnostic cannot retry or change the request');
    }

    public function testServiceExceptionKeepsThreeArgumentCompatibilityAndRejectsArbitraryKind():void
    {
        $legacy=new ServiceException('fixture','403','safe fixture');self::assertNull($legacy->errorKind);self::assertSame(403,$legacy->getCode());
        self::assertSame('fixture: safe fixture (403)',$legacy->getMessage());
        $diagnostic=new ServiceException('fixture','403','safe fixture',self::TOKEN);self::assertNull($diagnostic->errorKind);
        self::assertStringNotContainsString(self::TOKEN,$diagnostic->getMessage());
    }
}
