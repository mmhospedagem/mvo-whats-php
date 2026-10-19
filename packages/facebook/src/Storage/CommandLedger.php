<?php
declare(strict_types=1);
namespace MVO\Facebook\Storage;

use MVO\Facebook\FacebookException;

/** Durable idempotency for mutations. It never replays an uncertain write. */
final class CommandLedger
{
    private \PDO $db;
    private bool $closed=false;
    public function __construct(string $path)
    {
        if($path===''||!is_dir(dirname($path)))throw new \InvalidArgumentException('Create a private directory for the Facebook command ledger');
        $this->db=new \PDO('sqlite:'.$path,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);@chmod($path,0600);
        $this->db->exec('PRAGMA busy_timeout=5000; PRAGMA journal_mode=WAL; CREATE TABLE IF NOT EXISTS facebook_commands(id TEXT PRIMARY KEY,scope TEXT NOT NULL,idempotency_hash TEXT NOT NULL,payload_hash TEXT NOT NULL,operation TEXT NOT NULL,state TEXT NOT NULL,result TEXT,created_at INTEGER NOT NULL,updated_at INTEGER NOT NULL,UNIQUE(scope,idempotency_hash))');
    }
    /** @param array<string,mixed> $variables
     * @return array{id:string,state:string,execute:bool,result:?array<string,mixed>}
     */
    public function reserve(string $accountId,string $operation,?string $actorId,array $variables,string $idempotencyKey):array
    {
        $this->requireOpen();
        if($idempotencyKey===''||strlen($idempotencyKey)>256)throw new \InvalidArgumentException('Use an idempotency key of 1 to 256 bytes');
        if(!preg_match('/^[1-9][0-9]{0,24}$/D',$accountId)||!preg_match('/^[A-Za-z][A-Za-z0-9_.-]{0,159}$/D',$operation))throw new \InvalidArgumentException('Invalid Facebook command scope');
        $scope=$accountId.':'.($actorId??$accountId);$key=hash('sha256',$idempotencyKey);$hash=hash('sha256',json_encode([$operation,self::canonical($variables)],JSON_THROW_ON_ERROR));
        $this->db->exec('BEGIN IMMEDIATE');
        try{
            $statement=$this->db->prepare('SELECT id,payload_hash,state,result FROM facebook_commands WHERE scope=? AND idempotency_hash=?');$statement->execute([$scope,$key]);$row=$statement->fetch(\PDO::FETCH_ASSOC);
            if($row!==false){
                if(!hash_equals($row['payload_hash'],$hash))throw new FacebookException('facebook_idempotency_conflict','This idempotency key already belongs to different command content');
                $this->db->exec('COMMIT');
                return ['id'=>$row['id'],'state'=>$row['state'],'execute'=>false,'result'=>$row['result']===null?null:json_decode($row['result'],true,64,JSON_THROW_ON_ERROR)];
            }
            $id=bin2hex(random_bytes(16));$statement=$this->db->prepare('INSERT INTO facebook_commands(id,scope,idempotency_hash,payload_hash,operation,state,created_at,updated_at) VALUES(?,?,?,?,?,\'prepared\',?,?)');
            $statement->execute([$id,$scope,$key,$hash,$operation,time(),time()]);$this->db->exec('COMMIT');
            return ['id'=>$id,'state'=>'prepared','execute'=>true,'result'=>null];
        }catch(\Throwable $error){try{$this->db->exec('ROLLBACK');}catch(\PDOException){}throw $error;}
    }
    /** Record uncertainty before I/O; response_received means parsed data, not operation success.
     * @param array<string,mixed>|null $result Explicit projection only, never raw HTTP.
     */
    public function finish(string $id,string $state,?array $result=null):void
    {
        $this->requireOpen();
        if(!in_array($state,['unknown','response_received','confirmed','not_confirmed','rejected'],true))throw new \InvalidArgumentException('Invalid Facebook command state');
        $statement=$this->db->prepare('UPDATE facebook_commands SET state=?,result=?,updated_at=? WHERE id=? AND state<>\'confirmed\'');
        $statement->execute([$state,$result===null?null:json_encode($result,JSON_THROW_ON_ERROR),time(),$id]);
    }
    /** @return array<string,mixed>|null */
    public function get(string $id):?array
    {
        $this->requireOpen();
        $statement=$this->db->prepare('SELECT id,operation,state,result,created_at,updated_at FROM facebook_commands WHERE id=?');$statement->execute([$id]);$row=$statement->fetch(\PDO::FETCH_ASSOC);
        if($row===false)return null;$row['result']=$row['result']===null?null:json_decode($row['result'],true,64,JSON_THROW_ON_ERROR);return $row;
    }
    private static function canonical(mixed $value):mixed
    {
        if(!is_array($value))return $value;
        if(!array_is_list($value))ksort($value,SORT_STRING);
        foreach($value as $key=>$child)$value[$key]=self::canonical($child);
        return $value;
    }
    public function __debugInfo():array{return ['storage'=>'[PRIVATE]'];}
    public function close():void{unset($this->db);$this->closed=true;}
    private function requireOpen():void{if($this->closed)throw new \LogicException('The Facebook command ledger is closed');}
}
