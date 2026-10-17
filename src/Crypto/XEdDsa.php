<?php
declare(strict_types=1);

namespace MVO\Crypto;

/**
 * Signal's Curve25519 signature format used by WhatsApp.
 *
 * The Edwards public-key sign bit is stored in bit 255 of S. This is the
 * legacy curve25519_sign construction, as in upstream core/xeddsa.ts.
 * Field/scalar arithmetic uses GMP; detached verification uses libsodium.
 * GMP signing arithmetic does not provide constant-time execution guarantees.
 *
 * @phpstan-type Point array{x: \GMP, y: \GMP, z: \GMP, t: \GMP}
 */
final class XEdDsa
{
    private const FIELD_P = '57896044618658097711785492504343953926634992332820282019728792003956564819949';
    private const GROUP_L = '7237005577332262213973186563042994240857116359379907606001950938285454250989';
    private const TWO_D = '16295367250680780974490674513165176452449235426866156013048779062215315747161';

    private static ?\GMP $prime = null;
    private static ?\GMP $order = null;
    private static ?\GMP $twoD = null;

    /**
     * $random64 is only for deterministic test vectors. Omit it in production.
     * The caller's private-key string is not modified while clamping.
     */
    public static function sign(string $private32, string $message, ?string $random64 = null): string
    {
        Crypto::length($private32, 32);
        $random64 ??= random_bytes(64);
        Crypto::length($random64, 64);

        $private32[0] = chr(ord($private32[0]) & 248);
        $private32[31] = chr((ord($private32[31]) & 127) | 64);
        $privateScalar = self::fromLittleEndian($private32);
        $encodedPublic = self::encodePoint(self::scalarMultBase($privateScalar));

        $prefix = "\xfe" . str_repeat("\xff", 31);
        $r = self::hashScalar($prefix . $private32 . $message . $random64);
        $encodedR = self::encodePoint(self::scalarMultBase($r));
        $h = self::hashScalar($encodedR . $encodedPublic . $message);
        $s = gmp_mod(gmp_add($r, gmp_mul($h, $privateScalar)), self::order());
        $encodedS = self::toLittleEndian($s);
        $encodedS[31] = chr((ord($encodedS[31]) & 127) | (ord($encodedPublic[31]) & 128));

        return $encodedR . $encodedS;
    }

    /** Returns false for malformed keys/signatures, noncanonical S and weak points. */
    public static function verify(string $publicMontgomery32, string $message, string $signature64): bool
    {
        if (strlen($publicMontgomery32) !== 32 || strlen($signature64) !== 64) {
            return false;
        }
        if ((ord($signature64[63]) & 0x60) !== 0) {
            return false;
        }

        $signBit = ord($signature64[63]) & 128;
        $signature64[63] = chr(ord($signature64[63]) & 127);
        if (gmp_cmp(self::fromLittleEndian(substr($signature64, 32)), self::order()) >= 0) {
            return false;
        }

        // X25519 ignores bit 255. The birational map is y = (u - 1)/(u + 1).
        $publicMontgomery32[31] = chr(ord($publicMontgomery32[31]) & 127);
        $u = self::field(self::fromLittleEndian($publicMontgomery32));
        $inverse = gmp_invert(self::field(gmp_add($u, 1)), self::prime());
        if ($inverse === false) {
            return false; // u = p - 1 has no Edwards image.
        }
        $y = self::field(gmp_mul(gmp_sub($u, 1), $inverse));
        $encodedPublic = self::toLittleEndian($y);
        $encodedPublic[31] = chr((ord($encodedPublic[31]) & 127) | $signBit);

        try {
            // Sodium also rejects small-order R/A and invalid point encodings.
            return sodium_crypto_sign_verify_detached($signature64, $message, $encodedPublic);
        } catch (\SodiumException) {
            return false;
        }
    }

    private static function prime(): \GMP
    {
        return self::$prime ??= gmp_init(self::FIELD_P, 10);
    }

    private static function order(): \GMP
    {
        return self::$order ??= gmp_init(self::GROUP_L, 10);
    }

    private static function field(\GMP $value): \GMP
    {
        return gmp_mod($value, self::prime());
    }

