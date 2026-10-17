<?php
declare(strict_types=1);

namespace MVO\Tests;

use MVO\Protocol\BinaryNode;
use MVO\Service\{CatalogService, ServiceException, ServiceTransport};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CatalogCollectionsTest extends TestCase
{
    private array $queries = [];
    private array $responses = [];

    private function service(array $credentials = ['meJid'=>'5511999999999:3@s.whatsapp.net']): CatalogService
    {
        return new CatalogService(new ServiceTransport(function(BinaryNode $query, float $timeout):BinaryNode {
            $this->queries[] = $query;
            if ($this->responses === []) throw new \LogicException('Test response missing');
            return array_shift($this->responses);
        }, static function(BinaryNode $node):void {}, static fn():array=>$credentials));
    }

    private static function text(string $tag, string $text): BinaryNode { return new BinaryNode($tag, [], $text); }
    private static function iqResult(array $children = []): BinaryNode { return new BinaryNode('iq', ['type'=>'result'], $children); }
    private static function success(): BinaryNode { return self::iqResult([new BinaryNode('response', [], [new BinaryNode('success')])]); }
    private static function collection(string $id = 'COLL1', array $children = []): BinaryNode { return self::iqResult([new BinaryNode('collection', [], [self::text('id', $id), ...$children])]); }
    private function last(): BinaryNode { return $this->queries[array_key_last($this->queries)]; }

    public function testCategorySearchPreservesLocalizedHierarchyAndNotBusinessEntry(): void
    {
        $taxonomy = ['categories'=>[['id'=>'10','display_name'=>'Serviços','categories'=>[['id'=>'11','display_name'=>'Internet','categories'=>[['id'=>'12','display_name'=>'Hospedagem']]]]]], 'not_a_biz'=>['id'=>'0','display_name'=>'Não é uma empresa']];
        $this->responses[] = self::iqResult([new BinaryNode('result', [], json_encode(['data'=>['whatsapp_catkit_typeahead_proxy'=>$taxonomy]], JSON_THROW_ON_ERROR))]);
        self::assertSame($taxonomy, $this->service()->getBusinessCategories('Hospedagem'));
        self::assertSame('w:mex', $this->last()->attrs['xmlns']);
        self::assertSame('26869203922665622', $this->last()->child('query')->attrs['query_id']);
        $envelope = json_decode($this->last()->child('query')->content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['query_params'=>['query'=>'Hospedagem','locale'=>'pt_BR','operation'=>'PROFILE_TYPEAHEAD','version'=>'V_2']], $envelope['variables']);
    }

    public function testCategoryV1AllowsEmptySearchAndUsesItsOwnPersistedOperation(): void
    {
        $this->responses[] = self::iqResult([new BinaryNode('result', [], json_encode(['data'=>['whatsapp_catkit_typeahead_proxy'=>['categories'=>[],'not_a_biz'=>null]]], JSON_THROW_ON_ERROR))]);
        self::assertSame(['categories'=>[],'not_a_biz'=>null], $this->service()->getBusinessCategories('', 'en_US', false));
        self::assertSame('26266473919627648', $this->last()->child('query')->attrs['query_id']);
        $envelope = json_decode($this->last()->child('query')->content, true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('V_1', $envelope['variables']['query_params']['version']);
        self::assertSame('en_US', $envelope['variables']['query_params']['locale']);
    }

    #[DataProvider('invalidCategorySearches')]
    public function testInvalidCategorySearchesDoNotReachTransport(string $query, string $locale): void
    {
        try { $this->service()->getBusinessCategories($query, $locale); self::fail('Expected invalid category search'); }
        catch (\InvalidArgumentException) { self::assertSame([], $this->queries); }
    }

    public static function invalidCategorySearches(): array
    {
        return [[str_repeat('x',257),'pt_BR'],["\xff",'pt_BR'],['Serviços','pt-BR'],['Serviços','PT_br'],["bad\0query",'pt_BR']];
    }

    #[DataProvider('malformedCategories')]
    public function testMalformedCategoryResponsesDoNotInventTaxonomy(array $taxonomy): void
    {
        $this->responses[] = self::iqResult([new BinaryNode('result', [], json_encode(['data'=>['whatsapp_catkit_typeahead_proxy'=>$taxonomy]], JSON_THROW_ON_ERROR))]);
        $this->expectException(\UnexpectedValueException::class);
        $this->service()->getBusinessCategories('Serviços');
    }

    public static function malformedCategories(): array
    {
        return [[[]],[['categories'=>[['id'=>'1']]]],[['categories'=>[['id'=>'1','display_name'=>'Serviços','categories'=>'bad']]]],[['categories'=>[],'not_a_biz'=>['display_name'=>'bad']]]];
    }

    public function testCatalogCreationRequiresItsServerResponse(): void
    {
        $this->responses[] = self::iqResult([new BinaryNode('product_catalog_create')]);
        self::assertSame(['created'=>true], $this->service()->createCatalog());
        self::assertSame(['type'=>'set','to'=>'s.whatsapp.net','xmlns'=>'w:biz:catalog'], $this->last()->attrs);
        self::assertSame(['version'=>'1'], $this->last()->child('product_catalog_create')->attrs);
    }

    public function testCollectionCreateContainsNameMembershipAndCatalogSession(): void
    {
        $this->responses[] = self::collection('SERVER-COLLECTION', [new BinaryNode('status_info', [], [self::text('status','PENDING'), self::text('can_appeal','false')])]);
        $created = $this->service()->createCollection('Hospedagem', ['P1','P2','P1'], '123');
        self::assertSame('SERVER-COLLECTION', $created['id']);
        self::assertSame('PENDING', $created['reviewStatus']);
        self::assertSame(['type'=>'set','to'=>'s.whatsapp.net','xmlns'=>'w:biz:catalog','smax_id'=>'36'], $this->last()->attrs);
        $request = $this->last()->child('collection');
        self::assertSame(['op'=>'create'], $request->attrs);
        self::assertSame('Hospedagem', $request->child('name')->content);
        self::assertSame(['P1','P2'], array_map(static fn(BinaryNode $node)=>$node->child('id')->content, ServiceTransport::children($request, 'product')));
        self::assertSame('123', $request->child('catalog_session_id')->content);
    }

    public function testMissingModerationStatusIsNotInvented(): void
    {
        $this->responses[] = self::collection();
        $created = $this->service()->createCollection('Coleção sem itens');
        self::assertSame(['id'=>'COLL1'], $created);
        self::assertMatchesRegularExpression('/^[0-9]{1,10}$/D', $this->last()->child('collection')->child('catalog_session_id')->content);
        self::assertLessThanOrEqual(2147483647, (int)$this->last()->child('collection')->child('catalog_session_id')->content);
    }

    public function testCollectionUpdateRenamesAndChangesOnlyExplicitMembership(): void
    {
        $this->responses[] = self::collection('COLL1');
        $this->service()->updateCollection('COLL1', ['name'=>'Promoções','productsToAdd'=>['P2','P2'],'productsToRemove'=>['P1'],'catalogSessionId'=>'456']);
        self::assertSame('40', $this->last()->attrs['smax_id']);
        $request = $this->last()->child('collection');
        self::assertSame(['op'=>'update'], $request->attrs);
        self::assertSame('COLL1', $request->child('id')->content);
        self::assertSame('Promoções', $request->child('name')->content);
        self::assertSame(['P2'], array_map(static fn(BinaryNode $node)=>$node->content, $request->child('add')->content));
        self::assertSame(['P1'], array_map(static fn(BinaryNode $node)=>$node->content, $request->child('remove')->content));
        self::assertSame('456', $request->child('catalog_session_id')->content);
    }

    public function testRenameDoesNotReplaceProductMembership(): void
    {
        $this->responses[] = self::collection();
        $this->service()->updateCollection('COLL1', ['name'=>'Novo nome']);
        self::assertNull($this->last()->child('collection')->child('add'));
        self::assertNull($this->last()->child('collection')->child('remove'));
        self::assertNull($this->last()->child('collection')->child('product'));
    }

    #[DataProvider('invalidUpdates')]
    public function testInvalidCollectionUpdatesDoNotReachTransport(array $input): void
    {
        try { $this->service()->updateCollection('COLL1', $input); self::fail('Expected invalid collection update'); }
        catch (\InvalidArgumentException) { self::assertSame([], $this->queries); }
    }

    public static function invalidUpdates(): array
    {
        return [
            'empty'=>[[]], 'blank name'=>[['name'=>' ']], 'numeric name'=>[['name'=>1]],
            'membership replacement'=>[['products'=>['P1']]], 'conflicting membership'=>[['productsToAdd'=>['P1'],'productsToRemove'=>['P1']]],
            'non-list products'=>[['productsToAdd'=>['id'=>'P1']]], 'non-string product'=>[['productsToAdd'=>[1]]],
            'empty changes'=>[['productsToAdd'=>[],'productsToRemove'=>[]]], 'unknown visibility'=>[['isHidden'=>true]],
            'invalid session'=>[['name'=>'ok','catalogSessionId'=>2]],
        ];
    }

    public function testDeleteRequiresPerCollectionAcknowledgementAndReportsPartialRefusals(): void
    {
        $this->responses = [self::success(), new BinaryNode('iq', ['type'=>'error'], [new BinaryNode('error', ['code'=>'403','text'=>'not-authorized'])]), self::success()];
        $result = $this->service()->deleteCollections(['COLL1','COLL2','COLL3','COLL1'], '789');
        self::assertSame(2, $result['deleted']);
        self::assertSame(['COLL1','COLL3'], $result['ids']);
        self::assertSame(['COLL1','COLL2','COLL3'], array_column($result['results'], 'id'));
        self::assertFalse($result['results'][1]['deleted']);
        self::assertSame('403', $result['results'][1]['error']['code']);
        self::assertCount(3, $this->queries);
        foreach ($this->queries as $index=>$query) {
            self::assertSame('43', $query->attrs['smax_id']);
            self::assertSame(['op'=>'delete'], $query->child('collections')->attrs);
            self::assertSame('COLL'.($index + 1), $query->child('collections')->child('id')->content);
            self::assertSame('789', $query->child('collections')->child('catalog_session_id')->content);
        }
    }

    public function testSingleDeletePreservesServerRefusalCode(): void
    {
        $this->responses[] = new BinaryNode('iq', ['type'=>'error'], [new BinaryNode('error', ['code'=>'451','text'=>'restricted'])]);
        try { $this->service()->deleteCollection('COLL1'); self::fail('Expected server refusal'); }
        catch (ServiceException $error) { self::assertSame('451', $error->serverCode); self::assertSame('catalog.collectionDelete', $error->operation); }
    }

    public function testAppealUsesCollectionAttributeAndReturnsOnlyServerSuccess(): void
    {
        $this->responses[] = self::success();
        self::assertSame(['success'=>true], $this->service()->appealCollection('COLL1', 'Produtos permitidos'));
        self::assertSame('48', $this->last()->attrs['smax_id']);
        self::assertSame(['op'=>'appeal','id'=>'COLL1'], $this->last()->child('collection')->attrs);
        self::assertSame('Produtos permitidos', $this->last()->child('collection')->child('reason')->content);
    }

    public function testReorderUsesExplicitOriginalIndexesAndPreservesMoveOrder(): void
    {
        $this->responses[] = self::success();
        $result = $this->service()->reorderCollections([['id'=>'COLL3','fromIndex'=>2,'toIndex'=>0],['id'=>'COLL1','fromIndex'=>1,'toIndex'=>2]]);
        self::assertSame(['success'=>true], $result);
        self::assertSame('92', $this->last()->attrs['smax_id']);
        $request = $this->last()->child('collections');
        self::assertSame(['op'=>'update'], $request->attrs);
        self::assertSame(['COLL3','COLL1'], array_map(static fn(BinaryNode $node)=>$node->content, $request->child('move')->content));
        self::assertSame(['from_index'=>'2','to_index'=>'0'], $request->child('move')->content[0]->attrs);
    }

    #[DataProvider('invalidMoves')]
    public function testInvalidMovesDoNotReachTransport(array $input): void
    {
        try { $this->service()->reorderCollections($input); self::fail('Expected invalid collection move'); }
        catch (\InvalidArgumentException) { self::assertSame([], $this->queries); }
    }

    public static function invalidMoves(): array
    {
        return [
            'no moves'=>[[]], 'ids only'=>[['COLL1','COLL2']],
            'negative index'=>[[['id'=>'COLL1','fromIndex'=>-1,'toIndex'=>0]]],
            'numeric string'=>[[['id'=>'COLL1','fromIndex'=>'1','toIndex'=>0]]],
            'duplicate id'=>[[['id'=>'COLL1','fromIndex'=>1,'toIndex'=>0],['id'=>'COLL1','fromIndex'=>0,'toIndex'=>1]]],
        ];
    }

    #[DataProvider('cartValues')]
    public function testCartSettingUsesCommerceIqAndReportsActualReturnedState(bool $requested, string $returned): void
    {
        $this->responses[] = self::iqResult([new BinaryNode('commerce_settings', [], [new BinaryNode('cart', ['enabled'=>$returned])])]);
        self::assertSame(['cartEnabled'=>$returned === 'true'], $this->service()->setCartEnabled($requested));
        self::assertSame(['type'=>'set','to'=>'s.whatsapp.net','xmlns'=>'fb:thrift_iq','smax_id'=>'25'], $this->last()->attrs);
        self::assertSame(['enabled'=>$requested ? 'true' : 'false'], $this->last()->child('commerce_settings')->child('cart')->attrs);
    }

    public static function cartValues(): array { return [[true,'true'],[false,'false'],[true,'false']]; }

    public function testCartReadUsesServerBusinessProfileAndOwnCanonicalAccount(): void
    {
        $this->responses[] = self::iqResult([new BinaryNode('business_profile', [], [new BinaryNode('profile', ['jid'=>'5511999999999@s.whatsapp.net'], [new BinaryNode('profile_options', [], [self::text('cart_enabled','false'),self::text('commerce_experience','CATALOG')])])])]);
        self::assertSame(['cartEnabled'=>false,'commerceExperience'=>'CATALOG'], $this->service()->getCartSettings());
        self::assertSame('w:biz', $this->last()->attrs['xmlns']);
        self::assertSame(['v'=>'116'], $this->last()->child('business_profile')->attrs);
        self::assertSame(['jid'=>'5511999999999@s.whatsapp.net'], $this->last()->child('business_profile')->child('profile')->attrs);
    }

    #[DataProvider('malformedResponses')]
    public function testMalformedResponsesNeverInventSuccess(string $method, array $arguments, BinaryNode $response): void
    {
        $this->responses[] = $response;
        $this->expectException(\UnexpectedValueException::class);
        $this->service()->{$method}(...$arguments);
    }

    public static function malformedResponses(): array
    {
        return [
            'catalog empty ack'=>['createCatalog',[],self::iqResult()],
            'create missing id'=>['createCollection',['Plano',[]],self::iqResult([new BinaryNode('collection')])],
            'update wrong id'=>['updateCollection',['COLL1',['name'=>'Plano']],self::collection('COLL2')],
            'delete empty ack'=>['deleteCollection',['COLL1'],self::iqResult()],
            'appeal empty response'=>['appealCollection',['COLL1'],self::iqResult([new BinaryNode('response')])],
            'reorder empty ack'=>['reorderCollections',[[['id'=>'COLL1','fromIndex'=>1,'toIndex'=>0]]],self::iqResult()],
            'cart missing setting'=>['setCartEnabled',[true],self::iqResult([new BinaryNode('commerce_settings',[],[new BinaryNode('cart')])])],
            'cart invalid setting'=>['setCartEnabled',[true],self::iqResult([new BinaryNode('commerce_settings',[],[new BinaryNode('cart',['enabled'=>'1'])])])],
            'cart read another account'=>['getCartSettings',[],self::iqResult([new BinaryNode('business_profile',[],[new BinaryNode('profile',['jid'=>'other@s.whatsapp.net'])])])],
            'cart read missing setting'=>['getCartSettings',[],self::iqResult([new BinaryNode('business_profile',[],[new BinaryNode('profile',['jid'=>'5511999999999@s.whatsapp.net'],[new BinaryNode('profile_options')])])])],
        ];
    }
}
