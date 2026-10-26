<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Protocol\BinaryNode;
use MVO\Voip\{GroupCallState,GroupMediaSession,MediaSession,MLowCodec,OpusCodec,RelayTransport};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Native codecs and authenticated local media, without accounts or network sockets. */
final class MediaSessionCodecSelectionTest extends TestCase
{
    private const CALL='LOCAL-CODEC-SELECTION';
    private const ALICE='111:1@lid';
    private const BOB='222:2@lid';
    private string|false $originalMlow=false;
    private string $mlowLibrary='';
    private string $key;

    protected function setUp():void
    {
        $this->originalMlow=getenv('MVO_MLOW_LIBRARY');
        $this->mlowLibrary=MLowCodec::defaultMLowLibrary();
        if(!extension_loaded('FFI') || !is_file(OpusCodec::defaultLibrary()))self::markTestSkipped('Install optional native Opus and enable FFI');
        $this->key=random_bytes(32);
    }

    protected function tearDown():void
    {
        putenv($this->originalMlow===false?'MVO_MLOW_LIBRARY':'MVO_MLOW_LIBRARY='.$this->originalMlow);
    }

    public static function modes():iterable
    {
        yield 'one-to-one'=>[false];
        yield 'group'=>[true];
    }

    private function session(bool $group,bool $receiver=false,?\Closure $emit=null):MediaSession|GroupMediaSession
    {
        $own=$receiver?self::BOB:self::ALICE;$peer=$receiver?self::ALICE:self::BOB;
        if(!$group)return new MediaSession(self::CALL,$this->key,$own,$peer,$emit);
        $roster=new GroupCallState(self::CALL);
        $roster->applyRoster(new BinaryNode('group_info',['transaction-id'=>'1','connected_limit'=>'32','media'=>'audio'],[
            new BinaryNode('user',['jid'=>'111@lid','state'=>'connected'],[new BinaryNode('device',['jid'=>self::ALICE,'pid'=>'0'])]),
            new BinaryNode('user',['jid'=>'222@lid','state'=>'connected'],[new BinaryNode('device',['jid'=>self::BOB,'pid'=>'1'])]),
        ]),self::CALL.'@call',$own);
        $session=new GroupMediaSession(self::CALL,$own,$roster,$emit);
        $session->rotateKey($this->key,1);
        return $session;
    }

    private function removeMlow():void
    {
        $missing=sys_get_temp_dir().'/mvo-missing-mlow-'.bin2hex(random_bytes(8));
        self::assertFalse(is_file($missing));putenv('MVO_MLOW_LIBRARY='.$missing);
    }

    #[DataProvider('modes')]
    public function testMissingMlowDoesNotSilentlySubstituteAnAvailableOpusCodec(bool $group):void
    {
        $opus=new OpusCodec();$opus->close(); // The fallback dependency really is usable.
        $this->removeMlow();$session=$this->session($group);$failure=null;
        try{
            try{$session->mediaEngine();}catch(\RuntimeException $error){$failure=$error;}
            self::assertNotNull($failure,'Missing MLow must fail before an incompatible Opus engine is installed');
            self::assertStringContainsString('MLow',$failure->getMessage());
            self::assertStringContainsString('MVO_MLOW_LIBRARY',$failure->getMessage());
            self::assertNull((new \ReflectionProperty($session,'engine'))->getValue($session));
            // Failure does not poison this session or prohibit an explicit low-level codec.
            self::assertSame($session->mediaEngine(new OpusCodec()),$session->mediaEngine());
        }finally{$session->close();}
    }

    #[DataProvider('modes')]
    public function testExplicitOpusStillEncodesAndDecodesAuthenticatedPcmWithoutMlow(bool $group):void
    {
        $this->removeMlow();$frames=[];
        $sender=$this->session($group);$receiver=$this->session($group,true,function(string $event,array $data)use(&$frames):void{
            if($event==='voip_audio_frame')$frames[]=$data['pcm'];
        });
        $transport=new CodecSelectionMemoryTransport();$sender->connectTransport($transport);
        try{
            $out=$sender->mediaEngine(new OpusCodec(),['now'=>static fn():float=>0.0]);
            $in=$receiver->mediaEngine(new OpusCodec());$pcm=[];
            for($i=0;$i<960;++$i)$pcm[]=0.3*sin(2*M_PI*440*$i/16000);
            self::assertCount(1,$out->sendAudioPcm($pcm,0.0));self::assertNotEmpty($transport->packets);
            foreach($transport->packets as $packet)$receiver->handleDatagram($packet);
            self::assertCount(1,$frames);self::assertCount(960,$frames[0]);
            self::assertGreaterThan(1,array_sum(array_map(static fn(float $v):float=>$v*$v,$frames[0])));
            self::assertSame(960,$in->pullAudio(960)['real']);
        }finally{$sender->close();$receiver->close();}
    }

    #[DataProvider('modes')]
    public function testDefaultMlowStillDecodesTheCapturedWhatsappFixture(bool $group):void
    {
        if(!is_file($this->mlowLibrary))self::markTestSkipped('Install optional native MLow');
        $fixture=json_decode(ltrim(file_get_contents(dirname(__DIR__).'/fixtures/voip/mlow-web-capture.json'),"\xef\xbb\xbf"),true,512,JSON_THROW_ON_ERROR);
        $sender=$this->session($group);$frames=[];
        $receiver=$this->session($group,true,function(string $event,array $data)use(&$frames):void{if($event==='voip_audio_frame')$frames[]=$data['pcm'];});
        try{
            $engine=$receiver->mediaEngine();
            foreach($fixture as $item){$payload=hex2bin($item['hex']);$receiver->handleDatagram($sender->protectPayload($payload,$item['timestamp']));}
            $samples=0;$energy=0.0;
            foreach($frames as $frame){$samples+=count($frame);foreach($frame as $sample){self::assertTrue(is_finite($sample));$energy+=$sample*$sample;}}
            self::assertSame(15360,$samples);self::assertGreaterThan(1,$energy);
            self::assertSame(0,$engine->stats()['audio'][0]['errors']);
        }finally{$sender->close();$receiver->close();}
    }
}

final class CodecSelectionMemoryTransport implements RelayTransport
{
    public array $packets=[];
    public function connect(array $selfSsrcs,array $peerSsrcs=[]):void{}
    public function send(string $packet):void{$this->packets[]=$packet;}
    public function receive(float $timeout=0.0):?string{return null;}
    public function tick():void{}
    public function reregister():void{}
    public function updateSubscriptions(array $selfSsrcs,array $peerSsrcs,?string $streamDescriptor=null,?string $rxSubscriptions=null):void{}
    public function metrics():array{return ['open'=>true,'allocated'=>true,'lastPongAt'=>0.0,'openedAt'=>0.0,'rttMs'=>0];}
    public function close():void{}
}
