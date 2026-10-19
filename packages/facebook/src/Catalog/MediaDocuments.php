<?php
declare(strict_types=1);
namespace MVO\Facebook\Catalog;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\{Document,DocumentRegistry};

/** Public, source-bound Web media documents. Relay providers are current context, never defaults. */
final class MediaDocuments
{
    /** @return list<Document> */
    public static function documents(): array
    {
        $result=[];
        foreach(self::catalog() as $row)$result[]=new Document($row['name'],$row['id'],$row['kind'],$row['variables'],$row['defaults'],$row['source_sha256'],$row['source_url']);
        return $result;
    }
    /** @return list<string> */
    public static function providers(string $name): array
    {
        foreach(self::catalog() as $row)if($row['name']===$name)return $row['providers'];
        throw new FacebookException('facebook_document_unavailable','The media Web document was not observed');
    }
    /** @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public static function arguments(string $name,array $values):array
    {
        foreach(self::providers($name) as $provider)if(!array_key_exists($provider,$values)||!is_scalar($values[$provider])||(is_float($values[$provider])&&!is_finite($values[$provider])))throw new FacebookException('facebook_provider_context_required','Supply all current Relay provider values for this media document');
        return (new DocumentRegistry(self::documents()))->get($name)->arguments($values);
    }
    /** @return list<array{name:string,id:string,kind:string,variables:list<string>,defaults:array<string,mixed>,providers:list<string>,source_sha256:string,source_url:string}> */
    private static function catalog():array
    {
        /** @var list<array{name:string,id:string,kind:string,variables:list<string>,defaults:array<string,mixed>,providers:list<string>,source_sha256:string,source_url:string}>|null $cached */
        static $cached=null;
        if($cached!==null)return $cached;
        $raw=file_get_contents(dirname(__DIR__,2).'/resources/media-documents.json');
        if(!is_string($raw))throw new \RuntimeException('The media document catalog is missing');
        $data=json_decode($raw,true,64,JSON_THROW_ON_ERROR);
        if(!is_array($data)||!is_array($data['documents']??null)||!array_is_list($data['documents']))throw new \RuntimeException('Invalid media document catalog');
        return $cached=$data['documents'];
    }
}
