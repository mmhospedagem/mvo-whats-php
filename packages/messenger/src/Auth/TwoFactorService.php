<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\{WebLoginCurlTransport, WebLoginResponse, WebLoginTransport};

/** Confirms a user-entered OTP in the existing in-memory login context; never accepts a session itself. */
final class TwoFactorService
{
    private const OPERATION = 'useTwoFactorLoginValidateCodeMutation';
    private const COMPONENTS = [self::OPERATION . '_facebookRelayOperation', self::OPERATION . '.graphql', 'TwoFactorLoginMethodWithCode.react', 'TwoFactorLoginMethodTOTP.react', 'TwoStepVerificationRoot.react'];

    public function __construct(private readonly WebLoginTransport $transport) {}

    /** Returns only the server-selected native method enum; never a context, token or identity. */
    public function method(#[\SensitiveParameter] WebLoginResponse $response, #[\SensitiveParameter] string $url): ?string
    {
        WebLoginCurlTransport::validateUrl($url);
        if ($response->status !== 200) return null;
        $modules = []; $methods = []; $nodes = 0;
        foreach (self::documents($response->body) as $data) self::walk($data, $modules, $methods, $nodes);
        if (count($methods) > 1) self::unavailable();
        return $methods === [] ? null : (string) array_key_first($methods);
    }

    /**
     * Selects only resources mapped to the actual two-factor components.
     * Merges Bootloader compMap/rsrcMap with data-bootloader-hash script tags,
     * including already-loaded combined bundles absent from rsrcMap.
     * It does not traverse unrelated deferred components or fetch any URL.
     * @return list<string>
     */
    public function bundleUrls(#[\SensitiveParameter] string $html): array
    {
        $resources = []; $components = []; $nodes = 0;
        foreach (self::documents($html) as $data) self::resourceMaps($data, $resources, $components, $nodes);
        preg_match_all('~<script\b([^>]*)>~is', $html, $tags);
        foreach ($tags[1] as $tag) {
            $src = self::attribute($tag, 'src');
            $hash = self::attribute($tag, 'data-bootloader-hash');
            if ($src === null || $hash === null || !self::publicScriptUrl($src)) continue;
            if (isset($resources[$hash]) && $resources[$hash] !== $src) self::unavailable();
            $resources[$hash] = $src;
        }
        $urls = [];
        foreach (self::COMPONENTS as $name) {
            foreach ($components[$name] ?? [] as $resource) {
                if (isset($resources[$resource])) $urls[$resources[$resource]] = true;
                if (count($urls) > 32) self::unavailable();
            }
        }
        return array_keys($urls);
    }

    /**
     * Private continuation spec. The caller must never expose it in API responses or persist it.
     * Public scripts are the actual current page's downloaded bundle bytes, without execution.
     * A missing mutation, CSRF token, offered TOTP method or context returns null; no fallback ID.
     *
     * @param list<string> $publicScripts
     * @return array<string,mixed>|null
     */
    public function prepare(#[\SensitiveParameter] WebLoginResponse $response, #[\SensitiveParameter] string $url, #[\SensitiveParameter] array $publicScripts = []): ?array
    {
        WebLoginCurlTransport::validateUrl($url);
        $htmlForm = (new TwoFactorPageParser())->parse($response->body, $url);
        if ($htmlForm !== null) return ['kind' => 'html', 'form' => $htmlForm];
        if ($response->status !== 200 || strlen($response->body) > 16777216 || count($publicScripts) > 64) return null;
        $operation = self::operationId([$response->body, ...$publicScripts]);
        if ($operation === null) return null;
        $query = self::query($url);
        if (($query['flow'] ?? '') !== 'two_factor_login' || !isset($query['encrypted_context']) || $query['encrypted_context'] === '') return null;
        $modules = []; $methods = []; $nodes = 0;
        foreach (self::documents($response->body) as $data) self::walk($data, $modules, $methods, $nodes);
        if (count($methods) > 1) self::unavailable();
        if (!isset($methods['TOTP'])) return null;
        $fields = ['__a' => '1'];
        $identity = $modules['CurrentUserInitialData']['USER_ID'] ?? null;
        if (is_int($identity)) $identity = (string) $identity;
        if (is_string($identity) && preg_match('/^(?:0|[1-9][0-9]{0,24})$/D', $identity)) $fields['__user'] = $identity;
        foreach (['DTSGInitialData', 'DTSGInitData'] as $name) {
            if (is_string($modules[$name]['token'] ?? null) && $modules[$name]['token'] !== '') { $fields['fb_dtsg'] = $modules[$name]['token']; break; }
        }
        if (is_string($modules['LSD']['token'] ?? null) && $modules['LSD']['token'] !== '') $fields['lsd'] = $modules['LSD']['token'];
        if (!isset($fields['fb_dtsg']) && !isset($fields['lsd'])) return null;
        $sprinkle = $modules['SprinkleConfig'] ?? null;
        if (isset($fields['fb_dtsg']) && is_array($sprinkle) && is_int($sprinkle['version'] ?? null) && ($sprinkle['should_randomize'] ?? null) === false && !preg_match('/[^\x20-\x7e]/', $fields['fb_dtsg'])) {
            $fields['jazoest'] = (string) $sprinkle['version'] . array_sum(array_map(ord(...), str_split($fields['fb_dtsg'])));
        }
        $site = $modules['SiteData'] ?? [];
        foreach (['__spin_r' => '__spin_r', '__spin_b' => '__spin_b', '__spin_t' => '__spin_t', 'client_revision' => '__rev', 'haste_session' => '__hs', 'hsi' => '__hsi', 'comet_env' => '__comet_req'] as $source => $target) {
            if (is_string($site[$source] ?? null) || is_int($site[$source] ?? null)) $fields[$target] = (string) $site[$source];
        }
        $origin = 'https://' . parse_url($url, PHP_URL_HOST);
        $endpoint = $modules['RelayAPIConfigDefaults']['graphURI'] ?? $modules['RelayAPIConfig']['graphURI'] ?? null;
        if (!is_string($endpoint) || $endpoint === '') return null;
        $endpoint = WebLoginPageParser::resolveUrl($url, $endpoint);
        if ('https://' . parse_url($endpoint, PHP_URL_HOST) !== $origin) throw new MessengerException('login_unsafe_redirect', 'O destino da confirmação não pertence à origem do desafio.');
        $next = $query['next'] ?? null;
        if ($next !== null && $next !== '') WebLoginPageParser::resolveUrl($url, $next);
        $fields += ['fb_api_caller_class' => 'RelayModern', 'fb_api_req_friendly_name' => self::OPERATION, 'server_timestamps' => 'true', 'doc_id' => $operation];
        return ['kind' => 'graphql', 'url' => $endpoint, 'fields' => $fields, 'variables' => ['encryptedContext' => $query['encrypted_context'], 'flow' => 'TWO_FACTOR_LOGIN', 'method' => 'TOTP', 'maskedContactPoint' => null, 'next_uri' => $next, 'shared_prefs_data' => null, 'trust_this_device' => false]];
    }

    /** @param array<string,mixed> $spec */
    public function submit(#[\SensitiveParameter] array $spec, #[\SensitiveParameter] string $code): WebLoginResponse
    {
        if (($spec['kind'] ?? null) === 'html') {
            if (!is_array($spec['form'] ?? null)) self::unavailable();
            /** @var array{url:string,fields:array<string,string>,code_field:string,min_length:int,max_length:int} $form */
            $form = $spec['form'];
            $post = (new TwoFactorPageParser())->submission($form, $code);
            return $this->transport->request('POST', $post['url'], $post['fields']);
        }
        if (($spec['kind'] ?? null) !== 'graphql' || !is_string($spec['url'] ?? null) || !is_array($spec['fields'] ?? null) || !is_array($spec['variables'] ?? null)) self::unavailable();
        WebLoginCurlTransport::validateUrl($spec['url']);
        $code = str_replace(' ', '', trim($code));
        if (preg_match('/^[0-9]{6}$/D', $code) !== 1) throw new MessengerException('login_two_factor_invalid_code', 'Informe os seis dígitos atuais do aplicativo autenticador.');
        $variables = $spec['variables'];
        if (($variables['method'] ?? null) !== 'TOTP' || ($variables['flow'] ?? null) !== 'TWO_FACTOR_LOGIN' || !is_string($variables['encryptedContext'] ?? null) || $variables['encryptedContext'] === '') self::unavailable();
        $fields = $spec['fields'];
        foreach ($fields as $name => $value) if (!is_string($name) || !is_string($value)) self::unavailable();
        if (($fields['fb_api_req_friendly_name'] ?? null) !== self::OPERATION || !is_string($fields['doc_id'] ?? null) || preg_match('/^[1-9][0-9]{0,24}$/D', $fields['doc_id']) !== 1) self::unavailable();
        $variables['code'] = ['sensitive_string_value' => $code];
        $fields['variables'] = json_encode($variables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return $this->transport->request('POST', $spec['url'], $fields);
    }

    /** @return array{validated:bool,next:?string,user_id:?string} */
    public function result(#[\SensitiveParameter] WebLoginResponse $response, #[\SensitiveParameter] string $pageUrl): array
    {
        if ($response->status === 429) throw new MessengerException('login_rate_limited', 'O Facebook limitou as confirmações; aguarde antes de tentar novamente.');
        if ($response->status !== 200 || strlen($response->body) > 16777216) self::unavailable();
        $results = [];
        foreach (preg_split('/\r?\n/', trim($response->body)) ?: [] as $line) {
            $line = preg_replace('/^for\s*\(;;\);/', '', trim($line));
            try { $data = json_decode($line ?? '', true, 100, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
            catch (\JsonException) { continue; }
            if (is_array($data) && isset($data['errors'])) throw new MessengerException('login_two_factor_rejected', 'O Facebook não aceitou a confirmação do código.');
            if (is_array($data) && isset($data['data']['xfb_two_factor_login_validate_code']) && is_array($data['data']['xfb_two_factor_login_validate_code'])) $results[] = $data['data']['xfb_two_factor_login_validate_code'];
        }
        if (count($results) !== 1 || !is_bool($results[0]['is_code_valid'] ?? null)) self::unavailable();
        $result = $results[0];
        if ($result['is_code_valid'] !== true) throw new MessengerException('login_two_factor_invalid_code', 'O Facebook não confirmou o código; confira o código atual do aplicativo autenticador.');
        $next = $result['next'] ?? null;
        if ($next !== null && (!is_string($next) || $next === '')) self::unavailable();
        if (is_string($next)) $next = WebLoginPageParser::resolveUrl($pageUrl, $next);
        $id = $result['user_id'] ?? null;
        if (is_int($id)) $id = (string) $id;
        if ($id !== null && (!is_string($id) || preg_match('/^[1-9][0-9]{0,24}$/D', $id) !== 1)) self::unavailable();
        return ['validated' => true, 'next' => $next, 'user_id' => $id];
    }

    /** @param list<string> $sources */
    private static function operationId(#[\SensitiveParameter] array $sources): ?string
    {
        $ids = []; $total = 0;
        foreach ($sources as $source) {
            $total += strlen($source);
            if (strlen($source) > 16777216 || $total > 33554432) self::unavailable();
            if (preg_match_all('~__d\(\s*["\']useTwoFactorLoginValidateCodeMutation_facebookRelayOperation["\']\s*,\s*\[\]\s*,.{0,300}?\.exports\s*=\s*["\']([1-9][0-9]{0,24})["\']~s', $source, $matches)) foreach ($matches[1] as $id) $ids[$id] = true;
        }
        if (count($ids) > 1) self::unavailable();
        return $ids === [] ? null : (string) array_key_first($ids);
    }

    /** @return array<string,string> */
    private static function query(#[\SensitiveParameter] string $url): array
    {
        $fields = [];
        foreach (explode('&', parse_url($url, PHP_URL_QUERY) ?? '') as $part) {
            if ($part === '') continue;
            [$name, $value] = array_pad(explode('=', $part, 2), 2, '');
            $name = urldecode($name); $value = urldecode($value);
            if (isset($fields[$name])) self::unavailable();
            $fields[$name] = $value;
        }
        return $fields;
    }

    /** @param array<string,array<mixed>> $modules
     * @param array<string,true> $methods
     */
    private static function walk(#[\SensitiveParameter] mixed $value, #[\SensitiveParameter] array &$modules, array &$methods, int &$nodes, int $depth = 0): void
    {
        if (++$nodes > 100000 || $depth > 64) self::unavailable();
        if (!is_array($value)) return;
        foreach ($value['define'] ?? [] as $definition) {
            if (!is_array($definition) || !is_string($definition[0] ?? null) || !is_array($definition[2] ?? null)) continue;
            $name = $definition[0];
            if (!in_array($name, ['CurrentUserInitialData', 'DTSGInitialData', 'DTSGInitData', 'LSD', 'SiteData', 'SprinkleConfig', 'RelayAPIConfigDefaults', 'RelayAPIConfig'], true)) continue;
            // ServerJS in the native challenge refreshes LSD/SiteData in later
            // payloads. Preserve document order for those mutable modules.
            if (isset($modules[$name]) && $modules[$name] !== $definition[2] && !in_array($name, ['LSD', 'SiteData'], true)) self::unavailable();
            $modules[$name] = $definition[2];
        }
        $method = $value['xfb_two_factor_login_default_method']['method']['method'] ?? null;
        if (is_string($method) && preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $method)) $methods[$method] = true;
        foreach ($value as $child) {
            if (is_array($child)) self::walk($child, $modules, $methods, $nodes, $depth + 1);
            elseif (is_string($child) && strlen($child) <= 16777216 && ($child[0] ?? '') === '{') {
                try { $data = json_decode($child, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
                catch (\JsonException) { continue; }
                if (is_array($data)) self::walk($data, $modules, $methods, $nodes, $depth + 1);
            }
        }
    }

    /** @return \Generator<int,array<mixed>> */
    private static function documents(#[\SensitiveParameter] string $html): \Generator
    {
        if (strlen($html) > 16777216) self::unavailable();
        preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~is', $html, $scripts);
        if (count($scripts[1]) > 2000) self::unavailable();
        foreach ($scripts[1] as $script) {
            $script = trim($script);
            if ($script === '' || !in_array($script[0], ['{', '['], true)) continue;
            try { $data = json_decode($script, true, 100, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
            catch (\JsonException) { continue; }
            if (is_array($data)) yield $data;
        }
    }

    /** @param array<string,string> $resources
     * @param array<string,list<string>> $components
     */
    private static function resourceMaps(#[\SensitiveParameter] mixed $value, array &$resources, array &$components, int &$nodes, int $depth = 0): void
    {
        if (++$nodes > 150000 || $depth > 64) self::unavailable();
        if (!is_array($value)) return;
        if (is_array($value['rsrcMap'] ?? null)) {
            foreach ($value['rsrcMap'] as $hash => $resource) {
                if (!is_string($hash) || !is_array($resource) || ($resource['type'] ?? null) !== 'js' || !is_string($resource['src'] ?? null) || !self::publicScriptUrl($resource['src'])) continue;
                if (isset($resources[$hash]) && $resources[$hash] !== $resource['src']) self::unavailable();
                $resources[$hash] = $resource['src'];
            }
        }
        if (is_array($value['compMap'] ?? null)) {
            foreach ($value['compMap'] as $name => $component) {
                if (!is_string($name) || !in_array($name, self::COMPONENTS, true) || !is_array($component) || !is_array($component['r'] ?? null)) continue;
                $references = array_values(array_filter($component['r'], is_string(...)));
                if (count($references) > 512) self::unavailable();
                if (isset($components[$name])) $references = array_values(array_unique([...$components[$name], ...$references]));
                $components[$name] = $references;
            }
        }
        foreach ($value as $child) if (is_array($child)) self::resourceMaps($child, $resources, $components, $nodes, $depth + 1);
    }

    private static function publicScriptUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) return false;
        $host = strtolower($parts['host'] ?? '');
        $path = $parts['path'] ?? '';
        return ($host === 'static.xx.fbcdn.net' || str_ends_with($host, '.fbcdn.net')) && str_starts_with($path, '/rsrc.php/') && str_ends_with($path, '.js');
    }

    private static function attribute(#[\SensitiveParameter] string $tag, string $name): ?string
    {
        if (!preg_match('~(?:^|\s)' . preg_quote($name, '~') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))~i', $tag, $match, PREG_UNMATCHED_AS_NULL)) return null;
        return html_entity_decode($match[1] ?? $match[2] ?? $match[3] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function unavailable(): never
    {
        throw new MessengerException('login_two_factor_protocol_unavailable', 'A página não forneceu uma confirmação de duas etapas suportada e inequívoca.');
    }

    public function __debugInfo(): array { return ['transport' => '[PRIVATE TWO-FACTOR CONTEXT]']; }
}
