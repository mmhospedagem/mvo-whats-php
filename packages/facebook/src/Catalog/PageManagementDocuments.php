<?php
declare(strict_types=1);

namespace MVO\Facebook\Catalog;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\Document;
use MVO\Facebook\Protocol\DocumentRegistry;

/** Public Web documents for Page information and access management; no Marketing API. */
final class PageManagementDocuments
{
    /** @return list<Document> */
    public static function documents(): array
    {
        $documents = [];
        foreach (self::catalog()['documents'] as $entry) {
            $documents[] = new Document($entry['name'], $entry['id'], $entry['kind'], $entry['variables'],
                $entry['defaults'], $entry['source_sha256'], $entry['source_url']);
        }
        return $documents;
    }

    public static function registry(): DocumentRegistry { return new DocumentRegistry(self::documents()); }

    /** @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function arguments(string $name, array $values): array
    {
        foreach(self::providers($name) as $provider)if(!array_key_exists($provider,$values)||!is_bool($values[$provider])){
                throw new FacebookException('facebook_provider_context_required','Supply the current Web Relay provider values before this operation');
        }
        return self::registry()->get($name)->arguments($values);
    }
    /** @return list<string> */
    public static function providers(string $name):array
    {
        foreach(self::catalog()['documents'] as $entry)if($entry['name']===$name)return $entry['providers'];
        self::registry()->get($name);return [];
    }

    /** @return array<string,array{event:string,metric:string}> */
    public static function insightMetrics():array{return self::catalog()['insight_metrics'];}
    /** @return list<string> */
    public static function insightBreakdowns():array{return array_keys(self::catalog()['insight_breakdowns']);}

    /** Values use the enum keys passed by the shipped inline editor, not translated display strings. */
    public static function editContext(string $endPoint, string $entryPoint): void
    {
        $catalog = self::catalog();
        if (!in_array($endPoint, $catalog['edit_endpoints'], true) || !in_array($entryPoint, $catalog['edit_entry_points'], true)) {
            throw new \InvalidArgumentException('Use an observed Page edit endpoint and entry-point enum');
        }
    }

    /** @return array{edit_endpoints:list<string>,edit_entry_points:list<string>,insight_metrics:array<string,array{event:string,metric:string}>,insight_breakdowns:array<string,string>,documents:list<array{name:string,id:string,kind:string,variables:list<string>,defaults:array<string,mixed>,providers:list<string>,source_sha256:string,source_url:string}>} */
    private static function catalog(): array
    {
        static $catalog = null;
        if ($catalog !== null) return $catalog;
        $raw = file_get_contents(dirname(__DIR__, 2).'/resources/page-management-documents.json');
        if (!is_string($raw)) throw new \RuntimeException('The Page management Web document catalog is missing');
        $decoded = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded) || !is_array($decoded['documents'] ?? null) || !array_is_list($decoded['documents'])
            || !is_array($decoded['edit_endpoints'] ?? null) || !is_array($decoded['edit_entry_points'] ?? null)) {
            throw new \RuntimeException('Invalid Page management Web document catalog');
        }
        return $catalog = $decoded;
    }
}
