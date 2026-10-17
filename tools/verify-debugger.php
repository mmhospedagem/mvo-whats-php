<?php
declare(strict_types=1);

// Real local DBGp client: a PHP child hits a line breakpoint, exposes $probe and resumes.
// Uses an ephemeral loopback port so it does not conflict with VS Code on port 9003.
$root = dirname(__DIR__);
$local = json_decode(file_get_contents($root.'/dev.local.json'),true,512,JSON_THROW_ON_ERROR);
$listener = stream_socket_server('tcp://127.0.0.1:0',$errno,$error);
if ($listener === false) { fwrite(STDERR,"Nao foi possivel abrir listener DBGp: $error\n"); exit(1); }
$address = stream_socket_get_name($listener,false);
$port = (int)substr($address,strrpos($address,':')+1);
$target = $root.'/tools/fixtures/debug-target.php';
$lines = file($target);
$breakLine = null;
foreach ($lines as $number=>$line) if (str_contains($line,'VERIFY_BREAKPOINT')) $breakLine = $number+1;
if ($breakLine === null) throw new RuntimeException('Breakpoint de verificacao ausente.');
$path = str_replace('\\','/',$target);
$encoded = implode('/',array_map('rawurlencode',explode('/',$path)));
$uri = str_starts_with($path,'/') ? 'file://'.$encoded : 'file:///'.str_replace('%3A',':',$encoded);
$stdout = tmpfile(); $stderr = tmpfile();
$process = proc_open(
    [$local['php'],'-c',$local['ini'],'-d','xdebug.client_port='.$port,'-d','xdebug.start_with_request=yes',$target],
    [0=>['pipe','r'],1=>$stdout,2=>$stderr],$pipes,$root,
    array_merge(getenv(),['XDEBUG_MODE'=>'debug','XDEBUG_TRIGGER'=>'mvo'])
);
if (!is_resource($process)) throw new RuntimeException('Falha ao iniciar PHP para debug.');
fclose($pipes[0]);
$connection = false;
$read = static function ($socket): SimpleXMLElement {
    $size = '';
    while (true) {
        $b = fread($socket,1);
        if ($b === false || $b === '') throw new RuntimeException('Conexao DBGp interrompida ou timeout.');
        if ($b === "\0") break;
        if (strlen($size)>8 || !ctype_digit($b)) throw new RuntimeException('Tamanho DBGp invalido.');
        $size .= $b;
    }
    $length = (int)$size;
    if ($length < 1 || $length > 1048576) throw new RuntimeException('Pacote DBGp fora do limite.');
    $xml = '';
    while (strlen($xml)<$length) {
        $part = fread($socket,$length-strlen($xml));
        if ($part === false || $part === '') throw new RuntimeException('Pacote DBGp truncado.');
        $xml .= $part;
    }
    if (fread($socket,1) !== "\0") throw new RuntimeException('Terminador DBGp invalido.');
    $result = simplexml_load_string($xml);
    if ($result === false) throw new RuntimeException('Resposta DBGp invalida.');
    return $result;
};
$send = static function ($socket, string $command, int $id) use ($read): SimpleXMLElement {
    $wire = $command . "\0";
    while ($wire !== '') {
        $sent = fwrite($socket,$wire);
        if ($sent === false || $sent === 0) throw new RuntimeException('Falha ao enviar comando DBGp.');
        $wire = substr($wire,$sent);
    }
    $reply = $read($socket);
    if ((string)$reply['transaction_id'] !== (string)$id || $reply->xpath('//*[local-name()="error"]')) throw new RuntimeException('Comando DBGp rejeitado: '.$reply->asXML());
    return $reply;
};
$ok = false;
try {
    $connection = stream_socket_accept($listener,8);
    if ($connection === false) throw new RuntimeException('Xdebug nao conectou em 8s. Verifique o php.ini do projeto.');
    stream_set_timeout($connection,8);
    $init = $read($connection);
    if ($init->getName() !== 'init') throw new RuntimeException('Handshake DBGp invalido.');
    $reply = $send($connection,'breakpoint_set -i 1 -t line -f '.$uri.' -n '.$breakLine,1);
    if ((string)$reply['id'] === '') throw new RuntimeException('Breakpoint nao foi criado.');
    $reply = $send($connection,'run -i 2',2);
    if ((string)$reply['status'] !== 'break') throw new RuntimeException('PHP nao parou no breakpoint.');
    $reply = $send($connection,'eval -i 3 -- '.base64_encode('$probe'),3);
    $properties = $reply->xpath('//*[local-name()="property"]');
    if (!$properties) throw new RuntimeException('Inspecao nao retornou propriedade.');
    $property = $properties[0];
    $value = (string)$property['encoding'] === 'base64' ? base64_decode((string)$property,true) : (string)$property;
    if ($value !== 'mvo-debug-ready') throw new RuntimeException('Valor inspecionado incorreto.');
    $reply = $send($connection,'run -i 4',4);
    if ((string)$reply['status'] !== 'stopping') throw new RuntimeException('PHP nao concluiu apos retomar.');
    $send($connection,'stop -i 5',5);
    $ok = true;
} catch (Throwable $e) {
    fwrite(STDERR,'[FAIL] Debugger: '.$e->getMessage().PHP_EOL);
} finally {
    if (is_resource($connection)) fclose($connection);
    fclose($listener);
    // Bounded process wait; never leave a stalled test/debug child behind.
    $deadline = microtime(true)+3;
    do { $status = proc_get_status($process); if (!$status['running']) break; usleep(20000); } while (microtime(true)<$deadline);
    if ($status['running']) { proc_terminate($process); $ok = false; }
    proc_close($process);
    rewind($stdout); $output = stream_get_contents($stdout);
    rewind($stderr); $errors = stream_get_contents($stderr);
    fclose($stdout); fclose($stderr);
    if (trim($output) !== 'mvo-debug-ready:15' || trim($errors) !== '') {
        fwrite(STDERR,'Saida inesperada do PHP: '.$output.$errors.PHP_EOL); $ok = false;
    }
}
if ($ok) echo '[OK] Xdebug: conexao DBGp, breakpoint de linha, inspecao de $probe e retomada validados.' . PHP_EOL;
exit($ok ? 0 : 1);
