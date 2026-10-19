<?php
declare(strict_types=1);
namespace MVO\Messenger\Protocol;

/** No guessed result is exposed for an unimplemented procedure. */
final class LightspeedUnresolvedValue
{
    public function __construct(public readonly ?string $procedure=null){}
}
