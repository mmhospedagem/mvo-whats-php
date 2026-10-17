<?php
declare(strict_types=1);
namespace MVO\Store;
/** Commit was dispatched but its acknowledgement was lost; never repeat crypto operations automatically. */
final class RemoteCommitUnknown extends \RuntimeException {}
