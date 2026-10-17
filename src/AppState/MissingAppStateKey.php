<?php
declare(strict_types=1);
namespace MVO\AppState;
final class MissingAppStateKey extends \RuntimeException
{
    /** @var list<string> */
    public readonly array $keyIds;

    /** @param list<string> $keyIds */
    public function __construct(public readonly string $keyId, array $keyIds=[])
    {
        $this->keyIds=array_values(array_unique([$keyId,...$keyIds],SORT_STRING));
        parent::__construct('Missing app-state sync key'.(count($this->keyIds)===1?' ':'s ').implode(', ',array_map(bin2hex(...),$this->keyIds)));
    }
}

