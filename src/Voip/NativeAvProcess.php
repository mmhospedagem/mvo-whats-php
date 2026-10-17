<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Shell-free FFmpeg process with nonblocking loopback media; no Windows anonymous-pipe stalls. @internal */
final class NativeAvProcess
{
    public const SOCKET='{MVO_MEDIA_SOCKET}';
    /** @var resource|null */
    private $process=null;
    /** @var resource|null */
    private $socket=null;
    private ?int $exit=null;
    private string $error='';
    private ?string $stdoutPath=null;
    private ?string $stderrPath=null;
    private bool $started=false;
    /** @param list<string> $arguments */
    public function __construct(private readonly string $binary,private readonly array $arguments)
    {
        if(!is_file($binary)) throw new \RuntimeException('AV executable not found: '.$binary);
        if(!array_is_list($arguments) || count(array_keys($arguments,self::SOCKET,true))!==1) throw new \InvalidArgumentException('AV process needs exactly one media socket argument');
        foreach($arguments as $argument) if(str_contains($argument,"\0")) throw new \InvalidArgumentException('AV arguments cannot contain NUL');
    }
    public function start(float $timeout=10):void
    {
        if($this->started) throw new \LogicException('AV process can only be started once');
        if(!is_finite($timeout) || $timeout<=0 || $timeout>30) throw new \InvalidArgumentException('AV startup timeout must be 0..30 seconds');
        $this->started=true; $server=stream_socket_server('tcp://127.0.0.1:0',$errno,$reason,STREAM_SERVER_BIND|STREAM_SERVER_LISTEN);
        if($server===false) throw new \RuntimeException('Cannot allocate AV loopback socket: '.$reason);
        $address=stream_socket_get_name($server,false); if($address===false) { fclose($server); throw new \RuntimeException('Cannot inspect AV loopback socket'); }
        stream_set_blocking($server,false); $url='tcp://'.$address.'?tcp_nodelay=1';
        $arguments=array_map(static fn(string $argument):string=>$argument===self::SOCKET?$url:$argument,$this->arguments);
        $stdout=tempnam(sys_get_temp_dir(),'mvo-av-out-'); $stderr=tempnam(sys_get_temp_dir(),'mvo-av-err-');
        if($stdout===false || $stderr===false) { fclose($server); if(is_string($stdout)) @unlink($stdout); if(is_string($stderr)) @unlink($stderr); throw new \RuntimeException('Cannot allocate AV process logs'); }
        $this->stdoutPath=$stdout; $this->stderrPath=$stderr;
        $null=PHP_OS_FAMILY==='Windows'?'NUL':'/dev/null';
        $process=proc_open([$this->binary,...$arguments],[0=>['file',$null,'r'],1=>['file',$this->stdoutPath,'w'],2=>['file',$this->stderrPath,'w']],$pipes,null,null,['bypass_shell'=>true,'create_no_window'=>true]);
        if(!is_resource($process)) { fclose($server); $this->cleanup(); throw new \RuntimeException('Cannot start native AV process'); }
        $this->process=$process; $deadline=microtime(true)+$timeout;
        try {
            while(true) {
                $socket=@stream_socket_accept($server,0);
                if($socket!==false) { stream_set_blocking($socket,false); $this->socket=$socket; break; }
                if(!$this->running()) throw new \RuntimeException('AV process ended before media connection: '.$this->error);
                if(microtime(true)>=$deadline) throw new \RuntimeException('AV process startup timed out: '.$this->error);
                usleep(1000);
            }
        } catch(\Throwable $error) { $this->cancel(); throw $error; }
        finally { fclose($server); }
    }
    public function read(int $maximum=65536):string
    {
        if($maximum<1 || $maximum>1048576) throw new \InvalidArgumentException('Invalid AV read bound');
        if($this->socket===null) return '';
        $bytes=stream_get_contents($this->socket,$maximum); if($bytes===false) throw new \RuntimeException('Cannot read native AV media');
        $this->checkLogs(); return $bytes;
    }
    public function write(string $bytes):int
    {
        if($this->socket===null) throw new \LogicException('AV media socket is closed');
        if($bytes==='') return 0;
        if(strlen($bytes)>1048576) throw new \LengthException('AV write exceeds 1 MiB');
        $written=@fwrite($this->socket,$bytes); if($written===false) throw new \RuntimeException('Cannot write native AV media: '.$this->diagnostics());
        $this->checkLogs(); return $written;
    }
    public function eof():bool { return $this->socket===null || feof($this->socket); }
    public function running():bool
    {
        if($this->process===null) return false;
        $this->checkLogs(); $status=proc_get_status($this->process);
        if(!$status['running'] && $status['exitcode']>=0) $this->exit??=$status['exitcode'];
        return $status['running'];
    }
    public function assertSuccessful():void
    {
        if($this->running()) throw new \LogicException('AV process has not finished');
        if($this->exit!==0) throw new \RuntimeException('AV process failed ('.($this->exit??-1).'): '.$this->diagnostics());
    }
    public function finish(float $timeout=5):void
    {
        if(!is_finite($timeout) || $timeout<=0 || $timeout>30) throw new \InvalidArgumentException('Invalid AV finish timeout');
        if($this->socket!==null) { stream_socket_shutdown($this->socket,STREAM_SHUT_WR); fclose($this->socket); $this->socket=null; }
        $deadline=microtime(true)+$timeout;
        try {
            while($this->running()) { if(microtime(true)>=$deadline) throw new \RuntimeException('AV process did not finish in time'); usleep(1000); }
            $this->assertSuccessful();
        } finally { $this->cancel(); }
    }
    public function diagnostics():string { $this->checkLogs(); return $this->error; }
    public function cancel():void
    {
        if($this->socket!==null) { fclose($this->socket); $this->socket=null; }
        if($this->process!==null) {
            $process=$this->process; $status=proc_get_status($process);
            if($status['running']) {
                proc_terminate($process); $deadline=microtime(true)+0.25;
                do { $status=proc_get_status($process); if(!$status['running']) break; usleep(1000); } while(microtime(true)<$deadline);
                if($status['running']) proc_terminate($process,9);
            }
            if($status['exitcode']>=0) $this->exit??=$status['exitcode'];
            $this->process=null; proc_close($process);
        }
        $this->cleanup();
    }
    private function checkLogs():void
    {
        foreach([$this->stdoutPath,$this->stderrPath] as $path) if(is_string($path) && is_file($path)) { clearstatcache(true,$path); if(filesize($path)>1048576) { $this->cancel(); throw new \LengthException('AV diagnostic output exceeds 1 MiB'); } }
        if($this->stderrPath!==null && is_file($this->stderrPath)) { $bytes=file_get_contents($this->stderrPath,false,null,0,16384); if($bytes!==false) $this->error=$bytes; }
    }
    private function cleanup():void { foreach([$this->stdoutPath,$this->stderrPath] as $path) if(is_string($path) && is_file($path)) @unlink($path); $this->stdoutPath=null; $this->stderrPath=null; }
    public function __destruct() { $this->cancel(); }
}
