<?php
declare(strict_types=1);

// Repository-only bootstrap for tests/static analysis of the optional SDK packages.
// Consumer applications use each package's Composer autoloader.
require_once dirname(__DIR__).'/vendor/autoload.php';

spl_autoload_register(static function (string $class): void {
    $root = dirname(__DIR__).'/packages/';
    foreach ([
        'MVO\\Channels\\WhatsApp\\' => 'whatsapp-adapter/src/',
        'MVO\\Channels\\Messenger\\' => 'messenger-adapter/src/',
        'MVO\\Channels\\' => 'channels-core/src/',
        'MVO\\Messenger\\' => 'messenger/src/',
        'MVO\\Facebook\\' => 'facebook/src/',
    ] as $prefix => $directory) {
        if (!str_starts_with($class, $prefix)) {
            continue;
        }
        $file = $root.$directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require_once $file;
        }
        return;
    }
});
