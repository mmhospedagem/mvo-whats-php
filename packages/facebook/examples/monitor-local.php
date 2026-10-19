<?php
declare(strict_types=1);

// Synthetic data only: no authentication, network access, Facebook action or UI.
$composer = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($composer)) require $composer;
spl_autoload_register(static function (string $class): void {
    $prefix = 'MVO\\Facebook\\';
    if (!str_starts_with($class, $prefix)) return;
    $file = dirname(__DIR__) . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($file)) require_once $file;
});

use MVO\Facebook\Monitoring\ResourceScope;
use MVO\Facebook\Monitoring\SnapshotItem;
use MVO\Facebook\Monitoring\SnapshotMonitor;
use MVO\Facebook\Monitoring\SnapshotPage;

if (PHP_SAPI !== 'cli') throw new RuntimeException('Run this local example through PHP CLI');
$file = $argv[1] ?? sys_get_temp_dir() . '/mvo-facebook-monitor-example/state.sqlite';
$monitor = new SnapshotMonitor($file);
$scope = new ResourceScope('100000000000001', '200000000000001', 'page.posts', '200000000000001');

// The first exhaustive synthetic collection is historical baseline, never news.
$pages = [
    new SnapshotPage('fixture-baseline-v1', [
        new SnapshotItem('200000000000001_300000000000001', ['text' => 'Publicação de exemplo', 'author_id' => '200000000000001'], ['comments' => 2, 'reactions' => 5]),
    ], nextCursor: 'SYNTHETIC_NEXT_PAGE'),
    new SnapshotPage('fixture-baseline-v1', [
        new SnapshotItem('200000000000001_300000000000002', ['text' => 'Outro item histórico']),
    ], cursor: 'SYNTHETIC_NEXT_PAGE', complete: true),
    // A limited later collection can report observed changes, but no removals.
    new SnapshotPage('fixture-update-v1', [
        new SnapshotItem('200000000000001_300000000000001', ['text' => 'Publicação de exemplo editada'], ['comments' => 3]),
        new SnapshotItem('200000000000001_300000000000003', ['text' => 'Novo item de exemplo']),
    ], complete: false),
];

echo "Exemplo sintético local; nenhuma conta Facebook foi consultada.\n";
foreach ($pages as $page) {
    echo json_encode(['snapshot' => $page->snapshotId, ...$monitor->ingest($scope, $page)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
}
echo json_encode(['monitor' => $monitor->state($scope)], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;

// A real consumer persists its own event cursor after processing idempotently.
$after = 0;
do {
    $events = $monitor->events($scope, $after, 2);
    foreach ($events as $event) {
        echo json_encode($event, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE) . PHP_EOL;
        $after = $event['id'];
    }
} while (count($events) === 2);

echo "Execute novamente com o mesmo arquivo SQLite para verificar os recibos idempotentes.\n";
