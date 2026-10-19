<?php
declare(strict_types=1);

namespace MVO\Messenger\Protocol;

use MVO\Messenger\MessengerException;

/** Internal ordered LS dictionary; encoded keys prevent PHP scalar-key coercion. */
final class LightspeedDictionary
{
    /** @var list<array{mixed, mixed}> */
    private array $items = [];

    /** @var array<string, int> */
    private array $positions = [];

    public function __construct(private readonly int $maximumElements = 100000)
    {
        if ($maximumElements < 0) {
            throw new \InvalidArgumentException('The Lightspeed container limit must be non-negative');
        }
    }

    public function set(mixed $key, mixed $value): self
    {
        $encoded = $this->key($key);
        if ($encoded === null) {
            return $this;
        }
        if (array_key_exists($encoded, $this->positions)) {
            $this->items[$this->positions[$encoded]][1] = $value;
            return $this;
        }
        if (count($this->items) >= $this->maximumElements) {
            throw new MessengerException('lightspeed_limit', 'The Lightspeed dictionary exceeds its local element limit');
        }
        $this->positions[$encoded] = count($this->items);
        $this->items[] = [$key, $value];
        return $this;
    }

    public function get(mixed $key): mixed
    {
        $encoded = $this->key($key);
        if ($encoded === null || !array_key_exists($encoded, $this->positions)) {
            return new LightspeedUndefinedValue();
        }
        return $this->items[$this->positions[$encoded]][1];
    }

    /** @return list<array{mixed, mixed}> */
    public function entries(): array
    {
        return $this->items;
    }

    private function key(mixed $key): ?string
    {
        if ($key === null || $key instanceof LightspeedUndefinedValue) {
            return null;
        }
        if ($key instanceof LightspeedIntegerValue) {
            $decimal = $key->decimal;
            if (!preg_match('/^-?(?:0|[1-9][0-9]{0,18})$/D', $decimal)) {
                $this->invalidKey();
            }
            $negative = str_starts_with($decimal, '-');
            $magnitude = $negative ? substr($decimal, 1) : $decimal;
            $bound = $negative ? '9223372036854775808' : '9223372036854775807';
            if (strlen($magnitude) > strlen($bound) || (strlen($magnitude) === strlen($bound) && strcmp($magnitude, $bound) > 0)) {
                $this->invalidKey();
            }
            return 'i:' . ($decimal === '-0' ? '0' : $decimal);
        }
        if (is_string($key)) {
            return 's:' . $key;
        }
        if (is_bool($key)) {
            return $key ? 'b:1' : 'b:0';
        }
        if (is_int($key) || is_float($key)) {
            if (is_float($key) && !is_finite($key)) {
                $this->invalidKey();
            }
            // A JS number has one key type: normalize decimal notation, including 1/1.0 and -0/0.
            $number = json_encode($key, JSON_THROW_ON_ERROR);
            if (!preg_match('/^(-?)([0-9]+)(?:\.([0-9]+))?(?:[eE]([+-]?[0-9]+))?$/D', $number, $parts)) {
                $this->invalidKey();
            }
            $fraction = $parts[3] ?? '';
            $digits = ltrim($parts[2] . $fraction, '0');
            if ($digits === '') {
                return 'n:0';
            }
            $trimmed = rtrim($digits, '0');
            $exponent = (int)($parts[4] ?? '0') - strlen($fraction) + strlen($digits) - strlen($trimmed);
            return 'n:' . $parts[1] . $trimmed . 'e' . $exponent;
        }
        $this->invalidKey();
    }

    private function invalidKey(): never
    {
        throw new MessengerException('invalid_lightspeed_map_key', 'Unsupported Lightspeed dictionary key');
    }
}
