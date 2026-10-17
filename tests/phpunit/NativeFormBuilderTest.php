<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\{ContentBuilder,ContentDecoder,NativeFormBuilder};
use MVO\Protocol\SchemaCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NativeFormBuilderTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function content(): array
    {
        return ['type'=>'form','text'=>'Escolha uma opção','cta'=>'Responder','steps'=>[
            ['id'=>'nome','label'=>'Qual seu nome?','selection'=>'single',
                'options'=>[['id'=>'maik','title'=>'Maik'],['id'=>'outro','title'=>'Outro nome']], 'allow_custom_answer'=>true],
        ]];
    }

    /** @return array<string,mixed> */
    private static function androidContent(): array
    {
        return ['type'=>'form','platform'=>'android','text'=>'Digite seu nome','cta'=>'Responder','steps'=>[
            ['kind'=>'input','id'=>'cadastro','label'=>'Cadastro MVO','button'=>'Enviar','fields'=>[
                ['id'=>'name','label'=>'Nome','type'=>'text','required'=>true,'default'=>'Maik'],
            ]],
        ]];
    }

    public function testAndroidInputFieldsAndChoicesKeepDistinctGraphAndFlatFieldIds(): void
    {
        $content = self::androidContent();
        $content['steps'][] = [...self::content()['steps'][0],'kind'=>'choice'];
        $content['steps'][0]['fields'][] = ['id'=>'_mvo_form','label'=>'Observação','type'=>'text','required'=>false,'default'=>''];
        $original = $content;
        $decoded = ContentDecoder::decode(ContentBuilder::build($content, '5511000000000@s.whatsapp.net')['bytes']);
        self::assertSame($original, $content);
        $native = $decoded['data']['nativeFlowMessage'];
        self::assertSame('form_message', $native['buttons'][0]['name']);
        $payload = json_decode($native['messageParamsJson'], true, 128, JSON_THROW_ON_ERROR);
        $graph = $payload['payload']['data'];
        self::assertSame('_mvo_form_', $graph['root']);
        self::assertSame(['cadastro','nome'], $graph['components'][0]['children']);
        self::assertSame('InputFields', $graph['components'][1]['component']);
        self::assertSame('Enviar', $graph['components'][1]['button']);
        self::assertSame($content['steps'][0]['fields'], $graph['components'][1]['fields']);
        self::assertSame('ChoicePicker', $graph['components'][2]['component']);
        self::assertArrayNotHasKey('platform', $payload);
        self::assertArrayNotHasKey('flow_id', $payload);
        $reply = ContentDecoder::formResponse(['interactiveResponseMessage'=>['nativeFlowResponseMessage'=>[
            'name'=>'form_message','version'=>3,'paramsJson'=>'{"name":"Maik","_mvo_form":"","nome":"maik"}',
        ]]]);
        self::assertSame('Maik', $reply['params']->name);
        self::assertObjectNotHasProperty('cadastro', $reply['params']);
        self::assertSame('', $reply['params']->_mvo_form);
    }

    public function testAndroidDefaultLimitCountsUtf16AndButtonDefaultsToCta(): void
    {
        foreach (['',str_repeat('a',128),str_repeat('😀',64)] as $default) {
            $content = self::androidContent();
            unset($content['steps'][0]['button']);
            $content['steps'][0]['fields'][0]['default'] = $default;
            $native = NativeFormBuilder::build($content)['interactiveMessage']['nativeFlowMessage'];
            $component = json_decode($native['messageParamsJson'], true, 128, JSON_THROW_ON_ERROR)['payload']['data']['components'][1];
            self::assertSame($default, $component['fields'][0]['default']);
            self::assertSame('Responder', $component['button']);
        }
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidAndroidContents(): iterable
    {
        $valid = self::androidContent(); $step = $valid['steps'][0]; $field = $step['fields'][0];
        foreach ([null,'ios','web',false] as $platform) yield 'invalid platform '.json_encode($platform)=>[[...$valid,'platform'=>$platform]];
        $portable = $valid; unset($portable['platform']); yield 'no implicit Android conversion'=>[$portable];
        yield 'explicit portable cannot render input'=>[[...$valid,'platform'=>'portable']];
        foreach ([null,'InputFields','TextInput','url'] as $kind) yield 'invalid kind '.json_encode($kind)=>[[...$valid,'steps'=>[[...$step,'kind'=>$kind]]]];
        foreach ([null,[],['x'=>$field],array_fill(0,6,$field),[new \stdClass()]] as $i=>$fields) yield 'fields '.$i=>[[...$valid,'steps'=>[[...$step,'fields'=>$fields]]]];
        foreach ([null,'email','number','TEXT'] as $type) yield 'field type '.json_encode($type)=>[[...$valid,'steps'=>[[...$step,'fields'=>[[...$field,'type'=>$type]]]]]];
        foreach ([null,0,'true'] as $i=>$required) yield 'required '.$i=>[[...$valid,'steps'=>[[...$step,'fields'=>[[...$field,'required'=>$required]]]]]];
        $missing = $field; unset($missing['required']); yield 'required field missing'=>[[...$valid,'steps'=>[[...$step,'fields'=>[$missing]]]]];
        foreach ([null,1,false,"\xFF",str_repeat('a',129),str_repeat('😀',65)] as $i=>$default) yield 'default '.$i=>[[...$valid,'steps'=>[[...$step,'fields'=>[[...$field,'default'=>$default]]]]]];
        foreach (['url','flow_id','inputType','options'] as $extra) yield 'field unsupported '.$extra=>[[...$valid,'steps'=>[[...$step,'fields'=>[[...$field,$extra=>'x']]]]]];
        yield 'input step does not accept choice options'=>[[...$valid,'steps'=>[[...$step,'options'=>[]]]]];
        yield 'input button too long'=>[[...$valid,'steps'=>[[...$step,'button'=>str_repeat('á',31)]]]];
        yield 'input duplicate field IDs'=>[[...$valid,'steps'=>[[...$step,'fields'=>[$field,$field]]]]];
        yield 'field collides with component ID'=>[[...$valid,'steps'=>[[...$step,'fields'=>[[...$field,'id'=>'cadastro']]]]]];
        yield 'choice ID collides with previous input field'=>[[...$valid,'steps'=>[$step,[...self::content()['steps'][0],'id'=>'name']]]];
        yield 'input field collides with previous choice ID'=>[[...$valid,'steps'=>[self::content()['steps'][0],[...$step,'fields'=>[[...$field,'id'=>'nome']]]]]];
        yield 'input field collision across input steps'=>[[...$valid,'steps'=>[$step,[...$step,'id'=>'cadastro2']]]];
    }

    #[DataProvider('invalidAndroidContents')]
    public function testRejectsInputShapeAndCrossPlatformOrIdConflicts(array $content): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NativeFormBuilder::build($content);
    }

    public function testTypedFormUsesNativeGraphAndPreservesCallerFieldIds(): void
    {
        $content = self::content();
        $content['title'] = 'Cadastro MVO'; $content['footer'] = 'Atendimento';
        $content['steps'][] = ['id'=>'serviços.0','label'=>'Serviços','selection'=>'multiple',
            'options'=>[['id'=>'hosting','title'=>'Hospedagem'],['id'=>'whatsapp','title'=>'WhatsApp']]];
        $original = $content;
        $built = ContentBuilder::build($content, '5511000000000@s.whatsapp.net');
        $decoded = ContentDecoder::decode($built['bytes']);
        self::assertSame($original, $content);
        self::assertSame('interactive', $decoded['kind']);
        self::assertSame('Cadastro MVO', $decoded['data']['header']['title']);
        self::assertSame('Atendimento', $decoded['data']['footer']['text']);
        $native = $decoded['data']['nativeFlowMessage'];
        self::assertSame(1, $native['messageVersion']);
        self::assertCount(1, $native['buttons']);
        self::assertSame('form_message', $native['buttons'][0]['name']);
        self::assertSame(['display_text'=>'Responder'], json_decode($native['buttons'][0]['buttonParamsJson'], true));
        $params = json_decode($native['messageParamsJson'], true, 128, JSON_THROW_ON_ERROR);
        self::assertSame('2', $params['version']);
        self::assertSame('a2ui_form', $params['payload']['type']);
        $graph = $params['payload']['data'];
        self::assertSame($graph['root'], $graph['components'][0]['id']);
        self::assertSame(['nome','serviços.0'], $graph['components'][0]['children']);
        self::assertSame('form', $graph['components'][0]['component']);
        self::assertSame('ChoicePicker', $graph['components'][1]['component']);
        self::assertSame('mutuallyExclusive', $graph['components'][1]['variant']);
        self::assertSame('multipleSelection', $graph['components'][2]['variant']);
        self::assertTrue($graph['components'][1]['allow_custom_answer']);
        self::assertFalse($graph['components'][2]['allow_custom_answer']);
        self::assertSame('Responder', $graph['components'][1]['button']);
        self::assertSame($graph['components'][1]['label'], $graph['components'][1]['title']);
        self::assertStringNotContainsString('flow_id', $native['messageParamsJson']);
        self::assertArrayNotHasKey('parsedFlowResponse', $decoded);
        self::assertArrayNotHasKey('parsedFormResponse', $decoded);
    }

    public function testRootCollisionAndNumericIdsDoNotRewriteResponseKeys(): void
    {
        $content = self::content(); $content['steps'][0]['id'] = '_mvo_form';
        $content['steps'][] = [...$content['steps'][0],'id'=>'0'];
        $native = NativeFormBuilder::build($content)['interactiveMessage']['nativeFlowMessage'];
        $graph = json_decode($native['messageParamsJson'], true, 128, JSON_THROW_ON_ERROR)['payload']['data'];
        self::assertSame('_mvo_form_', $graph['root']);
        self::assertSame(['_mvo_form','0'], $graph['components'][0]['children']);
        self::assertSame('_mvo_form', $graph['components'][1]['id']);
        self::assertSame('0', $graph['components'][2]['id']);
    }

    public function testContextStillUsesNormalQuoteAndMentionPipeline(): void
    {
        $content = [...self::content(),'quote'=>['id'=>'original','message'=>['conversation'=>'Olá']],
            'mentions'=>['5511999999999@s.whatsapp.net']];
        $decoded = ContentDecoder::decode(ContentBuilder::build($content, '5511000000000@s.whatsapp.net')['bytes']);
        self::assertSame('original', $decoded['context']['stanzaId']);
        self::assertSame(['conversation'=>'Olá'], $decoded['context']['quotedMessage']);
        self::assertSame(['5511999999999@s.whatsapp.net'], $decoded['context']['mentionedJid']);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidContents(): iterable
    {
        $valid = self::content(); $step = $valid['steps'][0];
        foreach (['flow_id','form_id','flow_json','flow_token','flow_data_endpoint','url','data'] as $field) {
            yield 'unsupported '.$field=>[[...$valid,$field=>'value']];
        }
        yield 'wrong distinct type'=>[[...$valid,'type'=>'flow']];
        foreach (['text','cta'] as $field) foreach ([null,[],false,'','  ',"\xFF"] as $i=>$value) {
            yield $field.' invalid '.$i=>[[...$valid,$field=>$value]];
        }
        yield 'long CTA'=>[[...$valid,'cta'=>str_repeat('á',31)]];
        yield 'long text'=>[[...$valid,'text'=>str_repeat('a',65537)]];
        foreach (['title','footer'] as $field) yield $field.' invalid'=>[[...$valid,$field=>null]];
        foreach ([null,[],['key'=>$step],array_fill(0,4,$step),[new \stdClass()]] as $i=>$steps) {
            yield 'invalid steps '.$i=>[[...$valid,'steps'=>$steps]];
        }
        yield 'duplicate step IDs'=>[[...$valid,'steps'=>[$step,$step]]];
        foreach (['id','label'] as $field) foreach ([null,'',"\xFF"] as $i=>$value) {
            yield 'invalid step '.$field.' '.$i=>[[...$valid,'steps'=>[[...$step,$field=>$value]]]];
        }
        yield 'long step ID'=>[[...$valid,'steps'=>[[...$step,'id'=>str_repeat('a',129)]]]];
        foreach ([null,'text','SINGLE',true] as $i=>$value) yield 'selection '.$i=>[[...$valid,'steps'=>[[...$step,'selection'=>$value]]]];
        foreach ([null,0,'true',[]] as $i=>$value) yield 'custom answer '.$i=>[[...$valid,'steps'=>[[...$step,'allow_custom_answer'=>$value]]]];
        foreach (['default_id','url','type','button'] as $field) yield 'unsupported step '.$field=>[[...$valid,'steps'=>[[...$step,$field=>'value']]]];
        foreach ([null,[],['key'=>$step['options'][0]],array_fill(0,6,$step['options'][0]),[new \stdClass()]] as $i=>$value) {
            yield 'invalid options '.$i=>[[...$valid,'steps'=>[[...$step,'options'=>$value]]]];
        }
        yield 'duplicate option IDs'=>[[...$valid,'steps'=>[[...$step,'options'=>[$step['options'][0],$step['options'][0]]]]]];
        yield 'unsupported option URL'=>[[...$valid,'steps'=>[[...$step,'options'=>[['id'=>'a','title'=>'A','url'=>'https://example.com']]]]]];
        yield 'long option title'=>[[...$valid,'steps'=>[[...$step,'options'=>[['id'=>'a','title'=>str_repeat('a',1025)]]]]]];
    }

    #[DataProvider('invalidContents')]
    public function testRejectsUnsupportedOrMalformedNativeForms(array $content): void
    {
        $this->expectException(\InvalidArgumentException::class);
        NativeFormBuilder::build($content);
    }

    /** @return iterable<string,array{int}> */
    public static function replyVersions(): iterable { yield 'Web'=>[1]; yield 'Android'=>[3]; }

    #[DataProvider('replyVersions')]
    public function testNativeRepliesKeepRealVersionAndJsonObjectShape(int $version): void
    {
        $json = '{"0":"zero","nome":"Maik","serviços":["hosting","Outro"],"empty":{},"nested":{"accepted":true}}';
        $message = ['interactiveResponseMessage'=>['body'=>['text'=>'Enviado'],
            'nativeFlowResponseMessage'=>['name'=>'form_message','version'=>$version,'paramsJson'=>$json],
            'contextInfo'=>['stanzaId'=>'real-form-id','participant'=>'5511000000000@s.whatsapp.net']]];
        $wrapped = ['ephemeralMessage'=>['message'=>['deviceSentMessage'=>['destinationJid'=>'5511000000000@s.whatsapp.net','message'=>$message]]]];
        $decoded = ContentDecoder::decode(SchemaCodec::encode('Message', $wrapped));
        $reply = $decoded['parsedFormResponse'];
        self::assertSame('form_message', $reply['name']);
        self::assertSame($version, $reply['version']);
        self::assertInstanceOf(\stdClass::class, $reply['params']);
        self::assertSame('zero', $reply['params']->{'0'});
        self::assertSame('Maik', $reply['params']->nome);
        self::assertSame(['hosting','Outro'], $reply['params']->{'serviços'});
        self::assertInstanceOf(\stdClass::class, $reply['params']->empty);
        self::assertTrue($reply['params']->nested->accepted);
        self::assertSame($json, $decoded['data']['nativeFlowResponseMessage']['paramsJson']);
        self::assertSame('real-form-id', $decoded['context']['stanzaId']);
        self::assertArrayNotHasKey('flowToken', $reply);
        self::assertArrayNotHasKey('parsedFlowResponse', $decoded);
    }

    public function testMalformedResponsesStayRawAndOtherProtocolsStayDistinct(): void
    {
        foreach (['null','[]','true','0','"text"','{"bad":'] as $json) {
            $message = ['interactiveResponseMessage'=>['nativeFlowResponseMessage'=>['name'=>'form_message','version'=>3,'paramsJson'=>$json]]];
            $decoded = ContentDecoder::decode(SchemaCodec::encode('Message', $message));
            self::assertArrayNotHasKey('parsedFormResponse', $decoded);
            self::assertSame($json, $decoded['data']['nativeFlowResponseMessage']['paramsJson']);
        }
        self::assertNull(ContentDecoder::formResponse(['interactiveResponseMessage'=>[
            'nativeFlowResponseMessage'=>['name'=>'galaxy_message','version'=>3,'paramsJson'=>'{}']]]));
        $empty = ContentDecoder::formResponse(['interactiveResponseMessage'=>[
            'nativeFlowResponseMessage'=>['name'=>'form_message','version'=>1,'paramsJson'=>'{}']]]);
        self::assertSame('{}', json_encode($empty['params']));
    }
}
