<?php
declare(strict_types=1);

use MVO\Mobile\RegistrationProfile;
use PHPUnit\Framework\TestCase;

final class MobileRegistrationProfileTest extends TestCase
{
    public function testVersionTokenMatchesIndependentSignedApkVector(): void
    {
        // Independent Python hashlib PBKDF2/HMAC vector, from the signed APK's
        // hdpi resource, original signer DER and raw classes.dex digest.
        self::assertSame('8SMYIxZ/BUnmIPqO9YZ++lfczo0=', RegistrationProfile::token('11999990000', RegistrationProfile::defaultDeviceInfo()));
    }

    public function testAnUnmatchedVersionCannotSendAStaleVersionToken(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RegistrationProfile::token('11999990000', [...RegistrationProfile::defaultDeviceInfo(), 'appVersion'=>'2.26.1.1']);
    }

    public function testConsumerProfileCannotPretendToBeABusinessApk(): void
    {
        $this->expectException(InvalidArgumentException::class);
        RegistrationProfile::token('11999990000', [...RegistrationProfile::defaultDeviceInfo(), 'business'=>true]);
    }
}
