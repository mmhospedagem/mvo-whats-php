<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Message\InteractiveStanza;
use MVO\Protocol\BinaryNode;

final class InteractiveStanzaTest extends TestCase
{
    private const NOW = 1763059077;

    /** Static fixtures cover native flows, carousels, order review and response companions. */
    public static function companionFixtures(): iterable
    {
        $business = ['actual_actors'=>'2','host_storage'=>'2','privacy_mode_ts'=>'1763059077'];
        $mixed = ['tag'=>'interactive','attrs'=>['type'=>'native_flow','v'=>'1'], 'content'=>[
            ['tag'=>'native_flow','attrs'=>['v'=>'9','name'=>'mixed']],
        ]];
        $biz = ['tag'=>'biz','attrs'=>$business,'content'=>[$mixed]];
        yield 'quick reply'=>[
            ['interactiveMessage'=>['nativeFlowMessage'=>['buttons'=>[['name'=>'quick_reply']]]]],
            ['device_fanout'=>'false'], [$biz],
        ];
        yield 'multiple native flow actions'=>[
            ['interactiveMessage'=>['nativeFlowMessage'=>['buttons'=>[['name'=>'cta_url'],['name'=>'cta_call']]]]],
            ['device_fanout'=>'false'], [$biz],
        ];
        yield 'empty native flow still has companion'=>[
            ['interactiveMessage'=>['nativeFlowMessage'=>[]]], ['device_fanout'=>'false'], [$biz],
        ];
        yield 'Galaxy Flow CTA'=>[
            ['interactiveMessage'=>['nativeFlowMessage'=>['buttons'=>[['name'=>'galaxy_message']]]]],
            ['device_fanout'=>'false'], [$biz],
        ];
        yield 'local form selects the Android form parser'=>[
            ['interactiveMessage'=>['nativeFlowMessage'=>['buttons'=>[['name'=>'form_message']]]]],
            ['device_fanout'=>'false'], [['tag'=>'biz','attrs'=>[],'content'=>[
                ['tag'=>'interactive','attrs'=>['type'=>'native_flow','v'=>'1'],'content'=>[
                    ['tag'=>'native_flow','attrs'=>['name'=>'form_message']],
                ]],
            ]]],
        ];
        yield 'location action uses the accepted message alias in a single envelope'=>[
            ['interactiveMessage'=>['nativeFlowMessage'=>['buttons'=>[['name'=>'send_location']]]]],
            ['device_fanout'=>'false'], [['tag'=>'biz','attrs'=>[],'content'=>[
                ['tag'=>'interactive','attrs'=>['type'=>'native_flow','v'=>'1'],'content'=>[
                    ['tag'=>'native_flow','attrs'=>['name'=>'location_request_message']],
                ]],
            ]]],
        ];
        yield 'legacy buttons'=>[['buttonsMessage'=>['buttons'=>[]]], [], [$biz]];
        yield 'carousel companion metadata'=>[['interactiveMessage'=>['carouselMessage'=>['cards'=>[]]]], ['device_fanout'=>'false'], [$biz]];
        yield 'list'=>[
            ['listMessage'=>['listType'=>1,'sections'=>[]]], [],
            [['tag'=>'biz','attrs'=>[], 'content'=>[['tag'=>'list','attrs'=>['type'=>'product_list','v'=>'2']]]]],
        ];
        yield 'product list'=>[
            ['listMessage'=>['listType'=>2]], [],
            [['tag'=>'biz','attrs'=>[], 'content'=>[['tag'=>'list','attrs'=>['type'=>'product_list','v'=>'2']]]]],
        ];
        foreach (['buttonsResponseMessage'=>'buttons_response','listResponseMessage'=>'list_response',
            'interactiveResponseMessage'=>'interactive_response','interactiveMessage'=>'interactive'] as $field=>$tag) {
            yield $tag=>[[$field=>[]], $field === 'interactiveMessage' ? ['device_fanout'=>'false'] : [],
                [['tag'=>'biz','attrs'=>[], 'content'=>[['tag'=>$tag,'attrs'=>[], 'content'=>[]]]]],
            ];
        }
        foreach (['payment_info'=>'payment_info','review_and_pay'=>'order_details'] as $name=>$flowName) {
            yield $name=>[
                ['interactiveMessage'=>['nativeFlowMessage'=>['buttons'=>[['name'=>$name]]]]],
                ['device_fanout'=>'false'], [['tag'=>'biz','attrs'=>['native_flow_name'=>$flowName]]],
            ];
        }
        foreach (['mpm','cta_catalog','call_permission_request',
            'wa_payment_transaction_details','automated_greeting_message_view_catalog','review_order'] as $name) {
            yield $name=>[
                ['interactiveMessage'=>['nativeFlowMessage'=>['buttons'=>[['name'=>$name]]]]],
                ['device_fanout'=>'false'], [['tag'=>'biz','attrs'=>[], 'content'=>[
                    ['tag'=>'biz','attrs'=>$business,'content'=>[
                        ['tag'=>'interactive','attrs'=>['type'=>'native_flow','v'=>'1'], 'content'=>[
                            ['tag'=>'native_flow','attrs'=>['v'=>'2','name'=>$name]],
                        ]],
                        ['tag'=>'quality_control','attrs'=>['source_type'=>'third_party']],
                    ]],
                ]]],
            ];
        }
    }

