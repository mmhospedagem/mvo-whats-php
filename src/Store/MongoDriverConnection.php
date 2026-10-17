<?php
declare(strict_types=1);
namespace MVO\Store;

/** Optional ext-mongodb adapter, loaded dynamically so SQLite/Redis users need no MongoDB extension. */
final class MongoDriverConnection
{
    private ?object $manager;
    public function __construct(string $uri,private readonly string $database)
    {
        $class='MongoDB\\Driver\\Manager';if(!class_exists($class)) throw new \RuntimeException('MongoStore requires the optional ext-mongodb PHP extension');
        $this->manager=new $class($uri,['retryWrites'=>false,'serverSelectionTimeoutMS'=>5000,'connectTimeoutMS'=>5000,'socketTimeoutMS'=>15000]);
    }
    /** @param array<string,mixed> $document
     * @return array<string,mixed> */
    public function command(array $document,?string $database=null): array
    {
        if($this->manager===null) throw new \LogicException('MongoDB connection closed');$class='MongoDB\\Driver\\Command';$command=new $class($document);
        // Dynamic extension boundary; its cursor is converted into checked PHP arrays below.
        $execute='executeCommand';$cursor=$this->manager->$execute($database??$this->database,$command);$setTypeMap='setTypeMap';$cursor->$setTypeMap(['root'=>'array','document'=>'array','array'=>'array']);$toArray='toArray';$results=$cursor->$toArray();
        if(!is_array($results) || count($results)!==1 || !is_array($results[0])) throw new \UnexpectedValueException('MongoDB command response missing');return $results[0];
    }
    public function close(): void { $this->manager=null; }
}
