<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\SchemaCodec;

/** Typed content conveniences plus an explicit, complete raw WAProto escape hatch. */
final class ContentBuilder
{
    /**
     * Media must be uploaded by MediaTransfer before calling this pure content layer.
     *
     * @param string|array<string,mixed> $content
     *
     * @param array<string,mixed> $context
     *
     * @return array{bytes:string,attrs:array<string,string>,encAttrs:array<string,string>,secrets:array<string,string>,metaAttrs:array<string,string>,buttonAddonKind:?string,companionNodes:list<\MVO\Protocol\BinaryNode>,proto:array<string,mixed>}
     */
    public static function build(string|array $content, string $to, array $context = []): array
    {
        if ($to === '') throw new \InvalidArgumentException('Message recipient is required');
        if(is_array($content)&&($content['type']??null)==='spoiler'){
            foreach($content as$key=>$_)if(!in_array($key,['type','content','quote','messageId'],true))throw new \InvalidArgumentException('Unknown spoiler field '.$key);
            $inner=$content['content']??null;
            if(is_string($inner))$inner=['type'=>'text','text'=>$inner];
            if(!is_array($inner)||($inner['type']??null)==='spoiler')throw new \InvalidArgumentException('Spoiler requires text or prepared content');
            $content=[...$inner,...array_intersect_key($content,array_flip(['quote','messageId'])),'spoiler'=>true];
        }
        $options = is_array($content) ? $content : [];
        $now = $context['nowMs'] ?? (int)floor(microtime(true) * 1000);
        if (is_string($content)) { MessageCodec::text($content); $message = ['conversation'=>$content]; }
        elseif (!isset($content['type'])) $message = $content;
        else $message = self::typed($content, $to, $context, $now);
        if ($message === []) throw new \InvalidArgumentException('Message content cannot be empty');
        $contextOptions = array_replace($options, $context);
        $ctx = self::context($contextOptions, $to);
        if ($ctx !== []) $message = self::applyContext($message, $ctx);
        $spoiler=$contextOptions['spoiler']??false;
        if(!is_bool($spoiler))throw new \InvalidArgumentException('spoiler must be boolean');
        if($spoiler){
            $inner=ContentDecoder::unwrap($message)['message'];
            if(array_intersect(array_keys($inner),['conversation','extendedTextMessage','imageMessage','videoMessage','audioMessage','documentMessage','stickerMessage','ptvMessage','instantImageMessage'])===[])throw new \InvalidArgumentException('Spoiler supports text and media messages');
            $message=self::applyContext($message,['isSpoiler'=>true]);
            if(!in_array('spoilerMessage',ContentDecoder::unwrap($message)['wrappers'],true))$message=['spoilerMessage'=>['message'=>$message]];
        }
        $viewOnce = $contextOptions['viewOnce'] ?? false;
        if (!is_bool($viewOnce)) throw new \InvalidArgumentException('viewOnce must be boolean');
        if ($viewOnce) $message = self::applyViewOnce($message);
        if (isset($contextOptions['ephemeralExpiration'])) {
            $message = self::applyContext($message, ['expiration'=>$contextOptions['ephemeralExpiration']]);
            $message = ['ephemeralMessage'=>['message'=>$message]];
        }
        $unwrappedInfo = ContentDecoder::unwrap($message);
        $unwrapped = $unwrappedInfo['message'];
        // Interactive content supplies MessageContextInfo, which is also
        // copied outside the own-device wrapper for companion synchronization.
        $needsSecret = isset($unwrapped['eventMessage']) || isset($unwrapped['interactiveMessage']);
        foreach (array_keys($unwrapped) as $field) if (str_starts_with($field, 'pollCreationMessage')) $needsSecret = true;
        $secrets = [];
        if ($needsSecret) {
            $secret = $unwrappedInfo['context']['messageSecret'] ?? random_bytes(32);
            if (!is_string($secret) || strlen($secret) !== 32) throw new \InvalidArgumentException('Message secret must be 32 bytes');
            $message['messageContextInfo']['messageSecret'] = $secret;
        }
        $secret = $message['messageContextInfo']['messageSecret'] ?? $unwrapped['messageContextInfo']['messageSecret'] ?? null;
        if ($secret !== null) {
            if (!is_string($secret) || strlen($secret) !== 32) throw new \InvalidArgumentException('Message secret must be 32 bytes');
            $secrets['messageSecret'] = $secret;
        }
        $bytes = SchemaCodec::encode('Message', $message);
        [$attrs,$encAttrs,$meta,$button] = self::attributes($message);
        // Legacy buttons omit enc mediatype and retain the text stanza type.
        // Lists retain media/list in individual chats as well as groups.
        if (isset($unwrapped['buttonsMessage'])) {
            $attrs['type'] = 'text';
            unset($encAttrs['mediatype']);
        }
        $companions = InteractiveStanza::build($message,intdiv($now,1000));
        if (($options['type'] ?? null) === 'provider-notice') {
            $companions['nodes'] = [...$companions['nodes'], ...ProviderNoticeContent::companionNodes($options,intdiv($now,1000))];
        }
        $attrs = [...$attrs,...$companions['attrs']];
        if (isset($contextOptions['pushPriority'])) {
            if (!in_array($contextOptions['pushPriority'],['high','high_force'],true)) throw new \InvalidArgumentException('Invalid push priority');
            $attrs['push_priority'] = $contextOptions['pushPriority'];
        }
        return ['bytes'=>$bytes,'attrs'=>$attrs,'encAttrs'=>$encAttrs,'secrets'=>$secrets,'metaAttrs'=>$meta,'buttonAddonKind'=>$button,'companionNodes'=>$companions['nodes'],'proto'=>$message];
    }

