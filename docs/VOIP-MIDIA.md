# Mídia de chamadas em PHP

O processamento fica em PHP. Os codecs chamam bibliotecas C por FFI, sem Node.js, WASM ou callbacks de threads para PHP. O transporte recebe pacotes SRTP/SRTCP autenticados pela `MediaSession`; a camada de mídia produz PCM mono e unidades H.264 Annex-B.

## Dependências nativas

Neste ambiente, Opus 1.6.1 está em `.dev/native/ucrt64/bin/libopus-0.dll`. O MLow/SMPL foi compilado nativamente do release `edgardmessias/opus_mlow` 1.0.1. Para instalar o pacote Opus com hash fixo e reconstruir o MLow:

```powershell
./tools/setup-codecs.ps1
```

O script exige Zig 0.14.1, CMake e Ninja nos caminhos de `.dev/native` ou nos parâmetros `-ZigPath`, `-CmakePath` e `-NinjaPath`. Ele verifica o SHA-256 do tarball antes de extrair, compila uma DLL x86_64 e grava a licença e o manifesto em `.dev/native/codecs`. Não executa os scripts JavaScript do projeto original.

O PHP precisa carregar `ext-ffi` e permitir `FFI::cdef`. É possível configurar outros binários por `MVO_OPUS_LIBRARY` e `MVO_MLOW_LIBRARY`, ou informar seus caminhos nos construtores. Em Linux/macOS, forneça a biblioteca compartilhada compatível com a plataforma; o instalador atual compila para Windows x86_64.

Para captura, gravação e reprodução opcionais, instale FFmpeg, ffplay e ffprobe:

```powershell
./tools/setup-av.ps1
composer voip:doctor
```

