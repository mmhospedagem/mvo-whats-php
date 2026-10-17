<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Auth\ShortcakeCrypto;
use MVO\Crypto\Crypto;
use MVO\Protocol\SchemaCodec;

final class ShortcakeCryptoTest extends TestCase
{
    public function testAllCryptoMatchesIndependentPythonCryptographyVector():void
    {
        $v=json_decode(file_get_contents(__DIR__.'/Shortcake-vector.json'),true,32,JSON_THROW_ON_ERROR);
        $companion=ShortcakeCrypto::companion($v['ref'],$v['deviceType'],['private'=>hex2bin($v['companionPrivate']),'public'=>hex2bin($v['companionPublic'])],hex2bin($v['companionNonce']));
        self::assertSame($v['identity'],bin2hex($companion['companionEphemeralIdentityBytes']));self::assertSame($v['commitment'],bin2hex($companion['commitmentHash']));self::assertSame($v['prologue'],bin2hex($companion['prologuePayloadBytes']));
        $primary=ShortcakeCrypto::decodePrimary(SchemaCodec::encode('PrimaryEphemeralIdentity',['publicKey'=>hex2bin($v['primaryPublic']),'nonce'=>hex2bin($v['primaryNonce'])]));
        self::assertSame($v['verificationCode'],ShortcakeCrypto::verificationCode($companion['companionNonce'],$primary));
        $key=ShortcakeCrypto::encryptionKey($companion['keyPair']['private'],$primary['publicKey'],$v['deviceType'],$v['ref']);self::assertSame($v['encryptionKey'],bin2hex($key));
        self::assertSame($v['envelope'],bin2hex(ShortcakeCrypto::encryptPairingRequest($key,hex2bin($v['plaintext']),hex2bin($v['iv']))));
        $handoff=ShortcakeCrypto::handoffKey(hex2bin($v['advSecret']));self::assertSame($v['handoffKey'],bin2hex($handoff));self::assertSame($v['handoffProof'],bin2hex(Crypto::hmac($handoff,$companion['prologuePayloadBytes'])));
    }
    public function testPrimaryAndCompanionDeriveSameKeyAndAuthenticateEnvelope():void
    {
        $companion=ShortcakeCrypto::companion('ref');$primary=Crypto::x25519KeyPair();
        $key=ShortcakeCrypto::encryptionKey($companion['keyPair']['private'],$primary['public'],1,'ref');
        $other=Crypto::hkdf(Crypto::x25519($primary['private'],$companion['keyPair']['public']),32,'Pairing Information Encryption Key','Companion Pairing 1 with ref ref');self::assertSame($other,$key);
        $envelope=SchemaCodec::decode('EncryptedPairingRequest',ShortcakeCrypto::encryptPairingRequest($key,'payload'));self::assertSame('payload',Crypto::decryptGcm($other,$envelope['iv'],$envelope['encryptedPayload']));
        $this->expectException(\UnexpectedValueException::class);$envelope['encryptedPayload'][0]=chr(ord($envelope['encryptedPayload'][0])^1);Crypto::decryptGcm($other,$envelope['iv'],$envelope['encryptedPayload']);
    }
    public function testMalformedPrimaryIdentityAndInconsistentPairAreRejected():void
    {
        foreach([['publicKey'=>'short','nonce'=>str_repeat('n',32)],['publicKey'=>str_repeat('p',32),'nonce'=>'short']] as $primary) {
            try{ShortcakeCrypto::decodePrimary(SchemaCodec::encode('PrimaryEphemeralIdentity',$primary));self::fail();}catch(\UnexpectedValueException $e){self::assertStringContainsString('32 bytes',$e->getMessage());}
        }
        $this->expectException(\InvalidArgumentException::class);ShortcakeCrypto::companion('ref',1,['private'=>str_repeat('a',32),'public'=>str_repeat('b',32)]);
    }
    public function testBindingRefAndDeviceChangesEncryptionKey():void
    {
        $companion=Crypto::x25519KeyPair();$primary=Crypto::x25519KeyPair();
        $key=ShortcakeCrypto::encryptionKey($companion['private'],$primary['public'],1,'ref');
        self::assertNotSame($key,ShortcakeCrypto::encryptionKey($companion['private'],$primary['public'],2,'ref'));self::assertNotSame($key,ShortcakeCrypto::encryptionKey($companion['private'],$primary['public'],1,'other'));
    }
}
