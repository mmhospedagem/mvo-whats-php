<?php
declare(strict_types=1);

namespace MVO\Messenger\Protocol;

use MVO\Messenger\MessengerException;

/** Bounded, inert LS expression interpreter. Unsupported instructions fail the whole batch. */
final class LightspeedDecoder
{
    /** @var array<int,mixed> */ private array $references=[];
    /** @var array<string,string> */ private array $dependencies=[];
    /** @var list<array{name:string,args:list<mixed>}> */ private array $calls=[];
    private int $nodes=0;
    private readonly LightspeedProcedureResolver|\Closure|null $resolver;

    /** @param LightspeedProcedureResolver|callable(string,list<mixed>):LightspeedProcedureResult|null $procedureResultResolver */
    public function __construct(private readonly int $maximumNodes=100000, private readonly int $maximumDepth=96, private readonly int $maximumCalls=10000, LightspeedProcedureResolver|callable|null $procedureResultResolver=null)
    {
        if ($maximumNodes<1 || $maximumDepth<1 || $maximumDepth>120 || $maximumCalls<1) throw new \InvalidArgumentException('Invalid Lightspeed decoder limits');
        $this->resolver=$procedureResultResolver instanceof LightspeedProcedureResolver || $procedureResultResolver===null ? $procedureResultResolver : \Closure::fromCallable($procedureResultResolver);
    }

