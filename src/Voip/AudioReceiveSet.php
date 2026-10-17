<?php
declare(strict_types=1);
namespace MVO\Voip;

/** PCM receive/mix engine. Authentication and payload-type classification precede receive(). */
final class AudioReceiveSet
{
    /** @var array<string,AudioReceiveStream> */
    private array $senders=[];
    /** @var array<int,AudioReceiveStream> */
    private array $bySsrc=[];
    private readonly \Closure $factory;
    private readonly ?\Closure $onAudio;
    private int $mixUnderruns=0;
    private int $generation=0;
    /**
     * @param callable(string):AudioDecoder $createDecoder
     * @param callable(string,list<float>):void|null $onSenderAudio
     */
    public function __construct(callable $createDecoder,private readonly int $clockRate=16000,private readonly int $videoClockRate=90000,private readonly int $playoutCapacity=16000,private readonly int $maxSenders=64,?callable $onSenderAudio=null)
    {
        if($clockRate<=0 || $videoClockRate<=0 || $playoutCapacity<=0 || $playoutCapacity>1048576 || $maxSenders<=0 || $maxSenders>64) throw new \InvalidArgumentException('Invalid audio receive set bounds');
        $this->factory=$createDecoder(...); $this->onAudio=$onSenderAudio===null?null:$onSenderAudio(...);
    }
    /** Retained senders keep decoder, sequence and queued PCM; SSRC collisions reject the plan.
     * @param list<array{id:string,audio:int,streams:list<int>}> $specs
     * @return list<string> Senders removed while they were being heard.
     */
    public function sync(array $specs):array
    {
        $wanted=[]; $owners=[];
        foreach($specs as $spec) {
            if(count($wanted)>=$this->maxSenders) break;
            if($spec['id']==='' || isset($wanted[$spec['id']])) throw new \InvalidArgumentException('Duplicate or empty audio sender');
            $wanted[$spec['id']]=$spec;
            foreach([$spec['audio'],...$spec['streams']] as $ssrc) {
                if($ssrc<0 || $ssrc>0xffffffff || (isset($owners[$ssrc]) && $owners[$ssrc]!==$spec['id'])) throw new \InvalidArgumentException('Invalid or conflicting audio sender SSRC');
                $owners[$ssrc]=$spec['id'];
            }
        }
        $created=[];
        try { foreach($wanted as $id=>$spec) if(!isset($this->senders[$id])) $created[$id]=new AudioReceiveStream($id,$spec['audio'],($this->factory)($id),$this->clockRate,$this->videoClockRate,$this->playoutCapacity); }
        catch(\Throwable $error) { foreach($created as $sender) $sender->close(); throw $error; }
        $removed=[];
        foreach($this->senders as $id=>$sender) if(!isset($wanted[$id])) { if($sender->receiving) $removed[]=$id; $sender->close(); unset($this->senders[$id]); }
        foreach($created as $id=>$sender) $this->senders[$id]=$sender;
        foreach($wanted as $id=>$spec) {
            $sender=$this->senders[$id]; $sender->audioSsrc=$spec['audio'];
            foreach([$sender->audioReception,$sender->videoReception] as $tracker) if($tracker->sourceSsrc()!==0 && ($owners[$tracker->sourceSsrc()]??null)!==$id) $tracker->reset();
        }
        $this->bySsrc=[]; foreach($owners as $ssrc=>$id) $this->bySsrc[$ssrc]=$this->senders[$id]; ++$this->generation;
        return $removed;
    }
    public function size():int { return count($this->senders); }
    public function has(int $ssrc):bool { return isset($this->bySsrc[$ssrc]); }
    public function senderIdOf(int $ssrc):?string { return ($this->bySsrc[$ssrc]??null)?->id; }
    public function noteHeard(int $ssrc,float $nowMs):?string
    {
        $sender=$this->bySsrc[$ssrc]??null; if($sender===null) return null;
        $sender->lastHeardMs=$nowMs; if($sender->receiving) return null;
        $sender->receiving=true; return $sender->id;
    }
    /** @return list<string> */
    public function takeSilent(float $nowMs,float $silenceMs):array
    {
        if($silenceMs<0) throw new \InvalidArgumentException('Invalid audio silence interval');
        $silent=[]; foreach($this->senders as $sender) if($sender->receiving && $nowMs-$sender->lastHeardMs>=$silenceMs) { $sender->receiving=false; $silent[]=$sender->id; }
        return $silent;
    }
    /** @return array{outcome:string,frames:list<list<float>>}|null */
    public function receive(int $ssrc,int $sequence,int $timestamp,string $payload,int $stream,float $arrivalMs):?array
    {
        if(!in_array($stream,[0,1],true)) throw new \InvalidArgumentException('Audio stream must be main=0 or RED=1');
        $sender=$this->bySsrc[$ssrc]??null; if($sender===null) return null;
        ++$sender->received;
        $followed=$sender->audioReception->sourceSsrc();
        if($stream===0 || $followed===0 || $followed===$ssrc) $sender->audioReception->observe($ssrc,$sequence,$timestamp,$arrivalMs);
        $outcome=$sender->decoder->decodeSequenced($sequence,$payload,$timestamp,$stream);
        $generation=$this->generation;
        foreach($outcome['frames'] as $pcm) {
            $sender->playout->write($pcm); if($this->onAudio!==null) ($this->onAudio)($sender->id,$pcm);
            if($this->generation!==$generation || ($this->senders[$sender->id]??null)!==$sender || ($this->bySsrc[$ssrc]??null)!==$sender) break;
        }
        return $outcome;
    }
    public function countPt122(int $ssrc,?string $outcome):void
    {
        $sender=$this->bySsrc[$ssrc]??null; if($sender===null) return;
        ++$sender->pt122Received; if($outcome==='decoded') ++$sender->pt122Decoded; elseif($outcome==='rejected') ++$sender->pt122Rejected;
    }
    public function noteAuthFailure(int $ssrc):bool { $sender=$this->bySsrc[$ssrc]??null; if($sender===null) return false; ++$sender->authFailures; return true; }
    public function observeVideo(int $ssrc,int $sequence,int $timestamp,float $arrivalMs):void
    {
        $sender=$this->bySsrc[$ssrc]??null; if($sender===null) return;
        $source=$sender->videoReception->sourceSsrc(); if($source===0 || $source===$ssrc) $sender->videoReception->observe($ssrc,$sequence,$timestamp,$arrivalMs);
    }
    public function observeSenderReport(string $packet,float $arrivalMs):void
    {
        $report=Rtcp::parse($packet)[0]; if($report['type']!==200) return;
        $sender=$this->bySsrc[$report['senderSsrc']]??null; if($sender===null) return;
        $sender->audioReception->observeSenderReport($packet,$arrivalMs); $sender->videoReception->observeSenderReport($packet,$arrivalMs);
    }
    /** @return list<array<string,int>> */
    public function receptionReports(float $nowMs,bool $video=false):array
    {
        $reports=[]; foreach($this->senders as $sender) { $tracker=$video?$sender->videoReception:$sender->audioReception; if($tracker->sourceSsrc()!==0) $reports[]=$tracker->report($nowMs); }
        return $reports;
    }
    /** @return list<int> */
    public function videoSourceSsrcs():array { $sources=[]; foreach($this->senders as $sender) if($sender->videoReception->sourceSsrc()!==0) $sources[]=$sender->videoReception->sourceSsrc(); return $sources; }
    /** Sources only; leaves the report's loss interval unconsumed.
     * @return list<int>
     */
    public function audioSourceSsrcs():array { $sources=[]; foreach($this->senders as $sender) if($sender->audioReception->sourceSsrc()!==0) $sources[]=$sender->audioReception->sourceSsrc(); return $sources; }
    public function takeLossPercent(int $ssrc):float { return ($this->bySsrc[$ssrc]??null)?->audioReception->takeLossPercent()??0; }
    public function peakLossPercent(bool $video=false):float { $loss=0.0; foreach($this->senders as $sender) $loss=max($loss,($video?$sender->videoReception:$sender->audioReception)->lossPercent()); return $loss; }
    /** @return array{samples:list<float>,real:int} */
    public function pullPlayout(int $samples):array
    {
        if($samples<0 || $samples>1048576) throw new \InvalidArgumentException('Invalid mix block size');
        $out=array_fill(0,$samples,0.0); $real=0;
        foreach($this->senders as $sender) {
            if($sender->playout->buffered()===0) continue;
            $block=$sender->playout->read($samples); $real=max($real,$block['real']);
            for($i=0;$i<$block['real'];$i++) $out[$i]+=$block['samples'][$i];
        }
        for($i=0;$i<$real;$i++) $out[$i]=max(-1.0,min(1.0,$out[$i]));
        if($real<$samples) ++$this->mixUnderruns;
        return ['samples'=>$out,'real'=>$real];
    }
    /** @return array{buffered:int,capacity:int,dropped:int,underruns:int} */
    public function playoutStats():array
    {
        $buffered=0; $dropped=0; foreach($this->senders as $sender) { $stats=$sender->playout->stats(); $buffered=max($buffered,$stats['buffered']); $dropped+=$stats['dropped']; }
        return ['buffered'=>$buffered,'capacity'=>$this->playoutCapacity,'dropped'=>$dropped,'underruns'=>$this->mixUnderruns];
    }
    /** @return list<array<string,int|float|string>> */
    public function stats():array
    {
        $stats=[]; foreach($this->senders as $sender) $stats[]=['id'=>$sender->id,'recv'=>$sender->received,...$sender->decoder->stats(),'lossPercent'=>$sender->audioReception->lossPercent(),'authFailures'=>$sender->authFailures,'pt122Received'=>$sender->pt122Received,'pt122Decoded'=>$sender->pt122Decoded,'pt122Rejected'=>$sender->pt122Rejected];
        return $stats;
    }
    public function resetSequences():void { ++$this->generation; foreach($this->senders as $sender) { $sender->decoder->resetSequence(); $sender->audioReception->reset(); $sender->videoReception->reset(); } }
    public function resetPlayout():void { foreach($this->senders as $sender) $sender->playout->reset(); }
    public function clear():void { ++$this->generation; foreach($this->senders as $sender) $sender->close(); $this->senders=[]; $this->bySsrc=[]; }
}
