<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\WebLoginCurlTransport;

/**
 * Reads a server-provided HTML OTP form without executing scripts.
 * CAA/Relay pages without such a form return null: a mutation is never guessed.
 *
 * @phpstan-type OtpForm array{url:string,fields:array<string,string>,code_field:string,min_length:int,max_length:int}
 */
final class TwoFactorPageParser
{
    /** @return OtpForm|null */
    public function parse(#[\SensitiveParameter] string $html, #[\SensitiveParameter] string $pageUrl): ?array
    {
        WebLoginCurlTransport::validateUrl($pageUrl);
        if (strlen($html) > 16777216) self::unsupported('A página de verificação excedeu o limite local.');
        $document = preg_replace('~<!--.*?-->|<(script|style|textarea|template|noscript)\b[^>]*>.*?</\1\s*>~is', '', $html);
        if ($document === null) self::unsupported('A página não pôde ser analisada dentro dos limites locais.');
        preg_match_all('~<form\b([^>]*)>(.*?)</form\s*>~is', $document, $matches, PREG_SET_ORDER);
        if (count($matches) > 64) self::unsupported('A página contém formulários acima do limite local.');
        $forms = [];
        foreach ($matches as $match) {
            $attributes = self::attributes($match[1]);
            if (strtolower($attributes['method'] ?? 'get') !== 'post') continue;
            if (isset($attributes['enctype']) && strtolower($attributes['enctype']) !== 'application/x-www-form-urlencoded') continue;
            $fields = []; $codes = []; $submits = []; $hasPassword = false;
            preg_match_all('~<(input|button)\b([^>]*)>~is', $match[2], $controls, PREG_SET_ORDER);
            if (count($controls) > 256) self::unsupported('O formulário de verificação excedeu o limite local.');
            foreach ($controls as $control) {
                $a = self::attributes($control[2]);
                if (isset($a['disabled'])) continue;
                $name = $a['name'] ?? '';
                $type = strtolower($a['type'] ?? (strtolower($control[1]) === 'button' ? 'submit' : 'text'));
                if ($type === 'password') $hasPassword = true;
                if ($name === '') continue;
                self::validateName($name);
                if (isset($a['formaction']) || isset($a['formmethod']) || isset($a['formenctype']) || isset($a['form'])) self::unsupported('O formulário exige um destino de controle específico ainda não suportado.');
                if ($type === 'hidden') {
                    if (array_key_exists($name, $fields)) self::unsupported('O formulário de verificação contém campos ambíguos.');
                    $fields[$name] = $a['value'] ?? '';
                } elseif (in_array($type, ['text', 'tel', 'number'], true) && ($name === 'approvals_code' || strtolower($a['autocomplete'] ?? '') === 'one-time-code')) {
                    $codes[] = ['name' => $name, 'min' => $a['minlength'] ?? null, 'max' => $a['maxlength'] ?? null, 'pattern' => $a['pattern'] ?? null];
                } elseif ($type === 'submit') {
                    $submits[] = ['name' => $name, 'value' => $a['value'] ?? ''];
                }
            }
            if ($codes === []) continue;
            if (count($codes) !== 1 || $hasPassword || count($submits) > 1) self::unsupported('A página não forneceu um formulário de código inequívoco.');
            $action = $attributes['action'] ?? '';
            if ($action === '') self::unsupported('O formulário não forneceu o destino da confirmação.');
            $url = WebLoginPageParser::resolveUrl($pageUrl, $action);
            $code = $codes[0];
            if (array_key_exists($code['name'], $fields)) self::unsupported('O campo de código está duplicado no formulário.');
            foreach ($submits as $submit) {
                if (array_key_exists($submit['name'], $fields)) self::unsupported('O botão de confirmação está duplicado no formulário.');
                $fields[$submit['name']] = $submit['value'];
            }
            self::validateFields($fields);
            [$min, $max] = self::lengths($code['min'], $code['max'], $code['pattern']);
            $forms[] = ['url' => $url, 'fields' => $fields, 'code_field' => $code['name'], 'min_length' => $min, 'max_length' => $max];
        }
        if (count($forms) > 1) self::unsupported('A página forneceu mais de um formulário de código.');
        return $forms[0] ?? null;
    }

    /**
     * Only builds a POST from a previously parsed form; no network or session acceptance.
     * @param OtpForm $spec
     * @return array{url:string,fields:array<string,string>}
     */
    public function submission(#[\SensitiveParameter] array $spec, #[\SensitiveParameter] string $code): array
    {
        WebLoginCurlTransport::validateUrl($spec['url']);
        self::validateName($spec['code_field']);
        if (in_array(strtolower($spec['code_field']), ['pass', 'encpass', 'password'], true)) self::unsupported('O campo informado não é um código de verificação.');
        self::validateFields($spec['fields']);
        if ($spec['min_length'] < 6 || $spec['max_length'] > 8 || $spec['min_length'] > $spec['max_length']) self::unsupported('O tamanho do código não é suportado.');
        $code = str_replace(' ', '', trim($code));
        if (!ctype_digit($code) || strlen($code) < $spec['min_length'] || strlen($code) > $spec['max_length']) {
            throw new MessengerException('login_two_factor_invalid_code', 'Informe o código numérico atual do aplicativo autenticador.');
        }
        if (array_key_exists($spec['code_field'], $spec['fields'])) self::unsupported('O campo de código está duplicado no formulário.');
        $fields = $spec['fields'];
        $fields[$spec['code_field']] = $code;
        return ['url' => $spec['url'], 'fields' => $fields];
    }

    /** @param array<string,string> $fields */
    private static function validateFields(#[\SensitiveParameter] array $fields): void
    {
        if (count($fields) > 128) self::unsupported('O formulário contém campos acima do limite local.');
        $size = 0;
        foreach ($fields as $name => $value) {
            self::validateName($name);
            if (in_array(strtolower($name), ['pass', 'encpass', 'password'], true)) self::unsupported('A confirmação exige outro fluxo de autenticação.');
            $size += strlen($name) + strlen($value);
            if (strlen($value) > 65536 || $size > 262144 || str_contains($value, "\0")) self::unsupported('O formulário de verificação excedeu o limite local.');
        }
    }

    private static function validateName(string $name): void
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_.: \[\]-]{0,127}$/D', $name) !== 1) self::unsupported('O formulário contém um nome de campo não suportado.');
    }

    /** @return array{int,int} */
    private static function lengths(?string $minimum, ?string $maximum, ?string $pattern): array
    {
        $min = 6; $max = 8;
        if ($minimum !== null) {
            if (preg_match('/^[6-8]$/D', $minimum) !== 1) self::unsupported('O tamanho do código não é suportado.');
            $min = (int) $minimum;
        }
        if ($maximum !== null) {
            if (preg_match('/^[6-8]$/D', $maximum) !== 1) self::unsupported('O tamanho do código não é suportado.');
            $max = (int) $maximum;
        }
        if ($pattern !== null && $pattern !== '') {
            if (preg_match('~^\^?(?:\[0-9\]|\\\\d)\{([6-8])\}\$?$~D', $pattern, $match) !== 1) self::unsupported('O padrão do código exige outro fluxo de verificação.');
            $min = max($min, (int) $match[1]); $max = min($max, (int) $match[1]);
        }
        if ($min > $max) self::unsupported('O tamanho do código não é suportado.');
        return [$min, $max];
    }

    /** @return array<string,string> */
    private static function attributes(#[\SensitiveParameter] string $text): array
    {
        preg_match_all('~([A-Za-z_:][A-Za-z0-9_.:-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~', $text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $attributes = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (array_key_exists($name, $attributes)) self::unsupported('O formulário contém atributos ambíguos.');
            $attributes[$name] = html_entity_decode($match[2] ?? $match[3] ?? $match[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $attributes;
    }

    private static function unsupported(string $message): never
    {
        throw new MessengerException('login_two_factor_protocol_unavailable', $message);
    }
}
