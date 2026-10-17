<?php
declare(strict_types=1);
namespace MVO\Media;

/** Native GD thumbnails; optional FFmpeg/ffprobe codec processing invoked without a shell. */
final class MediaProcessor
{
    public function __construct(private readonly ?string $ffmpeg=null,private readonly ?string $ffprobe=null,private readonly int $maxEdge=96) {
        if ($maxEdge<16 || $maxEdge>1024) throw new \InvalidArgumentException('Thumbnail edge must be 16..1024');
    }
    /** @return array{imageThumbnails:bool,videoThumbnails:bool,voiceConversion:bool,probe:bool} */
    public function capabilities():array { return ['imageThumbnails'=>extension_loaded('gd'),'videoThumbnails'=>$this->ffmpeg!==null,'voiceConversion'=>$this->ffmpeg!==null,'probe'=>$this->ffprobe!==null]; }
    /** @return array{bytes:string,fields:array<string,mixed>} */
    public function process(string $bytes,string $type,bool $normalizeVoice=false):array {
        if ($normalizeVoice) {
            if ($type!=='ptt') throw new \InvalidArgumentException('Voice normalization applies only to ptt');
            $bytes=$this->normalizeVoice($bytes);
        }
        $fields=[];
        if (in_array($type,['image','sticker'],true) && extension_loaded('gd')) {
            $thumbnail=$this->thumbnail($bytes,$type==='sticker');
            if ($thumbnail!==null) $fields[$type==='sticker' ? 'pngThumbnail' : 'jpegThumbnail']=$thumbnail;
        }
        if (in_array($type,['video','gif','ptv','audio','ptt'],true) && ($this->ffmpeg!==null || $this->ffprobe!==null)) {
            $path=self::source($bytes);
            try {
                if ($this->ffprobe!==null) $fields += $this->probe($path);
                if ($this->ffmpeg!==null && in_array($type,['video','gif','ptv'],true)) {
                    $fields['jpegThumbnail']=$this->run([$this->ffmpeg,'-v','error','-i',$path,'-frames:v','1','-vf','scale='.$this->maxEdge.':'.$this->maxEdge.':force_original_aspect_ratio=decrease','-f','image2','-c:v','mjpeg','pipe:1'],1048576);
                }
                if ($this->ffmpeg!==null && in_array($type,['audio','ptt'],true)) {
                    $pcm=$this->run([$this->ffmpeg,'-v','error','-i',$path,'-f','f32le','-ac','1','-ar','8000','pipe:1'],67108864);
                    $fields['waveform']=self::waveform($pcm);
                    $fields['seconds']=(int)ceil(strlen($pcm)/32000);
                }
            } finally { unlink($path); }
        }
        return ['bytes'=>$bytes,'fields'=>$fields];
    }
    public function thumbnail(string $bytes,bool $png=false):?string {
        if (!extension_loaded('gd')) throw new \RuntimeException('Thumbnails require ext-gd');
        $size=@getimagesizefromstring($bytes);
        if ($size===false) return null;
        // Bound decoded image allocation before imagecreatefromstring.
        if ($size[0]*$size[1]>20000000) throw new \LengthException('Image dimensions exceed thumbnail pixel limit');
        $source=@imagecreatefromstring($bytes); if ($source===false) return null;
        $scale=min(1.0,$this->maxEdge/max($size[0],$size[1]));
        $width=max(1,(int)round($size[0]*$scale)); $height=max(1,(int)round($size[1]*$scale)); $target=imagecreatetruecolor($width,$height);
        try {
            if ($png) { imagealphablending($target,false); imagesavealpha($target,true); $color=imagecolorallocatealpha($target,0,0,0,127); imagefill($target,0,0,$color); }
            else { $white=imagecolorallocate($target,255,255,255); imagefill($target,0,0,$white); }
            if (!imagecopyresampled($target,$source,0,0,0,0,$width,$height,$size[0],$size[1])) throw new \RuntimeException('Cannot resize thumbnail');
            ob_start(); try { $ok=$png ? imagepng($target) : imagejpeg($target,null,55); $output=ob_get_contents(); } finally { ob_end_clean(); }
            if (!$ok || !is_string($output)) throw new \RuntimeException('Cannot encode thumbnail'); return $output;
        } finally { imagedestroy($target); imagedestroy($source); }
    }
    public function normalizeVoice(string $bytes):string {
        if ($this->ffmpeg===null) throw new \LogicException('Configure the FFmpeg path to normalize voice notes');
        $path=self::source($bytes);
        try { return $this->run([$this->ffmpeg,'-v','error','-i',$path,'-vn','-ac','1','-ar','48000','-c:a','libopus','-b:a','32k','-application','voip','-f','ogg','pipe:1'],33554432); }
        finally { unlink($path); }
    }
    /** @return array<string,int> */
    public function probe(string $path):array {
        if ($this->ffprobe===null) throw new \LogicException('Configure the ffprobe path');
        $json=$this->run([$this->ffprobe,'-v','error','-show_entries','format=duration:stream=width,height,codec_type','-of','json',$path],1048576);
        $data=json_decode($json,true,32,JSON_THROW_ON_ERROR); $fields=[];
        if (isset($data['format']['duration']) && is_numeric($data['format']['duration'])) $fields['seconds']=(int)ceil((float)$data['format']['duration']);
        foreach ($data['streams'] ?? [] as $stream) if (($stream['codec_type'] ?? '')==='video') { foreach (['width','height'] as $key) if (isset($stream[$key]) && is_int($stream[$key])) $fields[$key]=$stream[$key]; break; }
        return $fields;
    }
    /** Converts little-endian float PCM into WhatsApp's 64 normalized waveform bytes. */
    public static function waveform(string $pcm,int $points=64):string {
        if ($points<16 || $points>256 || strlen($pcm)%4!==0 || $pcm==='') throw new \InvalidArgumentException('Invalid PCM waveform input');
        $samples=intdiv(strlen($pcm),4); $sums=array_fill(0,$points,0.0); $counts=array_fill(0,$points,0);
        for ($i=0;$i<$samples;$i++) {
            $value=unpack('g',substr($pcm,$i*4,4))[1]; if (!is_finite($value)) throw new \UnexpectedValueException('Invalid PCM sample');
            $bucket=min($points-1,intdiv($i*$points,$samples)); $sums[$bucket]+=abs($value); ++$counts[$bucket];
        }
        $averages=[]; foreach ($sums as $i=>$sum) $averages[]=$counts[$i]===0 ? 0.0 : $sum/$counts[$i];
        $peak=max($averages); $result=''; foreach ($averages as $value) $result.=chr($peak===0.0 ? 0 : min(100,(int)round(100*$value/$peak))); return $result;
    }
    private static function source(string $bytes):string {
        $path=tempnam(sys_get_temp_dir(),'mvo-process-'); if ($path===false) throw new \RuntimeException('Cannot create media input');
        if (file_put_contents($path,$bytes)!==strlen($bytes)) { unlink($path); throw new \RuntimeException('Cannot write media input'); } return $path;
    }
    /** @param list<string> $command */
    private function run(array $command,int $maxBytes):string {
        $process=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true]);
        if (!is_resource($process)) throw new \RuntimeException('Cannot start media processor');
        fclose($pipes[0]); stream_set_blocking($pipes[1],false); stream_set_blocking($pipes[2],false);
        $output=''; $error=''; $deadline=microtime(true)+30; $exit=-1;
        try {
            do {
                $chunk=stream_get_contents($pipes[1]); $err=stream_get_contents($pipes[2]);
                if ($chunk===false || $err===false) throw new \RuntimeException('Cannot read media processor output');
                $output.=$chunk; $error.=substr($err,0,max(0,16384-strlen($error)));
                if (strlen($output)>$maxBytes) throw new \LengthException('Media processor output exceeds limit');
                $status=proc_get_status($process);
                if (!$status['running']) { $exit=$status['exitcode']; $tail=stream_get_contents($pipes[1]); if ($tail!==false) $output.=$tail; break; }
                if (microtime(true)>$deadline) throw new \RuntimeException('Media processor timed out');
                usleep(10000);
            } while (true);
            if ($exit!==0) throw new \RuntimeException('Media processor rejected input: '.substr($error,0,500));
            if (strlen($output)>$maxBytes) throw new \LengthException('Media processor output exceeds limit'); return $output;
        } finally { fclose($pipes[1]); fclose($pipes[2]); $status=proc_get_status($process); if ($status['running']) proc_terminate($process); proc_close($process); }
    }
}
