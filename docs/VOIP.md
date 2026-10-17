# Chamadas: sinalização, transporte e mídia em PHP

`MVO\Voip` implementa sinalização individual e de grupo, distribuição Signal de chaves, composição de participantes/rekey, RTP/SRTP/SRTCP, relays raw UDP e WebRTC nativo com ICE/DTLS/SCTP. O motor opcional processa PCM Opus/MLow, H.264, RTCP, jitter, RED e retransmissão por NACK. Captura/reprodução usam processos FFmpeg explícitos, sem Node.js ou WASM.

**Ainda não houve validação de uma chamada real do WhatsApp.** A evidência atual inclui vetores independentes, participantes simulados, UDP loopback, conexão ICE/DTLS/SCTP bidirecional real entre dois peers locais, codecs C reais e gravações sintéticas verificadas com ffprobe. ACK de sinalização, abertura de socket ou alocação de relay não são apresentados como mídia conectada.

## Sinalização e decisão da aplicação

```php
$voip = $client->voip();
$call = $voip->offer('5511999999999@s.whatsapp.net');
$id = $call['callId'];

$client->on('voip_incoming_call', function (array $call): void {
    // Enfileirar a decisão da aplicação; fazer I/O depois que o handler retornar.
});

// Alternativas para uma chamada recebida aguardando decisão:
// $voip->accept($receivedCallId);
// $voip->reject($receivedCallId);

$voip->terminate($id);
```

Receber uma oferta não aceita a chamada, abre mídia, ativa microfone nem câmera. O bridge usa `Message.call.callKey`, padding e envelope Signal `destination/to/enc`, com identidade quando necessária para `pkmsg`. Ofertas sem chave autenticável não criam uma chamada utilizável. Chaves ficam privadas e não aparecem em `getCall()`, `getCalls()` ou eventos. Pacotes atrasados não reabrem uma chamada encerrada.

O poller envia `preaccept` automaticamente para ofertas recebidas e, em chamadas individuais iniciadas aqui, depois do ACK da oferta. `preaccept()` continua disponível e deduplica esse trabalho. Ao receber o preaccept do destinatário, o iniciador anuncia `transport` tipo/round `0/0` ao usuário. O aceite local envia `transport` `1/1` ao remetente da oferta antes de `accept`; o iniciador também envia `1/1` ao aparelho que atendeu antes do receipt de aceite. O preaccept mantém a chamada tocando e não autoriza mídia.

Esses anúncios automáticos usam uma fila diferida de até 1.024 trabalhos, com no máximo 64 envios por drenagem. Stanzas `call` ficam pendentes até um ACK de classe, ID e tipo correspondentes; uma escrita no socket não conclui o trabalho. ACK negativo, erro de escrita ou timeout de ACK de três segundos geram `voip_error` e até três tentativas totais com a mesma ID, backoff de 0,5/1 segundo e prazo total de 30 segundos. `voip_signaling_ack` confirma somente o ACK correlacionado dessas stanzas. O receipt de aceite, enviado como `receipt`, conclui por escrita e não gera esse evento. Encerrar a chamada elimina seus trabalhos e correlações.

`WaClient::poll()` mantém essa fila e a mídia ativas inclusive durante uma IQ pendente. `VoipService::tick(false)` pode enviar anúncios e processar mídia, mas adia resolução de aparelhos e criptografia/distribuição de novas chaves que poderiam consultar a conexão. Uma integração direta com `VoipService` precisa chamar `tick()` regularmente e entregar os ACKs de classe `call` a `handleNode()`. O handler de entrada não consulta a rede recursivamente.

`getCall()`/`getCalls()` expõem os estados `offering`, `offered`, `ringing`, `connecting`, `connected` e `ended`. A transição a `connected` exige mídia SRTP/SRTCP autenticada do aparelho ou membro autorizado. `setMuted()` controla a transmissão do áudio e anuncia o estado; `setVideoEnabled()` exige a negociação apropriada. Pedidos recebidos de mute não silenciam outro participante à força.

