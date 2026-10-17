<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Console\CommandQueue;

final class CommandQueueTest extends TestCase
{
    private string $path;
    protected function setUp():void { $this->path=sys_get_temp_dir().'/mvo-command-'.bin2hex(random_bytes(8)); }
    protected function tearDown():void { foreach(glob($this->path.'/*') ?: [] as $file) unlink($file); if(is_dir($this->path))rmdir($this->path); }
    public function testWorkerUsesExistingConnectionAndDoesNotSubmitTwice():void {
        $producer=new CommandQueue($this->path); $worker=new CommandQueue($this->path); $id=$producer->submit('5511999999999','Olá'); $sent=[];
        self::assertSame('pending',$producer->status($id));
        $send=static function(string $to,string $text)use(&$sent):array{$sent[]=[$to,$text];return ['id'=>'wire-id','status'=>'server_ack'];};
        self::assertSame(1,$worker->process($send)); self::assertSame(0,$worker->process($send)); self::assertSame([['5511999999999','Olá']],$sent);
        self::assertSame('server_ack',$producer->result($id)['result']['status']); self::assertSame('completed',$producer->status($id));
    }
    public function testExpiredAndFailedCommandsAreNotResubmitted():void {
        $queue=new CommandQueue($this->path); $id=$queue->submit('5511999999999','test');
        $file=$this->path.'/'.$id.'.request.json'; $data=json_decode(file_get_contents($file),true); $data['expiresAt']=time()-1; file_put_contents($file,json_encode($data));
        $queue->process(static function():array{throw new \LogicException('Should not send expired command');});
        self::assertSame('failed',$queue->status($id)); self::assertStringContainsString('expired',$queue->result($id)['message']);
        $failed=$queue->submit('5511999999999','unknown'); $queue->process(static function():array{throw new \RuntimeException('ACK timeout: do not automatically repeat');});
        self::assertSame('failed',$queue->status($failed)); self::assertSame(0,$queue->process(static fn()=>[]));
    }
    public function testInterruptedProcessingIsNeverReplayedAfterRestart():void {
        $queue=new CommandQueue($this->path); $id=$queue->submit('5511999999999','uncertain');
        rename($this->path.'/'.$id.'.request.json',$this->path.'/'.$id.'.processing.json');
        $restarted=new CommandQueue($this->path); self::assertSame(0,$restarted->process(static fn()=>[])); self::assertSame('processing_unknown_after_restart',$restarted->status($id));
    }
    public function testStructuredPayloadPreservesBinaryBytesAndCoordinates():void {
        $queue=new CommandQueue($this->path);
        $content=['type'=>'raw','message'=>['locationMessage'=>['degreesLatitude'=>-23.55,'degreesLongitude'=>-46.63,'jpegThumbnail'=>"\0\xff\x80"]]];
        $id=$queue->submit('123456789@g.us',$content); $seen=null;
        $queue->process(static function(string $to,string|array $payload)use(&$seen):array { $seen=[$to,$payload]; return ['status'=>'server_ack']; });
        self::assertSame(['123456789@g.us',$content],$seen);
        self::assertSame('completed',$queue->status($id));
    }
}
