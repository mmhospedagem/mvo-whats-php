<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use MVO\Auth\CredentialStore;

$options=getopt('',['session:']);
$session=(string)($options['session'] ?? 'default');
$store=new CredentialStore(dirname(__DIR__).'/.auth',$session);
try {
    $credentials=$store->load();
    $busy=false;
    try { $store->acquire(); $store->release(); }
    catch (RuntimeException $e) {
        if (!str_contains($e->getMessage(),'already in use')) throw $e;
        $busy=true;
    }
    echo json_encode([
        'session'=>$session,
        'directory'=>$store->directory,
        'registered'=>($credentials['registered'] ?? false) === true,
        'accountJid'=>$credentials['meJid'] ?? null,
        'loginCounter'=>$credentials['loginCounter'] ?? 0,
        'inUse'=>$busy,
        'hasMessagingPreKeys'=>($credentials['serverHasPreKeys'] ?? false) === true,
        'revoked'=>($credentials['revoked'] ?? false) === true,
    ],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR).PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,$e->getMessage().PHP_EOL); exit(1); }
finally { $store->close(); }
