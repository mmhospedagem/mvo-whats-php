<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Codec/packet/feedback plane over authenticated MediaSession transport callbacks. */
final class CallMediaEngine
{
    private readonly \Closure $protect;
    private readonly ?\Closure $protectVideo;
    private readonly \Closure $send;
    private readonly \Closure $sendControl;
    private readonly ?\Closure $emit;
    private readonly MediaClock $clock;
    private readonly AudioReceiveSet $audio;
    private SenderReportSchedule $reports;
    private SenderReportSchedule $audioReports;
    private SenderReportSchedule $videoReportsSchedule;
    private readonly ?\Closure $reportRandom;
    private readonly \Closure $unixMs;
    /** @var array<string,array{packet:string,at:float,resends:int}> */
    private array $retransmit=[];
    /** @var array<int,array{depacketizer:H264Depacketizer,reception:RtpReception,owner:?string,generation:int,keyFrameReceived:bool,last:?int,lastPli:float,lastNack:float,next:?int,gapSince:?float,pending:array<int,array{payload:string,timestamp:int,marker:bool}>,pendingBytes:int}> */
    private array $video=[];
    /** @var array<int,array{ssrc:int,packets:int,octets:int,timestamp:int,captureMs:float,clockRate:int}> */
    private array $outbound=[];
    /** @var list<int> */
    private array $autoStreams=[];
    private int $autoAudio=0;
    private bool $autoLearn=true;
    private bool $audioEnabled=true;
    private bool $videoEnabled=true;
    private bool $videoStreamOpen=false;
    private ?int $lastVideoTimestamp=null;
    private int $videoFrameNumber=0;
    private int $videoTransportSequence=0;
    private int $videoGeneration=0;
    private int $videoFirSequence=0;
    private float $lastKeyframeRequest=-INF;
    private ?int $audioRembSsrc=null;
    private bool $audioRedEnabled=false;
    private string $audioRedMode='compact';
    private int $audioFramesPerPacket=1;
    private ?int $requiredAudioSamples=null;
    private ?int $nextAudioTimestamp=null;
    private bool $audioGap=false;
    /** @var array{packet:string,timestamp:int,captureMs:float}|null */
    private ?array $previousSecondary=null;
    /** @var array{packet:string,timestamp:int,captureMs:float,marker:bool}|null */
    private ?array $pendingAudio=null;
    private bool $closed=false;
    private int $videoBitrate=0;
    private int $audioBitrate=0;
    private float $lastEstimate=0;
    private int $videoOctets=0;
    /** @var array<string,int> */
    private array $counts=['audioSent'=>0,'audioReceived'=>0,'audioRedDiscarded'=>0,'audioFramesShed'=>0,'audioTimelineResyncs'=>0,'audioPendingDropped'=>0,'videoSent'=>0,'videoReceived'=>0,'videoFrames'=>0,'videoStreamsEvicted'=>0,'fecDiscarded'=>0,'unknownDiscarded'=>0,'retransmitted'=>0,'retransmitMisses'=>0,'nackSent'=>0,'pliSent'=>0,'firSent'=>0];
    private readonly string $cname;
    private readonly int $cacheLimit;
    private readonly float $cacheMs;
    private readonly int $maxPayload;
    private int $maxVideoStreams;
    private readonly bool $autoVideoStreamBound;
    private readonly bool $whatsappVideoProfile;
    private readonly int $localAudioSsrc;
    private readonly int $localVideoSsrc;
    private readonly int $senderSilenceMs;
    private int $reportIntervalMs;
    private bool $videoRembDisabled=false;
    private bool $appDataSframeRequested=false;

