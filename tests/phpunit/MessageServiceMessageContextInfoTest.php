<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Media\MediaTransfer;
use MVO\Message\{ContentDecoder,MessageCodec,MessageService};
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Signal\{SignalProtocol,SignalStore};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Local CDN and Signal peers only: these assertions do not establish client UI support. */
final class MessageServiceMessageContextInfoTest extends TestCase
{
    private const TO='5522000000000@s.whatsapp.net';
    private const ME='5511000000000:5@s.whatsapp.net';
    private SignalStore $store;
    private SignalStore $peerStore;
    private MessageService $service;
    private int $uploads=0;
    /** @var array<string,mixed> */
    private array $received=[];
    /** @var array<string,string> */
    private array $encAttrs=[];

    protected function setUp():void
    {
        $credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['meJid']=self::ME;$credentials['serverHasPreKeys']=true;
        $credentials['signedIdentity']=['details'=>'fixture','accountSignatureKey'=>random_bytes(32),'accountSignature'=>random_bytes(64),'deviceSignature'=>random_bytes(64)];
        $this->store=new SignalStore(':memory:');$signal=new SignalProtocol($this->store,$credentials);
        $peerCredentials=CredentialStore::fresh();$peerCredentials['registered']=true;$peerCredentials['meJid']=self::TO;
        $this->peerStore=new SignalStore(':memory:');$peer=new SignalProtocol($this->peerStore,$peerCredentials);$key=$this->peerStore->generatePreKeys(1)[0];
        $signal->ensureSession(self::TO,['registrationId'=>$peerCredentials['registrationId'],'identityKey'=>$peerCredentials['identityKeyPair']['public'],
            'signedPreKey'=>['id'=>$peerCredentials['signedPreKey']['id'],'public'=>$peerCredentials['signedPreKey']['public'],'signature'=>$peerCredentials['signedPreKey']['signature']],
            'preKey'=>['id'=>$key['id'],'public'=>$key['public']]]);
        $media=new MediaTransfer(static function(BinaryNode $node):BinaryNode{
            self::assertSame('w:m',$node->attrs['xmlns']??null);
            return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>'fixture','ttl'=>'3600'],[new BinaryNode('host',['hostname'=>'mmg.whatsapp.net'])])]);
        },function(array $request):array{
            self::assertSame('POST',$request['method']);++$this->uploads;
            return ['status'=>200,'body'=>json_encode(['url'=>'https://mmg.whatsapp.net/fixture-'.$this->uploads,'direct_path'=>'/fixture-'.$this->uploads],JSON_THROW_ON_ERROR)];
        });
        $query=function(BinaryNode $node)use($peer):BinaryNode{
            if($node->tag==='iq'&&($node->attrs['xmlns']??null)==='usync'){
                $users=[];foreach([self::TO=>[0],'5511000000000@s.whatsapp.net'=>[5]]as$jid=>$devices)$users[]=new BinaryNode('user',['jid'=>$jid],[new BinaryNode('devices',[],[new BinaryNode('device-list',[],array_map(static fn(int $id):BinaryNode=>new BinaryNode('device',['id'=>(string)$id]),$devices))])]);
                return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('usync',[],[new BinaryNode('list',[],$users)])]);
            }
            self::assertSame('message',$node->tag,'No unmocked network or account query');
            $destinations=$node->child('participants')?->content;self::assertIsArray($destinations);self::assertCount(1,$destinations);
            $enc=$destinations[0]->child('enc');self::assertNotNull($enc);$this->encAttrs=$enc->attrs;
            $plain=$peer->decrypt(self::ME,$enc->attrs['type'],$enc->content);$this->received=SchemaCodec::decode('Message',MessageCodec::unpad($plain));
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        };
        $this->service=new MessageService($signal,$this->store,static fn():array=>$credentials,static function():void{},$query,static function():void{},static function():void{},$media);
    }

    protected function tearDown():void
    {
        unset($this->service);$this->store->close();$this->peerStore->close();
    }

    private static function image():array
    {
        return ['type'=>'image','media'=>['bytes'=>base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aFioAAAAASUVORK5CYII=',true)],'mimetype'=>'image/png'];
    }

    private static function metadata(string $id='ALBUM-PARENT'):array
    {
        return ['messageAssociation'=>['associationType'=>1,'parentMessageKey'=>['remoteJid'=>self::TO,'fromMe'=>true,'id'=>$id],'messageIndex'=>0],'paddingBytes'=>'fixture'];
    }

    private static function assertMetadata(array $expected,array $actual):void
    {
        // Protobuf serializes map-like message fields in field-number order.
        ksort($expected);ksort($actual);self::assertSame($expected,$actual);
    }

    public function testUploadedAlbumChildRetainsAssociationOnTheDecryptedSignalMessage():void
    {
        $metadata=self::metadata();$result=$this->service->sendMessage(self::TO,[...self::image(),'caption'=>'Fixture','messageContextInfo'=>$metadata,
            'quote'=>['key'=>['id'=>'QUOTED','fromMe'=>false,'remoteJid'=>self::TO],'message'=>['conversation'=>'Quoted']],
            'mentions'=>['123456789012345@lid'],'ephemeralExpiration'=>60]);
        self::assertSame('server_ack',$result['status']);self::assertSame(1,$this->uploads);
        $decoded=ContentDecoder::unwrap($this->received);self::assertMetadata($metadata,$decoded['context']);
        $image=$decoded['message']['imageMessage'];self::assertSame('Fixture',$image['caption']);self::assertArrayNotHasKey('messageContextInfo',$image);
        self::assertSame('QUOTED',$image['contextInfo']['stanzaId']);self::assertSame(['123456789012345@lid'],$image['contextInfo']['mentionedJid']);self::assertSame(60,$image['contextInfo']['expiration']);
        self::assertSame('image',$this->encAttrs['mediatype']);
    }

    public function testCarouselStrictPreparationRetainsSharedMetadataAndGeneratedSecret():void
    {
        $card=[...self::image(),'buttons'=>[['type'=>'reply','label'=>'A','id'=>'A']]];
        $this->service->sendMessage(self::TO,['type'=>'carousel','cards'=>[$card,$card],'messageContextInfo'=>self::metadata()]);
        self::assertSame(2,$this->uploads);self::assertSame(self::metadata()['messageAssociation'],$this->received['messageContextInfo']['messageAssociation']);
        self::assertSame('fixture',$this->received['messageContextInfo']['paddingBytes']);self::assertSame(32,strlen($this->received['messageContextInfo']['messageSecret']));
        self::assertCount(2,$this->received['interactiveMessage']['carouselMessage']['cards']);
        foreach($this->received['interactiveMessage']['carouselMessage']['cards']as$entry)self::assertArrayNotHasKey('messageContextInfo',$entry['header']['imageMessage']);
    }

    public function testSpoilerUploadKeepsOuterMetadataAndOverridesNestedCallerMetadata():void
    {
        $this->service->sendMessage(self::TO,['type'=>'spoiler','content'=>[...self::image(),'messageContextInfo'=>self::metadata('INNER')],'messageContextInfo'=>self::metadata('OUTER')]);
        self::assertSame(1,$this->uploads);$decoded=ContentDecoder::unwrap($this->received);
        self::assertMetadata(self::metadata('OUTER'),$decoded['context']);self::assertTrue($decoded['message']['imageMessage']['contextInfo']['isSpoiler']);
    }

    public static function preparedSpoilerMessages():iterable
    {
        foreach(['imageMessage','videoMessage']as$field)yield $field=>[$field];
    }

    #[DataProvider('preparedSpoilerMessages')]
    public function testSpoilerPreparedRawContentKeepsOuterMetadataOnTheDecryptedSignalMessage(string $field):void
    {
        $this->service->sendMessage(self::TO,['type'=>'spoiler','content'=>['type'=>'raw','message'=>[
            $field=>['caption'=>'Fixture'],'messageContextInfo'=>['paddingBytes'=>'built']]],
            'messageContextInfo'=>self::metadata('OUTER'),
            'quote'=>['key'=>['id'=>'QUOTED','remoteJid'=>self::TO,'fromMe'=>false],'message'=>['conversation'=>'Quoted']]]);
        self::assertSame(0,$this->uploads);$decoded=ContentDecoder::unwrap($this->received);
        self::assertMetadata([...self::metadata('OUTER'),'paddingBytes'=>'built'],$decoded['context']);
        self::assertTrue($decoded['message'][$field]['contextInfo']['isSpoiler']);
        self::assertSame('QUOTED',$decoded['message'][$field]['contextInfo']['stanzaId']);
        self::assertArrayNotHasKey('messageContextInfo',$decoded['message'][$field]);
    }

    public function testRawConversionPreservesGeneratedMetadataOverCallerFields():void
    {
        $association=['associationType'=>6,'parentMessageKey'=>['remoteJid'=>'status@broadcast','fromMe'=>true,'id'=>'STATUS-PARENT']];
        $this->service->sendMessage(self::TO,[...self::image(),'statusMetadata'=>['association'=>$association],'messageContextInfo'=>self::metadata()]);
        self::assertSame(1,$this->uploads);self::assertSame($association,$this->received['messageContextInfo']['messageAssociation']);
        self::assertSame('fixture',$this->received['messageContextInfo']['paddingBytes']);
    }

    public function testRawOriginalNeverConsumesAnOuterTypedMetadataOption():void
    {
        $this->service->sendMessage(self::TO,['type'=>'raw','message'=>['extendedTextMessage'=>['text'=>'Raw fixture'],'messageContextInfo'=>self::metadata('RAW')],
            'messageContextInfo'=>self::metadata('IGNORED')]);
        self::assertSame(0,$this->uploads);self::assertSame('Raw fixture',$this->received['extendedTextMessage']['text']);self::assertMetadata(self::metadata('RAW'),$this->received['messageContextInfo']);
    }

    public function testInvalidSharedContainerFailsBeforeMediaUpload():void
    {
        $this->expectException(\InvalidArgumentException::class);
        try{$this->service->sendMessage(self::TO,[...self::image(),'messageContextInfo'=>'invalid']);}
        finally{self::assertSame(0,$this->uploads);}
    }
}
