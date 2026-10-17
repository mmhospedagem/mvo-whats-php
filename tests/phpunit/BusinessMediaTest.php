<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Media\{MediaSource,MediaTransfer};
use MVO\Protocol\BinaryNode;
use MVO\Service\{BusinessService,CatalogService,ServiceException,ServiceTransport};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BusinessMediaTest extends TestCase
{
    private const PNG='iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jL/QAAAAASUVORK5CYII=';
    private static function image():string{return base64_decode(self::PNG,true);}
    private static function conn(string $auth='fixture-token'):BinaryNode{return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('media_conn',['auth'=>$auth,'ttl'=>'3600'],[new BinaryNode('host',['hostname'=>'mmg.whatsapp.net'])])]);}
    public function testCatalogImagesUploadOriginalPlaintextAndUseItsHash():void{
        $requests=[];$queries=0;$image=self::image();
        $transfer=new MediaTransfer(function(BinaryNode $node)use(&$queries):BinaryNode{$queries++;self::assertSame('w:m',$node->attrs['xmlns']);return self::conn();},function(array $request)use(&$requests):array{$requests[]=$request;return ['status'=>200,'body'=>'{"direct_path":"/product/fixture.png"}'];});
        $result=$transfer->uploadBusiness($image,'product-catalog-image');$token=rtrim(strtr(base64_encode(hash('sha256',$image,true)),'+/','-_'),'=');
        self::assertSame($image,$requests[0]['body']);self::assertStringStartsWith('https://mmg.whatsapp.net/product/image/'.$token.'?auth=fixture-token&token='.$token,$requests[0]['url']);
        self::assertSame('application/octet-stream',$requests[0]['headers']['Content-Type']);self::assertSame('https://mmg.whatsapp.net/product/fixture.png',$result['url']);
        self::assertSame(hash('sha256',$image,true),$result['fileSha256']);self::assertSame(strlen($image),$result['fileLength']);self::assertArrayNotHasKey('mediaKey',$result);
        $transfer->uploadBusiness($image,'product-catalog-image');self::assertSame(1,$queries);
    }
    public function testCoverBindsOnlyMetadataReturnedBySuccessfulPlaintextUpload():void{
        $nodes=[];$requests=[];$image=self::image();
        $transport=new ServiceTransport(function(BinaryNode $node)use(&$nodes):BinaryNode{$nodes[]=$node;return new BinaryNode('iq',['type'=>'result']);},static function(BinaryNode $node):void{},static fn():array=>[]);
        $transfer=new MediaTransfer(static fn(BinaryNode $node):BinaryNode=>self::conn(),function(array $request)use(&$requests):array{$requests[]=$request;return ['status'=>200,'body'=>'{"url":"https://mmg.whatsapp.net/pps/fixture.jpg","fbid":"9007199254740993","ts":12345,"meta_hmac":"fixture-hmac"}'];});
        $business=new BusinessService($transport,coverUploader:static fn(mixed $source):array=>$transfer->uploadBusiness(MediaSource::read($source,1048576),'biz-cover-photo'));
        self::assertSame('9007199254740993',$business->updateCoverPhoto(['bytes'=>$image]));self::assertSame($image,$requests[0]['body']);
        self::assertStringContainsString('/pps/biz-cover-photo/',$requests[0]['url']);$profile=$nodes[0]->child('business_profile');
        self::assertSame(['v'=>'3','mutation_type'=>'delta'],$profile->attrs);self::assertSame(['op'=>'update','id'=>'9007199254740993','ts'=>'12345','token'=>'fixture-hmac'],$profile->child('cover_photo')->attrs);
    }
    public function testAuthenticationRefreshRetriesSamePlaintextOnceWithNewMediaToken():void{
        $requests=[];$connections=0;
        $transfer=new MediaTransfer(function(BinaryNode $node)use(&$connections):BinaryNode{return self::conn('token-'.++$connections);},function(array $request)use(&$requests):array{$requests[]=$request;return count($requests)===1?['status'=>401,'body'=>'']:['status'=>200,'body'=>'{"url":"https://mmg.whatsapp.net/product/fixture.jpg"}'];});
        $transfer->uploadBusiness(self::image(),'product-catalog-image');self::assertSame(2,$connections);self::assertCount(2,$requests);self::assertSame($requests[0]['body'],$requests[1]['body']);self::assertStringContainsString('auth=token-2',$requests[1]['url']);
    }
    public function testPpsMetadataWithoutLocationIsValidAndLargeNumericIdIsPreserved():void{
        $transfer=new MediaTransfer(static fn(BinaryNode $node):BinaryNode=>self::conn(),static fn(array $request):array=>['status'=>200,'body'=>'{"fbid":18446744073709551615,"ts":12345,"meta_hmac":"fixture-hmac"}']);
        $result=$transfer->uploadBusiness(self::image(),'biz-cover-photo');self::assertSame('18446744073709551615',$result['id']);self::assertSame('12345',$result['timestamp']);self::assertSame('fixture-hmac',$result['token']);self::assertArrayNotHasKey('url',$result);
    }
    public function testMissingCoverMetadataNeverIssuesProfileMutation():void{
        $mutations=0;$transport=new ServiceTransport(function(BinaryNode $node)use(&$mutations):BinaryNode{$mutations++;return new BinaryNode('iq',['type'=>'result']);},static function(BinaryNode $node):void{},static fn():array=>[]);
        $transfer=new MediaTransfer(static fn(BinaryNode $node):BinaryNode=>self::conn(),static fn(array $request):array=>['status'=>200,'body'=>'{"url":"https://mmg.whatsapp.net/pps/fixture.jpg","fbid":"123","ts":1}']);
        $business=new BusinessService($transport,coverUploader:static fn(mixed $source):array=>$transfer->uploadBusiness(MediaSource::read($source,1048576),'biz-cover-photo'));
        try{$business->updateCoverPhoto(['bytes'=>self::image()]);self::fail('Missing metadata accepted');}catch(\RuntimeException $error){self::assertStringContainsString('metadata token',$error->getMessage());}self::assertSame(0,$mutations);
    }
    public function testCoverServerDenialDoesNotReturnAnUploadedIdAsMutationSuccess():void{
        $transport=new ServiceTransport(static fn(BinaryNode $node):BinaryNode=>new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'403','text'=>'not authorized'])]),static function(BinaryNode $node):void{},static fn():array=>[]);
        $business=new BusinessService($transport,coverUploader:static fn(mixed $source):array=>['id'=>'123','timestamp'=>'1','token'=>'fixture-hmac']);
        try{$business->updateCoverPhoto(['bytes'=>self::image()]);self::fail('Denied mutation succeeded');}catch(ServiceException $error){self::assertSame('403',$error->serverCode);self::assertSame('business.coverPhoto',$error->operation);}
    }
    public function testProfileReadsNormalizeDeviceJidAndExposeCoverFields():void{
        $nodes=[];$transport=new ServiceTransport(function(BinaryNode $node)use(&$nodes):BinaryNode{$nodes[]=$node;return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('business_profile',[],[new BinaryNode('profile',['jid'=>'551100000000@s.whatsapp.net'],[new BinaryNode('cover_photo',['id'=>'123'],[new BinaryNode('url',[],'https://mmg.whatsapp.net/cover.jpg')]),new BinaryNode('profile_options',[],[new BinaryNode('cart_enabled',[],'true')])])])]);},static function(BinaryNode $node):void{},static fn():array=>[]);
        $profile=(new BusinessService($transport))->getBusinessProfile(['551100000000:5@s.whatsapp.net'])[0];self::assertSame('551100000000@s.whatsapp.net',$nodes[0]->child('business_profile')->child('profile')->attrs['jid']);self::assertSame('123',$profile['coverPhoto']['id']);self::assertSame('true',$profile['profileOptions']['cart_enabled']);
    }
    public static function invalidMedia():iterable{yield ['not an image','product-catalog-image'];yield [self::image(),'image'];yield ['', 'biz-cover-photo'];}
    public function testHostedAccountJidsNormalizeBeforeCommercialValidation():void{
        $nodes=[];$transport=new ServiceTransport(function(BinaryNode $node)use(&$nodes):BinaryNode{$nodes[]=$node;return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('product_catalog')]);},static function(BinaryNode $node):void{},static fn():array=>['meJid'=>'551100000000:5@hosted']);
        (new CatalogService($transport))->getCatalog();self::assertSame('551100000000@s.whatsapp.net',$nodes[0]->child('product_catalog')->attrs['jid']);
        $business=new BusinessService($transport);$business->getBusinessProfile(['123456:8@hosted.lid']);self::assertSame('123456@lid',$nodes[1]->child('business_profile')->child('profile')->attrs['jid']);
        $business->getVerifiedName('551100000000:5@hosted');self::assertSame('551100000000@s.whatsapp.net',$nodes[2]->child('verified_name')->attrs['jid']);
        $business->getVerifiedNames(['123456:8@hosted.lid']);self::assertSame('123456@lid',$nodes[3]->child('usync')->child('list')->child('user')->attrs['jid']);
    }
    public function testTypedTransportRejectionPreservesOperationAndServerReason():void{
        $transport=new ServiceTransport(static function(BinaryNode $node):BinaryNode{throw new ServiceException('w:biz','403','Business account permission denied');},static function(BinaryNode $node):void{},static fn():array=>[]);
        try{(new BusinessService($transport))->deleteCoverPhoto('123');self::fail('Rejection lost');}catch(ServiceException $error){self::assertSame('business.coverPhoto',$error->operation);self::assertSame('403',$error->serverCode);self::assertSame('Business account permission denied',$error->reason);}
    }
    #[DataProvider('invalidMedia')]
    public function testInvalidBusinessMediaNeverRequestsNetwork(string $bytes,string $type):void{
        $calls=0;$transfer=new MediaTransfer(function(BinaryNode $node)use(&$calls):BinaryNode{$calls++;return self::conn();});
        try{$transfer->uploadBusiness($bytes,$type);self::fail('Invalid media accepted');}catch(\InvalidArgumentException|\LengthException){}self::assertSame(0,$calls);
    }
    public static function invalidProfiles():iterable{
        yield [['email'=>'invalid']];
        yield [['websites'=>[['bad'=>'https://mvo.host']]]];
        yield [['websites'=>['file:///private']]];
        yield [['categories'=>[[]]]];
        yield [['businessHours'=>['config'=>[[]]]]];
        yield [['businessHours'=>['timezone'=>'invalid/timezone','config'=>[]]]];
        yield [['businessHours'=>['config'=>[['dayOfWeek'=>'mon','mode'=>'specific_hours','openTime'=>10]]]]];
        yield [['inventedField'=>true]];
    }
    #[DataProvider('invalidProfiles')]
    public function testInvalidProfileDataDoesNotSendPartialMutation(array $input):void{
        $calls=0;$transport=new ServiceTransport(function(BinaryNode $node)use(&$calls):BinaryNode{$calls++;return new BinaryNode('iq',['type'=>'result']);},static function(BinaryNode $node):void{},static fn():array=>[]);
        try{(new BusinessService($transport))->editBusinessProfile($input);self::fail('Invalid profile accepted');}catch(\InvalidArgumentException){}self::assertSame(0,$calls);
    }
}