    /**
     * @param array<string,mixed> $c
     * @param array<string,mixed> $context
     * @return array<string,mixed> */
    private static function typed(array $c, string $to, array $context, int $now): array
    {
        $type = $c['type'];
        if (!is_string($type)) throw new \InvalidArgumentException('Message type must be a string');
        if ($type === 'raw') return self::array($c['message'] ?? null, 'raw message');
        $advanced = AdvancedContent::build($c, $to, $context, $now);
        if ($advanced !== null) return $advanced;
        $modern = ModernContent::build($c, $to, $context, $now);
        if ($modern !== null) return $modern;
        if ($type === 'form') return NativeFormBuilder::build($c);
        if ($type === 'business-form') return BusinessFormBuilder::build($c);
        if ($type === 'carousel') return CarouselContent::build($c);
        if (in_array($type, ['flow','flow-navigate','flow-exchange','flow.navigate','flow.exchange'], true)) return FlowBuilder::build($c);
        if ($type === 'buttons' || $type === 'legacy-buttons') {
            $mode = $c['buttonMode'] ?? 'native';
            if (!in_array($mode,['native','text'],true) || ($type === 'legacy-buttons' && isset($c['buttonMode']))) throw new \InvalidArgumentException('buttons buttonMode must be native or text');
            $body = isset($c['data']) ? self::array($c['data'],'buttons data') : $c;
            foreach (['type','viewOnce','quote','forward','mentions','pushPriority','ephemeralExpiration','buttonMode'] as $option) unset($body[$option]);
            if ($type === 'legacy-buttons') return ['buttonsMessage'=>$body];
            return $mode === 'text' ? ButtonsBuilder::textFallback($body) : ButtonsBuilder::build($body);
        }
        if ($type === 'text') {
            $text = self::string($c['text'] ?? null, 'text'); MessageCodec::text($text);
            $body = ['text'=>$text];
            if (isset($c['linkPreview']) && is_array($c['linkPreview'])) $body += $c['linkPreview'];
            elseif (($c['linkPreview'] ?? false) === true) throw new \InvalidArgumentException('Automatic link fetching is unavailable; provide linkPreview fields');
            return ['extendedTextMessage'=>$body];
        }
        if (in_array($type,['reaction','revoke','pin','unpin','keep','unkeep','edit'],true)) {
            $target = $c['target'] ?? ($c['edit'] ?? null);
            if ($type === 'edit' && $target === null && isset($c['id'])) $target = ['id'=>$c['id'],'fromMe'=>true];
            $key = self::target(self::array($target,'target'), $to, $type === 'edit');
            return match ($type) {
                'reaction'=>['reactionMessage'=>['key'=>$key,'text'=>self::string($c['emoji'] ?? null,'emoji'),'senderTimestampMs'=>$c['senderTimestampMs'] ?? $now]],
                'revoke'=>['protocolMessage'=>['key'=>$key,'type'=>'REVOKE']],
                'pin','unpin'=>['pinInChatMessage'=>['key'=>$key,'type'=>$type === 'pin' ? 'PIN_FOR_ALL' : 'UNPIN_FOR_ALL','senderTimestampMs'=>$c['senderTimestampMs'] ?? $now],
                    'messageContextInfo'=>['messageAddOnDurationInSecs'=>$type === 'pin' ? ($c['durationSecs'] ?? 86400) : 0]],
                'keep','unkeep'=>['keepInChatMessage'=>['key'=>$key,'keepType'=>$type === 'keep' ? 'KEEP_FOR_ALL' : 'UNDO_KEEP_FOR_ALL','timestampMs'=>$c['timestampMs'] ?? $now]],
                'edit'=>['protocolMessage'=>['key'=>$key,'type'=>'MESSAGE_EDIT','editedMessage'=>self::build($c['content'] ?? $c['message'] ?? ['type'=>'text','text'=>$c['text'] ?? null],$to,$context)['proto'],'timestampMs'=>$c['timestampMs'] ?? $now]],
            };
        }
        if ($type === 'poll') {
            $options = self::array($c['options'] ?? null,'poll options');
            if (!array_is_list($options) || count($options) < 1 || count($options) > 12) throw new \InvalidArgumentException('Poll requires 1 to 12 options');
            $body = ['name'=>self::nonempty($c['name'] ?? null,'poll name'),'options'=>[], 'selectableOptionsCount'=>$c['selectableCount'] ?? 1];
            foreach ($options as $option) $body['options'][] = ['optionName'=>self::nonempty(is_array($option) ? ($option['name'] ?? null) : $option,'poll option')];
            if (!is_int($body['selectableOptionsCount']) || $body['selectableOptionsCount'] < 0 || $body['selectableOptionsCount'] > count($options)) throw new \InvalidArgumentException('Invalid selectable poll count');
            foreach (['allowAddOption','hideParticipantName'] as $flag) if (($c[$flag] ?? false) === true) $body[$flag] = true;
            $field = isset($body['allowAddOption']) || isset($body['hideParticipantName']) ? 'pollCreationMessageV6' : ($body['selectableOptionsCount'] === 1 ? 'pollCreationMessageV3' : 'pollCreationMessage');
            return [$field=>$body];
        }
        if ($type === 'event') {
            if(array_key_exists('cover',$c))throw new \InvalidArgumentException('Event covers require MessageService authenticated media preparation');
            $body = self::pick($c,['name','description','startTime','endTime','joinLink','extraGuestsAllowed','isScheduleCall','isCanceled','hasReminder','reminderOffsetSec']);
            $body['name'] = self::nonempty($c['name'] ?? null,'event name');
            if (!isset($c['startTime']) || !is_int($c['startTime']) || $c['startTime'] < 0) throw new \InvalidArgumentException('Event startTime must be Unix seconds');
            if (isset($c['endTime']) && $c['endTime'] < $c['startTime']) throw new \InvalidArgumentException('Event endTime precedes startTime');
            $body['isCanceled'] ??= false;
            if (isset($c['location'])) $body['location'] = self::location(self::array($c['location'],'event location'));
            return ['eventMessage'=>$body];
        }
        if ($type === 'poll-vote' || $type === 'event-response') {
            $parent = self::array($c[$type === 'poll-vote' ? 'poll' : 'event'] ?? null, 'parent message');
            $sender = self::bare(self::nonempty($context['meJid'] ?? null, 'addon sender meJid'));
            self::nonempty($context['outgoingId'] ?? null, 'outgoingId');
            $parentId = self::nonempty($parent['id'] ?? null,'parent id');
            $author = self::bare(self::nonempty($parent['authorJid'] ?? null,'parent authorJid'));
            $secret = self::string($parent['messageSecret'] ?? null,'parent messageSecret');
            if ($type === 'poll-vote') {
                $names = self::array($c['selectedOptionNames'] ?? null,'selectedOptionNames');
                if (!array_is_list($names)) throw new \InvalidArgumentException('Selected options must be a list');
                foreach($names as $name) self::nonempty($name,'selected option');
                if (count(array_unique($names,SORT_REGULAR))!==count($names)) throw new \InvalidArgumentException('Selected options must be unique');
                $original=$parent['pollCreation']??null;
                if (!is_array($original) && is_array($parent['message']??null)) foreach($parent['message'] as $field=>$body) {
                    if(str_starts_with((string)$field,'pollCreationMessage')&&is_array($body)){$original=$body;break;}
                }
                if(is_array($original)) {
                    $limit=$original['selectableOptionsCount']??1;
                    if(is_int($limit)&&$limit>0&&count($names)>$limit)throw new \InvalidArgumentException('Too many options for the original poll');
                    $known=array_column($original['options']??[],'optionName');
                    if(array_diff($names,$known)!==[])throw new \InvalidArgumentException('Vote refers to an unknown poll option');
                }
                $hashes = [];
                foreach ($names as $name) {
                    $option=['optionName'=>self::nonempty($name,'selected option')];
                    $hashes[]=PollOptionHash::vote($option);
                }
                // An empty selection withdraws the previous vote.
                $plain = SchemaCodec::encode('Message.PollVoteMessage',['selectedOptions'=>$hashes]);
                $encrypted = AddonCrypto::encrypt($secret,$parentId,$author,$sender,'Poll Vote',$plain);
                return ['pollUpdateMessage'=>['pollCreationMessageKey'=>self::target($parent,$to),'vote'=>$encrypted,'senderTimestampMs'=>$c['senderTimestampMs'] ?? $now]];
            }
            $response = match ($c['response'] ?? null) {'going'=>'GOING','not_going'=>'NOT_GOING','maybe'=>'MAYBE',default=>throw new \InvalidArgumentException('Invalid event response')};
            $body = ['response'=>$response,'timestampMs'=>$c['timestampMs'] ?? $now] + self::pick($c,['extraGuestCount']);
            if (isset($body['extraGuestCount']) && (!is_int($body['extraGuestCount']) || $body['extraGuestCount'] < 0)) throw new \InvalidArgumentException('extraGuestCount cannot be negative');
            $encrypted = AddonCrypto::encrypt($secret,$parentId,$author,$sender,'Event Response',SchemaCodec::encode('Message.EventResponseMessage',$body));
            return ['encEventResponseMessage'=>['eventCreationMessageKey'=>self::target($parent,$to)] + $encrypted];
        }
        if ($type === 'contact') {
            $body = self::pick($c,['displayName','vcard','isSelfContact']);
            $body['vcard'] = self::nonempty($c['vcard'] ?? null,'contact vcard');
            return ['contactMessage'=>$body];
        }
        if ($type === 'contacts') {
            $contacts = self::array($c['contacts'] ?? null,'contacts');
            if ($contacts === [] || !array_is_list($contacts)) throw new \InvalidArgumentException('contacts requires a non-empty list');
            foreach ($contacts as $contact) {
                $contact = self::array($contact,'contact');
                self::nonempty($contact['vcard'] ?? null,'contact vcard');
            }
            return ['contactsArrayMessage'=>['displayName'=>$c['displayName'] ?? '', 'contacts'=>$contacts]];
        }
        if ($type === 'location' || $type === 'live-location') return [$type === 'location' ? 'locationMessage' : 'liveLocationMessage'=>self::location($c)];
        $field = match ($type) {
            'gif'=>'videoMessage','ptt'=>'audioMessage','lottie-sticker'=>'stickerMessage',
            'sticker-pack'=>'stickerPackMessage','list-response'=>'listResponseMessage','buttons-response'=>'buttonsResponseMessage',
            'interactive-response'=>'interactiveResponseMessage','group-invite'=>'groupInviteMessage',
            'newsletter-follower-invite','channel-invite'=>'newsletterFollowerInviteMessageV2',
            default=>lcfirst(str_replace(' ','',ucwords(str_replace('-',' ',$type)))).'Message',
        };
        if (!isset(SchemaCodec::fields('Message')[$field])) throw new \InvalidArgumentException('Unknown message type '.$type.'; raw WAProto requires a valid schema field');
        if (isset($c['prepared'])) $body = self::array($c['prepared'],'prepared media');
        else {
            if (isset($c['media'])) throw new \InvalidArgumentException('Media must be prepared by MediaTransfer');
            $body = isset($c['data']) ? self::array($c['data'],'message data') : $c;
            foreach (['type','contextInfo','viewOnce','quote','forward','mentions','pushPriority','ephemeralExpiration','spoiler','messageId'] as $option) unset($body[$option]);
        }
        if ($type === 'gif') $body['gifPlayback'] = true;
        if ($type === 'ptt') $body['ptt'] = true;
        if($type==='lottie-sticker'){
            if(!isset($c['prepared']))throw new \InvalidArgumentException('Native Lottie media requires authenticated preparation');
            if(($body['mimetype']??null)!=='application/was')throw new \InvalidArgumentException('Native Lottie sticker requires application/was');$body['isLottie']=true;
        }
        return [$field=>$body];
    }

