<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';
use MVO\Auth\CredentialStore;

$root=dirname(__DIR__); $options=getopt('',['session:']); $session=(string)($options['session'] ?? 'default');
$store=new CredentialStore($root.'/.auth',$session);
try {
    $store->acquire(); $credentials=$store->load();
    if (!is_array($credentials) || ($credentials['revoked'] ?? false)!==true) throw new RuntimeException('A sessao nao esta marcada como revogada. Este comando nao substitui vinculos validos.');
    $source=realpath($store->directory); $authRoot=realpath($root.'/.auth'); $workspace=realpath($root);
    if ($source===false || $authRoot===false || $workspace===false || dirname($source)!==$authRoot) throw new RuntimeException('Diretorio de sessao fora do workspace esperado');
    $backupRoot=$root.'/.auth-backups';
    if (!is_dir($backupRoot) && !mkdir($backupRoot,0700)) throw new RuntimeException('Nao foi possivel criar o backup');
    $resolvedBackup=realpath($backupRoot);
    if ($resolvedBackup===false || dirname($resolvedBackup)!==$workspace) throw new RuntimeException('Backup fora do workspace esperado');
    $backup=$resolvedBackup.'/'.$session.'-'.date('Ymd-His').'-'.bin2hex(random_bytes(4));
    if (dirname($backup)!==$resolvedBackup || file_exists($backup)) throw new RuntimeException('Destino de backup invalido');
    // Windows cannot rename a directory containing an open lock/SQLite handle.
    // Another process opening it in this short interval makes rename fail safely.
    $store->close();
    if (!rename($source,$backup)) throw new RuntimeException('Nao foi possivel arquivar. Encerre todos os processos desta sessao e tente novamente.');
    echo 'Sessao antiga preservada em: '.$backup.PHP_EOL;
    echo 'Execute composer worker -- --session='.$session.' e escaneie o novo QR uma vez.'.PHP_EOL;
} catch (Throwable $e) { fwrite(STDERR,'Novo pareamento: '.$e->getMessage().PHP_EOL); exit(1); }
finally { $store->close(); }
