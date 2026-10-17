<?php
declare(strict_types=1);
namespace MVO\Store;
/** Another process committed this session after the transaction snapshot was read. */
final class RemoteStoreConflict extends \RuntimeException {}