    /**
     * @param array<string,mixed> $message
     * @return array<string,mixed>
     */
    private static function applyViewOnce(array $message, int $depth = 0): array
    {
        if ($depth > 8) throw new \LengthException('Message wrapper nesting limit exceeded');
        foreach (['imageMessage','videoMessage','ptvMessage','audioMessage'] as $field) {
            if (isset($message[$field])) { $message[$field]['viewOnce'] = true; return $message; }
        }
        foreach (SchemaCodec::fields('Message') as $name => $field) {
            if (in_array($field['type'],['Message.FutureProofMessage','Message.DeviceSentMessage'],true) && isset($message[$name]['message']) && is_array($message[$name]['message'])) {
                $message[$name]['message'] = self::applyViewOnce($message[$name]['message'],$depth + 1);
                return $message;
            }
        }
        throw new \InvalidArgumentException('View-once requires image, video, PTV or audio');
    }

    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    private static function location(array $input): array
    {
        $lat = $input['latitude'] ?? $input['degreesLatitude'] ?? null; $lon = $input['longitude'] ?? $input['degreesLongitude'] ?? null;
        if ((!is_float($lat) && !is_int($lat)) || (!is_float($lon) && !is_int($lon)) || !is_finite((float)$lat) || !is_finite((float)$lon) || $lat < -90 || $lat > 90 || $lon < -180 || $lon > 180) throw new \InvalidArgumentException('Invalid latitude or longitude');
        return ['degreesLatitude'=>$lat,'degreesLongitude'=>$lon] + self::pick($input,['name','address','url','isLive','accuracyInMeters','speedInMps','degreesClockwiseFromMagneticNorth','comment','caption','sequenceNumber','timeOffset','jpegThumbnail']);
    }

