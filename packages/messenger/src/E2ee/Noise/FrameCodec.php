<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee\Noise;
final class FrameCodec
{
    public const HEADER = "WA\x06\x03";
    private bool $introSent = false;
    private string $buffer = '';
    public function __construct(private readonly string $intro = '', private readonly int $maxLength = 0xffffff) {
        if ($maxLength < 1 || $maxLength > 0xffffff) throw new \InvalidArgumentException('Invalid frame limit');
    }
    public function encode(string $frame): string {
        $n = strlen($frame);
        if ($n > $this->maxLength) throw new \LengthException('Frame too large');
        $prefix = $this->introSent ? '' : $this->intro; $this->introSent = true;
        return $prefix.substr(pack('N',$n),1).$frame;
    }
    /**
     * Incoming data excludes outgoing intro.
     * @return list<string>
     */
    public function push(string $chunk): array {
        $this->buffer .= $chunk; $frames = []; $offset = 0; $total = strlen($this->buffer);
        while ($total - $offset >= 3) {
            $n = unpack('N',"\0".substr($this->buffer,$offset,3))[1];
            if ($n > $this->maxLength) { $this->buffer = ''; throw new \LengthException('Incoming frame too large'); }
            if ($total - $offset < $n+3) break;
            $frames[] = substr($this->buffer,$offset+3,$n); $offset += 3+$n;
        }
        $this->buffer = substr($this->buffer,$offset); return $frames;
    }
    public function finish(): void { if ($this->buffer !== '') throw new \UnexpectedValueException('Truncated frame'); }
}
