<?php
declare(strict_types=1);
namespace MVO\Service;

/** The supported input subset recovered from native callers, not a GraphQL introspection schema. */
final class BusinessAgentInput
{
    /** @param array<string,mixed> $schema */
    public static function validate(mixed $value,array $schema,string $path='variables'):void
    {
        if(isset($schema['oneOf'])){
            $matches=0;
            foreach($schema['oneOf']as$branch){try{self::validate($value,$branch,$path);++$matches;}catch(\InvalidArgumentException){}}
            if($matches!==1)throw new \InvalidArgumentException($path.' does not match one supported input variant');
        }
        $type=$schema['type']??null;
        $valid=match($type){
            'object'=>is_array($value)&&($value===[]||!array_is_list($value)),
            'array'=>is_array($value)&&array_is_list($value),
            'string'=>is_string($value),'boolean'=>is_bool($value),'integer'=>is_int($value),
            default=>true,
        };
        if(!$valid)throw new \InvalidArgumentException($path.' must be '.$type);
        if(isset($schema['enum'])&&!in_array($value,$schema['enum'],true))throw new \InvalidArgumentException($path.' has an unsupported value');
        if($type==='object'){
            if(count($value)<($schema['minProperties']??0))throw new \InvalidArgumentException($path.' is empty');
            foreach($schema['required']??[]as$key)if(!array_key_exists($key,$value))throw new \InvalidArgumentException($path.'.'.$key.' is required');
            foreach($value as$key=>$child){
                $property=$schema['properties'][$key]??null;
                if($property===null&&($schema['additionalProperties']??true)===false)throw new \InvalidArgumentException($path.' has an unknown field');
                if(is_array($property))self::validate($child,$property,$path.'.'.$key);
            }
        }elseif($type==='array'){
            if(count($value)<($schema['minItems']??0))throw new \InvalidArgumentException($path.' has too few items');
            foreach($value as$key=>$child)self::validate($child,$schema['items']??[],$path.'['.$key.']');
        }elseif($type==='integer'){
            if(isset($schema['minimum'])&&$value<$schema['minimum'])throw new \InvalidArgumentException($path.' is below the minimum');
            if(isset($schema['maximum'])&&$value>$schema['maximum'])throw new \InvalidArgumentException($path.' exceeds the maximum');
        }
    }
}