O primeiro pacote de mídia autenticado enfileira o anúncio do estado atual do microfone. Alterar mute ou mão levantada explicitamente cancela snapshots automáticos anteriores ainda pendentes, evitando retransmitir um estado que a aplicação já mudou. O estado local de mute, vídeo e tela é aplicado antes do ACK; encerrar uma chamada fecha a mídia antes de aguardar a resposta de sinalização.

Grupos usam `offerGroup()`, `participants()`, `join()`, `leave()`, roster confirmado do servidor e `enc_rekey`. Há negociação de upgrade de vídeo, screen share, mão levantada e app-data de reações/atribuição de efeitos. O código de efeitos transmite seu identificador; não aplica filtros gráficos. A descrição dos envelopes, autorização, revogação e APIs está em [VOIP-GRUPOS.md](VOIP-GRUPOS.md).

## Abrir transporte explicitamente

Após a chamada ser aceita:

```php
$media = $voip->connectMedia($id); // Companion: WebRTC nativo.

// Para uma identidade primary importada, escolher explicitamente:
// $media = $voip->connectMedia($id, 'raw-udp');

$engine = $media->mediaEngine(); // MLow quando instalado; Opus como alternativa.
$engine->sendAudioPcm($samplesMono); // 960 amostras a 16 kHz = 60 ms.
$media->poll(0.01);
```

`MediaSession` e `GroupMediaSession` também aceitam `RelayTransport` fornecido pela aplicação. `protectPayload()` e `handleDatagram()` separam processamento criptográfico de abertura de rede. `sendPayload()` continua disponível para frames já comprimidos. `mediaEngine()` é opcional; recebimento de sinalização não carrega codecs ou dispositivos automaticamente. Vídeo fica desativado até a aplicação habilitar o stream após negociação.

`RelayPool` mantém múltiplos endpoints, inscrição STUN, RTT, ping/pong, fallback de porta e recuperação após silêncio. Somente mídia autenticada prova a nova geração de credenciais; ACK STUN não retira sozinho o caminho anterior. Relays antigos deixam de receber transmissão depois da prova, mas podem permanecer recebendo por um período limitado. O pool e seus relays padrão usam o mesmo relógio injetado para métricas, eleição e retries.

`configure($descriptors, $generation)` ignora gerações antigas. Credenciais novas no mesmo endpoint preservam o socket UDP; WebRTC preserva ICE/DTLS quando chave e identidade de autenticação continuam compatíveis. Inscrições atuais acompanham a substituição. A geração mais recente é tentada primeiro, com acompanhamento do caminho autenticado anterior após 400 ms sem prova. O watchdog depende de envio de mídia aceita: reanuncia WebRTC após cinco segundos e faz no máximo três rediscagens com backoff, somente na geração atual. A cadência de keepalive muda de cinco para um segundo após aceite. Transportes personalizados podem implementar `RelayCredentialUpdater` e `RelayFlowControl`; o contrato básico `RelayTransport` continua válido.

Depois de `connectMedia()`, o poller envia `relaylatency` somente para relays que responderam à alocação/pong e têm RTT medido. Endpoints com o mesmo `relay_name` são deduplicados pela menor medição disponível. O relatório inclui os aparelhos anunciados/resolvidos e é atualizado quando essa composição muda; não inclui chaves ou tokens. Nenhum valor de latência é inventado para um relay sem resposta. A biblioteca aguarda `connectMedia()` explícito antes de sondar relays ou abrir ICE/DTLS/SCTP.

`WebRtcRelay` usa o bridge C de `native/rtc_bridge.c` com libdatachannel/Mbed TLS. O bridge enfileira callbacks de threads nativas para leitura pelo poller PHP; não chama PHP dessas threads. O SDP usa o fingerprint fixado do relay da referência, e o protocolo WhatsApp STUN/WARP trafega dentro do datachannel DTLS/SCTP. A ausência de FFI/DLL produz erro real. `raw-udp` é um transporte diferente, não um fallback que simule WebRTC.

