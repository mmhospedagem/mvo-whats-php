<?php
declare(strict_types=1);

namespace MVO\Transport;

use MVO\Crypto\Crypto;
use MVO\Crypto\XEdDsa;
use MVO\Protocol\Protobuf;

/** Authenticates the Noise server key against WhatsApp's pinned Curve25519 CA. */
final class NoiseCertificate
{
    public const ROOT_PUBLIC_HEX = '142375574d0a587166aae71ebe516437c4a28b73e3695c6ce1f7f9545da8ee6b';

    public static function verify(string $chain, string $serverStatic, ?string $rootPublic = null, int $rootSerial = 0): void
    {
        $rootPublic ??= hex2bin(self::ROOT_PUBLIC_HEX);
        Crypto::length($rootPublic, 32);
        Crypto::length($serverStatic, 32);
        $certs = self::fields($chain);
        $intermediate = self::certificate(self::bytes($certs, 2));
        if ($intermediate['issuer'] !== $rootSerial) throw new \UnexpectedValueException('Noise intermediate certificate issuer mismatch');
        if (!XEdDsa::verify($rootPublic, $intermediate['details'], $intermediate['signature'])) {
            throw new \UnexpectedValueException('Noise intermediate certificate signature is invalid');
        }
        $leaf = self::certificate(self::bytes($certs, 1));
        if ($leaf['issuer'] !== $intermediate['serial']) throw new \UnexpectedValueException('Noise leaf certificate issuer mismatch');
        if (!XEdDsa::verify($intermediate['key'], $leaf['details'], $leaf['signature'])) {
            throw new \UnexpectedValueException('Noise leaf certificate signature is invalid');
        }
        if (!hash_equals($leaf['key'], $serverStatic)) throw new \UnexpectedValueException('Noise leaf certificate key differs from server static key');
    }

    /** @return array<int,int|string> */
    private static function fields(string $bytes): array
    {
        $out = [];
        foreach (Protobuf::decode($bytes, 65536, 64) as $field) {
            if (isset($out[$field['number']])) throw new \UnexpectedValueException('Duplicate Noise certificate field');
            $out[$field['number']] = $field['value'];
        }
        return $out;
    }

    /** @param array<int,int|string> $fields */
    private static function bytes(array $fields, int $number): string
    {
        $value = $fields[$number] ?? null;
        if (!is_string($value) || $value === '') throw new \UnexpectedValueException('Missing Noise certificate bytes');
        return $value;
    }

    /** @return array{serial:int,issuer:int,key:string,details:string,signature:string} */
    private static function certificate(string $bytes): array
    {
        $fields = self::fields($bytes);
        $details = self::bytes($fields, 1);
        $signature = self::bytes($fields, 2);
        Crypto::length($signature, 64);
        $parsed = self::fields($details);
        // Missing scalar proto2 fields use their declared zero default.
        $serial = $parsed[1] ?? 0;
        $issuer = $parsed[2] ?? 0;
        if (!is_int($serial) || !is_int($issuer)) throw new \UnexpectedValueException('Invalid Noise certificate serial');
        $key = self::bytes($parsed, 3);
        Crypto::length($key, 32);
        return ['serial' => $serial, 'issuer' => $issuer, 'key' => $key, 'details' => $details, 'signature' => $signature];
    }
}
