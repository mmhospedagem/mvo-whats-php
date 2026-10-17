<?php
declare(strict_types=1);
require dirname(__DIR__).'/vendor/autoload.php';

use MVO\Voip\{NativeDataChannel,OpusCodec,MLowCodec,FfmpegBinary};

$failed=false;
$check=static function(string $name,callable $probe)use(&$failed):void{
    try{$detail=$probe();echo '[OK] '.$name.': '.$detail.PHP_EOL;}
    catch(Throwable $e){$failed=true;fwrite(STDERR,'[FAIL] '.$name.': '.$e->getMessage().PHP_EOL);}
};
$check('FFI',static function():string{if(!extension_loaded('FFI'))throw new RuntimeException('Habilite ext-ffi no PHP CLI');return 'habilitada no PHP CLI';});
$check('WebRTC nativo',static function():string{
    $peer=new NativeDataChannel(bindAddress:'127.0.0.1');
    try{if($peer->state()!=='new')throw new RuntimeException('Estado inicial inesperado');return 'bridge ABI 1 carregado; ICE/DTLS/SCTP disponível';}
    finally{$peer->close();}
});
foreach(['Opus'=>OpusCodec::class,'MLow'=>MLowCodec::class] as $name=>$class){
    $check($name,static function()use($class):string{
        $encoder=new $class();$decoder=$encoder->newDecoder();
        try{$frame=$encoder->encode(array_fill(0,960,0.0));$samples=$decoder->decodeSequenced(1,$frame,0)['frames'];if($samples===[] || count($samples[0])!==960)throw new RuntimeException('Round-trip PCM inválido');return 'encoder/decoder nativos, 960 amostras a 16 kHz';}
        finally{$encoder->close();$decoder->close();}
    });
}
if(is_file(MLowCodec::defaultCompanionLibrary())){
    $check('Companion opcional',static function():string{
        $codec=new MLowCodec(MLowCodec::defaultCompanionLibrary(),options:['decodeOnly'=>true]);
        try{if(!$codec->supportsCompanion())throw new RuntimeException('ABI Companion ausente');return 'ABI nativa disponível; modelo externo não carregado neste diagnóstico';}
        finally{$codec->close();}
    });
}else echo '[OPTIONAL] Companion: backend não instalado; exige modelo externo explícito.'.PHP_EOL;
foreach(['ffmpeg','ffprobe','ffplay'] as $name){
    $check($name,static function()use($name):string{
        $tool=FfmpegBinary::tool($name);if(!is_file($tool))throw new RuntimeException('Executável ausente: '.$tool);
        $process=proc_open([$tool,'-version'],[0=>['pipe','r'],1=>['pipe','w'],2=>['redirect',1]],$pipes,null,null,['bypass_shell'=>true]);
        if(!is_resource($process))throw new RuntimeException('Não foi possível executar -version');
        fclose($pipes[0]);$line=fgets($pipes[1]);stream_get_contents($pipes[1]);fclose($pipes[1]);$exit=proc_close($process);
        if($exit!==0 || $line===false)throw new RuntimeException('Diagnóstico do executável falhou');return trim($line);
    });
}
echo 'Diagnóstico local; não ativa microfone, câmera, tela, reprodução ou chamadas.'.PHP_EOL;
exit($failed?1:0);
