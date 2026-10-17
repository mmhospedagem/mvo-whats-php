<?php
declare(strict_types=1);
namespace MVO\Voip;

/** PCM playout or explicit WAV recording. No device opens until start(). */
final class AudioOutput
{
    private ?NativeAvSink $sink=null;
    private bool $stopped=false;
    private bool $playoutMuted=false;
    private float $gain=1.0;
    private float $rampMs=8.0;
    private int $mutedSamples=0;
    /** @param list<string>|null $nativeOutput */
    private function __construct(private readonly ?string $path,private readonly ?string $binary,private readonly int $maxQueuedBytes,private readonly ?array $nativeOutput=null) {}
    /** ffplay opens the speaker only when the caller explicitly starts this output. */
    public static function playback(?string $ffplay=null,int $maxQueuedBytes=1048576):self { return new self(null,$ffplay,$maxQueuedBytes); }
    public static function waveFile(string $path,?string $ffmpeg=null,int $maxQueuedBytes=1048576):self
    {
        if($path==='' || str_contains($path,"\0") || file_exists($path) || !is_dir(dirname($path))) throw new \InvalidArgumentException('WAV output requires a new path inside an existing directory');
        return new self($path,$ffmpeg,$maxQueuedBytes);
    }
    /** Explicit output device using a compiled FFmpeg libavdevice backend.
     * Unsupported backends fail at start(); constructing never opens hardware.
     */
    public static function device(string $backend,string $device,?string $ffmpeg=null,int $maxQueuedBytes=1048576):self
    {
        if($device==='' || strlen($device)>4096 || str_contains($device,"\0")) throw new \InvalidArgumentException('An explicit native audio output device is required');
        $arguments=match($backend) {
            'alsa','oss','sndio'=>['-f',$backend,$device],
            'pulse'=>['-f','pulse','-device',$device,'-buffer_duration','60','-prebuf','0','MVO WhatsApp'],
            'audiotoolbox'=>preg_match('/^(?:-|[0-9]{1,6})$/D',$device)===1?['-f','audiotoolbox',$device]:throw new \InvalidArgumentException('AudioToolbox output device must be an index or -'),
            default=>throw new \InvalidArgumentException('Unsupported native audio output backend')
        };
        return new self(null,$ffmpeg,$maxQueuedBytes,$arguments);
    }
    public function start(float $timeout=10):void
    {
        if($this->sink!==null || $this->stopped) throw new \LogicException('Audio output can only start once');
        $input=['-f','f32le','-ar','16000','-ac','1','-i',NativeAvProcess::SOCKET];
        $speaker=$this->path===null && $this->nativeOutput===null;
        $arguments=$speaker?['-hide_banner','-loglevel','error','-nodisp','-autoexit',...$input]:['-hide_banner','-loglevel','error','-nostdin','-n',...$input,'-c:a','pcm_s16le',...($this->nativeOutput??['-f','wav',$this->path??throw new \LogicException('Missing WAV output path')])];
        $this->sink=new NativeAvSink(new NativeAvProcess($this->binary??FfmpegBinary::tool($speaker?'ffplay':'ffmpeg'),$arguments),$this->maxQueuedBytes);
        try { $this->sink->start($timeout); } catch(\Throwable $error) { $this->cancel(); throw $error; }
    }
    /** @param list<float|int> $samples Normalized mono PCM at 16 kHz, commonly 960 samples. */
    public function write(array $samples):void
    {
        if(!array_is_list($samples) || count($samples)>262144) throw new \InvalidArgumentException('Invalid PCM output block');
        $sink=$this->active();
        foreach($samples as $sample) if((!is_int($sample) && !is_float($sample)) || !is_finite((float)$sample)) throw new \InvalidArgumentException('PCM output samples must be finite numeric data');
        $target=$this->playoutMuted?0.0:1.0; $step=$this->rampMs===0.0?1.0:1.0/max(1.0,$this->rampMs*16.0);
        $bytes=''; $gain=$this->gain; $muted=0; foreach($samples as $sample) {
            $gain=$target<$gain?max($target,$gain-$step):min($target,$gain+$step);
            $bytes.=pack('g',max(-1.0,min(1.0,(float)$sample))*$gain);
            if($gain===0.0) ++$muted;
        }
        $sink->write($bytes); $this->gain=$gain; $this->mutedSamples+=$muted;
    }
    /** Keeps consuming/writing the live timeline while muted, without pausing call reception. */
    public function setPlayoutMuted(bool $muted,float $rampMs=8):void
    {
        if($this->stopped) throw new \LogicException('Audio output is stopped');
        if(!is_finite($rampMs) || $rampMs<0 || $rampMs>100) throw new \InvalidArgumentException('Playout ramp must be 0..100 ms');
        $this->playoutMuted=$muted; $this->rampMs=$rampMs;
    }
    /** Selects an explicit native output endpoint. Startup failure leaves the current one active.
     * Device-specific names belong to the chosen native backend, never browser sinkId strings.
     */
    public function setOutput(self $replacement,float $timeout=10):void
    {
        $current=$this->active();
        if($replacement===$this || $replacement->sink!==null || $replacement->stopped) throw new \InvalidArgumentException('Replacement audio output must be fresh');
        $replacement->start($timeout); $next=$replacement->sink??throw new \LogicException('Replacement audio output did not start');
        try { $current->finish($timeout); }
        catch(\Throwable $error) { $replacement->cancel(); $this->stopped=true; throw $error; }
        $replacement->sink=null; $replacement->stopped=true; $this->sink=$next;
    }
    public function poll():void { $this->active()->poll(); }
    public function finish(float $timeout=5):void { try { $this->active()->finish($timeout); } finally { $this->stopped=true; } }
    public function stop():void { $this->cancel(); }
    public function cancel():void { $this->stopped=true; $this->sink?->cancel(); }
    /** @return array{queuedBytes:int,writtenBytes:int,closed:bool,playoutMuted:bool,mutedSamples:int} */
    public function stats():array { return [...($this->sink?->stats()??['queuedBytes'=>0,'writtenBytes'=>0,'closed'=>$this->stopped]),'playoutMuted'=>$this->playoutMuted,'mutedSamples'=>$this->mutedSamples]; }
    private function active():NativeAvSink { if($this->stopped) throw new \LogicException('Audio output is stopped'); return $this->sink??throw new \LogicException('Start audio output before writing'); }
    public function __destruct() { $this->cancel(); }
}
