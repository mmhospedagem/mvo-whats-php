<?php
declare(strict_types=1);
namespace MVO\Plugin;
use MVO\WaClient;
use MVO\Protocol\BinaryNode;

final class PluginContext
{
    /** @var list<callable():void> */
    private array $cleanup=[];
    public function __construct(public readonly WaClient $client) {}
    /** @param callable(array<string,mixed>):void $handler */
    public function on(string $event,callable $handler):void { $this->client->on($event,$handler); $this->registerDispose(function() use($event,$handler):void { $this->client->off($event,$handler); }); }
    /** @param callable(array<string,mixed>):void $handler */
    public function once(string $event,callable $handler):void {
        $wrapped=null;
        $wrapped=function(array $payload) use($event,$handler,&$wrapped):void { $this->client->off($event,$wrapped); $handler($payload); };
        $this->on($event,$wrapped);
    }
    /** @param array<string,mixed> $payload */
    public function emit(string $event,array $payload=[]):void { $this->client->emitEvent($event,$payload); }
    public function query(BinaryNode $node,float $timeoutSeconds=30):BinaryNode { return $this->client->query($node,$timeoutSeconds); }
    /** @param callable(BinaryNode):bool $handler
     * @return \Closure():void */
    public function registerIncomingHandler(callable $handler):\Closure {
        $remove=$this->client->registerIncomingHandler($handler); $this->registerDispose($remove); return $remove;
    }
    /** @param callable(BinaryNode):bool $filter true drops application traffic after ACK
     * @return \Closure():void */
    public function registerIncomingStanzaFilter(callable $filter):\Closure {
        $remove=$this->client->registerIncomingStanzaFilter($filter); $this->registerDispose($remove); return $remove;
    }
    /** @param callable():void $cleanup */
    public function registerDispose(callable $cleanup):void { $this->cleanup[]=$cleanup; }
    public function dispose():void {
        $callbacks=array_reverse($this->cleanup); $this->cleanup=[]; $first=null;
        foreach ($callbacks as $callback) { try { $callback(); } catch (\Throwable $e) { $first ??= $e; } }
        if ($first!==null) throw $first;
    }
}
