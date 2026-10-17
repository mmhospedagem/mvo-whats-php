<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use MVO\Message\{ContentBuilder,ContentDecoder};

final class ContentBuilderTest extends TestCase
{
    private const TO = '5511999999999@s.whatsapp.net';
    private const ME = '5511888888888@s.whatsapp.net';

    public function testReactionMatchesIndependentWaProtoFieldWireFixture(): void
    {
        // Message.reactionMessage=46, key=1, text=2, timestamp=4.
        // MessageKey.remoteJid=1, fromMe=2, id=3.
        $built = ContentBuilder::build(['type'=>'reaction','target'=>['id'=>'I','fromMe'=>true],'emoji'=>'👍','senderTimestampMs'=>1],'a');
        self::assertSame('f202120a080a016110011a01491204f09f918d2001',bin2hex($built['bytes']));
        $decoded = ContentDecoder::decode(hex2bin('f202120a080a016110011a01491204f09f918d2001'));
        self::assertSame('👍',$decoded['data']['text']);
        self::assertSame(['remoteJid'=>'a','fromMe'=>true,'id'=>'I'],$decoded['target']);
    }

    public function testReplyMentionForwardAndProvidedPreviewSurviveWireEncoding(): void
    {
        $built = ContentBuilder::build(['type'=>'text','text'=>'Olá @Maria https://example.com',
            'linkPreview'=>['matchedText'=>'https://example.com','title'=>'Example','description'=>'Description','jpegThumbnail'=>"\xff\xd8"],
            'quote'=>['key'=>['id'=>'PARENT','fromMe'=>false,'remoteJid'=>self::TO],'message'=>['conversation'=>'Original']],
            'mentions'=>['123@lid'],'forward'=>true], self::TO,['meJid'=>self::ME]);
        $decoded = ContentDecoder::decode($built['bytes']);
        self::assertSame('Olá @Maria https://example.com',$decoded['text']);
        self::assertSame('Original',$decoded['context']['quotedMessage']['conversation']);
        self::assertSame('PARENT',$decoded['context']['stanzaId']);
        self::assertSame(self::TO,$decoded['context']['participant']);
        self::assertArrayNotHasKey('remoteJid',$decoded['context']);
        self::assertSame(['123@lid'],$decoded['context']['mentionedJid']);
        self::assertTrue($decoded['context']['isForwarded']);
        self::assertSame(1,$decoded['context']['forwardingScore']);
        self::assertSame('media',$built['attrs']['type']);
        self::assertSame('url',$built['encAttrs']['mediatype']);
    }

    public static function typedKinds(): iterable
    {
        $key = ['id'=>'TARGET','fromMe'=>true];
        yield 'reaction'=>[['type'=>'reaction','emoji'=>'👍','target'=>$key],'reaction'];
        yield 'reaction withdrawal'=>[['type'=>'reaction','emoji'=>'','target'=>$key],'reaction'];
        yield 'revoke'=>[['type'=>'revoke','target'=>$key],'protocol'];
        yield 'edit'=>[['type'=>'edit','target'=>$key,'text'=>'new text'],'protocol'];
        yield 'pin'=>[['type'=>'pin','target'=>$key],'pin'];
        yield 'unpin'=>[['type'=>'unpin','target'=>$key],'unpin'];
        yield 'keep'=>[['type'=>'keep','target'=>$key],'keep'];
        yield 'unkeep'=>[['type'=>'unkeep','target'=>$key],'unkeep'];
        yield 'contact'=>[['type'=>'contact','displayName'=>'Ana','vcard'=>"BEGIN:VCARD\nVERSION:3.0\nFN:Ana\nEND:VCARD"],'contact'];
        yield 'contacts'=>[['type'=>'contacts','contacts'=>[['displayName'=>'Ana','vcard'=>'VCARD']]],'contacts'];
        yield 'location'=>[['type'=>'location','latitude'=>-22.5,'longitude'=>-47.0,'name'=>'Aqui'],'location'];
        yield 'live-location'=>[['type'=>'live-location','latitude'=>-22.5,'longitude'=>-47.0,'sequenceNumber'=>3],'live-location'];
        yield 'poll'=>[['type'=>'poll','name'=>'A ou B','options'=>['A','B']],'poll'];
        yield 'event'=>[['type'=>'event','name'=>'Festa','startTime'=>2000000000,'location'=>['latitude'=>-22.5,'longitude'=>-47.0]],'event'];
        yield 'list'=>[['type'=>'list','title'=>'Escolha','buttonText'=>'Abrir','listType'=>1,'sections'=>[['title'=>'A','rows'=>[['rowId'=>'1','title'=>'Item']]]]],'list'];
        yield 'buttons'=>[['type'=>'buttons','contentText'=>'Escolha','buttons'=>[['buttonId'=>'1','buttonText'=>['displayText'=>'Sim'],'type'=>1]]],'interactive'];
        yield 'legacy buttons'=>[['type'=>'legacy-buttons','contentText'=>'Escolha','buttons'=>[['buttonId'=>'1','buttonText'=>['displayText'=>'Sim'],'type'=>1]]],'buttons'];
        yield 'interactive'=>[['type'=>'interactive','body'=>['text'=>'Escolha'],'nativeFlowMessage'=>['buttons'=>[['name'=>'quick_reply','buttonParamsJson'=>'{"display_text":"Sim","id":"1"}']]]],'interactive'];
        yield 'product'=>[['type'=>'product','businessOwnerJid'=>self::TO,'product'=>['productId'=>'123','title'=>'Café','currencyCode'=>'BRL','priceAmount1000'=>12000]],'product'];
    }

