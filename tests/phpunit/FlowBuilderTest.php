<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\{ContentBuilder,ContentDecoder,FlowBuilder};
use MVO\Protocol\SchemaCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class FlowBuilderTest extends TestCase
{
    private const TO = '5511999999999@s.whatsapp.net';

    /** @return array<string,mixed> */
    private static function content(): array
    {
        return ['type'=>'flow','text'=>'Preencha seu cadastro','flow_id'=>'123456789012345',
            'flow_token'=>'pedido-42','flow_cta'=>'Abrir cadastro','screen'=>'WELCOME'];
    }

    /** @return array<string,mixed> */
    private static function inlineFlow(): array
    {
        return ['version'=>'7.3','screens'=>[['id'=>'WELCOME','title'=>'Cadastro MVO','terminal'=>true,'success'=>true,
            'data'=>new \stdClass(),'layout'=>['type'=>'SingleColumnLayout','children'=>[
                ['type'=>'Form','name'=>'form','children'=>[
                    ['type'=>'TextInput','name'=>'name','label'=>'Nome','required'=>true],
                    ['type'=>'Footer','label'=>'Enviar','on-click-action'=>['name'=>'complete','payload'=>['name'=>'${form.name}']]],
                ]],
            ]]]]];
    }

    /** @return array<string,mixed> */
    private static function inlineContent(): array
    {
        $content = self::content();
        unset($content['flow_id'], $content['screen']);
        return [...$content,'flow_json'=>self::inlineFlow()];
    }

    /** @param array<string,mixed> $content */
    private static function params(array $content): \stdClass
    {
        $built = FlowBuilder::build($content);
        return json_decode($built['interactiveMessage']['nativeFlowMessage']['buttons'][0]['buttonParamsJson'], false, 512, JSON_THROW_ON_ERROR);
    }

    public function testInlineFormWithoutRemoteIdKeepsRealJsonObjectAndInfersFirstScreen(): void
    {
        $content = self::inlineContent();
        $before = json_encode($content);
        $built = ContentBuilder::build($content, self::TO);
        $decoded = ContentDecoder::decode($built['bytes']);
        $button = $decoded['data']['nativeFlowMessage']['buttons'][0];
        $params = json_decode($button['buttonParamsJson'], false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('galaxy_message', $button['name']);
        self::assertSame('navigate', $params->flow_action);
        self::assertSame('WELCOME', $params->flow_action_payload->screen);
        self::assertInstanceOf(\stdClass::class, $params->flow_json);
        self::assertSame('7.3', $params->flow_json->version);
        self::assertSame('${form.name}', $params->flow_json->screens[0]->layout->children[0]->children[1]->{'on-click-action'}->payload->name);
        self::assertSame('{}', json_encode($params->flow_json->screens[0]->data));
        self::assertMatchesRegularExpression('/^1[0-9]{15}$/D', $params->flow_id);
        self::assertLessThanOrEqual(9007199254740991, (int)$params->flow_id);
        self::assertSame(32, strlen(base64_decode($params->flow_qpl_message_id, true)));
        self::assertSame(32, strlen(base64_decode($params->flow_wam_message_id, true)));
        self::assertNotSame($params->flow_qpl_message_id, $params->flow_wam_message_id);
        self::assertSame($before, json_encode($content), 'Nested user input must not be modified');
    }

    public function testInlineIdentityIsStableAcrossJsonObjectKeyOrderAndRetries(): void
    {
        $content = self::inlineContent();
        $expected = self::params($content)->flow_id;
        $flow = self::inlineFlow();
        $flow = ['screens'=>$flow['screens'],'version'=>$flow['version']];
        $flow['screens'][0] = array_reverse($flow['screens'][0], true);
        $flow['screens'][0]['layout'] = array_reverse($flow['screens'][0]['layout'], true);
        self::assertSame($expected, self::params([...$content,'flow_json'=>$flow])->flow_id);
        self::assertSame($expected, self::params([...$content,'flow_json'=>json_encode($flow, JSON_THROW_ON_ERROR)])->flow_id);
        self::assertSame($expected, self::params([...$content,'flow_json'=>json_decode(json_encode($flow, JSON_THROW_ON_ERROR))])->flow_id);
        self::assertSame($expected, self::params([...$content,'flow_token'=>'retry-correlation-token'])->flow_id);
        $flow['screens'][0]['title'] = 'Outro formulário';
        self::assertNotSame($expected, self::params([...$content,'flow_json'=>$flow])->flow_id);
    }

    public function testInlineTrackingIdsArePerMessageAndCannotBeOverridden(): void
    {
        $content = self::inlineContent();
        $first = self::params($content);
        $second = self::params($content);
        self::assertSame($first->flow_id, $second->flow_id);
        self::assertNotSame($first->flow_qpl_message_id, $second->flow_qpl_message_id);
        self::assertNotSame($first->flow_wam_message_id, $second->flow_wam_message_id);
        foreach (['flow_qpl_message_id','flow_wam_message_id'] as $field) {
            try { FlowBuilder::build([...$content,$field=>'external-override']); self::fail('External tracking overrides must be rejected'); }
            catch (\InvalidArgumentException $error) { self::assertStringContainsString($field, $error->getMessage()); }
        }
    }

    public function testExchangeDefaultsToRegisteredDataApiMetadataAndNavigateOptsInExplicitly(): void
    {
        $exchange = self::content(); unset($exchange['screen']);
        foreach (['flow-exchange','flow.exchange'] as $type) {
            $params = self::params([...$exchange,'type'=>$type]);
            self::assertSame('data_exchange', $params->flow_action);
            self::assertEquals((object)['data_api_version'=>'3.0'], $params->flow_metadata);
            self::assertObjectNotHasProperty('flow_action_payload', $params);
            self::assertObjectNotHasProperty('data_api_version', $params, 'The version belongs to flow_metadata on the wire');
        }
        $generic = self::params([...$exchange,'flow_action'=>'data_exchange','data_api_version'=>'3.0']);
        self::assertSame('3.0', $generic->flow_metadata->data_api_version);
        $navigate = self::params([...self::content(),'data_api_version'=>'3.0']);
        self::assertSame('navigate', $navigate->flow_action);
        self::assertSame('WELCOME', $navigate->flow_action_payload->screen);
        self::assertSame('3.0', $navigate->flow_metadata->data_api_version);
        self::assertObjectNotHasProperty('flow_metadata', self::params(self::content()), 'A static registered Flow does not opt into endpoint metadata');
        self::assertObjectNotHasProperty('flow_metadata', self::params(self::inlineContent()));
    }

    public static function invalidDataApiContents(): iterable
    {
        foreach ([null,3,3.0,'3','2.0','4.0','03.0','3.0 ',[],new \stdClass()] as $i=>$version) {
            yield 'invalid registered version '.$i=>[[...self::content(),'data_api_version'=>$version]];
        }
        yield 'inline top-level data version'=>[[...self::inlineContent(),'data_api_version'=>'3.0']];
        yield 'raw endpoint metadata'=>[[...self::content(),'flow_metadata'=>['data_api_version'=>'3.0']]];
    }

    #[DataProvider('invalidDataApiContents')]
    public function testDataApiVersionAndEndpointMetadataFailBeforeBuildingWireContent(array $content): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FlowBuilder::build($content);
    }

    public function testInlinePublishedIdAndExplicitInitialScreenDataArePreserved(): void
    {
        $flow = self::inlineFlow();
        $flow['screens'][] = [...$flow['screens'][0],'id'=>'SECOND'];
        $flow['routing_model'] = ['WELCOME'=>['SECOND'],'SECOND'=>[]];
        $payload = (object)['screen'=>'SECOND','data'=>(object)['name'=>'Ana','optional'=>new \stdClass()]];
        $content = [...self::inlineContent(),'flow_id'=>'123456789012345','flow_json'=>$flow,'flow_action_payload'=>$payload];
        $params = self::params($content);
        self::assertSame('123456789012345', $params->flow_id);
        self::assertSame('SECOND', $params->flow_action_payload->screen);
        self::assertSame('Ana', $params->flow_action_payload->data->name);
        self::assertSame('{}', json_encode($params->flow_action_payload->data->optional));
        self::assertSame(['SECOND'], $params->flow_json->routing_model->WELCOME);
        self::assertSame([], $params->flow_json->routing_model->SECOND);
    }

    public function testInlinePhpEmptyDataAndRoutingModelHaveObjectWireShape(): void
    {
        $flow = self::inlineFlow();
        $flow['routing_model'] = [];
        $flow['screens'][0]['data'] = [];
        $params = self::params([...self::inlineContent(),'flow_json'=>$flow,'flow_action_payload'=>['data'=>[]]]);
        self::assertSame('WELCOME', $params->flow_action_payload->screen);
        self::assertSame('{}', json_encode($params->flow_action_payload->data));
        self::assertSame('{}', json_encode($params->flow_json->routing_model));
        self::assertSame('{}', json_encode($params->flow_json->screens[0]->data));
    }

    public static function invalidInlineContents(): iterable
    {
        $valid = self::inlineContent();
        foreach ([null,true,7,[],['invalid list'],'[]','null','true','{"version":'] as $i=>$value) {
            yield 'invalid JSON root '.$i=>[[...$valid,'flow_json'=>$value]];
        }
        $flow = self::inlineFlow();
        foreach ([null,7,7.3,'7','0.1','unknown'] as $i=>$value) yield 'version '.$i=>[[...$valid,'flow_json'=>[...$flow,'version'=>$value]]];
        foreach ([null,[],new \stdClass(),['WELCOME'],array_fill(0,101,$flow['screens'][0])] as $i=>$screens) {
            yield 'screens '.$i=>[[...$valid,'flow_json'=>[...$flow,'screens'=>$screens]]];
        }
        foreach (['','1FIRST','WITH/SLASH',str_repeat('A',65)] as $i=>$id) {
            yield 'screen ID '.$i=>[[...$valid,'flow_json'=>[...$flow,'screens'=>[[...$flow['screens'][0],'id'=>$id]]]]];
        }
        yield 'case insensitive screen collision'=>[[...$valid,'flow_json'=>[...$flow,'screens'=>[$flow['screens'][0],[...$flow['screens'][0],'id'=>'welcome']]]]];
        foreach ([null,[],['type'=>'SingleColumnLayout'],['type'=>'SingleColumnLayout','children'=>[]],['type'=>'SingleColumnLayout','children'=>new \stdClass()]] as $i=>$layout) {
            yield 'screen layout '.$i=>[[...$valid,'flow_json'=>[...$flow,'screens'=>[[...$flow['screens'][0],'layout'=>$layout]]]]];
        }
        yield 'screen data must be object'=>[[...$valid,'flow_json'=>[...$flow,'screens'=>[[...$flow['screens'][0],'data'=>[1]]]]]];
        foreach ([['WELCOME'],['UNKNOWN'=>[]],['WELCOME'=>['UNKNOWN']],['WELCOME'=>'WELCOME']] as $i=>$routing) {
            yield 'routing model '.$i=>[[...$valid,'flow_json'=>[...$flow,'routing_model'=>$routing]]];
        }
        yield 'unknown initial screen'=>[[...$valid,'screen'=>'UNKNOWN']];
        yield 'unknown payload screen'=>[[...$valid,'flow_action_payload'=>['screen'=>'UNKNOWN']]];
        yield 'explicit null screen'=>[[...$valid,'screen'=>null]];
        yield 'explicit null payload screen'=>[[...$valid,'flow_action_payload'=>['screen'=>null]]];
        yield 'explicit null ID'=>[[...$valid,'flow_id'=>null]];
        yield 'too long ID'=>[[...$valid,'flow_id'=>str_repeat('1',65)]];
        yield 'invalid JSON float'=>[[...$valid,'flow_json'=>[...$flow,'value'=>NAN]]];
        yield 'inline byte limit'=>[[...$valid,'flow_json'=>[...$flow,'value'=>str_repeat('x',262144)]]];
        yield 'inline string byte limit'=>[[...$valid,'flow_json'=>str_repeat(' ',262145)]];
        foreach (['data_channel_uri'=>'https://example.com/endpoint','data_api_version'=>'3.0'] as $field=>$value) {
            yield 'inline endpoint '.$field=>[[...$valid,'flow_json'=>[...$flow,$field=>$value]]];
        }
        $exchange = [...$flow,'screens'=>[[...$flow['screens'][0],'layout'=>['type'=>'SingleColumnLayout','children'=>[
            ['type'=>'Footer','label'=>'Enviar','on-click-action'=>['name'=>'data_exchange','payload'=>new \stdClass()]],
        ]]]]];
        yield 'nested data exchange action'=>[[...$valid,'flow_json'=>$exchange]];
        yield 'inline exchange type'=>[[...$valid,'type'=>'flow-exchange']];
        yield 'inline exchange action'=>[[...$valid,'flow_action'=>'data_exchange']];
        yield 'inline exchange cannot override registered endpoint'=>[[...$valid,'flow_id'=>'123456789','flow_action'=>'data_exchange']];
    }

    #[DataProvider('invalidInlineContents')]
    public function testInvalidInlineFormFailsBeforeProducingWireContent(array $content): void
    {
        $this->expectException(\InvalidArgumentException::class);
        FlowBuilder::build($content);
    }

    public function testTypedFlowProducesGalaxyContractAndCompanionBusinessEnvelope(): void
    {
        $built = ContentBuilder::build(self::content(), self::TO, ['nowMs'=>1700000000000]);
        $decoded = ContentDecoder::decode($built['bytes']);
        self::assertSame('interactive', $decoded['kind']);
        self::assertSame('Preencha seu cadastro', $decoded['data']['body']['text']);
        self::assertSame(1, $decoded['data']['nativeFlowMessage']['messageVersion']);
        $buttons = $decoded['data']['nativeFlowMessage']['buttons'];
        self::assertCount(1, $buttons);
        self::assertSame('galaxy_message', $buttons[0]['name']);
        self::assertEquals((object)['flow_message_version'=>'3','flow_id'=>'123456789012345','flow_token'=>'pedido-42',
            'flow_cta'=>'Abrir cadastro','flow_action'=>'navigate','flow_action_payload'=>(object)['screen'=>'WELCOME']],
            json_decode($buttons[0]['buttonParamsJson']));
        self::assertArrayNotHasKey('messageParamsJson', $decoded['data']['nativeFlowMessage']);
        self::assertSame(['type'=>'text','device_fanout'=>'false'], $built['attrs']);
        self::assertSame([], $built['encAttrs']);
        $biz = $built['companionNodes'][0];
        self::assertSame(['actual_actors'=>'2','host_storage'=>'2','privacy_mode_ts'=>'1700000000'], $biz->attrs);
        self::assertSame(['type'=>'native_flow','v'=>'1'], $biz->child('interactive')->attrs);
        self::assertSame(['v'=>'9','name'=>'mixed'], $biz->child('interactive')->child('native_flow')->attrs);
        self::assertNull($biz->child('quality_control'));
    }

    public static function authoringVariants(): iterable
    {
        yield 'default navigate'=>['flow','navigate'];
        yield 'navigate'=>['flow-navigate','navigate'];
        yield 'navigate dotted'=>['flow.navigate','navigate'];
        yield 'exchange'=>['flow-exchange','data_exchange'];
        yield 'exchange dotted'=>['flow.exchange','data_exchange'];
    }

    #[DataProvider('authoringVariants')]
    public function testAuthoringVariantsHaveOneRealWireButton(string $type, string $action): void
    {
        $content = [...self::content(),'type'=>$type];
        if ($action === 'data_exchange') unset($content['screen']);
        $message = FlowBuilder::build($content);
        $button = $message['interactiveMessage']['nativeFlowMessage']['buttons'][0];
        $params = json_decode($button['buttonParamsJson'], false, 512, JSON_THROW_ON_ERROR);
        self::assertSame('galaxy_message', $button['name']);
        self::assertSame($action, $params->flow_action);
        self::assertSame($action === 'navigate', property_exists($params, 'flow_action_payload'));
    }

    public function testNavigateDataRemainsObjectIncludingEmptyAndNestedObjects(): void
    {
        foreach ([[],new \stdClass()] as $empty) {
            $built = FlowBuilder::build([...self::content(),'data'=>$empty]);
            $params = json_decode($built['interactiveMessage']['nativeFlowMessage']['buttons'][0]['buttonParamsJson']);
            self::assertInstanceOf(\stdClass::class, $params->flow_action_payload->data);
            self::assertSame('{}', json_encode($params->flow_action_payload->data));
        }
        $data = (object)['order'=>(object)['id'=>42,'optional'=>new \stdClass()], 'items'=>[['id'=>'A']], 'enabled'=>true];
        $payload = (object)['screen'=>'ORDER','data'=>$data];
        $content = self::content(); unset($content['screen']);
        $before = json_encode($payload);
        $built = FlowBuilder::build([...$content,'flow_action_payload'=>$payload]);
        $params = json_decode($built['interactiveMessage']['nativeFlowMessage']['buttons'][0]['buttonParamsJson']);
        self::assertJsonStringEqualsJsonString(json_encode($data), json_encode($params->flow_action_payload->data));
        self::assertSame($before, json_encode($payload), 'Authoring must not mutate the caller payload');
    }

    public function testAliasesDraftModeUnicodeHeaderFooterAndQuoteSurviveEncoding(): void
    {
        $content = self::content(); unset($content['flow_cta']);
        $content += ['cta'=>str_repeat('🚀',30),'action'=>'navigate','flow_message_version'=>3,'mode'=>'draft',
            'title'=>'Cadastro MVO','footer'=>'Seus dados de contato',
            'quote'=>['key'=>['id'=>'PARENT','remoteJid'=>self::TO,'fromMe'=>false],'message'=>['conversation'=>'Original']]];
        $built = ContentBuilder::build($content, self::TO);
        $message = SchemaCodec::decode('Message', $built['bytes']);
        $interactive = $message['interactiveMessage'];
        self::assertSame('Cadastro MVO', $interactive['header']['title']);
        self::assertFalse($interactive['header']['hasMediaAttachment']);
        self::assertSame('Seus dados de contato', $interactive['footer']['text']);
        self::assertSame('PARENT', $interactive['contextInfo']['stanzaId']);
        self::assertSame('Original', $interactive['contextInfo']['quotedMessage']['conversation']);
        $params = json_decode($interactive['nativeFlowMessage']['buttons'][0]['buttonParamsJson']);
        self::assertSame(str_repeat('🚀',30), $params->flow_cta);
        self::assertSame('3', $params->flow_message_version);
        self::assertSame('draft', $params->mode);
        self::assertSame(32, strlen($message['messageContextInfo']['messageSecret']));
    }

    public static function invalidContents(): iterable
    {
        $valid = self::content();
        foreach (['text','flow_id','flow_token','flow_cta','screen'] as $field) {
            $content = $valid; unset($content[$field]); yield 'missing '.$field=>[$content];
        }
        yield 'numeric ID must be string'=>[[...$valid,'flow_id'=>123]];
        yield 'ID not digits'=>[[...$valid,'flow_id'=>'flow-123']];
        yield 'blank token'=>[[...$valid,'flow_token'=>' ']];
        yield 'invalid UTF8 token'=>[[...$valid,'flow_token'=>"\xff"]];
        yield 'CTA too long'=>[[...$valid,'flow_cta'=>str_repeat('🚀',31)]];
        yield 'invalid mode'=>[[...$valid,'mode'=>'preview']];
        yield 'unsupported version'=>[[...$valid,'flow_message_version'=>'2']];
        yield 'explicit null version'=>[[...$valid,'flow_message_version'=>null]];
        yield 'unknown action'=>[[...$valid,'flow_action'=>'complete']];
        yield 'explicit null action'=>[[...$valid,'flow_action'=>null]];
        yield 'explicit null action alias'=>[[...$valid,'action'=>null]];
        foreach (['flow_json','flow_name','flow_data_endpoint','flow_metadata','flow_version_id','flow_json_fresh_fetched',
            'flow_qpl_message_id','flow_wam_message_id','creation_source','expire_time'] as $field) {
            yield 'unsupported '.$field=>[[...$valid,$field=>'unsupported']];
        }
        yield 'navigate conflicts with action'=>[[...$valid,'type'=>'flow-navigate','flow_action'=>'data_exchange']];
        yield 'exchange conflicts with action'=>[[...$valid,'type'=>'flow-exchange','flow_action'=>'navigate']];
        yield 'conflicting CTA alias'=>[[...$valid,'cta'=>'Outro']];
        yield 'conflicting action alias'=>[[...$valid,'flow_action'=>'navigate','action'=>'data_exchange']];
        yield 'list data is not object'=>[[...$valid,'data'=>['A','B']]];
        yield 'scalar data is not object'=>[[...$valid,'data'=>'{}']];
        yield 'invalid JSON data'=>[[...$valid,'data'=>['number'=>NAN]]];
        yield 'mixed payload and screen'=>[[...$valid,'flow_action_payload'=>['screen'=>'WELCOME']]];
        $payloadContent = $valid; unset($payloadContent['screen']);
        yield 'payload list'=>[[...$payloadContent,'flow_action_payload'=>['WELCOME']]];
        yield 'payload screen required'=>[[...$payloadContent,'flow_action_payload'=>[]]];
        yield 'payload unknown fields'=>[[...$payloadContent,'flow_action_payload'=>['screen'=>'WELCOME','unexpected'=>true]]];
        yield 'payload data list'=>[[...$payloadContent,'flow_action_payload'=>['screen'=>'WELCOME','data'=>[1]]]];
        $exchange = [...$payloadContent,'type'=>'flow-exchange'];
        foreach (['screen'=>'WELCOME','data'=>[],'flow_action_payload'=>[]] as $key=>$value) {
            yield 'exchange rejects '.$key=>[[...$exchange,$key=>$value]];
        }
    }

    #[DataProvider('invalidContents')]
    public function testInvalidFlowContractFailsBeforeProducingWireContent(array $content): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContentBuilder::build($content, self::TO);
    }

    public static function replies(): iterable
    {
        yield 'legacy response'=>['flow',2,'{"flow_token":"pedido-42","name":"Ana","empty":{},"items":[1,2]}'];
        yield 'Galaxy version 2'=>['galaxy_message',2,'{"flow_token":"pedido-42","name":"Ana","empty":{}}'];
        yield 'Galaxy version 3'=>['galaxy_message',3,'{"flow_token":"pedido-42","wa_flow_response_params":{"flow_id":"123","flow_name":"Cadastro","response_message":"Pronto","empty":{}}}'];
    }

    #[DataProvider('replies')]
    public function testReplyParametersAreParsedWithoutLosingObjectsOrOriginalData(string $name, int $version, string $json): void
    {
        $body = ['body'=>['text'=>'Concluído','format'=>'EXTENSIONS_1'],
            'nativeFlowResponseMessage'=>['name'=>$name,'paramsJson'=>$json,'version'=>$version],
            'contextInfo'=>['stanzaId'=>'FLOW-MESSAGE','participant'=>self::TO]];
        $message = ['interactiveResponseMessage'=>$body];
        $decoded = ContentDecoder::decode(SchemaCodec::encode('Message', $message));
        $parsed = $decoded['parsedFlowResponse'];
        self::assertSame($name, $parsed['name']);
        self::assertSame($version, $parsed['version']);
        self::assertSame('pedido-42', $parsed['flowToken']);
        self::assertInstanceOf(\stdClass::class, $parsed['params']);
        self::assertSame($json, $decoded['data']['nativeFlowResponseMessage']['paramsJson']);
        self::assertSame('FLOW-MESSAGE', $decoded['context']['stanzaId']);
        if ($version === 3) {
            self::assertSame('Pronto', $parsed['response']->response_message);
            self::assertSame('{}', json_encode($parsed['response']->empty));
        } else self::assertSame('{}', json_encode($parsed['params']->empty));
        foreach (['viewOnceMessage','viewOnceMessageV2','viewOnceMessageV2Extension','ephemeralMessage','documentWithCaptionMessage','deviceSentMessage'] as $wrapper) {
            self::assertEquals($parsed, ContentDecoder::flowResponse([$wrapper=>['message'=>$message]]));
        }
    }

    public static function malformedReplies(): iterable
    {
        yield 'invalid JSON'=>['galaxy_message','{"broken":'];
        yield 'array JSON'=>['galaxy_message','[]'];
        yield 'scalar JSON'=>['galaxy_message','true'];
        yield 'null JSON'=>['galaxy_message','null'];
        yield 'other native button'=>['quick_reply','{"id":"A"}'];
    }

    #[DataProvider('malformedReplies')]
    public function testMalformedAndUnrelatedRepliesRetainRawDataWithoutParsedProjection(string $name, string $json): void
    {
        $message = ['interactiveResponseMessage'=>['nativeFlowResponseMessage'=>['name'=>$name,'paramsJson'=>$json,'version'=>2]]];
        self::assertNull(ContentDecoder::flowResponse($message));
        $decoded = ContentDecoder::decode(SchemaCodec::encode('Message', $message));
        self::assertArrayNotHasKey('parsedFlowResponse', $decoded);
        self::assertSame($json, $decoded['data']['nativeFlowResponseMessage']['paramsJson']);
    }

    public function testPureReplyParserIgnoresMalformedOrExcessiveWrappers(): void
    {
        self::assertNull(ContentDecoder::flowResponse(['ephemeralMessage'=>[]]));
        $message = ['interactiveResponseMessage'=>['nativeFlowResponseMessage'=>['name'=>'galaxy_message','paramsJson'=>'{}']]];
        for ($i=0; $i<11; ++$i) $message = ['ephemeralMessage'=>['message'=>$message]];
        self::assertNull(ContentDecoder::flowResponse($message));
    }
}
