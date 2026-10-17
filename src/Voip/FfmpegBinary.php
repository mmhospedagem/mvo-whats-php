<?php
declare(strict_types=1);
namespace MVO\Voip;

final class FfmpegBinary
{
    public static function tool(string $name):string
    {
        if(!in_array($name,['ffmpeg','ffplay','ffprobe'],true)) throw new \InvalidArgumentException('Unknown AV executable');
        $configured=getenv('MVO_'.strtoupper($name)); if(is_string($configured) && $configured!=='') return $configured;
        return dirname(__DIR__,2).'/.dev/native/ffmpeg/ffmpeg-9.0.2-essentials_build/bin/'.$name.(PHP_OS_FAMILY==='Windows'?'.exe':'');
    }
}
