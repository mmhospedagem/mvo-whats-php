<?php
declare(strict_types=1);

namespace MVO\Tests;

use MVO\Protocol\BinaryNode;
use MVO\Service\{CatalogService, ServiceException, ServiceTransport};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogTest extends TestCase
{
    private array $queries = [];
    private array $responses = [];

    private function service(?callable $upload = null, array $credentials = ['meJid'=>'5511999999999:3@s.whatsapp.net']): CatalogService
    {
        return new CatalogService(new ServiceTransport(function(BinaryNode $query, float $timeout):BinaryNode {
            $this->queries[] = $query;
            if ($this->responses === []) throw new \LogicException('Test response missing');
            return array_shift($this->responses);
        }, static function(BinaryNode $node):void {}, static fn():array=>$credentials), $upload);
    }

    private static function iqResult(array $nodes = []): BinaryNode { return new BinaryNode('iq', ['type'=>'result'], $nodes); }
    private static function mexResult(array $data): BinaryNode { return self::iqResult([new BinaryNode('result', [], json_encode(['data'=>$data], JSON_THROW_ON_ERROR))]); }
    private static function text(string $tag, string $value): BinaryNode { return new BinaryNode($tag, [], $value); }
    private static function product(string $id = '987'): BinaryNode
    {
        return new BinaryNode('product', ['is_hidden'=>'true'], [self::text('id',$id), self::text('name','Plano MVO'), self::text('description','Hospedagem PHP'), self::text('retailer_id','SKU-PHP'), self::text('price','29990'), self::text('currency','BRL'), new BinaryNode('sale_price', [], [self::text('price','19990')]), new BinaryNode('media', [], [new BinaryNode('image', [], [self::text('request_image_url','https://mmg.whatsapp.net/preview'), self::text('original_image_url','https://mmg.whatsapp.net/original')]), new BinaryNode('image', [], [self::text('original_image_url','https://mmg.whatsapp.net/second')])]), new BinaryNode('status_info', [], [self::text('status','APPROVED'), self::text('can_appeal','false')])]);
    }
    private static function createInput(): array { return ['name'=>'Plano MVO','description'=>'Hospedagem PHP','price'=>'29990','currency'=>'BRL','images'=>[['url'=>'https://mmg.whatsapp.net/image']]]; }
    private function last(): BinaryNode { return $this->queries[array_key_last($this->queries)]; }
    private function variables(): array { return json_decode($this->last()->child('query')->content, true, 512, JSON_THROW_ON_ERROR)['variables']; }

    public function testCatalogPaginationUsesConnectedAccountAndParsesRealProductFields(): void
    {
        $this->responses[] = self::iqResult([new BinaryNode('product_catalog', [], [self::product(), new BinaryNode('paging', [], [self::text('after','NEXT=+12')])])]);
        $catalog = $this->service()->getCatalog(null,25,'START');
        self::assertSame(['type'=>'get','to'=>'s.whatsapp.net','xmlns'=>'w:biz:catalog'], $this->last()->attrs);
        $request = $this->last()->child('product_catalog');
        self::assertSame(['jid'=>'5511999999999@s.whatsapp.net','allow_shop_source'=>'true'], $request->attrs);
        self::assertSame(['25','100','100','START'], array_map(static fn(BinaryNode $n)=>$n->content,$request->content));
        self::assertSame('NEXT=+12',$catalog['nextPageCursor']);
        $product = $catalog['products'][0];
        self::assertSame('987',$product['id']);
        self::assertSame(29990,$product['price']);
        self::assertSame(19990,$product['salePrice']);
        self::assertTrue($product['isHidden']);
        self::assertSame('APPROVED',$product['reviewStatus']['whatsapp']);
        self::assertCount(2,$product['images']);
        self::assertSame('https://mmg.whatsapp.net/original',$product['imageUrls']['original']);
        self::assertArrayNotHasKey('availability',$product, 'Do not invent in-stock availability absent from server');
    }

    public function testProductCreateUsesUploadedImageAndServerGeneratedId(): void
    {
        $uploads = [];
        $this->responses[] = self::iqResult([new BinaryNode('product_catalog_add', ['v'=>'1'], [self::product('SERVER-ID')])]);
        $input = self::createInput();
        $input['images'] = [['path'=>'image.png'],['url'=>'https://mmg.whatsapp.net/already']];
        $input['originCountryCode'] = null;
        $created = $this->service(static function(string|array $source) use (&$uploads):string { $uploads[]=$source; return 'https://mmg.whatsapp.net/uploaded'; })->createProduct($input);
        self::assertSame('SERVER-ID',$created['id']);
        self::assertSame([['path'=>'image.png']],$uploads);
        self::assertSame('set',$this->last()->attrs['type']);
        $add = $this->last()->child('product_catalog_add');
        self::assertSame(['v'=>'1'],$add->attrs);
        self::assertSame(['compliance_category'=>'COUNTRY_ORIGIN_EXEMPT','is_hidden'=>'false'],$add->child('product')->attrs);
        self::assertNull($add->child('product')->child('id'));
        self::assertSame(['https://mmg.whatsapp.net/uploaded','https://mmg.whatsapp.net/already'],array_map(static fn(BinaryNode $n)=>$n->child('url')->content,$add->child('product')->child('media')->content));
    }

    public function testPartialProductUpdateDoesNotUploadOrOverwriteUnspecifiedFields(): void
    {
        $this->responses[] = self::iqResult([new BinaryNode('product_catalog_edit', [], [self::product()])]);
        $result = $this->service()->updateProduct('987',['isHidden'=>false,'salePrice'=>'0']);
        self::assertSame('987',$result['id']);
        $edit = $this->last()->child('product_catalog_edit')->child('product');
        self::assertSame(['is_hidden'=>'false'],$edit->attrs);
        self::assertSame('987',$edit->child('id')->content);
        self::assertSame('0',$edit->child('sale_price')->content);
        self::assertNull($edit->child('media'));
        self::assertNull($edit->child('name'));
        self::assertNull($edit->child('price'));
    }

    public function testProductAliasesUseCatalogIntegerUnitsWithoutRounding(): void
    {
        $this->responses[] = self::iqResult([new BinaryNode('product_catalog_edit', [], [self::product()])]);
        $this->service()->productUpdate('987',['priceAmount1000'=>'9007199254740993','currencyCode'=>'BRL','originCountryCode'=>'BR']);
        $product=$this->last()->child('product_catalog_edit')->child('product');
        self::assertSame('9007199254740993',$product->child('price')->content);
        self::assertSame('BRL',$product->child('currency')->content);
        self::assertSame('BR',$product->child('compliance_info')->child('country_code_origin')->content);
    }

    public function testDeleteReturnsServerCountAndDeduplicatesIds(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('product_catalog_delete',['deleted_count'=>'1'])]);
        self::assertSame(['deleted'=>1],$this->service()->deleteProducts(['987','876','987']));
        $request=$this->last()->child('product_catalog_delete');
        self::assertSame(['v'=>'1'],$request->attrs);
        self::assertSame(['987','876'],array_map(static fn(BinaryNode $n)=>$n->child('id')->content,$request->content));
    }

    public function testCollectionsDecodeModerationAndProducts(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('collections',[],[new BinaryNode('collection',[],[self::text('id','COLL1'),self::text('name','Ofertas'),self::product(),new BinaryNode('status_info',[],[self::text('status','REJECTED'),self::text('can_appeal','true')])])])]);
        $collections=$this->service()->getCollections('5511888888888',51);
        self::assertSame('35',$this->last()->attrs['smax_id']);
        self::assertSame(['biz_jid'=>'5511888888888@s.whatsapp.net'],$this->last()->child('collections')->attrs);
        self::assertSame('51',$this->last()->child('collections')->child('collection_limit')->content);
        self::assertSame('COLL1',$collections['collections'][0]['id']);
        self::assertTrue($collections['collections'][0]['status']['canAppeal']);
        self::assertSame('987',$collections['collections'][0]['products'][0]['id']);
    }

    public function testCollectionIqPreservesVisibilityAndModerationDetailsWhenProvided(): void
    {
        $collection = CatalogService::parseCollection(new BinaryNode('collection', [], [self::text('id','COLL1'),self::text('name','Ofertas'),self::text('is_hidden','TRUE'),new BinaryNode('status_info', [], [self::text('status','REJECTED'),self::text('can_appeal','true'),self::text('reject_reason','IMAGE_POLICY'),self::text('commerce_url','https://commerce.example/review/COLL1')])]));
        self::assertTrue($collection['isHidden']);
        self::assertSame('IMAGE_POLICY',$collection['status']['rejectReason']);
        self::assertSame('https://commerce.example/review/COLL1',$collection['status']['commerceUrl']);
        $visible = CatalogService::parseCollection(new BinaryNode('collection', ['is_hidden'=>'false'], [self::text('id','COLL2'),self::text('name','Visível')]));
        self::assertFalse($visible['isHidden']);
        self::assertArrayNotHasKey('status',$visible);
    }

    public function testCollectionWithoutVisibilityOrReviewDetailsDoesNotInventThoseFields(): void
    {
        $collection = CatalogService::parseCollection(new BinaryNode('collection', [], [self::text('id','COLL1'),self::text('name','Ofertas'),new BinaryNode('status_info', [], [self::text('status','PENDING')])]));
        self::assertArrayNotHasKey('isHidden',$collection);
        self::assertArrayNotHasKey('rejectReason',$collection['status']);
        self::assertArrayNotHasKey('commerceUrl',$collection['status']);
        $this->responses[]=self::mexResult(['xwa_product_catalog_get_single_collection'=>['collection'=>['id'=>'COLL1','name'=>'Ofertas','products'=>[]]]]);
        $mexCollection=$this->service()->getCollection('COLL1');
        self::assertArrayNotHasKey('isHidden',$mexCollection);
        self::assertArrayNotHasKey('status',$mexCollection);
    }

    public function testMexCollectionPreservesExplicitFalseVisibilityAndReviewDetails(): void
    {
        $this->responses[]=self::mexResult(['xwa_product_catalog_get_single_collection'=>['collection'=>['id'=>'COLL1','name'=>'Ofertas','products'=>[],'is_hidden'=>false,'status_info'=>['status'=>'REJECTED','can_appeal'=>true,'reject_reason'=>'COPYRIGHT','commerce_url'=>'https://commerce.example/review/COLL1']]]]);
        $collection=$this->service()->getCollection('COLL1');
        self::assertFalse($collection['isHidden']);
        self::assertSame('COPYRIGHT',$collection['status']['rejectReason']);
        self::assertSame('https://commerce.example/review/COLL1',$collection['status']['commerceUrl']);
        self::assertTrue($collection['status']['canAppeal']);
    }

    public function testInvalidIqCollectionVisibilityIsNotReportedAsVisible(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        CatalogService::parseCollection(new BinaryNode('collection', [], [self::text('id','COLL1'),self::text('name','Ofertas'),self::text('is_hidden','unknown')]));
    }

    public function testInvalidMexCollectionVisibilityIsNotReportedAsVisible(): void
    {
        $this->responses[]=self::mexResult(['xwa_product_catalog_get_single_collection'=>['collection'=>['id'=>'COLL1','name'=>'Ofertas','products'=>[],'is_hidden'=>'false']]]);
        $this->expectException(\UnexpectedValueException::class);
        $this->service()->getCollection('COLL1');
    }

    public function testSingleProductUsesPinnedMexOperationAndPreservesVariants(): void
    {
        $this->responses[]=self::mexResult(['xwa_product_catalog_get_product'=>['product_catalog'=>['product'=>['id'=>'987','name'=>'Plano','price'=>'9007199254740993','currency'=>'BRL','is_hidden'=>true,'sale_price'=>['price'=>'1','start_date'=>'2026-10-14'],'media'=>['images'=>[['id'=>'IMG','request_image_url'=>'https://mmg.whatsapp.net/image']],'videos'=>[['id'=>'VID','original_video_url'=>'https://mmg.whatsapp.net/video']]],'variant_info'=>['types'=>[['name'=>'Cor']]],'status_info'=>['status'=>'APPROVED','can_appeal'=>true]]]]]);
        $product=$this->service()->getProduct('987');
        self::assertSame('w:mex',$this->last()->attrs['xmlns']);
        self::assertSame(['request'=>['product'=>['jid'=>'5511999999999@s.whatsapp.net','product_id'=>'987','width'=>'100','height'=>'100','fetch_compliance_info'=>'true']]],$this->variables());
        self::assertSame(9007199254740993,$product['price']);
        self::assertSame('2026-10-14',$product['saleStartDate']);
        self::assertSame('Cor',$product['variantInfo']['types'][0]['name']);
        self::assertSame('VID',$product['videos'][0]['id']);
        self::assertTrue($product['reviewStatus']['canAppeal']);
    }

    public function testProductListAndSingleCollectionHaveTypedMexVariables(): void
    {
        $this->responses[]=self::mexResult(['xwa_product_catalog_get_product_list'=>['product_list'=>['products'=>[['id'=>'987','name'=>'Plano']]]]]);
        $this->responses[]=self::mexResult(['xwa_product_catalog_get_single_collection'=>['collection'=>['id'=>'COLL1','name'=>'Ofertas','products'=>[['id'=>'987','name'=>'Plano']],'status_info'=>['status'=>'APPROVED','can_appeal'=>'false']],'paging'=>['after'=>'NEXT']]]);
        $service=$this->service();
        self::assertSame('987',$service->getProducts(['987','987'])['products'][0]['id']);
        self::assertSame([['id'=>'987']],$this->variables()['request']['product_list']['products']);
        $collection=$service->getCollection('COLL1',null,20,'CURSOR');
        self::assertSame('NEXT',$collection['nextPageCursor']);
        self::assertSame('987',$collection['products'][0]['id']);
        self::assertFalse($collection['status']['canAppeal']);
        self::assertSame(['biz_jid'=>'5511999999999@s.whatsapp.net','id'=>'COLL1','limit'=>'20','width'=>'100','height'=>'100','after'=>'CURSOR'],$this->variables()['request']['collection']);
    }

    public function testCheckoutOrderAndCartReturnOnlyServerResults(): void
    {
        $this->responses[]=self::mexResult(['xwa_checkout_place_order'=>['order'=>['order_id'=>'ORDER1','token'=>'opaque-token','price'=>['total_amount'=>29990,'currency'=>'BRL']]]]);
        $this->responses[]=self::mexResult(['xwa_checkout_get_order_info'=>['order'=>['creation_time_stamp'=>'1720000000','products'=>[['id'=>'987','quantity'=>'1']]]]]);
        $this->responses[]=self::mexResult(['xwa_checkout_refresh_cart'=>['cart'=>['products'=>[['id'=>'987','status'=>'AVAILABLE']],'price_details'=>['total_amount'=>29990]]]]);
        $service=$this->service();
        $created=$service->createOrder('5511888888888',[['id'=>'987','quantity'=>1]]);
        self::assertSame('ORDER1',$created['order_id']);
        self::assertSame(['input'=>['order'=>['jid'=>'5511888888888@s.whatsapp.net','products'=>[['id'=>'987','quantity'=>1]]]]],$this->variables());
        self::assertSame('987',$service->getOrder('ORDER1','opaque-token')['products'][0]['id']);
        self::assertSame(['sensitive_string_value'=>'opaque-token'],$this->variables()['request']['order']['token']);
        $cartRequest=['cart'=>['products'=>[['id'=>'987','quantity'=>1]]]];
        self::assertSame(29990,$service->refreshCart($cartRequest)['price_details']['total_amount']);
        self::assertSame(['request'=>$cartRequest],$this->variables());
    }

    public function testOriginalOrderIqRetainsEncodedTokenAndAmounts(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('order',[],[new BinaryNode('price',[],[self::text('total','29990'),self::text('currency','BRL')]),new BinaryNode('product',[],[self::text('id','987'),self::text('name','Plano'),self::text('price','29990'),self::text('currency','BRL'),self::text('quantity','1'),new BinaryNode('image',[],[self::text('url','https://mmg.whatsapp.net/image')])])])]);
        $token=base64_encode('opaque-token');
        $order=$this->service()->getOrderDetails('ORDER1',$token);
        self::assertSame(['type'=>'get','to'=>'s.whatsapp.net','xmlns'=>'fb:thrift_iq','smax_id'=>'5'],$this->last()->attrs);
        self::assertSame($token,$this->last()->child('order')->child('token')->content);
        self::assertSame(29990,$order['price']['total']);
        self::assertSame(1,$order['products'][0]['quantity']);
    }

    public static function invalidProducts(): iterable
    {
        yield 'fractional price'=>[['price'=>29.99]];
        yield 'negative price'=>[['price'=>-1]];
        yield 'missing name'=>[['name'=>'']];
        yield 'bad currency'=>[['currency'=>'real']];
        yield 'not boolean'=>[['isHidden'=>'false']];
        yield 'null boolean'=>[['isHidden'=>null]];
        yield 'invalid origin'=>[['originCountryCode'=>'BRA']];
        yield 'unknown key'=>[['customerSecret'=>'secret']];
        yield 'empty images'=>[['images'=>[]]];
        yield 'mixed image validation'=>[['images'=>[['path'=>'x'],123]]];
        yield 'bad link'=>[['url'=>'javascript:alert(1)']];
        yield 'price alias conflict'=>[['priceAmount1000'=>'123']];
    }

    #[DataProvider('invalidProducts')]
    public function testInvalidProductDoesNotQueryOrUpload(array $patch): void
    {
        $uploadCount=0;
        $service=$this->service(static function(string|array $source) use (&$uploadCount):string { ++$uploadCount; return 'https://mmg.whatsapp.net/image'; });
        try { $service->createProduct(array_replace(self::createInput(),$patch)); self::fail('Expected validation failure'); }
        catch (\InvalidArgumentException $e) { self::assertNotSame('',$e->getMessage()); }
        self::assertSame(0,$uploadCount);
        self::assertCount(0,$this->queries);
    }

    public static function cdnUrls(): iterable
    {
        yield ['https://mmg.whatsapp.net/catalog/image',true];
        yield ['https://pps.whatsapp.net/asset?token=abc',true];
        yield ['https://mmg.whatsapp.net.attacker.example/image',false];
        yield ['https://attacker.example/.whatsapp.net/image',false];
        yield ['https://attacker.example/?next=.whatsapp.net',false];
        yield ['http://mmg.whatsapp.net/image',false];
        yield ['https://user:secret@mmg.whatsapp.net/image',false];
        yield ['https://mmg.whatsapp.net:8443/image',false];
    }

    #[DataProvider('cdnUrls')]
    public function testUploadedImageHostMustBeRealWhatsAppCdn(string $url,bool $expected): void { self::assertSame($expected,CatalogService::isUploadedImageUrl($url)); }

    public function testUploadFailurePreventsMutation(): void
    {
        $input=self::createInput();$input['images']=[['path'=>'image.png']];
        $this->expectException(\UnexpectedValueException::class);
        try { $this->service(static fn(string|array $source):string=>'https://attacker.example/image')->createProduct($input); }
        finally { self::assertCount(0,$this->queries); }
    }

    public function testIqAndNestedProductRefusalsAreNotSuccess(): void
    {
        $this->responses[]=new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'403','text'=>'not-business-account'])]);
        $this->responses[]=self::iqResult([new BinaryNode('product_catalog_edit',[],[new BinaryNode('product',[],[new BinaryNode('error',['code'=>'404','text'=>'not-found'])])])]);
        $service=$this->service();
        try { $service->getCatalog();self::fail('Expected refusal'); } catch (ServiceException $e) { self::assertSame('403',$e->serverCode); }
        try { $service->updateProduct('987',['name'=>'Novo']);self::fail('Expected refusal'); } catch (ServiceException $e) { self::assertSame('404',$e->serverCode); }
    }

    public static function incompleteResponses(): iterable
    {
        yield 'missing created product'=>[self::iqResult([new BinaryNode('product_catalog_add')]),'create'];
        yield 'missing created id'=>[self::iqResult([new BinaryNode('product_catalog_add',[],[new BinaryNode('product',[],[self::text('name','Plano')])])]),'create'];
        yield 'missing delete count'=>[self::iqResult([new BinaryNode('product_catalog_delete')]),'delete'];
        yield 'delete impossible count'=>[self::iqResult([new BinaryNode('product_catalog_delete',['deleted_count'=>'2'])]),'delete'];
        yield 'missing catalog'=>[self::iqResult(),'catalog'];
        yield 'missing mex product'=>[self::mexResult(['xwa_product_catalog_get_product'=>['product_catalog'=>[]]]),'single'];
        yield 'missing order id'=>[self::mexResult(['xwa_checkout_place_order'=>['order'=>['token'=>'token']]]),'order'];
    }

    #[DataProvider('incompleteResponses')]
    public function testIncompleteResponseNeverClaimsMutationSucceeded(BinaryNode $response,string $operation): void
    {
        $this->responses[]=$response;$service=$this->service();
        $this->expectException(\UnexpectedValueException::class);
        match($operation) { 'create'=>$service->createProduct(self::createInput()),'delete'=>$service->deleteProducts(['987']),'single'=>$service->getProduct('987'),'order'=>$service->createOrder('5511888888888',[['id'=>'987','quantity'=>1]]),default=>$service->getCatalog() };
    }

    public function testGraphqlBusinessAccountErrorPropagatesServerCode(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('result',[],json_encode(['errors'=>[['message'=>'catalog not available','extensions'=>['error_code'=>403]]],'data'=>null],JSON_THROW_ON_ERROR))]);
        try { $this->service()->getProduct('987');self::fail('Expected GraphQL refusal'); }
        catch(ServiceException $e) { self::assertSame('403',$e->serverCode);self::assertSame('mex.QueryCatalogProduct',$e->operation); }
    }

    public function testProductUpdateAndReadRejectDifferentReturnedIds(): void
    {
        $this->responses[]=self::iqResult([new BinaryNode('product_catalog_edit',[],[self::product('DIFFERENT')])]);
        $this->responses[]=self::mexResult(['xwa_product_catalog_get_product'=>['product_catalog'=>['product'=>['id'=>'DIFFERENT','name'=>'Plano']]]]);
        $service=$this->service();
        foreach (['update','get'] as $operation) {
            try { $operation==='update' ? $service->updateProduct('987',['name'=>'Novo']) : $service->getProduct('987');self::fail('Different product accepted'); }
            catch(\UnexpectedValueException $e) { self::assertStringContainsString('ID',$e->getMessage()); }
        }
    }

    public function testCheckoutRejectsInvalidJsonAndQuantitiesBeforeQuery(): void
    {
        $service=$this->service();
        foreach ([['cart'=>['price'=>INF]],['cart'=>['callback'=>new \stdClass()]]] as $input) {
            try { $service->refreshCart($input);self::fail('Invalid JSON values accepted'); } catch (\InvalidArgumentException) { self::assertCount(0,$this->queries); }
        }
        try { $service->createOrder('5511888888888',[['id'=>'987','quantity'=>0]]);self::fail('Invalid quantity accepted'); } catch (\InvalidArgumentException) { self::assertCount(0,$this->queries); }
    }
}
