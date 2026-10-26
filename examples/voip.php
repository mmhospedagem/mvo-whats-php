<?php
declare(strict_types=1);

// Local help/preflight never constructs a WhatsApp client or opens an account session.
$help=<<<'HELP'
Desenvolvimento VoIP em PHP (individual; duração padrão: 120 segundos)

  php examples/voip.php --help
  php examples/voip.php --preflight
  php examples/voip.php --session=default
  php examples/voip.php --session=default --to=5511999999999 --duration=120
  php examples/voip.php --session=default --accept --play

Sem --to: observa ofertas. Somente --accept aceita ofertas individuais recebidas.
--to=NUMERO inicia UMA chamada real; exige sessão já vinculada e worker parado.

Opções explícitas de mídia (só iniciam depois de a chamada ser aceita):
  --microphone=DEVICE  Captura o microfone indicado; mutuamente exclusivo com --tone.
  --tone               Envia tom sintético de 440 Hz; não abre microfone.
  --camera=DEVICE      Captura a câmera indicada, 640x480/15 fps.
  --screen=TARGET      Captura o alvo de tela indicado, 1280x720/15 fps.
                       --camera e --screen são mutuamente exclusivos.
  --play               Reproduz áudio recebido; sem esta flag não abre alto-falante.
  --transport=webrtc|raw-udp  WebRTC é o padrão de companion.
  --duration=SEGUNDOS   Limite de execução: 1..3600, incluindo conexão/espera.

Nenhum hardware abre sem sua flag. Sem flags de mídia, processa apenas rede/recepção.
Os logs mostram estados e contadores, sem JIDs, tokens, chaves ou payloads privados.
Chamadas reais ainda precisam de validação; veja docs/VOIP.md e composer voip:doctor.
HELP;
$values=['session','to','duration','transport','microphone','camera','screen'];
$flags=['help','preflight','accept','tone','play']; $options=[];
try {
    for($i=1;$i<$argc;$i++) {
        if(!str_starts_with($argv[$i],'--')) throw new InvalidArgumentException('Use opções --nome=valor ou --nome valor.');
        $parts=explode('=',substr($argv[$i],2),2); $name=$parts[0];
        if(isset($options[$name])) throw new InvalidArgumentException('Opção duplicada: --'.$name);
        if(in_array($name,$flags,true)) { if(count($parts)!==1) throw new InvalidArgumentException('--'.$name.' não recebe valor.'); $options[$name]=true; }
        elseif(in_array($name,$values,true)) {
            $value=$parts[1]??$argv[++$i]??null;
            if(!is_string($value) || $value==='' || str_starts_with($value,'--') || str_contains($value,"\0")) throw new InvalidArgumentException('Informe o valor de --'.$name.'.');
            $options[$name]=$value;
        } else throw new InvalidArgumentException('Opção desconhecida: --'.$name);
    }
    if(isset($options['help'])) { echo $help.PHP_EOL; exit(0); }
    $duration=filter_var($options['duration']??'120',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>3600]]);
    if($duration===false) throw new InvalidArgumentException('--duration deve ser de 1 a 3600 segundos.');
    $session=$options['session']??'default';
    if(!preg_match('/^[A-Za-z0-9_.-]{1,64}$/D',$session)) throw new InvalidArgumentException('Nome de sessão inválido.');
    $to=$options['to']??null;
    if($to!==null && !preg_match('/^\+?[1-9][0-9]{7,14}$/D',$to)) throw new InvalidArgumentException('--to exige número com DDI e DDD, somente dígitos e + opcional.');
    $transport=$options['transport']??null;
    if($transport!==null && !in_array($transport,['raw-udp','webrtc'],true)) throw new InvalidArgumentException('--transport deve ser webrtc ou raw-udp.');
    if(isset($options['microphone'],$options['tone'])) throw new InvalidArgumentException('Escolha --microphone OU --tone.');
    if(isset($options['camera'],$options['screen'])) throw new InvalidArgumentException('Escolha --camera OU --screen.');
    if(isset($options['preflight'])) { require dirname(__DIR__).'/tools/voip-doctor.php'; exit(0); }
} catch(InvalidArgumentException $error) { fwrite(STDERR,$error->getMessage().PHP_EOL.'Use --help.'.PHP_EOL); exit(2); }

require dirname(__DIR__).'/vendor/autoload.php';

use MVO\Voip\{AudioOutput,CallMediaEngine,FfmpegBinary,FfmpegCapture,MediaClock,MediaSession,MLowCodec,NativeDataChannel};
use MVO\WaClient;

