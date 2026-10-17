<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Explicit H264 rendering or MP4 recording, without decoding video pixels in PHP. */
final class VideoOutput
{
    private ?NativeAvSink $sink=null;
    private bool $stopped=false;
    private function __construct(private readonly ?string $path,private readonly int $fps,private readonly ?string $binary,private readonly int $maxQueuedBytes)
    {
        if($fps<1 || $fps>60) throw new \InvalidArgumentException('Video frame rate must be 1..60');
    }
    public static function render(int $fps=15,?string $ffplay=null,int $maxQueuedBytes=16777216):self { return new self(null,$fps,$ffplay,$maxQueuedBytes); }
    public static function videoFile(string $path,int $fps=15,?string $ffmpeg=null,int $maxQueuedBytes=16777216):self
    {
        if($path==='' || str_contains($path,"\0") || file_exists($path) || !is_dir(dirname($path))) throw new \InvalidArgumentException('MP4 output requires a new path inside an existing directory');
        return new self($path,$fps,$ffmpeg,$maxQueuedBytes);
    }
    public function start(float $timeout=10):void
    {
        if($this->sink!==null || $this->stopped) throw new \LogicException('Video output can only start once');
        $input=['-f','h264','-framerate',(string)$this->fps,'-i',NativeAvProcess::SOCKET];
        $arguments=$this->path===null?['-hide_banner','-loglevel','error','-autoexit',...$input]:['-hide_banner','-loglevel','error','-nostdin','-n',...$input,'-an','-c:v','copy','-f','mp4',$this->path];
        $this->sink=new NativeAvSink(new NativeAvProcess($this->binary??FfmpegBinary::tool($this->path===null?'ffplay':'ffmpeg'),$arguments),$this->maxQueuedBytes);
        try { $this->sink->start($timeout); } catch(\Throwable $error) { $this->cancel(); throw $error; }
    }
    public function write(string $annexB):void
    {
        if($annexB==='' || strlen($annexB)>8388608 || !str_starts_with($annexB,"\0\0\1") && !str_starts_with($annexB,H264::START_CODE)) throw new \InvalidArgumentException('Video output requires bounded Annex-B access units');
        $this->active()->write($annexB);
    }
    public function poll():void { $this->active()->poll(); }
    public function finish(float $timeout=5):void { try { $this->active()->finish($timeout); } finally { $this->stopped=true; } }
    public function stop():void { $this->cancel(); }
    public function cancel():void { $this->stopped=true; $this->sink?->cancel(); }
    /** @return array{queuedBytes:int,writtenBytes:int,closed:bool} */
    public function stats():array { return $this->sink?->stats()??['queuedBytes'=>0,'writtenBytes'=>0,'closed'=>$this->stopped]; }
    private function active():NativeAvSink { if($this->stopped) throw new \LogicException('Video output is stopped'); return $this->sink??throw new \LogicException('Start video output before writing'); }
    public function __destruct() { $this->cancel(); }
}
