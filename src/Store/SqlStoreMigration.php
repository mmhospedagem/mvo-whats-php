<?php
declare(strict_types=1);
namespace MVO\Store;

/** Internal one-time migration of storage identifiers; binary state is never decoded or regenerated. */
final class SqlStoreMigration
{
    private const LEGACY_TABLE='zapo_kv';
    private const CURRENT_TABLE='mvo_kv';
    /** @param callable():void|null $beforeMigration Called after a metadata probe, before any DDL. */
    public static function initialize(\PDO $db,string $driver,string $table,string $keyColumn,string $ddl,?callable $beforeMigration=null):void
    {
        if($table!==self::CURRENT_TABLE){$db->exec($ddl);return;}
        if(!self::exists($db,$driver,self::LEGACY_TABLE)){if(!self::exists($db,$driver,$table))$db->exec($ddl);return;}
        if($db->inTransaction())throw new \LogicException('Storage migration requires a connection outside a transaction');
        if($beforeMigration!==null)$beforeMigration();
        // A different opener may have completed migration while the guard was being acquired.
        if(!self::exists($db,$driver,self::LEGACY_TABLE)){if(!self::exists($db,$driver,$table))throw new \RuntimeException('Storage migration source and destination are missing');return;}
        if($driver==='mysql'){self::mysql($db,$table,$keyColumn);return;}
        $db->beginTransaction();
        try {
            if($driver==='pgsql')$db->exec('LOCK TABLE '.self::LEGACY_TABLE.' IN ACCESS EXCLUSIVE MODE');
            if(!self::exists($db,$driver,$table))$db->exec('ALTER TABLE '.self::LEGACY_TABLE.' RENAME TO '.$table);
            else {
                if($driver==='pgsql')$db->exec('LOCK TABLE '.$table.' IN ACCESS EXCLUSIVE MODE');
                self::merge($db,$driver,$table,$keyColumn);$db->exec('DROP TABLE '.self::LEGACY_TABLE);
            }
            $db->commit();
        }catch(\Throwable $error){if($db->inTransaction())$db->rollBack();throw $error;}
    }
    /** @phpstan-impure Metadata may change between probes when another opener finishes migration. */
    private static function exists(\PDO $db,string $driver,string $table):bool
    {
        $sql=match($driver){'sqlite'=>"SELECT 1 FROM sqlite_master WHERE type='table' AND name=?",'mysql'=>"SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? AND table_type='BASE TABLE'",'pgsql'=>"SELECT 1 FROM information_schema.tables WHERE table_schema=current_schema() AND table_name=? AND table_type='BASE TABLE'",default=>throw new \InvalidArgumentException('Unsupported storage migration driver')};
        $query=$db->prepare($sql);$query->execute([$table]);return $query->fetchColumn()!==false;
    }
    private static function merge(\PDO $db,string $driver,string $table,string $keyColumn):void
    {
        if(!in_array($keyColumn,['key','k'],true))throw new \InvalidArgumentException('Invalid migration key column');$column=$driver==='mysql'?'`'.$keyColumn.'`':'"'.$keyColumn.'"';
        $source=$db->query('SELECT session,namespace,'.$column.',value FROM '.self::LEGACY_TABLE);
        $find=$db->prepare('SELECT session,namespace,'.$column.',value FROM '.$table.' WHERE session=? AND namespace=? AND '.$column.'=?');
        $insert=$db->prepare('INSERT INTO '.$table.'(session,namespace,'.$column.',value) VALUES(?,?,?,?)');
        if($source===false)throw new \RuntimeException('Cannot read legacy storage');
        while(($row=$source->fetch(\PDO::FETCH_NUM))!==false){$bytes=array_map(self::bytes(...),$row);$find->execute(array_slice($bytes,0,3));$current=$find->fetch(\PDO::FETCH_NUM);$find->closeCursor();
            if($current!==false){if(array_map(self::bytes(...),$current)!==$bytes)throw new RemoteStoreConflict('Legacy and MVO SQL storage contain conflicting state; no values were overwritten');continue;}
            foreach($bytes as $index=>$value)$insert->bindValue($index+1,$value,$index===3?\PDO::PARAM_LOB:\PDO::PARAM_STR);$insert->execute();
        }
        $source->closeCursor();
    }
    private static function mysql(\PDO $db,string $table,string $keyColumn):void
    {
        // MySQL DDL commits implicitly. RENAME is atomic; merging uses explicit table locks
        // that survive COMMIT until UNLOCK, so an old writer cannot race the final DROP.
        if(!self::exists($db,'mysql',$table)){$db->exec('RENAME TABLE '.self::LEGACY_TABLE.' TO '.$table);return;}
        $engines=$db->prepare('SELECT engine FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN (?,?)');$engines->execute([self::LEGACY_TABLE,$table]);$rows=$engines->fetchAll(\PDO::FETCH_COLUMN);if(count($rows)!==2 || array_filter($rows,static fn($engine)=>strtolower($engine)!=='innodb')!==[])throw new \RuntimeException('SQL storage merge requires InnoDB tables');
        $auto=(int)$db->query('SELECT @@autocommit')->fetchColumn();$locked=false;
        try {
            $db->exec('SET autocommit=0');$db->exec('LOCK TABLES '.self::LEGACY_TABLE.' WRITE, '.$table.' WRITE');$locked=true;
            self::merge($db,'mysql',$table,$keyColumn);$db->exec('COMMIT');$db->exec('DROP TABLE '.self::LEGACY_TABLE);
        }catch(\Throwable $error){if($locked)$db->exec('ROLLBACK');throw $error;}
        finally {if($locked)$db->exec('UNLOCK TABLES');$db->exec('SET autocommit='.$auto);}
    }
    private static function bytes(mixed $value):string
    {
        if(is_resource($value)){$value=stream_get_contents($value);if($value===false)throw new \RuntimeException('Cannot read binary migration value');}
        if(!is_string($value))throw new \UnexpectedValueException('Storage migration encountered a non-binary value');return $value;
    }
}
