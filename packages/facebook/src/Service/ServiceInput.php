<?php
declare(strict_types=1);

namespace MVO\Facebook\Service;

use MVO\Facebook\FacebookException;

/** Internal validation and deliberate projections shared by the Page services. */
final class ServiceInput
{
    public static function actor(string $id): string
    {
        if (!preg_match('/^[1-9][0-9]{0,24}$/D', $id)) throw new \InvalidArgumentException('Use the exact numeric Facebook actor or Page ID');
        return $id;
    }

    public static function opaque(string $value, string $field, int $maximum = 4096): string
    {
        if ($value === '' || strlen($value) > $maximum || str_contains($value, "\0")) {
            throw new \InvalidArgumentException('Invalid '.$field);
        }
        return $value;
    }

    public static function text(string $text): string
    {
        $text = trim($text);
        if ($text === '' || strlen($text) > 60000 || !preg_match('//u', $text)) throw new \InvalidArgumentException('Supply nonempty UTF-8 text within the SDK limit');
        return $text;
    }

    public static function key(string $key): string
    {
        return self::opaque($key, 'idempotency key', 256);
    }

    /** The Web client uses an opaque string for its client mutation and idempotence tokens. */
    public static function mutationId(string $key): string
    {
        return substr(hash('sha256', self::key($key)), 0, 24);
    }

    /** @param array<string,mixed> $values
     * @param list<string> $allowed
     */
    public static function keys(array $values, array $allowed): void
    {
        if (array_diff(array_keys($values), $allowed) !== []) throw new \InvalidArgumentException('Unknown field for this Facebook operation context');
    }

    /** @param array<string,mixed> $values */
    public static function requiredString(array $values, string $key): string
    {
        if (!is_string($values[$key] ?? null)) throw new FacebookException('facebook_operation_context_required', 'Supply the current Web operation context');
        return self::opaque($values[$key], $key);
    }

    /** @param array<string,mixed> $values
     * @return array<mixed>
     */
    public static function requiredArray(array $values, string $key): array
    {
        if (!is_array($values[$key] ?? null)) throw new FacebookException('facebook_operation_context_required', 'Supply the current Web operation context');
        return $values[$key];
    }

    public static function scalarId(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : (is_int($value) && $value > 0 ? (string)$value : null);
    }

    /** @param array<string,mixed> $value
     * @param list<string> $fields
     * @return array<string,mixed>
     */
    public static function select(array $value, array $fields): array
    {
        return array_intersect_key($value, array_fill_keys($fields, true));
    }

    /** @return never */
    public static function unexpected(bool $mutation = false): never
    {
        throw new FacebookException('facebook_response_shape_unknown', 'The Web response did not match the established operation contract', $mutation ? 'not_confirmed' : 'not_executed');
    }
}
