<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failed = false;
$check = static function (string $name, bool $ok, string $details = '') use (&$failed): void {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $name . ($details !== '' ? ': ' . $details : '') . PHP_EOL;
    $failed = $failed || !$ok;
};
$check('PHP >= 8.2, 64 bits', PHP_VERSION_ID >= 80200 && PHP_INT_SIZE === 8, PHP_VERSION . ' / ' . PHP_BINARY);
$ini = php_ini_loaded_file();
echo '[INFO] php.ini: '.($ini ?: 'padrao do interpretador').PHP_EOL;
foreach (['openssl','sodium','pdo','pdo_sqlite','json','zlib','xmlwriter','curl','fileinfo','gmp','iconv'] as $extension) {
    $check('Extensao ' . $extension, extension_loaded($extension));
}
foreach (['xdebug','gd','ffi','sockets'] as $optional) echo '[INFO] Opcional '.$optional.': '.(extension_loaded($optional)?'disponivel':'nao instalado').PHP_EOL;
$check('Composer autoload', is_file($root . '/vendor/autoload.php'));
foreach (['PHPUnit'=>'phpunit','PHPStan'=>'phpstan'] as $name=>$binary) echo '[INFO] '.$name.': '.(is_file($root.'/vendor/bin/'.$binary)?'disponivel':'instale dependencias de desenvolvimento para usar').PHP_EOL;
echo '[INFO] Timezone: '.date_default_timezone_get().PHP_EOL;
echo PHP_EOL . 'Diagnostico local; nao testa conexao ou pareamento WhatsApp.' . PHP_EOL;
exit($failed ? 1 : 0);
