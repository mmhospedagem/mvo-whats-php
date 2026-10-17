<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use MVO\Auth\CredentialStore;
use MVO\Console\{CommandQueue,MessageInput};
use MVO\WaClient;

$options=getopt('',['session:','to:','text:','text-file:','file:','type:','caption:','mimetype:','file-name:','payload-file:','timeout:','status:']);
$session=(string)($options['session'] ?? 'default');
$store=new CredentialStore(dirname(__DIR__).'/.auth',$session);
$queue=new CommandQueue($store->directory.'/commands');
if (isset($options['status']) && is_string($options['status'])) {
    $id=$options['status'];
    echo json_encode(['id'=>$id,'status'=>$queue->status($id),'response'=>$queue->result($id)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
    exit(0);
}
$to=$options['to'] ?? null;
$timeout=filter_var($options['timeout'] ?? '60',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>3600]]);
if (!is_string($to) || trim($to)==='' || $timeout===false) {
    fwrite(STDERR,"Use --to=NUMERO --text=mensagem OU --type=image --file=caminho OU --payload-file=mensagem.json.\n"); exit(2);
}
$client=null;
try {
    $content=MessageInput::parse($options);
    $credentials=$store->load();
    if (!($credentials['registered'] ?? false)) throw new RuntimeException('Vincule primeiro com composer worker.');
    $busy=false;
    try { $store->acquire(); $store->release(); }
    catch (RuntimeException $e) { if (!str_contains($e->getMessage(),'already in use')) throw $e; $busy=true; }
    $store->close();
    if ($busy) {
        $id=$queue->submit($to,$content,$timeout);
        echo "Comando local $id enviado ao worker existente.\n";
        $deadline=microtime(true)+$timeout; $result=null;
        while (($result=$queue->result($id))===null && microtime(true)<$deadline) usleep(100000);
        if ($result===null) throw new RuntimeException('Worker nao respondeu no prazo. Consulte --status='.$id.' antes de reenviar; resultado pode ser desconhecido.');
        if ($result['status']!=='completed') throw new RuntimeException((string)($result['message'] ?? 'Falha no worker'));
        echo json_encode($result['result'],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
    } else {
        $client=new WaClient($session); $client->connect(); $deadline=microtime(true)+$timeout;
        while (!$client->isConnected() && microtime(true)<$deadline && !in_array($client->getState(),['logged_out','stopped','disconnected'],true)) $client->poll(0.25);
        if (!$client->isConnected()) throw new RuntimeException('Sessao nao autenticou no prazo');
        echo json_encode($client->sendMessage($to,$content),JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR).PHP_EOL;
    }
} catch (Throwable $e) { fwrite(STDERR,'Envio: '.$e->getMessage().PHP_EOL); exit(1); }
finally { $client?->disconnect(); $store->close(); }
