<?php
declare(strict_types=1);

// Read-only channel example. --help exits without opening a WhatsApp session.
$options=getopt('',['help','session:','auth-dir:','operation:','channel:','invite:','count:','timeout:']);
if(isset($options['help']) || !isset($options['operation'])) {
    echo "Canais pela biblioteca MVO PHP\n"
        ."php examples/channels.php --session=cliente-123 --operation=list\n"
        ."php examples/channels.php --session=cliente-123 --operation=metadata --channel=123456789@newsletter\n"
        ."php examples/channels.php --session=cliente-123 --operation=metadata --invite=https://whatsapp.com/channel/CONVITE\n"
        ."php examples/channels.php --session=cliente-123 --operation=messages --channel=123456789@newsletter --count=25\n"
        ."A sessão deve estar vinculada e não pode estar aberta em outro worker.\n"
        ."--auth-dir=PASTA: armazenamento privado persistente; --timeout=60: espera pela conexão.\n"
        ."Este exemplo consulta canais. Publicação/administração estão disponíveis em \$client->channels().\n";
    exit(0);
}
require dirname(__DIR__).'/vendor/autoload.php';
use MVO\WaClient;
use MVO\Auth\CredentialStore;
use MVO\Service\NewsletterService;

$operation=$options['operation'];$session=$options['session'] ?? 'default';$directory=$options['auth-dir'] ?? dirname(__DIR__).'/.auth';$channel=$options['channel'] ?? null;$invite=$options['invite'] ?? null;
$count=filter_var($options['count'] ?? '25',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>1000]]);
$timeout=filter_var($options['timeout'] ?? '60',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>3600]]);
if(!is_string($operation) || !in_array($operation,['list','metadata','messages'],true) || !is_string($session) || !preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D',$session) || !is_string($directory) || $directory==='' || $count===false || $timeout===false || ($operation!=='list' && !is_string($channel) && !is_string($invite)) || ($operation==='messages' && !is_string($channel))) {
    fwrite(STDERR,"Opções inválidas; use --help.\n");exit(2);
}
$client=null;
// Event protobufs, raw nodes and media/poll hashes can contain binary bytes.
// Keep them in the SDK; this CLI prints only the public summary below.
$publicEvent=static function(array $data):array {
    $summary=[];
    foreach(['chat','id','serverId','responseServerId','questionServerId','text','type','timestampSeconds','replayed','action'] as $field) {
        $value=$data[$field] ?? null;
        if(array_key_exists($field,$data) && ($value===null || is_string($value) || is_int($value) || is_bool($value))) $summary[$field]=$value;
    }
    if(!isset($summary['chat'])) foreach(['chatJid','channelJid'] as $field) if(is_string($data[$field] ?? null)) {$summary['chat']=$data[$field];break;}
    if(!isset($summary['id']) && is_string($data['stanzaId'] ?? null)) $summary['id']=$data['stanzaId'];
    if(is_array($data['update'] ?? null)) {
        $update=[];
        foreach(['kind','views','forwards','responses'] as $field) {
            $value=$data['update'][$field] ?? null;
            if(is_string($value) || is_int($value)) $update[$field]=$value;
        }
        if($update!==[]) $summary['update']=$update;
    }
    return $summary;
};
try {
    $credentials=new CredentialStore($directory,$session);$registered=($credentials->load()['registered'] ?? false)===true;$credentials->close();
    if(!$registered) throw new RuntimeException('Vincule a sessão antes de consultar canais.');
    $client=new WaClient(sessionId:$session,authDirectory:$directory);
    foreach(['newsletter_message','newsletter_update','channel_update'] as $eventName) {
        $client->on($eventName,static function(array $event)use($eventName,$publicEvent):void {echo json_encode(['event'=>$eventName,'data'=>$publicEvent($event)],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;});
    }
    $client->connect();$deadline=microtime(true)+$timeout;
    while(!$client->isConnected() && $client->isRunning() && microtime(true)<$deadline) $client->poll(0.25);
    if(!$client->isConnected()) throw new RuntimeException('A sessão não conectou no prazo.');
    $channels=$client->channels();
    $result=match($operation) {
        'list'=>array_map(NewsletterService::normalizeMetadata(...),$channels->listSubscribed()),
        'metadata'=>NewsletterService::normalizeMetadata(is_string($channel)?$channels->fetch($channel):$channels->fetchByInvite($invite)),
        'messages'=>array_map(static fn(array $entry):array=>['event'=>$entry['event'],'data'=>$publicEvent($entry['data'])],$channels->fetchMessagesDecoded($channel,$count)),
    };
    echo json_encode(['operation'=>$operation,'result'=>$result],JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch(Throwable $error) {fwrite(STDERR,'Canais: '.$error->getMessage().PHP_EOL);exit(1);}
finally {$client?->disconnect();}
