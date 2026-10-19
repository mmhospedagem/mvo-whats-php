<?php
declare(strict_types=1);

namespace MVO\Facebook\Catalog;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\Document;
use MVO\Facebook\Protocol\DocumentRegistry;

/** Observed Comet content mutations. Account-specific Relay providers remain mandatory. */
final class ContentManagementDocuments
{
    /** @return list<Document> */
    public static function documents(): array
    {
        $result = [];
        foreach (self::catalog() as $entry) {
            $result[] = new Document($entry['name'], $entry['id'], $entry['kind'], $entry['variables'],
                $entry['defaults'], $entry['source_sha256'], $entry['source_url']);
        }
        return $result;
    }

    public static function registry(): DocumentRegistry { return new DocumentRegistry(self::documents()); }

    /** @return list<string> */
    public static function providers(string $name): array
    {
        foreach (self::catalog() as $entry) if ($entry['name'] === $name) return $entry['providers'];
        throw new FacebookException('facebook_document_unavailable', 'The content Web document was not observed');
    }

    /** @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function arguments(string $name, array $values): array
    {
        foreach (self::providers($name) as $provider) {
            if (!array_key_exists($provider, $values) || !is_scalar($values[$provider])
                || (is_float($values[$provider]) && !is_finite($values[$provider]))) {
                throw new FacebookException('facebook_provider_context_required', 'Supply all current Relay provider values for this Web document');
            }
        }
        return self::registry()->get($name)->arguments($values);
    }

    /** @return list<array{name:string,id:string,kind:string,variables:list<string>,defaults:array<string,mixed>,providers:list<string>,source_sha256:string,source_url:string}> */
    private static function catalog(): array
    {
        /** @var list<array{name:string,id:string,kind:string,variables:list<string>,defaults:array<string,mixed>,providers:list<string>,source_sha256:string,source_url:string}>|null $cached */
        static $cached = null;
        if ($cached !== null) return $cached;
        $raw = file_get_contents(dirname(__DIR__, 2).'/resources/content-management-documents.json');
        if (!is_string($raw)) throw new \RuntimeException('The content document catalog is missing');
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['documents'] ?? null) || !array_is_list($decoded['documents'])) {
            throw new \RuntimeException('Invalid content document catalog');
        }
        return $cached = $decoded['documents'];
    }
}
