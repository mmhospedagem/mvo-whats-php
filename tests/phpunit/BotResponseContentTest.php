<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\{BotResponseContent,ContentDecoder};
use MVO\Protocol\SchemaCodec;
use PHPUnit\Framework\TestCase;

final class BotResponseContentTest extends TestCase
{
    public function testPublicUnifiedTextLayoutsAndTypedCodeTableLatexAreProjectedWithoutRawData(): void
    {
        $message = ['richResponseMessage'=>['messageType'=>1,'submessages'=>[['messageType'=>2,'messageText'=>'legacy duplicate']],
            'unifiedResponse'=>['data'=>json_encode(['sections'=>[
                ['view_model'=>['__typename'=>'GenAISingleLayoutViewModel','primitive'=>['__typename'=>'GenAIMarkdownTextUXPrimitive','text'=>'Olá {{citation:1}}MVO{{/citation:1}}','mediaKey'=>'secret']]],
                ['view_model'=>['__typename'=>'GenAIGridLayoutViewModel','primitives'=>[
                    ['__typename'=>'GenAICodeUXPrimitive','code_blocks'=>[['content'=>'echo '],['content'=>'1;']]],
                    ['__typename'=>'GenATableUXPrimitive','rows'=>[['cells'=>['Nome','Valor']],['cells'=>['MVO','1']]]],
                    ['__typename'=>'GenAILatexUXPrimitive','item'=>['latex_expression'=>'x^2']],
                ]]],
                ['view_model'=>['__typename'=>'GenAIHScrollLayoutViewModel','primitives'=>[['__typename'=>'FOATextPrimitive','text'=>'Fim']]]],
            ],'token'=>'must not leak'],JSON_THROW_ON_ERROR)]]];
        $decoded = BotResponseContent::decode($message);
        self::assertSame("Olá MVO\necho 1;\nNome | Valor\nMVO | 1\nx^2\nFim",$decoded['text']);
        self::assertSame('replace',$decoded['responseMode']);
        self::assertSame('unified',$decoded['richResponse']['format']);
        self::assertSame('parsed',$decoded['richResponse']['status']);
        self::assertSame(['text','code','table','latex','text'],array_column($decoded['richResponse']['blocks'],'type'));
        self::assertStringNotContainsString('secret',json_encode($decoded,JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('must not leak',json_encode($decoded,JSON_THROW_ON_ERROR));
        self::assertSame($decoded['text'],ContentDecoder::decode(SchemaCodec::encode('Message',$message))['text']);
    }

    public function testEditedRichSnapshotIsDecodedButOrdinaryProtocolSemanticsArePreserved(): void
    {
        $message=['protocolMessage'=>['type'=>14,'key'=>['id'=>'first'],'editedMessage'=>[
            'richResponseMessage'=>['messageType'=>1,'submessages'=>[['messageType'=>2,'messageText'=>'Resposta final']]]]]];
        self::assertSame('Resposta final',BotResponseContent::decode($message)['text']);
        $decoded=ContentDecoder::decode(SchemaCodec::encode('Message',$message));
        self::assertSame('protocol',$decoded['kind']);self::assertNull($decoded['text']);
        self::assertSame('edit',$decoded['action']);
        self::assertNull(BotResponseContent::decode(['extendedTextMessage'=>['text'=>'prompt','contextInfo'=>['quotedMessage'=>$message]]]));
    }

    public function testLegacyTextCodeTableLatexAndInvalidUnifiedFallbackPreserveMeaningfulContent(): void
    {
        $message=['richResponseMessage'=>['messageType'=>1,'unifiedResponse'=>['data'=>'not-json'],'submessages'=>[
            ['messageType'=>2,'messageText'=>'Texto'],
            ['messageType'=>5,'codeMetadata'=>['codeLanguage'=>'php','codeBlocks'=>[['codeContent'=>'echo '],['codeContent'=>'1;']]]],
            ['messageType'=>4,'tableMetadata'=>['title'=>'Tabela','rows'=>[['items'=>['a','b']]]]],
            ['messageType'=>8,'latexMetadata'=>['text'=>'x+y']],
        ]]];
        $decoded=BotResponseContent::decode($message);
        self::assertSame("Texto\necho 1;\na | b\nx+y",$decoded['text']);
        self::assertSame('partial',$decoded['richResponse']['status']);
        self::assertSame('php',$decoded['richResponse']['blocks'][1]['language']);
    }

    public function testUnknownPrimitiveIsNotRecursivelyScrapedAndProgressIsNotAnswerText(): void
    {
        $message=['richResponseMessage'=>['unifiedResponse'=>['data'=>json_encode(['sections'=>[
            ['view_model'=>['__typename'=>'GenAISingleLayoutViewModel','primitive'=>['__typename'=>'FuturePrimitive','text'=>'private field','key'=>'private key']]],
            ['view_model'=>['__typename'=>'GenAISingleLayoutViewModel','primitive'=>['__typename'=>'GenAIBotProgressStatusPrimitive','title'=>'Pensando...']]],
        ]],JSON_THROW_ON_ERROR)]]];
        $decoded=BotResponseContent::decode($message);
        self::assertNull($decoded['text']);self::assertSame('unsupported',$decoded['richResponse']['status']);
        self::assertSame(['type'=>'unsupported','wireType'=>'FuturePrimitive'],$decoded['richResponse']['blocks'][0]);
        self::assertSame('progress',$decoded['richResponse']['blocks'][1]['type']);
        self::assertStringNotContainsString('private',json_encode($decoded,JSON_THROW_ON_ERROR));
    }

    public function testMalformedAndOversizedJsonRemainExplicitAndDoNotThrowAwayStreamCompletion(): void
    {
        foreach (['{','[]','{"sections":null}',str_repeat('x',1048577)] as $json) {
            $decoded=BotResponseContent::decode(['richResponseMessage'=>['unifiedResponse'=>['data'=>$json]]]);
            self::assertNull($decoded['text']);self::assertSame('invalid',$decoded['richResponse']['status']);
        }
        self::assertTrue($decoded['richResponse']['truncated']);
        $decoded=BotResponseContent::decode(['richResponseMessage'=>['submessages'=>[['messageType'=>2,'messageText'=>str_repeat('á',600000)]]]]);
        self::assertTrue($decoded['richResponse']['truncated']);self::assertLessThanOrEqual(1048576,strlen($decoded['text']));
        self::assertSame(1,preg_match('//u',$decoded['text']));
        json_encode($decoded,JSON_THROW_ON_ERROR);
        $decoded=BotResponseContent::decode(['richResponseMessage'=>['submessages'=>[['messageType'=>2,'messageText'=>"\xff"]]]]);
        self::assertNull($decoded['text']);self::assertTrue($decoded['richResponse']['truncated']);json_encode($decoded,JSON_THROW_ON_ERROR);
    }
}
