<?php
declare(strict_types=1);

namespace MVO\Facebook\Catalog;

use MVO\Facebook\Protocol\Document;

/** Explicit source-pinned Web queries, never a fallback to Marketing Graph API. */
final class AdsDocuments
{
    /** @return list<Document> */
    public static function documents(): array
    {
        $file = dirname(__DIR__, 2) . '/resources/ads-documents.json';
        $json = file_get_contents($file);
        if ($json === false || strlen($json) > 1048576) {
            throw new \RuntimeException('The Facebook advertising document catalog is unavailable');
        }
        $catalog = json_decode($json, true, 32, JSON_THROW_ON_ERROR);
        if (!is_array($catalog) || ($catalog['schema'] ?? null) !== 1 || !is_array($catalog['documents'] ?? null) || !array_is_list($catalog['documents'])) {
            throw new \UnexpectedValueException('Invalid Facebook advertising document catalog');
        }
        $documents = [];
        foreach ($catalog['documents'] as $entry) {
            if (!is_array($entry) || ($entry['kind'] ?? null) !== 'query' || !is_string($entry['name'] ?? null) || !is_string($entry['id'] ?? null)
                || !is_array($entry['variables'] ?? null) || !array_is_list($entry['variables']) || !is_array($entry['defaults'] ?? null)
                || (isset($entry['fixed_variables']) && !is_array($entry['fixed_variables']))
                || !is_string($entry['source_sha256'] ?? null) || !is_string($entry['source_url'] ?? null)) {
                throw new \UnexpectedValueException('Invalid Facebook advertising query entry');
            }
            foreach ($entry['variables'] as $variable) {
                if (!is_string($variable)) {
                    throw new \UnexpectedValueException('Invalid Facebook advertising query variable');
                }
            }
            $documents[] = new Document($entry['name'], $entry['id'], 'query', $entry['variables'], $entry['defaults'], $entry['source_sha256'], $entry['source_url'], $entry['fixed_variables'] ?? []);
        }
        return $documents;
    }
}