    #[DataProvider('typedKinds')]
    public function testTypedConveniencesProduceStructuredAuthenticatedContent(array $content, string $expectedKind): void
    {
        $built = ContentBuilder::build($content,self::TO,['meJid'=>self::ME,'nowMs'=>1000,'outgoingId'=>'OUT']);
        $decoded = ContentDecoder::decode($built['bytes']);
        self::assertSame($expectedKind,$decoded['kind']);
        self::assertIsArray($decoded['data']);
        self::assertNotEmpty($decoded['proto']);
    }

    public function testPollVersionsDoNotSerializeFalseFlags(): void
    {
        foreach ([[1,false,false,'pollCreationMessageV3'],[2,false,false,'pollCreationMessage'],[1,true,false,'pollCreationMessageV6'],[1,false,true,'pollCreationMessageV6']] as [$count,$add,$hide,$field]) {
            $built = ContentBuilder::build(['type'=>'poll','name'=>'Escolha','options'=>['A','B'],'selectableCount'=>$count,'allowAddOption'=>$add,'hideParticipantName'=>$hide],self::TO);
            self::assertArrayHasKey($field,$built['proto']);
            if (!$add) self::assertArrayNotHasKey('allowAddOption',$built['proto'][$field]);
            if (!$hide) self::assertArrayNotHasKey('hideParticipantName',$built['proto'][$field]);
            self::assertSame($built['secrets']['messageSecret'],$built['proto']['messageContextInfo']['messageSecret']);
            self::assertSame(32,strlen($built['secrets']['messageSecret']));
            self::assertSame(['polltype'=>'creation'],$built['metaAttrs']);
        }
    }

    public function testViewOncePreparedMediaEphemeralAndRawWrappers(): void
    {
        $content = ['type'=>'raw','message'=>['imageMessage'=>['url'=>'https://example.com','caption'=>'A foto','mediaKey'=>str_repeat('k',32),'directPath'=>'/m','fileSha256'=>str_repeat('h',32)]],'viewOnce'=>true,'ephemeralExpiration'=>86400];
        $built = ContentBuilder::build($content,self::TO);
        $decoded = ContentDecoder::decode($built['bytes']);
        self::assertSame('image',$decoded['kind']);
        self::assertNull($decoded['text']);
        self::assertSame('A foto',$decoded['data']['caption']);
        self::assertTrue($decoded['data']['viewOnce']);
        self::assertSame(86400,$decoded['context']['expiration']);
        self::assertSame('true',$built['metaAttrs']['view_once']);
        self::assertSame(str_repeat('k',32),$decoded['media']['mediaKey']);
        self::assertSame('image',$decoded['media']['mediaType']);
        $wrapped = ContentBuilder::build(['viewOnceMessageV2'=>['message'=>['videoMessage'=>['caption'=>'Video']]]],self::TO,['viewOnce'=>true]);
        self::assertSame('video',ContentDecoder::decode($wrapped['bytes'])['kind']);
    }

    public function testDecodedMediaCanBeDownloadedWithAuthenticatedHashes(): void
    {
        $plain = "photo\0bytes";
        $encrypted = \MVO\Media\MediaCrypto::encrypt($plain,'image',str_repeat('k',32));
        $fields = $encrypted; unset($fields['body']);
        $fields['url'] = 'https://mmg.whatsapp.net/photo';
        $built = ContentBuilder::build(['imageMessage'=>$fields],self::TO);
        $decoded = ContentDecoder::decode($built['bytes']);
        $transfer = new \MVO\Media\MediaTransfer(static fn($query)=>$query, static fn($request)=>['status'=>200,'body'=>$encrypted['body']]);
        self::assertSame($plain,$transfer->download($decoded['media']));
    }

