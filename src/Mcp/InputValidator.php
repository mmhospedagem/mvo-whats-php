<?php
declare(strict_types=1);
namespace MVO\Mcp;

/** Bounded JSON-schema subset used by the native server's own tool definitions. */
final class InputValidator
{
    /** @param array<string,mixed> $schema */
    public static function validate(mixed $value,array $schema,int $depth=0): void
    {
        if($depth>48) throw new \InvalidArgumentException('MCP argument nesting limit');
        if(isset($schema['anyOf'])) { foreach($schema['anyOf'] as $option) { try { self::validate($value,$option,$depth+1);return; }catch(\InvalidArgumentException) {} }throw new \InvalidArgumentException('MCP argument does not match schema'); }
        $type=$schema['type'] ?? null;
        $valid=match($type) { 'string'=>is_string($value),'integer'=>is_int($value),'number'=>(is_int($value) || is_float($value)) && is_finite((float)$value),'boolean'=>is_bool($value),'null'=>$value===null,'object'=>is_array($value) && ($value===[] || !array_is_list($value)),'array'=>is_array($value) && array_is_list($value),null=>true,default=>false };
        if(!$valid || (isset($schema['enum']) && !in_array($value,$schema['enum'],true))) throw new \InvalidArgumentException('Invalid MCP argument type/value');
        if(is_string($value) && (strlen($value)>($schema['maxLength'] ?? 1048576) || strlen($value)<($schema['minLength'] ?? 0))) throw new \InvalidArgumentException('MCP string length limit');
        if((is_int($value) || is_float($value)) && ($value<($schema['minimum'] ?? -INF) || $value>($schema['maximum'] ?? INF))) throw new \InvalidArgumentException('MCP numeric range limit');
        if(!is_array($value)) return;if(count($value)>($schema['maxItems'] ?? 10000)) throw new \InvalidArgumentException('MCP collection limit');
        if($type==='object') {
            foreach($schema['required'] ?? [] as $key) if(!array_key_exists($key,$value)) throw new \InvalidArgumentException('Required MCP argument missing: '.$key);
            foreach($value as $key=>$item) { $child=$schema['properties'][$key] ?? null;if($child===null && ($schema['additionalProperties'] ?? true)===false) throw new \InvalidArgumentException('Unknown MCP argument');self::validate($item,$child ?? [],$depth+1); }
        } else foreach($value as $item) self::validate($item,$schema['items'] ?? [],$depth+1);
    }
}
