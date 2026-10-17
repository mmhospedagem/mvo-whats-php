<?php
declare(strict_types=1);
namespace MVO\Plugin;
use MVO\WaClient;

final class PluginHost
{
    /** @var list<array{definition:PluginDefinition,context:PluginContext,instance:mixed}> */
    private array $installed=[];
    /** @param list<PluginDefinition> $definitions */
    public function __construct(private readonly array $definitions=[]) {
        $ids=[];
        foreach ($definitions as $plugin) {
            if (!$plugin instanceof PluginDefinition || isset($ids[$plugin->id])) throw new \InvalidArgumentException('Invalid or duplicate plugin');
            $ids[$plugin->id]=true;
        }
    }
    public function install(WaClient $client):void {
        if ($this->installed!==[]) return;
        try {
            foreach ($this->definitions as $definition) {
                $context=new PluginContext($client);
                try { $instance=($definition->setup)($context); }
                catch (\Throwable $e) { try { $context->dispose(); } catch (\Throwable) {} throw $e; }
                $this->installed[]=['definition'=>$definition,'context'=>$context,'instance'=>$instance];
            }
        } catch (\Throwable $e) { try { $this->dispose(); } catch (\Throwable) {} throw $e; }
    }
    public function get(string $id):mixed {
        foreach ($this->installed as $entry) if ($entry['definition']->id===$id) return $entry['instance'];
        throw new \OutOfBoundsException('Plugin is not installed: '.$id);
    }
    public function dispose():void {
        $entries=array_reverse($this->installed); $this->installed=[]; $first=null;
        foreach ($entries as $entry) {
            try { if ($entry['definition']->dispose!==null) ($entry['definition']->dispose)($entry['instance'],$entry['context']); }
            catch (\Throwable $e) { $first ??= $e; }
            finally { try { $entry['context']->dispose(); } catch (\Throwable $e) { $first ??= $e; } }
        }
        if ($first!==null) throw $first;
    }
}
