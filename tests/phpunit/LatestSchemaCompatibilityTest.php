<?php
declare(strict_types=1);

namespace MVO\Tests;

use MVO\Message\{ContentBuilder, ContentDecoder, MessageCodec};
use MVO\Protocol\{ClientPayload, SchemaCodec};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Literal wire fixtures are authored independently from the PHP descriptor:
 * protobuf key = field number * 8 + wire type; strings/messages use a byte
 * length prefix, varints use base 128, and fixed32 uses little endian bytes.
 * Field numbers come from public Web snapshot 2.3000.1050579926; retained
 * legacy fixtures come from the previous checked-in snapshot.
 */
final class LatestSchemaCompatibilityTest extends TestCase
{
    public static function newContentVectors(): iterable
    {
        // Message#129 -> MusicMessage(songUri#2, artworkUri#3, style#4).
        yield 'music' => [
            ['musicMessage' => ['songUri' => 'song', 'artworkUri' => 'art', 'style' => 1]],
            '8a080d1204736f6e671a036172742001',
            'musicMessage',
        ];
        // Message#135 has the same body type as ImageMessage; caption is #3.
        yield 'instant image' => [
            ['instantImageMessage' => ['caption' => 'pic']],
            'ba08051a03706963',
            'instantImageMessage',
        ];
        // Message#136 -> RequestLocationMessage.contextInfo#1 -> stanzaId#1.
        yield 'request location' => [
            ['requestLocationMessage' => ['contextInfo' => ['stanzaId' => 'id']]],
            'c208060a040a026964',
            'requestLocationMessage',
        ];
        // Message#138 -> key#1/id#3, updateType#2=CANCEL, timestamp#3=150.
        yield 'cancel location request' => [
            ['requestLocationUpdateMessage' => ['key' => ['id' => 'id'], 'updateType' => 1, 'senderTimestampMs' => 150]],
            'd2080b0a041a0269641001189601',
            'requestLocationUpdateMessage',
        ];
        // Message#130 -> StatusLinkPreviewMetadata.style#1=IMMERSIVE(3).
        yield 'status link preview' => [
            ['statusLinkPreviewMetadata' => ['style' => 3]],
            '9208020803',
            'statusLinkPreviewMetadata',
        ];
    }

    #[DataProvider('newContentVectors')]
    public function testNewContentUsesThePublishedWireIdsThroughRawMessages(array $message, string $hex, string $field): void
    {
        $wire = hex2bin($hex);
        self::assertSame($wire, SchemaCodec::encode('Message', $message));
        self::assertSame($message, SchemaCodec::decode('Message', $wire));
        $built = ContentBuilder::build(['type' => 'raw', 'message' => $message], '1@lid');
        self::assertSame($wire, $built['bytes']);
        $event = ContentDecoder::decode($wire);
        self::assertSame($field, $event['contentType']);
        self::assertSame($message, $event['proto']);
        self::assertNotSame('unsupported', $event['kind']);
    }

    public static function wrapperVectors(): iterable
    {
        // Every body is FutureProofMessage.message#1 -> Message.conversation#1.
        yield 'bot registration' => ['botPlatformRegistrationSuccessMessage', '9a08060a040a026f6b'];
        yield 'scheduled channel message' => ['newsletterScheduledMessage', 'a208060a040a026f6b'];
        yield 'acp2 setting wrapper' => ['acp2SettingMessage', 'aa08060a040a026f6b'];
        yield 'audio sticker wrapper' => ['audioStickerMessage', 'b208060a040a026f6b'];
        yield 'bot group participant wrapper' => ['botGroupParticipantMessage', 'ca08060a040a026f6b'];
        yield 'retained legacy channel profile' => ['newsletterAdminProfileMessageV2', 'aa07060a040a026f6b'];
    }

    #[DataProvider('wrapperVectors')]
    public function testNewAndRetainedWrappersPreserveTheInnerContent(string $field, string $hex): void
    {
        $message = [$field => ['message' => ['conversation' => 'ok']]];
        $wire = hex2bin($hex);
        self::assertSame($wire, SchemaCodec::encode('Message', $message));
        $event = ContentDecoder::decode($wire);
        self::assertSame($message, $event['proto']);
        self::assertSame([$field], $event['wrappers']);
        self::assertSame('ok', $event['text']);
        self::assertSame('text', $event['kind']);
    }

    public function testNewMusicSurvivesTheExistingOwnDeviceEnvelope(): void
    {
        $inner = hex2bin('8a080d1204736f6e671a036172742001');
        // Message.deviceSentMessage#31; destinationJid#1 and message#2.
        $wire = hex2bin('fa01190a0531406c696412108a080d1204736f6e671a036172742001');
        self::assertSame($wire, MessageCodec::deviceSent($inner, '1@lid'));
        $event = ContentDecoder::decode($wire);
        self::assertSame('1@lid', $event['destination']);
        self::assertSame(['deviceSentMessage'], $event['wrappers']);
        self::assertSame('musicMessage', $event['contentType']);
        self::assertSame('song', $event['data']['songUri']);
    }