`UdpRelay` usa ALLOCATE autenticado, token binário, assinatura de SSRC, reenvio limitado e ping/pong. Apenas resposta ALLOCATE com MESSAGE-INTEGRITY válido confirma alocação. Os descritores de relay contêm tokens/chaves e devem ser tratados como credenciais, não publicados em eventos ou logs.

## Criptografia e mídia

As chaves/salts por aparelho são derivadas com HKDF-SHA256 da chave da chamada e do JID completo do aparelho. SRTP usa AES-128-CTR e HMAC-SHA1 truncado a quatro bytes; SRTCP usa tag de dez bytes. O código verifica rollover, autenticação, replay e limites de contextos. SSRCs são derivados do ID da chamada, aparelho e slot: áudio principal 0, vídeo principal 2 e app-data 6.

O engine protege cada pacote uma vez. NACK retransmite o ciphertext SRTP já persistido no cache temporário, sem recifrar o mesmo índice. Reordenação de vídeo aguarda uma janela limitada para reparar FU-A antes de abandonar um frame. Remover um participante poda imediatamente sua recepção/buffers; trocar chaves elimina estado da época anterior. RTP valida CSRCs, extensões e padding; os payload types 64–95 são recusados para evitar colisão no multiplexador RTCP.

`CallMediaEngine` inclui Opus/MLow C via FFI, RED compacto com encoder secundário MLow, PLC/FEC do codec, mistura de PCM por remetente, jitter de playout, relógio compartilhado e H.264 Annex-B/STAP-A/FU-A. Gera/lê SR, RR, NACK, PLI, FIR, REMB e oferece fast REMB como primitiva de extensão. Eventos entregam áudio decodificado, unidades H.264, pedidos de keyframe e estimativas de bitrate.

`FfmpegCapture` fornece microfone, câmera, tela e sinais sintéticos; `AudioOutput`/`VideoOutput` oferecem reprodução ou gravação. Tudo exige `start()` explícito e oferece stop/cancel, deadlines e filas limitadas. A aplicação mantém seu loop, relógio de captura e política de UI. Os fluxos brutos não fornecem os PTS originais dos dispositivos. Veja [VOIP-MIDIA.md](VOIP-MIDIA.md) para APIs, tamanhos de frame e exemplos.

## Instalação opcional

```powershell
./tools/setup-voip.ps1   # ICE/DTLS/SCTP nativos e bridge PHP FFI.
./tools/setup-codecs.ps1 # Opus 1.6.1 e build MLow/SMPL 1.0.1.
./tools/setup-companion.ps1 # Pós-filtro nativo opcional; exige pesos externos.
./tools/setup-av.ps1     # FFmpeg/ffplay/ffprobe 9.0.2 opcionais.
composer voip:doctor     # Verifica dependências, sem hardware ou WhatsApp.
```

Os scripts verificam revisões/hashes e preservam licenças em `.dev/native`. O primeiro prepara Zig/CMake/Ninja, utilizados pelo build de codecs. O PHP precisa de `ext-ffi` e permissão para `FFI::cdef` para WebRTC/codecs; processamento de arquivos/captura por FFmpeg não depende de FFI. Os scripts atuais preparam Windows x86_64. Outras plataformas podem fornecer suas bibliotecas/executáveis compatíveis por caminho ou variáveis descritas nos módulos.

Composer não instala esses binários nem liga hardware. O protocolo e o loop da aplicação continuam em PHP; não existe runtime Node.js.

## Exemplo CLI de desenvolvimento individual

```powershell
C:/xampp/php/php.exe -c .dev/php.ini examples/voip.php --help
C:/xampp/php/php.exe -c .dev/php.ini examples/voip.php --preflight

# Conecta uma sessão já vinculada e apenas observa ofertas por até 120 segundos.
C:/xampp/php/php.exe -c .dev/php.ini examples/voip.php --session=default

# Inicia UMA chamada real, sem microfone, câmera ou reprodução automática.
C:/xampp/php/php.exe -c .dev/php.ini examples/voip.php --session=default --to=5511999999999 --duration=120

# Aceita explicitamente ofertas individuais e reproduz o áudio recebido.
C:/xampp/php/php.exe -c .dev/php.ini examples/voip.php --session=default --accept --play
```