    public function testPreparedGifAndVoiceNoteAliasesUseActualMediaSchemaFields(): void
    {
        foreach ([['gif','videoMessage','gifPlayback'],['ptt','audioMessage','ptt']] as [$kind,$field,$flag]) {
            $prepared = ['url'=>'https://mmg.whatsapp.net/m','mediaKey'=>str_repeat('k',32),'fileSha256'=>str_repeat('h',32),'fileEncSha256'=>str_repeat('e',32),'fileLength'=>100];
            $built = ContentBuilder::build(['type'=>$kind,'prepared'=>$prepared,'viewOnce'=>true],self::TO);
            self::assertArrayHasKey($field,$built['proto']);
            self::assertTrue($built['proto'][$field][$flag]);
            $decoded = ContentDecoder::decode($built['bytes']);
            self::assertSame($kind,$decoded['kind']);
            self::assertSame($kind,$decoded['media']['mediaType']);
            self::assertTrue($decoded['data']['viewOnce']);
            self::assertSame($kind,$built['encAttrs']['mediatype']);
        }
    }

    public function testViewOnceIsAppliedInsideNestedEnvelopesAndPreservesThem(): void
    {
        $message = ['ephemeralMessage'=>['message'=>['deviceSentMessage'=>['destinationJid'=>self::TO,'message'=>['videoMessage'=>['caption'=>'private']]]]]];
        $built = ContentBuilder::build(['type'=>'raw','message'=>$message,'viewOnce'=>true],self::TO);
        $decoded = ContentDecoder::decode($built['bytes']);
        self::assertTrue($decoded['data']['viewOnce']);
        self::assertSame(self::TO,$decoded['destination']);
        self::assertSame(['ephemeralMessage','deviceSentMessage'],$decoded['wrappers']);
        self::assertSame('true',$built['metaAttrs']['view_once']);
        $existing = ['ephemeralMessage'=>['message'=>['viewOnceMessageV2'=>['message'=>['imageMessage'=>['caption'=>'private']]]]]];
        $built = ContentBuilder::build($existing,self::TO);
        self::assertSame('true',$built['metaAttrs']['view_once']);
        self::assertSame('image',ContentDecoder::decode($built['bytes'])['kind']);
    }

    public function testTargetAndProtocolAttributesMatchWhatsApp(): void
    {
        $target = ['key'=>['remoteJid'=>'wrong','id'=>'TARGET','fromMe'=>false,'participant'=>'123@lid'],'rawNode'=>[]];
        $revoke = ContentBuilder::build(['type'=>'revoke','target'=>$target],self::TO);
        self::assertSame('8',$revoke['attrs']['edit']);
        self::assertSame(self::TO,$revoke['proto']['protocolMessage']['key']['remoteJid']);
        self::assertArrayNotHasKey('participant',$revoke['proto']['protocolMessage']['key']);
        $pin = ContentBuilder::build(['type'=>'pin','target'=>$target],'123@g.us');
        self::assertSame(86400,$pin['proto']['messageContextInfo']['messageAddOnDurationInSecs']);
        self::assertSame('123@lid',$pin['proto']['pinInChatMessage']['key']['participant']);
        self::assertSame('2',$pin['attrs']['edit']);
        self::assertSame('hide',$pin['encAttrs']['decrypt-fail']);
        $edit = ContentBuilder::build(['type'=>'edit','target'=>$target,'text'=>'Novo texto'],self::TO);
        self::assertTrue($edit['proto']['protocolMessage']['key']['fromMe']);
        self::assertSame('1',$edit['attrs']['edit']);
        self::assertSame('edit',ContentDecoder::decode($edit['bytes'])['action']);
    }

    public function testListCompanionStanzaKeepsMediaTypeInDirectChatsAndGroups(): void
    {
        $content = ['type'=>'list','description'=>'Options','buttonText'=>'Abrir','listType'=>1];
        $direct = ContentBuilder::build($content,self::TO);
        self::assertSame('media',$direct['attrs']['type']);
        self::assertSame('list',$direct['encAttrs']['mediatype']);
        self::assertSame('list',$direct['buttonAddonKind']);
        $group = ContentBuilder::build($content,'123@g.us');
        self::assertSame('media',$group['attrs']['type']);
        self::assertSame('list',$group['encAttrs']['mediatype']);
    }

