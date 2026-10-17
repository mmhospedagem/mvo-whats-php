<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Voip\{CallCrypto,Rtp,SrtpSession,Stun,MediaSession};

final class VoipCryptoTest extends TestCase
{
    private function vector():array { return json_decode(file_get_contents(__DIR__.'/Voip-vector.json'),true,32,JSON_THROW_ON_ERROR); }
    public function testRfc3711KeyDerivationAndIndependentPythonRtpRtcpVectors():void
    {
        $v=$this->vector();$key=hex2bin($v['masterKey']);$salt=hex2bin($v['masterSalt']);
        self::assertSame('c61e7a93744f39ee10734afe3ff7a087',bin2hex(CallCrypto::sessionKey($key,$salt,0,16)));self::assertSame('cebe321f6ff7716b6fd4ab49af256a156d38baa4',bin2hex(CallCrypto::sessionKey($key,$salt,1,20)));self::assertSame('30cbbc08863d8c85d49db34a9ae1',bin2hex(CallCrypto::sessionKey($key,$salt,2,14)));
        $tx=new SrtpSession($key,$salt);$rx=new SrtpSession($key,$salt);
        self::assertSame($v['srtp'],bin2hex($tx->protect(hex2bin($v['rtp']))));self::assertSame($v['rtp'],bin2hex($rx->unprotect(hex2bin($v['srtp']))));self::assertNull($rx->unprotect(hex2bin($v['srtp'])));
        self::assertSame($v['srtcp'],bin2hex($tx->protectRtcp(hex2bin($v['rtcp']))));self::assertSame($v['rtcp'],bin2hex($rx->unprotectRtcp(hex2bin($v['srtcp']))));self::assertNull($rx->unprotectRtcp(hex2bin($v['srtcp'])));
    }
    public function testDeviceKeysAndAllSsrcSlotsMatchIndependentPythonHkdf():void
    {
        $v=$this->vector();$derived=CallCrypto::perDevice(hex2bin($v['callKey']),$v['jid']);self::assertSame($v['perDeviceMasterKey'],bin2hex($derived['masterKey']));self::assertSame($v['perDeviceMasterSalt'],bin2hex($derived['masterSalt']));
        foreach($v['ssrcs'] as $slot=>$expected)self::assertSame($expected,CallCrypto::ssrc($v['callId'],$v['jid'],$slot));
    }
    public function testRolloverReorderingTamperingAndDuplicateSendingAreRejected():void
    {
        $key=random_bytes(16);$salt=random_bytes(14);$tx=new SrtpSession($key,$salt);$rx=new SrtpSession($key,$salt);$packets=[];
        foreach([65534,65535,0,1] as $seq)$packets[$seq]=$tx->protect(Rtp::encode('frame',10,$seq,123,120));
        foreach([65534,0,65535,1] as $seq)self::assertSame('frame',Rtp::decode($rx->unprotect($packets[$seq]))['payload']);
        $fresh=$tx->protect(Rtp::encode('new',10,2,123,120));$bad=$fresh;$bad[12]=chr(ord($bad[12])^1);try{$rx->unprotect($bad);self::fail();}catch(\UnexpectedValueException){}
        self::assertSame('new',Rtp::decode($rx->unprotect($fresh))['payload']);
        $this->expectException(\LogicException::class);$tx->protect(Rtp::encode('different plaintext',10,2,124,120));
    }
    public function testRtpExtensionsCsrcAndPaddingAreParsedWithBounds():void
    {
        $packet=Rtp::encode('frame',1,2,3,120,true,[4,5],"\x10x\0\0");$parsed=Rtp::decode($packet);self::assertSame('frame',$parsed['payload']);self::assertSame(0xdebe,$parsed['extensionProfile']);self::assertTrue($parsed['marker']);self::assertSame(28,strlen($parsed['header']));
        $packet[0]=chr(ord($packet[0])|32);$packet.="\0\x02";self::assertSame('frame',Rtp::decode($packet)['payload']);
        $this->expectException(\UnexpectedValueException::class);Rtp::decode(substr($packet,0,15));
    }
    public function testStunAllocateMatchesIndependentPythonAndVerifiesIntegrity():void
    {
        $v=$this->vector();$id=hex2bin($v['stunTransactionId']);$subs=Stun::subscriptions([0x11223344],[0x55667788],0,1);self::assertSame($v['stunSubscriptions'],bin2hex($subs));
        $packet=Stun::allocate('raw-relay-credential','relay-secret',$subs,'127.0.0.1',3480,$id);self::assertSame($v['stunAllocate'],bin2hex($packet));self::assertTrue(Stun::decode($packet,'relay-secret',$id)['authenticated']);
        $withFingerprint=Stun::encode(0x0103,$id,[6=>'username'],'secret',true);self::assertTrue(Stun::decode($withFingerprint,'secret',$id)['success']);
        $bad=$withFingerprint;$bad[24]=chr(ord($bad[24])^1);$this->expectException(\UnexpectedValueException::class);Stun::decode($bad,'secret',$id);
    }
    public function testMediaBecomesReadyOnlyFromAuthenticatedPeerPackets():void
    {
        $key=random_bytes(32);$events=[];$a=new MediaSession('CALL',$key,'111:1@lid','222:2@lid');$b=new MediaSession('CALL',$key,'222:2@lid','111:1@lid',function($name,$data)use(&$events){$events[]=$name;});
        self::assertFalse($b->isReady());$packet=$a->protectPayload("\xf8\xff\xfe",16000);$bad=$packet;$bad[12]=chr(ord($bad[12])^1);try{$b->handleDatagram($bad);self::fail();}catch(\UnexpectedValueException){}self::assertFalse($b->isReady());
        $received=$b->handleDatagram($packet);self::assertSame("\xf8\xff\xfe",$received['payload']);self::assertTrue($b->isReady());self::assertSame(['voip_media_ready','voip_media_packet'],$events);self::assertNull($b->handleDatagram($packet));
    }
    public function testSsrcContextLimitDoesNotEvictReplayProtection():void
    {
        $key=random_bytes(16);$salt=random_bytes(14);$tx=new SrtpSession($key,$salt);$rx=new SrtpSession($key,$salt,4,1);$first=$tx->protect(Rtp::encode('a',1,1,1));$rx->unprotect($first);
        try{$rx->unprotect($tx->protect(Rtp::encode('b',2,1,1)));self::fail();}catch(\LengthException){}
        self::assertNull($rx->unprotect($first));
    }
}
