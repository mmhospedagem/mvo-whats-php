<?php
declare(strict_types=1);
echo json_encode([
    'version'=>PHP_VERSION,
    'minor'=>PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION,
    'bits'=>PHP_INT_SIZE*8,
    'ts'=>(bool)PHP_ZTS,
    'ext'=>ini_get('extension_dir'),
    'ca'=>ini_get('curl.cainfo')
],JSON_THROW_ON_ERROR);
