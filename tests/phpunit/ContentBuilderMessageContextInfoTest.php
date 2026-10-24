<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\{ContentBuilder,ContentDecoder,MessageCodec};
use MVO\Protocol\{BinaryCodec,BinaryNode,SchemaCodec};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ContentBuilderMessageContextInfoTest extends TestCase
{
    private const TO='123456789012345@lid';
    private const ME='5511888888888@s.whatsapp.net';

    /** @return array<string,mixed> */
    private static function association():array
    {
        return ['associationType'=>1,'parentMessageKey'=>['remoteJid'=>self::TO,'fromMe'=>true,'id'=>'ALBUM-PARENT'],'messageIndex'=>2];
    }

    public static function typedMessages():iterable
    {
        $key=['id'=>'TARGET','fromMe'=>true];
        yield 'text'=>[['type'=>'text','text'=>'Fixture'],'extendedTextMessage'];
        yield 'link preview'=>[['type'=>'text','text'=>'https://example.com','linkPreview'=>['matchedText'=>'https://example.com','title'=>'Fixture']],'extendedTextMessage'];
        yield 'reaction'=>[['type'=>'reaction','emoji'=>'x','target'=>$key],'reactionMessage'];
        yield 'revoke'=>[['type'=>'revoke','target'=>$key],'protocolMessage'];
        yield 'pin'=>[['type'=>'pin','target'=>$key],'pinInChatMessage'];
        yield 'keep'=>[['type'=>'keep','target'=>$key],'keepInChatMessage'];
        yield 'poll'=>[['type'=>'poll','name'=>'Fixture','options'=>['A','B']],'pollCreationMessageV3'];
        yield 'poll vote'=>[['type'=>'poll-vote','poll'=>['id'=>'POLL','authorJid'=>self::ME,'messageSecret'=>str_repeat('p',32)],'selectedOptionNames'=>['A']],'pollUpdateMessage'];
        yield 'event'=>[['type'=>'event','name'=>'Fixture','startTime'=>1700000000],'eventMessage'];
        yield 'event response'=>[['type'=>'event-response','event'=>['id'=>'EVENT','authorJid'=>self::ME,'messageSecret'=>str_repeat('e',32)],'response'=>'going'],'encEventResponseMessage'];
        foreach(['image','video','audio','document','sticker']as$type)yield 'prepared '.$type=>[['type'=>$type,'prepared'=>['url'=>'https://example.com/fixture','directPath'=>'/fixture','mediaKey'=>str_repeat('k',32),'fileSha256'=>str_repeat('h',32),'fileEncSha256'=>str_repeat('e',32),'fileLength'=>1]],$type.'Message'];
        yield 'contact'=>[['type'=>'contact','displayName'=>'Fixture','vcard'=>'VCARD'],'contactMessage'];
        yield 'location'=>[['type'=>'location','latitude'=>1,'longitude'=>2],'locationMessage'];
        yield 'buttons'=>[['type'=>'buttons','contentText'=>'Fixture','buttons'=>[['buttonId'=>'A','buttonText'=>['displayText'=>'A']]]],'interactiveMessage'];
        yield 'legacy buttons'=>[['type'=>'legacy-buttons','contentText'=>'Fixture','buttons'=>[['buttonId'=>'A','buttonText'=>['displayText'=>'A']]]],'buttonsMessage'];
        yield 'album'=>[['type'=>'album','expectedImageCount'=>2],'albumMessage'];
        yield 'Business form'=>[['type'=>'business-form','publication'=>['flowId'=>'9007199254740993','wellVersion'=>'V700'],'form'=>['form_title'=>'Fixture','form_description'=>'','form_type'=>'CUSTOMER_INFO','selected_field_list'=>['NAME']]],'interactiveMessage'];
    }

    #[DataProvider('typedMessages')]
    public function testTypedMetadataIsAValidMessageSiblingAndNeverLeaksIntoContent(array $content,string $field):void
    {
        $mci=['messageAssociation'=>self::association(),'paddingBytes'=>"p\0"];
        $built=ContentBuilder::build([...$content,'messageContextInfo'=>$mci],self::TO,['meJid'=>self::ME,'outgoingId'=>'OUT','nowMs'=>1700000000000]);
        $wire=SchemaCodec::decode('Message',$built['bytes']);
        self::assertArrayHasKey($field,$wire);
        self::assertSame(self::association(),$wire['messageContextInfo']['messageAssociation']);
        self::assertSame("p\0",$wire['messageContextInfo']['paddingBytes']);
        self::assertArrayNotHasKey('messageContextInfo',$wire[$field]);
        if(isset($built['secrets']['messageSecret']))self::assertSame($wire['messageContextInfo']['messageSecret'],$built['secrets']['messageSecret']);
    }

    public function testGeneratedPinDurationAndAddonSecretAndAssociationWinFieldByField():void
    {
        $caller=['messageAddOnDurationInSecs'=>5,'messageAssociation'=>self::association()];
        $pin=ContentBuilder::build(['type'=>'pin','target'=>['id'=>'PIN','fromMe'=>true],'messageContextInfo'=>$caller],self::TO);
        $wire=SchemaCodec::decode('Message',$pin['bytes']);
        self::assertSame(86400,$wire['messageContextInfo']['messageAddOnDurationInSecs']);
        self::assertSame(self::association(),$wire['messageContextInfo']['messageAssociation']);
        $parent=['id'=>'POLL','authorJid'=>self::ME,'fromMe'=>true,'messageSecret'=>str_repeat('p',32),'proto'=>['pollCreationMessageV3'=>['name'=>'Fixture','options'=>[['optionName'=>'A'],['optionName'=>'B']],'allowAddOption'=>true]]];
        $built=ContentBuilder::build(['type'=>'poll-add-option','poll'=>$parent,'optionName'=>'C','messageContextInfo'=>['messageSecret'=>str_repeat('c',32),'messageAssociation'=>self::association(),'paddingBytes'=>'fixture']],self::TO,['meJid'=>self::ME,'outgoingId'=>'OUT','messageSecret'=>str_repeat('g',32)]);
        $wire=SchemaCodec::decode('Message',$built['bytes']);
        self::assertSame(str_repeat('g',32),$wire['messageContextInfo']['messageSecret']);
        self::assertSame(str_repeat('g',32),$built['secrets']['messageSecret']);
        self::assertSame(20,$wire['messageContextInfo']['messageAssociation']['associationType']);
        self::assertSame('POLL',$wire['messageContextInfo']['messageAssociation']['parentMessageKey']['id']);
        self::assertSame('fixture',$wire['messageContextInfo']['paddingBytes']);
    }

    public function testQuotesMentionsExpirationAndDeviceCopiesKeepTheirDistinctContexts():void
    {
        $built=ContentBuilder::build(['type'=>'image','prepared'=>['caption'=>'Fixture'],'messageContextInfo'=>['messageAssociation'=>self::association()],
            'quote'=>['key'=>['id'=>'QUOTED','remoteJid'=>self::TO,'fromMe'=>false],'message'=>['conversation'=>'Quoted']],
            'mentions'=>['987654321012345@lid'],'ephemeralExpiration'=>60,'viewOnce'=>true],self::TO,['meJid'=>self::ME]);
        $copy=SchemaCodec::decode('Message',MessageCodec::deviceSent($built['bytes'],self::TO));
        $decoded=ContentDecoder::unwrap($copy);
        self::assertSame(self::TO,$decoded['destination']);
        self::assertSame(['deviceSentMessage','ephemeralMessage'],$decoded['wrappers']);
        self::assertSame(self::association(),$decoded['context']['messageAssociation']);
        $body=$decoded['message']['imageMessage'];
        self::assertTrue($body['viewOnce']);
        self::assertSame('QUOTED',$body['contextInfo']['stanzaId']);
        self::assertSame(['987654321012345@lid'],$body['contextInfo']['mentionedJid']);
        self::assertSame(60,$body['contextInfo']['expiration']);
        self::assertArrayNotHasKey('messageAssociation',$body['contextInfo']);
        self::assertArrayNotHasKey('contextInfo',$decoded['context']);
    }

    public function testRawMessageAndOuterRawOptionKeepTheirExistingBehavior():void
    {
        $raw=['messageContextInfo'=>['messageAssociation'=>self::association()],'extendedTextMessage'=>['text'=>'Raw fixture']];
        self::assertSame($raw,ContentBuilder::build($raw,self::TO)['proto']);
        $built=ContentBuilder::build(['type'=>'raw','message'=>$raw,'messageContextInfo'=>['messageAddOnDurationInSecs'=>5]],self::TO);
        self::assertSame($raw,$built['proto']);
        $context=ContentBuilder::build($raw,self::TO,['contextInfo'=>['stanzaId'=>'Q'],'mentions'=>['987654321012345@lid']]);
        $wire=SchemaCodec::decode('Message',$context['bytes']);
        self::assertSame(self::association(),$wire['messageContextInfo']['messageAssociation']);
        self::assertSame('Q',$wire['extendedTextMessage']['contextInfo']['stanzaId']);
        self::assertSame(['987654321012345@lid'],$wire['extendedTextMessage']['contextInfo']['mentionedJid']);
    }

    public function testSpoilerConvenienceCanCarryMessageContextInfoOutsideItsBody():void
    {
        $built=ContentBuilder::build(['type'=>'spoiler','content'=>['type'=>'text','text'=>'Fixture'],'messageContextInfo'=>['messageAssociation'=>self::association()]],self::TO);
        $wire=SchemaCodec::decode('Message',$built['bytes']);
        self::assertSame(['spoilerMessage'],ContentDecoder::unwrap($wire)['wrappers']);
        self::assertSame(self::association(),ContentDecoder::unwrap($wire)['context']['messageAssociation']);
        self::assertArrayNotHasKey('messageContextInfo',$wire['spoilerMessage']['message']['extendedTextMessage']);
    }

    public static function preparedSpoilerMessages():iterable
    {
        foreach(['imageMessage','videoMessage']as$field)yield $field=>[$field];
    }

    #[DataProvider('preparedSpoilerMessages')]
    public function testSpoilerOuterMetadataAlsoAppliesToPreparedRawContent(string $field):void
    {
        $raw=['messageContextInfo'=>['paddingBytes'=>'built'],$field=>['caption'=>'Fixture']];
        $built=ContentBuilder::build(['type'=>'spoiler','content'=>['type'=>'raw','message'=>$raw],
            'messageContextInfo'=>['messageAssociation'=>self::association(),'paddingBytes'=>'caller'],
            'quote'=>['key'=>['id'=>'QUOTED','remoteJid'=>self::TO,'fromMe'=>false],'message'=>['conversation'=>'Quoted']]],self::TO);
        $wire=SchemaCodec::decode('Message',$built['bytes']);$decoded=ContentDecoder::unwrap($wire);
        self::assertSame(['spoilerMessage'],$decoded['wrappers']);
        self::assertSame(self::association(),$decoded['context']['messageAssociation']);
        self::assertSame('built',$decoded['context']['paddingBytes']);
        self::assertTrue($decoded['message'][$field]['contextInfo']['isSpoiler']);
        self::assertSame('QUOTED',$decoded['message'][$field]['contextInfo']['stanzaId']);
        self::assertArrayNotHasKey('messageContextInfo',$decoded['message'][$field]);
    }

    public static function invalidMetadata():iterable
    {
        yield 'scalar'=>['invalid'];
        yield 'unknown field'=>[['notAProtoField'=>true]];
        yield 'invalid scalar type'=>[['deviceListMetadataVersion'=>[]]];
        yield 'invalid nested type'=>[['messageAssociation'=>['parentMessageKey'=>'invalid']]];
    }

    #[DataProvider('invalidMetadata')]
    public function testCallerMetadataStillUsesTheProtobufSchema(mixed $metadata):void
    {
        $this->expectException(\InvalidArgumentException::class);
        ContentBuilder::build(['type'=>'text','text'=>'Fixture','messageContextInfo'=>$metadata],self::TO);
    }

    public function testAlbumParentUsesCollectionEncMetadataWithTheIndependentAlbumWireFixture():void
    {
        $built=ContentBuilder::build(['type'=>'album','expectedImageCount'=>2,'expectedVideoCount'=>1],self::TO);
        // Message.albumMessage=83; AlbumMessage image/video counts=2/3.
        self::assertSame('9a050410021801',bin2hex($built['bytes']));
        self::assertSame('text',$built['attrs']['type']);
        self::assertSame(['mediatype'=>'collection'],$built['encAttrs']);
        $node=new BinaryNode('message',$built['attrs'],[new BinaryNode('enc',['v'=>'2','type'=>'msg',...$built['encAttrs']],'opaque-ciphertext')]);
        $codec=new BinaryCodec();$wire=$codec->decode($codec->encode($node));
        self::assertSame('text',$wire->attrs['type']);
        self::assertSame('collection',$wire->child('enc')?->attrs['mediatype']);
        $wrapped=ContentBuilder::build(['ephemeralMessage'=>['message'=>['albumMessage'=>['expectedImageCount'=>2,'expectedVideoCount'=>1]]]],self::TO);
        self::assertSame(['mediatype'=>'collection'],$wrapped['encAttrs']);
    }
}
