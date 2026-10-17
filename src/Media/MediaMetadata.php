<?php
declare(strict_types=1);

namespace MVO\Media;

/** Native metadata readers. Thumbnail/codec conversion can be supplied by the caller. */
final class MediaMetadata
{
    /** @return array<string,mixed> */
    public static function probe(string $bytes, string $type): array
    {
        $result = [];
        if (in_array($type,['image','sticker'],true)) {
            $dimensions = @getimagesizefromstring($bytes);
            if ($dimensions !== false) $result += ['width'=>$dimensions[0],'height'=>$dimensions[1]];
            if ($type === 'sticker') {
                $first = self::webpFirstFrameLength($bytes);
                if ($first !== null) $result += ['isAnimated'=>true,'firstFrameLength'=>$first];
            }
        }
        if (in_array($type,['video','gif','ptv','audio','ptt'],true)) {
            if (strlen($bytes) >= 12 && substr($bytes,4,4) === 'ftyp') $result += self::mp4($bytes);
            if (strlen($bytes) >= 12 && substr($bytes,0,4) === 'RIFF' && substr($bytes,8,4) === 'WAVE') {
                $offset = 12; $rate = 0; $dataLength = 0;
                while ($offset+8 <= strlen($bytes)) {
                    $chunk = substr($bytes,$offset,4);
                    $length = unpack('Vvalue',substr($bytes,$offset+4,4))['value'];
                    if ($offset+8+$length > strlen($bytes)) break;
                    if ($chunk === 'fmt ' && $length >= 16) $rate = unpack('Vvalue',substr($bytes,$offset+16,4))['value'];
                    if ($chunk === 'data') $dataLength += $length;
                    $offset += 8+$length+($length%2);
                }
                if ($rate > 0) $result['seconds'] = (int) ceil($dataLength/$rate);
            }
            if (str_starts_with($bytes,'OggS') && str_contains(substr($bytes,0,100),'OpusHead')) {
                $pos = strrpos($bytes,'OggS');
                if ($pos !== false && $pos+14 <= strlen($bytes)) {
                    $granule = unpack('Pvalue',substr($bytes,$pos+6,8))['value'];
                    if ($granule > 0) $result['seconds'] = (int) ceil($granule/48000);
                }
            }
        }
        return $result;
    }

    public static function mimetype(string $bytes, string $type, ?string $fileName): string
    {
        if ($type === 'sticker') return 'image/webp';
        if ($type === 'gif' || $type === 'ptv') return 'video/mp4';
        if ($type === 'ptt' && str_starts_with($bytes,'OggS') && str_contains(substr($bytes,0,100),'OpusHead')) return 'audio/ogg; codecs=opus';
        $detected = (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if (is_string($detected) && $detected !== 'application/octet-stream') return $detected;
        $extension = strtolower(pathinfo($fileName ?? '',PATHINFO_EXTENSION));
        return match($extension) {
            'jpg','jpeg'=>'image/jpeg','png'=>'image/png','webp'=>'image/webp','gif'=>'image/gif',
            'mp4','m4v'=>'video/mp4','mov'=>'video/quicktime','mp3'=>'audio/mpeg','ogg','opus'=>'audio/ogg',
            'm4a'=>'audio/mp4','wav'=>'audio/wav','pdf'=>'application/pdf',
            default=>match($type) {'image'=>'image/jpeg','video'=>'video/mp4','audio'=>'audio/mpeg',default=>'application/octet-stream'},
        };
    }

    private static function webpFirstFrameLength(string $bytes): ?int
    {
        if (strlen($bytes) < 30 || substr($bytes,0,4) !== 'RIFF' || substr($bytes,8,4) !== 'WEBP' || substr($bytes,12,4) !== 'VP8X') return null;
        $offset = 30;
        while ($offset+8 <= strlen($bytes)) {
            $length = unpack('Vvalue',substr($bytes,$offset+4,4))['value'];
            $next = $offset+8+$length+($length%2);
            if ($next > strlen($bytes)) return null;
            if (substr($bytes,$offset,4) === 'ANMF') return $next;
            $offset = $next;
        }
        return null;
    }

    /** @return array<string,int> */
    private static function mp4(string $bytes): array
    {
        $result = [];
        foreach (self::boxes($bytes,0,strlen($bytes)) as [$type,$start,$length]) {
            if ($type !== 'moov') continue;
            foreach (self::boxes($bytes,$start,$length) as [$child,$position,$size]) {
                if ($child === 'mvhd' && $size >= 20) {
                    $version = ord($bytes[$position]);
                    $scaleAt = $position+($version === 1 ? 20 : 12);
                    $durationAt = $scaleAt+4;
                    if ($durationAt+($version === 1 ? 8 : 4) <= $position+$size) {
                        $scale = unpack('Nvalue',substr($bytes,$scaleAt,4))['value'];
                        $duration = $version === 1 ? unpack('Jvalue',substr($bytes,$durationAt,8))['value'] : unpack('Nvalue',substr($bytes,$durationAt,4))['value'];
                        if ($scale > 0 && $duration >= 0) $result['seconds'] = (int) ceil($duration/$scale);
                    }
                }
                if ($child === 'trak') foreach (self::boxes($bytes,$position,$size) as [$sub,$at,$subSize]) {
                    if ($sub !== 'tkhd' || $subSize < 84) continue;
                    $width = (int) (unpack('Nvalue',substr($bytes,$at+$subSize-8,4))['value']/65536);
                    $height = (int) (unpack('Nvalue',substr($bytes,$at+$subSize-4,4))['value']/65536);
                    if ($width > 0 && $height > 0) $result += ['width'=>$width,'height'=>$height];
                }
            }
        }
        return $result;
    }

    /** @return list<array{string,int,int}> Payload coordinates of validated MP4 boxes. */
    private static function boxes(string $bytes, int $offset, int $length): array
    {
        $end = $offset+$length;
        $result = [];
        while ($offset+8 <= $end) {
            $size = unpack('Nvalue',substr($bytes,$offset,4))['value'];
            $header = 8;
            if ($size === 0) $size = $end-$offset;
            elseif ($size === 1) {
                if ($offset+16 > $end) break;
                $size = unpack('Jvalue',substr($bytes,$offset+8,8))['value'];
                $header = 16;
            }
            if ($size < $header || $size > $end-$offset) break;
            $result[] = [substr($bytes,$offset+4,4),$offset+$header,$size-$header];
            $offset += $size;
        }
        return $result;
    }
}
