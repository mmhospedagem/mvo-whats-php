<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\SchemaCodec;

/** Converts the legacy authoring shape to current native-flow content. */
final class ButtonsBuilder
{
    /**
     * Accepts the body of ButtonsMessage, not the surrounding sendMessage options.
     * Media headers must already contain uploaded WAProto media fields.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function build(array $body): array
    {
        $allowed = ['text','documentMessage','imageMessage','videoMessage','locationMessage',
            'contentText','footerText','contextInfo','buttons','headerType'];
        foreach ($body as $field => $_) {
            if (!in_array($field, $allowed, true)) throw new \InvalidArgumentException('Unknown buttons field '.$field);
        }
        $buttons = $body['buttons'] ?? null;
        if (!is_array($buttons) || !array_is_list($buttons) || $buttons === []) {
            throw new \InvalidArgumentException('Buttons require a non-empty list');
        }
        $nativeButtons = []; $ids = [];
        foreach ($buttons as $index => $button) {
            if (!is_array($button)) throw new \InvalidArgumentException('Button '.$index.' must be an object');
            foreach ($button as $field => $_) {
                if (!in_array($field, ['buttonId','buttonText','type','nativeFlowInfo'], true)) {
                    throw new \InvalidArgumentException('Unknown button field '.$field);
                }
            }
            $type = $button['type'] ?? 1;
            if ($type === 1 || $type === 'RESPONSE') {
                $id = self::text($button['buttonId'] ?? null, 'buttonId', true);
                $label = $button['buttonText'] ?? null;
                if (!is_array($label) || array_keys($label) !== ['displayText']) {
                    throw new \InvalidArgumentException('buttonText requires displayText');
                }
                $label = self::text($label['displayText'], 'displayText', true);
                if (isset($button['nativeFlowInfo'])) throw new \InvalidArgumentException('Response button cannot also contain nativeFlowInfo');
                $params = ['display_text'=>$label,'id'=>$id];
                $native = ['name'=>'quick_reply','buttonParamsJson'=>json_encode($params, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)];
            } elseif ($type === 2 || $type === 'NATIVE_FLOW') {
                $flow = $button['nativeFlowInfo'] ?? null;
                if (!is_array($flow)) throw new \InvalidArgumentException('Native-flow button requires nativeFlowInfo');
                foreach ($flow as $field => $_) {
                    if (!in_array($field, ['name','paramsJson'], true)) throw new \InvalidArgumentException('Unknown nativeFlowInfo field '.$field);
                }
                $name = self::text($flow['name'] ?? null, 'nativeFlowInfo.name', true);
                $json = self::text($flow['paramsJson'] ?? null, 'nativeFlowInfo.paramsJson', true);
                try { $params = json_decode($json, false, 512, JSON_THROW_ON_ERROR); }
                catch (\JsonException $error) { throw new \InvalidArgumentException('nativeFlowInfo.paramsJson must be valid JSON', 0, $error); }
                if (!$params instanceof \stdClass) throw new \InvalidArgumentException('nativeFlowInfo.paramsJson must be a JSON object');
                $params = get_object_vars($params);
                $id = null;
                if ($name === 'quick_reply') {
                    $id = self::text($params['id'] ?? null, 'quick_reply.id', true);
                    self::text($params['display_text'] ?? null, 'quick_reply.display_text', true);
                }
                $native = ['name'=>$name,'buttonParamsJson'=>$json];
            } else throw new \InvalidArgumentException('Unsupported legacy button type');
            if ($id !== null) {
                if (isset($ids[$id])) throw new \InvalidArgumentException('Duplicate button ID '.$id);
                $ids[$id] = true;
            }
            $nativeButtons[] = $native;
        }
        // Quick replies do not force a protocol version or empty message parameters;
        // optional native-flow fields remain raw API options.
        $interactive = ['header'=>self::header($body), 'nativeFlowMessage'=>['buttons'=>$nativeButtons]];
        if (array_key_exists('contentText', $body)) $interactive['body'] = ['text'=>self::text($body['contentText'], 'contentText')];
        if (array_key_exists('footerText', $body)) $interactive['footer'] = ['text'=>self::text($body['footerText'], 'footerText')];
        if (array_key_exists('contextInfo', $body)) {
            if (!is_array($body['contextInfo'])) throw new \InvalidArgumentException('contextInfo must be an object');
            $interactive['contextInfo'] = $body['contextInfo'];
        }
        $message = ['interactiveMessage'=>$interactive];
        // Validate prepared headers and context against the same schema as raw messages.
        SchemaCodec::encode('Message', $message);
        return $message;
    }

    /**
     * Explicit plain-text menu for clients that cannot render interactive content.
     * Numeric answers are ordinary text; the application maps their order to IDs.
     *
     * @param array<string,mixed> $body
     * @return array<string,mixed>
     */
    public static function textFallback(array $body): array
    {
        $interactive = self::build($body)['interactiveMessage'];
        if ($interactive['header']['hasMediaAttachment']) {
            throw new \InvalidArgumentException('Text buttons cannot include a media header');
        }
        $sections = [];
        foreach ([$interactive['header']['title'] ?? '', $interactive['body']['text'] ?? ''] as $text) {
            if ($text !== '') $sections[] = $text;
        }
        $options = [];
        foreach ($interactive['nativeFlowMessage']['buttons'] as $index => $button) {
            if ($button['name'] !== 'quick_reply') {
                throw new \InvalidArgumentException('Text buttons support quick_reply options only');
            }
            $params = json_decode($button['buttonParamsJson'], true, 512, JSON_THROW_ON_ERROR);
            $options[] = ($index + 1).'. '.self::text($params['display_text'] ?? null, 'quick_reply.display_text', true);
        }
        $sections[] = implode("\n", $options);
        $sections[] = 'Responda com o número da opção.';
        if (($interactive['footer']['text'] ?? '') !== '') $sections[] = $interactive['footer']['text'];
        $text = implode("\n\n", $sections);
        MessageCodec::text($text);
        if (array_key_exists('contextInfo', $interactive)) {
            return ['extendedTextMessage'=>['text'=>$text,'contextInfo'=>$interactive['contextInfo']]];
        }
        return ['conversation'=>$text];
    }

