<?php
declare(strict_types=1);
// Temporary, bounded measurement. Never prints message contents, contacts or credentials.
$root=dirname(__DIR__);
$directory=$root.'/.cache/resource-probe-live';
if(!is_dir($directory))mkdir($directory,0700,true);
$started=hrtime(true)/1e9;
$phase='bootstrap';$connectedAt=null;$messages=0;$nodes=0;$finished=false;$error=null;
$identityBefore=null;$noiseBefore=null;
$snapshot=static function(string $stage,array $extra=[])use($directory,$started,&$connectedAt,&$messages,&$nodes):void{
    file_put_contents($directory.'/php.jsonl',json_encode([
        'phase'=>$stage,'elapsed_seconds'=>round(hrtime(true)/1e9-$started,3),
        'connected_seconds'=>$connectedAt===null?null:round(hrtime(true)/1e9-$connectedAt,3),
        'php_used_mib'=>round(memory_get_usage(false)/1048576,3),
        'php_allocated_mib'=>round(memory_get_usage(true)/1048576,3),
        'php_peak_mib'=>round(memory_get_peak_usage(true)/1048576,3),
        'messages'=>$messages,'nodes'=>$nodes,...$extra
    ],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES).PHP_EOL,FILE_APPEND|LOCK_EX);
};
$snapshot('bootstrap');
require $root.'/vendor/autoload.php';
$snapshot('autoload');
$store=new MVO\Auth\CredentialStore($root.'/.auth','default');
$credentials=$store->load();$store->close();
if(!is_array($credentials)||!($credentials['registered']??false)||($credentials['revoked']??false)){
    $snapshot('not_linked');exit(2);
}
$identityBefore=hash('sha256',$credentials['identityKeyPair']['public']);
$noiseBefore=hash('sha256',$credentials['noiseKeyPair']['public']);
unset($credentials);
$client=new MVO\WaClient('default',autoReconnect:false);
$snapshot('constructed');
$client->on('connection',static function(array $event)use(&$phase,&$connectedAt,$snapshot):void{
    $phase=$event['state'];
    if($phase==='connected'&&$connectedAt===null)$connectedAt=hrtime(true)/1e9;
    $snapshot($phase);
});
$client->on('message',static function(array $event)use(&$messages):void{++$messages;});
$client->on('node',static function(array $event)use(&$nodes):void{++$nodes;});
$client->on('auth_qr',static function():void{throw new RuntimeException('Unexpected pairing instead of session resume');});
try{
    $client->connect();$lastSample=0.0;
    while($client->isRunning()&&hrtime(true)/1e9-$started<160){
        $client->poll(0.5);$now=hrtime(true)/1e9;
        if($now-$lastSample>=1){
            $stage=$connectedAt===null?$phase:($now-$connectedAt<15?'warmup':'idle');
            $snapshot($stage);$lastSample=$now;
        }
        if($connectedAt!==null&&$now-$connectedAt>=80){$finished=true;break;}
        if(is_file($directory.'/stop'))break;
    }
}catch(Throwable $failure){$error=get_class($failure);}
finally{
    $snapshot('before_disconnect',['completed'=>$finished,'error_class'=>$error]);
    $client->disconnect();
    $after=$store->load();$store->close();
    $snapshot('finished',[
        'completed'=>$finished,'error_class'=>$error,'registered'=>$after['registered']??false,
        'identity_preserved'=>isset($after['identityKeyPair']['public'])&&hash_equals($identityBefore,hash('sha256',$after['identityKeyPair']['public'])),
        'noise_key_preserved'=>isset($after['noiseKeyPair']['public'])&&hash_equals($noiseBefore,hash('sha256',$after['noiseKeyPair']['public']))
    ]);
}
exit($finished&&$error===null?0:1);
