<?php
declare(strict_types=1);
namespace MVO\Channels\Messenger;

use MVO\Channels\{Capabilities,PersistentClient};
use MVO\Messenger\MessengerClient;

/** Adapter is optional; the Messenger protocol package does not require the multichannel API. */
final class MessengerChannelAdapter implements PersistentClient
{
    public function __construct(private readonly MessengerClient $client) {}
    public function channel():string{return $this->client->channel();}
    public function provider():string{return $this->client->provider();}
    public function capabilities():Capabilities{return new Capabilities($this->client->capabilities());}
    public function accountInfo():array{return $this->client->accountInfo();}
    public function on(string $event,callable $handler):static{$this->client->on($event,$handler);return $this;}
    public function sendText(string $to,string $text):array{return $this->client->sendText($to,$text);}
    public function connect():void{$this->client->connect();}
    public function poll(float $timeoutSeconds=.5):void{$this->client->poll($timeoutSeconds);}
    public function stop():void{$this->client->stop();}
    public function isConnected():bool{return $this->client->isConnected();}
    public function nativeClient():MessengerClient{return $this->client;}
}
