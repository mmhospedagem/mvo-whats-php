<?php
declare(strict_types=1);
namespace MVO\Protocol;
/** WAP dictionary v3. Strings represent bytes; UTF-8 conversion is the caller's responsibility. */
final class BinaryCodec
{
    /** @var list<string> */
    private array $single;
    /** @var list<list<string>> */
    private array $dictionaries;
    /** @var array<string,string> */
    private array $map = [];
    private string $data = '';
    private int $offset = 0;
    private int $wordDepth = 0;
    public function __construct(private readonly int $maxBytes = 16777215, private readonly int $maxDepth = 64) {
        if ($maxBytes < 1 || $maxDepth < 1) throw new \InvalidArgumentException('Invalid codec limits');
        $tables = json_decode(file_get_contents(__DIR__.'/../../resources/tokens.json'),true,512,JSON_THROW_ON_ERROR);
        $this->single = $tables['single']; $this->dictionaries = $tables['dictionaries'];
        foreach ($this->single as $i=>$word) $this->map[$word] ??= chr($i+1);
        foreach ($this->dictionaries as $d=>$words) foreach ($words as $i=>$word) $this->map[$word] ??= chr(236+$d).chr($i);
    }
    private function listSize(int $n): string {
        if ($n < 0 || $n > 65535) throw new \LengthException('List too large');
        return $n < 256 ? "\xf8".chr($n) : "\xf9".pack('n',$n);
    }
    private function raw(string $value): string {
        $n = strlen($value);
        if ($n > $this->maxBytes) throw new \LengthException('Value too large');
        return ($n < 256 ? "\xfc".chr($n) : ($n < 1048576 ? "\xfd".substr(pack('N',$n),1) : "\xfe".pack('N',$n))).$value;
    }
    private function word(string $value, bool $jid = false): string {
        if (isset($this->map[$value])) return $this->map[$value];
        if ($jid && str_contains($value,'@')) {
            $j = Jid::parse($value);
            $domain = ['s.whatsapp.net'=>0,'lid'=>1,'hosted'=>128,'hosted.lid'=>129][$j->server] ?? null;
            if ($j->device > 0 && $j->device < 256 && $domain !== null) return "\xf7".chr($domain).chr($j->device).$this->word($j->user);
            $user = $j->user.($j->device ? ':'.$j->device : '');
            return "\xfa".$this->word($user).$this->word($j->server);
        }
        if ($value !== '' && strlen($value) < 128 && preg_match('/^[0-9A-F.\-]+$/D',$value)) {
            $nibble = (bool)preg_match('/^[0-9.\-]+$/D',$value);
            $alphabet = $nibble ? '0123456789-.' : '0123456789ABCDEF';
            $out = chr($nibble ? 255 : 251).chr((int)ceil(strlen($value)/2) | (strlen($value)%2 ? 128 : 0));
            for ($i=0;$i<strlen($value);$i+=2) $out .= chr((strpos($alphabet,$value[$i])<<4) | (isset($value[$i+1]) ? strpos($alphabet,$value[$i+1]) : 15));
            return $out;
        }
        return $this->raw($value);
    }
    public function encode(BinaryNode $node, bool $stanza = false): string {
        $bytes = ($stanza ? "\0" : '').$this->writeNode($node,0);
        if (strlen($bytes)>$this->maxBytes) throw new \LengthException('Node too large');
        return $bytes;
    }
    private function writeNode(BinaryNode $node,int $depth): string {
        if ($depth >= $this->maxDepth) throw new \LengthException('Node too deep');
        $out = $this->listSize(1+2*count($node->attrs)+($node->content !== null ? 1 : 0)).$this->word($node->tag);
        foreach ($node->attrs as $key=>$value) $out .= $this->word($key).$this->word($value,true);
        if (is_string($node->content)) $out .= $this->raw($node->content);
        elseif (is_array($node->content)) {
            $out .= $this->listSize(count($node->content));
            foreach ($node->content as $child) $out .= $this->writeNode($child,$depth+1);
        }
        return $out;
    }
    private function take(int $n): string {
        if ($n < 0 || $n > strlen($this->data)-$this->offset) throw new \UnexpectedValueException('Truncated binary node');
        $s = substr($this->data,$this->offset,$n); $this->offset += $n; return $s;
    }
    private function byte(): int { return ord($this->take(1)); }
    private function readList(int $token): int {
        return match($token) {0=>0,248=>$this->byte(),249=>unpack('n',$this->take(2))[1],default=>throw new \UnexpectedValueException('Expected list')};
    }
    private function readWord(?int $token = null): string {
        if ($this->wordDepth >= $this->maxDepth) throw new \LengthException('Token nesting too deep');
        $this->wordDepth++;
        try { return $this->readWordInternal($token); }
        finally { $this->wordDepth--; }
    }
    private function readWordInternal(?int $token = null): string {
        $token ??= $this->byte();
        if ($token > 0 && $token < 236) return $this->single[$token-1] ?? throw new \UnexpectedValueException('Unknown token');
        if ($token >= 236 && $token <= 239) return $this->dictionaries[$token-236][$this->byte()] ?? throw new \UnexpectedValueException('Unknown dictionary token');
        if ($token === 0) return '';
        if ($token === 250) { $user = $this->readWord(); $server = $this->readWord(); return $user === '' ? $server : $user.'@'.$server; }
        if ($token === 247) {
            $domain = $this->byte(); $device = $this->byte(); $user = $this->readWord();
            $server = match($domain) {1=>'lid',129=>'hosted.lid',default=>(($domain & 128) && !($domain & 1)) ? 'hosted' : 's.whatsapp.net'};
            return $user.($device ? ':'.$device : '').'@'.$server;
        }
        if ($token === 245 || $token === 246) {
            $user = $this->readWord(); $device = unpack('n',$this->take(2))[1];
            $integrator = $token === 245 ? unpack('n',$this->take(2))[1].'-' : '';
            $this->readWord(); return $integrator.$user.':'.$device.'@'.($token === 245 ? 'interop' : 'msgr');
        }
        if ($token === 251 || $token === 255) {
            $length = $this->byte(); $odd = (bool)($length & 128); $n = $length & 127;
            if ($odd && $n === 0) throw new \UnexpectedValueException('Invalid packed length');
            $alphabet = $token === 251 ? '0123456789ABCDEF' : '0123456789-.'; $out = '';
            for ($i=0;$i<$n;$i++) {
                $b = $this->byte(); $hi = $b>>4; $lo = $b & 15;
                if (!isset($alphabet[$hi])) throw new \UnexpectedValueException('Invalid packed digit');
                $out .= $alphabet[$hi];
                if ($i === $n-1 && $odd) { if ($lo !== 15) throw new \UnexpectedValueException('Invalid packed padding'); }
                else { if (!isset($alphabet[$lo])) throw new \UnexpectedValueException('Invalid packed digit'); $out .= $alphabet[$lo]; }
            }
            return $out;
        }
        $n = match($token) {252=>$this->byte(),253=>(($this->byte() & 15)<<16)|unpack('n',$this->take(2))[1],254=>unpack('N',$this->take(4))[1],default=>throw new \UnexpectedValueException('Unsupported string token')};
        if ($n > $this->maxBytes) throw new \LengthException('Value too large');
        return $this->take($n);
    }
    private function readNode(int $depth): BinaryNode {
        if ($depth >= $this->maxDepth) throw new \LengthException('Node too deep');
        $size = $this->readList($this->byte());
        if ($size < 1) throw new \UnexpectedValueException('Empty node');
        $tag = $this->readWord(); $attrs = [];
        for ($i=0;$i<intdiv($size-1,2);$i++) {
            $key = $this->readWord(); $value = $this->readWord();
            if (array_key_exists($key,$attrs)) throw new \UnexpectedValueException('Duplicate attribute');
            $attrs[$key] = $value;
        }
        $content = null;
        if ($size % 2 === 0) {
            $token = $this->byte();
            if (in_array($token,[0,248,249],true)) {
                $n = $this->readList($token); $content = [];
                for ($i=0;$i<$n;$i++) $content[] = $this->readNode($depth+1);
            } else $content = $this->readWord($token);
        }
        return new BinaryNode($tag,$attrs,$content);
    }
    public function decode(string $bytes, bool $stanza = false): BinaryNode {
        if (strlen($bytes)>$this->maxBytes) throw new \LengthException('Node too large');
        if ($stanza) {
            if ($bytes === '') throw new \UnexpectedValueException('Missing stanza flags');
            $flag = ord($bytes[0]); $bytes = substr($bytes,1);
            if (($flag & ~2) !== 0) throw new \UnexpectedValueException('Unsupported stanza flags');
            if ($flag & 2) {
                $plain = @zlib_decode($bytes,$this->maxBytes);
                if ($plain === false) $plain = @gzinflate($bytes,$this->maxBytes);
                if ($plain === false) throw new \UnexpectedValueException('Invalid compressed stanza');
                $bytes = $plain;
            }
        }
        $this->data = $bytes; $this->offset = 0;
        try {
            $node = $this->readNode(0);
            if ($this->offset !== strlen($bytes)) throw new \UnexpectedValueException('Trailing bytes');
            return $node;
        } finally { $this->data = ''; $this->offset = 0; }
    }
}
