<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Message\ModernContent;

/** Applies the current chat theme protocol through the normal encrypted sender. */
final class ChatThemeService
{
    /** @var \Closure(string,array<string,mixed>):array<string,mixed> */
    private readonly \Closure $sender;
    /** @param callable(string,array<string,mixed>):array<string,mixed> $sender */
    public function __construct(callable $sender){$this->sender=\Closure::fromCallable($sender);}
    /**
     * @param array<string,mixed> $theme
     * @return array<string,mixed> */
    public function set(string $jid,array $theme):array
    {
        if($jid==='')throw new \InvalidArgumentException('Chat recipient is required');
        $theme=ModernContent::theme($theme,(int)floor(microtime(true)*1000));
        ModernContent::build(['type'=>'chat-theme','theme'=>$theme],$jid,[],0);
        return ($this->sender)($jid,['type'=>'chat-theme','theme'=>$theme]);
    }
    /**
     * @return array<string,mixed> */
    public function reset(string $jid):array
    {if($jid==='')throw new \InvalidArgumentException('Chat recipient is required');ModernContent::build(['type'=>'reset-chat-theme'],$jid,[],0);return ($this->sender)($jid,['type'=>'reset-chat-theme']);}
}
