<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Protocol\{BinaryData,BinaryNode};
use MVO\Signal\SignalStore;

/** Typed server experiments from the pinned upstream catalogue; no experiment is invented. */
final class AbPropsService
{
    /** @var array<string,array{code:int,type:string,defaultValue:bool|int|float|string}>|null */
    private static ?array $catalogue=null;
    /** @var array<int,string> */
    private array $names=[];
    /** @var array<string,bool|int|float|string> */
    private array $values=[];
    /** @var \Closure(BinaryNode,float):BinaryNode */
    private \Closure $query;
    /** @var \Closure():float */
    private \Closure $clock;
    private ?string $hash=null;
    private ?string $abKey=null;
    private ?int $refreshId=null;
    private float $nextSync=0;
    private bool $syncing=false;

    /** @param callable(BinaryNode,float):BinaryNode $query
     * @param callable():float|null $clock */
    public function __construct(callable $query,private readonly ?SignalStore $store=null,?callable $clock=null)
    {
        $this->query=\Closure::fromCallable($query);$this->clock=$clock===null ? static fn():float=>microtime(true) : \Closure::fromCallable($clock);
        foreach(self::catalogue() as $name=>$spec)$this->names[$spec['code']]=$name;
        $bytes=$store?->get('abprops','state');
        if($bytes!==null){$state=BinaryData::decode($bytes);if(!is_array($state))throw new \UnexpectedValueException('Invalid AbProps state');
            foreach($state['values'] ?? [] as $name=>$value)if(isset(self::catalogue()[$name]) && (is_bool($value)||is_int($value)||is_float($value)||is_string($value)))$this->values[$name]=$value;
            $this->hash=is_string($state['hash'] ?? null) ? $state['hash'] : null;$this->abKey=is_string($state['abKey'] ?? null) ? $state['abKey'] : null;$this->refreshId=is_int($state['refreshId'] ?? null) ? $state['refreshId'] : null;}
    }
    /** @return array<string,array{code:int,type:string,defaultValue:bool|int|float|string}> */
    public static function catalogue():array
    {
        if(self::$catalogue===null){$bytes=file_get_contents(dirname(__DIR__,2).'/resources/abprops.json');if($bytes===false)throw new \RuntimeException('Missing AbProps catalogue');self::$catalogue=json_decode($bytes,true,64,JSON_THROW_ON_ERROR)['props'];}return self::$catalogue;
    }
    public function getConfigValue(string $name):bool|int|float|string
    {
        $spec=self::catalogue()[$name] ?? throw new \InvalidArgumentException('Unknown AbProp '.$name);return $this->values[$name] ?? $spec['defaultValue'];
    }
    /** @return array{received:int,applied:int,isDelta:bool,refresh:int} */
    public function sync():array
    {
        if($this->syncing)throw new \LogicException('AbProps sync is already in progress');$this->syncing=true;
        try{for($attempt=0;$attempt<3;$attempt++){try{return $this->syncOnce();}catch(\Throwable $error){$last=$error;}}throw $last;}
        finally{$this->syncing=false;}
    }
    /** Runs only at the advertised bounded refresh interval, including retry backoff after failures. */
    public function tick():void {if(($this->clock)()<$this->nextSync || $this->syncing)return;try{$this->sync();}catch(\Throwable $e){$this->nextSync=($this->clock)()+600;throw $e;}}
    public function reset():void {$this->values=[];$this->hash=null;$this->abKey=null;$this->refreshId=null;$this->nextSync=0;$this->store?->delete('abprops','state');}
    /** @return array{hash:?string,abKey:?string,refreshId:?int,nextSync:float} */
    public function getState():array {return ['hash'=>$this->hash,'abKey'=>$this->abKey,'refreshId'=>$this->refreshId,'nextSync'=>$this->nextSync];}
    /** @return array{received:int,applied:int,isDelta:bool,refresh:int} */
    private function syncOnce():array
    {
        $attrs=['protocol'=>'1'];if($this->hash!==null)$attrs['hash']=$this->hash;if($this->refreshId!==null)$attrs['refresh_id']=(string)$this->refreshId;
        $response=($this->query)(new BinaryNode('iq',['type'=>'get','xmlns'=>'abt','to'=>'s.whatsapp.net'],[new BinaryNode('props',$attrs)]),30.0);
        ServiceTransport::assertResult($response,'abprops.sync');$props=$response->child('props');$delta=($props?->attrs['delta_update'] ?? '')==='true';$values=$delta ? $this->values : [];$received=0;$applied=0;
        foreach(ServiceTransport::children($props,'prop') as $prop){if(++$received>10000)throw new \LengthException('AbProps response limit');$code=$prop->attrs['config_code'] ?? '';
            if(!ctype_digit($code) || !isset($this->names[(int)$code]))continue;$name=$this->names[(int)$code];$spec=self::catalogue()[$name];$values[$name]=self::parse($prop->attrs['config_value'] ?? null,$spec['type'],$spec['defaultValue']);$applied++;}
        $hash=$props?->attrs['hash'] ?? $this->hash;$abKey=$props?->attrs['ab_key'] ?? $this->abKey;$id=$props?->attrs['refresh_id'] ?? null;$refreshId=$id!==null && ctype_digit($id) ? (int)$id : $this->refreshId;
        $raw=$props?->attrs['refresh'] ?? '';$refresh=preg_match('/^-?\d+$/D',$raw) ? max(600,min(604800,(int)$raw)) : 86400;
        $this->store?->set('abprops','state',BinaryData::encode(['values'=>$values,'hash'=>$hash,'abKey'=>$abKey,'refreshId'=>$refreshId]));
        $this->values=$values;$this->hash=$hash;$this->abKey=$abKey;$this->refreshId=$refreshId;$this->nextSync=($this->clock)()+$refresh;
        return ['received'=>$received,'applied'=>$applied,'isDelta'=>$delta,'refresh'=>$refresh];
    }
    private static function parse(?string $value,string $type,bool|int|float|string $default):bool|int|float|string
    {
        if($value===null)return $default;if($type==='bool')return in_array($value,['1','true','True'],true);
        if($type==='int'){if(!preg_match('/^\s*([+-]?)(\d+)/D',$value,$m))return $default;$digits=ltrim($m[2],'0');$number=filter_var(($m[1]==='-' ? '-' : '').($digits==='' ? '0' : $digits),FILTER_VALIDATE_INT);return $number===false ? $default : $number;}
        if($type==='float'){if(!preg_match('/^\s*([+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)/D',$value,$m))return $default;$number=(float)$m[1];return is_finite($number) ? $number : $default;}return $value;
    }
}
