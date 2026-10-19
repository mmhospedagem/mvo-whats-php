<?php
declare(strict_types=1);
namespace MVO\Facebook\Protocol;

/** A persisted document observed in a public, shipped Relay artifact. */
final class Document
{
    /** @param array<string,mixed> $defaults
     * @param list<string> $variables
     * @param array<string,mixed> $fixedVariables Application policy, separate from observed Relay defaults.
     */
    public function __construct(public readonly string $name,public readonly string $id,public readonly string $kind,
        public readonly array $variables,public readonly array $defaults,public readonly string $sourceSha256,public readonly string $sourceUrl,public readonly array $fixedVariables=[])
    {
        if(!preg_match('/^[A-Za-z][A-Za-z0-9_]{1,159}$/D',$name)||!preg_match('/^[1-9][0-9]{0,24}$/D',$id)||!in_array($kind,['query','mutation'],true))throw new \InvalidArgumentException('Invalid persisted Facebook document');
        if(!preg_match('/^[a-f0-9]{64}$/D',$sourceSha256))throw new \InvalidArgumentException('A Facebook document requires its source digest');
        $parts=parse_url($sourceUrl);
        if(($parts['scheme']??null)!=='https'||!str_ends_with($parts['host']??'','.fbcdn.net')||!str_starts_with($parts['path']??'','/rsrc.php/')||isset($parts['query'])||isset($parts['user'])||isset($parts['fragment']))throw new \InvalidArgumentException('Expected a canonical public Facebook artifact URL');
        foreach($variables as $variable)if(!is_string($variable)||!preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,127}$/D',$variable))throw new \InvalidArgumentException('Invalid Facebook document variable');
        if(count(array_unique($variables))!==count($variables)||array_diff(array_keys($defaults),$variables)!==[]||array_diff(array_keys($fixedVariables),$variables)!==[])throw new \InvalidArgumentException('Invalid Facebook document defaults');
    }
    /** @param array<string,mixed> $values
     * @return array<string,mixed>
     */
    public function arguments(array $values):array
    {
        if(array_diff(array_keys($values),$this->variables)!==[])throw new \InvalidArgumentException('Unknown field for this Facebook Web document');
        $result=[...$this->defaults,...$values];
        foreach($this->fixedVariables as $key=>$expected){
            if(array_key_exists($key,$values)&&$values[$key]!==$expected)throw new \InvalidArgumentException('This variable would enable an operation outside the supported Facebook contract');
            $result[$key]=$expected;
        }
        if(strlen(json_encode($result,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE))>1048576)throw new \LengthException('Facebook variables exceed the size limit');
        return $result;
    }
}
