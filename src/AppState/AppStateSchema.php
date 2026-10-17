<?php
declare(strict_types=1);
namespace MVO\AppState;

use MVO\Protocol\Jid;
use MVO\Protocol\SchemaCodec;

/** The 66 pinned action specifications plus the verified Business message-label action. */
final class AppStateSchema
{
    /** @var array<string,array<string,mixed>>|null */
    private static ?array $schemas=null;
    /**
     * @return array<string,array<string,mixed>> */
    public static function all(): array
    {
        if (self::$schemas===null) {
            $json=file_get_contents(__DIR__.'/schema.json');
            if ($json===false) throw new \RuntimeException('Missing app-state schema');
            self::$schemas=json_decode($json,true,32,JSON_THROW_ON_ERROR);
        }
        return self::$schemas;
    }
    /**
     * @param array<string,mixed> $args
     * @param array<string,mixed>|null $data
     * @return array<string,mixed> */
    public static function mutation(string $name,array $args,?array $data,int $timestamp,bool $remove=false): array
    {
        if($timestamp<0)throw new \InvalidArgumentException('Negative app-state timestamp');
        $schema=self::all()[$name]??throw new \InvalidArgumentException('Unknown app-state action '.$name);
        $index=[];
        foreach ($schema['indexParts'] as $part) {
            if ($part['type']==='literal') { $index[]=$part['value']; continue; }
            $value=$args[$part['name']]??null;
            if ($part['type']==='boolString') {
                if (!is_bool($value)) throw new \InvalidArgumentException('Expected boolean index '.$part['name']);
                $index[]=$value?'1':'0';
            } elseif ($part['type']==='enum') {
                if (is_string($value)) $value=$part['enumValues'][$value]??null;
                if (!is_int($value) || !in_array($value,$part['enumValues'],true)) throw new \InvalidArgumentException('Invalid enum index '.$part['name']);
                $index[]=(string)$value;
            } elseif ($part['type']==='jidOrZero' && ($value===null || $value==='0')) $index[]='0';
            else {
                if (!is_string($value) || $value==='') throw new \InvalidArgumentException('Missing app-state index '.$part['name']);
                $index[]=match($part['type']) { 'jid'=>Jid::recipient($value)->canonical()->bare(),'jidOrZero'=>(string)Jid::recipient($value)->canonical(),default=>$value };
            }
        }
        $value=null;
        if (!$remove) {
            if ($data===null) throw new \InvalidArgumentException('App-state set requires action data');
            $value=['timestamp'=>$timestamp];
            if ($schema['valueField']!==null) $value[$schema['valueField']]=$data;
            else $value=array_replace($data,$value);
            SchemaCodec::encode('SyncActionValue',$value);
        }
        return ['collection'=>$schema['collection'],'operation'=>$remove?'remove':'set','index'=>json_encode($index,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),'value'=>$value,'version'=>$schema['version'],'timestamp'=>$timestamp];
    }

    /** Parses authenticated records into the same named action and index arguments used by mutate().
     * @param array<string,mixed> $mutation
     * @return array<string,mixed>|null */
    public static function parse(array $mutation): ?array
    {
        if(!is_string($mutation['index']??null))return null;
        try{$parts=json_decode($mutation['index'],true,32,JSON_THROW_ON_ERROR);}catch(\JsonException){return null;}
        if(!is_array($parts) || !array_is_list($parts) || !is_string($parts[0]??null))return null;
        $name=null;$schema=null;
        foreach(self::all() as $candidate=>$definition)if($definition['name']===$parts[0] && count($parts)===count($definition['indexParts'])){$name=$candidate;$schema=$definition;break;}
        if($schema===null || count($parts)!==count($schema['indexParts']))return null;
        $args=[];
        foreach($schema['indexParts'] as $i=>$part) {
            if(!is_string($parts[$i]))return null;
            if($part['type']==='literal'){if($part['value']!==$parts[$i])return null;continue;}
            if($part['type']==='boolString') {
                if(!in_array($parts[$i],['0','1'],true))return null;
                $args[$part['name']]=$parts[$i]==='1';
            }elseif($part['type']==='enum') {
                $symbol=array_search((int)$parts[$i],$part['enumValues'],true);
                $args[$part['name']]=$symbol===false?$parts[$i]:$symbol;
            }else $args[$part['name']]=$parts[$i];
        }
        $raw=$mutation['value']??null;$data=[];
        if(($mutation['operation']??'')!=='remove' && is_array($raw)) {
            if($schema['valueField']===null){$data=$raw;unset($data['timestamp']);}
            else{$data=$raw[$schema['valueField']]??[];if(!is_array($data))return null;}
        }
        return ['schema'=>$name,'operation'=>$mutation['operation']??'set',...$args,...$data,
            'source'=>$mutation['source']??'patch','collection'=>$mutation['collection']??$schema['collection'],
            'version'=>$mutation['version']??$schema['version'],'timestamp'=>$mutation['timestamp']??$raw['timestamp']??0,
            '_raw'=>['index'=>$mutation['index'],'indexParts'=>$parts,'value'=>$raw]];
    }
}

