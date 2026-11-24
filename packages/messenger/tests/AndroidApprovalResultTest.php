<?php
declare(strict_types=1);

namespace MVO\Messenger\Tests;

use MVO\Messenger\Auth\AndroidApprovalResult;
use MVO\Messenger\MessengerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AndroidApprovalResultTest extends TestCase
{
    #[DataProvider('approvalStatuses')]
    public function testOnlyAnActualBooleanStatusIsConsumed(bool $approved):void
    {
        $result=AndroidApprovalResult::fromJson(json_encode(['data'=>[['approved'=>$approved]]],JSON_THROW_ON_ERROR));
        self::assertSame($approved,$result,'The parser returns only status, never an authenticated identity or CookieJar');
    }

    /** @return iterable<string,array{bool}> */
    public static function approvalStatuses():iterable
    {
        yield 'pending'=>[false];yield 'approved'=>[true];
    }

    #[DataProvider('invalidResponses')]
    public function testMalformedAmbiguousAndErrorStatusesNeverBecomeApproved(string $json):void
    {
        try{AndroidApprovalResult::fromJson($json);self::fail('Expected invalid native approval response');}
        catch(MessengerException $error){
            self::assertSame('login_native_response_invalid',$error->reason);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE',$error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    /** @return iterable<string,array{string}> */
    public static function invalidResponses():iterable
    {
        yield 'invalid JSON'=>['SYNTHETIC_PRIVATE'];yield 'root list'=>['[{"approved":true}]'];yield 'null'=>['null'];
        yield 'root bool'=>['true'];yield 'missing data'=>['{}'];yield 'null data'=>['{"data":null}'];
        yield 'empty statuses'=>['{"data":[]}'];yield 'object disguised as list'=>['{"data":{"0":{"approved":true}}}'];
        yield 'entry is list'=>['{"data":[[true]]}'];yield 'entry is bool'=>['{"data":[true]}'];yield 'entry null'=>['{"data":[null]}'];
        yield 'missing boolean'=>['{"data":[{}]}'];yield 'null boolean'=>['{"data":[{"approved":null}]}'];
        yield 'numeric truthy'=>['{"data":[{"approved":1}]}'];yield 'string truthy'=>['{"data":[{"approved":"true"}]}'];
        yield 'string false'=>['{"data":[{"approved":"false"}]}'];yield 'array truthy'=>['{"data":[{"approved":[true]}]}'];
        yield 'ambiguous statuses'=>['{"data":[{"approved":true},{"approved":false}]}'];
        yield 'unexpected root field'=>['{"data":[{"approved":true}],"access_token":"SYNTHETIC_PRIVATE"}'];
        yield 'unexpected status field'=>['{"data":[{"approved":true,"uid":"123"}]}'];
        yield 'error and approval'=>['{"error":{"code":405,"message":"SYNTHETIC_PRIVATE"},"data":[{"approved":true}]}'];
        yield 'native error'=>['{"error_code":406,"data":[{"approved":true}]}'];
        yield 'unparsed trailing data'=>['{"data":[{"approved":true}]}SYNTHETIC_PRIVATE'];
        yield 'too large'=>[str_repeat(' ',2097153).'{"data":[{"approved":true}]}'];
        yield 'invalid encoding'=>["{\"data\":[{\"approved\":\"\xff\"}]}"];
    }
}
