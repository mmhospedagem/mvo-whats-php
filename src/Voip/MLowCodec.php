<?php
declare(strict_types=1);
namespace MVO\Voip;

/** SMPL/MLow encoder and decoder from the pinned native opus_mlow fork. */
final class MLowCodec extends OpusCodec
{
    public const EXTRA_API=<<<'C'
        void opus_global_create(void);
        void opus_global_free(void);
        int opus_encode_secondary(OpusEncoder *st, unsigned char *data, int max_data_bytes);
        int mlow_packet_has_fec_content(const unsigned char *data);
        C;
    /** Native globals live for this PHP process; freeing while another FFI owner exists is unsafe.
     * @var array<string,\FFI>
     */
    private static array $globals=[];
    private bool $secondaryEnabled=false;
    private ?string $secondary=null;
    private ?\FFI\CData $secondaryOutput=null;
    private ?\FFI $companionApi=null;
    private ?\FFI\CData $companion=null;
    private ?string $companionModel=null;
    /** @var array<string,true> */
    private static array $validatedModels=[];
    /** Fixed widths consumed by companion.c/companion_net.c at the pinned native revision.
     * @var array<string,array{int,int}>
     */
    private const COMPANION_LAYERS=[
        'pitch_embedding'=>[351,64],'fnet_conv1'=>[165,96],'fnet_conv2'=>[768,160],
        'fnet_tconv'=>[160,640],'fnet_gru_input'=>[160,480],'fnet_gru_recurrent'=>[160,480],
        'af1_kernel'=>[160,32],'af1_gain'=>[160,2],'af4_kernel'=>[160,32],'af4_gain'=>[160,1],
        'tdshape1_alpha1_f'=>[320,80],'tdshape1_alpha1_t'=>[42,80],'tdshape1_alpha2'=>[160,80],
        'ft1'=>[320,160],'ft2'=>[320,160]
    ];
    private const COMPANION_API=<<<'C'
        void *oc_companion_create(const unsigned char *model, int model_bytes, int *error);
        void oc_companion_destroy(void *companion);
        void oc_companion_reset(void *companion);
        int oc_decoder_set_companion(void *decoder, void *companion);
        C;
    /** @var array<string,mixed> */
    private readonly array $options;
    /** Options mirror the native capabilities, never load WASM or download model weights.
     * @param array<string,mixed> $options fec,packetLossPercent,maxConcealFrames,tunables,red,decodeOnly,companionModel,companionModelPath.
     */
    public function __construct(?string $library=null,int $bitrate=15000,int $complexity=5,array $options=[])
    {
        foreach($options as $key=>$value) if(!in_array($key,['fec','packetLossPercent','maxConcealFrames','tunables','red','decodeOnly','companionModel','companionModelPath'],true)) throw new \InvalidArgumentException('Unknown MLow option: '.$key);
        foreach(['fec','decodeOnly'] as $key) if(isset($options[$key]) && !is_bool($options[$key])) throw new \InvalidArgumentException('MLow '.$key.' must be boolean');
        $loss=$options['packetLossPercent']??0; $maximum=$options['maxConcealFrames']??5;
        if(!is_int($loss) || $loss<0 || $loss>100 || !is_int($maximum) || $maximum<0 || $maximum>100) throw new \InvalidArgumentException('Invalid MLow loss or concealment limit');
        if(isset($options['companionModel'],$options['companionModelPath'])) throw new \InvalidArgumentException('Choose Companion model bytes/loader or model path');
        $model=$options['companionModel']??null;
        if(isset($options['companionModelPath'])) {
            $modelPath=$options['companionModelPath'];
            if(!is_string($modelPath) || !is_file($modelPath) || ($size=filesize($modelPath))===false || $size<20 || $size>33554432) throw new \InvalidArgumentException('Companion model path requires a local 20..33554432 byte file');
            $model=file_get_contents($modelPath); if($model===false) throw new \RuntimeException('Cannot read explicitly supplied Companion model');
        }
        if($model!==null && !is_string($model)) { if(!is_callable($model)) throw new \InvalidArgumentException('Companion model must be binary bytes or a callable loader'); $model=$model(); }
        if($model!==null && !is_string($model)) throw new \UnexpectedValueException('Companion model loader must return binary bytes');
        $this->options=$options;
        $path=$library??($model===null?self::defaultMLowLibrary():self::defaultCompanionLibrary()); parent::__construct($path,16000,1,$bitrate,$complexity,true,$options['decodeOnly']??false,$maximum);
        try {
            if($model!==null) $this->setCompanionModel($model);
            if(!$this->isDecodeOnly()) {
                $this->setExpectedPacketLossPercent($loss); $this->setInbandFec($options['fec']??true); $this->setDtx(true);
                if(isset($options['tunables'])) { if(!is_array($options['tunables'])) throw new \InvalidArgumentException('MLow tunables must be an array'); $this->applyEncoderTunables($options['tunables']); }
                $red=$options['red']??false;
                if($red!==false) { if($red!==true && !is_array($red)) throw new \InvalidArgumentException('MLow red must be boolean or settings'); $this->enableSecondary(is_array($red)?($red['secondaryBitrate']??4200):4200,is_array($red)?($red['secondaryComplexity']??5):5); }
            }
        } catch(\Throwable $error) { $this->close(); throw $error; }
    }
    public function newDecoder():OpusCodec { return new self($this->libraryPath,options:['decodeOnly'=>true,'maxConcealFrames'=>$this->options['maxConcealFrames']??5,...($this->companionModel===null?[]:['companionModel'=>$this->companionModel])]); }
    public static function defaultCompanionLibrary():string
    {
        $configured=getenv('MVO_MLOW_COMPANION_LIBRARY'); if(is_string($configured) && $configured!=='') return $configured;
        return dirname(__DIR__,2).'/.dev/native/codecs/libmvo-mlow-companion.dll';
    }
    /** Checks for the published oc_* ABI in the same DLL which created this decoder. */
    public function supportsCompanion():bool
    {
        if($this->companionApi!==null) return true;
        try { $this->companionApi=\FFI::cdef(self::COMPANION_API,$this->libraryPath); return true; }
        catch(\FFI\Exception) { return false; }
    }
    public function hasCompanion():bool { return $this->companion!==null; }
    /** Explicit DNNw weights only. Invalid replacement leaves the current filter attached. */
    public function setCompanionModel(?string $model):void
    {
        $decoder=$this->decoder??throw new \LogicException('Audio decoder is closed');
        if($model===null && $this->companion===null) { $this->companionModel=null; return; }
        if($model!==null && (strlen($model)<20 || strlen($model)>33554432 || !str_starts_with($model,'DNNw'))) throw new \InvalidArgumentException('Companion weights must be a 20..33554432 byte DNNw container');
        if(!$this->supportsCompanion()) throw new \RuntimeException('The selected native opus_mlow ABI has no MLow Companion/NoLACE post-filter; model was not applied. Build tools/setup-companion.ps1 and select its DLL');
        $api=$this->companionApi??throw new \LogicException('Companion ABI is unavailable');
        $next=null;
        if($model!==null) {
            self::validateCompanionModel($model);
            $bytes=$api->new('unsigned char['.strlen($model).']'); \FFI::memcpy($bytes,$model,strlen($model)); $error=$api->new('int[1]');
            $next=$this->companionCall('oc_companion_create',$bytes,strlen($model),$error);
            if(!$next instanceof \FFI\CData || \FFI::isNull($next) || $error[0]!==0) {
                if($next instanceof \FFI\CData && !\FFI::isNull($next)) $this->companionCall('oc_companion_destroy',$next);
                throw new \RuntimeException('Native MLow Companion model was not applied (model error '.(int)$error[0].')');
            }
        }
        $result=$this->companionCall('oc_decoder_set_companion',$decoder,$next);
        if(!is_int($result) || $result<0) { if($next!==null) $this->companionCall('oc_companion_destroy',$next); throw new \RuntimeException('Native MLow Companion attachment failed; existing filter retained'); }
        $old=$this->companion; $this->companion=$next; $this->companionModel=$model;
        if($old!==null) $this->companionCall('oc_companion_destroy',$old);
    }
    public function resetSequence():void
    {
        parent::resetSequence();
        if($this->companion!==null) $this->companionCall('oc_companion_reset',$this->companion);
    }
    /** The C parser assumes nonzero, exact tensor widths. Reject corrupt containers before FFI. */
    public static function validateCompanionModel(string $model):void
    {
        $length=strlen($model);
        if($length<20 || $length>33554432 || !str_starts_with($model,'DNNw')) throw new \InvalidArgumentException('Companion weights must be a bounded DNNw container');
        $digest=hash('sha256',$model); if(isset(self::$validatedModels[$digest])) return;
        $offset=0; $chunks=[];
        while($offset<$length) {
            if($offset+20>$length || substr($model,$offset,4)!=='DNNw' || count($chunks)>=256) throw new \InvalidArgumentException('Invalid Companion DNNw chunk header');
            $header=unpack('Vdtype/Vbytes/Vpadded',substr($model,$offset+8,12));
            if($header===false) throw new \InvalidArgumentException('Invalid Companion DNNw chunk fields');
            $end=strpos($model,"\0",$offset+20);
            if($end===false || $end<=$offset+20 || $end-$offset-20>127) throw new \InvalidArgumentException('Invalid Companion tensor name');
            $name=substr($model,$offset+20,$end-$offset-20); $data=intdiv($end+128,128)*128;
            $size=$header['bytes']; $padded=$header['padded']; $dtype=$header['dtype'];
            if(isset($chunks[$name]) || $size>$padded || $padded%128!==0 || $data+$padded>$length || $data+$padded<=$offset || ($dtype===0 && $size%4!==0)) throw new \InvalidArgumentException('Invalid Companion tensor bounds or padding');
            if($dtype===0) {
                for($at=0;$at<$size;$at+=65536) {
                    $values=unpack('g*',substr($model,$data+$at,min(65536,$size-$at)));
                    if($values===false) throw new \InvalidArgumentException('Invalid Companion float tensor');
                    foreach($values as $value) if(!is_finite($value)) throw new \InvalidArgumentException('Companion model has non-finite weights');
                }
            }
            $chunks[$name]=['bytes'=>$size,'dtype'=>$dtype]; $offset=$data+$padded;
        }
        foreach(self::COMPANION_LAYERS as $layer=>[$inputs,$outputs]) {
            $base='mlowcompanion_'.$layer; $bias=$chunks[$base.'_bias']??null;
            if($bias===null || $bias['dtype']!==0 || $bias['bytes']!==$outputs*4) throw new \InvalidArgumentException('Companion model has missing or incompatible bias: '.$layer);
            $quantized=$chunks[$base.'_weights_int8']??null;
            if($quantized!==null) {
                if($quantized['dtype']!==3 || $quantized['bytes']!==$inputs*$outputs) throw new \InvalidArgumentException('Companion model has incompatible quantized weights: '.$layer);
                foreach(['_scale','_subias'] as $suffix) { $chunk=$chunks[$base.$suffix]??null; if($chunk===null || $chunk['dtype']!==0 || $chunk['bytes']!==$outputs*4) throw new \InvalidArgumentException('Companion model has incompatible quantization tensor: '.$layer); }
            } else {
                $weights=$chunks[$base.'_weights_float']??null;
                if($weights===null || $weights['dtype']!==0 || $weights['bytes']!==$inputs*$outputs*4) throw new \InvalidArgumentException('Companion model has missing or incompatible float weights: '.$layer);
            }
        }
        if(count(self::$validatedModels)>=16) array_shift(self::$validatedModels);
        self::$validatedModels[$digest]=true;
    }
    private function companionCall(string $function,mixed ...$arguments):mixed
    {
        $api=$this->companionApi??throw new \LogicException('Companion ABI is unavailable'); return $api->{$function}(...$arguments);
    }
    /** @param list<float|int> $pcm One 60 ms frame on the 16 kHz timeline. */
    public function encode(array $pcm):string
    {
        if(count($pcm)!==960) throw new \InvalidArgumentException('MLow encoding requires 960 mono samples (60 ms)');
        $this->secondary=null; $packet=parent::encode($pcm);
        // The secondary must follow every primary while enabled, even when a caller drops RED.
        if($this->secondaryEnabled) {
            $encoder=$this->encoder??throw new \LogicException('Audio encoder is closed');
            $output=$this->secondaryOutput??=$this->api->new('unsigned char[4000]');
            $length=$this->nativeInt('opus_encode_secondary',$encoder,$output,4000); $this->check($length,'encode secondary');
            $this->secondary=\FFI::string($output,$length);
        }
        return $packet;
    }
    /** @param list<string> $frames */
    public function packFrames(array $frames):string
    {
        if($this->isDecodeOnly()) throw new \LogicException('Decode-only MLow codec has no encoder/repacketizer');
        $packet=MLowPacket::pack($frames);
        if(MLowPacket::samples($packet)>1920) throw new \InvalidArgumentException('MLow packet exceeds 120 ms');
        return $packet;
    }
    public function redActive():bool { return $this->secondaryEnabled; }
    public function lastSecondary():?string { return $this->secondary; }
    public function setExpectedPacketLossPercent(int $percent):void
    {
        parent::setExpectedPacketLossPercent($percent);
        if($this->secondaryEnabled) parent::setInbandFec(false);
    }
    public function setInbandFec(bool $enabled):void
    {
        if($enabled && $this->secondaryEnabled) throw new \LogicException('Native MLow secondary RED and in-band FEC cannot be enabled together');
        parent::setInbandFec($enabled);
    }
    public function setDtx(bool $enabled):void
    {
        if($enabled && $this->secondaryEnabled) throw new \LogicException('Native MLow secondary RED requires DTX disabled');
        parent::setDtx($enabled);
    }
    /** Native requests are only applied when explicitly provided by the caller.
     * @param array<string,int> $tunables
     */
    public function applyEncoderTunables(array $tunables):void
    {
        $requests=['subframeImportance'=>[4060,300],'useSpeechActivityFlatness'=>[4062,1],'vadNonBinary'=>[4066,100],'vadHighpassSharpness'=>[4068,100]];
        foreach($tunables as $name=>$value) if(!isset($requests[$name]) || !is_int($value) || $value<0 || $value>$requests[$name][1]) throw new \InvalidArgumentException('Invalid MLow encoder tunable: '.$name);
        foreach($tunables as $name=>$value) $this->encoderCtl($requests[$name][0],$value);
    }
    public static function defaultMLowLibrary():string
    {
        $configured=getenv('MVO_MLOW_LIBRARY'); if(is_string($configured) && $configured!=='') return $configured;
        return dirname(__DIR__,2).'/.dev/native/codecs/libopus-mlow.dll';
    }
    /** @internal */
    public static function retainGlobals(\FFI $api,string $path):void { $key=realpath($path)?:$path; if(!isset(self::$globals[$key])) { self::globalVoid($api,'opus_global_create'); self::$globals[$key]=$api; } }
    private static function globalVoid(\FFI $api,string $function):void { $api->{$function}(); }
    public function enableSecondary(int $bitrate=4200,int $complexity=5):void
    {
        if($bitrate<500 || $bitrate>15000 || $complexity<0 || $complexity>10) throw new \InvalidArgumentException('Invalid MLow secondary encoder settings');
        // opus_mlow 1.0.1 asserts (terminates PHP) if secondary sees a primary LBRR or DTX frame.
        $this->setDtx(false); parent::setInbandFec(false); $this->encoderCtl(4056,$bitrate); $this->encoderCtl(4054,$complexity); $this->secondaryEnabled=true;
    }
    /** The cached lower-bitrate copy from encode(), safe to read more than once. */
    public function encodeSecondary():string
    {
        if(!$this->secondaryEnabled) throw new \LogicException('Enable MLow secondary encoder first');
        if($this->encoder===null) throw new \LogicException('Audio encoder is closed');
        return $this->secondary??throw new \LogicException('Encode a primary MLow frame before reading its secondary');
    }
    /** @return list<array{packet:string,samples:int,timestamp:?int,copy:bool}> */
    protected function packetSegments(string $packet,?int $timestamp,int $stream):array
    {
        if(strlen($packet)>4000) throw new \InvalidArgumentException('MLow packet exceeds bound');
        $red=$stream===1?MLowPacket::readRed($packet):null; $segments=[];
        if($red!==null) {
            $copies=$red['copies']; usort($copies,static fn($a,$b)=>$b['delta']<=>$a['delta']);
            foreach($copies as $copy) { $span=MLowPacket::samples($copy['packet']); if($span>1920 || $span>$copy['delta']) continue; $start=$timestamp===null?null:($timestamp-$copy['delta'])&0xffffffff; foreach(MLowPacket::frames($copy['packet']) as $frame) { $segments[]=['packet'=>$frame['packet'],'samples'=>$frame['samples'],'timestamp'=>$start,'copy'=>true]; if($start!==null) $start=($start+$frame['samples'])&0xffffffff; } }
            $packet=$red['primary'];
        }
        $frames=MLowPacket::frames($packet); if(MLowPacket::samples($packet)>1920) throw new \InvalidArgumentException('MLow packet duration exceeds 120 ms');
        if($frames===[]) return $stream===0?parent::packetSegments($packet,$timestamp,$stream):[];
        $start=$timestamp;
        foreach($frames as $frame) { $segments[]=['packet'=>$frame['packet'],'samples'=>$frame['samples'],'timestamp'=>$start,'copy'=>false]; if($start!==null) $start=($start+$frame['samples'])&0xffffffff; }
        return $segments;
    }
    public function close():void
    {
        $this->secondary=null; $this->secondaryOutput=null; $this->secondaryEnabled=false;
        // Destroy the decoder before its hook context, so no native pointer outlives the filter.
        parent::close();
        if($this->companion!==null) { $this->companionCall('oc_companion_destroy',$this->companion); $this->companion=null; }
        $this->companionModel=null;
    }
}
