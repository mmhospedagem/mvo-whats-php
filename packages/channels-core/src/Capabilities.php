<?php
declare(strict_types=1);

namespace MVO\Channels;

/** Explicit support declarations; absence means unsupported by the common adapter contract. */
final readonly class Capabilities implements \JsonSerializable
{
    /** @var list<string> */
    private array $names;

    /** @param list<string> $names */
    public function __construct(array $names)
    {
        if (!array_is_list($names)) {
            throw new \InvalidArgumentException('Capabilities must be a list of names');
        }
        foreach ($names as $name) {
            if (!is_string($name) || preg_match('/^[a-z][a-z0-9_.-]{0,95}$/D', $name) !== 1) {
                throw new \InvalidArgumentException('Invalid capability name');
            }
        }
        $this->names = array_values(array_unique($names));
    }

    public function supports(string $name): bool
    {
        return in_array($name, $this->names, true);
    }

    /** @return list<string> */
    public function all(): array
    {
        return $this->names;
    }

    /** @return list<string> */
    public function jsonSerialize(): array
    {
        return $this->names;
    }
}
