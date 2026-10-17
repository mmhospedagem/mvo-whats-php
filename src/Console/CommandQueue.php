<?php
declare(strict_types=1);
namespace MVO\Console;
use MVO\Protocol\BinaryData;

/** Private local IPC: submit sends to an already running worker without opening a second session. */
final class CommandQueue
{
    public function __construct(private readonly string $directory) {
        if (!is_dir($directory) && !mkdir($directory,0700,true) && !is_dir($directory)) throw new \RuntimeException('Cannot create local command directory');
    }
    /** @param string|array<string,mixed> $content */
    public function submit(string $to,string|array $content,int $ttlSeconds=60): string {
        if (trim($to)==='' || (is_string($content) && (trim($content)==='' || strlen($content)>65536)) || $content===[] || $ttlSeconds<1 || $ttlSeconds>3600) throw new \InvalidArgumentException('Invalid local send command');
        $id=bin2hex(random_bytes(16));
        // Plain text keeps the old queue format; structured payloads preserve binary keys.
        $body = is_string($content) ? ['text'=>$content] : ['content'=>json_decode(BinaryData::encode($content),true,128,JSON_THROW_ON_ERROR)];
        $command = ['id'=>$id,'to'=>$to,...$body,'expiresAt'=>time()+$ttlSeconds];
        if (strlen(json_encode($command,JSON_THROW_ON_ERROR))>524288) throw new \LengthException('Local command too large; send media by file path');
        $this->write($id.'.request.json',$command);
        return $id;
    }
    /** @param callable(string,string|array<string,mixed>):array<string,mixed> $send */
    public function process(callable $send,int $maximum=1): int {
        $processed=0;
        foreach (glob($this->directory.'/*.request.json') ?: [] as $path) {
            if ($processed>=$maximum) break;
            $id=substr(basename($path),0,-strlen('.request.json'));
            if (!preg_match('/^[a-f0-9]{32}$/D',$id) || is_link($path)) continue;
            $working=$this->directory.'/'.$id.'.processing.json';
            if (!@rename($path,$working)) continue;
            $processed++;
            try {
                if (filesize($working)>524288) throw new \UnexpectedValueException('Local command too large');
                $command=json_decode(file_get_contents($working),true,128,JSON_THROW_ON_ERROR);
                if (!is_array($command) || ($command['id'] ?? '')!==$id || !is_string($command['to'] ?? null) || !is_int($command['expiresAt'] ?? null)) throw new \UnexpectedValueException('Invalid local command');
                $content = isset($command['content']) ? BinaryData::decode(json_encode($command['content'],JSON_THROW_ON_ERROR)) : ($command['text'] ?? null);
                if ((!is_string($content) && !is_array($content)) || $content===[]) throw new \UnexpectedValueException('Invalid local content');
                if ($command['expiresAt']<=time()) throw new \RuntimeException('Command expired before submission; nothing was sent');
                $result=$send($command['to'],$content);
                $this->write($id.'.result.json',['id'=>$id,'status'=>'completed','result'=>$result]);
            } catch (\Throwable $e) {
                $this->write($id.'.result.json',['id'=>$id,'status'=>'failed','message'=>$e->getMessage()]);
            }
            unlink($working);
        }
        return $processed;
    }
    /** @return array<string,mixed>|null */
    public function result(string $id): ?array {
        $this->validateId($id); $path=$this->directory.'/'.$id.'.result.json';
        if (!is_file($path)) return null;
        $result=json_decode(file_get_contents($path),true,32,JSON_THROW_ON_ERROR);
        if (!is_array($result)) throw new \UnexpectedValueException('Invalid local command result');
        return $result;
    }
    public function status(string $id): string {
        $this->validateId($id);
        $result=$this->result($id);
        if ($result!==null) return (string)$result['status'];
        if (is_file($this->directory.'/'.$id.'.processing.json')) return 'processing_unknown_after_restart';
        if (is_file($this->directory.'/'.$id.'.request.json')) return 'pending';
        return 'not_found';
    }
    private function validateId(string $id): void { if (!preg_match('/^[a-f0-9]{32}$/D',$id)) throw new \InvalidArgumentException('Invalid command ID'); }
    /** @param array<string,mixed> $value */
    private function write(string $name,array $value): void {
        $temp=tempnam($this->directory,'command-');
        if ($temp===false) throw new \RuntimeException('Cannot stage local command');
        try {
            if (file_put_contents($temp,json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),LOCK_EX)===false || !rename($temp,$this->directory.'/'.$name)) throw new \RuntimeException('Cannot commit local command');
        } finally { if (is_file($temp)) unlink($temp); }
    }
}
