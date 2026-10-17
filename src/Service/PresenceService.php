<?php
declare(strict_types=1);
namespace MVO\Service;
use MVO\Protocol\BinaryNode;

final class PresenceService
{
    public function __construct(private readonly ServiceTransport $transport) {}
    public function send(?string $type = null): void
    {
        if ($type !== null && !in_array($type,['available','unavailable'],true)) throw new \InvalidArgumentException('Invalid presence');
        $attrs=[]; if ($type !== null) $attrs['type']=$type;
        $name=$this->transport->credentials()['meDisplayName'] ?? null; if (is_string($name) && $name !== '') $attrs['name']=$name;
        $this->transport->send(new BinaryNode('presence',$attrs));
    }
    public function sendChatstate(string $jid,string $state='composing',?string $media=null): void
    {
        if ($state === 'recording') {
            if ($media !== null && $media !== 'audio') throw new \InvalidArgumentException('Recording requires audio media');
            $state = 'composing'; $media = 'audio';
        }
        self::target($jid); if (!in_array($state,['composing','paused'],true)) throw new \InvalidArgumentException('Invalid chatstate');
        if ($media !== null && ($state !== 'composing' || $media !== 'audio')) throw new \InvalidArgumentException('Media requires composing audio');
        $this->transport->send(new BinaryNode('chatstate',['to'=>$jid],[new BinaryNode($state,$media === null ? [] : ['media'=>$media])]));
    }
    public function subscribe(string $jid,?string $name=null,?string $context=null,?BinaryNode $privacyToken=null): void
    {
        self::target($jid); if ($privacyToken !== null && $privacyToken->tag !== 'tctoken') throw new \InvalidArgumentException('Expected a tctoken');
        $attrs=['type'=>'subscribe','to'=>$jid]; if ($name !== null) $attrs['name']=$name; if ($context !== null) $attrs['context']=$context;
        $this->transport->send(new BinaryNode('presence',$attrs,$privacyToken === null ? null : [$privacyToken]));
    }
    private static function target(string $jid): void
    {
        ServiceTransport::jid($jid); if (preg_match('/@(broadcast|newsletter)$/D',$jid)) throw new \InvalidArgumentException('Presence is unavailable for broadcasts/newsletters');
    }
}
