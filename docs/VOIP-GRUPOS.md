# Chamadas em grupo, vídeo e app-data

O módulo PHP implementa os envelopes de `packages/voip` e `packages/voip-media` do projeto de referência: oferta de grupo, aceite, composição de participantes, `enc_rekey`, saída própria, retomada de chamada joinable, negociação de vídeo, compartilhamento de tela, mão levantada, pedido de mute e reações. Os testes usam participantes simulados e transporte local. Ainda não houve validação desta implementação em uma chamada real do WhatsApp.

## Iniciar, aceitar e sair

```php
$voip = $client->voip();
$call = $voip->offerGroup('123456789@g.us', [
    '5511999999999@s.whatsapp.net',
    '5521999999999@s.whatsapp.net',
]);
$id = $call['callId'];

// Alternativas para uma chamada recebida, executadas pelo loop externo:
$voip->accept($id);
// $voip->reject($id);

$participants = $voip->participants($id);
$voip->leave($id); // Sai somente este aparelho/conta.
$voip->join($id);  // Aceita novamente uma chamada de grupo ainda joinable.
```

`offerGroup()` aceita também entradas `['jid' => '…', 'devices' => ['…:0@lid', '…:2@lid']]`. A resolução de aparelhos usa o bridge Signal do cliente. São aceitos até cinco aparelhos por participante e até 256 participantes no roster. A oferta leva o criador primeiro e é enviada a `callId@call`, com `group_info` sem atributos; não leva `destination`, `enc`, `encopt` nem identidade de dispositivo. O aceite de grupo leva `encopt keygen=2`, sem `enc`.

O roster confirmado só pode vir do canal autenticado do servidor `callId@call`. O parser valida a identidade de cada aparelho, participantes duplicados, mistura de formatos, nack, PID zero e limites. Um roster completo mais recente substitui o anterior; um roster vazio ou transação antiga não apaga participantes. A ordenação usa a transação de `group_info`, depois a do envelope, depois a maior transação de participante e, quando todas faltam, a sequência local seguinte, como na referência.

O `terminate` de um participante retira esse participante e seus aparelhos da composição local. Só `group_call_ended` do servidor encerra o grupo inteiro e elimina a opção de `join()`. Uma saída local, rejeição ou ring-out pode preservar uma chamada joinable. `join()` envia `accept` à chamada existente; não inventa uma nova oferta ou uma composição de participantes.

Ofertas de grupo recebidas enfileiram `preaccept` para `callId@call`; o criador de uma oferta de grupo não envia o preaccept individual. `accept()`/`join()` anunciam primeiro `transport` tipo/round `1/1` ao remetente da oferta e depois enviam o aceite ao servidor da chamada. O grupo começa a enviar heartbeat imediatamente após o aceite; para o criador, isso começa quando o roster confirma seu aparelho e outro participante conectado. A cadência é de dez segundos enquanto o estado é `connecting` ou `connected`, nunca durante o toque ou depois de sair.

A fila mantém somente um heartbeat pendente por chamada. ACK negativo ou ausente causa até três envios totais da mesma stanza ID, sem acumular jobs a cada intervalo. ACK de `call` é correlacionado em `handleNode()` e não significa mídia conectada. O poller continua enviando heartbeat com `tick(false)` durante uma IQ pendente, enquanto adia consultas/resolução/criptografia que poderiam iniciar outra IQ. Limites, timeouts e eventos da fila estão descritos em [VOIP.md](VOIP.md).

## Chaves e autenticação de mídia

O servidor escolhe quem distribui a nova chave com `group_info rekey`. O trabalho fica na fila de `drainPending()` e é executado pelo `tick()` do poller, depois que o handler de entrada retornou. O distribuidor cria uma chave aleatória de 32 bytes e envia um `enc_rekey` Signal separado a cada aparelho dos outros participantes conectados, excluindo os que não aceitaram os termos. Um envio que falha preserva o mesmo ID e ciphertext para a próxima tentativa; não avança o ratchet novamente. O bridge Signal do cliente persiste sua própria operação antes de devolver o ciphertext.