$audioWanted=isset($options['microphone']) || isset($options['tone']);
$videoWanted=isset($options['camera']) || isset($options['screen']);
$stopping=false; $pairingRequired=false; $offered=false; $callId=null;
$failed=false; $phase='pré-check local';
$incoming=[]; $callEnded=false; $client=null; $media=null; $engine=null;
$audioCapture=null; $videoCapture=null; $playback=null; $videoStarted=false; $upgradeRequested=false;
$audioQueue=[]; $videoQueue=[]; $sentSamples=0; $audioDropped=0; $videoDropped=0;
$counts=['audioReceived'=>0,'videoReceived'=>0];
$now=static fn():float=>hrtime(true)/1000000000;
$clock=new MediaClock(static fn():float=>hrtime(true)/1000000);
$line=static function(string $text):void { echo '['.date('H:i:s').'] '.$text.PHP_EOL; };
$stop=static function()use(&$stopping):void { $stopping=true; };
if(function_exists('sapi_windows_set_ctrl_handler')) sapi_windows_set_ctrl_handler(static function(int $event)use($stop):bool { if(in_array($event,[PHP_WINDOWS_EVENT_CTRL_C,PHP_WINDOWS_EVENT_CTRL_BREAK],true)) { $stop(); return true; } return false; });
if(function_exists('pcntl_async_signals')) { pcntl_async_signals(true); pcntl_signal(SIGINT,$stop); pcntl_signal(SIGTERM,$stop); }

