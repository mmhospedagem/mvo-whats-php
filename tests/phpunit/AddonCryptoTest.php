<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Message\{AddonCrypto,ContentBuilder,ContentDecoder};

final class AddonCryptoTest extends TestCase
{
    public function testCiphertextMatchesIndependentPythonCryptographyVector(): void
    {
        $v = json_decode(file_get_contents(dirname(__DIR__).'/fixtures/addon-crypto.json'),true,512,JSON_THROW_ON_ERROR);
        $secret = hex2bin($v['secret']); $iv = hex2bin($v['iv']); $plain = hex2bin($v['plaintext']);
        self::assertSame($v['key'],bin2hex(AddonCrypto::key($secret,$v['parentId'],$v['author'],$v['sender'],$v['type'])));
        $encrypted = AddonCrypto::encrypt($secret,$v['parentId'],$v['author'],$v['sender'],$v['type'],$plain,$iv);
        self::assertSame($v['ciphertext'],bin2hex($encrypted['encPayload']));
        self::assertSame($plain,AddonCrypto::decrypt($secret,$v['parentId'],$v['author'],$v['sender'],$v['type'],$encrypted['encPayload'],$iv));
    }

    public function testWrongSenderParentAndUseCaseCannotAuthenticate(): void
    {
        $secret = str_repeat('s',32); $cipher = AddonCrypto::encrypt($secret,'PARENT','author@lid','voter@lid','Poll Vote','payload');
        foreach ([['OTHER','author@lid','voter@lid','Poll Vote'],['PARENT','other@lid','voter@lid','Poll Vote'],['PARENT','author@lid','other@lid','Poll Vote'],['PARENT','author@lid','voter@lid','Event Response']] as [$parent,$author,$sender,$type]) {
            try { AddonCrypto::decrypt($secret,$parent,$author,$sender,$type,$cipher['encPayload'],$cipher['encIv']); self::fail('Accepted wrong addon identity'); }
            catch (\UnexpectedValueException $e) { self::assertSame('Addon authentication failed',$e->getMessage()); }
        }
    }

    public function testBuiltPollVoteAndEventResponseDecryptToTheirActualPayloads(): void
    {
        $parent = ['id'=>'PARENT','fromMe'=>false,'authorJid'=>'123:4@lid','messageSecret'=>str_repeat('s',32)];
        foreach ([
            ['type'=>'poll-vote','poll'=>$parent,'selectedOptionNames'=>['A','B']],
            ['type'=>'event-response','event'=>$parent,'response'=>'maybe','extraGuestCount'=>2],
        ] as $content) {
            $built = ContentBuilder::build($content,'456@lid',['meJid'=>'456:2@lid','outgoingId'=>'OUT','nowMs'=>1000]);
            $decoded = ContentDecoder::decode($built['bytes']); $addon = $decoded['encryptedAddon'];
            self::assertSame($content['type'],$addon['kind']);
            $plain = AddonCrypto::decrypt($parent['messageSecret'],'PARENT','123@lid','456@lid',$addon['modificationType'],$addon['encPayload'],$addon['encIv']);
            $body = AddonCrypto::decodePlaintext($addon['kind'],$plain);
            if ($content['type'] === 'poll-vote') self::assertSame([hash('sha256','A',true),hash('sha256','B',true)],$body['selectedOptions']);
            else self::assertSame(['response'=>3,'timestampMs'=>1000,'extraGuestCount'=>2],$body);
        }
    }

    public function testMalformedSecretAndIvAreRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        AddonCrypto::encrypt('short','PARENT','a@lid','b@lid','Poll Vote','payload');
    }

    public function testSenderFallbackAuthenticatesLidOrPhoneIdentityAndExcludesChatJids(): void
    {
        $secret = str_repeat('s',32);
        $parents = ['123@g.us','111@s.whatsapp.net','999:2@lid'];
        $senders = ['123@g.us','222@s.whatsapp.net','888:3@lid'];
        self::assertSame([['999@lid','888@lid'],['111@s.whatsapp.net','222@s.whatsapp.net']],AddonCrypto::senderPairs($parents,$senders));
        foreach ([['999@lid','888@lid'],['111@s.whatsapp.net','222@s.whatsapp.net']] as [$author,$sender]) {
            $cipher = AddonCrypto::encrypt($secret,'PARENT',$author,$sender,'Poll Vote','payload');
            self::assertSame('payload',AddonCrypto::decryptWithSenderFallback($secret,'PARENT',$parents,$senders,'Poll Vote',$cipher['encPayload'],$cipher['encIv']));
        }
    }
}
