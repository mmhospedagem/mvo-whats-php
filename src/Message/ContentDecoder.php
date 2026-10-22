<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\SchemaCodec;

/** Every authenticated WAProto field remains available, including unknown future wire fields. */
final class ContentDecoder
{
    /**
     * @param array<string,mixed> $message
     * @return array{message:array<string,mixed>,destination:?string,wrappers:list<string>,context:array<string,mixed>} */
    public static function unwrap(array $message, int $depth = 0): array
    {
        $destination = null; $wrappers = []; $context = $message['messageContextInfo'] ?? [];
        while (true) {
            if ($depth > 8) throw new \LengthException('Message wrapper nesting limit exceeded');
            $next = null;
            foreach (SchemaCodec::fields('Message') as $name => $field) {
                if (!in_array($field['type'], ['Message.FutureProofMessage','Message.DeviceSentMessage'], true) || !isset($message[$name])) continue;
                $wrapper = $message[$name];
                if (!is_array($wrapper) || !isset($wrapper['message']) || !is_array($wrapper['message'])) throw new \UnexpectedValueException('Message wrapper missing body');
                if ($name === 'deviceSentMessage') $destination = $wrapper['destinationJid'] ?? $destination;
                $wrappers[] = $name; $next = $wrapper['message']; break;
            }
            if ($next === null) break;
            $message = $next; $context = array_replace($context, $message['messageContextInfo'] ?? []); ++$depth;
        }
        return ['message'=>$message,'destination'=>$destination,'wrappers'=>$wrappers,'context'=>$context];
    }

    /**
     * @return array<string,mixed> */
    public static function decode(string $bytes, int $depth = 0): array
    {
        $proto = SchemaCodec::decode('Message', $bytes);
        $unwrapped = self::unwrap($proto, $depth); $message = $unwrapped['message'];
        $contentType = null;
        foreach ($message as $name => $value) {
            if ($value === null || in_array($name, ['_unknown','messageContextInfo','senderKeyDistributionMessage','fastRatchetKeySenderKeyDistributionMessage'], true)) continue;
            $contentType = $name; break;
        }
        // Sender-key and future fields are still surfaced as structured messages.
        $contentType ??= array_key_first($message);
        $data = $contentType !== null ? $message[$contentType] : [];
        $text = $contentType === 'conversation' ? $data : (in_array($contentType,['extendedTextMessage','questionResponseMessage'],true) ? ($data['text'] ?? null) : null);
        $kind = match ($contentType) {
            'conversation','extendedTextMessage'=>'text',
            'pollCreationMessage','pollCreationMessageV2','pollCreationMessageV3','pollCreationMessageV5','pollCreationMessageV6'=>'poll',
            'pollUpdateMessage'=>'poll-vote', 'encEventResponseMessage','eventResponseMessage'=>'event-response',
            'contactsArrayMessage'=>'contacts', 'contactMessage'=>'contact',
            'pinInChatMessage'=>($data['type'] ?? 0) === 2 ? 'unpin' : 'pin',
            'keepInChatMessage'=>($data['keepType'] ?? 0) === 2 ? 'unkeep' : 'keep',
            'audioMessage'=>($data['ptt'] ?? false) ? 'ptt' : 'audio',
            'videoMessage'=>($data['gifPlayback'] ?? false) ? 'gif' : 'video',
            'protocolMessage'=>'protocol', '_unknown',null=>'unsupported',
            default=>self::name($contentType),
        };
        $context = is_array($data) ? ($data['contextInfo'] ?? []) : [];
        $decoded = ['text'=>$text,'destination'=>$unwrapped['destination'],'kind'=>$kind,
            'fields'=>SchemaCodec::fieldNumbers(SchemaCodec::encode('Message',$message)),
            'contentType'=>$contentType,'data'=>$data,'proto'=>$proto,'context'=>$context,
            'messageContextInfo'=>$unwrapped['context'],'wrappers'=>$unwrapped['wrappers']];
        $decoded['spoiler']=in_array('spoilerMessage',$unwrapped['wrappers'],true)||($context['isSpoiler']??false)===true;
        if(is_string($context['stanzaId']??null))$decoded['quote']=['id'=>$context['stanzaId'],'participant'=>$context['participant']??null,'remoteJid'=>$context['remoteJid']??null,'selectedText'=>$context['partiallySelectedContent']['text']??null,'quotedType'=>$context['quotedType']??null];
        if($contentType==='instantImageMessage')$decoded['instantImage']=['caption'=>$data['caption']??null,'mimetype'=>$data['mimetype']??null,'width'=>$data['width']??null,'height'=>$data['height']??null];
        if($contentType==='newsletterFollowerInviteMessageV2')$decoded['channelInvite']=['jid'=>$data['newsletterJid']??null,'name'=>$data['newsletterName']??null,'caption'=>$data['caption']??null,'hasThumbnail'=>isset($data['jpegThumbnail'])];
        if(is_array($message['interactiveMessage']['carouselMessage']??null)){
            $carousel=$message['interactiveMessage']['carouselMessage'];$cards=[];
            foreach($carousel['cards']??[]as$card){$header=$card['header']??[];$cards[]=['title'=>$header['title']??null,'caption'=>$card['body']['text']??null,'footer'=>$card['footer']['text']??null,'mediaType'=>isset($header['imageMessage'])?'image':(isset($header['videoMessage'])?'video':null),'buttons'=>$card['nativeFlowMessage']['buttons']??[]];}
            $decoded['carousel']=['text'=>$message['interactiveMessage']['body']['text']??null,'cardCount'=>count($cards),'cards'=>$cards];
        }
        if ($contentType === 'richResponseMessage' && ($rich = BotResponseContent::decode($message)) !== null) {
            $decoded['text'] = $rich['text'];
            $decoded['richResponse'] = $rich['richResponse'];
        }
        if (is_array($data)) {
            $target = $data['key'] ?? $data['targetMessageKey'] ?? $data['pollCreationMessageKey'] ?? $data['eventCreationMessageKey'] ?? null;
            if (is_array($target)) $decoded['target'] = $target;
            if ($contentType === 'protocolMessage') {
                $decoded['protocolType'] = $data['type'] ?? 0;
                $decoded['action'] = match ($data['type'] ?? 0) {0=>'revoke',14=>'edit',default=>'protocol'};
            }
        }
        $flowResponse = self::flowResponse($message);
        if ($flowResponse !== null) $decoded['parsedFlowResponse'] = $flowResponse;
        $formResponse = self::formResponse($message);
        if ($formResponse !== null) $decoded['parsedFormResponse'] = $formResponse;
        $media = \MVO\Media\MediaTransfer::resolvePayload($message);
        if ($media !== null) $decoded['media'] = $media;
        $addon = AddonCrypto::identify($proto);
        if ($addon !== null) $decoded['encryptedAddon'] = $addon;
        $normalizedMessage=$message;
        if ($unwrapped['context']!==[]) $normalizedMessage['messageContextInfo']=$unwrapped['context'];
        $decoded = array_replace($decoded, AdvancedEvents::normalize($normalizedMessage, $kind, is_array($data) ? $data : [],$unwrapped['wrappers']));
        $decoded = array_replace($decoded, ModernEvents::normalize($normalizedMessage, $unwrapped['wrappers']));
        return $decoded;
    }

