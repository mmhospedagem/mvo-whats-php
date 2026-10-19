<?php
declare(strict_types=1);
namespace MVO\Messenger\Protocol;

/** Explicitly distinguishes a demonstrated null/false result from an unknown result. */
final class LightspeedProcedureResult
{
    private function __construct(public readonly bool $known, private readonly mixed $value) {}
    public static function known(mixed $value): self { return new self(true,$value); }
    public static function unknown(): self { return new self(false,null); }
    public function value(): mixed
    {
        if (!$this->known) throw new \LogicException('No value exists for an unknown Lightspeed procedure result');
        return $this->value;
    }
    /** @return array{known:bool} */
    public function __debugInfo(): array { return ['known'=>$this->known]; }
}
