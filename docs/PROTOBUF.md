# Schemas Protobuf

As definições da biblioteca foram atualizadas em **16/10/2026** e conferidas com o build público **2.3000.1050588369** do WhatsApp Web. O pacote inclui o arquivo `.proto`, seu descriptor JSON e metadados com versão, hashes e limites da verificação. A aplicação carrega o descriptor diretamente em PHP.

| Conteúdo distribuído | Quantidade |
|---|---:|
| Mensagens, incluindo submensagens | 771 |
| Enums | 226 |
| Campos | 3.576 |
| Valores de enum | 1.680 |
| Grupos `oneof` | 39 |

Em relação ao schema anterior, foram acrescentados **130 tipos de mensagem, 17 enums, 84 campos em mensagens existentes e 99 valores em enums existentes**. O total preserva também 46 tipos, quatro enums e o campo histórico `Message.newsletterAdminProfileMessageV2`, ausentes do novo corpus extraído. A ausência em um carregamento parcial não comprova remoção do protocolo.

## Novas estruturas

As definições incluem música, prévia de links em Status, publicação agendada em canais, figurinha com áudio, imagem instantânea, solicitação e atualização de localização, ACP2, informações comerciais, capacidades de dispositivos e extensões de histórico/sincronização. Também existem estruturas auxiliares de criptografia e recuperação.

Esses dados ficam disponíveis no codec e no envelope `raw`. Um novo schema permite representar a mensagem; envio, interação e demais funcionalidades ainda dependem da implementação correspondente e do que o WhatsApp aceita para a conta. Definir estruturas auxiliares não implementa automaticamente seus algoritmos.

A biblioteca também oferece métodos para álbuns, quizzes, edição de enquetes/eventos, cartões musicais, figurinha com áudio, localização, temas e novos formatos de status. Consulte [novos recursos de mensagens](PROTOBUF_FEATURES.md) para exemplos, consultas de votos/RSVP, recebimento agrupado e limites de compatibilidade.

```php
use MVO\Protocol\SchemaCodec;

// Consultar a definição de um novo conteúdo.
$music = SchemaCodec::fields('Message')['musicMessage'];

// Serializar uma estrutura de capacidades, sem enviá-la ao WhatsApp.
$wire = SchemaCodec::encode('DeviceCapabilities', [
    'hostWriteMutationSupport' => ['mutationNames' => ['archive']],
]);
$decoded = SchemaCodec::decode('DeviceCapabilities', $wire);
```

## Alternativas e históricos

Campos de um mesmo `oneof` são alternativas. Ao codificar, informe somente uma delas; valores `null` não selecionam uma alternativa. Ao decodificar, vale a última alternativa recebida, inclusive em mensagens aninhadas. Ocorrências consecutivas do mesmo membro de mensagem são mescladas conforme o protocolo.

`Message.ChatSolidColorWallpaper` passa a usar `backgroundLightArgb` e `backgroundDarkArgb`, ambos `fixed32`. Os campos antigos eram strings no mesmo número de campo. Ao receber esse formato histórico, a biblioteca preserva seus bytes em `_unknown`, sem convertê-los em cores numéricas. A exceção é restrita a esses dois campos; outros tipos incorretos continuam sendo rejeitados.

Enums numéricos desconhecidos, campos desconhecidos e números de 64 bits continuam preservados. Bytes permanecem strings binárias PHP; inteiros maiores que `PHP_INT_MAX` usam strings decimais.

## Verificação e atualização

Foram comparados estaticamente **3.016 campos de 612 mensagens e 213 enums** de 28 módulos públicos do WhatsApp Web, sem divergências de wire em relação à atualização. A captura não executou módulos nem utilizou sessões de clientes. Existem módulos carregados posteriormente: 113 tipos do snapshot de origem não estavam nesse bootstrap e permanecem identificados como fora da comparação direta.

Arquivos distribuídos:

- `resources/WAProto.proto`: definições atualizadas e declarações de compatibilidade.
- `resources/WAProto.schema.json`: descriptor usado pelo PHP.
- `resources/WAProto.compatibility.json`: exceções explícitas para wire histórico.
- `resources/WAProto.metadata.json`: versão, hashes, contagens e proveniência.

Após uma atualização revisada do `.proto`, o descriptor pode ser regenerado no ambiente de desenvolvimento:

```powershell
python tools/generate_schema.py
python -m unittest discover -s tests/tools -p "test_generate_schema.py"
composer test
```

O gerador valida nomes, números de campos, referências de tipos e grupos `oneof`. A versão do schema é independente da versão negociada pela conexão. A procedência e as licenças dos arquivos estão em [avisos de terceiros](../THIRD_PARTY_NOTICES.md).