    /** Parses authenticated Flow reply parameters without changing their original wire fields.
     * Malformed or non-object JSON remains available in the unmodified message.
     * @param array<string,mixed> $message
     * @return array<string,mixed>|null */
    public static function flowResponse(array $message): ?array
    {
        try { $message = self::unwrap($message)['message']; }
        catch (\UnexpectedValueException|\LengthException) { return null; }
        $response = $message['interactiveResponseMessage']['nativeFlowResponseMessage'] ?? null;
        if (!is_array($response) || !in_array($response['name'] ?? null, ['galaxy_message','flow'], true) || !is_string($response['paramsJson'] ?? null)) return null;
        try { $params = json_decode($response['paramsJson'], false, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return null; }
        if (!$params instanceof \stdClass) return null;
        $parsed = ['name'=>$response['name'],'version'=>$response['version'] ?? null,'params'=>$params];
        if (property_exists($params, 'flow_token')) $parsed['flowToken'] = $params->flow_token;
        if (property_exists($params, 'wa_flow_response_params')) $parsed['response'] = $params->wa_flow_response_params;
        if ($response['name'] === 'galaxy_message' && ($businessForm = self::businessFormResponse($params, $response['paramsJson'])) !== null) $parsed['businessForm'] = $businessForm;
        return $parsed;
    }

    /** Native Business schema version is independent of nativeFlowResponseMessage.version.
     * Keep screens/components as lists: repeated names or screen IDs must not overwrite answers.
     * @return array<string,mixed>|null */
    private static function businessFormResponse(\stdClass $params, string $rawParams): ?array
    {
        if (strlen($rawParams) > 262144) return null;
        $response = $params->wa_flow_response_params ?? null;
        if (!$response instanceof \stdClass || ($response->flow_creation_source ?? null) !== 'FLEXIBLE_CHECKOUT' ||
            !self::responseText($response->flow_id ?? null, 64) || preg_match('/^[1-9][0-9]{0,63}$/D', $response->flow_id) !== 1 ||
            !self::responseText($response->flow_name ?? null, 4096, true) || !is_string($response->response_message ?? null) || strlen($response->response_message) > 262144) return null;
        try { $schema = json_decode($response->response_message, false, 32, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return null; }
        if (!$schema instanceof \stdClass || ($schema->version ?? null) !== 2 || !is_array($schema->screens ?? null) || !array_is_list($schema->screens) || count($schema->screens) < 1 || count($schema->screens) > 20) return null;
        if (!self::uniqueResponseObjectKeys($rawParams) || !self::uniqueResponseObjectKeys($response->response_message)) return null;
        $screens = []; $count = 0;
        foreach ($schema->screens as $screen) {
            if (!$screen instanceof \stdClass || !self::responseText($screen->id ?? null, 4096) || !self::responseText($screen->title ?? null, 4096, true) ||
                !is_array($screen->components ?? null) || !array_is_list($screen->components) || count($screen->components) > 100) return null;
            $components = [];
            foreach ($screen->components as $component) {
                if (++$count > 100 || !$component instanceof \stdClass || !self::responseText($component->type ?? null, 64) ||
                    !self::responseText($component->name ?? null, 4096) || !self::responseText($component->label ?? null, 4096, true) ||
                    !self::responseText($component->value ?? null, 65536, true) || !is_bool($component->isSensitive ?? null)) return null;
                $components[] = ['type'=>$component->type,'name'=>$component->name,'label'=>$component->label,'value'=>$component->value,'isSensitive'=>$component->isSensitive];
            }
            $screens[] = ['id'=>$screen->id,'title'=>$screen->title,'components'=>$components];
        }
        return $count === 0 ? null : ['flowId'=>$response->flow_id,'flowName'=>$response->flow_name,'creationSource'=>'FLEXIBLE_CHECKOUT','version'=>2,'screens'=>$screens];
    }

    private static function responseText(mixed $value, int $maxBytes, bool $allowEmpty = false): bool
    {
        return is_string($value) && ($allowEmpty || $value !== '') && strlen($value) <= $maxBytes && preg_match('//u', $value) === 1;
    }

    /** Already-valid, bounded JSON only. Reject ambiguous duplicate keys, including escaped aliases.
     * JSON object keys are distinct from repeated component names in separate objects. */
    private static function uniqueResponseObjectKeys(string $json): bool
    {
        $stack = []; $length = strlen($json);
        for ($i = 0; $i < $length; ++$i) {
            $char = $json[$i];
            if ($char === '{') { $stack[] = []; continue; }
            if ($char === '[') { $stack[] = null; continue; }
            if ($char === '}' || $char === ']') { array_pop($stack); continue; }
            if ($char !== '"') continue;
            $start = $i++;
            while ($i < $length && $json[$i] !== '"') { if ($json[$i] === '\\') ++$i; ++$i; }
            $next = $i + 1;
            while ($next < $length && str_contains(" \t\r\n", $json[$next])) ++$next;
            if ($next >= $length || $json[$next] !== ':') continue;
            $key = json_decode(substr($json, $start, $i - $start + 1), true, 2, JSON_THROW_ON_ERROR);
            $last = count($stack) - 1;
            if (!is_string($key) || $last < 0 || !is_array($stack[$last]) || isset($stack[$last]['key:'.$key])) return false;
            $stack[$last]['key:'.$key] = true;
        }
        return true;
    }

    /** Native form replies retain caller-owned field IDs and the client's wire version.
     * @param array<string,mixed> $message
     * @return array{name:string,version:mixed,params:\stdClass}|null */
    public static function formResponse(array $message): ?array
    {
        try { $message = self::unwrap($message)['message']; }
        catch (\UnexpectedValueException|\LengthException) { return null; }
        $response = $message['interactiveResponseMessage']['nativeFlowResponseMessage'] ?? null;
        if (!is_array($response) || ($response['name'] ?? null) !== 'form_message' || !is_string($response['paramsJson'] ?? null)) return null;
        try { $params = json_decode($response['paramsJson'], false, 512, JSON_THROW_ON_ERROR); }
        catch (\JsonException) { return null; }
        if (!$params instanceof \stdClass) return null;
        return ['name'=>'form_message','version'=>$response['version'] ?? null,'params'=>$params];
    }

    private static function name(string $name): string
    {
        $name = preg_replace('/Message$/', '', $name) ?? $name;
        return strtolower(preg_replace('/([a-z0-9])([A-Z])/', '$1-$2', $name) ?? $name);
    }
}