    #[DataProvider('companionFixtures')]
    public function testMatchesCompanionMetadataFixtures(array $message, array $attrs, array $nodes): void
    {
        $before = $message;
        $built = InteractiveStanza::build($message,self::NOW);
        self::assertSame($attrs,$built['attrs']);
        self::assertSame($nodes,array_map(self::nodeFixture(...),$built['nodes']));
        self::assertSame($before,$message);
    }

    public static function wrappers(): iterable
    {
        foreach (['viewOnceMessage','viewOnceMessageV2','viewOnceMessageV2Extension',
            'ephemeralMessage','documentWithCaptionMessage','deviceSentMessage'] as $wrapper) yield $wrapper=>[$wrapper];
    }

    #[DataProvider('wrappers')]
    public function testCompanionsSurviveMessageWrappers(string $wrapper): void
    {
        foreach (self::companionFixtures() as [$message]) {
            $wrapped = [$wrapper=>['message'=>$message]];
            self::assertEquals(InteractiveStanza::build($message,self::NOW),InteractiveStanza::build($wrapped,self::NOW));
        }
    }

    public function testNestedWrappersAndSuppliedTimestampRemainDeterministic(): void
    {
        $message = ['ephemeralMessage'=>['message'=>['viewOnceMessageV2'=>['message'=>[
            'interactiveMessage'=>['nativeFlowMessage'=>['buttons'=>[['name'=>'quick_reply']]]],
        ]]]]];
        $first = InteractiveStanza::build($message,1234);
        self::assertEquals($first,InteractiveStanza::build($message,1234));
        self::assertSame('1234',$first['nodes'][0]->attrs['privacy_mode_ts']);
        self::assertSame('1235',InteractiveStanza::build($message,1235)['nodes'][0]->attrs['privacy_mode_ts']);
    }

    public function testOnlyFirstButtonSelectsSpecialCompanionBranch(): void
    {
        $built = InteractiveStanza::build(['interactiveMessage'=>['nativeFlowMessage'=>[
            'buttons'=>[['name'=>'quick_reply'],['name'=>'payment_info']],
        ]]],self::NOW);
        self::assertSame('mixed',$built['nodes'][0]->child('interactive')?->child('native_flow')?->attrs['name']);
    }

    public function testOrdinaryMessagesProduceNoCompanionsOrFanoutChanges(): void
    {
        foreach ([[],['conversation'=>'Hello'],['extendedTextMessage'=>['text'=>'Hello']],
            ['imageMessage'=>['caption'=>'Photo']],['templateMessage'=>['hydratedTemplate'=>[]]],
            ['reactionMessage'=>['text'=>'👍']],['viewOnceMessage'=>['message'=>['imageMessage'=>[]]]]] as $message) {
            self::assertSame(['attrs'=>[], 'nodes'=>[]],InteractiveStanza::build($message,self::NOW));
        }
    }

    /** @return array<string,mixed> */
    private static function nodeFixture(BinaryNode $node): array
    {
        $fixture = ['tag'=>$node->tag,'attrs'=>$node->attrs];
        if (is_array($node->content)) $fixture['content'] = array_map(self::nodeFixture(...),$node->content);
        elseif ($node->content !== null) $fixture['content'] = $node->content;
        return $fixture;
    }
}