O receptor confirma o recebimento mesmo se a chave não decifrar. Uma chave só é instalada quando o remetente é um aparelho conectado no roster confirmado, o criador confere e a transação corresponde à composição atual. Chaves recebidas antes da oferta/roster ficam em um cache de até 64 chamadas, quatro chaves por chamada e TTL de 30 segundos. Uma retransmissão da mesma chave não reinicia a janela de replay. Chaves conflitantes para a mesma transação são recusadas.

Mudanças nos aparelhos autorizados suspendem a chave antiga até uma nova distribuição. Material de chave revogado não pode ser reutilizado. Cada aparelho deriva a própria chave/salt SRTP da chave compartilhada e de seu JID completo, incluindo `:0`. Recepção, replay e SSRC são independentes por remetente; mídia de um participante removido é recusada imediatamente. Uma chamada só aparece como `connected` depois de receber SRTP/SRTCP autenticado de um membro atual.

## Abrir transporte e processar áudio/vídeo

```php
// Abre rede somente quando a aplicação chama este método explicitamente.
$media = $voip->connectMedia($id); // Companion: WebRTC nativo.

// Para uma identidade primary importada, escolha o transporte explicitamente:
// $media = $voip->connectMedia($id, 'raw-udp');

$plan = $media->streamPlan(); // SSRCs, camadas, aparelhos e PIDs; não contém chaves.
$engine = $media->mediaEngine(); // Codec/feedback nativo opcional.
$engine->sendAudioPcm($samplesMono);
$engine->sendVideoAnnexB($h264Frame, $rtpTimestamp90kHz);
```

`connectMedia()` liga um `RelayPool` aos endpoints UDP anunciados, com WebRTC nativo como padrão de companion e fallback de porta 3480. O WebRTC precisa da extensão FFI e da biblioteca compilada em `native/rtc_bridge.c`; a ausência da dependência produz erro real. `raw-udp` não substitui automaticamente um transporte WebRTC. Credenciais reemitidas são aplicadas pelo poller; o pool só considera um novo caminho provado depois de mídia autenticada.

Nenhum relay é sondado antes desse consentimento explícito, diferentemente da abertura antecipada da referência. Depois da abertura, `relaylatency` usa apenas RTTs reais de alocação/pong respondidos, deduplica o nome do relay e inclui os aparelhos do roster/anunciados/resolvidos. Uma composição de destinos nova produz um novo relatório; um endpoint ainda sem medição não recebe latência fictícia.

`GroupMediaSession` também oferece `connectRelays()`, `connectTransport(RelayTransport)` e `setTransport(send, receive, tick?, close?)` para integração explícita. `protectPayload()` e `handleDatagram()` permitem testar o protocolo sem abrir rede. `poll()` processa no máximo 64 datagramas por rodada, descarta pacotes inválidos e mantém o restante do cliente WhatsApp ativo. Uma falha real de transporte em `VoipService::tick()` encerra apenas a chamada afetada com `transport_failed`.

O plano de saída declara camadas de relay 0 (áudio), 1 (vídeo principal), 2 (vídeo secundário) e 5 (app-data). Elas diferem dos slots usados na derivação de SSRC: áudio 0/1/4, vídeo 2/3/5 e app-data 6. O stream secundário deriva SSRC de `deviceJid_1`. O engine recebe apenas pacotes autenticados dos remetentes configurados, remove seus buffers quando deixam a composição e limpa estado de recepção/retransmissão ao trocar o contexto SRTP.

Uma chamada individual promovida pelo servidor a grupo transfere o transporte já aberto. A antiga chave individual é revogada e o estado aguarda um `enc_rekey` autenticado da composição de grupo antes de voltar a mídia conectada. Isso evita rediscagem e uso da chave anterior como chave compartilhada. Ofertas com roster válido podem representar grupos mesmo sem `group-jid`. Remoção de membros também elimina suas inscrições e estados de vídeo pendentes.