    /**
     * @param callable(string,int,int,int,bool):string $protectPayload Advances SRTP index exactly once.
     * @param callable(string):void $sendRawEncrypted Sends the exact bytes, including retransmissions.
     * @param callable(string):void $sendRtcp Protects/sends a plaintext RTCP compound packet.
     * @param array<string,mixed> $options protectVideoPayload(payload,timestamp,pt,slot,marker,extension,profile), now,unixMs,emit,createDecoder,clock,cacheLimit,cacheMs,maxPayload,maxVideoStreams,maxAudioSenders,videoProfile,reportIntervalMs,videoRembDisabled,senderSilenceMs.
     */
    public function __construct(private readonly OpusCodec $codec,callable $protectPayload,callable $sendRawEncrypted,callable $sendRtcp,array $options=[])
    {
        if($codec->channels!==1) throw new \InvalidArgumentException('WhatsApp call audio is mono');
        $this->protect=$protectPayload(...); $this->send=$sendRawEncrypted(...); $this->sendControl=$sendRtcp(...);
        $videoProtector=$options['protectVideoPayload']??null; if($videoProtector!==null && !is_callable($videoProtector)) throw new \InvalidArgumentException('Video media protector must be callable'); $this->protectVideo=$videoProtector===null?null:$videoProtector(...);
        $emit=$options['emit']??null; if($emit!==null && !is_callable($emit)) throw new \InvalidArgumentException('Media emit must be callable'); $this->emit=$emit===null?null:$emit(...);
        $clock=$options['clock']??null; if($clock!==null && !$clock instanceof MediaClock) throw new \InvalidArgumentException('Media clock must be MediaClock');
        $now=$options['now']??null; if($now!==null && !is_callable($now)) throw new \InvalidArgumentException('Media time source must be callable');
        $this->clock=$clock??new MediaClock($now); $this->clock->start();
        $this->cacheLimit=self::positiveInt($options['cacheLimit']??512,4096,'retransmit cache limit');
        $this->cacheMs=self::positiveInt($options['cacheMs']??2000,30000,'retransmit cache age');
        $this->maxPayload=self::positiveInt($options['maxPayload']??800,65000,'RTP payload bound'); if($this->maxPayload<3) throw new \InvalidArgumentException('RTP payload bound must be at least 3');
        $this->autoVideoStreamBound=!array_key_exists('maxVideoStreams',$options);
        $this->maxVideoStreams=self::positiveInt($options['maxVideoStreams']??8,128,'video stream bound');
        $this->whatsappVideoProfile=($options['videoProfile']??true)===true;
        $this->localAudioSsrc=self::ssrcOption($options['localAudioSsrc']??0);
        $this->localVideoSsrc=self::ssrcOption($options['localVideoSsrc']??0);
        $this->reportIntervalMs=self::positiveInt($options['reportIntervalMs']??1000,60000,'RTCP interval');
        $random=$options['random']??null; if($random!==null && !is_callable($random)) throw new \InvalidArgumentException('RTCP random source must be callable'); $this->reportRandom=$random===null?null:$random(...);
        $this->reports=SenderReportSchedule::onWallClock($this->reportIntervalMs,$this->reportRandom);
        $this->audioReports=SenderReportSchedule::onMediaClock($this->reportIntervalMs,$codec->sampleRate,$this->reportRandom);
        $this->videoReportsSchedule=SenderReportSchedule::onWallClock($this->reportIntervalMs,$this->reportRandom);
        $wallClock=$options['unixMs']??static fn():float=>microtime(true)*1000; if(!is_callable($wallClock)) throw new \InvalidArgumentException('RTCP wall clock must be callable'); $this->unixMs=$wallClock(...);
        $this->videoRembDisabled=($options['videoRembDisabled']??false)===true;
        $this->senderSilenceMs=self::positiveInt($options['senderSilenceMs']??20000,600000,'sender silence interval');
        $factory=$options['createDecoder']??fn(string $id):AudioDecoder=>$this->codec->newDecoder();
        if(!is_callable($factory)) throw new \InvalidArgumentException('Audio decoder factory must be callable');
        $maxSenders=self::positiveInt($options['maxAudioSenders']??64,64,'audio sender bound');
        $this->audio=new AudioReceiveSet($factory,$codec->sampleRate,90000,$codec->sampleRate*2,$maxSenders,function(string $id,array $pcm):void { $this->event('voip_audio_frame',['senderId'=>$id,'pcm'=>$pcm]); });
        $this->cname=random_bytes(18); $this->lastEstimate=$this->clock->now();
        $redMode=$options['audioRedMode']??'compact'; if(!in_array($redMode,['compact','copy-first'],true)) throw new \InvalidArgumentException('Unknown audio RED mode'); $this->audioRedMode=$redMode;
        if(($options['audioRed']??false)===true) $this->setAudioRedEnabled(true);
        if(isset($options['voipSettings'])) { if(!is_array($options['voipSettings'])) throw new \InvalidArgumentException('VoIP settings must be an array'); $this->applyVoipSettings($options['voipSettings']); }
    }

