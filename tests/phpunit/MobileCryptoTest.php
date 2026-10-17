<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Auth\PairingCodeCrypto;
use MVO\Crypto\{Crypto,XEdDsa};
use MVO\Mobile\{CompanionCrypto,HistoryBootstrap};
use MVO\Protocol\SchemaCodec;

final class MobileCryptoTest extends TestCase
{
    public function testQrReadsTrailingKeysAndKeepsCommasInRef():void
    {
        $keys=[random_bytes(32),random_bytes(32),random_bytes(32)];$encoded=array_map(static fn($k)=>rtrim(strtr(base64_encode($k),'+/','-_'),'='),$keys);
        $parsed=CompanionCrypto::parseQr('ref,with,commas,'.implode(',',$encoded).',CHROME');
        self::assertSame('ref,with,commas',$parsed['ref']);self::assertSame($keys,[$parsed['noisePublicKey'],$parsed['identityPublicKey'],$parsed['advSecretKey']]);
        $this->expectException(\InvalidArgumentException::class);CompanionCrypto::parseQr('ref,bad,bad,bad,CHROME');
    }
    public function testSignedCompanionHasCorrectHmacAccountAndKeyIndexSignatures():void
    {
        $primary=Crypto::x25519KeyPair();$companion=Crypto::x25519KeyPair();$adv=random_bytes(32);
        $built=CompanionCrypto::signedCompanion($primary,$companion['public'],$adv,123,4,1000,[0,2,4]);
        $wrapper=SchemaCodec::decode('ADVSignedDeviceIdentityHMAC',$built['deviceIdentityBytes']);self::assertSame(Crypto::hmac($adv,$wrapper['details']),$wrapper['hmac']);self::assertArrayNotHasKey('accountType',$wrapper);
        $signed=SchemaCodec::decode('ADVSignedDeviceIdentity',$wrapper['details']);$details=SchemaCodec::decode('ADVDeviceIdentity',$signed['details']);
        self::assertSame(['rawId'=>123,'timestamp'=>1000,'keyIndex'=>4],$details);self::assertTrue(XEdDsa::verify($primary['public'],"\x06\x00".$signed['details'].$companion['public'],$signed['accountSignature']));
        $list=SchemaCodec::decode('ADVSignedKeyIndexList',$built['keyIndexListBytes']);self::assertTrue(XEdDsa::verify($primary['public'],"\x06\x02".$list['details'],$list['accountSignature']));self::assertSame([0,2,4],SchemaCodec::decode('ADVKeyIndexList',$list['details'])['validIndexes']);
        self::assertFalse(XEdDsa::verify($primary['public'],"\x06\x00".$signed['details'].str_repeat('x',32),$signed['accountSignature']));
    }
    public function testPrimaryCodeExchangeAgreesWithCompanionAndRejectsTampering():void
    {
        $hello=PairingCodeCrypto::createHello('1234-5678');$primaryIdentity=Crypto::x25519KeyPair();$companionIdentity=Crypto::x25519KeyPair();
        $primary=CompanionCrypto::preparePrimaryHello($hello['pairingCode'],$hello['wrappedCompanionEphemeralPub']);
        $finish=PairingCodeCrypto::completeFinish($hello['pairingCode'],$primary['wrappedPrimaryEphemeralPub'],$primaryIdentity['public'],$hello['companionEphemeralKeyPair']['private'],$companionIdentity);
        $adv=CompanionCrypto::completePrimary($primary['sharedEphemeral'],$finish['wrappedKeyBundle'],$companionIdentity['public'],$primaryIdentity);self::assertSame($finish['advSecret'],$adv);
        $this->expectException(\UnexpectedValueException::class);$finish['wrappedKeyBundle'][100]=chr(ord($finish['wrappedKeyBundle'][100])^1);CompanionCrypto::completePrimary($primary['sharedEphemeral'],$finish['wrappedKeyBundle'],$companionIdentity['public'],$primaryIdentity);
    }
    public function testCodeBundleCannotSubstituteCompanionIdentity():void
    {
        $hello=PairingCodeCrypto::createHello('12345678');$primaryIdentity=Crypto::x25519KeyPair();$companionIdentity=Crypto::x25519KeyPair();$primary=CompanionCrypto::preparePrimaryHello($hello['pairingCode'],$hello['wrappedCompanionEphemeralPub']);
        $finish=PairingCodeCrypto::completeFinish($hello['pairingCode'],$primary['wrappedPrimaryEphemeralPub'],$primaryIdentity['public'],$hello['companionEphemeralKeyPair']['private'],$companionIdentity);
        $this->expectException(\UnexpectedValueException::class);CompanionCrypto::completePrimary($primary['sharedEphemeral'],$finish['wrappedKeyBundle'],Crypto::x25519KeyPair()['public'],$primaryIdentity);
    }
    public function testHistoryBootstrapContainsRealCompressedHistoryAndMappings():void
    {
        $built=HistoryBootstrap::build(['phoneNumberToLidMappings'=>[['pnJid'=>'551234567@s.whatsapp.net','lidJid'=>'123@lid']],'pushnames'=>[['id'=>'123@lid','pushname'=>'Cliente']],'companionMetaNonce'=>'nonce']);
        $notification=$built['message']['protocolMessage']['historySyncNotification'];$compressed=$notification['initialHistBootstrapInlinePayload'];$history=SchemaCodec::decode('HistorySync',gzuncompress($compressed));
        self::assertSame('HISTORY_SYNC_NOTIFICATION',$built['message']['protocolMessage']['type']);self::assertSame(0,$history['syncType']);self::assertSame(100,$history['progress']);self::assertSame('nonce',$history['companionMetaNonce']);self::assertSame('Cliente',$history['pushnames'][0]['pushname']);self::assertSame(strlen($compressed),$built['payloadBytes']);
    }
    public function testLargeHistoryUsesEncryptedMediaReferenceAndRequiresUploader():void
    {
        $options=['companionMetaNonce'=>base64_encode(random_bytes(60000))];$uploaded=null;
        $built=HistoryBootstrap::build($options,function($bytes)use(&$uploaded){$uploaded=$bytes;return ['mediaKey'=>str_repeat('m',32),'fileSha256'=>hash('sha256',$bytes,true),'fileEncSha256'=>str_repeat('e',32),'directPath'=>'/history'];});
        $notification=$built['message']['protocolMessage']['historySyncNotification'];self::assertArrayNotHasKey('initialHistBootstrapInlinePayload',$notification);self::assertSame('/history',$notification['directPath']);self::assertSame(strlen($uploaded),$notification['fileLength']);self::assertSame($options['companionMetaNonce'],SchemaCodec::decode('HistorySync',gzuncompress($uploaded))['companionMetaNonce']);
        $this->expectException(\LengthException::class);HistoryBootstrap::build($options);
    }
}
