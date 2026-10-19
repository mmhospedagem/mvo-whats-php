<?php
declare(strict_types=1);
namespace MVO\Messenger\Transport;

/** One explicitly selected local file. Never serialize private local paths. */
final class UploadFile
{
    private readonly string $localPath;
    private readonly string $selectedDigest;
    public readonly int $size;
    public function __construct(#[\SensitiveParameter] string $path, public readonly string $filename, public readonly string $mediaType)
    {
        if ($path === '' || str_contains($path, "\0") || preg_match('~^[a-z][a-z0-9+.-]*://~i', $path)) throw new \InvalidArgumentException('Select a local regular file for upload');
        $resolved = realpath($path);
        if ($resolved === false || !is_file($resolved) || !is_readable($resolved)) throw new \InvalidArgumentException('The selected upload file is not readable');
        if ($filename === '' || strlen($filename) > 255 || preg_match('~[\x00-\x1f\x7f/\\\\]~', $filename)) throw new \InvalidArgumentException('Use a plain upload filename without paths or control characters');
        if (!preg_match('~^[A-Za-z0-9!#$&^_.+-]+/[A-Za-z0-9!#$&^_.+-]+$~D', $mediaType)) throw new \InvalidArgumentException('Use a valid upload media type');
        $size = filesize($resolved);
        if ($size === false || $size < 1 || $size > 134217728) throw new \LengthException('Upload files must contain between 1 byte and 128 MiB');
        $this->localPath = $resolved;
        $this->size = $size;
        $digest = hash_file('sha256', $resolved);
        if ($digest === false) throw new \RuntimeException('Unable to read the selected upload file');
        $this->selectedDigest = $digest;
    }
    /** @internal Transport boundary only. */
    public function curlFile(): \CURLFile { return new \CURLFile($this->localPath, $this->mediaType, $this->filename); }
    /** @internal Native transport only. Caller must close the selected stream.
     * @return resource */
    public function openRange(int $offset,int $length)
    {
        if($offset<0||$length<0||$offset>$this->size||$length>$this->size-$offset)throw new \OutOfBoundsException('The upload range exceeds the selected file');
        $this->sha256();
        $stream=fopen($this->localPath,'rb');
        if($stream===false)throw new \RuntimeException('Unable to open the selected upload file');
        if(fseek($stream,$offset)!==0){fclose($stream);throw new \RuntimeException('Unable to seek the selected upload file');}
        return $stream;
    }
    /** Recheck before I/O; the service also binds the content digest to idempotency. */
    public function sha256(): string
    {
        clearstatcache(true, $this->localPath);
        if (!is_file($this->localPath) || filesize($this->localPath) !== $this->size) throw new \RuntimeException('The upload file changed after selection');
        $digest = hash_file('sha256', $this->localPath);
        if ($digest === false) throw new \RuntimeException('Unable to read the selected upload file');
        if (!hash_equals($this->selectedDigest, $digest)) throw new \RuntimeException('The upload file contents changed after selection');
        return $digest;
    }
    public function __debugInfo(): array { return ['filename' => $this->filename, 'media_type' => $this->mediaType, 'bytes' => $this->size, 'path' => '[PRIVATE]']; }
    public function __serialize(): never { throw new \LogicException('Private upload files cannot be serialized'); }
}
