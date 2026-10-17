<?php
declare(strict_types=1);
namespace MVO\Protocol;
final readonly class BinaryNode
{
    /**
     * @param array<string,string> $attrs
     * @param list<BinaryNode>|string|null $content
     */
    public function __construct(public string $tag, public array $attrs = [], public array|string|null $content = null) {
        if ($tag === '') throw new \InvalidArgumentException('Empty tag');
        foreach ($attrs as $k=>$v) if (!is_string($k) || $k === '' || !is_string($v)) throw new \InvalidArgumentException('Attributes must be strings');
        if (is_array($content)) foreach ($content as $child) if (!$child instanceof self) throw new \InvalidArgumentException('Invalid child');
    }
    public function child(string $tag): ?self {
        if (is_array($this->content)) foreach ($this->content as $child) if ($child->tag === $tag) return $child;
        return null;
    }
}
