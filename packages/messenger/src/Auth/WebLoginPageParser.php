<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\WebLoginCurlTransport;

/** Reads the actual form and inert ServerJS JSON argument literals; never evaluates JavaScript. */
final class WebLoginPageParser
{
    /** @return array{url:string,fields:array<string,string>,password_field:string,key_id:int,public_key:string} */
    public function parse(#[\SensitiveParameter] string $html, #[\SensitiveParameter] string $pageUrl): array
    {
        WebLoginCurlTransport::validateUrl($pageUrl);
        if (strlen($html) > 16777216) throw new MessengerException('login_response_too_large', 'A página de login excedeu o limite local.');
        $forms = [];
        preg_match_all('~<form\b([^>]*)>(.*?)</form\s*>~is', $html, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $attrs = self::attributes($match[1]);
            if (($attrs['id'] ?? '') !== 'login_form' || strtolower($attrs['method'] ?? '') !== 'post') continue;
            $fields = []; $hasEmail = false; $hasPassword = false;
            preg_match_all('~<input\b([^>]*)>~is', $match[2], $inputs, PREG_SET_ORDER);
            foreach ($inputs as $input) {
                $a = self::attributes($input[1]);
                $name = $a['name'] ?? '';
                if ($name === '' || isset($a['disabled'])) continue;
                if ($name === 'email') $hasEmail = true;
                if ($name === 'pass' && strtolower($a['type'] ?? '') === 'password') $hasPassword = true;
                if (strtolower($a['type'] ?? '') === 'hidden') {
                    if (array_key_exists($name, $fields)) throw new MessengerException('login_flow_unavailable', 'O formulário de login contém campos ambíguos.');
                    $fields[$name] = $a['value'] ?? '';
                }
            }
            preg_match_all('~<button\b([^>]*)>~is', $match[2], $buttons, PREG_SET_ORDER);
            foreach ($buttons as $button) {
                $a = self::attributes($button[1]);
                if (($a['name'] ?? '') === 'login' && strtolower($a['type'] ?? 'submit') === 'submit') $fields['login'] = $a['value'] ?? '';
            }
            if (!$hasEmail || !$hasPassword) continue;
            $action = $attrs['action'] ?? '';
            if ($action === '') continue;
            $url = self::resolveUrl($pageUrl, $action);
            $path = parse_url($url, PHP_URL_PATH) ?? '';
            if (!str_starts_with($path, '/login/') && $path !== '/login.php') throw new MessengerException('login_flow_unavailable', 'O destino do formulário de login não foi reconhecido.');
            $forms[] = ['url' => $url, 'fields' => $fields];
        }
        if (count($forms) !== 1) throw new MessengerException('login_flow_unavailable', 'A página não forneceu um formulário Web de login suportado; o fluxo CAA/Relay sem formulário ainda não foi implementado.');
        $form = $forms[0];
        $keys = []; $lsd = null; $sprinkle = null;
        foreach (self::entries($html) as $entry) {
            $name = explode('@', $entry[0])[0];
            if ($name === 'LSD' && is_array($entry[2] ?? null) && is_string($entry[2]['token'] ?? null)) $lsd = $entry[2]['token'];
            if ($name === 'SprinkleConfig' && is_array($entry[2] ?? null)) $sprinkle = $entry[2];
            if (($entry[1] ?? null) !== 'init' || !is_array($entry[3] ?? null)) continue;
            $args = $entry[3];
            if ($name === 'MWLoginEncryptPasswordOnSubmit') {
                $config = $args[0] ?? null;
                if (is_array($config)) $keys[] = ['field' => 'pass', 'id' => $config['keyId'] ?? null, 'key' => $config['publicKey'] ?? null];
            } elseif ($name === 'LoginFormController') {
                $config = $args[4]['pubKey'] ?? null;
                if (is_array($config)) $keys[] = ['field' => 'encpass', 'id' => $config['keyId'] ?? null, 'key' => $config['publicKey'] ?? null];
            }
        }
        if (count($keys) !== 1 || !is_int($keys[0]['id']) || $keys[0]['id'] < 0 || $keys[0]['id'] > 255 ||
            !is_string($keys[0]['key']) || preg_match('/^[a-fA-F0-9]{64}$/D', $keys[0]['key']) !== 1) {
            throw new MessengerException('login_encryption_unavailable', 'A página não forneceu uma chave de criptografia de senha inequívoca.');
        }
        $fields = $form['fields'];
        if (array_key_exists('lsd', $fields) && $fields['lsd'] === '' && is_string($lsd)) $fields['lsd'] = $lsd;
        if (array_key_exists('lsd', $fields) && $fields['lsd'] === '') throw new MessengerException('login_flow_unavailable', 'A página não forneceu o token do formulário de login.');
        if (array_key_exists('jazoest', $fields) && $fields['jazoest'] === '') {
            $token = $fields['lsd'] ?? null;
            if (!is_string($token) || !is_array($sprinkle) || ($sprinkle['should_randomize'] ?? null) !== false || !is_int($sprinkle['version'] ?? null) ||
                preg_match('/^[\x21-\x7e]+$/D', $token) !== 1) throw new MessengerException('login_flow_unavailable', 'A configuração dinâmica do formulário de login não está disponível.');
            $sum = 0; foreach (str_split($token) as $character) $sum += ord($character);
            $fields['jazoest'] = (string) $sprinkle['version'] . $sum;
        }
        unset($fields['pass'], $fields['encpass'], $fields['email']);
        // The current Messenger handler disables the submit button before
        // form.submit(); that button is absent from the browser's entry list.
        if($keys[0]['field']==='pass')unset($fields['login']);
        return ['url' => $form['url'], 'fields' => $fields, 'password_field' => $keys[0]['field'], 'key_id' => $keys[0]['id'], 'public_key' => $keys[0]['key']];
    }

    public function userId(#[\SensitiveParameter] string $html): ?string
    {
        $ids = [];
        foreach (self::entries($html) as $entry) {
            if ($entry[0] !== 'CurrentUserInitialData' || !is_array($entry[2] ?? null)) continue;
            $id = $entry[2]['USER_ID'] ?? null;
            if (is_int($id)) $id = (string) $id;
            if (is_string($id) && preg_match('/^[1-9][0-9]{0,24}$/D', $id) === 1) $ids[$id] = true;
        }
        if (count($ids) > 1) throw new MessengerException('login_identity_mismatch', 'A página retornou identidades de login conflitantes.');
        return $ids === [] ? null : (string) array_key_first($ids);
    }

    public static function resolveUrl(#[\SensitiveParameter] string $base, #[\SensitiveParameter] string $location): string
    {
        if (str_starts_with($location, '/') && !str_starts_with($location, '//')) {
            $location = 'https://' . parse_url($base, PHP_URL_HOST) . $location;
        }
        WebLoginCurlTransport::validateUrl($location);
        return $location;
    }

    /** @return array<string,string> */
    private static function attributes(#[\SensitiveParameter] string $text): array
    {
        preg_match_all('~([A-Za-z_:][A-Za-z0-9_.:-]*)(?:\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+)))?~', $text, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $attributes = [];
        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (array_key_exists($name, $attributes)) throw new MessengerException('login_flow_unavailable', 'O formulário contém atributos ambíguos.');
            $attributes[$name] = html_entity_decode($match[2] ?? $match[3] ?? $match[4] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
        }
        return $attributes;
    }

    /** @return list<array<mixed>> */
    private static function entries(#[\SensitiveParameter] string $html): array
    {
        if (strlen($html) > 16777216) throw new MessengerException('login_response_too_large', 'A página de login excedeu o limite local.');
        preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~is', $html, $scripts);
        $entries = [];
        foreach ($scripts[1] as $script) {
            preg_match_all('~\[\s*"(?:MWLoginEncryptPasswordOnSubmit[^"\\r\\n]*|LoginFormController[^"\\r\\n]*|LSD|SprinkleConfig|CurrentUserInitialData)"\s*,~', $script, $matches, PREG_OFFSET_CAPTURE);
            foreach ($matches[0] as [$literal, $offset]) {
                if (count($entries) >= 1000) throw new MessengerException('login_response_too_large', 'A página tem configurações de login acima do limite.');
                $json = self::arrayLiteral($script, $offset);
                if ($json === null) continue;
                try { $entry = json_decode($json, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
                catch (\JsonException) { continue; }
                if (is_array($entry) && is_string($entry[0] ?? null)) $entries[] = $entry;
            }
        }
        return $entries;
    }

    private static function arrayLiteral(#[\SensitiveParameter] string $text, int $start): ?string
    {
        $stack = []; $quoted = false; $escape = false;
        $end = min(strlen($text), $start + 262144);
        for ($i = $start; $i < $end; ++$i) {
            $character = $text[$i];
            if ($quoted) {
                if ($escape) $escape = false;
                elseif ($character === '\\') $escape = true;
                elseif ($character === '"') $quoted = false;
                continue;
            }
            if ($character === '"') { $quoted = true; continue; }
            if ($character === '[' || $character === '{') { $stack[] = $character; if (count($stack) > 64) return null; }
            elseif ($character === ']' || $character === '}') {
                $open = array_pop($stack);
                if (($character === ']' && $open !== '[') || ($character === '}' && $open !== '{')) return null;
                if ($stack === []) return substr($text, $start, $i - $start + 1);
            }
        }
        return null;
    }
}
