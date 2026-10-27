<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use MVO\Auth\QrCode;
use MVO\History\CallbackHistorySyncSink;
use MVO\Protocol\BinaryData;
use MVO\WaClient;

// One private application database per account; never place it under a public web root.
$options=getopt('', ['session:', 'directory:', 'help']);
if (isset($options['help'])) {
    echo "php examples/history-sink.php --session=cliente-demo --directory=/private/mvo\n";
    exit(0);
}
$directory=(string)($options['directory'] ?? dirname(__DIR__).'/.auth/history-sink-demo');
if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new RuntimeException('Cannot create private directory');
$session=(string)($options['session'] ?? 'cliente-demo');
$path=$directory.'/history-'.hash('sha256',$session).'.sqlite';
$db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if (PHP_OS_FAMILY!=='Windows') chmod($path,0600);
$db->exec('CREATE TABLE IF NOT EXISTS chunks (id TEXT PRIMARY KEY, summary TEXT NOT NULL)');
$db->exec('CREATE TABLE IF NOT EXISTS staged (chunk_id TEXT NOT NULL, sequence INTEGER NOT NULL, payload TEXT NOT NULL, PRIMARY KEY(chunk_id,sequence))');
$db->exec('CREATE TABLE IF NOT EXISTS items (chunk_id TEXT NOT NULL, sequence INTEGER NOT NULL, payload TEXT NOT NULL, PRIMARY KEY(chunk_id,sequence))');
$current=null; $sequence=0; $committed=false;
$start=static function(string $id) use($db,&$current,&$sequence,&$committed):void {
    if ($current===$id) return;
    $current=$id; $sequence=0;
    $check=$db->prepare('SELECT 1 FROM chunks WHERE id=?'); $check->execute([$id]);
    $committed=$check->fetchColumn()!==false;
    // A process may have died with provisional rows from an earlier attempt.
    $delete=$db->prepare('DELETE FROM staged WHERE chunk_id=?'); $delete->execute([$id]);
};
$sink=new CallbackHistorySyncSink(
    item:static function(array $item,array $chunk) use($db,$start,&$sequence,&$committed):void {
        $start($chunk['id']);
        if ($committed) return;
        $insert=$db->prepare('INSERT INTO staged(chunk_id,sequence,payload) VALUES(?,?,?)');
        // BinaryData preserves protobuf bytes and large integers. No raw payload logging.
        $insert->execute([$chunk['id'],++$sequence,BinaryData::encode($item)]);
    },
    commit:static function(array $chunk) use($db,$start,&$current,&$committed):void {
        $start($chunk['id']);
        if ($committed) { $current=null; return; }
        $db->beginTransaction();
        try {
            $copy=$db->prepare('INSERT INTO items SELECT chunk_id,sequence,payload FROM staged WHERE chunk_id=?'); $copy->execute([$chunk['id']]);
            $mark=$db->prepare('INSERT INTO chunks(id,summary) VALUES(?,?)'); $mark->execute([$chunk['id'],BinaryData::encode($chunk)]);
            $delete=$db->prepare('DELETE FROM staged WHERE chunk_id=?'); $delete->execute([$chunk['id']]);
            $db->commit(); $current=null;
        } catch (Throwable $error) { if ($db->inTransaction()) $db->rollBack(); throw $error; }
    },
    abort:static function(array $chunk,Throwable $error) use($db,&$current):void {
        $delete=$db->prepare('DELETE FROM staged WHERE chunk_id=?'); $delete->execute([$chunk['id']]);
        $current=null;
        fwrite(STDERR,"History attempt aborted (".$error::class.").\n");
    },
);
$client=new WaClient(
    sessionId:$session,
    authDirectory:$directory.'/sessions',
    historySink:$sink,
    historyPersist:false,
);
$client->on('auth_qr',static function(array $event) use($directory):void {
    file_put_contents($directory.'/qr.svg',QrCode::svg($event['qr']));
    echo "Scan the QR in the private directory.\n";
});
$client->on('history_sync_chunk',static function(array $chunk):void {
    echo "History committed: ".$chunk['messagesCount']." messages.\n";
});
// Commands that use the SDK belong to the outer poll loop, after sink callbacks return.
try { $client->connect(); $client->run(); }
finally { $client->stop(); }
