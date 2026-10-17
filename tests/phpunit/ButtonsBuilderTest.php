<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Message\ButtonsBuilder;
use MVO\Protocol\SchemaCodec;

final class ButtonsBuilderTest extends TestCase
{
    /** @return array<string,mixed> */
    private static function body(): array
    {
        return ['contentText'=>'Escolha uma opção:', 'footerText'=>'Atendimento PHP', 'headerType'=>'EMPTY',
            'buttons'=>[['buttonId'=>'confirmar','buttonText'=>['displayText'=>'Confirmar ✅'],'type'=>'RESPONSE'],
                ['buttonId'=>'ajuda','buttonText'=>['displayText'=>'Ajuda'],'type'=>1]]];
    }

    public function testResponseButtonsBecomeNativeFlowWithStableIdsAndUnicode(): void
    {
        $body = self::body();
        $body['contextInfo'] = ['stanzaId'=>'parent','mentionedJid'=>['123@lid']];
        $message = ButtonsBuilder::build($body);
        self::assertArrayNotHasKey('buttonsMessage', $message);
        $interactive = $message['interactiveMessage'];
        self::assertSame(['text'=>$body['contentText']], $interactive['body']);
        self::assertSame(['text'=>$body['footerText']], $interactive['footer']);
        self::assertSame($body['contextInfo'], $interactive['contextInfo']);
        self::assertSame(['hasMediaAttachment'=>false], $interactive['header']);
        self::assertArrayNotHasKey('messageVersion', $interactive['nativeFlowMessage']);
        self::assertArrayNotHasKey('messageParamsJson', $interactive['nativeFlowMessage']);
        $buttons = $interactive['nativeFlowMessage']['buttons'];
        self::assertSame('quick_reply', $buttons[0]['name']);
        self::assertSame(['display_text'=>'Confirmar ✅','id'=>'confirmar'], json_decode($buttons[0]['buttonParamsJson'], true, flags:JSON_THROW_ON_ERROR));
        self::assertSame(['display_text'=>'Ajuda','id'=>'ajuda'], json_decode($buttons[1]['buttonParamsJson'], true, flags:JSON_THROW_ON_ERROR));
        self::assertEquals($message, SchemaCodec::decode('Message', SchemaCodec::encode('Message', $message)));
    }

    public function testMissingResponseTypeAndTextHeaderAreSupported(): void
    {
        $body = self::body(); unset($body['headerType'], $body['buttons'][0]['type']);
        $body['text'] = 'Título';
        $header = ButtonsBuilder::build($body)['interactiveMessage']['header'];
        self::assertSame(['hasMediaAttachment'=>false,'title'=>'Título'], $header);
    }

    /** @return iterable<string,array{string|int,string,array<string,mixed>}> */
    public static function preparedHeaders(): iterable
    {
        yield 'image'=>['IMAGE','imageMessage',['url'=>'https://mmg.whatsapp.net/image','mediaKey'=>str_repeat("\xff",32),'fileSha256'=>str_repeat('h',32)]];
        yield 'video'=>[5,'videoMessage',['url'=>'https://mmg.whatsapp.net/video','seconds'=>3]];
        yield 'document'=>['DOCUMENT','documentMessage',['fileName'=>'arquivo.pdf','mimetype'=>'application/pdf']];
        yield 'location'=>['LOCATION','locationMessage',['degreesLatitude'=>-23.5,'degreesLongitude'=>-46.6]];
    }

    /** @param array<string,mixed> $prepared */
    #[DataProvider('preparedHeaders')]
    public function testPreparedMediaHeadersAndTitlesArePreserved(string|int $type, string $field, array $prepared): void
    {
        $body = self::body(); $body['headerType'] = $type; $body[$field] = $prepared; $body['text'] = 'Título da mídia';
        $message = ButtonsBuilder::build($body);
        $header = $message['interactiveMessage']['header'];
        self::assertTrue($header['hasMediaAttachment']);
        self::assertSame($prepared, $header[$field]);
        self::assertSame('Título da mídia', $header['title']);
        self::assertEquals($message, SchemaCodec::decode('Message', SchemaCodec::encode('Message', $message)));
    }

    public function testNativeFlowInfoIsPreservedAndValidated(): void
    {
        $body = self::body(); $json = '{"display_text":"Abrir","url":"https://example.com"}';
        $body['buttons'][] = ['type'=>'NATIVE_FLOW','nativeFlowInfo'=>['name'=>'cta_url','paramsJson'=>$json]];
        $buttons = ButtonsBuilder::build($body)['interactiveMessage']['nativeFlowMessage']['buttons'];
        self::assertSame(['name'=>'cta_url','buttonParamsJson'=>$json], $buttons[2]);
        $body['buttons'][2]['type'] = 2;
        self::assertSame($buttons, ButtonsBuilder::build($body)['interactiveMessage']['nativeFlowMessage']['buttons']);
    }

    public function testTextFallbackPreservesHeaderBodyFooterAndUnicodeOptions(): void
    {
        $body = self::body(); $body['headerType'] = 'TEXT'; $body['text'] = 'Menu de opções';
        self::assertSame(['conversation'=>"Menu de opções\n\nEscolha uma opção:\n\n1. Confirmar ✅\n2. Ajuda\n\nResponda com o número da opção.\n\nAtendimento PHP"], ButtonsBuilder::textFallback($body));
        // The textual number maps to the existing order, even when IDs are not numeric.
        $body['buttons'][0]['buttonId'] = '0';
        self::assertStringContainsString('1. Confirmar ✅', ButtonsBuilder::textFallback($body)['conversation']);
    }