    public function testButtonsUseNativeFlowAndTextFallbackIsExplicit():void
    {
        $content=['type'=>'buttons','contentText'=>'Escolha','buttons'=>[['buttonId'=>'confirmar','buttonText'=>['displayText'=>'Confirmar']]]];
        $built=ContentBuilder::build($content,self::TO);
        self::assertArrayNotHasKey('buttonsMessage',$built['proto']);
        self::assertSame('quick_reply',$built['proto']['interactiveMessage']['nativeFlowMessage']['buttons'][0]['name']);
        self::assertSame(['display_text'=>'Confirmar','id'=>'confirmar'],json_decode($built['proto']['interactiveMessage']['nativeFlowMessage']['buttons'][0]['buttonParamsJson'],true));
        self::assertSame('text',$built['attrs']['type']);
        $fallback=ContentBuilder::build([...$content,'buttonMode'=>'text'],self::TO);
        self::assertSame('text',ContentDecoder::decode($fallback['bytes'])['kind']);
        self::assertStringContainsString('1. Confirmar',ContentDecoder::decode($fallback['bytes'])['text']);
        self::assertNull($fallback['buttonAddonKind']);
    }

    public function testInteractiveDefaultsPreserveCompanionMetadataAndExistingSecret():void
    {
        $body=['body'=>['text'=>'Escolha'],'nativeFlowMessage'=>['buttons'=>[['name'=>'quick_reply','buttonParamsJson'=>'{"display_text":"Sim","id":"1"}']]]];
        $secret=str_repeat('s',32);
        $message=['ephemeralMessage'=>['message'=>['interactiveMessage'=>$body,'messageContextInfo'=>['messageSecret'=>$secret]]]];
        $built=ContentBuilder::build($message,self::TO,['nowMs'=>1710000000123]);
        self::assertSame($secret,$built['secrets']['messageSecret']);
        self::assertSame($secret,$built['proto']['messageContextInfo']['messageSecret']);
        self::assertSame('false',$built['attrs']['device_fanout']);
        self::assertSame(['actual_actors'=>'2','host_storage'=>'2','privacy_mode_ts'=>'1710000000'],$built['companionNodes'][0]->attrs);
        self::assertSame($body,ContentDecoder::decode($built['bytes'])['data']);
        self::assertSame('media',ContentBuilder::build(['type'=>'list','listType'=>1,'buttonText'=>'Abrir'],self::TO)['attrs']['type']);
    }

    public function testProvidedTextSecretIsReturnedForPersistenceWithoutGeneratingOneForPlainText(): void
    {
        $secret = str_repeat('s',32);
        $built = ContentBuilder::build(['conversation'=>'text','messageContextInfo'=>['messageSecret'=>$secret]],self::TO);
        self::assertSame(['messageSecret'=>$secret],$built['secrets']);
        self::assertSame($secret,ContentDecoder::decode($built['bytes'])['messageContextInfo']['messageSecret']);
        self::assertSame([],ContentBuilder::build('plain text',self::TO)['secrets']);
        $this->expectException(\InvalidArgumentException::class);
        ContentBuilder::build(['conversation'=>'text','messageContextInfo'=>['messageSecret'=>'short']],self::TO);
    }

    public static function invalidContents(): iterable
    {
        yield 'missing text'=>[['type'=>'text']];
        yield 'invalid target'=>[['type'=>'reaction','emoji'=>'👍','target'=>[]]];
        yield 'empty poll'=>[['type'=>'poll','name'=>'x','options'=>[]]];
        yield 'invalid poll count'=>[['type'=>'poll','name'=>'x','options'=>['A'],'selectableCount'=>2]];
        yield 'invalid coordinates'=>[['type'=>'location','latitude'=>91,'longitude'=>0]];
        yield 'event missing time'=>[['type'=>'event','name'=>'x']];
        yield 'event invalid end'=>[['type'=>'event','name'=>'x','startTime'=>200,'endTime'=>100]];
        yield 'unknown raw'=>[['type'=>'raw','message'=>['nonexistentMessage'=>[]]]];
        yield 'text view once'=>[['type'=>'text','text'=>'no','viewOnce'=>true]];
        yield 'poll vote missing parent'=>[['type'=>'poll-vote']];
        yield 'empty contacts'=>[['type'=>'contacts','contacts'=>[]]];
    }

    #[DataProvider('invalidContents')]
    public function testInvalidContentFailsBeforeEncryption(array $content): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContentBuilder::build($content,self::TO,['meJid'=>self::ME,'outgoingId'=>'OUT']);
    }
}
