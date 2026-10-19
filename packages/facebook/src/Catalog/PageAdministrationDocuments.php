<?php
declare(strict_types=1);

namespace MVO\Facebook\Catalog;

use MVO\Facebook\Protocol\Document;
use MVO\Facebook\Protocol\DocumentRegistry;
use MVO\Facebook\FacebookException;

/** Observed Page-role and Page moderation Web bindings, separate from portfolio task IDs. */
final class PageAdministrationDocuments
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

    /** CUSTOM is readable, but the observed UI disables changes/removal of that role.
     * @return array<string,int>
     */
    public static function roles(): array { return self::catalog()['page_roles']; }

    /** @return list<string> */
    public static function providers(string $name):array
    {
        foreach(self::catalog()['documents']as $entry)if($entry['name']===$name)return $entry['providers'];
        self::registry()->get($name);return [];
    }

    /** @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function arguments(string $name,array $values):array
    {
        foreach(self::providers($name)as $provider)if(!is_bool($values[$provider]??null)){
            throw new FacebookException('facebook_provider_context_required','Supply the current Web Relay provider before this administration operation');
        }
        return self::registry()->get($name)->arguments($values);
    }

    /** @return array{page_roles:array<string,int>,documents:list<array{name:string,id:string,kind:string,variables:list<string>,defaults:array<string,mixed>,providers:list<string>,source_sha256:string,source_url:string}>} */
    private static function catalog(): array
    {
        static $catalog = null;
        if ($catalog !== null) return $catalog;
        $raw = file_get_contents(dirname(__DIR__, 2).'/resources/page-administration-documents.json');
        if (!is_string($raw)) throw new \RuntimeException('The Page administration Web document catalog is missing');
        $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($value) || !is_array($value['documents'] ?? null) || !array_is_list($value['documents'])
            || !is_array($value['page_roles'] ?? null)) throw new \RuntimeException('Invalid Page administration document catalog');
        return $catalog = $value;
    }
}