    /** @param list<array{id:string,audio:int,streams:list<int>}> $specs */
    public function configureSenders(array $specs):void
    {
        $this->active(); $removed=$this->audio->sync($specs); $this->autoLearn=false; $this->autoStreams=[];
        foreach(array_keys($this->video) as $ssrc) if(!$this->audio->has($ssrc) || $this->video[$ssrc]['owner']!==$this->audio->senderIdOf($ssrc)) unset($this->video[$ssrc]);
        if($this->autoVideoStreamBound) $this->maxVideoStreams=max(8,$this->audio->size()*2);
        while(count($this->video)>$this->maxVideoStreams) { unset($this->video[array_key_first($this->video)]); ++$this->counts['videoStreamsEvicted']; }
        if($this->audioRembSsrc!==null && !$this->audio->has($this->audioRembSsrc)) $this->audioRembSsrc=null;
        foreach($removed as $id) { $this->event('voip_sender_media',['senderId'=>$id,'receiving'=>false,'reason'=>'removed']); if($this->closed) return; }
    }
    /** Clear authenticated media state when the transport installs new SRTP contexts. */
    public function resetReceiveState():void { $this->active(); $this->audio->resetSequences(); $this->audio->resetPlayout(); $this->video=[]; $this->retransmit=[]; $this->previousSecondary=null; $this->audioRembSsrc=null; if($this->pendingAudio!==null) ++$this->counts['audioPendingDropped']; $this->pendingAudio=null; $this->audioGap=true; }
    public function setAudioEnabled(bool $enabled):void { $this->active(); $this->audioEnabled=$enabled; if(!$enabled) { if($this->pendingAudio!==null) ++$this->counts['audioPendingDropped']; $this->pendingAudio=null; $this->previousSecondary=null; $this->audioGap=true; } }
    public function setVideoEnabled(bool $enabled):void { $this->active(); $this->videoEnabled=$enabled; if(!$enabled) $this->videoStreamOpen=false; }
    /** Server RTCP policy. Disabling video REMB keeps estimates in the RTP extension. */
    public function configureRtcp(?int $intervalMs=null,bool $videoRembDisabled=false):void
    {
        $this->active(); $interval=self::positiveInt($intervalMs??1000,60000,'RTCP interval'); $this->videoRembDisabled=$videoRembDisabled;
        if($interval!==$this->reportIntervalMs) {
            $this->reportIntervalMs=$interval; $this->reports=SenderReportSchedule::onWallClock($interval,$this->reportRandom);
            $this->audioReports=SenderReportSchedule::onMediaClock($interval,$this->codec->sampleRate,$this->reportRandom);
            $this->videoReportsSchedule=SenderReportSchedule::onWallClock($interval,$this->reportRandom); $this->lastEstimate=$this->clock->now();
        }
    }
    /** Applies normalized server media settings without sending network packets.
     * @param array<string,mixed> $settings
     */
    public function applyVoipSettings(array $settings):void
    {
        $this->active();
        $sendSframe=$settings['sframe']??false; $receiveSframe=$settings['sframeRx']??false;
        if(!is_bool($sendSframe) || !is_bool($receiveSframe)) throw new \InvalidArgumentException('App-data SFrame gates must be boolean');
        $interval=self::positiveInt($settings['rtcpIntervalMs']??1000,60000,'RTCP interval');
        $gate=$settings['disableRtcpRemb']??false; if(!is_bool($gate)) throw new \InvalidArgumentException('Video REMB policy must be boolean');
        $frameMs=$settings['audioFrameMs']??null;
        if($frameMs!==null && (!is_int($frameMs) || !in_array($frameMs,$this->codec instanceof MLowCodec?[60]:[5,10,20,40,60],true))) throw new \LogicException('Negotiated audio frame length is not supported by the native codec');
        $maxFrames=self::positiveInt($settings['audioMaxFramesPerPacket']??1,65535,'audio max frames per packet');
        $frames=$this->codec instanceof MLowCodec?min(2,$maxFrames):1;
        if($this->audioFramesPerPacket!==$frames || $this->requiredAudioSamples!==($frameMs===null?null:intdiv($this->codec->sampleRate*$frameMs,1000))) {
            if($this->pendingAudio!==null) ++$this->counts['audioPendingDropped'];
            $this->pendingAudio=null; $this->previousSecondary=null;
        }
        // The paired gates concern app-data only; RTP audio/video keep their SRTP contexts.
        $this->appDataSframeRequested=$sendSframe && $receiveSframe;
        $this->audioFramesPerPacket=$frames; $this->requiredAudioSamples=$frameMs===null?null:intdiv($this->codec->sampleRate*$frameMs,1000);
        $this->configureRtcp($interval,$gate);
    }
    /** Enable only after peer negotiation permits mlow-red-1/PT121. */
    public function setAudioRedEnabled(bool $enabled):void
    {
        $this->active();
        if($enabled) { if(!$this->codec instanceof MLowCodec) throw new \LogicException('Compact RED requires native MLow codec'); $this->codec->enableSecondary(); }
        $this->audioRedEnabled=$enabled; $this->previousSecondary=null; if($this->pendingAudio!==null) ++$this->counts['audioPendingDropped']; $this->pendingAudio=null;
    }

