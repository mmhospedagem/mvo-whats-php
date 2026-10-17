<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Protocol\Protobuf;
use MVO\Voip\AppDataProtocol;

final class VoipAppDataTest extends TestCase
{
    public function testProtobufWireVectorsAndBareMessageFallback():void
    {
        $reaction=AppDataProtocol::reaction(1,'👍');self::assertSame('0a0a0a0808011204f09f918d',bin2hex($reaction));self::assertSame([['kind'=>'reaction','transactionId'=>1,'text'=>'👍']],AppDataProtocol::decode($reaction));self::assertSame(AppDataProtocol::decode($reaction),AppDataProtocol::decode(substr($reaction,2)));
        $effect=AppDataProtocol::arEffect(7,'fx');self::assertSame('0a081a06080712026678',bin2hex($effect));self::assertSame([['kind'=>'ar_effect','transactionId'=>7,'text'=>'fx']],AppDataProtocol::decode($effect));
    }
    public function testUnknownTranscriptionMalformedAndBoundedLists():void
    {
        self::assertSame([],AppDataProtocol::decode("\x0a\xff"));self::assertSame([],AppDataProtocol::decode(''));$transcription=Protobuf::encode([['number'=>2,'wire'=>2,'value'=>'unmodelled']]);self::assertSame([],AppDataProtocol::decode($transcription));self::assertCount(32,AppDataProtocol::decode(str_repeat(AppDataProtocol::reaction(1,'x'),40)));
    }
    public function testCounterRemainsSigned63BitAndInvalidTextCannotBeSent():void
    {
        self::assertSame(PHP_INT_MAX,AppDataProtocol::decode(AppDataProtocol::reaction(PHP_INT_MAX,'x'))[0]['transactionId']);foreach([[0,'x'],[1,''],[1,"\xff"]] as [$counter,$text]){try{AppDataProtocol::reaction($counter,$text);self::fail();}catch(\InvalidArgumentException){self::assertTrue(true);}}
    }
}