    /**
     * @param array<string,mixed> $target
     * @return array<string,mixed> */
    private static function target(array $target, string $to, bool $forceMine = false): array
    {
        if (isset($target['key'])) $target = self::array($target['key'],'message key');
        $key = ['remoteJid'=>$to,'id'=>self::nonempty($target['id'] ?? null,'target id'),'fromMe'=>$forceMine ? true : ($target['fromMe'] ?? false)];
        if (preg_match('/@(g\.us|broadcast)$/', $to) === 1 && isset($target['participant'])) $key['participant'] = $target['participant'];
        return $key;
    }

    public static function bare(string $jid): string { return preg_replace('/:\d+(?=@)/','',$jid) ?? $jid; }

    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    private static function context(array $options, string $to): array
    {
        $input = self::array($options['contextInfo'] ?? [],'contextInfo');
        $ctx = [];
        $aliases = ['quotedMessageId'=>'stanzaId','quotedParticipant'=>'participant','quotedRemoteJid'=>'remoteJid','mentionedJids'=>'mentionedJid','expirationSeconds'=>'expiration'];
        foreach ($input as $key => $value) {
            if ($key === 'raw') continue;
            if ($key === 'disappearingModeInitiator') { $ctx['disappearingMode']['initiator'] = $value; continue; }
            if ($key === 'disappearingModeTrigger') { $ctx['disappearingMode']['trigger'] = $value; continue; }
            $ctx[$aliases[$key] ?? $key] = $value;
        }
        $ctx = array_replace($ctx, self::array($input['raw'] ?? [],'raw contextInfo'));
        if (isset($options['quote'])) {
            $quote = self::array($options['quote'],'quote'); $key = $quote['key'] ?? $quote;
            $ctx['stanzaId'] = self::nonempty($key['id'] ?? null,'quoted id');
            $remote = $key['remoteJid'] ?? $to;
            $participant = $key['participant'] ?? (($key['fromMe'] ?? false) ? ($options['meLid'] ?? $options['meJid'] ?? null) : $remote);
            if ($participant !== null) $ctx['participant'] = self::bare($participant);
            if (self::bare($remote) !== self::bare($to)) $ctx['remoteJid'] = $remote;
            else unset($ctx['remoteJid']);
            if (isset($quote['message'])) $ctx['quotedMessage'] = $quote['message'];
            elseif (isset($quote['proto'])) $ctx['quotedMessage'] = $quote['proto'];
            if(array_key_exists('selectedText',$quote)){
                if(!is_array($ctx['quotedMessage']??null))throw new \InvalidArgumentException('Partial quote requires the original message');
                $ctx['partiallySelectedContent']=['text'=>QuoteSelection::validate($ctx['quotedMessage'],$quote['selectedText'])];
                $ctx['quotedType']='EXPLICIT';
            }
        }
        if (($options['forward'] ?? false) !== false) {
            $ctx['isForwarded'] = true; $ctx['forwardingScore'] = is_array($options['forward']) ? ($options['forward']['score'] ?? (($ctx['forwardingScore'] ?? 0) + 1)) : (($ctx['forwardingScore'] ?? 0) + 1);
        }
        if (isset($options['mentions'])) $ctx['mentionedJid'] = $options['mentions'];
        return $ctx;
    }

