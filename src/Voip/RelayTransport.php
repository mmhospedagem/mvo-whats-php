<?php
declare(strict_types=1);
namespace MVO\Voip;

interface RelayTransport
{
    /** @param list<int> $selfSsrcs
     * @param list<int> $peerSsrcs */
    public function connect(array $selfSsrcs,array $peerSsrcs=[]):void;
    public function send(string $packet):void;
    public function receive(float $timeout=0.0):?string;
    public function tick():void;
    public function close():void;
    public function reregister():void;
    /** @param list<int> $selfSsrcs
     * @param list<int> $peerSsrcs */
    public function updateSubscriptions(array $selfSsrcs,array $peerSsrcs,?string $streamDescriptor=null,?string $rxSubscriptions=null):void;
    /** @return array{open:bool,allocated:bool,lastPongAt:float,openedAt:float,rttMs:?int} */
    public function metrics():array;
}
