<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\{Document,DocumentRegistry,GraphQLDecoder,WebGraphQL};
use MVO\Messenger\Auth\WebSession;
use MVO\Messenger\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

final class WebProtocolTest extends TestCase
{
    private function document(string $kind='query'):Document{return new Document('SyntheticPagesQuery','123456789',$kind,['id','count'],['count'=>20],str_repeat('a',64),'https://static.xx.fbcdn.net/rsrc.php/v4/aa/r/synthetic.js');}
    public function testDocumentBuildsExactVariablesAndRejectsUnknownKeys():void
    {
        $document=$this->document();self::assertSame(['count'=>20,'id'=>'10001'],$document->arguments(['id'=>'10001']));
        $this->expectException(\InvalidArgumentException::class);$document->arguments(['invented'=>true]);
    }
    public function testGraphQLParsesAntiJsonPrettyJsonAndSeparateEnvelopesWithoutEvaluatingAnything():void
    {
        $decoder=new GraphQLDecoder();self::assertSame(['viewer'=>['id'=>'10001']],$decoder->decode('for (;;);'.json_encode(['data'=>['viewer'=>['id'=>'10001']]],JSON_PRETTY_PRINT)));
        self::assertSame(['first'=>1,'second'=>2],$decoder->decode('{"data":{"first":1}}'."\n".'{"data":{"second":2}}'));
    }
    public function testPartialErrorsNeverReturnSuccessOrLeakRemoteErrorText():void
    {
        try{(new GraphQLDecoder())->decode('{"data":{"result":null},"errors":[{"message":"SYNTHETIC_PRIVATE_TOKEN"}]}');self::fail('Expected partial failure');}
        catch(FacebookException $error){self::assertSame('partial',$error->outcome);self::assertStringNotContainsString('SYNTHETIC_PRIVATE_TOKEN',$error->getMessage());}
    }
    public function testIncrementalPatchesAndNonJsonFailExplicitly():void
    {
        foreach(['{"path":["viewer"],"data":{"id":"10001"}}','<html>PRIVATE CHECKPOINT</html>','{"errors":[{"message":"PRIVATE"}]}'] as $body){
            try{(new GraphQLDecoder())->decode($body);self::fail('Expected unsupported response');}catch(FacebookException $error){self::assertStringNotContainsString('PRIVATE',$error->getMessage());}
        }
    }
    public function testTransportKeepsOwnerFieldsAndActorSeparate():void
    {
        $session=new class implements WebSession{
            public array $forms=[];
            public function accountId():string{return '10001';}
            public function privateRequestFields():array{return ['__user'=>'10001','fb_dtsg'=>'SYNTHETIC_PRIVATE'];}
            public function request(string $method,string $url,array $form=[]):HttpResponse{
                TestCase::assertSame('POST',$method);TestCase::assertSame('https://www.facebook.com/api/graphql/',$url);$this->forms[]=$form;return new HttpResponse(200,'{"data":{"result":{"id":"20002"}}}');
            }
        };
        $client=new WebGraphQL($session,new DocumentRegistry([$this->document()]));$result=$client->execute('SyntheticPagesQuery',['id'=>'20002'],'20002');
        self::assertSame('20002',$result['result']['id']);self::assertSame('10001',$session->forms[0]['__user']);self::assertSame('20002',$session->forms[0]['av']);
        self::assertSame('123456789',$session->forms[0]['doc_id']);self::assertSame(['count'=>20,'id'=>'20002'],json_decode($session->forms[0]['variables'],true));
    }
    public function testUnavailableDocumentsMakeNoRequestAndMutationsNeverAutoRetry():void
    {
        $session=new class implements WebSession{
            public int $requests=0;
            public function accountId():string{return '10001';}
            public function privateRequestFields():array{return ['__user'=>'10001','lsd'=>'SYNTHETIC_PRIVATE'];}
            public function request(string $method,string $url,array $form=[]):HttpResponse{$this->requests++;throw new \RuntimeException('SYNTHETIC_PRIVATE_TRANSPORT');}
        };
        $client=new WebGraphQL($session,new DocumentRegistry([$this->document('mutation')]));
        try{$client->execute('UnavailableQuery',[]);self::fail('Expected unavailable document');}catch(FacebookException $error){self::assertSame('facebook_document_unavailable',$error->reason);}
        self::assertSame(0,$session->requests);
        try{$client->execute('SyntheticPagesQuery',[]);self::fail('Expected missing mutation ledger');}
        catch(FacebookException $error){self::assertSame('facebook_mutation_requires_idempotency',$error->reason);self::assertStringNotContainsString('SYNTHETIC_PRIVATE',$error->getMessage());}
        self::assertSame(0,$session->requests);
    }
    public function testReadDocumentCannotEnableItsAutomaticAccountCreationBranch():void
    {
        $document=new Document('SyntheticAccountChooserQuery','123456789','query',['skipLwiDefaultAdAccount'],['skipLwiDefaultAdAccount'=>false],str_repeat('a',64),'https://static.xx.fbcdn.net/rsrc.php/v4/aa/r/synthetic.js',['skipLwiDefaultAdAccount'=>true]);
        self::assertSame(['skipLwiDefaultAdAccount'=>true],$document->arguments([]));
        $this->expectException(\InvalidArgumentException::class);$document->arguments(['skipLwiDefaultAdAccount'=>false]);
    }
    public function testDeferredAndOutOfOrderStreamDataAreMergedOnlyIntoExistingPaths():void
    {
        $body=implode("\n",[
            '{"data":{"viewer":{"edges":[]}},"extensions":{"is_final":false}}',
            '{"label":"Synthetic$stream$edges","path":["viewer","edges",1],"data":{"node":{"id":"20002"}}}',
            '{"label":"Synthetic$stream$edges","path":["viewer","edges",0],"data":{"node":{"id":"10001"}}}',
            '{"label":"Synthetic$defer$name","path":["viewer","edges",0,"node"],"data":{"name":"Synthetic"},"extensions":{"is_final":true}}',
        ]);
        self::assertSame(['viewer'=>['edges'=>[['node'=>['id'=>'10001','name'=>'Synthetic']],['node'=>['id'=>'20002']]]]],(new GraphQLDecoder())->decode($body));
    }
    public function testUnfinishedOrConflictingIncrementalResultsNeverReturnSuccess():void
    {
        foreach([
            '{"data":{"viewer":{"id":"10001"}},"extensions":{"is_final":false}}',
            '{"data":{"viewer":{"id":"10001"}}}'."\n".'{"label":"Synthetic$defer$id","path":["viewer"],"data":{"id":"20002"}}',
            '{"data":{"viewer":{}}}'."\n".'{"label":"Synthetic$defer$absent","path":["viewer","missing"],"data":{"id":"10001"},"extensions":{"is_final":true}}',
        ] as $body){try{(new GraphQLDecoder())->decode($body);self::fail('Expected partial response');}catch(FacebookException $error){self::assertSame('partial',$error->outcome);}}
    }
    public function testRelayEnvelopeArraysAndFinalSentinelPreserveIncrementalData():void
    {
        $body='for (;;);'.json_encode([
            ['data'=>['viewer'=>['edges'=>[]]],'extensions'=>['is_final'=>false]],
            ['label'=>'Synthetic$stream$edges','path'=>['viewer','edges',0],'data'=>['node'=>['id'=>'10001']]],
            ['data'=>null,'extensions'=>['is_final'=>true]],
        ],JSON_THROW_ON_ERROR|JSON_PRETTY_PRINT);
        self::assertSame(['viewer'=>['edges'=>[['node'=>['id'=>'10001']]]]],(new GraphQLDecoder())->decode($body));
    }
    public function testSentinelAloneAndMalformedEnvelopeArraysNeverConfirmAnOperation():void
    {
        foreach([
            '{"data":null,"extensions":{"is_final":true}}',
            '[{"data":{"viewer":{}}},null]',
            '[{"data":{"viewer":{}}},[{"data":{"other":{}}}]]',
            '[]',
        ] as $body){
            try{(new GraphQLDecoder())->decode($body);self::fail('Expected an unconfirmed or invalid response');}
            catch(FacebookException $error){self::assertNotSame('confirmed',$error->outcome);}
        }
    }
    public function testEnvelopeLimitAppliesToArrayBatchesAsWellAsLines():void
    {
        $body=json_encode(array_fill(0,2049,['data'=>['viewer'=>['id'=>'10001']]]),JSON_THROW_ON_ERROR);
        $this->expectException(FacebookException::class);
        (new GraphQLDecoder())->decode($body);
    }
}
