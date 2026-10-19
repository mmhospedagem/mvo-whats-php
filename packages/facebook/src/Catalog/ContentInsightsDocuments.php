<?php
declare(strict_types=1);
namespace MVO\Facebook\Catalog;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\{Document,DocumentRegistry};

/** Observed Business Suite content-table contracts, separate from distribution totals. */
final class ContentInsightsDocuments
{
    /** @return list<Document> */
    public static function documents():array
    {
        $result=[];
        foreach(self::catalog()['documents'] as $row)$result[]=new Document($row['name'],$row['id'],$row['kind'],$row['variables'],$row['defaults'],$row['source_sha256'],$row['source_url']);
        return $result;
    }
    public static function registry():DocumentRegistry{return new DocumentRegistry(self::documents());}
    /** @return list<string> */
    public static function providers(string $name):array
    {
        foreach(self::catalog()['documents'] as $row)if($row['name']===$name)return $row['providers'];
        self::registry()->get($name);return [];
    }
    /** @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function arguments(string $name,array $values):array
    {
        foreach(self::providers($name) as $key)if(!array_key_exists($key,$values)||!is_bool($values[$key]))throw new FacebookException('facebook_provider_context_required','Supply the current content-table Relay provider values before reading metrics');
        return self::registry()->get($name)->arguments($values);
    }
    /** Exact column-to-row-field mapping in BizWebUnifiedTableRowFields.react.
     * @return array<string,string>
     */
    public static function columnFields():array{return self::catalog()['column_fields'];}
    /** @return array{column_fields:array<string,string>,documents:list<array{name:string,id:string,kind:string,variables:list<string>,defaults:array<string,mixed>,providers:list<string>,source_sha256:string,source_url:string}>} */
    private static function catalog():array
    {
        static $catalog=null;
        if($catalog!==null)return $catalog;
        $raw=file_get_contents(dirname(__DIR__,2).'/resources/content-insights-documents.json');
        if(!is_string($raw))throw new \RuntimeException('The content-table catalog is missing');
        $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data)||!is_array($data['documents']??null)||!array_is_list($data['documents'])||!is_array($data['column_fields']??null))throw new \RuntimeException('Invalid content-table document catalog');
        return $catalog=$data;
    }
}
