<?php
declare(strict_types=1);
namespace MVO\Media;

final class MediaExpiredException extends \RuntimeException
{
    public function __construct(public readonly int $status)
    {
        parent::__construct('Media download failed with HTTP '.$status,$status);
    }
}