    /**
     * @param list<float|int> $samples
     * @return list<string> Ciphertext sent.
     */
    public function sendAudioPcm(array $samples,?float $captureMs=null):array
    {
        $this->active(); if(!$this->audioEnabled) return [];
        if($this->requiredAudioSamples!==null && count($samples)!==$this->requiredAudioSamples) throw new \InvalidArgumentException('PCM frame length differs from negotiated audio settings');
        $captureMs??=$this->clock->now(); $timestamp=$this->clock->ticksAt($captureMs,$this->codec->sampleRate); $sampleCount=count($samples);
        if($this->nextAudioTimestamp!==null) {
            $drift=($timestamp-$this->nextAudioTimestamp)&0xffffffff; if($drift>=0x80000000) $drift-=0x100000000;
            $slack=$this->codec->sampleRate*0.12;
            if($drift<=-$slack) { ++$this->counts['audioFramesShed']; return []; }
            if($drift<$slack) $timestamp=$this->nextAudioTimestamp; else ++$this->counts['audioTimelineResyncs'];
        }
        $marker=$this->audioGap || $this->nextAudioTimestamp===null || $timestamp!==$this->nextAudioTimestamp;
        $encoded=$this->codec->encode($samples); $this->nextAudioTimestamp=($timestamp+$sampleCount)&0xffffffff; $this->audioGap=false; $pt=120;
        if($this->audioRedEnabled && $this->codec instanceof MLowCodec) {
            $currentTimestamp=$timestamp; $currentCaptureMs=$captureMs;
            $secondary=$this->codec->encodeSecondary(); $copy=$marker?null:$this->previousSecondary;
            if($this->audioRedMode==='copy-first') {
                if($copy!==null) { $encoded=$this->codec->packFrames([$copy['packet'],$encoded]); $timestamp=$copy['timestamp']; $captureMs=$copy['captureMs']; $marker=false; }
            } else { $encoded=MLowPacket::writeRed($encoded,$timestamp,$copy===null?[]:[['packet'=>$copy['packet'],'timestamp'=>$copy['timestamp']]]); $pt=121; }
            $this->previousSecondary=['packet'=>$secondary,'timestamp'=>$currentTimestamp,'captureMs'=>$currentCaptureMs];
        } elseif($this->audioFramesPerPacket>1 && $this->codec instanceof MLowCodec) {
            $pending=$this->pendingAudio; $speech=(ord($encoded[0])&0x40)!==0; $sent=[];
            if($pending!==null && $speech && !$marker) { $encoded=$this->codec->packFrames([$pending['packet'],$encoded]); $timestamp=$pending['timestamp']; $captureMs=$pending['captureMs']; $marker=$pending['marker']; $this->pendingAudio=null; }
            else {
                if($pending!==null) { $this->pendingAudio=null; $sent[]=$this->sendAudioPacket($pending['packet'],$pending['timestamp'],$pending['marker'],$pending['captureMs'],120); }
                if($speech) { $this->pendingAudio=['packet'=>$encoded,'timestamp'=>$timestamp,'captureMs'=>$captureMs,'marker'=>$marker]; return $sent; }
            }
            $sent[]=$this->sendAudioPacket($encoded,$timestamp,$marker,$captureMs,$pt); return $sent;
        }
        return [$this->sendAudioPacket($encoded,$timestamp,$marker,$captureMs,$pt)];
    }

    /** timestamp is an explicit 90 kHz RTP timestamp; absent uses the shared media clock.
     * @return list<string> Ciphertext sent.
     */
    public function sendVideoAnnexB(string $frame,?int $timestamp=null):array
    {
        $this->active(); if(!$this->videoEnabled) return [];
        $packetized=H264::packetize($frame,$this->maxPayload); $payloads=$packetized['payloads']; $keyFrame=$packetized['keyFrame'];
        if($payloads===[] || (!$this->videoStreamOpen && !$keyFrame)) return [];
        $now=$this->clock->now(); $timestamp??=$this->clock->ticksAt($now,90000);
        if($timestamp<0 || $timestamp>0xffffffff) throw new \InvalidArgumentException('Video timestamp must be uint32');
        if($this->lastVideoTimestamp!==null) { $advance=($timestamp-$this->lastVideoTimestamp)&0xffffffff; if($advance===0 || $advance>=0x80000000) $timestamp=($this->lastVideoTimestamp+1)&0xffffffff; }
        $this->lastVideoTimestamp=$timestamp; $sent=[];
        try {
            foreach($payloads as $i=>$payload) {
                $extension=Rtp::videoExtension($keyFrame,$i===0,$this->videoFrameNumber,$this->videoTransportSequence,$i===0?($this->videoBitrate>0?$this->videoBitrate:300000):0);
                $this->videoTransportSequence=($this->videoTransportSequence+1)&65535;
                $sent[]=$this->transmit($payload,$timestamp,97,2,$i===count($payloads)-1,$extension,$now); ++$this->counts['videoSent'];
                if($this->closed || !$this->videoEnabled) return $sent;
            }
            $this->videoStreamOpen=true;
            if($this->videoReportsSchedule->shouldReport(((int)$now)&0xffffffff)) $this->sendSourceReport(2,$this->clock->now());
        } catch(\Throwable $error) { $this->videoStreamOpen=false; throw $error; }
        finally { $this->videoFrameNumber=($this->videoFrameNumber+1)&65535; }
        return $sent;
    }