    private static function fromLittleEndian(string $bytes): \GMP
    {
        return gmp_import($bytes, 1, GMP_LSW_FIRST | GMP_LITTLE_ENDIAN);
    }

    private static function toLittleEndian(\GMP $value): string
    {
        return str_pad(gmp_export($value, 1, GMP_LSW_FIRST | GMP_LITTLE_ENDIAN), 32, "\0");
    }

    private static function hashScalar(string $bytes): \GMP
    {
        return gmp_mod(self::fromLittleEndian(hash('sha512', $bytes, true)), self::order());
    }

    /** @return Point */
    private static function scalarMultBase(\GMP $scalar): array
    {
        $scalar = gmp_mod($scalar, self::order());
        $result = ['x' => gmp_init(0), 'y' => gmp_init(1), 'z' => gmp_init(1), 't' => gmp_init(0)];
        $base = [
            'x' => gmp_init('15112221349535400772501151409588531511454012693041857206046113283949847762202'),
            'y' => gmp_init('46316835694926478169428394003475163141307993866256225615783033603165251855960'),
            'z' => gmp_init(1),
            't' => gmp_init('46827403850823179245072216630277197565144205554125654976674165829533817101731'),
        ];
        // Fixed-size traversal; complete extended-Edwards addition/doubling.
        for ($bit = 252; $bit >= 0; --$bit) {
            $result = self::doublePoint($result);
            $added = self::addPoints($result, $base);
            if (gmp_testbit($scalar, $bit)) {
                $result = $added;
            }
        }
        return $result;
    }

    /**
     * @param Point $left
     * @param Point $right
     * @return Point
     */
    private static function addPoints(array $left, array $right): array
    {
        $a = self::field(gmp_mul(gmp_sub($left['y'], $left['x']), gmp_sub($right['y'], $right['x'])));
        $b = self::field(gmp_mul(gmp_add($left['y'], $left['x']), gmp_add($right['y'], $right['x'])));
        $twoD = self::$twoD ??= gmp_init(self::TWO_D, 10);
        $c = self::field(gmp_mul(gmp_mul($twoD, $left['t']), $right['t']));
        $d = self::field(gmp_mul(gmp_mul(2, $left['z']), $right['z']));
        $e = gmp_sub($b, $a);
        $f = gmp_sub($d, $c);
        $g = gmp_add($d, $c);
        $h = gmp_add($b, $a);
        return [
            'x' => self::field(gmp_mul($e, $f)),
            'y' => self::field(gmp_mul($g, $h)),
            'z' => self::field(gmp_mul($f, $g)),
            't' => self::field(gmp_mul($e, $h)),
        ];
    }

    /**
     * @param Point $point
     * @return Point
     */
    private static function doublePoint(array $point): array
    {
        $a = self::field(gmp_mul($point['x'], $point['x']));
        $b = self::field(gmp_mul($point['y'], $point['y']));
        $c = self::field(gmp_mul(2, gmp_mul($point['z'], $point['z'])));
        $d = gmp_neg($a);
        $sum = gmp_add($point['x'], $point['y']);
        $e = gmp_sub(gmp_sub(gmp_mul($sum, $sum), $a), $b);
        $g = gmp_add($d, $b);
        $f = gmp_sub($g, $c);
        $h = gmp_sub($d, $b);
        return [
            'x' => self::field(gmp_mul($e, $f)),
            'y' => self::field(gmp_mul($g, $h)),
            'z' => self::field(gmp_mul($f, $g)),
            't' => self::field(gmp_mul($e, $h)),
        ];
    }

    /** @param Point $point */
    private static function encodePoint(array $point): string
    {
        $inverse = gmp_invert($point['z'], self::prime());
        if ($inverse === false) {
            throw new \UnexpectedValueException('Invalid Edwards point at infinity');
        }
        $x = self::field(gmp_mul($point['x'], $inverse));
        $y = self::field(gmp_mul($point['y'], $inverse));
        $encoded = self::toLittleEndian($y);
        $encoded[31] = chr((ord($encoded[31]) & 127) | (gmp_testbit($x, 0) ? 128 : 0));
        return $encoded;
    }
}