Substitua o número antes de usar `--to`: essa opção envia uma oferta real. Sem ela, o exemplo só observa ofertas; `--accept` autoriza o aceite de uma oferta individual recebida. O exemplo trata uma chamada e não aceita grupos. Use uma sessão vinculada e pare outro worker que detenha o lock. `--help` e `--preflight` saem antes de construir o cliente WhatsApp; o preflight verifica dependências locais sem abrir dispositivos ou conta.

Os flags de mídia são opt-in: `--microphone="NOME EXATO"`, `--camera="NOME EXATO"`, `--screen=desktop` (Windows) e `--play`. `--camera`/`--screen` são mutuamente exclusivos; `--tone` gera áudio sintético de 440 Hz e exclui `--microphone`. Eles não abrem hardware enquanto a chamada estiver apenas tocando. Câmera/tela aguardam a negociação de vídeo. Sem fonte de áudio explícita, o exemplo anuncia microfone silenciado via `setMuted()` e mantém esse estado no engine. Sem flags de captura/reprodução, processa apenas rede e mídia recebida.

`--transport=webrtc|raw-udp` escolhe o transporte explicitamente quando necessário. O tempo total padrão é 120 segundos, configurável por `--duration=1..3600`; Ctrl+C encerra os processos e a sessão. O loop envia blocos PCM a cada 60 ms e vídeo a 15 fps, limita filas atrasadas e reproduz áudio somente com `--play`. Não registra JIDs, chaves, tokens ou payloads: imprime estados, tipos de falha e contadores. O grupo completo permanece documentado em [VOIP-GRUPOS.md](VOIP-GRUPOS.md).

## Limites e validação

- Compatibilidade com relays, contas e clientes WhatsApp reais ainda exige teste de chamada específico.
- Companion exige o backend separado e um modelo DNNw externo. O ciclo nativo foi testado; qualidade neural não foi avaliada.
- SFrame anunciado por ambos os flags é uma camada adicional de app-data não aplicada pela referência consultada. A biblioteca recusa PT119 nesse caso, mantendo áudio/vídeo SRTP.
- Paridade Reed-Solomon de vídeo é identificada e descartada com contador, conforme o upstream, sem alegar recuperação. RED/FEC do codec e NACK são mecanismos distintos implementados.
- Efeitos AR transmitidos por app-data não incluem renderizador de efeitos; campos não modelados/transcrição não recebem sucesso fictício.
- Não há operação falsa de expulsar/adicionar arbitrariamente participantes a uma chamada em andamento. As APIs seguem oferta, roster recebido e saída própria.

Os testes cobrem RFC 3711, vetores independentes de SRTP/SRTCP/HKDF/STUN, adulteração, replay, rollover, UDP local, ICE/DTLS/SCTP real local, o percurso PCM → Opus → SRTP → SCTP/DTLS local → PCM, rekey/roster, múltiplos relays, RTCP/H.264, oito capturas MLow do WhatsApp Web e áudio C real. Incluem ACK positivo/negativo, timeout/retry limitado, heartbeat, snapshots de mute/mão e processamento de mídia durante uma IQ pendente. Um teste local gera H.264 real pelo FFmpeg, fragmenta/protege/transmite por SRTP sobre SCTP, remonta e verifica o MP4 de 160×120 pelo ffprobe. Testes FFmpeg usam apenas tom/color e arquivos WAV/MP4; nenhum microfone, câmera, tela, áudio de alto-falante ou chamada da conta real foi ativado.

Referência: `packages/voip` e `packages/voip-media` do projeto de referência. As dependências nativas e suas licenças estão em [THIRD_PARTY_NOTICES.md](../THIRD_PARTY_NOTICES.md).
