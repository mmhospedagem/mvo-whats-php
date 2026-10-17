<?php
declare(strict_types=1);
namespace MVO\Voip;

/** A datagram-oriented, authenticated ICE/DTLS/SCTP channel. */
interface DataChannel
{
    public function createOffer():void;
    public function localDescription(bool $waitForCandidates=false,float $timeout=5.0):string;
    public function setRemoteDescription(string $sdp,string $type='answer'):void;
    public function isOpen():bool;
    public function state():string;
    public function send(string $bytes):void;
    public function receive(float $timeout=0.0):?string;
    public function close():void;
}