    /** Only plaintext already authenticated/replay-checked by MediaSession belongs here.
     * @param array<string,mixed> $packet
     */
    public function handlePacket(array $packet):void
    {
        $this->active(); $now=$this->clock->now();
        if(($packet['kind']??null)==='rtcp') {
            if(!is_string($packet['bytes']??null)) throw new \InvalidArgumentException('RTCP media packet needs bytes');
            $this->handleControl($packet['bytes'],$now); return;
        }
        if(($packet['kind']??null)!=='rtp') throw new \InvalidArgumentException('Unknown authenticated media packet kind');
        foreach(['ssrc','sequence','timestamp','payloadType'] as $field) if(!is_int($packet[$field]??null)) throw new \InvalidArgumentException('RTP media packet missing '.$field);
        if(!is_string($packet['payload']??null) || !is_bool($packet['marker']??null)) throw new \InvalidArgumentException('RTP media packet missing payload/marker');
        $ssrc=$packet['ssrc']; $sequence=$packet['sequence']; $timestamp=$packet['timestamp']; $payload=$packet['payload']; $pt=$packet['payloadType'];
        if($ssrc<0 || $ssrc>0xffffffff || $sequence<0 || $sequence>65535 || $timestamp<0 || $timestamp>0xffffffff || strlen($payload)>65535) throw new \InvalidArgumentException('Invalid authenticated RTP fields');
        $kind=MediaPayload::kind($pt);
        if(!$this->autoLearn && !$this->audio->has($ssrc)) { ++$this->counts['unknownDiscarded']; return; }
        if($kind==='audio') {
            if($pt===121 && MLowPacket::readRed($payload)===null) { ++$this->counts['audioRedDiscarded']; return; }
            if($this->autoLearn && !$this->audio->has($ssrc)) {
                if(count($this->autoStreams)>=8) { ++$this->counts['unknownDiscarded']; return; }
                $this->autoStreams[]=$ssrc; if($this->autoAudio===0 || $pt!==121) $this->autoAudio=$ssrc;
                $this->audio->sync([['id'=>'peer','audio'=>$this->autoAudio,'streams'=>$this->autoStreams]]);
            }
            $this->noteSenderHeard($ssrc,$now); if($this->closed) return;
            $result=$this->audio->receive($ssrc,$sequence,$timestamp,$payload,$pt===121?1:0,$now);
            if($result!==null && !$this->isClosed()) {
                ++$this->counts['audioReceived']; $this->audioRembSsrc??=$ssrc;
                if($this->counts['audioReceived']%100===0) $this->codec->setExpectedPacketLossPercent((int)min(100,round($this->audio->peakLossPercent())));
            }
            if($pt===122) $this->audio->countPt122($ssrc,$result['outcome']??null); return;
        }
        $this->noteSenderHeard($ssrc,$now); if($this->closed || (!$this->autoLearn && !$this->audio->has($ssrc))) return;
        if($kind==='reed-solomon-parity') { ++$this->counts['fecDiscarded']; return; }
        if($kind!=='h264') { ++$this->counts['unknownDiscarded']; return; }
        if(!isset($this->video[$ssrc])) {
            if(count($this->video)>=$this->maxVideoStreams) { unset($this->video[array_key_first($this->video)]); ++$this->counts['videoStreamsEvicted']; }
            $this->video[$ssrc]=['depacketizer'=>new H264Depacketizer(),'reception'=>new RtpReception(90000),'owner'=>$this->audio->senderIdOf($ssrc),'generation'=>++$this->videoGeneration,'keyFrameReceived'=>false,'last'=>null,'lastPli'=>-INF,'lastNack'=>-INF,'next'=>null,'gapSince'=>null,'pending'=>[],'pendingBytes'=>0];
        }
        if(!$this->autoLearn) $this->audio->observeVideo($ssrc,$sequence,$timestamp,$now);
        $stream=&$this->video[$ssrc]; $generation=$stream['generation']; $stream['reception']->observe($ssrc,$sequence,$timestamp,$now); ++$this->counts['videoReceived']; $this->videoOctets+=strlen($payload);
        $last=$stream['last'];
        if($last!==null) {
            $delta=($sequence-$last)&65535;
            if($delta>1 && $delta<32768 && $now-$stream['lastNack']>=20) {
                $lost=[]; for($i=1;$i<min($delta,129);$i++) $lost[]=($last+$i)&65535;
                $this->control(Rtcp::nack($this->ownSsrc(),$ssrc,$lost)); ++$this->counts['nackSent']; $stream['lastNack']=$now;
                $this->requestVideoKeyframe($ssrc,$now);
                if($this->closed || !isset($this->video[$ssrc]) || $this->video[$ssrc]['generation']!==$generation) { unset($stream); return; }
            }
        }
        if($last===null || (($sequence-$last)&65535)<32768) $stream['last']=$sequence;
        $stream['next']??=$sequence;
        if((($sequence-$stream['next'])&65535)>=32768 || isset($stream['pending'][$sequence])) return;
        if(count($stream['pending'])>=256 || $stream['pendingBytes']+strlen($payload)>262144) {
            $stream['pending']=[]; $stream['pendingBytes']=0; $stream['depacketizer']->reset(); $stream['next']=$sequence; $stream['gapSince']=null;
        }
        $stream['pending'][$sequence]=['payload'=>$payload,'timestamp'=>$timestamp,'marker'=>$packet['marker']]; $stream['pendingBytes']+=strlen($payload);
        unset($stream); $this->drainVideo($ssrc,$now);
    }

