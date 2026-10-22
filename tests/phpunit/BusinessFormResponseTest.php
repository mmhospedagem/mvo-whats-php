<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\ContentDecoder;
use MVO\Protocol\SchemaCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Fictitious replies following the observed schema; no captured account content. */
final class BusinessFormResponseTest extends TestCase
{
    private static function schema(): array
    {
        return ['screens'=>[['id'=>'contact_details','title'=>'Cadastro fictício','components'=>[
            ['type'=>'TextInput','name'=>'customer_name','label'=>'Nome','value'=>'Pessoa de exemplo','isSensitive'=>false],
            ['type'=>'TextInput','name'=>'reference','label'=>'Referência','value'=>'000123','isSensitive'=>true],
        ]]],'version'=>2];
    }

    private static function params(?array $schema=null): array
    {
        return ['wa_flow_response_params'=>['title'=>'Resposta fictícia','flow_id'=>'9007199254740993','flow_name'=>'Modelo de exemplo',
            'flow_creation_source'=>'FLEXIBLE_CHECKOUT','response_message'=>json_encode($schema??self::schema(),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]];
    }

    private static function message(string $params, int $version=3, string $name='galaxy_message'): array
    {
        return ['interactiveResponseMessage'=>['nativeFlowResponseMessage'=>['name'=>$name,'version'=>$version,'paramsJson'=>$params],
            'contextInfo'=>['stanzaId'=>'FICTITIOUS-PUBLISHED-MESSAGE','participant'=>'5511999999999@s.whatsapp.net']]];
    }

    public function testNativeVersionThreeContainsIndependentVersionTwoBusinessFormWithoutChangingRawData(): void
    {
        $params=self::params();$raw=json_encode($params,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);$message=self::message($raw);
        $decoded=ContentDecoder::decode(SchemaCodec::encode('Message',$message));$parsed=$decoded['parsedFlowResponse'];
        self::assertSame(3,$parsed['version']);self::assertSame($raw,$decoded['data']['nativeFlowResponseMessage']['paramsJson']);
        self::assertSame($params['wa_flow_response_params']['response_message'],$parsed['response']->response_message);
        self::assertSame(['flowId'=>'9007199254740993','flowName'=>'Modelo de exemplo','creationSource'=>'FLEXIBLE_CHECKOUT','version'=>2,'screens'=>self::schema()['screens']],$parsed['businessForm']);
        self::assertArrayNotHasKey('flowToken',$parsed);self::assertArrayNotHasKey('answers',$parsed['businessForm']);
        self::assertSame('000123',$parsed['businessForm']['screens'][0]['components'][1]['value']);self::assertTrue($parsed['businessForm']['screens'][0]['components'][1]['isSensitive']);
        self::assertSame('FICTITIOUS-PUBLISHED-MESSAGE',$decoded['context']['stanzaId']);
        self::assertEquals($parsed,ContentDecoder::flowResponse(['deviceSentMessage'=>['message'=>$message]]));
    }

    public function testRepeatedNamesAndScreenIdsStayOrderedWithoutFlatteningOrInventingIdentifiers(): void
    {
        $schema=self::schema();$schema['screens'][0]['components'][1]['name']='customer_name';
        $schema['screens'][]=$schema['screens'][0];$schema['screens'][1]['components'][0]['value']='Outro valor';
        $parsed=ContentDecoder::flowResponse(self::message(json_encode(self::params($schema),JSON_THROW_ON_ERROR)));
        self::assertSame($schema['screens'],$parsed['businessForm']['screens']);self::assertCount(2,$parsed['businessForm']['screens']);
        self::assertSame('customer_name',$parsed['businessForm']['screens'][0]['components'][1]['name']);
        self::assertSame('Outro valor',$parsed['businessForm']['screens'][1]['components'][0]['value']);
    }

    public function testEmptyStringValuesLabelsAndFalseSensitivityArePreserved(): void
    {
        $schema=self::schema();$schema['screens'][0]['title']='';$schema['screens'][0]['components'][0]=['type'=>'TextInput','name'=>'optional','label'=>'','value'=>'','isSensitive'=>false];
        $parsed=ContentDecoder::flowResponse(self::message(json_encode(self::params($schema),JSON_THROW_ON_ERROR)));
        self::assertSame($schema['screens'],$parsed['businessForm']['screens']);
    }

    public function testJsonLookingTextIsAValueAndDoesNotCreateDuplicateObjectKeys(): void
    {
        $schema=self::schema();$schema['screens'][0]['components'][0]['value']='{"name":"first","name":"second"} with "quotes" and \\ paths';
        $parsed=ContentDecoder::flowResponse(self::message(json_encode(self::params($schema),JSON_THROW_ON_ERROR)));
        self::assertSame($schema['screens'],$parsed['businessForm']['screens']);
    }

    public function testSchemaRecognitionDoesNotRewriteOrGateTheOuterNativeVersion(): void
    {
        $raw=json_encode(self::params(),JSON_THROW_ON_ERROR);
        foreach([2,3,4]as$version){$parsed=ContentDecoder::flowResponse(self::message($raw,$version));self::assertSame($version,$parsed['version']);self::assertSame(2,$parsed['businessForm']['version']);}
        self::assertArrayNotHasKey('businessForm',ContentDecoder::flowResponse(self::message($raw,3,'flow')));
    }

    /** @return iterable<string,array{string}> */
    public static function invalidProjections(): iterable
    {
        $base=self::params();
        foreach(['flow_id','flow_name','flow_creation_source','response_message']as$key){$copy=$base;unset($copy['wa_flow_response_params'][$key]);yield 'missing '.$key=>[json_encode($copy,JSON_THROW_ON_ERROR)];}
        foreach(['flow_id'=>123,'flow_name'=>false,'flow_creation_source'=>'BUSINESS_PLATFORM','response_message'=>'not JSON']as$key=>$value){$copy=$base;$copy['wa_flow_response_params'][$key]=$value;yield 'invalid '.$key=>[json_encode($copy,JSON_THROW_ON_ERROR)];}
        foreach(['0','not-a-flow',str_repeat('9',65)]as$i=>$id){$copy=$base;$copy['wa_flow_response_params']['flow_id']=$id;yield 'invalid flow identity '.$i=>[json_encode($copy,JSON_THROW_ON_ERROR)];}
        $schema=self::schema();$schema['version']='2';yield 'string schema version'=>[json_encode(self::params($schema),JSON_THROW_ON_ERROR)];
        $schema=self::schema();$schema['version']=3;yield 'unknown schema version'=>[json_encode(self::params($schema),JSON_THROW_ON_ERROR)];
        foreach([[],(object)['x'=>self::schema()['screens'][0]]]as$i=>$screens){$schema=self::schema();$schema['screens']=$screens;yield 'screens shape '.$i=>[json_encode(self::params($schema),JSON_THROW_ON_ERROR)];}
        foreach(['id','title','components']as$key){$schema=self::schema();unset($schema['screens'][0][$key]);yield 'missing screen '.$key=>[json_encode(self::params($schema),JSON_THROW_ON_ERROR)];}
        foreach(['type','name','label','value','isSensitive']as$key){$schema=self::schema();unset($schema['screens'][0]['components'][0][$key]);yield 'missing component '.$key=>[json_encode(self::params($schema),JSON_THROW_ON_ERROR)];}
        foreach([['isSensitive','false'],['isSensitive',0],['value',42],['value',['answer']],['value',(object)['answer'=>'example']],['name',''],['type','']]as$i=>[$key,$value]){$schema=self::schema();$schema['screens'][0]['components'][0][$key]=$value;yield 'invalid component value '.$i=>[json_encode(self::params($schema),JSON_THROW_ON_ERROR)];}
        $schema=self::schema();$schema['screens'][0]['components']=(object)['first'=>$schema['screens'][0]['components'][0]];yield 'components object is not a list'=>[json_encode(self::params($schema),JSON_THROW_ON_ERROR)];
        $schema=self::schema();$schema['screens'][0]['components']=[];yield 'no fields'=>[json_encode(self::params($schema),JSON_THROW_ON_ERROR)];
        $schema=self::schema();$schema['screens']=array_fill(0,21,$schema['screens'][0]);yield 'too many screens'=>[json_encode(self::params($schema),JSON_THROW_ON_ERROR)];
        $schema=self::schema();$schema['screens'][0]['components']=array_fill(0,101,$schema['screens'][0]['components'][0]);yield 'too many components'=>[json_encode(self::params($schema),JSON_THROW_ON_ERROR)];
        $schema=self::schema();$screen=$schema['screens'][0];$screen['components']=array_fill(0,51,$screen['components'][0]);$schema['screens']=[$screen,$screen];yield 'aggregate field limit'=>[json_encode(self::params($schema),JSON_THROW_ON_ERROR)];
        $schema=self::schema();$schema['screens'][0]['components'][0]['value']=str_repeat('x',65537);yield 'value exceeds 64 KiB'=>[json_encode(self::params($schema),JSON_THROW_ON_ERROR)];
        $copy=$base;$copy['padding']=str_repeat('x',262144);yield 'outer params exceed 256 KiB'=>[json_encode($copy,JSON_THROW_ON_ERROR)];
        $copy=$base;$copy['wa_flow_response_params']['response_message']='{"version":2,"screens":'.str_repeat('[',40).'0'.str_repeat(']',40).'}';yield 'nested JSON depth'=>[json_encode($copy,JSON_THROW_ON_ERROR)];
        $copy=$base;$copy['wa_flow_response_params']['response_message']=str_replace('"version":2','"version":1,"version":2',$copy['wa_flow_response_params']['response_message']);yield 'duplicate inner key'=>[json_encode($copy,JSON_THROW_ON_ERROR)];
        $copy=$base;$copy['wa_flow_response_params']['response_message']=str_replace('"version":2','"version":1,"\\u0076ersion":2',$copy['wa_flow_response_params']['response_message']);yield 'escaped duplicate key'=>[json_encode($copy,JSON_THROW_ON_ERROR)];
        $raw=json_encode($base,JSON_THROW_ON_ERROR);yield 'duplicate outer key'=>[substr($raw,0,-1).',"wa_flow_response_params":'.json_encode($base['wa_flow_response_params'],JSON_THROW_ON_ERROR).'}'];
    }

    #[DataProvider('invalidProjections')]
    public function testInvalidBoundedOrAmbiguousSchemaKeepsLegacyAndRawProjection(string $raw): void
    {
        $decoded=ContentDecoder::decode(SchemaCodec::encode('Message',self::message($raw)));$parsed=$decoded['parsedFlowResponse'];
        self::assertSame(3,$parsed['version']);self::assertInstanceOf(\stdClass::class,$parsed['params']);
        self::assertArrayNotHasKey('businessForm',$parsed);self::assertSame($raw,$decoded['data']['nativeFlowResponseMessage']['paramsJson']);
    }
}
