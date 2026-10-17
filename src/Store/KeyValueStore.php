<?php
declare(strict_types=1);
namespace MVO\Store;

/** Session-scoped binary persistence. Transactions must commit atomically or throw. */
interface KeyValueStore
{
    public function get(string $namespace, string $key): ?string;
    public function set(string $namespace, string $key, string $value): void;
    public function delete(string $namespace, string $key): void;
    /** @return array<string,string> Keys strictly after $after, in byte order. */
    public function entries(string $namespace, int $limit = 1000, string $after = ''): array;
    /** @template T
     * @param callable(static):T $operation
     * @return T */
    public function transaction(callable $operation): mixed;
    public function close(): void;
}
