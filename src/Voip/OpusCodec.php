<?php
declare(strict_types=1);
namespace MVO\Voip;

/** Native libopus, synchronously called through PHP FFI; no callbacks or worker threads. */
class OpusCodec implements AudioDecoder
{
    protected readonly \FFI $api;
    protected readonly string $libraryPath;
    protected ?\FFI\CData $encoder=null;
    protected ?\FFI\CData $decoder=null;
    /** @var array<int,int> */
    private array $lastSequences=[];
    private ?int $playedUntil=null;
    private int $lastSamples=0;
    private int $packetStep=0;
    private ?\FFI\CData $encodeInput=null;
    private ?\FFI\CData $encodeOutput=null;
    private ?\FFI\CData $decodeOutput=null;
    private ?\FFI\CData $packetInput=null;
    /** @var array<string,int> */
    protected array $counts=['success'=>0,'errors'=>0,'plc'=>0,'fecAttempts'=>0,'late'=>0,'concealCapped'=>0,'redundant'=>0,'redRecovered'=>0];
    protected const API=<<<'C'
        typedef struct OpusEncoder OpusEncoder;
        typedef struct OpusDecoder OpusDecoder;
        OpusEncoder *opus_encoder_create(int Fs, int channels, int application, int *error);
        void opus_encoder_destroy(OpusEncoder *st);
        int opus_encode_float(OpusEncoder *st, const float *pcm, int frame_size, unsigned char *data, int max_data_bytes);
        int opus_encoder_ctl(OpusEncoder *st, int request, int value);
        OpusDecoder *opus_decoder_create(int Fs, int channels, int *error);
        void opus_decoder_destroy(OpusDecoder *st);
        int opus_decode_float(OpusDecoder *st, const unsigned char *data, int len, float *pcm, int frame_size, int decode_fec);
        int opus_decoder_ctl(OpusDecoder *st, int request, int value);
        int opus_packet_get_nb_samples(const unsigned char *packet, int len, int Fs);
        const char *opus_get_version_string(void);
        const char *opus_strerror(int error);
        C;

