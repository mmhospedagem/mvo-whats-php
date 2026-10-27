<?php
declare(strict_types=1);

namespace MVO\Protocol;

use MVO\Crypto\Crypto;

/** Numeric protobuf fields follow resources/WAProto.proto ClientPayload/DeviceProps. */
final class ClientPayload
{
    /** @param array<string,mixed> $credentials */
    public static function registration(array $credentials, string $version, string $browser = 'CHROME', string $os = 'Windows', bool $requireFullSync = false): string
    {
        $registrationId = self::integer($credentials['registrationId'] ?? null, 1, 65535, 'registrationId');
        $identity = $credentials['identityKeyPair'] ?? null;
        $signed = $credentials['signedPreKey'] ?? null;
        if (!is_array($identity) || !is_array($signed)) throw new \InvalidArgumentException('Registration requires identityKeyPair and signedPreKey');
        $identityPublic = self::bytes($identity['public'] ?? null, 32);
        $signedPublic = self::bytes($signed['public'] ?? null, 32);
        $signature = self::bytes($signed['signature'] ?? null, 64);
        $signedId = self::integer($signed['id'] ?? null, 1, 0xffffff, 'signedPreKey.id');
        $pairing = self::encode([
            1 => pack('N', $registrationId), 2 => "\x05", 3 => $identityPublic,
            4 => substr(pack('N', $signedId), 1), 5 => $signedPublic, 6 => $signature,
            7 => md5($version, true), 8 => self::deviceProps($browser, $os, $requireFullSync),
        ]);
        return self::encode(self::common($version, false) + [19 => $pairing]);
    }

    /** @param array<string,mixed> $credentials */
    public static function login(array $credentials, string $version, string $browser = 'CHROME', string $os = 'Windows'): string
    {
        $jidText = $credentials['meJid'] ?? null;
        if (!is_string($jidText)) throw new \InvalidArgumentException('Login requires a persisted meJid');
        $jid = Jid::parse($jidText);
        if ($jid->server !== 's.whatsapp.net' || !ctype_digit($jid->user)) throw new \InvalidArgumentException('Login requires a numeric WhatsApp account JID');
        $username = filter_var($jid->user, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($username === false) throw new \InvalidArgumentException('WhatsApp username exceeds the supported integer range');
        return self::encode(self::common($version, true) + [
            1 => $username,
            16 => self::integer($credentials['connectAttemptCount'] ?? 0, 0, 0xffffffff, 'connectAttemptCount'),
            18 => self::integer($jid->device, 0, 0xffffffff, 'device'),
            24 => self::integer($credentials['loginCounter'] ?? 0, 0, 0x7fffffff, 'loginCounter'),
            41 => ($credentials['lidDbMigrated'] ?? false) === true ? 1 : 0,
        ]);
    }

    /** Device metadata used both for Noise registration and link-code requests. */
    public static function deviceProps(string $browser = 'CHROME', string $os = 'Windows', bool $requireFullSync = false): string
    {
        $platform = ['CHROME' => 1, 'FIREFOX' => 2, 'IE' => 3, 'OPERA' => 4, 'SAFARI' => 5,
            'EDGE' => 6, 'DESKTOP' => 7, 'ELECTRON' => 7, 'IPAD' => 8, 'ANDROID_TABLET' => 9,
            'ANDROID TABLET' => 9, 'OHANA' => 10, 'ALOHA' => 11, 'CATALINA' => 12][strtoupper(trim($browser))] ?? 0;
        $history = [3 => 114149, 4 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 1, 10 => 1,
            11 => 1, 12 => 1, 14 => 1, 15 => 1, 19 => 60, 21 => 1, 22 => 1];
        return self::encode([1 => $os, 3 => $platform, 4 => $requireFullSync ? 1 : 0, 5 => self::encode($history)]);
    }

    /** @return array<int,int|string> */
    private static function common(string $version, bool $pull): array
    {
        if (!preg_match('/^[0-9]+(?:\.[0-9]+){2,4}$/D', $version)) throw new \InvalidArgumentException('Expected a three-to-five component WhatsApp version');
        $versionFields = [];
        foreach (explode('.', $version) as $index => $part) {
            $value = filter_var($part, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 0xffffffff]]);
            if ($value === false) throw new \InvalidArgumentException('Invalid WhatsApp version component');
            $versionFields[$index + 1] = $value;
        }
        $agent = self::encode([1 => 14, 2 => self::encode($versionFields), 3 => '000', 4 => '000',
            5 => '0.1', 6 => '', 7 => 'Desktop', 8 => '0.1', 10 => 0, 11 => 'en', 12 => 'US']);
        return [3 => 0, 5 => $agent, 6 => self::encode([4 => 0]), 12 => 1, 13 => 1, 33 => $pull ? 1 : 0];
    }

    private static function integer(mixed $value, int $min, int $max, string $name): int
    {
        if (!is_int($value) || $value < $min || $value > $max) throw new \InvalidArgumentException('Invalid ' . $name);
        return $value;
    }

    private static function bytes(mixed $value, int $length): string
    {
        if (!is_string($value)) throw new \InvalidArgumentException('Expected binary credential');
        Crypto::length($value, $length);
        return $value;
    }

    /** @param array<int,int|string> $values */
    private static function encode(array $values): string
    {
        ksort($values);
        $fields = [];
        foreach ($values as $number => $value) $fields[] = ['number' => $number, 'wire' => is_int($value) ? 0 : 2, 'value' => $value];
        return Protobuf::encode($fields);
    }
}
