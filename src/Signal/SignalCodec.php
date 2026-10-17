<?php
declare(strict_types=1);

namespace MVO\Signal;

use MVO\Protocol\Protobuf;

/** Signal v3 wire framing, matching upstream SignalSerializer and WAProto.proto. */
final class SignalCodec
{
    /** @return array{ratchetKey:string,counter:int,previousCounter:int,ciphertext:string,body:string} */
    public static function readMessage(string $bytes): array
    {
        $fields = self::fields(self::content($bytes,8));
        $ciphertext = self::bytes($fields,4);
        if ($ciphertext === '' || strlen($ciphertext)%16 !== 0) throw new \UnexpectedValueException('Invalid Signal ciphertext length');
        return [
            'ratchetKey'=>SignalState::publicKey(self::bytes($fields,1)),
            'counter'=>self::integer($fields,2),
            'previousCounter'=>isset($fields[3]) ? self::integer($fields,3) : 0,
            'ciphertext'=>$ciphertext,
            'body'=>$bytes,
        ];
    }

    /** @return array{ratchetKey:string,counter:int,previousCounter:int,ciphertext:string,body:string,registrationId:int,identityKey:string,baseKey:string,signedPreKeyId:int,preKeyId:?int} */
    public static function readPreKeyMessage(string $bytes): array
    {
        $fields = self::fields(self::content($bytes,0));
        return [
            ...self::readMessage(self::bytes($fields,4)),
            'registrationId'=>self::integer($fields,5),
            'identityKey'=>SignalState::publicKey(self::bytes($fields,3)),
            'baseKey'=>SignalState::publicKey(self::bytes($fields,2)),
            'signedPreKeyId'=>self::integer($fields,6),
            'preKeyId'=>isset($fields[1]) ? self::integer($fields,1) : null,
        ];
    }

    public static function writeMessage(string $ratchetKey,int $counter,int $previousCounter,string $ciphertext): string
    {
        return "\x33".Protobuf::encode([
            ['number'=>1,'wire'=>2,'value'=>SignalState::publicKey($ratchetKey)],
            ['number'=>2,'wire'=>0,'value'=>$counter],
            ['number'=>3,'wire'=>0,'value'=>$previousCounter],
            ['number'=>4,'wire'=>2,'value'=>$ciphertext],
        ]);
    }

    public static function writePreKeyMessage(int $registrationId,?int $preKeyId,int $signedPreKeyId,string $baseKey,string $identityKey,string $message): string
    {
        $fields = [];
        if ($preKeyId !== null) $fields[] = ['number'=>1,'wire'=>0,'value'=>$preKeyId];
        array_push($fields,
            ['number'=>2,'wire'=>2,'value'=>SignalState::publicKey($baseKey)],
            ['number'=>3,'wire'=>2,'value'=>SignalState::publicKey($identityKey)],
            ['number'=>4,'wire'=>2,'value'=>$message],
            ['number'=>5,'wire'=>0,'value'=>$registrationId],
            ['number'=>6,'wire'=>0,'value'=>$signedPreKeyId],
        );
        return "\x33".Protobuf::encode($fields);
    }

    private static function content(string $bytes,int $macSize): string
    {
        if (strlen($bytes) <= 1+$macSize) throw new \UnexpectedValueException('Truncated Signal message');
        if (ord($bytes[0])>>4 !== 3) throw new \UnexpectedValueException('Unsupported Signal version');
        return substr($bytes,1,strlen($bytes)-1-$macSize);
    }

    /** @return array<int,array{wire:int,value:int|string}> */
    private static function fields(string $bytes): array
    {
        $result = [];
        foreach (Protobuf::decode($bytes,4194304,100) as $field) {
            if (isset($result[$field['number']])) throw new \UnexpectedValueException('Repeated singular Signal field');
            $result[$field['number']] = ['wire'=>$field['wire'],'value'=>$field['value']];
        }
        return $result;
    }

    /** @param array<int,array{wire:int,value:int|string}> $fields */
    private static function bytes(array $fields,int $number): string
    {
        $field = $fields[$number] ?? null;
        if ($field === null || $field['wire'] !== 2 || !is_string($field['value'])) throw new \UnexpectedValueException('Missing Signal bytes field '.$number);
        return $field['value'];
    }

    /** @param array<int,array{wire:int,value:int|string}> $fields */
    private static function integer(array $fields,int $number): int
    {
        $field = $fields[$number] ?? null;
        if ($field === null || $field['wire'] !== 0 || !is_int($field['value']) || $field['value']>0xffffffff) throw new \UnexpectedValueException('Invalid Signal integer field '.$number);
        return $field['value'];
    }
}
