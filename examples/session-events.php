<?php
declare(strict_types=1);

// PHP worker example: replace $publish with the API's event/state publisher.
// --help exits before opening a session or connecting to WhatsApp.
$options=getopt('', ['help','session:','auth-dir:','mode:','phone:','duration:']);
if(isset($options['help'])){
    echo "Eventos de sessão diretamente pela biblioteca PHP\n"
        ."php -c .dev/php.ini examples/session-events.php --session=cliente-123\n"
        ."php -c .dev/php.ini examples/session-events.php --session=cliente-123 --mode=code --phone=5511999999999\n"
        ."--auth-dir=PASTA: armazenamento persistente privado (padrão: .auth do projeto)\n"
        ."--duration=SEGUNDOS: duração limitada; 0 mantém o worker ativo\n"
        ."A mesma sessão/pasta restaura as credenciais; não execute junto de outro worker dessa sessão.\n"
        ."STDOUT contém JSON Lines de eventos, incluindo o QR/código para o usuário autorizado.\n";
    exit(0);
}
$mode=$options['mode']??'qr';$session=$options['session']??'api-demo';
$authDirectory=$options['auth-dir']??dirname(__DIR__).'/.auth';$phone=$options['phone']??null;
$duration=filter_var($options['duration']??'0',FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>86400]]);
if(!is_string($session)||!preg_match('/^[a-zA-Z0-9][a-zA-Z0-9_.-]{0,63}$/D',$session)||!is_string($authDirectory)||$authDirectory===''||!in_array($mode,['qr','code'],true)||$duration===false||($mode==='code'&&(!is_string($phone)||$phone===''))){
    fwrite(STDERR,"Opções inválidas; use --help.\n");exit(2);
}

require dirname(__DIR__).'/vendor/autoload.php';
use MVO\Auth\QrCode;
use MVO\WaClient;

$publish=static function(string $event,array $data)use($session):void{
    echo json_encode(['sessionId'=>$session,'event'=>$event,'data'=>$data],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES).PHP_EOL;
};
$client=new WaClient(sessionId:$session,authDirectory:$authDirectory);
$client->on('auth_qr',static function(array $event)use($publish,$mode):void{
    if($mode==='qr')$publish('auth_qr',['qr'=>$event['qr'],'svg'=>QrCode::svg($event['qr']),'expiresIn'=>$event['expiresIn']]);
});
$client->on('auth_code',static fn(array $event)=>$publish('auth_code',['code'=>$event['code']]));
$client->on('connection',static fn(array $event)=>$publish('connection',['state'=>$event['state']]));
$client->on('version_updated',static fn(array $event)=>$publish('version_updated',['version'=>$event['version']]));
$client->on('version_error',static fn(array $event)=>$publish('version_error',['message'=>$event['message']]));
$client->on('reconnect',static fn(array $event)=>$publish('reconnect',['attempt'=>$event['attempt'],'delay'=>$event['delay']]));
// auth_paired contains private credentials: deliberately publish only the public outcome.
$client->on('auth_paired',static fn(array $event)=>$publish('auth_paired',['registered'=>true]));
foreach(['auth_qr_expired','auth_code_expired'] as $event){
    $client->on($event,static fn(array $data)=>$publish($event,[]));
}
$client->on('auth_code_refresh',static fn(array $event)=>$publish('auth_code_refresh',['forceManual'=>$event['forceManual']]));
$stopping=false;$failed=false;$codeRequested=false;
$stop=static function()use(&$stopping):void{$stopping=true;};
if(function_exists('sapi_windows_set_ctrl_handler'))sapi_windows_set_ctrl_handler(static function(int $event)use($stop):bool{if(in_array($event,[PHP_WINDOWS_EVENT_CTRL_C,PHP_WINDOWS_EVENT_CTRL_BREAK],true)){$stop();return true;}return false;});
if(function_exists('pcntl_async_signals')){pcntl_async_signals(true);pcntl_signal(SIGINT,$stop);pcntl_signal(SIGTERM,$stop);}

try{
    $client->connect(); // Loads and resumes this identity, or begins pairing for a new one.
    $deadline=$duration>0?hrtime(true)/1000000000+$duration:INF;
    while(!$stopping&&$client->isRunning()&&hrtime(true)/1000000000<$deadline){
        $client->poll(0.25); // Reads events, rotates QR, reconnects and keeps WhatsApp alive.
        if($mode==='code'&&!$codeRequested&&!$client->isRegistered()&&$client->getState()==='pairing'){
            $client->requestPairingCode($phone);$codeRequested=true;
        }
        // Consume API commands here, outside callbacks, e.g. $client->sendText($to,$text).
    }
}catch(Throwable $error){
    fwrite(STDERR,'Falha de sessão ('.get_class($error).").\n");$failed=true;
}finally{$client->disconnect();} // Saves remain available for the next process; no logout.
exit($failed?1:0);