    public function testWallpaperUsesTheNewFixed32FieldsWithoutAnOldWireOverride(): void
    {
        // 0xFF010203 and 0xFF040506 are fixed32 fields #1/#2, not strings.
        $data = ['backgroundLightArgb' => 0xff010203, 'backgroundDarkArgb' => 0xff040506, 'isDoodleEnabled' => true];
        $wire = hex2bin('0d030201ff15060504ff1801');
        self::assertSame($wire, SchemaCodec::encode('Message.ChatSolidColorWallpaper', $data));
        self::assertSame($data, SchemaCodec::decode('Message.ChatSolidColorWallpaper', $wire));
        self::assertArrayNotHasKey('colorLight', SchemaCodec::fields('Message.ChatSolidColorWallpaper'));
        self::assertArrayNotHasKey('colorDark', SchemaCodec::fields('Message.ChatSolidColorWallpaper'));
    }

    public function testLegacyRawConsumerAndLabyrinthMessagesRemainReadable(): void
    {
        $consumer = ['payload' => ['content' => ['messageText' => ['text' => 'old']]]];
        $consumerWire = hex2bin('0a090a070a050a036f6c64');
        self::assertSame($consumerWire, SchemaCodec::encode('ConsumerApplication', $consumer));
        self::assertSame($consumer, SchemaCodec::decode('ConsumerApplication', $consumerWire));
        $backup = ['createBackupInput' => ['recoveryCode' => 'r', 'userId' => 150]];
        $backupWire = hex2bin('0a060a0172109601');
        self::assertSame($backupWire, SchemaCodec::encode('LabyrinthWaCommand', $backup));
        self::assertSame($backup, SchemaCodec::decode('LabyrinthWaCommand', $backupWire));
    }

    public function testHistoryKeepsOldChunkFieldsAlongsideOmittedConversations(): void
    {
        // HistorySync #1=RECENT(3), #5=chunk2, #6=75%; new #23 and #24.
        $data = ['syncType' => 3, 'chunkOrder' => 2, 'progress' => 75,
            'omittedConversations' => [['historyChatId' => 'c', 'accountLid' => '1@lid']],
            'omittedConversationsProvided' => true];
        $wire = hex2bin('08032802304bba010a0a0163120531406c6964c00101');
        self::assertSame($wire, SchemaCodec::encode('HistorySync', $data));
        self::assertSame($data, SchemaCodec::decode('HistorySync', $wire));
    }

    public function testLoginAndAdvKeepExistingFieldsWhileMessageStsIsAvailable(): void
    {
        // ClientPayload #1=username, #3=passive, #18=device; new #49=int64.
        $wire = hex2bin('0807180090010288039601');
        $data = ['username' => 7, 'passive' => false, 'device' => 2, 'messageSts' => 150];
        self::assertSame($wire, SchemaCodec::encode('ClientPayload', $data));
        self::assertSame($data, SchemaCodec::decode('ClientPayload', $wire));
        $login = SchemaCodec::decode('ClientPayload', ClientPayload::login(['meJid' => '7:2@s.whatsapp.net'], '2.3000.1050579926'));
        self::assertSame(7, $login['username']);
        self::assertSame(2, $login['device']);
        self::assertSame(1050579926, $login['userAgent']['appVersion']['tertiary']);
        $adv = ['rawId' => 1, 'timestamp' => 150, 'keyIndex' => 2, 'accountType' => 1, 'deviceType' => 1];
        $advWire = hex2bin('0801109601180220012801');
        self::assertSame($advWire, SchemaCodec::encode('ADVDeviceIdentity', $adv));
        self::assertSame($adv, SchemaCodec::decode('ADVDeviceIdentity', $advWire));
    }

    public function testRenamedSharingEnumKeepsItsPriorWireNumbers(): void
    {
        $wire = hex2bin('080110031896012001');
        $data = ['sharingLimited' => true, 'trigger' => 'UNKNOWN_GROUP', 'limitSharingSettingTimestamp' => 150, 'initiatedByMe' => true];
        self::assertSame($wire, SchemaCodec::encode('LimitSharing', $data));
        self::assertSame(3, SchemaCodec::decode('LimitSharing', $wire)['trigger']);
        // The public field resolves to the current name; the old enum remains
        // available to integrations inspecting the descriptor directly.
        self::assertSame('LimitSharing.TriggerType', SchemaCodec::fields('LimitSharing')['trigger']['type']);
        $schema = json_decode(file_get_contents(dirname(__DIR__, 2).'/resources/WAProto.schema.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($schema['enums']['LimitSharing.TriggerType'], $schema['enums']['LimitSharing.Trigger']);
    }
}
