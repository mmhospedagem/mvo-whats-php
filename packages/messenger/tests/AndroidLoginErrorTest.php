<?php
declare(strict_types=1);

namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\AndroidLoginError;
use MVO\Messenger\MessengerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AndroidLoginErrorTest extends TestCase
{
    /** @param array<string,mixed> $body Raw native error envelopes under test. */
    #[DataProvider('errorShapes')]
    public function testNativeTwoFactorErrorRetainsOnlyTheBoundContinuation(array $body):void
    {
        $error=AndroidLoginError::fromJson(json_encode($body,JSON_THROW_ON_ERROR));
        self::assertNotNull($error);self::assertSame(406,$error->code);
        self::assertSame(['uid'=>'12345678901234567890','first_factor'=>'SYNTHETIC_FACTOR','machine_id'=>'SYNTHETIC_MACHINE'],$error->twoFactorContextPrivate());
        self::assertSame(['uid'=>'12345678901234567890','machine_id'=>'SYNTHETIC_MACHINE','auth_token'=>'SYNTHETIC_UNUSED_TOKEN'],$error->approvalContextPrivate());
        self::assertNull($error->checkpointContextPrivate());
        self::assertStringNotContainsString('SYNTHETIC_FACTOR',print_r($error,true));
        self::assertStringNotContainsString('SYNTHETIC_UNUSED_TOKEN',print_r($error,true));
    }
    /** @return iterable<string,array{array<string,mixed>}> */
    public static function errorShapes():iterable
    {
        $data=['uid'=>'12345678901234567890','machine_id'=>'SYNTHETIC_MACHINE','login_first_factor'=>'SYNTHETIC_FACTOR','auth_token'=>'SYNTHETIC_UNUSED_TOKEN'];
        yield 'nested'=>[['error'=>['code'=>406,'error_data'=>$data]]];
        yield 'nested encoded data'=>[['error'=>['code'=>406,'error_data'=>json_encode($data,JSON_THROW_ON_ERROR)]]];
        yield 'flat'=>[['error_code'=>406,'error_msg'=>'Private server text','error_data'=>$data]];
    }

    public function testMissingFirstFactorCannotBecomeReadyByItsErrorCodeAlone():void
    {
        $error=AndroidLoginError::fromJson('{"error":{"code":406,"error_data":{"uid":"123"}}}');
        self::assertNotNull($error);self::assertNull($error->twoFactorContextPrivate());
        self::assertNull($error->approvalContextPrivate());
    }

    #[DataProvider('approvalMachines')]
    public function testApprovalRequiresItsOwnTokenAndAllowsOnlyAnActualMachineOrNoMachine(mixed $machine,?string $expected):void
    {
        $error=AndroidLoginError::fromJson(json_encode(['error_code'=>406,'error_data'=>[
            'uid'=>123,'machine_id'=>$machine,'auth_token'=>'SYNTHETIC_PRIVATE_APPROVAL_TOKEN',
        ]],JSON_THROW_ON_ERROR));
        self::assertSame(['uid'=>'123','machine_id'=>$expected,'auth_token'=>'SYNTHETIC_PRIVATE_APPROVAL_TOKEN'],$error?->approvalContextPrivate());
        self::assertNull($error->twoFactorContextPrivate(),'An approval token is never a first factor for code submission');
        self::assertSame('{"code":406}',json_encode($error,JSON_THROW_ON_ERROR));
        foreach(['SYNTHETIC_PRIVATE_APPROVAL_TOKEN','SYNTHETIC_MACHINE']as$private)self::assertStringNotContainsString($private,print_r($error,true));
    }

    /** @return iterable<string,array{?string,?string}> */
    public static function approvalMachines():iterable
    {
        yield 'actual machine'=>['SYNTHETIC_MACHINE','SYNTHETIC_MACHINE'];
        yield 'no machine'=>[null,null];
        yield 'empty machine'=>['',null];
    }

    #[DataProvider('invalidApprovalTokens')]
    public function testOptionalInvalidApprovalTokenDoesNotChangeCodeSubmissionContext(mixed $token):void
    {
        $error=AndroidLoginError::fromJson(json_encode(['error_code'=>406,'error_data'=>[
            'uid'=>'123','login_first_factor'=>'SYNTHETIC_FACTOR','auth_token'=>$token,
        ]],JSON_THROW_ON_ERROR));
        self::assertSame(['uid'=>'123','first_factor'=>'SYNTHETIC_FACTOR','machine_id'=>null],$error?->twoFactorContextPrivate());
        self::assertNull($error->approvalContextPrivate());
    }

    /** @return iterable<string,array{null|string|int|bool|array{token:string}}> */
    public static function invalidApprovalTokens():iterable
    {
        yield 'missing/null'=>[null];yield 'empty'=>[''];yield 'integer'=>[123];yield 'boolean'=>[true];
        yield 'array'=>[['token'=>'SYNTHETIC_PRIVATE_APPROVAL_TOKEN']];
        yield 'space'=>['SYNTHETIC TOKEN'];yield 'line break'=>["SYNTHETIC\r\nTOKEN"];yield 'NUL'=>["SYNTHETIC\0TOKEN"];
        yield 'DEL'=>["SYNTHETIC\x7fTOKEN"];yield 'too long'=>[str_repeat('A',16385)];
    }

    public function testOpaqueApprovalTokenMaximumAndBigIntegerUidRemainPrivate():void
    {
        $token=str_repeat('A',16384);
        $error=AndroidLoginError::fromJson('{"error":{"code":406,"error_data":{"uid":12345678901234567890,"auth_token":"'.$token.'"}}}');
        self::assertSame(['uid'=>'12345678901234567890','machine_id'=>null,'auth_token'=>$token],$error?->approvalContextPrivate());
        self::assertStringNotContainsString($token,print_r($error,true));
        $this->expectException(\LogicException::class);serialize($error);
    }

    #[DataProvider('invalidApprovalBindings')]
    public function testApprovalRejectsInvalidUidMachineAndOtherNativeBranches(int $code,mixed $uid,mixed $machine):void
    {
        $error=AndroidLoginError::fromJson(json_encode(['error_code'=>$code,'error_data'=>[
            'uid'=>$uid,'machine_id'=>$machine,'auth_token'=>'SYNTHETIC_PRIVATE_APPROVAL_TOKEN',
        ]],JSON_THROW_ON_ERROR));
        self::assertNull($error?->approvalContextPrivate());
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE_APPROVAL_TOKEN',print_r($error,true));
        // Non406 errors must not retain the token even in their private storage.
        if($code!==406){
            $context=(new \ReflectionProperty(AndroidLoginError::class,'context'))->getValue($error);
            self::assertArrayNotHasKey('auth_token',$context);
        }
    }

    /** @return iterable<string,array{int,mixed,mixed}> Raw JSON UID and machine inputs deliberately include malformed types. */
    public static function invalidApprovalBindings():iterable
    {
        yield '405 is checkpoint'=>[405,'123','SYNTHETIC_MACHINE'];
        yield '401 is credentials'=>[401,'123','SYNTHETIC_MACHINE'];
        yield 'missing UID'=>[406,null,null];yield 'zero UID'=>[406,'0',null];yield 'leading zero UID'=>[406,'0123',null];
        yield 'nondecimal UID'=>[406,'synthetic@example.invalid',null];yield 'float UID'=>[406,123.5,null];
        yield 'oversized UID'=>[406,str_repeat('1',26),null];yield 'composite UID'=>[406,['123'],null];
        yield 'composite machine'=>[406,'123',['machine'=>'synthetic']];yield 'machine whitespace'=>[406,'123','SYNTHETIC MACHINE'];
        yield 'machine too long'=>[406,'123',str_repeat('M',4097)];
    }

    public function testOptionalEmptyMachineDoesNotInventAnIdentifier():void
    {
        $error=AndroidLoginError::fromJson('{"error_code":406,"error_data":{"uid":"123","login_first_factor":"SYNTHETIC_FACTOR","machine_id":""}}');
        self::assertSame(['uid'=>'123','first_factor'=>'SYNTHETIC_FACTOR','machine_id'=>null],$error?->twoFactorContextPrivate());
    }

    public function testCredentialsErrorIsNotMistakenForCodeSubmissionOrCheckpoint():void
    {
        $error=AndroidLoginError::fromJson('{"error":{"code":401,"error_data":{"uid":"123","login_first_factor":"synthetic"}}}');
        self::assertNotNull($error);self::assertNull($error->twoFactorContextPrivate());self::assertNull($error->checkpointContextPrivate());
    }

    public function testCheckpointAcceptsOnlyTheServerFacebookUrlAndFlow():void
    {
        $error=AndroidLoginError::fromJson('{"error_code":405,"error_data":{"url":"https://www.facebook.com/checkpoint/?flow=synthetic","flow_id":"SYNTHETIC_FLOW"}}');
        self::assertSame(['url'=>'https://www.facebook.com/checkpoint/?flow=synthetic','flow_id'=>'SYNTHETIC_FLOW'],$error?->checkpointContextPrivate());
        $unsafe=AndroidLoginError::fromJson('{"error_code":405,"error_data":{"url":"https://facebook.com.evil.invalid/checkpoint/","flow_id":"SYNTHETIC_FLOW"}}');
        self::assertNull($unsafe?->checkpointContextPrivate());
    }

    #[DataProvider('checkpointAccountIds')]
    public function testCheckpointRetainsOnlyAnActualValidUid(mixed $uid,?string $expected):void
    {
        $error=AndroidLoginError::fromJson(json_encode(['error_code'=>405,'error_data'=>[
            'url'=>'https://www.facebook.com/checkpoint/?flow=SYNTHETIC_PRIVATE_URL',
            'flow_id'=>'SYNTHETIC_PRIVATE_FLOW','uid'=>$uid,'access_token'=>'SYNTHETIC_UNUSED_TOKEN',
        ]],JSON_THROW_ON_ERROR));
        $context=$error?->checkpointContextPrivate();
        if($expected===null)self::assertNull($context);
        else self::assertSame(['url'=>'https://www.facebook.com/checkpoint/?flow=SYNTHETIC_PRIVATE_URL','flow_id'=>'SYNTHETIC_PRIVATE_FLOW','uid'=>$expected],$context);
        $debug=print_r($error,true);
        foreach(['SYNTHETIC_PRIVATE_URL','SYNTHETIC_PRIVATE_FLOW','SYNTHETIC_UNUSED_TOKEN'] as $private)self::assertStringNotContainsString($private,$debug);
    }

    /** @return iterable<string,array{mixed,?string}> */
    public static function checkpointAccountIds():iterable
    {
        yield 'big decimal string'=>['12345678901234567890','12345678901234567890'];
        yield 'actual integer'=>[123,'123'];
        yield 'null does not mean unknown'=>[null,null];
        yield 'nondecimal'=>['owner@example.invalid',null];
        yield 'zero'=>['0',null];
        yield 'too long'=>[str_repeat('1',26),null];
        yield 'composite'=>[['uid'=>'123'],null];
    }

    public function testNoErrorEnvelopeDoesNotInferAnErrorFromOtherNumericFields():void
    {
        self::assertNull(AndroidLoginError::fromJson('{"uid":"406","access_token":"synthetic"}'));
    }

    public function testChallengeCannotBeSerializedIntoPersistentJobs():void
    {
        $error=AndroidLoginError::fromJson('{"error_code":406,"error_data":{"uid":"123","login_first_factor":"SYNTHETIC_FACTOR"}}');
        $this->expectException(\LogicException::class);serialize($error);
    }

    #[DataProvider('invalidData')]
    public function testMalformedResponsesFailWithoutExposingTheBody(string $json):void
    {
        try{AndroidLoginError::fromJson($json);self::fail('Expected invalid response');}
        catch(MessengerException $error){self::assertSame('login_native_response_invalid',$error->reason);self::assertStringNotContainsString('SYNTHETIC_FACTOR',$error->getMessage());}
    }
    /** @return iterable<string,array{string}> */
    public static function invalidData():iterable
    {
        yield 'invalid JSON'=>['SYNTHETIC_FACTOR'];
        yield 'non-object'=>['[]'];
        yield 'non-object error'=>['{"error":"SYNTHETIC_FACTOR"}'];
        yield 'invalid code'=>['{"error_code":"SYNTHETIC_FACTOR"}'];
        yield 'invalid error data'=>['{"error_code":406,"error_data":"SYNTHETIC_FACTOR"}'];
        yield 'list error data'=>['{"error_code":406,"error_data":["SYNTHETIC_FACTOR"]}'];
    }
}
