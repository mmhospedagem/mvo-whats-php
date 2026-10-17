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
    public static function build(array $message, int $timestampSeconds): array
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
            // Use the companion-compatible business envelope shared by native buttons.
            $biz = new BinaryNode('biz', $businessAttrs, [self::flow('9','mixed')]);
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
}
