<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use MVO\Message\MessageCodec;
use MVO\Protocol\SchemaCodec;

final class MessageCodecTest extends TestCase
{
    public function testConversationMatchesProtobufWireFixture(): void
    {
        self::assertSame('0a044f6cc3a1', bin2hex(MessageCodec::text('Olá')));
        self::assertSame("\x0a\x04Ol\xc3\xa1", MessageCodec::text('Olá'));
        self::assertSame('Olá', MessageCodec::decode("\x0a\x04Ol\xc3\xa1")['text']);
    }

    public function testExtendedTextAndOwnDeviceWrapper(): void
    {
        // Message.extendedTextMessage(6).text(1), independent wire fixture.
        self::assertSame('oi', MessageCodec::decode(hex2bin('32040a026f69'))['text']);
        $wrapped = MessageCodec::deviceSent(MessageCodec::text('oi'), '5511000000000@s.whatsapp.net');
        $decoded = MessageCodec::decode($wrapped);
        self::assertSame('oi', $decoded['text']);
        self::assertSame('5511000000000@s.whatsapp.net', $decoded['destination']);
        self::assertSame('text', $decoded['kind']);
    }

    public function testEphemeralMessageUnwrapsAndMediaCaptionsRemainSeparateFromText(): void
    {
        $text = MessageCodec::bytes(40, MessageCodec::bytes(1, MessageCodec::text('secret')));
        self::assertSame('secret', MessageCodec::decode($text)['text']);
        $media = MessageCodec::bytes(3, MessageCodec::bytes(3, 'caption'));
        self::assertNull(MessageCodec::decode($media)['text']);
        self::assertSame('image', MessageCodec::decode($media)['kind']);
        self::assertSame('caption', MessageCodec::decode($media)['data']['caption']);
        self::assertSame('protocol', MessageCodec::decode(MessageCodec::bytes(12, ''))['kind']);
    }

    public function testOwnDeviceEnvelopeCopiesMessageContextOutsideDeviceSentMessage():void
    {
        $context=['deviceListMetadataVersion'=>2,'messageSecret'=>str_repeat("\xff",32)];
        $message=['conversation'=>'self','messageContextInfo'=>$context];
        $bytes=SchemaCodec::encode('Message',$message);
        $wrapped=SchemaCodec::decode('Message',MessageCodec::deviceSent($bytes,'123@lid'));
        self::assertSame($context,$wrapped['messageContextInfo']);
        self::assertSame($message,$wrapped['deviceSentMessage']['message']);
        self::assertSame('123@lid',$wrapped['deviceSentMessage']['destinationJid']);
    }

    public function testPaddingIsValidForEveryLegalLength(): void
    {
        for ($length=1; $length<=16; ++$length) {
            self::assertSame('payload', MessageCodec::unpad('payload'.str_repeat(chr($length), $length)));
        }
        $padded = MessageCodec::pad('payload');
        self::assertSame('payload', MessageCodec::unpad($padded));
        self::assertGreaterThanOrEqual(8, strlen($padded));
        self::assertLessThanOrEqual(23, strlen($padded));
    }

    /** @return list<array{string}> */
    public static function invalidPadding(): array { return [[''],["a\0"],["a\x02"],["foo\x03\x02\x03"],[str_repeat("\x11",17)]]; }

    #[DataProvider('invalidPadding')]
    public function testInvalidPaddingCannotBecomeAValidMessage(string $bytes): void
    {
        $this->expectException(\UnexpectedValueException::class);
        MessageCodec::unpad($bytes);
    }

    public function testNestedWrappersHaveABoundedDepth(): void
    {
        $bytes = MessageCodec::text('hi');
        for ($n=0; $n<10; ++$n) $bytes = MessageCodec::bytes(40, MessageCodec::bytes(1, $bytes));
        $this->expectException(\LengthException::class);
        MessageCodec::decode($bytes);
    }

    public function testInvalidUtf8RejectedBeforeEncryption(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MessageCodec::text("\xff");
    }
}