    /** @return array{samples:list<float>,real:int} */
    public function pullAudio(int $samples):array { $this->active(); return $this->audio->pullPlayout($samples); }

    public function tick():void
    {
        $this->active(); $now=$this->clock->now(); $this->expireCache($now);
        foreach($this->audio->takeSilent($now,$this->senderSilenceMs) as $id) { $this->event('voip_sender_media',['senderId'=>$id,'receiving'=>false,'reason'=>'silent']); if($this->closed) return; }
        foreach(array_keys($this->video) as $ssrc) { if(isset($this->video[$ssrc])) $this->drainVideo($ssrc,$now); if($this->closed) return; }
        if($this->reports->shouldReport(((int)$now)&0xffffffff)) {
            if($this->outbound===[] && ($this->audio->size()>0 || $this->video!==[])) $this->control(Rtcp::receiverReport($this->ownSsrc(),array_slice([...$this->audio->receptionReports($now),...$this->videoReports($now)],0,31)));
            if($this->closed) return;
        }
        if($now-$this->lastEstimate>=$this->reportIntervalMs) {
            if($this->video!==[]) {
                $loss=0.0; foreach($this->video as $stream) $loss=max($loss,$stream['reception']->lossPercent());
                $this->videoBitrate=Rtcp::nextVideoBitrate($this->videoBitrate,$this->videoOctets,$now-$this->lastEstimate,$loss);
                $sources=$this->autoLearn?array_keys($this->video):$this->audio->videoSourceSsrcs();
                if(!$this->videoRembDisabled && $sources!==[]) $this->control(Rtcp::remb($this->videoSsrc(),$sources,$this->videoBitrate));
                if($this->closed) return;
            }
            if($this->audioRembSsrc!==null && $this->audio->has($this->audioRembSsrc)) {
                $this->audioBitrate=Rtcp::nextAudioBitrate($this->audioBitrate,$this->audio->takeLossPercent($this->audioRembSsrc));
                $this->control(Rtcp::remb($this->audioSsrc(),$this->audioRembSsrc,$this->audioBitrate));
            }
            $this->lastEstimate=$now; $this->videoOctets=0;
        }
    }

    /** @return array<string,mixed> */
    public function stats():array { return [...$this->counts,'retransmitCached'=>count($this->retransmit),'videoBitrate'=>$this->videoBitrate,'audioBitrate'=>$this->audioBitrate,'audio'=>$this->audio->stats(),'playout'=>$this->audio->playoutStats(),'audioEnabled'=>$this->audioEnabled,'videoEnabled'=>$this->videoEnabled,'audioRedEnabled'=>$this->audioRedEnabled,'audioRedMode'=>$this->audioRedMode,'audioFramesPerPacket'=>$this->audioFramesPerPacket,'audioFrameSamples'=>$this->requiredAudioSamples,'videoExtensionsEnabled'=>$this->protectVideo!==null,'reportIntervalMs'=>$this->reportIntervalMs,'videoRembDisabled'=>$this->videoRembDisabled,'appDataSframeRequested'=>$this->appDataSframeRequested,'appDataSframeSupported'=>false]; }
    public function close():void { if($this->closed) return; $this->closed=true; $this->audio->clear(); $this->codec->close(); $this->retransmit=[]; $this->video=[]; $this->pendingAudio=null; $this->previousSecondary=null; }

