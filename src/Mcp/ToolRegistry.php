<?php
declare(strict_types=1);
namespace MVO\Mcp;

/** Only registered handlers can run; no object-property or arbitrary method traversal. */
final class ToolRegistry
{
    /** @var array<string,array{definition:array<string,mixed>,handler:\Closure(array<string,mixed>):mixed}> */
    private array $tools=[];
    /** @param array<string,mixed> $schema
     * @param callable(array<string,mixed>):mixed $handler */
    public function add(string $name,string $description,array $schema,callable $handler,bool $readOnly=false,bool $destructive=false): void
    {
        if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$name) || isset($this->tools[$name]) || count($this->tools)>=256) throw new \InvalidArgumentException('Invalid/duplicate MCP tool or registry limit');
        if(($schema['type'] ?? '')!=='object') throw new \InvalidArgumentException('Tool schema must describe an object');
        $this->tools[$name]=['definition'=>['name'=>$name,'description'=>$description,'inputSchema'=>$schema,'annotations'=>['readOnlyHint'=>$readOnly,'destructiveHint'=>$destructive,'idempotentHint'=>$readOnly,'openWorldHint'=>true]],'handler'=>\Closure::fromCallable($handler)];
    }
    /** @return list<array<string,mixed>> */
    public function definitions(): array { return array_values(array_map(static fn(array $t):array=>$t['definition'],$this->tools)); }
    /** @param array<string,mixed> $arguments */
    public function invoke(string $name,array $arguments): mixed
    {
        $t=$this->tools[$name] ?? throw new \InvalidArgumentException('Unknown MCP tool');InputValidator::validate($arguments,$t['definition']['inputSchema']);return ($t['handler'])($arguments);
    }
}
