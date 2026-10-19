<?php
declare(strict_types=1);
namespace MVO\Facebook\Storage;

use MVO\Facebook\FacebookException;

/** Local application preferences, scoped to the authenticated account. */
final class SelectionStore
{
    private \PDO $db;
    private bool $closed=false;
    public function __construct(string $file,private readonly string $accountId)
    {
        if(!preg_match('/^[1-9][0-9]{0,24}$/D',$accountId)||!is_dir(dirname($file)))throw new \InvalidArgumentException('Expected an account and a private selection directory');
        $this->db=new \PDO('sqlite:'.$file,null,null,[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);@chmod($file,0600);
        $this->db->exec('PRAGMA busy_timeout=5000; CREATE TABLE IF NOT EXISTS facebook_resources(account_id TEXT NOT NULL,kind TEXT NOT NULL,id TEXT NOT NULL,payload TEXT NOT NULL,observed_at INTEGER NOT NULL,PRIMARY KEY(account_id,kind,id)); CREATE TABLE IF NOT EXISTS facebook_selections(account_id TEXT NOT NULL,kind TEXT NOT NULL,id TEXT NOT NULL,PRIMARY KEY(account_id,kind))');
    }
    /** Only normalized rows from a successful resource query belong here.
     * @param list<array<string,mixed>> $items
     */
    public function remember(string $kind,array $items):void
    {
        $this->requireOpen();
        self::kind($kind);if(!array_is_list($items)||count($items)>5000)throw new \InvalidArgumentException('Invalid Facebook resource list');
        $this->db->beginTransaction();
        try{
            $statement=$this->db->prepare('INSERT INTO facebook_resources(account_id,kind,id,payload,observed_at) VALUES(?,?,?,?,?) ON CONFLICT(account_id,kind,id) DO UPDATE SET payload=excluded.payload,observed_at=excluded.observed_at');
            foreach($items as $item){
                $id=$item['id']??null;if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,24}$/D',$id)||!array_key_exists('name',$item)||($item['name']!==null&&!is_string($item['name'])))throw new \InvalidArgumentException('Expected a confirmed native resource ID and a normalized name');
                $safe=array_intersect_key($item,array_flip(['id','name','type','actor_id','delegate_page_id','currency','account_status','status']));
                foreach($safe as $value)if(!is_scalar($value)&&$value!==null)throw new \InvalidArgumentException('Resource preferences require normalized scalar fields');
                $statement->execute([$this->accountId,$kind,$id,json_encode($safe,JSON_THROW_ON_ERROR),time()]);
            }
            $this->db->commit();
        }catch(\Throwable $error){try{$this->db->rollBack();}catch(\PDOException){}throw $error;}
    }
    public function select(string $kind,string $id):void
    {
        $this->requireOpen();
        self::kind($kind);
        $statement=$this->db->prepare('SELECT 1 FROM facebook_resources WHERE account_id=? AND kind=? AND id=?');$statement->execute([$this->accountId,$kind,$id]);
        if($statement->fetchColumn()===false)throw new FacebookException('facebook_resource_not_discovered','List the available resources for this account before selecting an exact ID');
        $statement=$this->db->prepare('INSERT INTO facebook_selections(account_id,kind,id) VALUES(?,?,?) ON CONFLICT(account_id,kind) DO UPDATE SET id=excluded.id');$statement->execute([$this->accountId,$kind,$id]);
    }
    /** @return array<string,mixed>|null */
    public function selected(string $kind):?array
    {
        $this->requireOpen();
        self::kind($kind);$statement=$this->db->prepare('SELECT r.payload,r.observed_at FROM facebook_selections s JOIN facebook_resources r ON r.account_id=s.account_id AND r.kind=s.kind AND r.id=s.id WHERE s.account_id=? AND s.kind=?');$statement->execute([$this->accountId,$kind]);$row=$statement->fetch(\PDO::FETCH_ASSOC);
        return $row===false?null:[...json_decode($row['payload'],true,32,JSON_THROW_ON_ERROR),'observed_at'=>(int)$row['observed_at']];
    }
    private static function kind(string $kind):void{if(!in_array($kind,['page','ad_account'],true))throw new \InvalidArgumentException('Unknown Facebook resource kind');}
    public function close():void{unset($this->db);$this->closed=true;}
    private function requireOpen():void{if($this->closed)throw new \LogicException('The Facebook selection store is closed');}
}
