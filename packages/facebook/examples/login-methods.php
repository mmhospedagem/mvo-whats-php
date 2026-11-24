<?php
declare(strict_types=1);

use MVO\Facebook\FacebookConnection;
use MVO\Messenger\MessengerException;

/*
 * Integration example only. The consuming application loads vendor/autoload.php,
 * owns the connection and supplies its private credential/verification UI.
 * Including this file does not log in, create storage or read browser cookies.
 */
if (PHP_SAPI === 'cli' && realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    echo <<<'HELP'
MVO Facebook PHP — escolher o método de verificação

Uso:
  php packages/facebook/examples/login-methods.php --help

Este arquivo fornece a função loginFacebookUsingMethods() para a aplicação PHP.
Carregue vendor/autoload.php e inclua este arquivo. Forneça FacebookConnection,
e-mail/senha de entrada privada e um callback de decisão. A função não abre UI.
Não coloque senha ou código em argumentos, logs, mensagens ou código público.

O callback recebe loginChallenge(), uma projeção segura, e devolve uma ação:
  ['action' => 'refresh']                         consultar alternativas
  ['action' => 'method', 'method_id' => $id]       solicitar a escolhida
  ['action' => 'code', 'code' => $codigo]          confirmar código privado
  ['action' => 'check']                           conferir aprovação no app
  ['action' => 'cancel']                          devolver controle à aplicação

Use somente IDs atuais devolvidos pelo servidor. A ação method pode provocar
SMS/e-mail ou pedido de aprovação: execute após uma escolha explícita do dono.
Cada ação check é uma decisão do dono; não há repetição automática de pedidos.
true significa identidade confirmada e sessão salva; chame connect() depois.
false devolve uma tentativa pendente ao chamador, que decide continuar ou stop().
Exceções não são convertidas em sucesso; nenhum código é repetido automaticamente.
CAPTCHA/checkpoint exige a continuação humana separada documentada. Mantenha o
mesmo processo/objeto/lock; stop(), reinício e expiração cancelam o desafio.

Esta integração é do checkout em desenvolvimento. Testes locais não comprovam
entrega de SMS/e-mail ou aprovação real por aplicativo. Não envia mensagens.
HELP;
    echo PHP_EOL;
    exit(in_array('--help', $argv, true) ? 0 : 1);
}

/**
 * One account, one owner. Call outside listeners; keep the same object/process.
 * The application owns private inputs and cancellation/expiry handling.
 *
 * @param callable(array<string,mixed>):array<string,mixed> $nextAction
 * @return bool True only after identity verification and session persistence.
 */
function loginFacebookUsingMethods(
    FacebookConnection $facebook,
    #[SensitiveParameter] string $email,
    #[SensitiveParameter] string $password,
    callable $nextAction,
    int $maxActions = 20,
): bool {
    if ($maxActions < 1 || $maxActions > 100) {
        throw new InvalidArgumentException('Use de 1 a 100 decisões explícitas por chamada.');
    }

    try {
        try {
            $facebook->login($email, $password);
            return true;
        } catch (MessengerException $error) {
            $challenge = $facebook->loginChallenge();
            if ($challenge === null || $challenge['type'] !== 'two_factor') {
                throw $error;
            }
        }
    } finally {
        if ($password !== '') sodium_memzero($password);
        unset($email, $password);
    }

    for ($decision = 0; $decision < $maxActions; ++$decision) {
        $challenge = $facebook->loginChallenge();
        if ($challenge === null || $challenge['type'] !== 'two_factor') {
            return false;
        }
        $action = $nextAction($challenge);
        switch ($action['action'] ?? null) {
            case 'refresh':
                $facebook->refreshLoginMethods();
                break;
            case 'method':
                if (!is_string($action['method_id'] ?? null) || $action['method_id'] === '') {
                    throw new InvalidArgumentException('Escolha um ID de método atual.');
                }
                $facebook->requestLoginMethod($action['method_id']);
                break;
            case 'code':
                if (!($challenge['ready'] ?? false) || !($challenge['requires_code'] ?? false)
                    || !is_string($action['code'] ?? null) || $action['code'] === '') {
                    throw new InvalidArgumentException('O desafio atual não aceita esse código.');
                }
                $code = $action['code'];
                unset($action['code']);
                try {
                    $facebook->confirmLogin($code);
                } finally {
                    if ($code !== '') sodium_memzero($code);
                    unset($code);
                }
                return true;
            case 'check':
                if (!($challenge['can_check'] ?? false)) {
                    throw new InvalidArgumentException('A tentativa não aguarda aprovação consultável.');
                }
                if ($facebook->checkLoginApproval()) return true;
                break;
            case 'cancel':
                return false;
            default:
                throw new InvalidArgumentException('Decisão de verificação não reconhecida.');
        }
        unset($action);
    }
    return false;
}
