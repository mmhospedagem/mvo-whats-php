<?php
declare(strict_types=1);

namespace MVO\History;

/** Callback adapter for a private, synchronous history consumer. */
final class CallbackHistorySyncSink implements HistorySyncSink
{
    /** @var \Closure(array{kind:string,value:mixed,conversationId?:string},array<string,mixed>):void */
    private readonly \Closure $onItem;
    /** @var (\Closure(array<string,mixed>):void)|null */
    private readonly ?\Closure $onCommit;
    /** @var (\Closure(array<string,mixed>,\Throwable):void)|null */
    private readonly ?\Closure $onAbort;

    /**
     * @param callable(array{kind:string,value:mixed,conversationId?:string},array<string,mixed>):void $item
     * @param (callable(array<string,mixed>):void)|null $commit
     * @param (callable(array<string,mixed>,\Throwable):void)|null $abort
     */
    public function __construct(callable $item, ?callable $commit = null, ?callable $abort = null)
    {
        $this->onItem = \Closure::fromCallable($item);
        $this->onCommit = $commit === null ? null : \Closure::fromCallable($commit);
        $this->onAbort = $abort === null ? null : \Closure::fromCallable($abort);
    }

    public function item(array $item, array $chunk): void { ($this->onItem)($item, $chunk); }
    public function commit(array $chunk): void { if ($this->onCommit !== null) ($this->onCommit)($chunk); }
    public function abort(array $chunk, \Throwable $error): void { if ($this->onAbort !== null) ($this->onAbort)($chunk, $error); }
}
