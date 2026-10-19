<?php
declare(strict_types=1);

namespace MVO\Messenger\Protocol;

use MVO\Messenger\MessengerException;

/** Internal mutable LS array. Object entries retain their identity in a shallow copy. */
final class LightspeedVector
{
    /** @var list<mixed> */
    public array $values;

    /** @param list<mixed> $values */
    public function __construct(array $values = [], private readonly int $maximumElements = 100000)
    {
        if ($maximumElements < 0) {
            throw new \InvalidArgumentException('The Lightspeed container limit must be non-negative');
        }
        if (!array_is_list($values)) {
            throw new MessengerException('invalid_lightspeed_container', 'A Lightspeed array must contain a list of values');
        }
        if (count($values) > $maximumElements) {
            throw new MessengerException('lightspeed_limit', 'The Lightspeed array exceeds its local element limit');
        }
        $this->values = $values;
    }

    public function append(mixed $value): self
    {
        if (count($this->values) >= $this->maximumElements) {
            throw new MessengerException('lightspeed_limit', 'The Lightspeed array exceeds its local element limit');
        }
        $this->values[] = $value;
        return $this;
    }
}
