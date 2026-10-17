<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Protocol\BinaryData;
final class BinaryDataTest extends TestCase
{
    public function testRoundTripsMediaKeysCoordinatesAndOldTextRecords():void {
        $value=['kind'=>'image','key'=>"\0\xff\x80",'latitude'=>-23.55,'text'=>'Olá','empty'=>'','zero'=>'0','bool'=>true,'null'=>null];
        self::assertSame($value,BinaryData::decode(BinaryData::encode($value)));
        self::assertSame(['name'=>'message','event'=>['text'=>'old']],BinaryData::decode('{"name":"message","event":{"text":"old"}}'));
        self::assertSame(['key'=>"\0\xff"],BinaryData::fromJsonInput(['key'=>['$base64'=>'AP8=']]));
    }
    public function testMalformedBase64IsNotAccepted():void { $this->expectException(\UnexpectedValueException::class); BinaryData::decode('{"$bytes":"%%%"}'); }
}
