<?php
declare(strict_types=1);

namespace MVO\Media;

/** Local files, explicit binary strings and open streams. URLs are never fetched. */
final class MediaSource
{
    /** @param mixed $source */
    public static function read(mixed $source, int $maxBytes): string
    {
        if ($maxBytes <= 0) throw new \InvalidArgumentException('Media maxBytes must be positive');
        if (is_array($source)) {
            if (isset($source['bytes']) && is_string($source['bytes'])) {
                if (strlen($source['bytes']) > $maxBytes) throw new \LengthException('Media exceeds maxBytes');
                return $source['bytes'];
            }
            $source = $source['path'] ?? null;
        }
        $close = false;
        if (is_string($source)) {
            if (str_contains($source, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $source) || !is_file($source) || !is_readable($source)) {
                throw new \InvalidArgumentException('Media must be a readable local file; use [bytes => ...] for binary data');
            }
            $size = filesize($source);
            if ($size !== false && $size > $maxBytes) throw new \LengthException('Media exceeds maxBytes');
            $source = fopen($source, 'rb');
            $close = true;
        }
        if (!is_resource($source) || get_resource_type($source) !== 'stream') {
            throw new \InvalidArgumentException('Media requires a local path, bytes array or stream resource');
        }
        try {
            $bytes = '';
            while (!feof($source)) {
                $chunk = fread($source, min(65536, $maxBytes - strlen($bytes) + 1));
                if ($chunk === false) throw new \RuntimeException('Cannot read media stream');
                if ($chunk === '' && !feof($source)) throw new \RuntimeException('Media stream stalled');
                $bytes .= $chunk;
                if (strlen($bytes) > $maxBytes) throw new \LengthException('Media exceeds maxBytes');
            }
            return $bytes;
        } finally {
            if ($close) fclose($source);
        }
    }

    /** @param mixed $source */
    public static function fileName(mixed $source): ?string
    {
        $path = is_array($source) ? ($source['path'] ?? null) : $source;
        return is_string($path) ? basename(str_replace('\\', '/', $path)) : null;
    }
}
