<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\AppState\AppStateCrypto;

final class AppStateCryptoTest extends TestCase
{
    public function testMatchesIndependentPythonHkdfAesCbcHmacAndLtHashVector(): void
    {
        $v=json_decode(file_get_contents(__DIR__.'/AppState-vector.json'),true,32,JSON_THROW_ON_ERROR);
        $key=hex2bin($v['key']); $id=hex2bin($v['keyId']);
        self::assertSame($v['derived'],bin2hex(implode('',AppStateCrypto::deriveKeys($key))));
        $value=['timestamp'=>$v['timestamp'],'muteAction'=>['muted'=>true,'muteEndTimestamp'=>$v['muteEndTimestamp']]];
        $encrypted=AppStateCrypto::encryptMutation(0,$id,$key,$v['index'],$value,2,hex2bin($v['iv']));
        foreach(['indexMac','valueBlob','valueMac'] as $field) self::assertSame($v[$field],bin2hex($encrypted[$field]));
        $decoded=AppStateCrypto::decryptMutation(0,$id,$key,$encrypted['indexMac'],$encrypted['valueBlob']);
        self::assertSame($v['index'],$decoded['index']); self::assertSame($value,$decoded['value']); self::assertSame(2,$decoded['version']);
        $hash=AppStateCrypto::ltHash(str_repeat("\0",128),[$encrypted['valueMac']]);
        self::assertSame($v['ltHash'],bin2hex($hash));
        $snapshot=AppStateCrypto::snapshotMac($key,$hash,3,'regular_high');
        self::assertSame($v['snapshotMac'],bin2hex($snapshot));
        self::assertSame($v['patchMac'],bin2hex(AppStateCrypto::patchMac($key,$snapshot,[$encrypted['valueMac']],3,'regular_high')));
        self::assertSame(str_repeat("\0",128),AppStateCrypto::ltHash($hash,[],[$encrypted['valueMac']]));
    }
    public function testValueIndexOperationAndKeyIdAreAuthenticated(): void
    {
        $key=str_repeat('k',32); $id='keyid'; $encrypted=AppStateCrypto::encryptMutation(0,$id,$key,'["x"]',['timestamp'=>1],1);
        foreach(['value','index','operation','keyId'] as $tamper) {
            $blob=$encrypted['valueBlob']; $index=$encrypted['indexMac']; $operation=0; $kid=$id;
            if($tamper==='value')$blob[20]=chr(ord($blob[20])^1);
            if($tamper==='index')$index[0]=chr(ord($index[0])^1);
            if($tamper==='operation')$operation=1;
            if($tamper==='keyId')$kid='other';
            try { AppStateCrypto::decryptMutation($operation,$kid,$key,$index,$blob); self::fail('Unauthenticated app-state record accepted'); }
            catch(\UnexpectedValueException $e) { self::assertStringContainsString('MAC mismatch',$e->getMessage()); }
        }
    }
    public function testLtHashUsesModularLittleEndianPoints(): void
    {
        $base=str_repeat("\xff",128); $a=str_repeat('a',32); $b=str_repeat('b',32);
        $added=AppStateCrypto::ltHash($base,[$a,$b,$a]);
        self::assertSame($base,AppStateCrypto::ltHash($added,[],[$a,$a,$b]));
        self::assertSame(AppStateCrypto::ltHash($base,[$b]),AppStateCrypto::ltHash($base,[$a,$b],[$a]));
    }
    public function testRemoveActionCanBeEncryptedWithoutValue(): void
    {
        $encrypted=AppStateCrypto::encryptMutation(1,'id',str_repeat('k',32),'["contact","x"]',null,2);
        self::assertNull(AppStateCrypto::decryptMutation(1,'id',str_repeat('k',32),$encrypted['indexMac'],$encrypted['valueBlob'])['value']);
    }
    public function testMalformedBlobFailsBeforeAes(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        AppStateCrypto::decryptMutation(0,'id',str_repeat('k',32),str_repeat('i',32),'short');
    }
}