    /** Protects/sends a packet and may invoke callbacks that mute or close the engine.
     * @phpstan-impure
     */
    private function transmit(string $payload,int $timestamp,int $pt,int $slot,bool $marker,?string $extension=null,?float $captureMs=null):string
    {
        $packet=$extension!==null && $this->protectVideo!==null?($this->protectVideo)($payload,$timestamp,$pt,$slot,$marker,$extension,Rtp::EXTENSION_PROFILE):($this->protect)($payload,$timestamp,$pt,$slot,$marker);
        if(!is_string($packet) || strlen($packet)<12 || (ord($packet[0])&0xc0)!==0x80) throw new \UnexpectedValueException('Media protector returned invalid SRTP bytes');
        $header=unpack('nsequence/Ntimestamp/Nssrc',substr($packet,2,10)); $ssrc=$header['ssrc']; $key=$ssrc.':'.$header['sequence']; $now=$this->clock->now();
        $this->expireCache($now); $this->retransmit[$key]=['packet'=>$packet,'at'=>$now,'resends'=>0];
        while(count($this->retransmit)>$this->cacheLimit) unset($this->retransmit[array_key_first($this->retransmit)]);
        $source=$this->outbound[$slot]??['ssrc'=>$ssrc,'packets'=>0,'octets'=>0,'timestamp'=>$timestamp,'captureMs'=>$captureMs??$now,'clockRate'=>$slot===2?90000:$this->codec->sampleRate];
        if($source['ssrc']!==$ssrc) throw new \UnexpectedValueException('Outbound RTP stream SSRC changed');
        $source['packets']=($source['packets']+1)&0xffffffff; $source['octets']=($source['octets']+strlen($payload))&0xffffffff; $source['timestamp']=$timestamp; $source['captureMs']=$captureMs??$now; $this->outbound[$slot]=$source;
        ($this->send)($packet); return $packet;
    }
    private function handleControl(string $compound,float $now):void
    {
        foreach(Rtcp::parse($compound) as $control) {
            if(isset($control['senderSsrc'])) $this->noteSenderHeard($control['senderSsrc'],$now);
            if($this->closed) return;
            if($control['type']===200) { $this->audio->observeSenderReport($control['raw'],$now); foreach($this->video as $stream) $stream['reception']->observeSenderReport($control['raw'],$now); }
            if(($control['kind']??null)==='nack') {
                $this->expireCache($now);
                foreach($control['sequences'] as $sequence) {
                    $key=$control['mediaSsrc'].':'.$sequence; $cached=$this->retransmit[$key]??null;
                    if($cached===null || $cached['resends']>=3) { ++$this->counts['retransmitMisses']; continue; }
                    ++$this->retransmit[$key]['resends']; ($this->send)($cached['packet']); ++$this->counts['retransmitted'];
                    if($this->closed) return;
                }
            } elseif(in_array($control['kind']??null,['pli','fir'],true)) $this->event('voip_keyframe_requested',$control);
            elseif(($control['kind']??null)==='remb') $this->event('voip_bitrate_estimate',$control);
            $this->event('voip_rtcp_feedback',$control);
            if($this->closed) return;
        }
    }
    /** @return list<array<string,int>> */
    private function videoReports(float $now):array { if(!$this->autoLearn) return $this->audio->receptionReports($now,true); $reports=[]; foreach($this->video as $stream) $reports[]=$stream['reception']->report($now); return $reports; }
    private function ownSsrc():int { return $this->outbound[2]['ssrc']??$this->outbound[0]['ssrc']??($this->localAudioSsrc!==0?$this->localAudioSsrc:$this->localVideoSsrc); }
    private function audioSsrc():int { return $this->outbound[0]['ssrc']??($this->localAudioSsrc!==0?$this->localAudioSsrc:$this->ownSsrc()); }
    private function videoSsrc():int { return $this->outbound[2]['ssrc']??($this->localVideoSsrc!==0?$this->localVideoSsrc:$this->ownSsrc()); }
    private function sendAudioPacket(string $payload,int $timestamp,bool $marker,float $captureMs,int $pt):string
    {
        try { $packet=$this->transmit($payload,$timestamp,$pt,0,$marker,null,$captureMs); }
        catch(\Throwable $error) { $this->audioGap=true; $this->previousSecondary=null; if($this->pendingAudio!==null) ++$this->counts['audioPendingDropped']; $this->pendingAudio=null; throw $error; }
        $this->audioGap=false; ++$this->counts['audioSent'];
        if(!$this->closed && $this->audioReports->shouldReport($timestamp)) $this->sendSourceReport(0,$this->clock->now());
        return $packet;
    }
    private function sendSourceReport(int $slot,float $now):void
    {
        $source=$this->outbound[$slot];
        $blocks=array_slice($slot===2?$this->videoReports($now):$this->audio->receptionReports($now),0,$slot===2?15:31);
        $timestamp=($source['timestamp']+(int)round(($now-$source['captureMs'])*$source['clockRate']/1000))&0xffffffff;
        $this->control(Rtcp::senderReportWithSdes($source['ssrc'],$source['packets'],$source['octets'],$timestamp,$this->cname,$blocks,null,count($blocks)<=1,$slot===2 && $this->whatsappVideoProfile,(float)($this->unixMs)()));
    }
    private function noteSenderHeard(int $ssrc,float $now):void
    {
        $started=$this->audio->noteHeard($ssrc,$now);
        if($started!==null) $this->event('voip_sender_media',['senderId'=>$started,'receiving'=>true,'reason'=>'authenticated_media']);
    }
    private function requestVideoKeyframe(int $ssrc,float $now):void
    {
        if(!isset($this->video[$ssrc]) || $now-$this->video[$ssrc]['lastPli']<300 || $now-$this->lastKeyframeRequest<300/$this->maxVideoStreams) return;
        $generation=$this->video[$ssrc]['generation'];
        $this->video[$ssrc]['lastPli']=$now; $this->lastKeyframeRequest=$now;
        $this->control(Rtcp::pli($this->videoSsrc(),$ssrc,$this->whatsappVideoProfile)); ++$this->counts['pliSent'];
        if($this->closed || !isset($this->video[$ssrc]) || $this->video[$ssrc]['generation']!==$generation) return;
        $this->control(Rtcp::fir($this->videoSsrc(),$ssrc,$this->videoFirSequence)); ++$this->counts['firSent']; $this->videoFirSequence=($this->videoFirSequence+1)&255;
    }
    private function drainVideo(int $ssrc,float $now):void
    {
        if(!isset($this->video[$ssrc])) return;
        $stream=&$this->video[$ssrc];
        $generation=$stream['generation'];
        if($stream['next']===null) return;
        if($stream['pending']!==[] && !isset($stream['pending'][$stream['next']])) {
            $stream['gapSince']??=$now;
            if($now-$stream['gapSince']>=100) {
                $nearest=null; $distance=65536;
                foreach(array_keys($stream['pending']) as $sequence) { $delta=($sequence-$stream['next'])&65535; if($delta<$distance) { $distance=$delta; $nearest=$sequence; } }
                if($nearest!==null) $stream['next']=$nearest;
                $stream['depacketizer']->reset(); $stream['gapSince']=null;
            }
        }
        while(isset($stream['pending'][$stream['next']])) {
            $sequence=$stream['next']; $packet=$stream['pending'][$sequence]; unset($stream['pending'][$sequence]); $stream['pendingBytes']-=strlen($packet['payload']); $stream['next']=($sequence+1)&65535; $stream['gapSince']=null;
            foreach($stream['depacketizer']->push($packet['payload'],$packet['timestamp'],$packet['marker'],$sequence) as $frame) {
                if($frame['keyFrame']) $stream['keyFrameReceived']=true;
                if(!$stream['keyFrameReceived']) $this->requestVideoKeyframe($ssrc,$now);
                if($this->closed || !isset($this->video[$ssrc]) || $this->video[$ssrc]['generation']!==$generation) return;
                ++$this->counts['videoFrames']; $this->event('voip_video_frame',['ssrc'=>$ssrc,'senderId'=>$stream['owner'],'codec'=>'h264',...$frame]);
                if($this->closed || !isset($this->video[$ssrc]) || $this->video[$ssrc]['generation']!==$generation) return;
            }
            if($this->closed || !isset($this->video[$ssrc]) || $this->video[$ssrc]['generation']!==$generation) return;
        }
        if($stream['pending']!==[]) $stream['gapSince']??=$now;
    }
    private function expireCache(float $now):void { foreach($this->retransmit as $key=>$entry) if($now-$entry['at']>$this->cacheMs) unset($this->retransmit[$key]); }
    private function control(string $packet):void { ($this->sendControl)($packet); }
    /** @param array<string,mixed> $data */
    private function event(string $name,array $data):void { if($this->emit!==null) ($this->emit)($name,$data); }
    private function active():void { if($this->closed) throw new \LogicException('Call media engine is closed'); }
    /** Receive callbacks may close the engine while AudioReceiveSet is decoding. */
    private function isClosed():bool { return $this->closed; }
    private static function positiveInt(mixed $value,int $maximum,string $name):int { if(!is_int($value) || $value<1 || $value>$maximum) throw new \InvalidArgumentException('Invalid '.$name); return $value; }
    private static function ssrcOption(mixed $value):int { if(!is_int($value) || $value<0 || $value>0xffffffff) throw new \InvalidArgumentException('Local media SSRC must be uint32'); return $value; }
}