## Controles de vídeo e interação

```php
$voip->requestVideoUpgrade($id);
$voip->setVideoEnabled($id, true); // Depois da negociação.
$voip->setScreenSharing($id, true);
$voip->setHandRaised($id, true);
$voip->requestParticipantMute($id, '123456:2@lid');
$voip->sendReaction($id, '👍');
$voip->sendArEffect($id, 'effect-id');

$voip->setVideoSubscriptions($id, [
    ['participantJid' => '123456:2@lid', 'quality' => 2],
]);
// null restaura a política automática de até quatro câmeras ligadas,
// quando mais de dois participantes estão conectados.
$voip->setVideoSubscriptions($id, null);
```

O pedido de upgrade usa `video state=3` em grupos e `state=11` em chamadas individuais. Em grupo, somente `group_update/av_upgrade av-upgrader=…` do servidor concede o upgrade; `av-upgradable` informa apenas capacidade. Em chamadas individuais, `acceptVideoUpgrade()` e `rejectVideoUpgrade()` respondem a um pedido recebido. Pedidos pendentes expiram em cinco segundos. Compartilhamento anunciado usa versão 2, ocupa o stream da câmera e numera cada início/fim com `screen_share_id`; a aplicação fornece os frames da tela. Um pedido recebido de compartilhamento não é confundido com confirmação de transmissão.

Pedido de mute usa `request-state=1` diretamente ao aparelho alvo. O estado do microfone só muda quando esse participante anuncia `mute-state`; o pedido não o silencia à força. Mão levantada usa `user_action/raise_hand`, com broadcast em grupo.

O estado inicial do microfone é anunciado automaticamente depois da primeira mídia autenticada. Quando um novo membro chega ou passa a conectado, o poller retransmite o mute atual e a mão levantada, se estiver ativa. `setMuted()` e `setHandRaised()` cancelam snapshots anteriores ainda sem ACK antes de anunciar o estado escolhido pela aplicação, para que um retry atrasado não volte a anunciar áudio aberto ou mão levantada.

Reações e efeitos AR viajam no protobuf `wa.voip` de app-data sobre RTP PT119/slot6, protegido com SRTP. Reações começam no contador 1 e são retransmitidas a cada 60 ms por até 600 ms, com nova sequência RTP e mesmo payload/timestamp. O receptor deduplica por remetente/SSRC/transação. `sendArEffect()` transmite a atribuição do efeito; não aplica efeitos gráficos ao vídeo. `sendAppData()` permite outro payload já serializado. Transcrição ao vivo e campos não modelados não são apresentados como funcionalidades implementadas.

Se `sframe` e `sframeRx` estiverem ambos ativos, envio e recepção de PT119/reactions são recusados com erro explícito: essa camada extra de app-data não está implementada. Um flag isolado não a ativa. Áudio e vídeo continuam usando seus contextos SRTP normalmente.

## Eventos e limites

Além dos eventos individuais, estão disponíveis `voip_group_roster`, `voip_group_key_rotated`, `voip_group_participant_left`, `voip_group_media_ready`, `voip_group_media_packet`, `voip_video_state`, `voip_video_upgrade`, `voip_screen_share`, `voip_hand_raised`, `voip_mute_requested`, `voip_participant_mute`, `voip_app_data` e `voip_media_error`. Chaves SRTP/Signal não aparecem nos eventos ou em `getCall()`. `relayDescriptors()` contém credenciais de relay e não deve ser publicado.

O protocolo de referência consultado não fornece uma operação pública confiável de expulsar outro participante ou adicionar arbitrariamente participantes a uma chamada já em andamento. Por isso esta API oferece os participantes da oferta inicial, composição recebida e saída própria. `peer_state/invisible` não foi reutilizado como uma falsa operação de kick. A captura/produção de frames, a reprodução no dispositivo e a política da interface pertencem à aplicação. A aceitação por contas e aplicativos WhatsApp reais precisa ser validada separadamente.
