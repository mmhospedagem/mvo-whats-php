<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\BinaryNode;

/** Companion stanza metadata for interactive messages, including carousels and order review. */
final class InteractiveStanza
{
    private const SPECIAL_FLOWS = [
        'mpm', 'cta_catalog', 'call_permission_request',
        'wa_payment_transaction_details', 'automated_greeting_message_view_catalog',
        'payment_info', 'review_and_pay', 'review_order',
    ];

    /**
     * @param array<string,mixed> $message WAProto.Message, before per-device encryption.
     * @return array{attrs:array<string,string>,nodes:list<BinaryNode>}
     */
    public static function build(array $message, int $timestampSeconds, bool $isPrimary=false): array
    {
        $message = ContentDecoder::unwrap($message)['message'];
        $buttonType = self::buttonType($message);
        $attrs = isset($message['interactiveMessage']) ? ['device_fanout'=>'false'] : [];
        if ($buttonType === null) return ['attrs'=>$attrs, 'nodes'=>[]];

        $interactive = $message['interactiveMessage'] ?? null;
        $nativeFlow = is_array($interactive) ? ($interactive['nativeFlowMessage'] ?? null) : null;
        $firstButtonName = is_array($nativeFlow) ? ($nativeFlow['buttons'][0]['name'] ?? null) : null;
        $firstButtonName = is_string($firstButtonName) ? $firstButtonName : null;
        $businessAttrs = [
            'actual_actors'=>'2', 'host_storage'=>'2', 'privacy_mode_ts'=>(string)$timestampSeconds,
        ];

        if (is_array($nativeFlow) && $firstButtonName === 'form_message') {
            // Android selects its local form parser from the companion name.
            $biz = new BinaryNode('biz', [], [new BinaryNode('interactive', ['type'=>'native_flow','v'=>'1'], [
                new BinaryNode('native_flow', ['name'=>'form_message']),
            ])]);
        } elseif (is_array($nativeFlow) && $firstButtonName === 'send_location') {
            // The server routes this message alias; Android pairs it with the send_location action.
            $biz = new BinaryNode('biz', [], [new BinaryNode('interactive', ['type'=>'native_flow','v'=>'1'], [
                new BinaryNode('native_flow', ['name'=>'location_request_message']),
            ])]);
        } elseif (is_array($nativeFlow) && $firstButtonName === 'galaxy_message') {
            $metadata=self::businessFormMetadata($nativeFlow);
            // Initial native Business fanout does not force the legacy interactive override.
            if($metadata!==null)unset($attrs['device_fanout']);
            // Cpa.AUe: registered Business v4 uses its real name and nested extensions metadata.
            // Preserve the established envelope for other Galaxy messages and malformed/raw inputs.
            $biz=$metadata===null?new BinaryNode('biz',$businessAttrs,[self::flow('9','mixed')]):
                ($isPrimary?new BinaryNode('biz',[],[new BinaryNode('interactive',['type'=>'native_flow','v'=>'1'],[
                    new BinaryNode('native_flow',['name'=>'galaxy_message'],[new BinaryNode('extensions_metadata',$metadata)]),
                ])]):new BinaryNode('biz',['native_flow_name'=>'galaxy_message']));
        } elseif (is_array($nativeFlow) && in_array($firstButtonName, ['review_and_pay','payment_info'], true)) {
            $biz = new BinaryNode('biz', [
                'native_flow_name'=>$firstButtonName === 'review_and_pay' ? 'order_details' : 'payment_info',
            ]);
        } elseif (is_array($nativeFlow) && in_array($firstButtonName, self::SPECIAL_FLOWS, true)) {
            // These special flows use a nested business envelope for their flow metadata.
            $biz = new BinaryNode('biz', [], [new BinaryNode('biz', $businessAttrs, [
                self::flow('2', $firstButtonName),
                new BinaryNode('quality_control', ['source_type'=>'third_party']),
            ])]);
        } elseif (is_array($nativeFlow) || isset($message['buttonsMessage']) ||
            (is_array($interactive) && isset($interactive['carouselMessage']))) {
            $biz = new BinaryNode('biz', $businessAttrs, [self::flow('9','mixed')]);
        } elseif (isset($message['listMessage'])) {
            $biz = new BinaryNode('biz', [], [new BinaryNode('list', ['type'=>'product_list','v'=>'2'])]);
        } else {
            // Response companions retain an empty child content list for their response subtype.
            $biz = new BinaryNode('biz', [], [new BinaryNode($buttonType, [], [])]);
        }

        return ['attrs'=>$attrs, 'nodes'=>[$biz]];
    }

    /** @param array<string,mixed> $message */
    private static function buttonType(array $message): ?string
    {
        foreach ([
            'buttonsMessage'=>'buttons', 'buttonsResponseMessage'=>'buttons_response',
            'interactiveResponseMessage'=>'interactive_response', 'listMessage'=>'list',
            'listResponseMessage'=>'list_response', 'interactiveMessage'=>'interactive',
        ] as $field=>$type) {
            if (isset($message[$field])) return $type;
        }
        return null;
    }

    private static function flow(string $version, string $name): BinaryNode
    {
        return new BinaryNode('interactive', ['type'=>'native_flow','v'=>'1'], [
            new BinaryNode('native_flow', ['v'=>$version,'name'=>$name]),
        ]);
    }

    /** Recognizes only the typed registered Business v4 contract; this is not input validation or authorization.
     * @param array<string,mixed> $nativeFlow
     * @return array{well_version:string,flow_message_version:string}|null */
    private static function businessFormMetadata(array $nativeFlow):?array
    {
        $buttons=$nativeFlow['buttons']??null;
        if(($nativeFlow['messageVersion']??null)!==3||!is_array($buttons)||count($buttons)!==1)return null;
        $json=$buttons[0]['buttonParamsJson']??null;
        if(!is_string($json)||strlen($json)>262144)return null;
        try{$params=json_decode($json,false,32,JSON_THROW_ON_ERROR);}catch(\JsonException){return null;}
        if(!$params instanceof \stdClass||($params->flow_message_version??null)!=='4'||
            !in_array($params->form_type??null,['template','ai_form_attachment_tray'],true)||
            !is_string($params->flow_id??null)||preg_match('/^[1-9][0-9]{0,63}$/D',$params->flow_id)!==1||
            ($params->flow_action??null)!=='navigate'||!(($params->flow_action_payload??null) instanceof \stdClass)||
            !in_array($params->flow_action_payload->screen??null,['contact_details','delivery_address'],true)||
            !(($params->flow_action_payload->data??null) instanceof \stdClass)||
            !in_array($params->flow_token??null,[base64_encode('CUSTOMER_INFO'),base64_encode('OFFER_SIGNUP')],true))return null;
        $version=$params->well_version??null;$cta=$params->flow_cta??null;
        if(!is_string($version)||trim($version)===''||strlen($version)>128||preg_match('//u',$version)!==1||preg_match('/[\x00-\x1f\x7f]/',$version)===1||
            !is_string($cta)||trim($cta)===''||strlen($cta)>1024||preg_match('//u',$cta)!==1||str_contains($cta,"\0")||
            ($cta!=='__localize:FLOWS_COMPLETE_FORM_BUTTON_TITLE'&&preg_match_all('/./us',$cta)>30))return null;
        // The native writer removes every ASCII v/V without changing the encrypted button parameters.
        $wireVersion=str_ireplace('v','',$version);
        return trim($wireVersion)===''?null:['well_version'=>$wireVersion,'flow_message_version'=>'4'];
    }
}