    /** @param array<string,mixed> $body
     * @return array<string,mixed> */
    private static function header(array $body): array
    {
        $types = [1=>'EMPTY',2=>'TEXT',3=>'DOCUMENT',4=>'IMAGE',5=>'VIDEO',6=>'LOCATION'];
        $fields = ['DOCUMENT'=>'documentMessage','IMAGE'=>'imageMessage','VIDEO'=>'videoMessage','LOCATION'=>'locationMessage'];
        $media = [];
        foreach ($fields as $name => $field) if (array_key_exists($field, $body)) $media[$name] = $body[$field];
        if (count($media) > 1) throw new \InvalidArgumentException('Buttons support one prepared media header');
        $type = $body['headerType'] ?? (array_key_first($media) ?? (isset($body['text']) ? 'TEXT' : 'EMPTY'));
        if (is_int($type)) $type = $types[$type] ?? null;
        if (!is_string($type) || !in_array($type, $types, true)) throw new \InvalidArgumentException('Invalid buttons headerType');
        $header = ['hasMediaAttachment'=>$media !== []];
        if (array_key_exists('text', $body)) $header['title'] = self::text($body['text'], 'header text');
        if ($type === 'TEXT' && !array_key_exists('text', $body)) throw new \InvalidArgumentException('TEXT header requires text');
        if ($type === 'EMPTY' && (isset($body['text']) || $media !== [])) throw new \InvalidArgumentException('EMPTY header cannot include text or media');
        if (isset($fields[$type])) {
            if (!array_key_exists($type, $media) || !is_array($media[$type]) || $media[$type] === []) {
                throw new \InvalidArgumentException($type.' header requires prepared '.$fields[$type]);
            }
            $header[$fields[$type]] = $media[$type];
        } elseif ($media !== []) throw new \InvalidArgumentException('headerType must match its prepared media');
        return $header;
    }

    private static function text(mixed $value, string $label, bool $nonempty = false): string
    {
        if (!is_string($value) || preg_match('//u', $value) !== 1 || ($nonempty && trim($value) === '')) {
            throw new \InvalidArgumentException($label.' must be '.($nonempty ? 'non-empty ' : '').'UTF-8 text');
        }
        return $value;
    }
}