    /**
     * @param array<string,mixed> $message
     * @param array<string,mixed> $context
     * @return array<string,mixed> */
    private static function applyContext(array $message, array $context): array
    {
        if (isset($message['conversation'])) { $message['extendedTextMessage'] = ['text'=>$message['conversation']]; unset($message['conversation']); }
        foreach ($message as $name => $body) {
            $field = SchemaCodec::fields('Message')[$name] ?? null;
            if ($field === null || !is_array($body)) continue;
            if (in_array($field['type'],['Message.FutureProofMessage','Message.DeviceSentMessage'],true) && isset($body['message'])) {
                $message[$name]['message'] = self::applyContext($body['message'],$context); return $message;
            }
            if (isset(SchemaCodec::fields($field['type'])['contextInfo'])) {
                $message[$name]['contextInfo'] = array_replace($body['contextInfo'] ?? [], $context); return $message;
            }
        }
        throw new \InvalidArgumentException('This message type has no contextInfo field');
    }

    /**
     * @param array<string,mixed> $message
     * @return array{array<string,string>,array<string,string>,array<string,string>,?string} */
    private static function attributes(array $message): array
    {
        $unwrapped = ContentDecoder::unwrap($message);
        $m = $unwrapped['message']; $attrs = ['type'=>'media']; $enc = []; $meta = []; $button = null;
        if(in_array('groupStatusMessageV2',$unwrapped['wrappers'],true))$meta['is_group_status']='true';
        $secretType = $m['secretEncryptedMessage']['secretEncType'] ?? 0;
        if (isset($m['reactionMessage']) || isset($m['encReactionMessage'])) $attrs['type'] = 'reaction';
        elseif (isset($m['eventMessage']) || isset($m['encEventResponseMessage']) || in_array($secretType,[1,'EVENT_EDIT'],true)) $attrs['type'] = 'event';
        elseif (isset($m['pollUpdateMessage']) || in_array($secretType,[4,5,'POLL_EDIT','POLL_ADD_OPTION'],true)) $attrs['type'] = 'poll';
        elseif (isset($m['conversation']) || (isset($m['extendedTextMessage']) && empty($m['extendedTextMessage']['matchedText']))) $attrs['type'] = 'text';
        elseif (isset($m['albumMessage'])) $attrs['type'] = 'text';
        foreach (array_keys($m) as $field) {
            if (str_starts_with($field,'pollCreationMessage')) { $attrs['type'] = 'poll'; $meta['polltype'] = 'creation'; }
            if (in_array($field,['protocolMessage','interactiveMessage','keepInChatMessage','requestPhoneNumberMessage','editedMessage','pinInChatMessage','encCommentMessage','newsletterAdminInviteMessage','newsletterFollowerInviteMessageV2','pollResultSnapshotMessage','pollResultSnapshotMessageV3','templateButtonReplyMessage','messageHistoryNotice','richResponseMessage','botForwardedMessage'],true)) $attrs['type'] = 'text';
        }
        if (in_array($secretType,[2,3,'MESSAGE_EDIT','MESSAGE_SCHEDULE'],true)) $attrs['type'] = 'text';
        $protocol = $m['protocolMessage'] ?? [];
        if (isset($m['protocolMessage'])) {
            $pt = $protocol['type'] ?? 0;
            if (in_array($pt,[0,'REVOKE'],true)) $attrs['edit'] = ($protocol['key']['fromMe'] ?? true) === false ? '8' : '7';
            elseif (in_array($pt,[14,'MESSAGE_EDIT'],true)) $attrs['edit'] = '1';
        }
        if (isset($m['editedMessage']) || in_array($secretType,[1,2,'EVENT_EDIT','MESSAGE_EDIT'],true)) $attrs['edit'] = '1';
        if (isset($m['reactionMessage']) && ($m['reactionMessage']['text'] ?? null) === '') $attrs['edit'] = '7';
        if (isset($m['pinInChatMessage'])) $attrs['edit'] = '2';
        if (isset($m['keepInChatMessage']) && ($m['keepInChatMessage']['key']['fromMe'] ?? false) && in_array($m['keepInChatMessage']['keepType'] ?? 0,[2,'UNDO_KEEP_FOR_ALL'],true)) $attrs['edit'] = '7';
        $mediaTypes = ['imageMessage'=>'image','videoMessage'=>'video','ptvMessage'=>'ptv','audioMessage'=>'audio','documentMessage'=>'document','stickerMessage'=>'sticker','stickerPackMessage'=>'sticker_pack','locationMessage'=>'location','liveLocationMessage'=>'livelocation','contactMessage'=>'vcard','contactsArrayMessage'=>'contact_array','listMessage'=>'list','listResponseMessage'=>'list_response','buttonsMessage'=>'button','buttonsResponseMessage'=>'buttons_response','orderMessage'=>'order','productMessage'=>'product','groupInviteMessage'=>'url','interactiveResponseMessage'=>'native_flow_response','messageHistoryBundle'=>'group_history','scheduledCallCreationMessage'=>'scheduled_call','scheduledCallEditMessage'=>'scheduled_call_edit'];
        foreach ($mediaTypes as $field => $mediaType) if (isset($m[$field])) { $enc['mediatype'] = $mediaType; break; }
        if ($m['audioMessage']['ptt'] ?? false) $enc['mediatype'] = 'ptt';
        if ($m['videoMessage']['gifPlayback'] ?? false) $enc['mediatype'] = 'gif';
        if ($m['locationMessage']['isLive'] ?? false) $enc['mediatype'] = 'livelocation';
        if (!empty($m['extendedTextMessage']['matchedText'])) $enc['mediatype'] = 'url';
        foreach (['reactionMessage','encReactionMessage','pollUpdateMessage','keepInChatMessage','editedMessage','pinInChatMessage','encCommentMessage','encEventResponseMessage','messageHistoryNotice'] as $field) if (isset($m[$field])) $enc['decrypt-fail'] = 'hide';
        if (in_array($secretType,[1,4,5,'EVENT_EDIT','POLL_EDIT','POLL_ADD_OPTION'],true) || isset($protocol['editedMessage']) || in_array($protocol['type'] ?? null,[4,18,'EPHEMERAL_SYNC_RESPONSE','REQUEST_WELCOME_MESSAGE'],true)) $enc['decrypt-fail'] = 'hide';
        if (isset($m['pollUpdateMessage'])) $meta['polltype'] = 'vote';
        if (in_array($secretType,[4,5,'POLL_EDIT','POLL_ADD_OPTION'],true)) $meta['polltype'] = 'edit';
        if (isset($m['eventMessage'])) $meta['event_type'] = 'creation';
        elseif (isset($m['encEventResponseMessage'])) $meta['event_type'] = 'response';
        elseif (in_array($secretType,[1,'EVENT_EDIT'],true)) $meta['event_type'] = 'edit';
        foreach (['imageMessage','videoMessage','ptvMessage','audioMessage'] as $field) if ($m[$field]['viewOnce'] ?? false) $meta['view_once'] = 'true';
        if (array_intersect($unwrapped['wrappers'],['viewOnceMessage','viewOnceMessageV2','viewOnceMessageV2Extension']) !== []) $meta['view_once'] = 'true';
        if (isset($m['listMessage'])) $button = 'list';
        elseif (isset($m['buttonsMessage'])) $button = 'interactive';
        elseif (isset($m['interactiveMessage']['nativeFlowMessage'])) $button = match ($m['interactiveMessage']['nativeFlowMessage']['buttons'][0]['name'] ?? '') {'payment_info'=>'payment_info','review_and_pay'=>'order_details',default=>'interactive'};
        $modern=ModernContent::attributes($message);
        return [array_replace($attrs,$modern['attrs']),array_replace($enc,$modern['encAttrs']),array_replace($meta,$modern['metaAttrs']),$button];
    }

    /**
     * @param array<string,mixed> $input
     * @param list<string> $keys
     * @return array<string,mixed> */
    private static function pick(array $input, array $keys): array { return array_intersect_key($input,array_flip($keys)); }

    private static function string(mixed $value, string $label): string
    {
        if (!is_string($value)) throw new \InvalidArgumentException($label.' must be a string');
        return $value;
    }
    private static function nonempty(mixed $value, string $label): string
    {
        $value = self::string($value,$label);
        if (trim($value) === '' || preg_match('//u',$value) !== 1) throw new \InvalidArgumentException($label.' cannot be empty and must be UTF-8');
        return $value;
    }
    /**
     * @return array<mixed> */
    private static function array(mixed $value, string $label): array
    {
        if (!is_array($value)) throw new \InvalidArgumentException($label.' must be an array');
        return $value;
    }
}
