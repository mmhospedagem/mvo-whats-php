<?php
declare(strict_types=1);
namespace MVO\Voip;

interface AudioDecoder
{
    /** @return array{outcome:string,frames:list<list<float>>} */
    public function decodeSequenced(int $sequence,string $packet,?int $timestamp=null,int $stream=0):array;
    /** @return array<string,int> */
    public function stats():array;
    public function resetSequence():void;
    public function close():void;
}
