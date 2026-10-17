<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Explicit, cancellable microphone/camera/screen capture; constructing never opens a device. */
final class FfmpegCapture
{
    private ?NativeAvProcess $process=null;
    private string $pcm='';
    private readonly AnnexBAccessUnitBuffer $accessUnits;
    private bool $ended=false;
    private bool $stopped=false;
    private int $frames=0;
    private int $paddedSamples=0;
    private int $decodedBytes=0;
    private int $maxDecodedBytes=0;
    /** @param list<string> $input */
    private function __construct(private readonly string $kind,private readonly array $input,private readonly ?string $binary=null,private readonly int $width=640,private readonly int $height=480,private readonly int $fps=15,private readonly int $bitrate=300000)
    {
        if(!in_array($kind,['audio','video'],true) || $width<16 || $width>3840 || $height<16 || $height>2160 || $width%2!==0 || $height%2!==0 || $fps<1 || $fps>60 || $bitrate<64000 || $bitrate>8000000) throw new \InvalidArgumentException('Invalid native capture configuration');
        $this->accessUnits=new AnnexBAccessUnitBuffer();
    }
    public static function microphone(string $device,?string $binary=null):self
    {
        self::device($device);
        $input=match(PHP_OS_FAMILY) { 'Windows'=>['-f','dshow','-i','audio='.$device], 'Darwin'=>['-f','avfoundation','-i',':'.$device], 'Linux'=>['-f','pulse','-i',$device], default=>throw new \RuntimeException('No microphone capture backend for this platform') };
        return new self('audio',$input,$binary);
    }
    public static function camera(string $device,int $width=640,int $height=480,int $fps=15,?string $binary=null):self
    {
        self::device($device);
        $input=match(PHP_OS_FAMILY) { 'Windows'=>['-f','dshow','-framerate',(string)$fps,'-video_size',$width.'x'.$height,'-i','video='.$device], 'Darwin'=>['-f','avfoundation','-framerate',(string)$fps,'-i',$device.':none'], 'Linux'=>['-f','v4l2','-framerate',(string)$fps,'-video_size',$width.'x'.$height,'-i',$device], default=>throw new \RuntimeException('No camera capture backend for this platform') };
        return new self('video',$input,$binary,$width,$height,$fps);
    }
    public static function screen(string $target,int $width=1280,int $height=720,int $fps=15,?string $binary=null):self
    {
        self::device($target);
        $input=match(PHP_OS_FAMILY) { 'Windows'=>['-f','gdigrab','-framerate',(string)$fps,'-i',$target], 'Darwin'=>['-f','avfoundation','-framerate',(string)$fps,'-i',$target.':none'], 'Linux'=>['-f','x11grab','-framerate',(string)$fps,'-i',$target], default=>throw new \RuntimeException('No screen capture backend for this platform') };
        return new self('video',$input,$binary,$width,$height,$fps);
    }
    /** Synthetic/local filter input, useful for deterministic tests without opening a device. */
    public static function lavfiAudio(string $filter,?string $binary=null):self { self::device($filter); return new self('audio',['-f','lavfi','-i',$filter],$binary); }
    public static function lavfiVideo(string $filter,int $width=640,int $height=480,int $fps=15,?string $binary=null):self { self::device($filter); return new self('video',['-f','lavfi','-i',$filter],$binary,$width,$height,$fps); }
    /** Streams a local audio file as call PCM instead of loading its full decoded track in PHP. */
    public static function audioFile(string $path,?string $binary=null,int $maxDecodedBytes=134217728):self
    {
        $real=realpath($path);
        if($real===false || !is_file($real) || $maxDecodedBytes<3840 || $maxDecodedBytes>536870912) throw new \InvalidArgumentException('Audio file requires a local file and a 3840..536870912 byte decode bound');
        $capture=new self('audio',['-protocol_whitelist','file','-i',$real],$binary); $capture->maxDecodedBytes=$maxDecodedBytes; return $capture;
    }
    /** An explicit microphone name or fresh native source (including audioFile/lavfiAudio).
     * The old source stays active when the replacement fails to start.
     */
    public function setMicrophone(string|self $source,float $timeout=10):void
    {
        if($this->kind!=='audio') throw new \LogicException('Only an audio capture can switch microphones');
        $current=$this->active(); $replacement=is_string($source)?self::microphone($source,$this->binary):$source;
        if($replacement===$this || $replacement->kind!=='audio' || $replacement->process!==null || $replacement->stopped) throw new \InvalidArgumentException('Replacement microphone must be a fresh audio capture');
        $replacement->start($timeout); $next=$replacement->process??throw new \LogicException('Replacement microphone did not start');
        $replacement->process=null; $replacement->stopped=true;
        $current->cancel(); $this->process=$next; $this->pcm=''; $this->ended=false; $this->decodedBytes=0; $this->maxDecodedBytes=$replacement->maxDecodedBytes;
    }
    public function start(float $timeout=10):void
    {
        if($this->process!==null || $this->stopped) throw new \LogicException('Capture can only start once');
        $output=$this->kind==='audio'?['-map','0:a:0','-vn','-ac','1','-ar','16000','-c:a','pcm_f32le','-f','f32le']:['-map','0:v:0','-an','-vf','scale='.$this->width.':'.$this->height.':force_original_aspect_ratio=decrease,pad='.$this->width.':'.$this->height.':(ow-iw)/2:(oh-ih)/2,format=yuv420p','-r',(string)$this->fps,'-c:v','libx264','-preset','ultrafast','-tune','zerolatency','-b:v',(string)$this->bitrate,'-g',(string)($this->fps*2),'-bf','0','-x264-params','aud=1:repeat-headers=1','-f','h264'];
        $process=new NativeAvProcess($this->binary??FfmpegBinary::tool('ffmpeg'),['-hide_banner','-loglevel','error','-nostdin',...$this->input,...$output,'-flush_packets','1',NativeAvProcess::SOCKET]);
        try { $process->start($timeout); $this->process=$process; } catch(\Throwable $error) { $process->cancel(); $this->stopped=true; throw $error; }
    }
    /** Poll from the application's loop; every returned block contains 960 normalized mono samples.
     * @return list<list<float>>
     */
    public function pollAudio():array
    {
        if($this->kind!=='audio') throw new \LogicException('This capture produces H264 video');
        $process=$this->active(); if($this->ended) return [];
        try {
        $bytes=$process->read(65536); $this->decodedBytes+=strlen($bytes);
        if($this->maxDecodedBytes>0 && $this->decodedBytes>$this->maxDecodedBytes) { $this->cancel(); throw new \LengthException('Decoded audio file exceeds configured byte bound'); }
        $this->pcm.=$bytes; if(strlen($this->pcm)>131072) { $this->cancel(); throw new \LengthException('Captured PCM buffer exceeds bound'); }
        $ended=$process->eof() && !$process->running();
        if($ended) { $process->assertSuccessful(); if(strlen($this->pcm)%4!==0) throw new \UnexpectedValueException('Truncated float PCM from capture'); }
        $frames=[];
        while(strlen($this->pcm)>=3840) { $frames[]=$this->pcmFrame(substr($this->pcm,0,3840)); $this->pcm=substr($this->pcm,3840); }
        if($ended) {
            if($this->pcm!=='') { $this->paddedSamples+=960-intdiv(strlen($this->pcm),4); $frames[]=$this->pcmFrame(str_pad($this->pcm,3840,"\0")); $this->pcm=''; }
            $this->ended=true; $process->cancel();
        }
        $this->frames+=count($frames); return $frames;
        } catch(\Throwable $error) { $this->cancel(); throw $error; }
    }
    /** Each frame is a complete Annex-B access unit with an AUD, ready for sendVideoAnnexB().
     * @return list<string>
     */
    public function pollVideo():array
    {
        if($this->kind!=='video') throw new \LogicException('This capture produces PCM audio');
        $process=$this->active(); if($this->ended) return [];
        try { $frames=$this->accessUnits->push($process->read(65536)); }
        catch(\Throwable $error) { $this->cancel(); throw $error; }
        if($process->eof() && !$process->running()) { $process->assertSuccessful(); $frames=[...$frames,...$this->accessUnits->finish()]; $this->ended=true; $process->cancel(); }
        $this->frames+=count($frames); return $frames;
    }
    public function isEnded():bool { return $this->ended || $this->stopped; }
    /** @return array{kind:string,frames:int,paddedSamples:int,ended:bool} */
    public function stats():array { return ['kind'=>$this->kind,'frames'=>$this->frames,'paddedSamples'=>$this->paddedSamples,'ended'=>$this->isEnded()]; }
    public function stop():void { $this->cancel(); }
    public function cancel():void { $this->stopped=true; $this->process?->cancel(); $this->pcm=''; $this->accessUnits->reset(); }
    private function active():NativeAvProcess { if($this->stopped) throw new \LogicException('Native capture is stopped'); return $this->process??throw new \LogicException('Start native capture before polling'); }
    /** @return list<float> */
    private function pcmFrame(string $bytes):array { $values=unpack('g*',$bytes); if($values===false) throw new \UnexpectedValueException('Cannot decode captured PCM'); $pcm=array_values($values); foreach($pcm as $sample) if(!is_finite($sample)) throw new \UnexpectedValueException('Captured PCM contains non-finite sample'); return $pcm; }
    private static function device(string $device):void { if($device==='' || strlen($device)>4096 || str_contains($device,"\0")) throw new \InvalidArgumentException('An explicit AV device/filter is required'); }
    public function __destruct() { $this->cancel(); }
}
