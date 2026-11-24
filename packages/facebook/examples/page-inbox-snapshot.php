<?php
declare(strict_types=1);

use MVO\Facebook\FacebookConnection;

// Integration helper: including this file does not connect or contact Facebook.
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo <<<'HELP'
MVO Facebook PHP — leitura do inbox da Página selecionada

Carregue vendor/autoload.php na sua aplicação e inclua este arquivo.
Com FacebookConnection conectado, liste as Páginas, receba a escolha do dono
e chame selectPage() com o ID descoberto. Depois execute:

    $snapshot = consultarInboxPaginaSelecionada($facebook);

Para uma conversa, use somente selection_id do snapshot da mesma Página:

    $snapshot = consultarInboxPaginaSelecionada($facebook, $selectionId);

O resultado contém somente conversas/mensagens carregadas pelo documento nativo.
Não é histórico completo nem assinatura realtime. Nenhuma mensagem é enviada.
Mantenha sessão e diretórios privados; não publique HTML, cookies ou tokens.
HELP;
    echo PHP_EOL;
    exit(in_array('--help', $argv, true) ? 0 : 1);
}

/** Call outside event callbacks, after the account owner explicitly selects a discovered Page.
 * @return array<string,mixed>
 */
function consultarInboxPaginaSelecionada(FacebookConnection $facebook, ?string $selectionId = null): array
{
    $page = $facebook->selectedContext()['page'];
    if (!is_array($page)) throw new RuntimeException('Selecione uma Fanpage descoberta por esta conta.');
    $actor = $page['actor_id'] ?? null;
    $mailbox = $page['delegate_page_id'] ?? (($page['type'] ?? null) !== 'profile_plus' ? ($page['id'] ?? null) : null);
    if (!is_string($actor) || !is_string($mailbox)) {
        throw new RuntimeException('A Página não forneceu actor e mailbox reconhecidos.');
    }
    return $facebook->inbox()->snapshot($actor, $mailbox, $selectionId);
}
