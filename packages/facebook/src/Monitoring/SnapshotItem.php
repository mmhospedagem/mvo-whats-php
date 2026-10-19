<?php
declare(strict_types=1);

namespace MVO\Facebook\Monitoring;

/** A normalized projection, never raw HTML, GraphQL nodes or authentication data. */
final readonly class SnapshotItem
{
    private const FIELDS = ['text', 'message', 'name', 'author_id', 'parent_id', 'created_time', 'updated_time', 'reaction_type', 'permalink_url', 'status', 'object_type'];

    /** Missing fields/counters are unknown; explicit null clears a known field.
     * @param array<string,string|int|bool|null> $fields
     * @param array<string,int> $counters
     */
    public function __construct(public string $id, public array $fields = [], public array $counters = [])
    {
        if ($id === '' || strlen($id) > 512 || preg_match('/[\x00-\x20\x7f]/', $id) === 1 || !preg_match('//u', $id)) {
            throw new \InvalidArgumentException('Invalid normalized Facebook item ID');
        }
        foreach ($fields as $key => $value) {
            if (!in_array($key, self::FIELDS, true) || (!is_string($value) && !is_int($value) && !is_bool($value) && $value !== null)) {
                throw new \InvalidArgumentException('Unsupported normalized monitor field');
            }
            if (is_string($value) && (strlen($value) > 1_048_576 || !preg_match('//u', $value))) {
                throw new \InvalidArgumentException('Invalid normalized monitor text');
            }
        }
        foreach ($counters as $key => $value) {
            if (!is_string($key) || preg_match('/^[a-z][a-z0-9_]{0,63}$/D', $key) !== 1 || !is_int($value) || $value < 0) {
                throw new \InvalidArgumentException('Monitor counters must be named nonnegative integers');
            }
        }
    }

    /** @return array{id:string,fields:array<string,string|int|bool|null>,counters:array<string,int>} */
    public function toArray(): array { return ['id' => $this->id, 'fields' => $this->fields, 'counters' => $this->counters]; }
    /** Do not accidentally print monitored text through object debugging. */
    public function __debugInfo(): array { return ['id' => $this->id, 'field_count' => count($this->fields), 'counter_count' => count($this->counters)]; }
}