O instalador usa o FFmpeg 9.0.2 Essentials do [Gyan](https://www.gyan.dev/ffmpeg/builds/), distribuidor de binários Windows indicado pela [página oficial do FFmpeg](https://ffmpeg.org/download.html). A URL e o SHA-256 do ZIP ficam fixos no script, com manifesto, licença GPLv3, documentação e avisos preservados em `.dev/native/ffmpeg`. Os executáveis podem ser substituídos por `MVO_FFMPEG`, `MVO_FFPLAY`, `MVO_FFPROBE` ou por parâmetros das APIs. Esses processos recebem argumentos por array, sem passar por um shell.

## Codec e recepção

```php
use MVO\Voip\MLowCodec;

$codec = new MLowCodec();
$encoded = $codec->encode($pcm); // lista de floats normalizados; 960 amostras = 60 ms
$decoded = $codec->decode($encoded);
$concealed = $codec->decode(null, 960); // PLC nativo

$result = $codec->decodeSequenced($sequence, $encoded, $rtpTimestamp);
foreach ($result['frames'] as $frame) {
    // Entregar PCM ao gravador/reprodutor da aplicação.
}
$codec->close();
```

`OpusCodec` suporta as taxas Opus de 8, 12, 16, 24 e 48 kHz, com um ou dois canais. `MLowCodec` usa 16 kHz mono e habilita SMPL no encoder e no decoder nativos. PCM de entrada aceita blocos de 2,5, 5, 10, 20, 40 ou 60 ms; o decoder recebe até 120 ms por chamada. Valores não finitos e pacotes acima de 4000 bytes são rejeitados antes de entrar no código nativo.

`decodeSequenced()` trata o retorno das sequências de 65535 para zero, descarta duplicatas/atrasados, mantém sequências separadas para áudio principal e RED e limita a ocultação de perdas. Pausas de DTX indicadas apenas pelo timestamp não geram perda artificial de pacotes. Pacotes malformados não avançam a sequência aceita.

MLow multiframe e RED compacto são implementados em `MLowPacket`. `enableSecondary()` e `encodeSecondary()` usam o encoder secundário nativo, e `writeRed()` associa sua cópia ao timestamp anterior. A recepção reproduz cópias apenas nos trechos ainda não tocados. `stats()['redRecovered']` conta as cópias efetivamente decodificadas para preencher perdas; `fecAttempts` conta pedidos de FEC ao codec. Uma tentativa de FEC pode resultar em PLC quando o pacote não contém redundância, conforme a API nativa.

`AudioReceiveSet` mantém um decoder e uma fila PCM por remetente, oferece estatísticas de recepção independentes, detecta silêncio e mistura os remetentes com saturação de saída em `[-1, 1]`. `sync()` preserva os decoders dos remetentes mantidos e rejeita colisões de SSRC. `JitterBuffer` tem capacidade fixa, descarta as amostras mais antigas em overflow e completa underruns com silêncio.

`AudioCodecNegotiation::select($offer, $supported, $mlowForOpus)` escolhe somente uma taxa/codificação oferecida e localmente suportada. O terceiro argumento é explícito porque o upstream usa SMPL sob a sinalização histórica `enc=opus`; não se deve confundir esse nome com disponibilidade local do MLow.

O perfil padrão de `MediaSession::mediaEngine()` e `GroupMediaSession::mediaEngine()` exige MLow. Sem `MVO_MLOW_LIBRARY`/binário compatível, falha explicitamente; não cai para Opus comum. A sinalização histórica `enc=opus` não autoriza trocar de decoder. O construtor `CallMediaEngine(new OpusCodec(), ...)` permanece uma escolha explícita de baixo nível.

## Motor integrado

`CallMediaEngine` recebe um codec e três callbacks do transporte:

```php
$engine = new \MVO\Voip\CallMediaEngine(
    new \MVO\Voip\MLowCodec(),
    $protectPayload,   // fn(payload, timestamp, payloadType, slot, marker): string SRTP
    $sendEncrypted,    // fn(string $exactSrtpBytes): void
    $sendRtcp,         // fn(string $plaintextRtcp): void, proteção SRTCP no transporte
    ['emit' => $onMediaEvent]
);

$engine->sendAudioPcm($pcm);
$engine->sendVideoAnnexB($annexBFrame); // opcional: timestamp RTP explícito de 90 kHz
$engine->handlePacket($authenticatedMediaSessionPacket);
$audio = $engine->pullAudio(960);
$engine->tick();
$engine->close();
```

O motor usa um mesmo `MediaClock` para áudio e vídeo, gera SR/SDES e estimativas REMB, recebe feedback e emite `voip_audio_frame`, `voip_video_frame`, `voip_keyframe_requested`, `voip_bitrate_estimate` e `voip_rtcp_feedback`. Áudio usa slot 0; vídeo principal usa slot 2, conforme a derivação de SSRC do upstream. `setAudioEnabled()` e `setVideoEnabled()` controlam transmissão. `configureSenders()` define explicitamente os SSRCs de cada participante de grupo, remove imediatamente filas e recepção de vídeo dos SSRCs retirados e rejeita novos pacotes fora desse roster. Sem configuração, o modo individual aprende no máximo oito SSRCs do transporte autenticado. Chame `resetReceiveState()` ao instalar novos contextos SRTP para descartar sequências, PCM pendente, vídeo parcial e retransmissões da época anterior.

RED de saída é opt-in: configure `['audioRed' => true]` ou `setAudioRedEnabled(true)` somente quando a negociação com o destinatário permitir. O modo padrão `compact` preserva PT121/`mlow-red-1`; `['audioRed' => true, 'audioRedMode' => 'copy-first']` usa PT120 com cópia anterior seguida do frame atual, como no transporte da referência. A recepção descarta redundância já reproduzida. RED secundário não pode ser combinado com FEC/DTX incompatíveis do encoder MLow nativo; a API impede essa configuração antes de chamar C.

`applyVoipSettings()` aplica intervalo RTCP, política REMB e `maxfpp`: agrupamento de até dois frames MLow de 60 ms, com liberação de um frame isolado após prazo de 120 ms contado da entrada na fila. `configureRtcp()` permite configuração direta. Os Sender Reports acompanham o avanço de áudio RTP ou a emissão de frames de vídeo; executar somente `tick()` não inventa transmissão. O timestamp de captura de `sendAudioPcm()` usa o mesmo relógio monotônico do engine. `voip_sender_media` informa início, silêncio ou remoção de remetentes; o silêncio padrão é 20 segundos.

Cada pacote de saída é protegido uma vez. O cache conserva o ciphertext SRTP completo; ao receber NACK, reenvia esses mesmos bytes, sem repetir a cifra no mesmo índice. O padrão limita o cache a 512 pacotes por 2 segundos e três reenvios por pacote. A reordenação de vídeo aguarda até 100 ms por um pacote perdido e limita cada stream a 256 pacotes/256 KiB pendentes. NACKs pedem no máximo 128 sequências e PLIs de um stream têm intervalo mínimo de 500 ms.

## H.264 e RTCP

`H264::packetize()` produz NAL simples, STAP-A e FU-A. Reproduz também o formato de keyframe do WhatsApp em que SPS, PPS e IDR viajam dentro de uma fragmentação tipada como SPS. `H264Depacketizer` exige continuidade de sequência para completar FU-A, suporta wrap, rejeita agregações truncadas e limita os bytes acumulados. A saída continua sendo H.264 comprimido; a aplicação fornece câmera, encoder/decoder de vídeo e tela.

O envio aguarda um IDR antes de transmitir frames dependentes, inclusive após uma pausa. As sessões fornecem a extensão RTP DEBE com frame-info, sequência de transporte e fast REMB antes da proteção SRTP. Integrações diretas do engine podem informar o callback `protectVideoPayload(payload, timestamp, pt, slot, marker, extension, profile)`. `H264::readCodec()` extrai a identificação do codec a partir do SPS. PLI/FIR solicitam recuperação inclusive antes do primeiro keyframe recebido.

`Rtcp` constrói e lê pacotes compostos, Receiver Reports padrão, Sender Reports com os blocos de 32 bytes e trailer usados pelo WhatsApp, Generic NACK, PLI, FIR, REMB e a extensão fast REMB. `RtpReception` calcula perda, jitter, LSR e DLSR, sem misturar streams de FEC com os de vídeo. `SenderReportSchedule` oferece pacing com jitter e wrap de contador; `HostCaptureTimeMapper` corrige épocas e drift dos timestamps de captura.

As implementações seguem os módulos `packages/voip-media/src/media` do projeto de referência e os formatos de [RTP/RTCP, RFC 3550](https://www.rfc-editor.org/rfc/rfc3550), [feedback, RFC 4585](https://www.rfc-editor.org/rfc/rfc4585) e [H.264 sobre RTP, RFC 6184](https://www.rfc-editor.org/rfc/rfc6184).

## Limites e validação

O upstream classifica a paridade Reed-Solomon proprietária (`103 + 3k`) e a descarta; não disponibiliza um recuperador. A versão PHP conserva esse comportamento com contador `fecDiscarded`, sem alimentar o depacketizer ou as estatísticas de perda de vídeo. PT 121 e PT 122 são reconhecidos como áudio antes dessa classificação. A recuperação disponível é RED/FEC do codec, além de retransmissão por NACK.

O codec MLow padrão continua disponível sem pós-filtro. Para Companion/NoLACE, existe um backend C separado compilado por `tools/setup-companion.ps1`, sem substituir `libopus-mlow.dll`. O instalador fixa revisão/hashes, preserva licenças e grava `companion-build.json`. Não executa JavaScript nem WASM. O build fornecido é Windows x86_64; outras plataformas precisam de uma biblioteca nativa compatível com o mesmo ABI.

```php
$codec = new \MVO\Voip\MLowCodec(options: [
    'decodeOnly' => true,
    'companionModelPath' => '/caminho/modelo-real.dnnw',
]);
// Sem argumento library, o modelo seleciona o backend Companion separado.
// MVO_MLOW_COMPANION_LIBRARY permite informar uma biblioteca compatível.
$codec->supportsCompanion(); // ABI disponível
$codec->hasCompanion();      // Modelo realmente instalado neste decoder
$codec->setCompanionModel(null); // Destaca o pós-filtro
$codec->close();
```

`companionModel` aceita bytes DNNw ou um loader explícito. Dimensões, limites de 32 MiB e pesos finitos são verificados antes de entrar em C. Modelo inválido não substitui um filtro já ativo; cada decoder de participante mantém seu próprio estado. Reset e descarte também resetam/liberam o filtro nativo. Encoder/decoder e Companion precisam pertencer à mesma biblioteca compartilhada.

**Pesos reais não são fornecidos.** O ciclo nativo de carga/hook/decodificação/reset/descarte foi exercitado com tensores sintéticos apenas de teste; isso não comprova qualidade de áudio. Backend ausente ou modelo incompatível produz erro explícito. A biblioteca não captura microfone, câmera ou alto-falante automaticamente.

## Captura, reprodução e gravação explícitas

`FfmpegCapture` oferece fábricas `microphone($device)`, `camera($device, $width, $height, $fps)` e `screen($target, $width, $height, $fps)`. Construir esses objetos não abre os dispositivos: é necessário chamar `start()`. O backend usa DirectShow/gdigrab no Windows, PulseAudio/V4L2/X11 no Linux ou AVFoundation no macOS. A aplicação informa o dispositivo ou alvo exato; um backend/dispositivo ausente gera falha real de inicialização.

`lavfiAudio()` e `lavfiVideo()` produzem sinais sintéticos para desenvolver sem abrir microfone, câmera ou tela. `pollAudio()` retorna blocos de 960 floats mono a 16 kHz; um último bloco parcial em EOF natural recebe silêncio e registra `paddedSamples`. `pollVideo()` retorna access units H.264 Annex-B completos, separados por AUD gerado no encoder x264. Os limites são 128 KiB para o buffer PCM e 8 MiB por access unit de vídeo. `stop()`/`cancel()` encerram o processo e descartam frames parciais.

```php
$capture = \MVO\Voip\FfmpegCapture::lavfiAudio(
    'sine=frequency=440:sample_rate=16000:duration=1'
);
$recording = \MVO\Voip\AudioOutput::waveFile('/caminho/novo-audio.wav');
$capture->start();
$recording->start();
try {
    while (!$capture->isEnded()) {
        foreach ($capture->pollAudio() as $pcm) {
            $recording->write($pcm);
            // Em uma chamada ativa autorizada: $engine->sendAudioPcm($pcm, $captureMs).
        }
        $recording->poll();
        usleep(1000);
    }
    $recording->finish();
} finally {
    $capture->cancel();
    $recording->cancel();
}
```

Para áudio recebido, entregue `pullAudio()['samples']` a `AudioOutput::playback()` depois de iniciar explicitamente esse player. `AudioOutput::waveFile()` grava PCM em WAV sem tocar no alto-falante. Para vídeo, `VideoOutput::render()` abre um player ffplay somente em `start()`, enquanto `VideoOutput::videoFile()` grava H.264 em MP4. Entregue as access units de `voip_video_frame` a `write()`, chame `poll()` no loop e `finish()` para fechar o contêiner normalmente. Os destinos de gravação devem ser novos arquivos: não há sobrescrita automática.

`setPlayoutMuted(true, 8)` em `AudioOutput` aplica uma transição de ganho de oito milissegundos, mantendo a linha de tempo de recepção. `setMicrophone($nomeOuCapture)` troca uma captura de áudio ativa; `setOutput($novaSaida)` substitui a saída. Falha ao iniciar o novo endpoint mantém o atual. `FfmpegCapture::audioFile($path)` fornece PCM de arquivo local com limite de expansão, sem carregar a faixa inteira em memória.

`AudioOutput::device($backend, $device)` seleciona uma saída nativa ALSA, PulseAudio, AudioToolbox, OSS ou sndio, quando esse backend existe no FFmpeg instalado. No Windows, `playback()` usa a saída padrão do sistema pelo FFplay; seleção por nome não está disponível nesse backend. Essas APIs não usam os identificadores de dispositivos do navegador. Troca de arquivos e mute foram testados sem abrir alto-falante ou microfone; suporte de hardware depende do host.

As filas de saída aplicam backpressure e rejeitam escritas além do limite configurado, em vez de acumular memória sem limite. Os dados trafegam em sockets TCP de loopback não bloqueantes entre PHP e o processo nativo; stdin/stdout de pipes anônimos do Windows não participam desse caminho. Logs têm limite de 1 MiB, e startup/finish têm deadlines. Nenhuma reprodução ou captura inicia como efeito de receber uma mensagem ou criar uma sessão WhatsApp.

Os fluxos brutos fornecem PCM e H.264, sem timestamps de captura do dispositivo. A aplicação deve manter o relógio de captura e informar os timestamps ao motor quando entregar lotes acumulados; frames de vídeo devem seguir o FPS configurado e áudio avança 60 ms por bloco. Isto não substitui um sincronizador de hardware com PTS originais.

Os testes incluem vetores RTCP calculados separadamente, a imagem de SR capturada do upstream, fragmentação/reordenação H.264, oito pacotes MLow capturados de um cliente WhatsApp Web oficial, codificação/decodificação de PCM real, reparo RED por encoder secundário nativo, PLC/FEC contra um decoder de referência separado e retransmissão de ciphertext SRTP sem recifrar. A fixture `tests/fixtures/voip/mlow-web-capture.json` deriva de `_mlow-captures.ts` do upstream. O pipeline FFmpeg foi testado com tom/color sintéticos, gravações WAV/MP4 verificadas por ffprobe, EOF parcial, falha de filtro, cancelamento e filas limitadas. Os testes nativos mostram skip explícito quando suas dependências não estão instaladas. Nenhuma chamada, microfone, câmera, captura de tela ou reprodução de áudio real foi ativada nesses testes.

```powershell
C:/xampp/php/php.exe -c .dev/php.ini vendor/bin/phpunit --filter 'Voip(MediaAlgorithms|NativeCodec|AvPipeline)Test|CallMediaEngineTest'
```
