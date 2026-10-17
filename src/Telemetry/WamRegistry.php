<?php
declare(strict_types=1);
namespace MVO\Telemetry;

/** 426 named events, 46 globals and 862 enums from the pinned reference registry. */
final class WamRegistry
{
    /** @var array<string,mixed>|null */
    private static ?array $registry=null;
    /** @return list<string> */
    public static function events(): array { return array_keys(self::data()['events']); }
    /** @return array<string,mixed> */
    public static function event(string $name): array { return self::data()['events'][$name] ?? throw new \InvalidArgumentException('Unknown WAM event'); }
    /** @param array<string,mixed> $payload
     * @return list<array{id:int,kind:string,value:int|float|string|bool}> */
    public static function fields(string $name,array $payload): array
    {
        $definition=self::event($name);$fields=$definition['fields'];foreach($payload as $key=>$value) if(!isset($fields[$key])) throw new \InvalidArgumentException('Unknown WAM field');$out=[];
        foreach($fields as $name=>$field) {
            if(!array_key_exists($name,$payload) || $payload[$name]===null) continue;$value=$payload[$name];$type=$field['type'];
            $kind=match($type) {'boolean'=>'bool','integer','timer','enum'=>'int','number'=>'float','string'=>'string',default=>throw new \InvalidArgumentException('Unsupported WAM registry field kind')};
            if($type==='enum') { $table=self::data()['enums'][$field['enum']]['values'];if(is_string($value) && array_key_exists($value,$table)) $value=$table[$value];elseif(!is_int($value) || !in_array($value,$table,true)) throw new \InvalidArgumentException('Invalid WAM enum value'); }
            if(!is_int($value) && !is_float($value) && !is_string($value) && !is_bool($value)) throw new \InvalidArgumentException('Invalid WAM field value');
            // Encoder validates the exact scalar type before any batch is changed.
            WamEncoder::field($field['id'],$kind,$value,false);$out[]=['id'=>$field['id'],'kind'=>$kind,'value'=>$value];
        } return $out;
    }
    /** @param array<string,int|string|bool|null> $named
     * @return array<int,int|string|bool|null> */
    public static function globals(array $named,string $channel='regular'): array
    {
        $out=[];foreach($named as $name=>$value) { $g=self::data()['globals'][$name] ?? throw new \InvalidArgumentException('Unknown WAM global');if(!in_array($channel,$g['channels'],true)) continue;
            if($g['type']==='enum' && $value!==null) { $values=self::data()['enums'][$g['enum']]['values'];if(is_string($value) && array_key_exists($value,$values)) $value=$values[$value];elseif(!is_int($value) || !in_array($value,$values,true)) throw new \InvalidArgumentException('Invalid WAM global enum'); }$out[$g['id']]=$value;
        }return $out;
    }
    /** @return array<string,mixed> */
    private static function data(): array
    {
        if(self::$registry===null) {$json=file_get_contents(__DIR__.'/wam-registry.json');if($json===false) throw new \RuntimeException('WAM registry missing');self::$registry=json_decode($json,true,512,JSON_THROW_ON_ERROR);}return self::$registry;
    }
}
