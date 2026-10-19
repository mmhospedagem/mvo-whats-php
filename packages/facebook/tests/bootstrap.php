<?php
declare(strict_types=1);

$autoload = dirname(__DIR__) . '/vendor/autoload.php';
if (is_file($autoload)) require_once $autoload;

// A Composer installation supplies Messenger. The sibling fallback also permits
// testing this package from the source monorepo without loading its private lab.
spl_autoload_register(static function (string $class): void {
    $package = dirname(__DIR__);
    foreach ([
        'MVO\\Facebook\\' => $package . '/src/',
        'MVO\\Messenger\\' => dirname($package) . '/messenger/src/',
    ] as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) continue;
        $file = $directory . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        if (is_file($file)) require_once $file;
        return;
    }
});
