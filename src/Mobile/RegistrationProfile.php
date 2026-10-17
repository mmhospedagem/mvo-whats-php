<?php
declare(strict_types=1);
namespace MVO\Mobile;

/** Public signed-APK version profile. The version token is not a device attestation proof. */
final class RegistrationProfile
{
    public const VERSION = '2.26.39.79';
    private const TOKEN_KEY = 'RFObk0NHtvEmCSluaRRbWDCd+U7QqKWi2UB4qOr/hwE+PZWmlkSqG5JGRlMsJ5+LzShVq1XyyLwWk623gAyI/w==';
    private const SIGNER = 'MIIDMjCCAvCgAwIBAgIETCU2pDALBgcqhkjOOAQDBQAwfDELMAkGA1UEBhMCVVMxEzARBgNVBAgTCkNhbGlmb3JuaWExFDASBgNVBAcTC1NhbnRhIENsYXJhMRYwFAYDVQQKEw1XaGF0c0FwcCBJbmMuMRQwEgYDVQQLEwtFbmdpbmVlcmluZzEUMBIGA1UEAxMLQnJpYW4gQWN0b24wHhcNMTAwNjI1MjMwNzE2WhcNNDQwMjE1MjMwNzE2WjB8MQswCQYDVQQGEwJVUzETMBEGA1UECBMKQ2FsaWZvcm5pYTEUMBIGA1UEBxMLU2FudGEgQ2xhcmExFjAUBgNVBAoTDVdoYXRzQXBwIEluYy4xFDASBgNVBAsTC0VuZ2luZWVyaW5nMRQwEgYDVQQDEwtCcmlhbiBBY3RvbjCCAbgwggEsBgcqhkjOOAQBMIIBHwKBgQD9f1OBHXUSKVLfSpwu7OTn9hG3UjzvRADDHj+AtlEmaUVdQCJR+1k9jVj6v8X1ujD2y5tVbNeBO4AdNG/yZmC3a5lQpaSfn+gEexAiwk+7qdf+t8Yb+DtX58aophUPBPuD9tPFHsMCNVQTWhaRMvZ1864rYdcq7/IiAxmd0UgBxwIVAJdgUI8VIwvMspK5gqLrhAvwWBz1AoGBAPfhoIXWmz3ey7yrXDa4V7l5lK+7+jrqgvlXTAs9B4JnUVlXjrrUWU/mcQcQgYC0SRZxI+hMKBYTt88JMozIpuE8FnqLVHyNKOCjrh4rs6Z1kW6jfwv6ITVi8ftiegEkO8yk8b6oUZCJqIPf4VrlnwaSi2ZegHtVJWQBTDv+z0kqA4GFAAKBgQDRGYtLgWh7zyRtQainJfCpiaUbzjJuhMgo4fVWZIvXHaSHBU1t5w//S0lDK2hiqkj8KpMWGywVov9eZxZy37V26dEqr/c2m5qZ0E+ynSu7sqUD7kGx/zeIcGT0H+KAVgkGNQCo5Uc0koLRWYHNtYoIvt5R3X6YZylbPftF/8ayWTALBgcqhkjOOAQDBQADLwAwLAIUAKYCp0d6z4QQdyN74JDfQ2WCyi8CFDUM4CaNB+ceVXdKtOrNTQcc0e+t';
    private const CLASSES_MD5 = 'f3fb96596657af0e59e405cb42fc99a3';

    /** @param array<string,mixed> $deviceInfo */
    public static function token(string $nationalNumber, array $deviceInfo): string
    {
        if (($deviceInfo['os'] ?? 'android') !== 'android' || ($deviceInfo['business'] ?? false) !== false || ($deviceInfo['appVersion'] ?? null) !== self::VERSION) {
            throw new \InvalidArgumentException('Registration requires the supported Android version profile '.self::VERSION);
        }
        if (!preg_match('/^[0-9]{4,14}$/D', $nationalNumber)) {
            throw new \InvalidArgumentException('Invalid national number for registration');
        }
        return base64_encode(hash_hmac('sha1', base64_decode(self::SIGNER, true).hex2bin(self::CLASSES_MD5).$nationalNumber, base64_decode(self::TOKEN_KEY, true), true));
    }

    /** Android protocol defaults for this supported public profile; identifiers are generated and persisted by RegistrationService.
     * @return array<string,mixed> */
    public static function defaultDeviceInfo(): array
    {
        return ['os'=>'android', 'business'=>false, 'appVersion'=>self::VERSION,
            'manufacturer'=>'MVO', 'device'=>'PHP', 'osVersion'=>'14', 'osBuildNumber'=>'MVO',
            'localeLanguageIso6391'=>'pt', 'localeCountryIso31661Alpha2'=>'BR', 'mcc'=>'000', 'mnc'=>'000'];
    }
}
