<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\{WebLoginCurlTransport, WebLoginResponse, WebLoginTransport};

/**
 * Native CAA method picker. All returned specs are PRIVATE memory-only login
 * state; only methods()/selectedInfo() are suitable for application projections.
 * A notification or approval result never authenticates an account by itself.
 */
final class TwoFactorMethodService
{
    private const PICKER = 'TwoStepVerificationChallengePickerDialogQuery';
    private const SEND = 'useTwoStepVerificationSendCodeMutation';
    private const CODE = 'useTwoFactorLoginValidateCodeMutation';
    private const APPROVAL = 'TwoStepVerificationUpdateLoginMutation';
    private const TYPES = ['TOTP' => 'totp', 'SMS' => 'sms', 'EMAIL' => 'email', 'APPROVE_FROM_ANOTHER_DEVICE' => 'app_approval'];
    private const COMPONENTS = [
        'TwoStepVerificationRoot.react', 'TwoStepVerificationChallengePickerDialog.react',
        'TwoStepVerificationChallengePickerDialog.entrypoint', 'TwoFactorLoginMethodPicker.react',
        'TwoStepVerificationChallengeEnterCode.react', 'TwoStepVerificationUpdateLogin.react',
        'TwoStepVerificationChallengeApproveFromAnotherDevice.react',
        'TwoStepVertificationNavigation',
        'XCometTwoStepVerificationTwoFactorRememberBrowserControllerRouteBuilder',
        'XPolarisRememberBrowserControllerRouteBuilder',
        self::PICKER . '_facebookRelayOperation', self::PICKER . '.graphql',
        self::SEND . '_facebookRelayOperation', self::SEND . '.graphql',
        self::CODE . '_facebookRelayOperation', self::CODE . '.graphql',
        self::APPROVAL . '_facebookRelayOperation', self::APPROVAL . '.graphql',
    ];

    public function __construct(private readonly WebLoginTransport $transport) {}

