<?php
declare(strict_types=1);
namespace MVO\Message;

/** Authors a native Galaxy CTA for a published Flow or an inline navigate form. */
final class FlowBuilder
{
    private const MAX_INLINE_BYTES = 262144;

    /** @param array<string,mixed> $content
     * @return array<string,mixed> */
    public static function build(array $content): array
    {
        $type = $content['type'] ?? 'flow';
        if (!in_array($type, ['flow','flow-navigate','flow-exchange','flow.navigate','flow.exchange'], true)) {
            throw new \InvalidArgumentException('Unsupported Flow message type');
        }
        foreach (['flow_name','flow_data_endpoint','flow_metadata','flow_version_id','flow_json_fresh_fetched',
            'flow_qpl_message_id','flow_wam_message_id','creation_source','expire_time'] as $field) {
            if (array_key_exists($field, $content)) throw new \InvalidArgumentException('Typed Flow authoring does not support '.$field.'; use an existing published flow_id');
        }
        $inline = null;
        $screens = [];
        if (array_key_exists('flow_json', $content)) [$inline, $screens] = self::inlineJson($content['flow_json']);
        $id = array_key_exists('flow_id', $content)
            ? self::text($content['flow_id'], 'flow_id')
            : ($inline !== null ? self::inlineId($inline) : self::text(null, 'flow_id or flow_json'));
        if (!ctype_digit($id) || strlen($id) > 64) throw new \InvalidArgumentException('flow_id must be a numeric string of at most 64 digits');
        $token = self::text($content['flow_token'] ?? null, 'flow_token');
        $cta = self::text(self::alias($content, 'flow_cta', 'cta'), 'flow_cta');
        if (preg_match_all('/./us', $cta) > 30) throw new \InvalidArgumentException('flow_cta cannot exceed 30 Unicode characters');
        $version = array_key_exists('flow_message_version', $content) ? $content['flow_message_version'] : '3';
        if ($version !== '3' && $version !== 3) throw new \InvalidArgumentException('Supported flow_message_version is 3');

        $forcedAction = match ($type) {
            'flow-navigate','flow.navigate'=>'navigate',
            'flow-exchange','flow.exchange'=>'data_exchange',
            default=>null,
        };
        $action = self::alias($content, 'flow_action', 'action');
        if ($action === null && (array_key_exists('flow_action', $content) || array_key_exists('action', $content))) {
            throw new \InvalidArgumentException('flow_action must be navigate or data_exchange');
        }
        $action ??= $forcedAction ?? 'navigate';
        if (!in_array($action, ['navigate','data_exchange'], true)) throw new \InvalidArgumentException('flow_action must be navigate or data_exchange');
        if ($forcedAction !== null && $action !== $forcedAction) throw new \InvalidArgumentException('Flow message type conflicts with flow_action');
        if ($inline !== null && $action === 'data_exchange') throw new \InvalidArgumentException('Inline flow_json supports navigate only; data_exchange requires a registered flow_id and configured endpoint');
        $hasDataApiVersion = array_key_exists('data_api_version', $content);
        if ($hasDataApiVersion && $content['data_api_version'] !== '3.0') throw new \InvalidArgumentException('Supported data_api_version is the string 3.0');
        if ($inline !== null && $hasDataApiVersion) throw new \InvalidArgumentException('Inline flow_json cannot use data_api_version; use a registered Flow with a configured endpoint');

        $params = ['flow_message_version'=>'3','flow_id'=>$id,'flow_token'=>$token,'flow_cta'=>$cta,'flow_action'=>$action];
        // A non-null version makes the client fetch and verify the registered endpoint metadata.
        // Without it, fresh exchange messages can skip the public-key/signature lookup.
        if ($action === 'data_exchange' || $hasDataApiVersion) $params['flow_metadata'] = (object)['data_api_version'=>'3.0'];
        if ($inline !== null) {
            $params['flow_json'] = $inline;
            // Inline JSON bypasses the client's metadata loader, so its tracking IDs
            // must already be present for the native form to finish initializing.
            $trackingSeed = $id."\0".$token."\0".random_bytes(16);
            $params['flow_qpl_message_id'] = base64_encode(hash('sha256', $trackingSeed.'_qpl', true));
            $params['flow_wam_message_id'] = base64_encode(hash('sha256', $trackingSeed.'_wam', true));
        }
        if (array_key_exists('mode', $content)) {
            if (!in_array($content['mode'], ['published','draft'], true)) throw new \InvalidArgumentException('Flow mode must be published or draft');
            $params['mode'] = $content['mode'];
        }
        if ($action === 'navigate') {
            if (array_key_exists('flow_action_payload', $content)) {
                if (array_key_exists('screen', $content) || array_key_exists('data', $content)) {
                    throw new \InvalidArgumentException('Use flow_action_payload or screen/data, not both');
                }
                $payload = self::object($content['flow_action_payload'], 'flow_action_payload');
                foreach (get_object_vars($payload) as $key => $_) {
                    if (!in_array($key, ['screen','data'], true)) throw new \InvalidArgumentException('Unknown flow_action_payload field '.$key);
                }
                $payload->screen = self::text(property_exists($payload, 'screen') ? $payload->screen : ($screens[0] ?? null), 'flow_action_payload.screen');
                if (property_exists($payload, 'data')) $payload->data = self::object($payload->data, 'flow_action_payload.data');
            } else {
                $payload = (object)['screen'=>self::text(array_key_exists('screen', $content) ? $content['screen'] : ($screens[0] ?? null), 'screen')];
                if (array_key_exists('data', $content)) $payload->data = self::object($content['data'], 'data');
            }
            if ($inline !== null && !in_array($payload->screen, $screens, true)) throw new \InvalidArgumentException('Flow navigate screen must exist in flow_json.screens');
            $params['flow_action_payload'] = $payload;
        } else {
            foreach (['screen','data','flow_action_payload'] as $field) {
                if (array_key_exists($field, $content)) throw new \InvalidArgumentException('data_exchange cannot include '.$field);
            }
        }
        try { $json = json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); }
        catch (\JsonException $error) { throw new \InvalidArgumentException('Flow parameters must contain valid JSON values', 0, $error); }
        $interactive = ['body'=>['text'=>self::text($content['text'] ?? null, 'Flow text')],
            'nativeFlowMessage'=>['buttons'=>[['name'=>'galaxy_message','buttonParamsJson'=>$json]],'messageVersion'=>1]];
        if (array_key_exists('title', $content)) $interactive['header'] = ['title'=>self::text($content['title'], 'Flow title'),'hasMediaAttachment'=>false];
        if (array_key_exists('footer', $content)) $interactive['footer'] = ['text'=>self::text($content['footer'], 'Flow footer')];
        return ['interactiveMessage'=>$interactive];
    }

    /** @return array{0:\stdClass,1:list<string>} */
    private static function inlineJson(mixed $value): array
    {
        try {
            if (is_string($value)) {
                if (strlen($value) > self::MAX_INLINE_BYTES) throw new \InvalidArgumentException('flow_json exceeds 256 KiB');
                $value = json_decode($value, false, 128, JSON_THROW_ON_ERROR);
            }
            $object = self::object($value, 'flow_json');
            $json = json_encode($object, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES, 128);
            if (strlen($json) > self::MAX_INLINE_BYTES) throw new \InvalidArgumentException('flow_json exceeds 256 KiB');
            $flow = json_decode($json, false, 128, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('flow_json must contain a valid JSON object', 0, $error);
        }
        if (!$flow instanceof \stdClass) throw new \InvalidArgumentException('flow_json must be a JSON object');
        $version = self::text($flow->version ?? null, 'flow_json.version');
        if (strlen($version) > 16 || preg_match('/^[1-9][0-9]*\.[0-9]+$/D', $version) !== 1) {
            throw new \InvalidArgumentException('flow_json.version must be a semantic version string such as 7.3');
        }
        foreach (['data_channel_uri','data_api_version'] as $field) {
            if (property_exists($flow, $field)) throw new \InvalidArgumentException('Inline flow_json cannot configure '.$field.'; use a registered Flow for data_exchange');
        }
        if (!isset($flow->screens) || !is_array($flow->screens) || !array_is_list($flow->screens)
            || count($flow->screens) < 1 || count($flow->screens) > 100) {
            throw new \InvalidArgumentException('flow_json.screens must contain between 1 and 100 screens');
        }
        $screens = [];
        $routes = [];
        foreach ($flow->screens as $screen) {
            if (!$screen instanceof \stdClass) throw new \InvalidArgumentException('Every flow_json screen must be a JSON object');
            $id = self::text($screen->id ?? null, 'flow_json screen id');
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]{0,63}$/D', $id) !== 1) throw new \InvalidArgumentException('Flow screen IDs must contain 1 to 64 letters, digits or underscores and start with a letter or underscore');
            $route = strtolower($id);
            if (in_array($route, $routes, true)) throw new \InvalidArgumentException('flow_json screen IDs must be unique, ignoring case');
            $screens[] = $id;
            $routes[] = $route;
            if (!isset($screen->layout) || !$screen->layout instanceof \stdClass) throw new \InvalidArgumentException('Flow screen layout must be a JSON object');
            self::text($screen->layout->type ?? null, 'flow_json layout type');
            if (!isset($screen->layout->children) || !is_array($screen->layout->children)
                || !array_is_list($screen->layout->children) || $screen->layout->children === []) {
                throw new \InvalidArgumentException('Flow screen layout.children must be a non-empty JSON array');
            }
            if (property_exists($screen, 'data') && $screen->data === []) $screen->data = new \stdClass();
            if (property_exists($screen, 'data') && !$screen->data instanceof \stdClass) throw new \InvalidArgumentException('Flow screen data must be a JSON object');
        }
        if (property_exists($flow, 'routing_model')) {
            if ($flow->routing_model === []) $flow->routing_model = new \stdClass();
            if (!$flow->routing_model instanceof \stdClass) throw new \InvalidArgumentException('flow_json.routing_model must be a JSON object');
            foreach (get_object_vars($flow->routing_model) as $source => $targets) {
                if (!in_array($source, $screens, true) || !is_array($targets) || !array_is_list($targets)) throw new \InvalidArgumentException('flow_json.routing_model must map known screen IDs to arrays');
                foreach ($targets as $target) {
                    if (!is_string($target) || !in_array($target, $screens, true)) throw new \InvalidArgumentException('flow_json.routing_model references an unknown screen');
                }
            }
        }
        self::rejectInlineExchange($flow);
        return [$flow, $screens];
    }

    private static function rejectInlineExchange(mixed $value): void
    {
        if ($value instanceof \stdClass) {
            if (($value->name ?? null) === 'data_exchange') throw new \InvalidArgumentException('Inline Flow actions cannot use data_exchange; use a registered Flow with a configured endpoint');
            $value = get_object_vars($value);
        }
        if (is_array($value)) foreach ($value as $child) self::rejectInlineExchange($child);
    }

    /** Stable local identity only: this does not register or publish a remote Flow. */
    private static function inlineId(\stdClass $flow): string
    {
        $canonical = json_encode(self::canonical($flow), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $decimal = '0';
        foreach (str_split(hash('sha256', $canonical)) as $hexDigit) {
            $carry = (int)hexdec($hexDigit);
            $next = '';
            for ($i = strlen($decimal) - 1; $i >= 0; --$i) {
                $carry += ((int)$decimal[$i]) * 16;
                $next = ($carry % 10).$next;
                $carry = intdiv($carry, 10);
            }
            while ($carry > 0) {
                $next = ($carry % 10).$next;
                $carry = intdiv($carry, 10);
            }
            $decimal = ltrim($next, '0') ?: '0';
        }
        // Keep local IDs below JavaScript's maximum safe integer for client metrics.
        return '1'.str_pad(substr($decimal, -15), 15, '0', STR_PAD_LEFT);
    }

    private static function canonical(mixed $value): mixed
    {
        if ($value instanceof \stdClass) {
            $properties = get_object_vars($value);
            ksort($properties, SORT_STRING);
            foreach ($properties as $key => $child) $properties[$key] = self::canonical($child);
            return (object)$properties;
        }
        if (is_array($value)) return array_map(self::canonical(...), $value);
        return $value;
    }

    /** @param array<string,mixed> $content */
    private static function alias(array $content, string $canonical, string $alias): mixed
    {
        if (array_key_exists($canonical, $content) && array_key_exists($alias, $content) && $content[$canonical] !== $content[$alias]) {
            throw new \InvalidArgumentException('Conflicting '.$canonical.' and '.$alias);
        }
        return $content[$canonical] ?? $content[$alias] ?? null;
    }

    private static function text(mixed $value, string $field): string
    {
        if (!is_string($value) || trim($value) === '' || preg_match('//u', $value) !== 1) {
            throw new \InvalidArgumentException($field.' must be non-empty UTF-8 text');
        }
        return $value;
    }

    private static function object(mixed $value, string $field): \stdClass
    {
        if ($value instanceof \stdClass) return clone $value;
        if (!is_array($value) || ($value !== [] && array_is_list($value))) throw new \InvalidArgumentException($field.' must be a JSON object');
        return (object)$value;
    }
}
