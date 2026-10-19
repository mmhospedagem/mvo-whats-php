<?php
declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';
require dirname(__DIR__, 2).'/channels-core/tests/bootstrap.php';
spl_autoload_register(static function (string $class): void {
    $prefix = 'MVO\\Channels\\WhatsApp\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $path = dirname(__DIR__).'/src/'.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
    if (is_file($path)) {
        require $path;
    }
});