    /**
     * No effects escape until the complete AST succeeds.
     * @param array<array-key,mixed>|string $payload
     * @param array<array-key,mixed> $dependencies
     * @return list<array{name:string,args:list<mixed>}>
     */
    public function decode(#[\SensitiveParameter] array|string $payload, array $dependencies=[]): array
    {
        $this->references=[]; $this->calls=[]; $this->nodes=0;
        if ($this->resolver instanceof LightspeedProcedureResolver) $this->resolver->beginBatch();
        if (is_string($payload)) $payload=$this->json($payload);
        if (!array_is_list($payload)) {
            if ($dependencies===[] && isset($payload['dependencies']) && is_array($payload['dependencies'])) $dependencies=$payload['dependencies'];
            if (!array_key_exists('step',$payload)) throw new MessengerException('invalid_lightspeed_payload','Lightspeed payload has no step expression');
            $payload=$payload['step'];
        }
        $this->dependencies=$this->dependencyMap($dependencies);
        $this->evaluate($payload,0);
        if ($this->resolver instanceof LightspeedProcedureResolver) $this->resolver->finishBatch();
        return $this->calls;
    }

    /** @return array<array-key,mixed> */
    private function json(#[\SensitiveParameter] string $json): array
    {
        if (strlen($json)>16777216) throw new MessengerException('lightspeed_limit','Lightspeed payload exceeds the local size limit');
        try { $value=json_decode($json,true,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); }
        catch (\JsonException) { throw new MessengerException('invalid_lightspeed_payload','Lightspeed payload is not valid bounded JSON'); }
        if (!is_array($value)) throw new MessengerException('invalid_lightspeed_payload','Expected a Lightspeed object or expression');
        return $value;
    }

    /**
     * @param array<array-key,mixed> $entries
     * @return array<string,string>
     */
    private function dependencyMap(array $entries): array
    {
        if (count($entries)>10000) throw new MessengerException('lightspeed_limit','Too many Lightspeed dependencies');
        $map=[];
        foreach ($entries as $key=>$entry) {
            if (is_string($entry)) {
                $map[(string)$key]=$entry;
                if (array_is_list($entries)) $map[$entry]=$entry;
            } elseif (is_array($entry) && is_string($entry['name'] ?? null) && is_string($entry['value']['__dr'] ?? null)) {
                $map[$entry['name']]=$entry['value']['__dr'];
            } else throw new MessengerException('invalid_lightspeed_dependency','Unsupported Lightspeed dependency descriptor');
        }
        foreach ($map as $name) if (!preg_match('/^[A-Za-z_$][A-Za-z0-9_.$-]{0,199}$/D',$name)) throw new MessengerException('invalid_lightspeed_dependency','Invalid Lightspeed dependency identity');
        return $map;
    }

    private function evaluate(mixed $expression, int $depth): mixed
    {
        if (++$this->nodes>$this->maximumNodes || $depth>$this->maximumDepth) throw new MessengerException('lightspeed_limit','Lightspeed expression exceeds the local evaluation budget');
        if (!is_array($expression)) {
            if (is_float($expression) && (!is_finite($expression) || abs($expression)>9007199254740991)) throw new MessengerException('invalid_lightspeed_number','Unsafe floating-point value in Lightspeed payload');
            return $expression;
        }
        if ($expression===[]) return [];
        if (!array_is_list($expression) || !is_int($expression[0])) throw new MessengerException('invalid_lightspeed_expression','Expected a typed Lightspeed instruction');
        $opcode=$expression[0]; $args=array_slice($expression,1);
        $get=fn(int $index):mixed=>$this->evaluate(array_key_exists($index,$args) ? $args[$index] : throw new MessengerException('invalid_lightspeed_expression','Missing Lightspeed operand'),$depth+1);
        switch ($opcode) {
            case 1: // BLOCK
                $result=null;
                foreach ($args as $arg) $result=$this->evaluate($arg,$depth+1);
                return $result;
            case 2: // LOAD
                $this->arity($args,1); $index=$this->reference($args[0]);
                if (!array_key_exists($index,$this->references)) throw new MessengerException('unresolved_lightspeed_reference','Lightspeed expression refers to an unset local');
                return $this->references[$index];
            case 3: // STORE
                $this->arity($args,2); $index=$this->reference($args[0]);
                return $this->references[$index]=$get(1);
            case 4: // STORE_ARRAY: unpack the actual tuple, evaluating its expression once.
                $this->arity($args,3);$start=$this->reference($args[0]);$count=$this->reference($args[1]);
                if($start+$count>65536)$this->invalid();$this->spend($count);
                $result=$get(2);
                if($count>0&&!$result instanceof LightspeedUnresolvedValue&&!$result instanceof LightspeedVector)$this->invalid();
                for($offset=0;$offset<$count;$offset++)$this->references[$start+$offset]=$result instanceof LightspeedUnresolvedValue?new LightspeedUnresolvedValue($result->procedure):(array_key_exists($offset,$result->values)?$result->values[$offset]:new LightspeedUndefinedValue());
                return $result;
            case 5: // CALL_STORED_PROCEDURE: collect protocol records, never invoke PHP by name.
                if($args===[])$this->invalid();$target=$get(0);$this->resolved($target);
                if(!is_string($target)&&!is_int($target))$this->invalid();
                $reference=(string)$target; $name=$this->dependencies[$reference] ?? null;
                if ($name===null && preg_match('/^LS[A-Za-z0-9_]{1,197}$/D',$reference)) $name=$reference;
                if ($name===null) throw new MessengerException('unresolved_lightspeed_dependency','Stored procedure dependency is not present in the response');
                $values=[];$resolverValues=[];
                foreach (array_slice($args,1) as $arg) {
                    $value=$this->evaluate($arg,$depth+1);
                    $values[]=$this->exportValue($value); $resolverValues[]=$this->resolverValue($value);
                }
                if (count($this->calls)>=$this->maximumCalls) throw new MessengerException('lightspeed_limit','Too many stored procedure calls in one Lightspeed batch');
                $this->calls[]=['name'=>$name,'args'=>$values];
                if ($this->resolver!==null) {
                    $result=$this->resolver instanceof LightspeedProcedureResolver ? $this->resolver->resolve($name,$resolverValues) : ($this->resolver)($name,$resolverValues);
                    if (!$result instanceof LightspeedProcedureResult) throw new \UnexpectedValueException('The Lightspeed result resolver must return an explicit known/unknown result');
                    if ($result->known) return $this->importResult($result->value(),$depth+1);
                }
                // Its local database result cannot be guessed without that procedure's implementation.
                return new LightspeedUnresolvedValue($name);
            case 8: // LIST of evaluated values
                return new LightspeedVector(array_map(fn(mixed $arg):mixed=>$this->evaluate($arg,$depth+1),$args),$this->maximumNodes);
            case 9: // UNDEFINED remains distinct from protocol null inside comparisons.
                $this->arity($args,0); return new LightspeedUndefinedValue();
            case 12: // Tuple expression over absolute local indexes; does not terminate BLOCK.
                $this->arity($args,2);$start=$this->reference($args[0]);$end=$this->reference($args[1]);
                if($end<$start)$this->invalid();$this->spend($end);
                $values=array_fill(0,$end,new LightspeedUndefinedValue());
                for($index=$start;$index<$end;$index++)$values[$index]=array_key_exists($index,$this->references)?$this->references[$index]:new LightspeedUndefinedValue();
                return new LightspeedVector($values,$this->maximumNodes);
            case 13: // BOOL_TO_STR
                $this->arity($args,1); return $this->truthy($get(0))?'1':'0';
            case 17: // I64_OF_FLOAT; safe integer values only.
            case 18: // I64_TO_FLOAT is deliberately rejected if precision would be lost.
                $this->arity($args,1); $value=$get(0);
                if ($opcode===17) {
                    if ((!is_int($value) && !is_float($value)) || !is_finite((float)$value) || abs((float)$value)>9007199254740991 || floor((float)$value)!=(float)$value) throw new MessengerException('invalid_lightspeed_number','Unsafe Lightspeed integer conversion');
                    return new LightspeedIntegerValue((string)(int)$value);
                }
                $number=$this->integer($value);
                if ($this->compareInteger(ltrim($number,'-'),'9007199254740991')>0) throw new MessengerException('invalid_lightspeed_number','Lightspeed conversion would lose integer precision');
                return (float)$number;
            case 19: // I64_FROM_STRING stays a decimal string at the public boundary.
                $this->arity($args,1); return new LightspeedIntegerValue($this->integer($get(0)));
            case 20: // I64_TO_STRING changes the internal integer type to a string.
                $this->arity($args,1); return $this->integer($get(0));
            case 23: // IF: do not inspect or execute the untaken branch.
                if (count($args)<2 || count($args)>3) $this->invalid();
                if ($this->truthy($get(0))) return $get(1);
                return count($args)===3 ? $get(2) : null;
            case 24: // OR
                $this->arity($args,2);$left=$get(0);return $this->truthy($left)?$left:$get(1);
            case 25: // AND
                $this->arity($args,2);$left=$get(0);return $this->truthy($left)?$get(1):$left;
            case 26: $this->arity($args,1); return !$this->truthy($get(0));
            case 27: $this->arity($args,1); $value=$get(0); $this->resolved($value); return $value===null||$value instanceof LightspeedUndefinedValue;
            case 28:
                $this->arity($args,1); $value=$get(0); $this->resolved($value);
                if ($value===null || $value instanceof LightspeedUndefinedValue) throw new MessengerException('lightspeed_null','Lightspeed required a non-null value');
                return $value;
            case 29: case 32: // Generic equality/inequality preserves JSON type.
                $this->arity($args,2); $left=$get(0);$right=$get(1);$this->resolved($left);$this->resolved($right);
                $equal=($left instanceof LightspeedUndefinedValue && $right instanceof LightspeedUndefinedValue) ||
                    ($left instanceof LightspeedIntegerValue && $right instanceof LightspeedIntegerValue && $left->decimal===$right->decimal) || $left===$right;
                return $opcode===29 ? $equal : !$equal;
            case 30: case 33: case 36: case 39: case 42: case 45:
                $this->arity($args,2); $comparison=$this->compareInteger($this->integer($get(0)),$this->integer($get(1)));
                return match($opcode) {30=>$comparison===0,33=>$comparison!==0,36=>$comparison>0,39=>$comparison>=0,42=>$comparison<0,45=>$comparison<=0};
            case 47: throw new MessengerException('lightspeed_server_throw','Facebook returned a Lightspeed exception');
            case 48: case 49: // Remote logging instructions produce no local output.
                foreach ($args as $index=>$_) $get($index);
                return null;
            case 50: // LSVec with an optional shallow copy of evaluated elements.
                if(count($args)>1)$this->invalid();
                if($args===[])return new LightspeedVector([],$this->maximumNodes);
                $values=$get(0);$this->resolved($values);if(!$values instanceof LightspeedVector)$this->invalid();
                return new LightspeedVector($values->values,$this->maximumNodes);
            case 51: // APPEND mutates and returns the same array identity.
                $this->arity($args,2);$array=$get(0);$this->resolved($array);if(!$array instanceof LightspeedVector)$this->invalid();
                return $array->append($get(1));
            case 52:
                $this->arity($args,1);$array=$get(0);$this->resolved($array);if(!$array instanceof LightspeedVector)$this->invalid();
                return new LightspeedIntegerValue((string)count($array->values));
            case 53:
                $this->arity($args,0);return new LightspeedDictionary($this->maximumNodes);
            case 54:
                $this->arity($args,2);$map=$get(0);$this->resolved($map);if(!$map instanceof LightspeedDictionary)$this->invalid();
                $key=$get(1);$this->resolved($key);return $map->get($key);
            case 55: // MAP_SET mutates and returns the same map identity.
                $this->arity($args,3);$map=$get(0);$this->resolved($map);if(!$map instanceof LightspeedDictionary)$this->invalid();
                $key=$get(1);$this->resolved($key);return $map->set($key,$get(2));
            case 97: // TERNARY
                $this->arity($args,3); return $this->truthy($get(0))?$get(1):$get(2);
            case 99: // NULLISH_COALESCE
                $this->arity($args,2); $value=$get(0);$this->resolved($value);
                return $value===null || $value instanceof LightspeedUndefinedValue ? $get(1) : $value;
            case 103: // COMMENT has no local effects.
                return null;
            default:
                throw new MessengerException('unsupported_lightspeed_opcode','Unsupported Lightspeed instruction '.$opcode.'; the batch was not applied');
        }
    }

    /** @param list<mixed> $args */
    private function arity(array $args,int $count): void { if (count($args)!==$count) $this->invalid(); }
    private function spend(int $count):void { $this->nodes+=$count;if($this->nodes>$this->maximumNodes)throw new MessengerException('lightspeed_limit','Lightspeed expression exceeds the local evaluation budget'); }
    private function invalid(): never { throw new MessengerException('invalid_lightspeed_expression','Invalid Lightspeed instruction operands'); }
    private function reference(mixed $index): int
    {
        if (!is_int($index) || $index<0 || $index>65535) $this->invalid();
        return $index;
    }
    private function resolved(mixed $value): void
    {
        if ($value instanceof LightspeedUnresolvedValue) throw new MessengerException('unsupported_lightspeed_result','The batch requires an unimplemented stored procedure return value'.($value->procedure===null?'':' ('.$value->procedure.')'));
    }
    private function truthy(mixed $value): bool
    {
        $this->resolved($value);
        if ($value instanceof LightspeedIntegerValue||$value instanceof LightspeedVector||$value instanceof LightspeedDictionary) return true;
        // JS/LS strings are true even when the contents are "0".
        if ($value instanceof LightspeedUndefinedValue || $value===null || $value===false) return false;
        if ($value===true) return true;
        if (is_int($value) || is_float($value)) return $value!=0;
        if (is_string($value)) return $value!=='';
        if (is_array($value)) return true;
        $this->invalid();
    }
    private function integer(mixed $value): string
    {
        $this->resolved($value);
        if ($value instanceof LightspeedIntegerValue) return $value->decimal;
        if (is_int($value)) $value=(string)$value;
        if (!is_string($value) || !preg_match('/^-?(?:0|[1-9][0-9]{0,18})$/D',$value)) throw new MessengerException('invalid_lightspeed_number','Expected a signed decimal Lightspeed integer');
        if ($value==='-0') $value='0';
        if ($this->compareInteger($value,'9223372036854775807')>0 || $this->compareInteger($value,'-9223372036854775808')<0) throw new MessengerException('invalid_lightspeed_number','Lightspeed integer is outside the signed 64-bit range');
        return $value;
    }
    private function compareInteger(string $left,string $right): int
    {
        $negativeLeft=str_starts_with($left,'-'); $negativeRight=str_starts_with($right,'-');
        if ($negativeLeft!==$negativeRight) return $negativeLeft?-1:1;
        $a=ltrim($left,'-');$b=ltrim($right,'-');
        $comparison=strlen($a)<=>strlen($b);
        if ($comparison===0) $comparison=strcmp($a,$b)<=>0;
        return $negativeLeft?-$comparison:$comparison;
    }
    private function exportValue(mixed $value): mixed
    {
        return $this->boundaryValue($value,false,0,new \SplObjectStorage());
    }

    private function importResult(mixed $value,int $depth): mixed
    {
        if (++$this->nodes>$this->maximumNodes || $depth>$this->maximumDepth) throw new MessengerException('lightspeed_limit','Lightspeed procedure result exceeds the local evaluation budget');
        if (is_array($value)) {
            if (!array_is_list($value)) throw new \UnexpectedValueException('A Lightspeed procedure result must use list values');
            return new LightspeedVector(array_map(fn(mixed $entry):mixed=>$this->importResult($entry,$depth+1),$value),$this->maximumNodes);
        }
        if ($value!==null&&!is_bool($value)&&!is_string($value)&&!is_int($value)&&!$value instanceof LightspeedUndefinedValue) throw new \UnexpectedValueException('Unsupported value returned by a Lightspeed procedure resolver');
        return $value;
    }

    /** Preserve undefined for explicit local resolvers; the public row projection still uses null slots. */
    private function resolverValue(mixed $value): mixed
    {
        return $this->boundaryValue($value,true,0,new \SplObjectStorage());
    }

    /** Export detached snapshots while preserving typed IDs and object/list shape.
     * @param \SplObjectStorage<object,null> $active */
    private function boundaryValue(mixed $value,bool $forResolver,int $depth,\SplObjectStorage $active):mixed
    {
        $this->spend(1);if($depth>$this->maximumDepth)throw new MessengerException('lightspeed_limit','Lightspeed value exceeds the local depth budget');
        $this->resolved($value);
        if ($value instanceof LightspeedIntegerValue) return $value->decimal;
        if($value instanceof LightspeedUndefinedValue)return $forResolver?$value:null;
        if($value instanceof LightspeedVector||$value instanceof LightspeedDictionary){
            if($active->contains($value))throw new MessengerException('invalid_lightspeed_expression','Lightspeed container cycle cannot cross the procedure boundary');
            $active->attach($value);
            try{
                if($value instanceof LightspeedVector)return array_map(fn(mixed $entry):mixed=>$this->boundaryValue($entry,$forResolver,$depth+1,$active),$value->values);
                $object=new \stdClass();
                foreach($value->entries() as [$key,$entry]){
                    if(!is_string($key))throw new MessengerException('invalid_lightspeed_map_key','A mixed-key Lightspeed map cannot be projected as a native JSON object');
                    $object->{$key}=$this->boundaryValue($entry,$forResolver,$depth+1,$active);
                }
                return $object;
            }finally{$active->detach($value);}
        }
        if (is_array($value)) return array_map(fn(mixed $entry):mixed=>$this->boundaryValue($entry,$forResolver,$depth+1,$active),$value);
        return $value;
    }
}