try {
    $willUseMedia=$to!==null || isset($options['accept']);
    if($willUseMedia) {
        if(!extension_loaded('FFI')) throw new RuntimeException('Native media FFI is unavailable');
        if(!is_file(MLowCodec::defaultMLowLibrary())) throw new RuntimeException('WhatsApp call audio requires native MLow; configure MVO_MLOW_LIBRARY');
        if($transport!=='raw-udp' && !is_file(NativeDataChannel::defaultLibrary())) throw new RuntimeException('Native WebRTC library is unavailable');
        if((isset($options['microphone']) || $videoWanted) && !is_file(FfmpegBinary::tool('ffmpeg'))) throw new RuntimeException('Native capture FFmpeg is unavailable');
        if(isset($options['play']) && !is_file(FfmpegBinary::tool('ffplay'))) throw new RuntimeException('Native playback ffplay is unavailable');
    }

    $phase='inicialização da sessão'; $client=new WaClient($session);
    $client->on('connection',static function(array $event)use($line):void {
        $state=$event['state']??''; if(in_array($state,['connecting','pairing','connected','disconnected','reconnecting','stopped','logged_out'],true)) $line('Conexão: '.$state);
    });
    $client->on('auth_qr',static function()use(&$pairingRequired,&$stopping):void { $pairingRequired=true; $stopping=true; });
    $client->on('voip_incoming_call',static function(array $event)use(&$incoming):void {
        if(is_string($event['callId']??null) && count($incoming)<16) $incoming[$event['callId']]=($event['group']??false)===true;
    });
    $client->on('voip_call_state',static function(array $event)use(&$callId,&$callEnded,$line):void {
        if(($event['callId']??null)!==$callId) return;
        $state=$event['state']??''; if(in_array($state,['offering','offered','ringing','connecting','connected','ended'],true)) $line('Chamada: '.$state);
        if($state==='ended') $callEnded=true;
    });
    $client->on('voip_audio_frame',static function()use(&$counts):void { ++$counts['audioReceived']; });
    $client->on('voip_video_frame',static function()use(&$counts):void { ++$counts['videoReceived']; });
    $client->on('voip_media_error',static function()use($line):void { $line('Falha de mídia/transport; detalhes privados omitidos.'); });

    $line($to===null?'Modo observação; aceite somente com --accept.':'Uma chamada real será iniciada depois do login.');
    $phase='conexão da sessão'; $deadline=$now()+$duration; $client->connect();
    $nextAudio=0.0; $nextVideo=0.0; $nextPlay=0.0; $nextStats=$now()+5;
    while(!$stopping && !$callEnded && $now()<$deadline && $client->isRunning()) {
        // Poll also drains VoipService's signaling/transport/heartbeat queues and media sessions.
        $phase='poll da sessão'; $client->poll(0.005);
        if(!$client->isConnected()) continue;
        $voip=$client->voip();
        if($to!==null && !$offered) {
            $phase='oferta individual'; $offered=true; $call=$voip->offer(ltrim($to,'+'),$videoWanted); $callId=$call['callId']; $line('Oferta individual enviada.');
        }
        foreach($incoming as $id=>$group) {
            unset($incoming[$id]);
            if($group) { $line('Oferta de grupo observada; este exemplo trata somente chamadas individuais.'); continue; }
            if(!isset($options['accept']) || $callId!==null) { $line('Oferta individual observada; não aceita por este processo.'); continue; }
            $call=$voip->getCall($id); if($call['state']!=='ringing') continue;
            $phase='aceite individual'; $callId=$id; $voip->accept($id); $line('Oferta individual aceita por --accept.');
        }
        if($callId===null) continue;
        $call=$voip->getCall($callId); if($call['state']==='ended') break;
        if(!in_array($call['state'],['connecting','connected'],true)) continue;
        if($media===null) {
            if($voip->relayDescriptors($callId)===[]) continue;
            $phase='transporte de mídia'; $candidate=$voip->connectMedia($callId,$transport);
            if(!$candidate instanceof MediaSession) throw new LogicException('This CLI only supports individual calls');
            $media=$candidate; $engine=$media->mediaEngine(options:['clock'=>$clock]);
            // Keep signaling and engine state aligned: receive-only announces a muted microphone.
            $voip->setMuted($callId,!$audioWanted);
            $nextAudio=$nextVideo=$nextPlay=$now();
            if(isset($options['microphone'])) { $phase='início do microfone'; $audioCapture=FfmpegCapture::microphone($options['microphone']); $audioCapture->start(); $line('Captura de microfone iniciada explicitamente.'); }
            if(isset($options['play'])) { $phase='início da reprodução'; $playback=AudioOutput::playback(); $playback->start(); $line('Reprodução de áudio iniciada explicitamente.'); }
            $line('Transporte configurado; conectado somente após mídia autenticada.');
        }
        if(!$engine instanceof CallMediaEngine) continue;
        if($videoWanted && !$videoStarted) {
            if(!$call['video']) {
                if(!$upgradeRequested) { $upgradeRequested=true; $voip->requestVideoUpgrade($callId); $line('Upgrade de vídeo solicitado; aguardando negociação.'); }
            } else {
                if(isset($options['screen'])) { $voip->setScreenSharing($callId,true); $videoCapture=FfmpegCapture::screen($options['screen']); }
                else { $voip->setVideoEnabled($callId,true); $videoCapture=FfmpegCapture::camera($options['camera']); }
                $phase='início da captura de vídeo'; $videoCapture->start(); $videoStarted=true; $line('Captura de vídeo iniciada explicitamente.');
            }
        }

        $phase='processamento de mídia';
        if($audioCapture!==null && !$audioCapture->isEnded()) {
            foreach($audioCapture->pollAudio() as $frame) $audioQueue[]=$frame;
            if(count($audioQueue)>10) { $audioDropped+=count($audioQueue)-10; $audioQueue=array_slice($audioQueue,-10); }
        }
        if($videoCapture!==null && !$videoCapture->isEnded()) {
            foreach($videoCapture->pollVideo() as $frame) $videoQueue[]=$frame;
            if(count($videoQueue)>4) { $videoDropped+=count($videoQueue)-4; $videoQueue=array_slice($videoQueue,-4); }
        }
        $instant=$now();
        if($audioWanted && $instant>=$nextAudio && (isset($options['tone']) || $audioQueue!==[])) {
            if($instant-$nextAudio>0.18) $nextAudio=$instant;
            if(isset($options['tone'])) { $pcm=[]; for($i=0;$i<960;$i++) $pcm[]=0.15*sin(2*M_PI*440*($sentSamples+$i)/16000); }
            else $pcm=array_shift($audioQueue);
            try { if($engine->sendAudioPcm($pcm,$nextAudio*1000)!==[]) $sentSamples+=960; }
            catch(RuntimeException $error) { if($error->getMessage()!=='No responsive call relay is ready') throw $error; ++$audioDropped; }
            $nextAudio+=0.06;
        }
        if($videoQueue!==[] && $instant>=$nextVideo) {
            if($instant-$nextVideo>0.2) $nextVideo=$instant;
            $frame=array_shift($videoQueue);
            try { $engine->sendVideoAnnexB($frame,$clock->ticksAt($nextVideo*1000,90000)); }
            catch(RuntimeException $error) { if($error->getMessage()!=='No responsive call relay is ready') throw $error; ++$videoDropped; }
            $nextVideo+=1/15;
        }
        if($playback!==null && $instant>=$nextPlay) {
            if($instant-$nextPlay>0.18) $nextPlay=$instant;
            $playback->write($engine->pullAudio(960)['samples']); $playback->poll(); $nextPlay+=0.06;
        }
        if($instant>=$nextStats) {
            $line('Frames recebidos: áudio='.$counts['audioReceived'].' vídeo='.$counts['videoReceived'].'; amostras enviadas='.$sentSamples.'; captura atrasada descartada: áudio='.$audioDropped.' vídeo='.$videoDropped.'.'); $nextStats=$instant+5;
        }
    }
    if($pairingRequired) throw new RuntimeException('Pairing is required; use composer connect:qr before the VoIP example');
    $line('Execução encerrada; hardware e sessão serão fechados.');
} catch(Throwable $error) {
    // Some protocol exceptions can carry private payload fragments; never echo them here.
    fwrite(STDERR,'Falha em '.$phase.' ('.get_class($error).'). Execute --preflight; vincule a sessão antes e pare outro worker que detenha seu lock.'.PHP_EOL);
    if($pairingRequired) fwrite(STDERR,'Sessão não vinculada: execute composer connect:qr antes deste exemplo.'.PHP_EOL);
    $failed=true; $stopping=true;
} finally {
    $audioCapture?->cancel(); $videoCapture?->cancel(); $playback?->cancel();
    if($client instanceof WaClient && $client->isConnected() && $callId!==null) {
        try { $voip=$client->voip(); if($voip->getCall($callId)['state']!=='ended') $voip->terminate($callId); }
        catch(Throwable) { fwrite(STDERR,'Encerramento remoto não confirmado; fechando transporte local.'.PHP_EOL); }
    }
    $client?->disconnect();
}
exit($failed?1:0);
