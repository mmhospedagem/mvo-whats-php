<?php
declare(strict_types=1);
namespace MVO\Protocol;
final readonly class Jid implements \Stringable
{
    public function __construct(public string $user, public string $server, public int $device = 0) {
        if ($user === '' || $server === '' || preg_match('/[@:\s]/', $user.$server) || $device < 0) throw new \InvalidArgumentException('Invalid JID');
    }
    public static function parse(string $jid): self {
        if (!preg_match('/^([^@:\s]+)(?::([0-9]+))?@([^@:\s]+)$/D', $jid, $m)) throw new \InvalidArgumentException('Invalid JID');
        $device = $m[2] === '' ? 0 : filter_var($m[2], FILTER_VALIDATE_INT, ['options'=>['min_range'=>0]]);
        if ($device === false) throw new \InvalidArgumentException('Invalid device');
        return new self($m[1], $m[3], $device);
    }
    public static function recipient(string $input): self {
        $input = trim($input);
        if (str_contains($input, '@')) return self::parse($input);
        if (preg_match('/^[0-9]+-[0-9]+$/D', $input)) return new self($input, 'g.us');
        if (!preg_match('/^\+?[0-9 ()-]+$/D', $input)) throw new \InvalidArgumentException('Invalid recipient');
        return new self(preg_replace('/[^0-9]/', '', $input), 's.whatsapp.net');
    }
    public function bare(): string { return "$this->user@$this->server"; }
    public function canonical(): self { return new self($this->user, match($this->server) {'hosted'=>'s.whatsapp.net','hosted.lid'=>'lid',default=>$this->server}, $this->device); }
    public function __toString(): string { return $this->user . ($this->device ? ':' . $this->device : '') . '@' . $this->server; }
}