    public function testTextFallbackPreservesQuotedAndMentionedContext(): void
    {
        $body = self::body();
        $body['contextInfo'] = ['stanzaId'=>'original','participant'=>'123@lid','quotedMessage'=>['conversation'=>'Pergunta original'],'mentionedJid'=>['456@lid']];
        $fallback = ButtonsBuilder::textFallback($body);
        self::assertArrayNotHasKey('conversation', $fallback);
        self::assertSame($body['contextInfo'], $fallback['extendedTextMessage']['contextInfo']);
        self::assertStringContainsString('1. Confirmar ✅', $fallback['extendedTextMessage']['text']);
        self::assertEquals($fallback, SchemaCodec::decode('Message', SchemaCodec::encode('Message', $fallback)));
    }

    public function testTextFallbackAcceptsValidatedNativeQuickReplies(): void
    {
        $body = self::body(); unset($body['contentText'], $body['footerText']);
        $body['buttons'] = [['type'=>2,'nativeFlowInfo'=>['name'=>'quick_reply','paramsJson'=>'{"display_text":"Olá 👋","id":"boas_vindas"}']]];
        self::assertSame(['conversation'=>"1. Olá 👋\n\nResponda com o número da opção."], ButtonsBuilder::textFallback($body));
    }

    public function testTextFallbackRejectsActionsThatCannotBeRepresentedAsNumericReplies(): void
    {
        $body = self::body();
        $body['buttons'][] = ['type'=>2,'nativeFlowInfo'=>['name'=>'cta_url','paramsJson'=>'{"display_text":"Abrir","url":"https://example.com"}']];
        $this->expectException(\InvalidArgumentException::class);
        ButtonsBuilder::textFallback($body);
    }

    /** @param array<string,mixed> $prepared */
    #[DataProvider('preparedHeaders')]
    public function testTextFallbackRejectsPreparedMediaRatherThanDroppingIt(string|int $type, string $field, array $prepared): void
    {
        $body = self::body(); $body['headerType'] = $type; $body[$field] = $prepared;
        $this->expectException(\InvalidArgumentException::class);
        ButtonsBuilder::textFallback($body);
    }

    /** @return iterable<string,array{array<string,mixed>}> */
    public static function invalidBodies(): iterable
    {
        $body = self::body(); $body['buttons'] = []; yield 'empty buttons'=>[$body];
        $body = self::body(); $body['buttons'] = ['a'=>$body['buttons'][0]]; yield 'non-list buttons'=>[$body];
        $body = self::body(); $body['buttons'][] = $body['buttons'][0]; yield 'duplicate ids'=>[$body];
        $body = self::body(); $body['buttons'][0]['buttonId'] = ''; yield 'empty id'=>[$body];
        $body = self::body(); $body['buttons'][0]['buttonId'] = "\xff"; yield 'invalid UTF-8 id'=>[$body];
        $body = self::body(); $body['buttons'][0]['buttonText']['displayText'] = "\xff"; yield 'invalid UTF-8 label'=>[$body];
        $body = self::body(); $body['contentText'] = "\xff"; yield 'invalid UTF-8 body'=>[$body];
        $body = self::body(); $body['buttons'][0]['type'] = 4; yield 'unsupported type'=>[$body];
        $body = self::body(); $body['headerType'] = 'IMAGE'; yield 'missing media header'=>[$body];
        $body = self::body(); $body['headerType'] = 'TEXT'; yield 'missing text header'=>[$body];
        $body = self::body(); $body['imageMessage'] = ['url'=>'x']; yield 'inconsistent header'=>[$body];
        $body = self::body(); $body['text'] = 'title'; yield 'empty header with text'=>[$body];
        $body = self::body(); $body['headerType'] = 'UNKNOWN'; yield 'unknown header'=>[$body];
        $body = self::body(); $body['unexpected'] = true; yield 'unknown field'=>[$body];
        foreach (['{','[]','null','"text"'] as $json) {
            $body = self::body(); $body['buttons'][] = ['type'=>2,'nativeFlowInfo'=>['name'=>'cta_url','paramsJson'=>$json]];
            yield 'invalid params '.$json=>[$body];
        }
        $body = self::body(); $body['buttons'][] = ['type'=>2,'nativeFlowInfo'=>['name'=>'quick_reply','paramsJson'=>'{"display_text":"Again","id":"confirmar"}']]; yield 'duplicate native id'=>[$body];
        $body = self::body(); $body['buttons'][] = ['type'=>2,'nativeFlowInfo'=>['name'=>'quick_reply','paramsJson'=>'{"id":"new"}']]; yield 'native missing label'=>[$body];
        $body = self::body(); $body['contextInfo'] = ['unknown'=>true]; yield 'invalid context schema'=>[$body];
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('invalidBodies')]
    public function testMalformedButtonsFailBeforeSubmission(array $body): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ButtonsBuilder::build($body);
    }

    /** @param array<string,mixed> $body */
    #[DataProvider('invalidBodies')]
    public function testTextFallbackUsesTheSameIdUnicodeAndSchemaValidation(array $body): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ButtonsBuilder::textFallback($body);
    }
}
