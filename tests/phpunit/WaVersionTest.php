<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Transport\WaVersion;
final class WaVersionTest extends TestCase {
    public function testRevisionAndEscapedRevision():void {
        self::assertSame('2.3000.1043028647',WaVersion::fromServiceWorker('{"client_revision":1043028647}'));
        self::assertSame('2.3000.123456',WaVersion::fromServiceWorker('{\\"client_revision\\": 123456}'));
    }
    public function testMissingRevisionDoesNotInventVersion():void { $this->expectException(\UnexpectedValueException::class); WaVersion::fromServiceWorker('{"version":0}'); }
}
