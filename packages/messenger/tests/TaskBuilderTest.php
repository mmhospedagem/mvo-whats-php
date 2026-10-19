<?php
declare(strict_types=1);
namespace MVO\Messenger\Tests;

use MVO\Messenger\Protocol\TaskBuilder;
use PHPUnit\Framework\TestCase;

final class TaskBuilderTest extends TestCase
{
    public function testTextTaskPreservesUnicodeAndExactIdsAcrossBothJsonEnvelopes():void
    {
        $task=TaskBuilder::text('12345678901234567','Olá MVO 🚀','7500000000000000001',1);
        $request=TaskBuilder::request('123456',9,3,TaskBuilder::batch('98765432101234567',[$task],'7500000000000000002'));
        $outer=json_decode($request,true,64,JSON_THROW_ON_ERROR);$batch=json_decode($outer['payload'],true,64,JSON_THROW_ON_ERROR);$text=json_decode($batch['tasks'][0]['payload'],true,64,JSON_THROW_ON_ERROR);
        self::assertSame('123456',$outer['app_id']);self::assertSame(9,$outer['request_id']);self::assertSame(3,$outer['type']);
        self::assertSame('46',$batch['tasks'][0]['label']);self::assertSame('12345678901234567',$batch['tasks'][0]['queue_name']);
        self::assertSame('12345678901234567',(string)$text['thread_id']);self::assertSame('7500000000000000001',$text['otid']);
        self::assertSame('Olá MVO 🚀',$text['text']);self::assertSame(65537,$text['source']);self::assertArrayHasKey('multitab_env',$text);self::assertArrayNotHasKey('multi_tab_env',$text);
    }
    public function testSyncUsesCursorInsteadOfReinitializingAndDoesNotInventVersion():void
    {
        $initial=TaskBuilder::sync('1234',1,null,'{"locale":"pt_BR"}');self::assertNull($initial['last_applied_cursor']);self::assertSame('{"locale":"pt_BR"}',$initial['sync_params']);
        $resume=TaskBuilder::sync('1234',1,'cursor-42','{"locale":"pt_BR"}');self::assertSame('cursor-42',$resume['last_applied_cursor']);self::assertSame('{"locale":"pt_BR"}',$resume['sync_params']);self::assertSame(1234,$resume['version']);
    }
    public function testWhatsappJidIsNotInventedIntoAFacebookThread():void
    {
        $this->expectException(\InvalidArgumentException::class);TaskBuilder::text('123@s.whatsapp.net','Test','42',1);
    }
    public function testNumericOverflowDoesNotTruncateIdentity():void
    {
        $this->expectException(\InvalidArgumentException::class);TaskBuilder::decimalId('9223372036854775808');
    }
    public function testEpochUsesCurrentMillisecondsAndIndependentRandomBits():void
    {
        $one=TaskBuilder::epoch(1792356000000);$two=TaskBuilder::epoch(1792356000000);
        self::assertSame(1792356000000,(int)$one>>22);self::assertSame(1792356000000,(int)$two>>22);
        self::assertMatchesRegularExpression('/^[0-9]+$/D',$one);
    }
}
