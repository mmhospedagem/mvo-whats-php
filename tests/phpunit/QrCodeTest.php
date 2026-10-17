<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Auth\QrCode;
final class QrCodeTest extends TestCase
{
    public function testCreatesSelfContainedSvgWithoutExternalQrService():void {
        $svg=QrCode::svg('ref,'.base64_encode(random_bytes(32)).','.base64_encode(random_bytes(32)).','.base64_encode(random_bytes(32)).',1');
        $xml=simplexml_load_string($svg); self::assertNotFalse($xml); self::assertSame('svg',$xml->getName()); self::assertSame('360',(string)$xml['width']);
        self::assertStringNotContainsString('<script',$svg); self::assertStringNotContainsString('http://api.',$svg);
    }
}
