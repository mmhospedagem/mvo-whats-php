# Recursos de protocolo

## Dicionário binário WAP

`tokens.json` contém o vocabulário fixo usado por `MVO\Protocol\BinaryCodec`:
235 tokens simples e quatro dicionários de 256 entradas. A posição e a grafia de
cada entrada fazem parte do formato binário. O importador de desenvolvimento
`tools/import_reference.py` preserva as tabelas da referência selecionada.

Algumas strings desse vocabulário coincidem com padrões reconhecidos por
verificadores de segredos. A entrada `dictionaries[2][62]`, representada no
protocolo pelos bytes `ee 3e`, corresponde a um desses alertas. Ela é um dado
público do dicionário; o SDK não a utiliza como configuração ou credencial de
autenticação para serviços Google. Essa análise não verifica a validade,
as restrições ou a revogação do valor no provedor de origem.

Não remova, substitua ou desloque essa entrada para silenciar um alerta: isso
alteraria o significado dos nós recebidos. Também não é necessário ocultar o
literal nem desabilitar a detecção de segredos. Para essa ocorrência, manter a
entrada e encerrar o alerta como publicação intencional registra uma decisão de
compatibilidade, sem atestar a segurança do valor no provedor de origem e sem
classificar outras ocorrências automaticamente.

`tests/phpunit/BinaryCodecTest.php` verifica um vetor binário fixo e a
decodificação de todas as entradas com a grafia da tabela. A tabela equivalente
do pacote Messenger tem o mesmo requisito de compatibilidade por índice.
