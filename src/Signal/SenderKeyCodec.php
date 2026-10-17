<?php
declare(strict_types=1);
namespace MVO\Signal;

use MVO\Protocol\Protobuf;

/** Signal v3 group wire format (0x33 + protobuf + 64-byte Curve25519 signature). */
final class SenderKeyCodec
{
    public static function distribution(int $id, int $iteration, string $chainKey, string $signingKey): string
    {
        self::uint($id); self::uint($iteration);
        if (strlen($chainKey) !== 32) throw new \UnexpectedValueException('Invalid sender chain key');
        return "\x33".Protobuf::encode([
            ['number'=>1,'wire'=>0,'value'=>$id], ['number'=>2,'wire'=>0,'value'=>$iteration],
            ['number'=>3,'wire'=>2,'value'=>$chainKey], ['number'=>4,'wire'=>2,'value'=>SignalState::publicKey($signingKey)],
        ]);
    }

    /** @return array{id:int,iteration:int,chain:string,public:string} */
    public static function readDistribution(string $bytes): array
    {
        $fields = self::fields(self::content($bytes, 0));
        $chain = self::bytes($fields, 3);
        if (strlen($chain) !== 32) throw new \UnexpectedValueException('Invalid sender chain key');
        return ['id'=>self::integer($fields, 1), 'iteration'=>self::integer($fields, 2),
            'chain'=>$chain, 'public'=>SignalState::publicKey(self::bytes($fields, 4))];
    }

    public static function message(int $id, int $iteration, string $ciphertext): string
    {
        self::uint($id); self::uint($iteration);
        if ($ciphertext === '' || strlen($ciphertext)%16 !== 0) throw new \UnexpectedValueException('Invalid sender ciphertext length');
        return "\x33".Protobuf::encode([
            ['number'=>1,'wire'=>0,'value'=>$id], ['number'=>2,'wire'=>0,'value'=>$iteration],
            ['number'=>3,'wire'=>2,'value'=>$ciphertext],
        ]);
    }

    /** @return array{id:int,iteration:int,ciphertext:string,body:string,signature:string} */
    public static function readMessage(string $bytes): array
    {
        $fields = self::fields(self::content($bytes, 64));
        $ciphertext = self::bytes($fields, 3);
        if ($ciphertext === '' || strlen($ciphertext)%16 !== 0) throw new \UnexpectedValueException('Invalid sender ciphertext length');
        return ['id'=>self::integer($fields, 1), 'iteration'=>self::integer($fields, 2),
            'ciphertext'=>$ciphertext, 'body'=>substr($bytes, 0, -64), 'signature'=>substr($bytes, -64)];
    }

    private static function content(string $bytes, int $signatureSize): string
    {
        if (strlen($bytes) <= 1+$signatureSize) throw new \UnexpectedValueException('Truncated sender key message');
        if (ord($bytes[0]) !== 0x33) throw new \UnexpectedValueException('Unsupported sender key version');
        return substr($bytes, 1, strlen($bytes)-1-$signatureSize);
    }

    /** @return array<int,array{wire:int,value:int|string}> */
    private static function fields(string $bytes): array
    {
        $result = [];
        foreach (Protobuf::decode($bytes, 16777216, 100) as $field) {
            if (isset($result[$field['number']])) throw new \UnexpectedValueException('Repeated singular sender key field');
            $result[$field['number']] = ['wire'=>$field['wire'],'value'=>$field['value']];
        }
        return $result;
    }

    /** @param array<int,array{wire:int,value:int|string}> $fields */
    private static function bytes(array $fields, int $number): string
    {
        $field = $fields[$number] ?? null;
        if ($field === null || $field['wire'] !== 2 || !is_string($field['value'])) throw new \UnexpectedValueException('Missing sender key bytes field '.$number);
        return $field['value'];
    }

    /** @param array<int,array{wire:int,value:int|string}> $fields */
    private static function integer(array $fields, int $number): int
    {
        $field = $fields[$number] ?? null;
        if ($field === null || $field['wire'] !== 0 || !is_int($field['value'])) throw new \UnexpectedValueException('Missing sender key integer field '.$number);
        self::uint($field['value']);
        return $field['value'];
    }

    private static function uint(int $value): void
    {
        if ($value < 0 || $value > 0xffffffff) throw new \UnexpectedValueException('Invalid sender key uint32');
    }
}
