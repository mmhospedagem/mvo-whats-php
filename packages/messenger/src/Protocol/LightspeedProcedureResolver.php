<?php
declare(strict_types=1);
namespace MVO\Messenger\Protocol;

/** Batch-local interpretation only: implementations must not write storage or perform network I/O. */
interface LightspeedProcedureResolver
{
    public function beginBatch(): void;
    /** @param list<mixed> $arguments */
    public function resolve(string $procedure,array $arguments): LightspeedProcedureResult;
    /** Validate local effects before the decoder returns any calls to the persistent projector. */
    public function finishBatch(): void;
}
