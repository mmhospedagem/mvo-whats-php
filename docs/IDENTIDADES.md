# Identidades PN, LID e aparelhos

Uma conta pode ser identificada pelo telefone (`5511999999999@s.whatsapp.net`, PN) ou por um identificador opaco (`123456789012345@lid`, LID). O LID não contém o número de telefone. Um sufixo como `:2` identifica um aparelho e deve ser preservado ao trabalhar com sessões criptográficas.

## Consultar números e vínculos

Com o cliente conectado, consulte um lote pelo serviço de perfil:

```php
$items = $client->profile()->getLidsByPhoneNumbers([
    '+55 11 99999-9999',
    '5511888888888@s.whatsapp.net',
]);

foreach ($items as $item) {
    // Correlacione pelo número consultado. O WhatsApp pode corrigir o PN.
    $requested = $item['queriedJid'];
    $canonicalPhone = $item['phoneJid'];
    $lid = $item['lidJid'];
}
```

| Campo | Significado |
| --- | --- |
| `queriedJid` | Identificador consultado, usado para correlacionar a resposta. |
| `phoneJid` | PN normalizado, com a correção canônica do servidor quando informada. Não comprova registro por si só. |
| `lidJid` | LID informado pelo WhatsApp, ou `null`. |
| `exists` | `true` ou `false` quando confirmado pelo protocolo de contatos; `null` quando o servidor não fornece essa informação. Um LID em cache não comprova registro. |
| `invalid` | Entrada inválida; as demais entradas do lote continuam sendo consultadas. |
| `jid`, `pn`, `lid` | Campos de compatibilidade para integrações existentes. |

Uma correção do número pelo servidor pode alterar `phoneJid`, inclusive para números brasileiros. Use `queriedJid` para localizar a solicitação original; não associe contatos pela posição em uma resposta de rede. A biblioteca só persiste associações confirmadas e rejeita vínculos conflitantes.

## Identificadores nas mensagens recebidas

O evento `message` preserva a chave da mensagem e acrescenta alternativas conhecidas após a autenticação do conteúdo:

```php
$client->on('message', static function (array $message): void {
    $key = $message['key'];
    $chat = $key['remoteJid'];
    $alternativeChat = $key['remoteJidAlt'] ?? null;
    $participant = $key['participant'] ?? null;
    $alternativeParticipant = $key['participantAlt'] ?? null;
    $recipient = $key['recipientJid'] ?? null;
    $alternativeRecipient = $key['recipientAlt'] ?? null;
});
```

Os campos alternativos só aparecem quando existe uma associação confirmada. A ausência de `remoteJidAlt` ou `participantAlt` não é um erro. Em grupos, `remoteJid` continua sendo o grupo e `participant` identifica o remetente. Em ecos de outros aparelhos da própria conta, o destinatário da conversa é preservado sem trocar o contato remoto pelo remetente local.

Os mesmos campos são preservados no histórico e nos retornos PHP da biblioteca. Use a chave completa em respostas, reações e operações que referenciem mensagens.

## Mudança de LID

```php
$client->on('mex_notification', static function (array $event): void {
    if (($event['kind'] ?? null) !== 'lid_change') {
        return;
    }
    $oldLid = $event['oldLidJid'];
    $newLid = $event['newLidJid'];
    // Atualize os vínculos da sua aplicação para a mesma pessoa.
});
```

A biblioteca reconhece a notificação de mudança de LID do transporte autenticado e atualiza seus vínculos persistentes. Notificações repetidas ou que tentem reverter uma sucessão não restauram o LID antigo. As ações app-state `LidContact`, `PnForLidChat` e `ShareOwnPn` também alimentam os vínculos de identidade quando autenticadas.

Uma mudança de endereço não autoriza copiar uma ratchet para outro contato nem substituir uma identidade Signal confiada. As chaves antigas são preservadas para mensagens em trânsito; o novo endereço obtém as chaves necessárias pelo protocolo. A fila de saída mantém seus envelopes e referências originais.

## Ressincronizar sessões Signal

```php
$result = $client->message()->syncSignalSession('123456789012345@lid');
// ['jid' => '123456789012345@lid', 'devices' => [...], 'synced' => 2]

// Um aparelho explícito limita a ressincronização a esse aparelho.
$result = $client->message()->syncSignalSession('123456789012345:2@lid');
```

Esse método consulta os bundles dos aparelhos e reconstrói as sessões em uma única transação. Uma falha de validação ou conflito de identidade desfaz todo o lote. O estado anterior necessário para decifrar mensagens atrasadas permanece disponível. Use a operação para recuperação explícita de sessões; ela não exige novo QR nem apaga o vínculo da conta.

Endereços `@hosted` e `@hosted.lid` são normalizados para seus servidores canônicos sem perder o aparelho. Sessões PN e LID seguem seus endereços e aparelhos confirmados.

## Grupos e confirmação do envio

O modo de endereçamento informado pelo grupo é respeitado. Quando essa informação não vem na consulta, a presença de um membro LID orienta a seleção de LID. Se o servidor informar `addressing_mode` no ACK, a confirmação do envio expõe `ack.addressingMode`. O ACK do servidor continua sendo diferente de um recibo de entrega ou leitura.

## Integração com sua aplicação

Use os métodos PHP de resolução de identidades e sincronização Signal descritos acima. A biblioteca opera sobre a sessão autenticada; sua aplicação define autenticação HTTP, isolamento entre clientes, filas e o formato dos endpoints que desejar expor.

## Validação

O [relatório de validação da biblioteca](identities-validation.json) registra o pacote original testado, a suíte Linux e os testes com Redis isolado. Mudanças de LID, recuperação Signal, mensagens e grupos são cobertos por fixtures autenticadas. Esses registros descrevem o snapshot da data informada; verificações da aplicação privada e detalhes de contas conectadas não fazem parte do relatório público.

## Conflitos de identidade Signal em retries

O vínculo PN/LID não substitui a validação da chave criptográfica do aparelho. Se um pedido de retry apresentar uma identidade diferente da confiada, o SDK não aceita a chave, não apaga a sessão e não reenvia automaticamente. A falha aparece em `message_error` com `reason: recipient_retry_failed`, `code: signal_trusted_identity_changed`, `retryStatus: blocked`, `retryable: false`, `retryOutcome: not_executed` e `action: verify_recipient_identity`. O campo `error` é sanitizado, sem endereço do destinatário ou chave.

O bloqueio é por mensagem e endereço exato do aparelho, sobrevive ao reinício e não impede retries de outros destinatários. Verifique a identidade com o destinatário pelos meios apropriados; esta correção não adiciona método de aprovação ou reset de confiança. Consultar o evento não comprova entrega, e o bloqueio não deve ser tratado como sucesso.

Quando o retry já preparou ciphertext e a escrita não foi confirmada, um contador maior não o substitui nem avança novamente o ratchet. As tentativas limitadas conservam o nó cifrado exato; esgotado o limite, o registro fica bloqueado com `retryOutcome: unknown`. Não repita a mensagem como uma nova operação para tentar ocultar essa incerteza.


Os registros bloqueados são removidos por uma varredura local limitada (até 16 por minuto de loop) quando a outbox deixa de existir ou quando o timestamp original da mensagem ultrapassa sete dias. A limpeza não aprova identidades, não retoma envios e não remove ratchets, outbox ou outros pedidos pendentes.
