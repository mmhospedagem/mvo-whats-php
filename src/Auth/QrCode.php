<?php
declare(strict_types=1);
namespace MVO\Auth;
use BaconQrCode\{Writer,Renderer\ImageRenderer,Renderer\Image\SvgImageBackEnd,Renderer\RendererStyle\RendererStyle};
use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
final class QrCode
{
    public static function svg(string $payload, int $size = 360): string {
        if ($payload === '' || $size<128 || $size>2048) throw new \InvalidArgumentException('Invalid QR payload/size');
        return (new Writer(new ImageRenderer(new RendererStyle($size,4),new SvgImageBackEnd())))->writeString($payload);
    }
    /** Compact terminal QR: two module rows per Unicode half-block character. */
    public static function terminal(string $payload, bool $ansi = true): string {
        if ($payload === '') throw new \InvalidArgumentException('Invalid QR payload');
        $matrix = Encoder::encode($payload,ErrorCorrectionLevel::L(),'UTF-8')->getMatrix();
        $margin = 4; $size = $matrix->getWidth(); $width = $size+2*$margin;
        $black = static fn(int $x,int $y):bool => $x >= $margin && $x < $margin+$size
            && $y >= $margin && $y < $margin+$size && $matrix->get($x-$margin,$y-$margin) === 1;
        $out = '';
        for ($y=0;$y<$width;$y+=2) {
            $line = '';
            for ($x=0;$x<$width;$x++) {
                $top = $black($x,$y); $bottom = $black($x,$y+1);
                $line .= $top ? ($bottom ? '█' : '▀') : ($bottom ? '▄' : ' ');
            }
            // Explicit colours keep the white quiet zone visible in both light and dark themes.
            $out .= ($ansi ? "\033[30;47m" : '').$line.($ansi ? "\033[0m" : '')."\n";
        }
        return $out;
    }
}