    public function __construct(?string $library=null,public readonly int $sampleRate=16000,public readonly int $channels=1,int $bitrate=15000,int $complexity=5,protected readonly bool $mlow=false,private readonly bool $decodeOnly=false,private readonly int $maxConcealFrames=5)
    {
        if($maxConcealFrames<0 || $maxConcealFrames>100) throw new \InvalidArgumentException('Concealment limit must be 0..100 frames');
        if(!extension_loaded('FFI')) throw new \RuntimeException('Native audio requires ext-ffi and ffi.enable');
        if(!in_array($sampleRate,[8000,12000,16000,24000,48000],true) || !in_array($channels,[1,2],true) || ($mlow && ($sampleRate!==16000 || $channels!==1))) throw new \InvalidArgumentException('Invalid audio codec sample rate or channels');
        $path=$library??self::defaultLibrary(); if(!is_file($path)) throw new \RuntimeException('Native audio library not found: '.$path); $this->libraryPath=$path;
        $this->api=\FFI::cdef(self::API.($mlow?MLowCodec::EXTRA_API:''),$path);
        if($mlow) MLowCodec::retainGlobals($this->api,$path);
        try {
            $error=$this->api->new('int[1]');
            if(!$decodeOnly) {
                $encoder=$this->nativePointer('opus_encoder_create',$sampleRate,$channels,2048,$error);
                $this->check($error[0],'create encoder'); $this->encoder=$encoder;
                $this->encodeInput=$this->api->new('float['.(intdiv($sampleRate*60,1000)*$channels).']');
                $this->encodeOutput=$this->api->new('unsigned char[4000]');
            }
            $decoder=$this->nativePointer('opus_decoder_create',$sampleRate,$channels,$error);
            $this->check($error[0],'create decoder'); $this->decoder=$decoder;
            $this->decodeOutput=$this->api->new('float['.(intdiv($sampleRate*120,1000)*$channels).']');
            $this->packetInput=$this->api->new('unsigned char[4000]');
            if(!$decodeOnly) { $this->setBitrate($bitrate); $this->setComplexity($complexity); $this->encoderCtl(4024,3001); }
            if($mlow) { if(!$decodeOnly) $this->encoderCtl(4050,1); $this->check($this->nativeInt('opus_decoder_ctl',$decoder,4050,1),'enable MLow decoder'); }
        } catch(\Throwable $error) { $this->close(); throw $error; }
    }
    public static function defaultLibrary():string
    {
        $configured=getenv('MVO_OPUS_LIBRARY'); if(is_string($configured) && $configured!=='') return $configured;
        return dirname(__DIR__,2).'/.dev/native/ucrt64/bin/libopus-0.dll';
    }
    public function version():string { return $this->nativeString('opus_get_version_string'); }
    public function newDecoder():OpusCodec { return $this->mlow?new MLowCodec($this->libraryPath,options:['decodeOnly'=>true,'maxConcealFrames'=>$this->maxConcealFrames]):new self($this->libraryPath,$this->sampleRate,$this->channels,decodeOnly:true,maxConcealFrames:$this->maxConcealFrames); }
    public function isDecodeOnly():bool { return $this->decodeOnly; }
    public function frameSize():int { return intdiv($this->sampleRate*60,1000); }
    /** @param list<float|int> $pcm Normalized, interleaved samples. */
    public function encode(array $pcm):string
    {
        $encoder=$this->encoder??throw new \LogicException('Audio encoder is closed'); $size=intdiv(count($pcm),$this->channels);
        $allowed=[intdiv($this->sampleRate,400),intdiv($this->sampleRate,200),intdiv($this->sampleRate,100),intdiv($this->sampleRate,50),intdiv($this->sampleRate,25),intdiv($this->sampleRate*3,50)];
        if(!array_is_list($pcm) || count($pcm)%$this->channels!==0 || !in_array($size,$allowed,true)) throw new \InvalidArgumentException('PCM frame must span 2.5, 5, 10, 20, 40 or 60 ms');
        $input=$this->encodeInput??throw new \LogicException('Audio encoder is closed');
        foreach($pcm as $i=>$sample) { if((!is_int($sample) && !is_float($sample)) || !is_finite((float)$sample)) throw new \InvalidArgumentException('PCM sample must be finite numeric data'); $input[$i]=max(-1.0,min(1.0,(float)$sample)); }
        $output=$this->encodeOutput??throw new \LogicException('Audio encoder is closed'); $length=$this->nativeInt('opus_encode_float',$encoder,$input,$size,$output,4000); $this->check($length,'encode');
        return \FFI::string($output,$length);
    }
    /** Null packet invokes loss concealment. fec=true decodes in-band FEC where present, else native PLC.
     * @return list<float>
     */
    public function decode(?string $packet,int $frameSize=0,bool $fec=false):array
    {
        $decoder=$this->decoder??throw new \LogicException('Audio decoder is closed');
        if($packet!==null && ($packet==='' || strlen($packet)>4000)) throw new \InvalidArgumentException('Encoded packet must be 1..4000 bytes');
        $maximum=intdiv($this->sampleRate*120,1000); if($frameSize===0) $frameSize=$packet===null?$this->frameSize():$maximum;
        if($frameSize<1 || $frameSize>$maximum || (($packet===null || $fec) && $frameSize%intdiv($this->sampleRate,400)!==0)) throw new \InvalidArgumentException('Invalid audio decode frame size');
        $input=$packet===null?null:$this->bytes($packet); $output=$this->decodeOutput??throw new \LogicException('Audio decoder is closed');
        $samples=$this->nativeInt('opus_decode_float',$decoder,$input,$packet===null?0:strlen($packet),$output,$frameSize,$fec?1:0); $this->check($samples,$fec?'decode FEC':'decode');
        $pcm=[]; for($i=0;$i<$samples*$this->channels;$i++) { $sample=$output[$i]; if(!is_finite($sample)) throw new \RuntimeException('Native audio decoder produced non-finite PCM'); $pcm[]=$sample; } return $pcm;
    }
    /** @return array{outcome:string,frames:list<list<float>>} */
    public function decodeSequenced(int $sequence,string $packet,?int $timestamp=null,int $stream=0):array
    {
        if($sequence<0 || $sequence>65535 || !in_array($stream,[0,1],true) || ($timestamp!==null && ($timestamp<0 || $timestamp>0xffffffff))) throw new \InvalidArgumentException('Invalid sequenced audio header');
        if($this->decoder===null) throw new \LogicException('Audio decoder is closed');
        if($packet==='') return ['outcome'=>'empty','frames'=>[]];
        $last=$this->lastSequences[$stream]??null; $delta=$last===null?1:($sequence-$last)&65535;
        if($delta===0 || $delta>=32768) { ++$this->counts['late']; return ['outcome'=>'late','frames'=>[]]; }
        try { $segments=$this->packetSegments($packet,$timestamp,$stream); } catch(\RuntimeException|\InvalidArgumentException $error) { ++$this->counts['errors']; return ['outcome'=>'rejected','frames'=>[]]; }
        if($segments===[]) { ++$this->counts['errors']; return ['outcome'=>'rejected','frames'=>[]]; }
        $missing=$delta-1; $frames=[]; $budget=min($missing,$this->maxConcealFrames)*intdiv($this->sampleRate*120,1000); $decoded=false; $withoutTimelineConcealed=false;
        $previousEnd=$this->playedUntil; $newSamples=0;
        foreach($segments as $segment) {
            $start=$segment['timestamp']; $span=$segment['samples'];
            if($segment['copy'] && ($missing===0 || $start===null || $this->playedUntil===null)) { ++$this->counts['redundant']; continue; }
            if($start!==null && $this->playedUntil!==null) {
                $behind=self::signedDelta($this->playedUntil,($start+$span)&0xffffffff);
                if($behind>=0 && $behind<=$this->sampleRate) { ++$this->counts['redundant']; $decoded=true; continue; }
                if($segment['copy'] && self::signedDelta($start,$this->playedUntil)<0) { ++$this->counts['redundant']; continue; }
                $gap=self::signedDelta($start,$this->playedUntil);
                if($missing>0 && $gap>0) { if($budget===0 && $this->maxConcealFrames===0) ++$this->counts['concealCapped']; else $this->conceal(min($gap,$budget),$gap<=$budget?$segment['packet']:null,$frames,$budget); }
            } elseif($missing>0 && !$segment['copy'] && !$withoutTimelineConcealed) {
                $step=$this->lastSamples>0?$this->lastSamples:$this->frameSize();
                $conceal=min($missing,$this->maxConcealFrames)*$step; if($missing>$this->maxConcealFrames) ++$this->counts['concealCapped'];
                $this->conceal($conceal,$segment['packet'],$frames,$budget);
                $withoutTimelineConcealed=true;
            }
            try { $pcm=$this->decode($segment['packet']); }
            catch(\RuntimeException|\InvalidArgumentException) { ++$this->counts['errors']; $this->lastSequences[$stream]=($sequence-1)&65535; return ['outcome'=>'rejected','frames'=>$frames]; }
            $frames[]=$pcm; $decoded=true;
            if(!$segment['copy']) $newSamples+=intdiv(count($pcm),$this->channels);
            $this->playedUntil=$start===null?null:($start+$span)&0xffffffff;
            if($segment['copy']) $budget=max(0,$budget-$span);
            if($segment['copy'] || ($missing>0 && $start!==null && $previousEnd!==null && $this->packetStep>0 && self::signedDelta($start,$previousEnd)>=0 && self::signedDelta($start,$previousEnd)<$missing*$this->packetStep)) ++$this->counts['redRecovered'];
        }
        if($newSamples>0) $this->lastSamples=min(intdiv($this->sampleRate*120,1000),$newSamples);
        if($missing===0 && $previousEnd!==null && $timestamp!==null && $this->playedUntil!==null && self::signedDelta($timestamp,$previousEnd)<=0) $this->packetStep=max(0,self::signedDelta($this->playedUntil,$previousEnd));
        $this->lastSequences[$stream]=$decoded?$sequence:(($sequence-1)&65535);
        if($decoded) ++$this->counts['success'];
        return ['outcome'=>$decoded?'decoded':'rejected','frames'=>$frames];
    }
    /** @return list<array{packet:string,samples:int,timestamp:?int,copy:bool}> */
    protected function packetSegments(string $packet,?int $timestamp,int $stream):array
    {
        if(strlen($packet)>4000) throw new \InvalidArgumentException('Audio packet exceeds bound');
        $samples=$this->nativeInt('opus_packet_get_nb_samples',$this->bytes($packet),strlen($packet),$this->sampleRate); $this->check($samples,'read packet');
        if($samples<=0 || $samples>intdiv($this->sampleRate*120,1000)) throw new \InvalidArgumentException('Audio packet duration exceeds 120 ms');
        return [['packet'=>$packet,'samples'=>$samples,'timestamp'=>$timestamp,'copy'=>false]];
    }
    /** @param list<list<float>> $frames */
    private function conceal(int $samples,?string $fecPacket,array &$frames,int &$budget):void
    {
        $quantum=intdiv($this->sampleRate,400); $remaining=intdiv(min($samples,$budget),$quantum)*$quantum; $step=$this->lastSamples>0?$this->lastSamples:$this->frameSize();
        $limit=min(intdiv($this->sampleRate*120,1000),$step); $iterations=0;
        while($remaining>0 && $iterations++<$this->maxConcealFrames) {
            $size=min($remaining,$limit); $useFec=$fecPacket!==null && $size===$remaining;
            try { $frames[]=$this->decode($useFec?$fecPacket:null,$size,$useFec); }
            catch(\RuntimeException|\InvalidArgumentException) {
                // FEC can be unsupported for this packet. Keep the same slot on native PLC.
                $useFec=false;
                try { $frames[]=$this->decode(null,$size); }
                catch(\RuntimeException|\InvalidArgumentException) { $frames[]=array_fill(0,$size*$this->channels,0.0); }
            }
            ++$this->counts[$useFec?'fecAttempts':'plc']; $remaining-=$size; $budget-=$size;
            if($this->playedUntil!==null) $this->playedUntil=($this->playedUntil+$size)&0xffffffff;
        }
        if($remaining>0) ++$this->counts['concealCapped'];
    }
    public function setBitrate(int $bitrate):void { if($bitrate<500 || $bitrate>512000) throw new \InvalidArgumentException('Invalid codec bitrate'); $this->encoderCtl(4002,$bitrate); }
    public function setComplexity(int $complexity):void { if($complexity<0 || $complexity>10) throw new \InvalidArgumentException('Codec complexity must be 0..10'); $this->encoderCtl(4010,$complexity); }
    public function setExpectedPacketLossPercent(int $percent):void { if($percent<0 || $percent>100) throw new \InvalidArgumentException('Expected loss must be 0..100'); $this->encoderCtl(4012,1); $this->encoderCtl(4014,$percent); }
    public function setInbandFec(bool $enabled):void { $this->encoderCtl(4012,$enabled?1:0); }
    public function setDtx(bool $enabled):void { $this->encoderCtl(4016,$enabled?1:0); }
    /** @return array<string,int> */
    public function stats():array { return $this->counts; }
    public function resetSequence():void { $this->lastSequences=[]; $this->playedUntil=null; $this->lastSamples=0; $this->packetStep=0; if($this->decoder!==null) $this->check($this->nativeInt('opus_decoder_ctl',$this->decoder,4028,0),'reset decoder'); }
    public function close():void
    {
        if($this->encoder!==null) { $this->nativeVoid('opus_encoder_destroy',$this->encoder); $this->encoder=null; }
        if($this->decoder!==null) { $this->nativeVoid('opus_decoder_destroy',$this->decoder); $this->decoder=null; }
        $this->encodeInput=$this->encodeOutput=$this->decodeOutput=$this->packetInput=null;
    }
    protected function encoderCtl(int $request,int $value):void
    {
        // Only integer setter requests are bound here, never variadic getters/pointers.
        $encoder=$this->encoder??throw new \LogicException('Audio encoder is closed');
        $this->check($this->nativeInt('opus_encoder_ctl',$encoder,$request,$value),'encoder ctl '.$request);
    }
    protected function bytes(string $value):\FFI\CData { if($value==='' || strlen($value)>4000) throw new \InvalidArgumentException('Audio packet must be 1..4000 bytes'); $bytes=$this->packetInput??throw new \LogicException('Audio decoder is closed'); \FFI::memcpy($bytes,$value,strlen($value)); return $bytes; }
    protected function check(int $result,string $operation):void
    {
        if($result>=0) return;
        $description=$this->nativeString('opus_strerror',$result);
        throw new \RuntimeException('Native audio '.$operation.' failed ('.$result.'): '.$description);
    }
    protected static function signedDelta(int $left,int $right):int { $delta=($left-$right)&0xffffffff; return $delta>=0x80000000?$delta-0x100000000:$delta; }
    /** FFI exports are generated dynamically from API; validate each return at the native boundary. */
    protected function nativeInt(string $function,mixed ...$arguments):int { $result=$this->api->{$function}(...$arguments); if(!is_int($result)) throw new \RuntimeException('Invalid integer result from '.$function); return $result; }
    protected function nativePointer(string $function,mixed ...$arguments):\FFI\CData { $result=$this->api->{$function}(...$arguments); if(!$result instanceof \FFI\CData || \FFI::isNull($result)) throw new \RuntimeException('Native audio allocation failed: '.$function); return $result; }
    protected function nativeString(string $function,mixed ...$arguments):string { $result=$this->api->{$function}(...$arguments); if(is_string($result)) return $result; if($result instanceof \FFI\CData && !\FFI::isNull($result)) return \FFI::string($result); throw new \RuntimeException('Invalid string result from '.$function); }
    protected function nativeVoid(string $function,mixed ...$arguments):void { $this->api->{$function}(...$arguments); }
    private function __clone() {}
    /** @return array<never> */
    public function __serialize():array { throw new \LogicException('Native audio codec cannot be serialized'); }
    public function __destruct() { $this->close(); }
}
