<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Media\{MediaCrypto,MediaTransfer};
use MVO\Protocol\{BinaryData,BinaryNode,SchemaCodec};
use MVO\Service\GalaxyResponseService;
use MVO\Signal\SignalStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GalaxyResponseServiceTest extends TestCase
{
    private SignalStore $store;
    private int $now=1000;
    private array $sent=[];
    private array $descriptors=[];
    private array $credentials=['meJid'=>'100:5@s.whatsapp.net','meLid'=>'900:5@lid','role'=>'companion'];
    private string $compressed;
    protected function setUp():void{$this->store=new SignalStore(':memory:');$this->compressed=gzencode("name,answer\r\nMVO,\"Olá, mundo\"\r\n");}
    protected function tearDown():void{$this->store->close();}
    private function service(?callable $publisher=null,?callable $download=null,?SignalStore $store=null):GalaxyResponseService
    {
        return new GalaxyResponseService($store??$this->store,fn():array=>$this->credentials,$publisher??function(string $to,array $protocol,array $options):array{
            $this->sent[]=[$to,$protocol,$options];return ['id'=>$options['id'],'status'=>'server_ack'];
        },$download??function(array $descriptor):string{$this->descriptors[]=$descriptor;return $this->compressed;},fn():int=>$this->now*1000);
    }
    private function bundle(array $request,array $changes=[]):array
    {
        return $changes+['flowId'=>$request['flowId'],'galaxyFlowDownloadRequestId'=>$request['requestId'],'fileName'=>'respostas.csv','mimetype'=>'text/csv',
            'mediaKey'=>str_repeat("\xff",32),'fileSha256'=>str_repeat('h',32),'fileEncSha256'=>str_repeat('e',32),'directPath'=>'/v/t62/galaxy-private','fileLength'=>strlen($this->compressed)];
    }
    private function response(array $request,array $bundle=[],array $changes=[]):array
    {
        return $changes+['id'=>'PRIMARY-RESPONSE','fromMe'=>true,'authenticatedOwnDevice'=>true,'authenticatedSender'=>'100@s.whatsapp.net','proto'=>['protocolMessage'=>[
            'type'=>17,'peerDataOperationRequestResponseMessage'=>['peerDataOperationRequestType'=>11,'stanzaId'=>$request['stanzaId'],'peerDataOperationResult'=>[['flowResponsesCsvBundle'=>$this->bundle($request,$bundle)]]]],
        ]];
    }
    public function testWireMatchesPublicWebHandlerAndReservationPrecedesPublisher():void
    {
        $service=null;$service=$this->service(function(string $to,array $protocol,array $options)use(&$service):array{
            self::assertSame('prepared',$service->requestStatus('export-1')['state']);self::assertSame('100@s.whatsapp.net',$to);
            $wire=SchemaCodec::decode('Message.ProtocolMessage',SchemaCodec::encode('Message.ProtocolMessage',$protocol));
            self::assertSame(16,$wire['type']);self::assertSame(11,$wire['peerDataOperationRequestMessage']['peerDataOperationRequestType']);
            self::assertSame(['type'=>2,'flowId'=>'123456','galaxyFlowDownloadRequestId'=>'export-1'],$wire['peerDataOperationRequestMessage']['galaxyFlowAction']);
            self::assertSame('high',$options['pushPriority']);return ['id'=>$options['id'],'status'=>'server_ack'];
        });
        $request=$service->requestResponses('123456','export-1');self::assertSame('server_ack',$request['state']);self::assertFalse($request['csvReady']);
        self::assertSame(87400,$request['expiresAt']);self::assertSame([],$this->descriptors);
        $this->expectException(\LogicException::class);$service->downloadResponses('export-1');
    }
    public function testGeneratedRequestIdIsUuidAndSameIdDoesNotRepeatRequest():void
    {
        $service=$this->service();$request=$service->requestResponses('123');self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/D',$request['requestId']);
        self::assertSame($request,$service->requestResponses('123',$request['requestId']));self::assertCount(1,$this->sent);
        $this->expectException(\LogicException::class);$service->requestResponses('999',$request['requestId']);
    }
    public function testPreconditionsDoNotPublishOrReserveRequests():void
    {
        $service=$this->service();foreach([['flow','request'],['123','invalid request'],['','request']]as[$flow,$id]){
            try{$service->requestResponses($flow,$id);self::fail('Invalid input accepted');}catch(\InvalidArgumentException){}
        }
        $this->credentials['role']='primary';try{$service->requestResponses('123','request');self::fail('Primary unexpectedly requested its own export');}catch(\LogicException){}
        self::assertSame([],$this->store->entries('galaxy-response-requests'));self::assertSame([],$this->sent);
    }
    public function testTransportFailureKeepsUnknownAndBlocksImplicitRetry():void
    {
        $calls=0;$service=$this->service(static function()use(&$calls):never{$calls++;throw new \RuntimeException('private transport details');});
        try{$service->requestResponses('123','request');self::fail();}catch(\RuntimeException){}
        $record=$service->requestStatus('request');self::assertSame('unknown',$record['state']);self::assertSame('galaxy_export_outcome_unknown',$record['error']);self::assertStringNotContainsString('private',json_encode($record));
        self::assertSame($record,$service->requestResponses('123','request'));self::assertSame(1,$calls);
        try{$service->requestResponses('123','another-request');self::fail('Uncertain request was repeated');}catch(\LogicException){}self::assertSame(1,$calls);
    }
    #[DataProvider('wrongResponseFields')]
    public function testUntrustedOrUncorrelatedResponsesAreIgnored(string $kind):void
    {
        $service=$this->service();$request=$service->requestResponses('123','request');$event=$this->response($request);
        switch($kind){
            case 'not from me':$event['fromMe']=false;break;
            case 'not authenticated':$event['authenticatedOwnDevice']=false;break;
            case 'other user':$event['authenticatedSender']='200@s.whatsapp.net';break;
            case 'own companion':$event['authenticatedSender']='100:7@s.whatsapp.net';break;
            case 'invalid sender':$event['authenticatedSender']='not-a-jid';break;
            case 'wrong flow':$event=$this->response($request,['flowId'=>'999']);break;
            case 'wrong download id':$event=$this->response($request,['galaxyFlowDownloadRequestId'=>'another']);break;
            case 'missing correlation ids':unset($event['proto']['protocolMessage']['peerDataOperationRequestResponseMessage']['stanzaId'],$event['proto']['protocolMessage']['peerDataOperationRequestResponseMessage']['peerDataOperationResult'][0]['flowResponsesCsvBundle']['galaxyFlowDownloadRequestId']);break;
            case 'wrong stanza':$event['proto']['protocolMessage']['peerDataOperationRequestResponseMessage']['stanzaId']='other';break;
            case 'wrong type':$event['proto']['protocolMessage']['peerDataOperationRequestResponseMessage']['peerDataOperationRequestType']=14;break;
            case 'missing hash':unset($event['proto']['protocolMessage']['peerDataOperationRequestResponseMessage']['peerDataOperationResult'][0]['flowResponsesCsvBundle']['fileSha256']);break;
            case 'short key':$event=$this->response($request,['mediaKey'=>'short']);break;
            case 'oversized length':$event=$this->response($request,['fileLength'=>'18446744073709551615']);break;
        }
        $service->ingestAuthenticatedEvent($event);self::assertSame('server_ack',$service->requestStatus('request')['state']);self::assertFalse($service->requestStatus('request')['csvReady']);self::assertSame([],$this->descriptors);
    }
    public static function wrongResponseFields():iterable
    {
        foreach(['not from me','not authenticated','other user','own companion','invalid sender','wrong flow','wrong download id','missing correlation ids','wrong stanza','wrong type','missing hash','short key','oversized length']as$kind)yield $kind=>[$kind];
    }
    #[DataProvider('optionalCorrelationFields')]
    public function testOptionalBundleIdsMayBeAbsentWithAnotherReservedCorrelation(string $kind):void
    {
        $service=$this->service();$request=$service->requestResponses('123','request');$event=$this->response($request);
        $response=&$event['proto']['protocolMessage']['peerDataOperationRequestResponseMessage'];$bundle=&$response['peerDataOperationResult'][0]['flowResponsesCsvBundle'];
        if(in_array($kind,['missing flow','only stanza','only download id'],true))unset($bundle['flowId']);
        if(in_array($kind,['missing download id','only stanza'],true))unset($bundle['galaxyFlowDownloadRequestId']);
        if(in_array($kind,['missing stanza','only download id'],true))unset($response['stanzaId']);
        $service->ingestAuthenticatedEvent($event);self::assertSame('ready',$service->requestStatus('request')['state']);self::assertTrue($service->requestStatus('request')['csvReady']);self::assertSame([],$this->descriptors);
    }
    public static function optionalCorrelationFields():iterable
    {
        foreach(['missing flow','missing download id','missing stanza','only stanza','only download id']as$kind)yield $kind=>[$kind];
    }
    public function testIdsForTwoDifferentReservedRequestsCannotBeCombined():void
    {
        $service=$this->service();$first=$service->requestResponses('123','first');$second=$service->requestResponses('456','second');$event=$this->response($first,['flowId'=>'456','galaxyFlowDownloadRequestId'=>'second']);
        $service->ingestAuthenticatedEvent($event);self::assertSame('server_ack',$service->requestStatus('first')['state']);self::assertSame('server_ack',$service->requestStatus('second')['state']);
    }
    public function testOwnLidResponsePreservesBinaryDescriptorAndDoesNotDownloadInCallback():void
    {
        $service=$this->service();$request=$service->requestResponses('123','request');$event=$this->response($request,[],['authenticatedSender'=>'900@lid']);
        $service->ingestAuthenticatedEvent($event);$ready=$service->requestStatus('request');self::assertSame('ready',$ready['state']);self::assertTrue($ready['csvReady']);self::assertSame([],$this->descriptors);
        $json=json_encode($ready,JSON_THROW_ON_ERROR);foreach(['mediaKey','directPath','fileSha256','fileEncSha256','primaryJids','bundle']as$field)self::assertStringNotContainsString($field,$json);
        $reopened=$this->service();self::assertSame($ready,$reopened->requestStatus('request'));
        self::assertSame("name,answer\r\nMVO,\"Olá, mundo\"\r\n",$reopened->downloadResponses('request'));self::assertCount(1,$this->descriptors);self::assertSame(str_repeat("\xff",32),$this->descriptors[0]['mediaKey']);self::assertSame('document',$this->descriptors[0]['mediaType']);
        self::assertSame('downloaded',$reopened->requestStatus('request')['state']);
        $service->ingestAuthenticatedEvent($event);self::assertSame('downloaded',$service->requestStatus('request')['state']);
    }
    public function testResponseRacingWithAckOrPublisherExceptionCannotRegressReadyState():void
    {
        $service=null;$service=$this->service(function(string $to,array $protocol,array $options)use(&$service):array{
            $service->ingestAuthenticatedEvent($this->response($service->requestStatus('request')));return ['id'=>$options['id'],'status'=>'server_ack'];
        });self::assertSame('ready',$service->requestResponses('123','request')['state']);
        $service=null;$service=$this->service(function()use(&$service):never{$service->ingestAuthenticatedEvent($this->response($service->requestStatus('request-2')));throw new \RuntimeException('Lost ACK');});
        try{$service->requestResponses('456','request-2');self::fail();}catch(\RuntimeException){}self::assertSame('ready',$service->requestStatus('request-2')['state']);
    }
    public function testUnknownRequestCanStillReceiveAuthenticatedResponse():void
    {
        $service=$this->service(static fn():array=>[]);$request=$service->requestResponses('123','request');self::assertSame('unknown',$request['state']);
        $service->ingestAuthenticatedEvent($this->response($request));self::assertSame('ready',$service->requestStatus('request')['state']);
    }
    public function testMalformedWrapperDoesNotInterruptEventIngestion():void
    {
        $service=$this->service();$request=$service->requestResponses('123','request');$event=$this->response($request);$event['proto']=['ephemeralMessage'=>[]];
        $service->ingestAuthenticatedEvent($event);self::assertSame('server_ack',$service->requestStatus('request')['state']);
    }
    public function testExpiryClearsSecretsRejectsLateResponseAndAllowsNewRequest():void
    {
        $service=$this->service();$request=$service->requestResponses('123','request');$service->ingestAuthenticatedEvent($this->response($request));$this->now=87400;
        self::assertSame('expired',$service->requestStatus('request')['state']);self::assertFalse($service->requestStatus('request')['csvReady']);
        $raw=BinaryData::decode($this->store->get('galaxy-response-requests',hash('sha256','request')));self::assertArrayNotHasKey('bundle',$raw);
        $service->ingestAuthenticatedEvent($this->response($request));self::assertSame('expired',$service->requestStatus('request')['state']);
        try{$service->downloadResponses('request');self::fail('Expired descriptor downloaded');}catch(\LogicException){}self::assertSame([],$this->descriptors);
        self::assertSame('server_ack',$service->requestResponses('123','new-request')['state']);self::assertCount(2,$this->sent);
    }
    public function testDescriptorSurvivesSqliteReopeningAndSessionIsolation():void
    {
        $path=tempnam(sys_get_temp_dir(),'mvo-galaxy-');self::assertIsString($path);
        try{
            $store=new SignalStore($path,'owner');$service=$this->service(store:$store);$request=$service->requestResponses('123','request');$service->ingestAuthenticatedEvent($this->response($request));$store->close();
            $store=new SignalStore($path,'owner');$service=$this->service(store:$store);self::assertSame('ready',$service->requestStatus('request')['state']);self::assertStringContainsString('Olá',$service->downloadResponses('request'));$store->close();
            $other=new SignalStore($path,'other');self::assertNull($this->service(store:$other)->requestStatus('request'));$other->close();
        }finally{foreach([$path,$path.'-wal',$path.'-shm']as$file)if(is_file($file))unlink($file);}
    }
    public function testDownloadUsesAuthenticatedDocumentMediaAndPreservesCsvBytes():void
    {
        $this->compressed=gzencode("\xef\xbb\xbf\"Nome\",Valor\n\"Teste \"\"MVO\"\"\",\"linha 1\nlinha 2\"\n");$encrypted=MediaCrypto::encrypt($this->compressed,'document',str_repeat('k',32));$httpCalls=0;
        $transfer=new MediaTransfer(static fn():BinaryNode=>throw new \LogicException('Unexpected IQ'),static function()use($encrypted,&$httpCalls):array{$httpCalls++;return ['status'=>200,'body'=>$encrypted['body']];});
        $service=$this->service(download:$transfer->download(...));$request=$service->requestResponses('123','request');
        $service->ingestAuthenticatedEvent($this->response($request,$encrypted));self::assertSame(gzdecode($this->compressed),$service->downloadResponses('request'));self::assertSame(1,$httpCalls);
    }
    public function testCorruptedMediaIsRejectedBeforeGzipAndOutputDestinationIsUntouched():void
    {
        $encrypted=MediaCrypto::encrypt($this->compressed,'document',str_repeat('k',32));$body=$encrypted['body'];$body[0]=chr(ord($body[0])^1);
        $transfer=new MediaTransfer(static fn():BinaryNode=>throw new \LogicException('Unexpected IQ'),static fn():array=>['status'=>200,'body'=>$body]);
        $service=$this->service(download:$transfer->download(...));$request=$service->requestResponses('123','request');$service->ingestAuthenticatedEvent($this->response($request,$encrypted));$destination=tempnam(sys_get_temp_dir(),'mvo-galaxy-output-');file_put_contents($destination,'unchanged');
        try{try{$service->downloadResponses('request',$destination);self::fail('Corrupted media accepted');}catch(\RuntimeException){}self::assertSame('unchanged',file_get_contents($destination));self::assertSame('download_failed',$service->requestStatus('request')['state']);}
        finally{unlink($destination);}
    }
    #[DataProvider('invalidCompressedCsv')]
    public function testInvalidCompressedOrCsvContentIsRejected(string $kind):void
    {
        $this->compressed=match($kind){
            'not gzip'=>'name,value',
            'truncated'=>substr(gzencode('name,value'),0,-3),
            'bad checksum'=>substr(gzencode('name,value'),0,-8).str_repeat("\x00",8),
            'trailing data'=>gzencode('name,value').'trailing',
            'invalid utf8'=>gzencode("name\n\xff"),
            'nul'=>gzencode("name\n\0"),
            'unclosed quote'=>gzencode("name\n\"value"),
            'quote after text'=>gzencode('name,va"lue'),
            'text after quoted field'=>gzencode('name,"value"oops'),
            'gzip bomb'=>gzencode(str_repeat('x',GalaxyResponseService::MAX_CSV_BYTES+1)),
        };
        $service=$this->service();$request=$service->requestResponses('123','request');$service->ingestAuthenticatedEvent($this->response($request));
        try{$service->downloadResponses('request');self::fail('Invalid export accepted: '.$kind);}catch(\UnexpectedValueException|\LengthException){}
        self::assertSame('download_failed',$service->requestStatus('request')['state']);self::assertSame('galaxy_csv_download_failed',$service->requestStatus('request')['error']);
    }
    public static function invalidCompressedCsv():iterable
    {
        foreach(['not gzip','truncated','bad checksum','trailing data','invalid utf8','nul','unclosed quote','quote after text','text after quoted field','gzip bomb']as$kind)yield $kind=>[$kind];
    }
    public function testDownloadFailureMayRetryDownloadWithoutRepeatingPeerRequest():void
    {
        $attempts=0;$service=$this->service(download:function()use(&$attempts):string{if(++$attempts===1)throw new \RuntimeException('temporary media failure');return $this->compressed;});
        $request=$service->requestResponses('123','request');$service->ingestAuthenticatedEvent($this->response($request));try{$service->downloadResponses('request');self::fail();}catch(\RuntimeException){}
        self::assertSame(gzdecode($this->compressed),$service->downloadResponses('request'));self::assertCount(1,$this->sent);self::assertSame(2,$attempts);
    }
    public function testAtomicDestinationAndInvalidDestinationPrecondition():void
    {
        $service=$this->service();$request=$service->requestResponses('123','request');$service->ingestAuthenticatedEvent($this->response($request));
        try{$service->downloadResponses('request','https://example.com/export.csv');self::fail();}catch(\InvalidArgumentException){}self::assertSame([],$this->descriptors);
        $destination=tempnam(sys_get_temp_dir(),'mvo-galaxy-output-');
        try{self::assertSame($destination,$service->downloadResponses('request',$destination));self::assertSame(gzdecode($this->compressed),file_get_contents($destination));}finally{unlink($destination);}
    }
    public function testCredentialChangeCannotAuthorizeOldAccountResponse():void
    {
        $service=$this->service();$request=$service->requestResponses('123','request');$this->credentials=['meJid'=>'200:5@s.whatsapp.net'];
        $service->ingestAuthenticatedEvent($this->response($request));$service->ingestAuthenticatedEvent($this->response($request,[],['authenticatedSender'=>'200@s.whatsapp.net']));
        self::assertSame('server_ack',$service->requestStatus('request')['state']);
    }
}