    /** @param list<string> $publicScripts
     * @return array<string,mixed>|null PRIVATE context, never persist or expose.
     */
    public function prepare(#[\SensitiveParameter] WebLoginResponse $response, #[\SensitiveParameter] string $url, #[\SensitiveParameter] array $publicScripts = []): ?array
    {
        WebLoginCurlTransport::validateUrl($url);
        if ($response->status !== 200 || strlen($response->body) > 16777216 || count($publicScripts) > 64) return null;
        $query = self::query($url);
        if (($query['flow'] ?? null) !== 'two_factor_login' || !is_string($query['encrypted_context'] ?? null) || $query['encrypted_context'] === '') return null;
        $modules = []; $native = []; $nodes = 0;
        foreach (self::documents($response->body) as $data) self::walk($data, $modules, $native, $nodes);
        if (!isset($native['xfb_two_factor_login_default_method']['method']) || !is_array($native['xfb_two_factor_login_default_method']['method'])) return null;
        $default = $native['xfb_two_factor_login_default_method']['method'];
        if (!is_string($default['method'] ?? null) || preg_match('/^[A-Z][A-Z0-9_]{0,63}$/D', $default['method']) !== 1 || str_contains($default['method'], 'CAPTCHA')) return null;
        $fields = self::fields($modules);
        if ($fields === null) return null;
        $endpoint = $modules['RelayAPIConfigDefaults']['graphURI'] ?? $modules['RelayAPIConfig']['graphURI'] ?? null;
        if (!is_string($endpoint) || $endpoint === '') return null;
        $endpoint = WebLoginPageParser::resolveUrl($url, $endpoint);
        self::sameOrigin($url, $endpoint);
        $next = $query['next'] ?? null;
        if ($next === '') $next = null;
        if ($next !== null) { $next = WebLoginPageParser::resolveUrl($url, $next); self::sameOrigin($url, $next); }
        $operations = self::operations([$response->body, ...$publicScripts]);
        $state = ['url' => $endpoint, 'page_url' => $url, 'fields' => $fields, 'context' => $query['encrypted_context'],
            'next' => $next, 'approval_next' => self::approvalNext($url, $query, $native, [$response->body, ...$publicScripts]),
            'operations' => $operations, 'items' => [], 'selected' => null,
            'poll_interval' => 5, 'next_poll_at' => 0.0, 'sent_at' => [], 'cooldowns' => [], 'discovered' => false];
        $state = self::mergeNative($state, $native, false);
        $defaultKey = self::key($default['method'], self::contact($default['method_representation'] ?? null));
        foreach ($state['items'] as $id => $item) if ($item['key'] === $defaultKey) $state['selected'] = $id;
        return $state;
    }

    /** Only safe, supported, server-offered methods. Opaque IDs are scoped to this in-memory challenge.
     * @param array<string,mixed> $spec
     * @return list<array<string,mixed>>
     */
    public function methods(#[\SensitiveParameter] array $spec): array
    {
        $result = [];
        foreach ($spec['items'] ?? [] as $id => $item) {
            $method = $item['method'];
            $code = isset($spec['operations'][self::CODE]) && in_array($method, ['TOTP', 'SMS', 'EMAIL'], true);
            $approval = $method === 'APPROVE_FROM_ANOTHER_DEVICE' && isset($spec['operations'][self::APPROVAL]);
            $request = $method === 'TOTP' ? $code : ($method === 'APPROVE_FROM_ANOTHER_DEVICE' ? $approval : isset($spec['operations'][self::SEND]) && $code);
            $disabled = $item['disabled'] === true;
            $last = $spec['sent_at'][$item['key']] ?? null;
            $remaining = is_float($last) || is_int($last) ? max(0, (int) ceil($last + ($spec['cooldowns'][$method] ?? 180) - microtime(true))) : 0;
            $result[] = ['id' => $id, 'type' => self::TYPES[$method], 'masked_contact' => self::masked($item['contact']),
                'selected' => ($spec['selected'] ?? null) === $id, 'disabled' => $disabled,
                'request_supported' => !$disabled && $request, 'code_supported' => !$disabled && $code,
                'approval_supported' => !$disabled && $approval, 'retry_after' => $remaining];
        }
        return $result;
    }

    /** Safe selected method, or an empty projection.
     * @param array<string,mixed> $spec
     * @return array<string,mixed>
     */
    public function selectedInfo(#[\SensitiveParameter] array $spec): array
    {
        foreach ($this->methods($spec) as $item) if ($item['selected']) return $item + [
            'selected_method' => $item['id'], 'requires_code' => $item['code_supported'],
            'can_check' => $item['approval_supported'], 'poll_interval' => $spec['poll_interval'],
        ];
        return [];
    }

    /** @param array<string,mixed> $spec */
    public function canRefresh(#[\SensitiveParameter] array $spec): bool { return isset($spec['operations'][self::PICKER]); }

    /** Explicit method-picker query; never sends a code or chooses a different method.
     * @param array<string,mixed> $spec
     * @return array<string,mixed> PRIVATE updated context.
     */
    public function discover(#[\SensitiveParameter] array $spec): array
    {
        $response = $this->call($spec, self::PICKER, ['encryptedContext' => $spec['context'], 'loginAccountType' => 'FACEBOOK']);
        $data = self::data($response);
        if (!is_array($data['xfb_two_factor_login_methods']['methods'] ?? null) || !array_is_list($data['xfb_two_factor_login_methods']['methods'])) self::unavailable();
        return self::mergeNative($spec, $data, true);
    }

    /** Select an actually offered method. SMS/EMAIL send exactly one native notification.
     * App approval selection is local, as in the native picker; approval() checks the pending request.
     * @param array<string,mixed> $spec
     * @return array<string,mixed> PRIVATE updated context.
     */
    public function request(#[\SensitiveParameter] array $spec, string $methodId): array
    {
        if (!is_array($spec['items'][$methodId] ?? null)) throw new MessengerException('login_two_factor_method_unavailable', 'Selecione uma das formas de verificação oferecidas para esta tentativa.');
        $item = $spec['items'][$methodId];
        $summary = null;
        foreach ($this->methods($spec) as $method) if ($method['id'] === $methodId) $summary = $method;
        if ($summary === null || !$summary['request_supported']) throw new MessengerException('login_two_factor_method_unavailable', 'Esta forma de verificação não está disponível no contexto atual.');
        if (in_array($item['method'], ['SMS', 'EMAIL'], true)) {
            if ($summary['retry_after'] > 0) throw new MessengerException('login_two_factor_cooldown', 'Aguarde o prazo informado antes de solicitar outro código.');
            $response = $this->call($spec, self::SEND, ['challenge' => $item['method'], 'encryptedContext' => $spec['context'], 'maskedContactPoint' => $item['contact']]);
            $data = self::data($response);
            $result = $data['xfb_two_step_verification_send_notification'] ?? null;
            if (!is_array($result) || !is_bool($result['is_success'] ?? null)) self::unavailable();
            if (!$result['is_success']) throw new MessengerException('login_two_factor_send_rejected', 'O Facebook não confirmou o envio do código pela forma escolhida.');
            $spec['sent_at'][$item['key']] = microtime(true);
        }
        $spec['selected'] = $methodId;
        return $spec;
    }

    /** PRIVATE code continuation.
     * @param array<string,mixed> $spec
     * @return array<string,mixed>|null
     */
    public function codeSpec(#[\SensitiveParameter] array $spec): ?array
    {
        $item = $spec['items'][$spec['selected'] ?? ''] ?? null;
        if (!is_array($item) || $item['disabled'] || !in_array($item['method'], ['TOTP', 'SMS', 'EMAIL'], true) || !isset($spec['operations'][self::CODE])) return null;
        return ['kind' => 'graphql', 'url' => $spec['url'], 'fields' => self::operationFields($spec, self::CODE),
            'variables' => ['encryptedContext' => $spec['context'], 'flow' => 'TWO_FACTOR_LOGIN', 'method' => $item['method'],
                'maskedContactPoint' => $item['contact'], 'next_uri' => $spec['next'], 'shared_prefs_data' => null, 'trust_this_device' => false]];
    }

    /** One explicitly requested native approval-state check. Does not save cookies or accept a session.
     * @param array<string,mixed> $spec
     * @return array{approved:bool,next:?string,user_id:null,state:array<string,mixed>,status:string,interval_seconds:int}
     */
    public function approval(#[\SensitiveParameter] array $spec): array
    {
        $selected = $this->selectedInfo($spec);
        if (($selected['approval_supported'] ?? false) !== true) throw new MessengerException('login_two_factor_method_unavailable', 'Selecione a aprovação em outro dispositivo oferecida pelo Facebook antes de consultar.');
        $interval = $spec['poll_interval'];
        if (($spec['next_poll_at'] ?? 0.0) > microtime(true)) return ['approved' => false, 'next' => null, 'user_id' => null, 'state' => $spec, 'status' => 'pending', 'interval_seconds' => $interval];
        $data = self::data($this->call($spec, self::APPROVAL, ['encrypted_context' => $spec['context'], 'trust_this_device' => false]));
        $status = $data['xfb_two_step_verification_complete_if_approval_state_allowed']['state'] ?? null;
        if (!in_array($status, ['ALLOWED', 'DENIED', 'EXPIRED', 'PENDING'], true)) self::unavailable();
        $spec['next_poll_at'] = microtime(true) + $interval;
        if ($status === 'DENIED') throw new MessengerException('login_two_factor_approval_denied', 'O login foi recusado no outro dispositivo.');
        if ($status === 'EXPIRED') throw new MessengerException('login_challenge_expired', 'O pedido de aprovação no outro dispositivo expirou.');
        return ['approved' => $status === 'ALLOWED', 'next' => $status === 'ALLOWED' ? $spec['approval_next'] : null,
            'user_id' => null, 'state' => $spec, 'status' => $status === 'ALLOWED' ? 'approved' : 'pending', 'interval_seconds' => $interval];
    }

    /** @return list<string> Current two-factor Bootloader resources; never fetches or executes them. */
    public function bundleUrls(#[\SensitiveParameter] string $html): array
    {
        $resources = []; $components = []; $nodes = 0;
        foreach (self::documents($html) as $data) self::resourceMaps($data, $resources, $components, $nodes);
        preg_match_all('~<script\b([^>]*)>~is', $html, $tags);
        foreach ($tags[1] as $tag) {
            $src = self::attribute($tag, 'src'); $hash = self::attribute($tag, 'data-bootloader-hash');
            if ($src === null || $hash === null || !self::publicScriptUrl($src)) continue;
            if (isset($resources[$hash]) && $resources[$hash] !== $src) self::unavailable();
            $resources[$hash] = $src;
        }
        $urls = [];
        foreach (self::COMPONENTS as $name) foreach ($components[$name] ?? [] as $hash) {
            if (isset($resources[$hash])) $urls[$resources[$hash]] = true;
            if (count($urls) > 32) self::unavailable();
        }
        return array_keys($urls);
    }

    /** @param array<string,mixed> $spec
     * @param array<string,mixed> $variables
     */
    private function call(#[\SensitiveParameter] array $spec, string $operation, #[\SensitiveParameter] array $variables): WebLoginResponse
    {
        self::sameOrigin($spec['page_url'], $spec['url']);
        $fields = self::operationFields($spec, $operation);
        $fields['variables'] = json_encode($variables, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        return $this->transport->request('POST', $spec['url'], $fields);
    }

    /** @param array<string,mixed> $spec
     * @return array<string,string>
     */
    private static function operationFields(#[\SensitiveParameter] array $spec, string $operation): array
    {
        $id = $spec['operations'][$operation] ?? null;
        if (!is_string($id) || preg_match('/^[1-9][0-9]{0,24}$/D', $id) !== 1) self::unavailable();
        return $spec['fields'] + ['fb_api_caller_class' => 'RelayModern', 'fb_api_req_friendly_name' => $operation, 'server_timestamps' => 'true', 'doc_id' => $id];
    }

    /** @param array<string,mixed> $state
     * @param array<string,mixed> $native
     * @return array<string,mixed>
     */
    private static function mergeNative(#[\SensitiveParameter] array $state, #[\SensitiveParameter] array $native, bool $replace): array
    {
        $entries = $replace ? [] : [$native['xfb_two_factor_login_default_method']['method'] ?? null];
        if (isset($native['xfb_two_factor_login_methods']['methods'])) {
            if (!is_array($native['xfb_two_factor_login_methods']['methods']) || !array_is_list($native['xfb_two_factor_login_methods']['methods'])) self::unavailable();
            $entries = $native['xfb_two_factor_login_methods']['methods'];
        }
        if (count($entries) > 64) self::unavailable();
        $contacts = [];
        $groups = $native['xfb_two_step_verification_challenge_contact_points']['contact_points'] ?? [];
        if (!is_array($groups) || !array_is_list($groups) || count($groups) > 64) self::unavailable();
        foreach ($groups as $group) {
            if (!is_array($group) || !is_string($group['challenge'] ?? null) || !is_array($group['contact_points'] ?? null) || count($group['contact_points']) > 32) self::unavailable();
            foreach ($group['contact_points'] as $cp) {
                if (!is_array($cp) || !is_string($cp['contact_point'] ?? null)) self::unavailable();
                $contacts[$group['challenge']][] = self::contact($cp['contact_point']);
            }
        }
        $previous = $state['items']; $items = []; $seen = [];
        foreach ($entries as $entry) {
            if (!is_array($entry) || !is_string($entry['method'] ?? null) || !isset(self::TYPES[$entry['method']])) continue;
            $method = $entry['method'];
            if (isset($entry['is_disabled']) && !is_bool($entry['is_disabled'])) self::unavailable();
            // The native chooser expands multiple contact points. With zero/one,
            // native navigation retains method_representation as its submit value.
            $points = count($contacts[$method] ?? []) >= 2 ? $contacts[$method] : [self::contact($entry['method_representation'] ?? null)];
            foreach ($points as $contact) {
                $key = self::key($method, $contact); $id = null;
                foreach ($previous as $oldId => $old) if ($old['key'] === $key) $id = $oldId;
                $id ??= bin2hex(random_bytes(16));
                $item = ['key' => $key, 'method' => $method, 'contact' => $contact, 'disabled' => ($entry['is_disabled'] ?? false) === true];
                if (isset($seen[$key])) {
                    if ($items[$seen[$key]] !== $item) self::unavailable();
                    continue;
                }
                $seen[$key] = $id;
                $items[$id] = $item;
            }
        }
        $state['items'] = $items;
        if (!isset($items[$state['selected'] ?? ''])) $state['selected'] = null;
        $poll = $native['xfb_two_step_verification_afad_polling_interval']['polling_interval'] ?? null;
        if ($poll !== null) {
            if ((!is_int($poll) && !is_float($poll)) || !is_finite((float) $poll) || $poll <= 0 || $poll > 3600) self::unavailable();
            // A local lower bound reduces network traffic, never accelerates the server schedule.
            $state['poll_interval'] = max(1, (int) ceil($poll));
        }
        $rates = $native['xfb_two_step_verification_send_rate_limit_cooldown']['rate_limits'] ?? [];
        if (!is_array($rates) || !array_is_list($rates) || count($rates) > 64) self::unavailable();
        foreach ($rates as $rate) {
            if (!is_array($rate) || !is_string($rate['challenge'] ?? null) || !is_int($rate['cooldown_seconds'] ?? null) || $rate['cooldown_seconds'] < 0 || $rate['cooldown_seconds'] > 86400) self::unavailable();
            $state['cooldowns'][$rate['challenge']] = $rate['cooldown_seconds'];
        }
        if ($replace) $state['discovered'] = true;
        return $state;
    }

    /** @return array<string,mixed> */
    private static function data(#[\SensitiveParameter] WebLoginResponse $response): array
    {
        if ($response->status === 429) throw new MessengerException('login_rate_limited', 'O Facebook limitou as verificações; aguarde antes de tentar novamente.');
        if ($response->status !== 200 || strlen($response->body) > 16777216) self::unavailable();
        $results = [];
        foreach (preg_split('/\r?\n/', trim($response->body)) ?: [] as $line) {
            $line = preg_replace('/^for\s*\(;;\);/', '', trim($line));
            try { $data = json_decode($line ?? '', true, 100, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
            catch (\JsonException) { continue; }
            if (!is_array($data)) continue;
            if (isset($data['errors'])) throw new MessengerException('login_two_factor_rejected', 'O Facebook não aceitou a operação de verificação.');
            if (is_array($data['data'] ?? null)) $results[] = $data['data'];
        }
        if (count($results) !== 1) self::unavailable();
        return $results[0];
    }

    /** @param list<string> $sources
     * @return array<string,string>
     */
    private static function operations(#[\SensitiveParameter] array $sources): array
    {
        $operations = []; $total = 0;
        foreach ($sources as $source) {
            $total += strlen($source);
            if (strlen($source) > 16777216 || $total > 33554432) self::unavailable();
            foreach ([self::PICKER, self::SEND, self::CODE, self::APPROVAL] as $operation) {
                $pattern = '~__d\(\s*["\']' . preg_quote($operation, '~') . '_facebookRelayOperation["\']\s*,\s*\[\]\s*,.{0,300}?\.exports\s*=\s*["\']([1-9][0-9]{0,24})["\']~s';
                if (preg_match_all($pattern, $source, $matches)) foreach ($matches[1] as $id) {
                    if (isset($operations[$operation]) && $operations[$operation] !== $id) self::unavailable();
                    $operations[$operation] = $id;
                }
            }
        }
        return $operations;
    }

    /** @param array<string,array<mixed>> $modules
     * @return array<string,string>|null
     */
    private static function fields(#[\SensitiveParameter] array $modules): ?array
    {
        $fields = ['__a' => '1'];
        $id = $modules['CurrentUserInitialData']['USER_ID'] ?? null;
        if (is_int($id)) $id = (string) $id;
        if (is_string($id) && preg_match('/^(?:0|[1-9][0-9]{0,24})$/D', $id)) $fields['__user'] = $id;
        foreach (['DTSGInitialData', 'DTSGInitData'] as $name) if (is_string($modules[$name]['token'] ?? null) && $modules[$name]['token'] !== '') { $fields['fb_dtsg'] = $modules[$name]['token']; break; }
        if (is_string($modules['LSD']['token'] ?? null) && $modules['LSD']['token'] !== '') $fields['lsd'] = $modules['LSD']['token'];
        if (!isset($fields['fb_dtsg']) && !isset($fields['lsd'])) return null;
        $sprinkle = $modules['SprinkleConfig'] ?? null;
        if (isset($fields['fb_dtsg']) && is_array($sprinkle) && is_int($sprinkle['version'] ?? null) && ($sprinkle['should_randomize'] ?? null) === false && !preg_match('/[^\x20-\x7e]/', $fields['fb_dtsg'])) $fields['jazoest'] = (string) $sprinkle['version'] . array_sum(array_map(ord(...), str_split($fields['fb_dtsg'])));
        $site = $modules['SiteData'] ?? [];
        foreach (['__spin_r' => '__spin_r', '__spin_b' => '__spin_b', '__spin_t' => '__spin_t', 'client_revision' => '__rev', 'haste_session' => '__hs', 'hsi' => '__hsi', 'comet_env' => '__comet_req'] as $source => $target) if (is_string($site[$source] ?? null) || is_int($site[$source] ?? null)) $fields[$target] = (string) $site[$source];
        return $fields;
    }

    /** @param array<string,array<mixed>> $modules
     * @param array<string,mixed> $native
     */
    private static function walk(#[\SensitiveParameter] mixed $value, #[\SensitiveParameter] array &$modules, #[\SensitiveParameter] array &$native, int &$nodes, int $depth = 0): void
    {
        if (++$nodes > 150000 || $depth > 64) self::unavailable();
        if (!is_array($value)) return;
        foreach ($value['define'] ?? [] as $definition) {
            if (!is_array($definition) || !is_string($definition[0] ?? null) || !is_array($definition[2] ?? null)) continue;
            $name = $definition[0];
            if (!in_array($name, ['CurrentUserInitialData', 'DTSGInitialData', 'DTSGInitData', 'LSD', 'SiteData', 'SprinkleConfig', 'RelayAPIConfigDefaults', 'RelayAPIConfig'], true)) continue;
            if (isset($modules[$name]) && $modules[$name] !== $definition[2] && !in_array($name, ['LSD', 'SiteData'], true)) self::unavailable();
            $modules[$name] = $definition[2];
        }
        foreach (['xfb_two_factor_login_default_method', 'xfb_two_factor_login_methods', 'xfb_two_step_verification_challenge_contact_points', 'xfb_two_step_verification_afad_polling_interval', 'xfb_two_step_verification_send_rate_limit_cooldown', 'xfb_two_step_verification_skip_remember_browser'] as $key) if (array_key_exists($key, $value)) {
            if (isset($native[$key]) && $native[$key] !== $value[$key]) self::unavailable();
            $native[$key] = $value[$key];
        }
        $flag = $value['gkxData']['22979']['result'] ?? null;
        if (is_bool($flag)) {
            if (isset($native['polaris_remember_browser']) && $native['polaris_remember_browser'] !== $flag) self::unavailable();
            $native['polaris_remember_browser'] = $flag;
        }
        foreach ($value as $child) {
            if (is_array($child)) self::walk($child, $modules, $native, $nodes, $depth + 1);
            elseif (is_string($child) && strlen($child) <= 16777216 && in_array($child[0] ?? '', ['{', '['], true)) {
                try { $data = json_decode($child, true, 64, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
                catch (\JsonException) { continue; }
                if (is_array($data)) self::walk($data, $modules, $native, $nodes, $depth + 1);
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
            if (!in_array($script[0] ?? '', ['{', '['], true)) continue;
            try { $data = json_decode($script, true, 100, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING); }
            catch (\JsonException) { continue; }
            if (is_array($data)) yield $data;
        }
    }

    /** @return array<string,string> */
    private static function query(#[\SensitiveParameter] string $url): array
    {
        $fields = [];
        foreach (explode('&', parse_url($url, PHP_URL_QUERY) ?? '') as $part) {
            if ($part === '') continue;
            [$key, $value] = array_pad(explode('=', $part, 2), 2, ''); $key = urldecode($key); $value = urldecode($value);
            if (array_key_exists($key, $fields)) self::unavailable();
            $fields[$key] = $value;
        }
        return $fields;
    }

    private static function sameOrigin(#[\SensitiveParameter] string $page, #[\SensitiveParameter] string $target): void
    {
        WebLoginCurlTransport::validateUrl($page); WebLoginCurlTransport::validateUrl($target);
        if (parse_url($page, PHP_URL_HOST) !== parse_url($target, PHP_URL_HOST)) throw new MessengerException('login_unsafe_redirect', 'O destino da verificação não pertence à origem do desafio.');
    }

    /**
     * ALLOWED navigates through the native remember-browser continuation unless
     * the actual root query says to skip it. Never replace that with a guessed
     * /messages/ navigation. Missing route/feature evidence leaves the final
     * identity check to the owner, without inventing an acceptance step.
     * @param array<string,string> $query
     * @param array<string,mixed> $native
     * @param list<string> $sources
     */
    private static function approvalNext(#[\SensitiveParameter] string $url, #[\SensitiveParameter] array $query, #[\SensitiveParameter] array $native, #[\SensitiveParameter] array $sources): ?string
    {
        $skip = $native['xfb_two_step_verification_skip_remember_browser'] ?? null;
        if ($skip === true) {
            $next = WebLoginPageParser::resolveUrl($url, $query['next'] ?? '/');
            self::sameOrigin($url, $next);
            return $next;
        }
        if ($skip !== false || !is_bool($native['polaris_remember_browser'] ?? null)) return null;
        $module = $native['polaris_remember_browser'] ? 'XPolarisRememberBrowserControllerRouteBuilder' : 'XCometTwoStepVerificationTwoFactorRememberBrowserControllerRouteBuilder';
        $routes = [];
        foreach ($sources as $source) {
            $pattern = '~__d\(\s*["\']' . preg_quote($module, '~') . '["\'].{0,1000}?["\']jsRouteBuilder["\']\s*\)\s*\(\s*["\'](/[a-zA-Z0-9_/-]{1,256}/)["\']~s';
            if (preg_match_all($pattern, $source, $matches)) foreach ($matches[1] as $route) $routes[$route] = true;
        }
        if (count($routes) > 1) self::unavailable();
        if ($routes === []) return null;
        $next = WebLoginPageParser::resolveUrl($url, (string) array_key_first($routes));
        self::sameOrigin($url, $next);
        return $next . '?' . http_build_query(['encrypted_context' => $query['encrypted_context'], 'next' => $query['next'] ?? '/'], '', '&', PHP_QUERY_RFC3986);
    }

    private static function contact(#[\SensitiveParameter] mixed $value): ?string
    {
        if ($value === null) return null;
        if (!is_string($value) || strlen($value) > 512 || preg_match('/[\x00-\x1f\x7f]/', $value) || preg_match('//u', $value) !== 1) self::unavailable();
        return $value;
    }

    private static function masked(#[\SensitiveParameter] ?string $contact): ?string
    {
        if ($contact === null || $contact === '') return null;
        // Server representations normally are already masked. Never leak a full
        // contact if a changed server schema returns one unexpectedly.
        return preg_match('/[*•●…]/u', $contact) === 1 ? $contact : '[contato protegido]';
    }

    private static function key(string $method, #[\SensitiveParameter] ?string $contact): string { return hash('sha256', $method . "\0" . ($contact ?? '')); }

    /** @param array<string,string> $resources
     * @param array<string,list<string>> $components
     */
    private static function resourceMaps(#[\SensitiveParameter] mixed $value, array &$resources, array &$components, int &$nodes, int $depth = 0): void
    {
        if (++$nodes > 150000 || $depth > 64) self::unavailable();
        if (!is_array($value)) return;
        foreach ($value['rsrcMap'] ?? [] as $hash => $resource) {
            if (!is_string($hash) || !is_array($resource) || ($resource['type'] ?? null) !== 'js' || !is_string($resource['src'] ?? null) || !self::publicScriptUrl($resource['src'])) continue;
            if (isset($resources[$hash]) && $resources[$hash] !== $resource['src']) self::unavailable();
            $resources[$hash] = $resource['src'];
        }
        foreach ($value['compMap'] ?? [] as $name => $component) {
            if (!is_string($name) || !in_array($name, self::COMPONENTS, true) || !is_array($component) || !is_array($component['r'] ?? null)) continue;
            $refs = array_values(array_filter($component['r'], is_string(...)));
            if (count($refs) > 512) self::unavailable();
            $components[$name] = array_values(array_unique([...($components[$name] ?? []), ...$refs]));
        }
        foreach ($value as $child) if (is_array($child)) self::resourceMaps($child, $resources, $components, $nodes, $depth + 1);
    }

    private static function publicScriptUrl(string $url): bool
    {
        $parts = parse_url($url);
        if ($parts === false || strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url) || ($parts['scheme'] ?? null) !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) return false;
        $host = strtolower($parts['host'] ?? ''); $path = $parts['path'] ?? '';
        return ($host === 'static.xx.fbcdn.net' || str_ends_with($host, '.fbcdn.net')) && str_starts_with($path, '/rsrc.php/') && str_ends_with($path, '.js');
    }

    private static function attribute(#[\SensitiveParameter] string $tag, string $name): ?string
    {
        if (!preg_match('~(?:^|\s)' . preg_quote($name, '~') . '\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'=<>`]+))~i', $tag, $match, PREG_UNMATCHED_AS_NULL)) return null;
        return html_entity_decode($match[1] ?? $match[2] ?? $match[3] ?? '', ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }

    private static function unavailable(): never { throw new MessengerException('login_two_factor_protocol_unavailable', 'O Facebook não forneceu uma seleção de verificação suportada e inequívoca.'); }
    public function __debugInfo(): array { return ['transport' => '[PRIVATE TWO-FACTOR METHOD CONTEXT]']; }
}
