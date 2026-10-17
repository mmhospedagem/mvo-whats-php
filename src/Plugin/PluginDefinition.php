<?php
declare(strict_types=1);
namespace MVO\Plugin;

/** A native PHP extension, installed once per deliberate connect/disconnect cycle. */
final readonly class PluginDefinition
{
    /** @var \Closure(PluginContext):mixed */
    public \Closure $setup;
    /** @var (\Closure(mixed,PluginContext):void)|null */
    public ?\Closure $dispose;
    /** @param callable(PluginContext):mixed $setup
     * @param callable(mixed,PluginContext):void|null $dispose */
    public function __construct(public string $id,callable $setup,?callable $dispose=null) {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_.-]{0,63}$/D',$id)) throw new \InvalidArgumentException('Invalid plugin ID');
        $this->setup=\Closure::fromCallable($setup); $this->dispose=$dispose===null ? null : \Closure::fromCallable($dispose);
    }
}
