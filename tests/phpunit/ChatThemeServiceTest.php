<?php
declare(strict_types=1);
namespace MVO\Tests;
use MVO\Service\ChatThemeService;
use PHPUnit\Framework\TestCase;
final class ChatThemeServiceTest extends TestCase
{
    public function testSetAndResetUseNormalEncryptedSenderAndReturnItsOutcome():void
    {
        $calls=[];$service=new ChatThemeService(static function(string $jid,array $content)use(&$calls):array{$calls[]=[$jid,$content];return ['id'=>'ACK','acknowledged'=>true];});
        self::assertSame('ACK',$service->set('u@lid',['wallpaper'=>['type'=>'default','isDoodleEnabled'=>false]])['id']);
        self::assertSame('u@lid',$calls[0][0]);self::assertSame('chat-theme',$calls[0][1]['type']);self::assertFalse($calls[0][1]['theme']['defaultWallpaper']['isDoodleEnabled']);
        $service->reset('u@lid');self::assertSame('reset-chat-theme',$calls[1][1]['type']);
    }
    public function testInvalidThemeCannotReachSender():void
    {
        $service=new ChatThemeService(static function():array{self::fail('Invalid input reached sender');});
        $this->expectException(\InvalidArgumentException::class);$service->set('u@lid',[]);
    }
}
