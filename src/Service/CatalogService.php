<?php
declare(strict_types=1);

namespace MVO\Service;

use MVO\Protocol\BinaryNode;

/** WhatsApp Business catalog IQs. Product images use the plaintext catalog upload. */
final class CatalogService
{
    use CatalogCollections;
    /** @var \Closure(string|array<string,mixed>):string|null */
    private ?\Closure $uploadImage;

    /**
     * @param callable(string|array<string,mixed>):string|null $uploadImage */
    public function __construct(private readonly ServiceTransport $transport, ?callable $uploadImage = null)
    {
        $this->uploadImage = $uploadImage === null ? null : \Closure::fromCallable($uploadImage);
    }

    /**
     * @return array{products:list<array<string,mixed>>,nextPageCursor:?string} */
    public function getCatalog(?string $jid = null, int $limit = 10, ?string $cursor = null): array
    {
        $children = $this->dimensions($limit);
        if ($cursor !== null) $children[] = new BinaryNode('after', [], self::textInput($cursor, 'cursor', 4096));
        $result = $this->query('catalog.get', 'get', new BinaryNode('product_catalog', ['jid'=>$this->owner($jid), 'allow_shop_source'=>'true'], $children));
        $catalog = self::required($result, 'product_catalog', 'catalog.get');
        return ['products'=>array_map(self::parseProduct(...), ServiceTransport::children($catalog, 'product')), 'nextPageCursor'=>self::childText($catalog->child('paging'), 'after')];
    }

    /**
     * @return array{collections:list<array<string,mixed>>,nextPageCursor:?string} */
    public function getCollections(?string $jid = null, int $limit = 51, ?string $cursor = null): array
    {
        self::limit($limit);
        $children = [new BinaryNode('collection_limit', [], (string)$limit), new BinaryNode('item_limit', [], (string)$limit), ...$this->imageDimensions()];
        if ($cursor !== null) $children[] = new BinaryNode('after', [], self::textInput($cursor, 'cursor', 4096));
        $result = $this->query('catalog.collections', 'get', new BinaryNode('collections', ['biz_jid'=>$this->owner($jid)], $children), ['smax_id'=>'35']);
        $collections = self::required($result, 'collections', 'catalog.collections');
        return ['collections'=>array_map(self::parseCollection(...), ServiceTransport::children($collections, 'collection')), 'nextPageCursor'=>self::childText($collections->child('paging'), 'after')];
    }

    /**
     * @param array<string,mixed> $product
     * @return array<string,mixed> */
    public function createProduct(array $product): array
    {
        $product = self::aliases($product);
        foreach (['name','description','price','currency','images'] as $field) if (!array_key_exists($field, $product)) throw new \InvalidArgumentException('Missing product '.$field);
        if (!array_key_exists('isHidden', $product)) $product['isHidden'] = false;
        return $this->mutateProduct('product_catalog_add', 'catalog.productCreate', null, $product);
    }

    /**
     * @param array<string,mixed> $product
     * @return array<string,mixed> */
    public function productCreate(array $product): array { return $this->createProduct($product); }

    /**
     * @param array<string,mixed> $update
     * @return array<string,mixed> */
    public function updateProduct(string $productId, array $update): array
    {
        self::id($productId);
        if ($update === []) throw new \InvalidArgumentException('Empty product update');
        return $this->mutateProduct('product_catalog_edit', 'catalog.productUpdate', $productId, self::aliases($update));
    }

    /**
     * @param array<string,mixed> $update
     * @return array<string,mixed> */
    public function productUpdate(string $productId, array $update): array { return $this->updateProduct($productId, $update); }

    /**
     * @param list<string> $productIds
     * @return array{deleted:int} */
    public function deleteProducts(array $productIds): array
    {
        $ids = self::ids($productIds);
        $products = array_map(static fn(string $id):BinaryNode=>new BinaryNode('product', [], [new BinaryNode('id', [], $id)]), $ids);
        $result = $this->query('catalog.productDelete', 'set', new BinaryNode('product_catalog_delete', ['v'=>'1'], $products));
        $deleted = self::required($result, 'product_catalog_delete', 'catalog.productDelete');
        $count = $deleted->attrs['deleted_count'] ?? null;
        if ($count === null || !ctype_digit($count) || (int)$count > count($ids)) throw new \UnexpectedValueException('Catalog response has no valid deleted count');
        return ['deleted'=>(int)$count];
    }

    /**
     * @param list<string> $productIds
     * @return array{deleted:int} */
    public function productDelete(array $productIds): array { return $this->deleteProducts($productIds); }

    /**
     * @return array<string,mixed> */
    public function getProduct(string $productId, ?string $jid = null): array
    {
        self::id($productId);
        $data = $this->mex('QueryCatalogProduct', ['request'=>['product'=>['jid'=>$this->owner($jid), 'product_id'=>$productId, 'width'=>'100', 'height'=>'100', 'fetch_compliance_info'=>'true']]]);
        $product = self::parseJsonProduct(self::objectAt($data, ['xwa_product_catalog_get_product','product_catalog','product']));
        if ($product['id'] !== $productId) throw new \UnexpectedValueException('Catalog returned a different product ID');
        return $product;
    }

    /**
     * @param list<string> $productIds
     * @return array{products:list<array<string,mixed>>} */
    public function getProducts(array $productIds, ?string $jid = null): array
    {
        $ids = self::ids($productIds);
        $data = $this->mex('QueryProductListCatalog', ['request'=>['product_list'=>['jid'=>$this->owner($jid), 'products'=>array_map(static fn(string $id):array=>['id'=>$id], $ids), 'width'=>'100', 'height'=>'100']]]);
        $list = self::objectAt($data, ['xwa_product_catalog_get_product_list','product_list']);
        $products = array_map(self::parseJsonProduct(...), self::objectList($list['products'] ?? null, 'product list'));
        $returned = [];
        foreach ($products as $product) {
            if (!in_array($product['id'], $ids, true) || in_array($product['id'], $returned, true)) throw new \UnexpectedValueException('Catalog returned unexpected product IDs');
            $returned[] = $product['id'];
        }
        return ['products'=>$products];
    }

    /**
     * @return array<string,mixed> */
    public function getCollection(string $collectionId, ?string $jid = null, int $limit = 10, ?string $cursor = null): array
    {
        self::id($collectionId);
        self::limit($limit);
        $request = ['biz_jid'=>$this->owner($jid), 'id'=>$collectionId, 'limit'=>(string)$limit, 'width'=>'100', 'height'=>'100'];
        if ($cursor !== null) $request['after'] = self::textInput($cursor, 'cursor', 4096);
        $data = $this->mex('QueryProductSingleCollection', ['request'=>['collection'=>$request]]);
        $wrapper = self::objectAt($data, ['xwa_product_catalog_get_single_collection']);
        $collection = self::objectAt($wrapper, ['collection']);
        $id = $collection['id'] ?? null;
        $name = $collection['name'] ?? null;
        if (!is_string($id) || !is_string($name)) throw new \UnexpectedValueException('Collection response has no id or name');
        self::id($id);
        if ($id !== $collectionId) throw new \UnexpectedValueException('Catalog returned a different collection ID');
        $out = ['id'=>$id, 'name'=>$name, 'products'=>array_map(self::parseJsonProduct(...), self::objectList($collection['products'] ?? null, 'collection products')), 'nextPageCursor'=>$wrapper['paging']['after'] ?? null];
        if (isset($collection['is_hidden'])) {
            if (!is_bool($collection['is_hidden'])) throw new \UnexpectedValueException('Collection visibility must be boolean');
            $out['isHidden'] = $collection['is_hidden'];
        }
        if (isset($collection['status_info'])) $out['status'] = self::jsonStatus(self::objectAt($collection, ['status_info']));
        return $out;
    }

    /** Places an order against a business catalog, using the server's checkout schema.
     *
     * @param list<array<string,mixed>> $products
     * @return array<string,mixed> */
    public function createOrder(string $jid, array $products): array
    {
        if ($products === [] || count($products) > 100) throw new \InvalidArgumentException('Order requires one to one hundred products');
        self::objectList($products, 'order products');
        foreach ($products as $product) {
            if (!isset($product['id']) || !is_string($product['id'])) throw new \InvalidArgumentException('Order product ID required');
            self::id($product['id']);
            if (isset($product['quantity']) && (!is_int($product['quantity']) && !is_string($product['quantity']))) throw new \InvalidArgumentException('Order quantity must be integer');
            if (isset($product['quantity']) && (preg_match('/^[1-9][0-9]{0,5}$/D', (string)$product['quantity']) !== 1)) throw new \InvalidArgumentException('Invalid order quantity');
        }
        self::safeRequest($products);
        $data = $this->mex('BizCreateOrder', ['input'=>['order'=>['jid'=>$this->owner($jid), 'products'=>$products]]]);
        $order = self::objectAt($data, ['xwa_checkout_place_order','order']);
        if (!isset($order['order_id']) || !is_string($order['order_id']) || $order['order_id'] === '') throw new \UnexpectedValueException('Created order response has no order ID');
        return $order;
    }

    /**
     * @return array<string,mixed> */
    public function getOrder(string $orderId, string $token, ?string $jid = null): array
    {
        self::id($orderId);
        self::textInput($token, 'order token', 16384);
        $data = $this->mex('BizQueryOrder', ['request'=>['order'=>['jid'=>$this->owner($jid), 'token'=>['sensitive_string_value'=>$token], 'id'=>$orderId, 'image_dimensions'=>['height'=>100,'width'=>100]]]]);
        return self::objectAt($data, ['xwa_checkout_get_order_info','order']);
    }

    /** The pinned checkout request is an extensible record, unlike cart visibility settings.
     *
     * @param array<string,mixed> $request
     * @return array<string,mixed> */
    public function refreshCart(array $request): array
    {
        if ($request === [] || array_is_list($request)) throw new \InvalidArgumentException('Cart refresh request must be an object');
        self::safeRequest($request);
        return self::objectAt($this->mex('BizGraphQLRefreshCart', ['request'=>$request]), ['xwa_checkout_refresh_cart','cart']);
    }

    /**
     * @return array<string,mixed> */
    public function getOrderDetails(string $orderId, string $tokenBase64): array
    {
        self::id($orderId);
        if ($tokenBase64 === '' || strlen($tokenBase64) > 16384 || base64_decode($tokenBase64, true) === false) throw new \InvalidArgumentException('Order token must be base64');
        $result = $this->transport->iq('catalog.orderDetails', 'get', 's.whatsapp.net', 'fb:thrift_iq', [new BinaryNode('order', ['op'=>'get','id'=>$orderId], [new BinaryNode('image_dimensions', [], $this->imageDimensions()), new BinaryNode('token', [], $tokenBase64)])], ['smax_id'=>'5']);
        $order = self::required($result, 'order', 'catalog.orderDetails');
        $products = [];
        foreach (ServiceTransport::children($order, 'product') as $node) {
            $products[] = ['id'=>self::requiredText($node, 'id'), 'name'=>self::requiredText($node, 'name'), 'imageUrl'=>self::childText($node->child('image'), 'url'), 'price'=>self::number(self::requiredText($node, 'price')), 'currency'=>self::requiredText($node, 'currency'), 'quantity'=>self::number(self::requiredText($node, 'quantity'))];
        }
        $price = self::required($order, 'price', 'catalog.orderDetails');
        return ['price'=>['total'=>self::number(self::requiredText($price, 'total')), 'currency'=>self::requiredText($price, 'currency')], 'products'=>$products];
    }

    /**
     * @return array<string,mixed> */
    public static function parseProduct(BinaryNode $node): array
    {
        self::assertNode($node, 'catalog.product');
        $id = self::requiredText($node, 'id');
        self::id($id);
        $product = ['id'=>$id, 'name'=>self::requiredText($node, 'name'), 'description'=>self::childText($node, 'description') ?? '', 'isHidden'=>($node->attrs['is_hidden'] ?? 'false') === 'true'];
        foreach (['retailer_id'=>'retailerId','url'=>'url','currency'=>'currency','availability'=>'availability'] as $wire=>$field) {
            $value = self::childText($node, $wire);
            if ($value !== null) $product[$field] = $value;
        }
        $price = self::childText($node, 'price');
        if ($price !== null) $product['price'] = self::number($price);
        $sale = $node->child('sale_price');
        $salePrice = self::childText($sale, 'price') ?? ServiceTransport::text($sale);
        if ($salePrice !== null) $product['salePrice'] = self::number($salePrice);
        $status = $node->child('status_info');
        if ($status !== null) $product['reviewStatus'] = ['whatsapp'=>self::childText($status, 'status'), 'canAppeal'=>self::childText($status, 'can_appeal') === 'true'];
        $images = [];
        foreach (ServiceTransport::children($node->child('media'), 'image') as $image) {
            $row = [];
            foreach (['request_image_url'=>'requested','original_image_url'=>'original','url'=>'url'] as $wire=>$field) {
                $url = self::childText($image, $wire);
                if ($url !== null) $row[$field] = $url;
            }
            $images[] = $row;
        }
        $product['images'] = $images;
        $product['imageUrls'] = $images[0] ?? [];
        $origin = self::childText($node->child('compliance_info'), 'country_code_origin');
        if ($origin !== null) $product['originCountryCode'] = $origin;
        if (($node->attrs['compliance_category'] ?? '') === 'COUNTRY_ORIGIN_EXEMPT') $product['originCountryCode'] = null;
        return $product;
    }

    /**
     * @return array<string,mixed> */
    public static function parseCollection(BinaryNode $node): array
    {
        self::assertNode($node, 'catalog.collection');
        $collection = ['id'=>self::requiredText($node, 'id'), 'name'=>self::requiredText($node, 'name'), 'products'=>array_map(self::parseProduct(...), ServiceTransport::children($node, 'product'))];
        self::id($collection['id']);
        $hidden = $node->attrs['is_hidden'] ?? self::childText($node, 'is_hidden');
        if ($hidden !== null) {
            if (!in_array(strtolower($hidden), ['true','false'], true)) throw new \UnexpectedValueException('Invalid collection visibility');
            $collection['isHidden'] = strtolower($hidden) === 'true';
        }
        $status = $node->child('status_info');
        if ($status !== null) {
            self::assertNode($status, 'catalog.collection');
            $collection['status'] = ['status'=>self::childText($status, 'status'), 'canAppeal'=>self::childText($status, 'can_appeal') === 'true'];
            foreach (['reject_reason'=>'rejectReason','commerce_url'=>'commerceUrl'] as $tag=>$field) {
                $text = self::childText($status, $tag);
                if ($text !== null) $collection['status'][$field] = $text;
            }
        }
        return $collection;
    }

    public static function isUploadedImageUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port'] !== 443)) return false;
        $host = strtolower($parts['host'] ?? '');
        return $host !== '' && str_ends_with($host, '.whatsapp.net') && (string)($parts['path'] ?? '') !== '' && preg_match('/[\x00-\x20\x7f]/', $url) !== 1;
    }

    /**
     * @param array<string,mixed> $product
     * @return array<string,mixed> */
    private function mutateProduct(string $tag, string $operation, ?string $id, array $product): array
    {
        $node = $this->productNode($id, $product);
        $result = $this->query($operation, 'set', new BinaryNode($tag, ['v'=>'1'], [$node, ...$this->imageDimensions()]));
        $resultProduct = self::parseProduct(self::required(self::required($result, $tag, $operation), 'product', $operation));
        if ($id !== null && $resultProduct['id'] !== $id) throw new \UnexpectedValueException('Updated product response has a different ID');
        return $resultProduct;
    }

    /**
     * @param array<string,mixed> $product */
    private function productNode(?string $id, array $product): BinaryNode
    {
        $allowed = ['name','description','retailerId','salePrice','url','price','currency','images','originCountryCode','isHidden'];
        foreach (array_keys($product) as $key) if (!in_array($key, $allowed, true)) throw new \InvalidArgumentException('Unknown product field '.$key);
        $attrs = [];
        $children = $id === null ? [] : [new BinaryNode('id', [], self::id($id))];
        foreach (['name'=>'name','description'=>'description','retailerId'=>'retailer_id','salePrice'=>'sale_price','url'=>'url','price'=>'price','currency'=>'currency'] as $field=>$tag) {
            if (!array_key_exists($field, $product)) continue;
            $value = $product[$field];
            if (in_array($field, ['price','salePrice'], true)) $value = self::money($value, $field);
            else {
                if (!is_string($value)) throw new \InvalidArgumentException('Product '.$field.' must be a string');
                $value = self::textInput($value, $field, $field === 'description' ? 10000 : 2048, in_array($field, ['description','retailerId','url'], true));
                if ($field === 'currency' && preg_match('/^[A-Z]{3}$/D', $value) !== 1) throw new \InvalidArgumentException('Product currency must be an ISO currency code');
                if ($field === 'url' && $value !== '' && !self::httpUrl($value)) throw new \InvalidArgumentException('Product URL must use HTTP or HTTPS');
            }
            $children[] = new BinaryNode($tag, [], $value);
        }
        if (array_key_exists('originCountryCode', $product)) {
            if ($product['originCountryCode'] === null) $attrs['compliance_category'] = 'COUNTRY_ORIGIN_EXEMPT';
            elseif (!is_string($product['originCountryCode']) || preg_match('/^[A-Z]{2}$/D', $product['originCountryCode']) !== 1) throw new \InvalidArgumentException('Invalid product origin country');
            else $children[] = new BinaryNode('compliance_info', [], [new BinaryNode('country_code_origin', [], $product['originCountryCode'])]);
        }
        if (array_key_exists('isHidden', $product)) {
            if (!is_bool($product['isHidden'])) throw new \InvalidArgumentException('Product visibility must be boolean');
            $attrs['is_hidden'] = $product['isHidden'] ? 'true' : 'false';
        }
        if (array_key_exists('images', $product)) {
            $images = $product['images'];
            if (!is_array($images) || !array_is_list($images) || count($images) > 10 || $images === []) throw new \InvalidArgumentException('Product images must contain one to ten images');
            foreach ($images as $image) if (!is_string($image) && !is_array($image)) throw new \InvalidArgumentException('Invalid product image');
            $media = [];
            foreach ($images as $image) {
                    $url = is_array($image) && isset($image['url']) && is_string($image['url']) ? $image['url'] : (is_string($image) && self::isUploadedImageUrl($image) ? $image : null);
                    if ($url === null || !self::isUploadedImageUrl($url)) {
                        if ($this->uploadImage === null) throw new \LogicException('Catalog image uploader is not configured');
                        $url = ($this->uploadImage)($image);
                    }
                    if (!self::isUploadedImageUrl($url)) throw new \UnexpectedValueException('Catalog image uploader returned an invalid WhatsApp URL');
                    $media[] = new BinaryNode('image', [], [new BinaryNode('url', [], $url)]);
            }
            $children[] = new BinaryNode('media', [], $media);
        }
        return new BinaryNode('product', $attrs, $children);
    }

    /**
     * @param array<string,mixed> $product
     * @return array<string,mixed> */
    private static function aliases(array $product): array
    {
        foreach (['priceAmount1000'=>'price','currencyCode'=>'currency'] as $alias=>$field) if (array_key_exists($alias, $product)) {
            if (array_key_exists($field, $product) && $product[$field] !== $product[$alias]) throw new \InvalidArgumentException('Conflicting product '.$field);
            $product[$field] = $product[$alias];
            unset($product[$alias]);
        }
        return $product;
    }

    private function owner(?string $jid): string
    {
        if ($jid === null) {
            $creds = $this->transport->credentials();
            $jid = $creds['meJid'] ?? $creds['me']['id'] ?? $creds['meId'] ?? null;
            if (!is_string($jid) || $jid === '') throw new \LogicException('Connected account JID required for catalog');
        }
        return ServiceTransport::user(\MVO\Protocol\Jid::recipient($jid)->canonical()->bare());
    }

    /**
     * @param array<string,string> $attrs */
    private function query(string $operation, string $type, BinaryNode $node, array $attrs = []): BinaryNode
    {
        return $this->transport->iq($operation, $type, 's.whatsapp.net', 'w:biz:catalog', [$node], $attrs);
    }

    /**
     * @param array<string,mixed> $variables
     * @return array<string,mixed> */
    private function mex(string $operation, array $variables): array
    {
        $data = (new MexService($this->transport))->run($operation, $variables);
        if ($data === null) throw new \UnexpectedValueException('Catalog MEX response has no data');
        return $data;
    }

    /**
     * @param array<string,mixed> $data
     * @param list<string> $path
     * @return array<string,mixed> */
    private static function objectAt(array $data, array $path): array
    {
        foreach ($path as $field) {
            if (!isset($data[$field]) || !is_array($data[$field]) || ($data[$field] !== [] && array_is_list($data[$field]))) throw new \UnexpectedValueException('Catalog MEX response missing '.$field);
            $data = $data[$field];
        }
        return $data;
    }

    /**
     * @return list<array<string,mixed>> */
    private static function objectList(mixed $value, string $field): array
    {
        if (!is_array($value) || !array_is_list($value)) throw new \UnexpectedValueException('Catalog response missing '.$field);
        foreach ($value as $row) if (!is_array($row) || ($row !== [] && array_is_list($row))) throw new \UnexpectedValueException('Invalid catalog '.$field);
        return $value;
    }

    /**
     * @param array<string,mixed> $product
     * @return array<string,mixed> */
    private static function parseJsonProduct(array $product): array
    {
        if (!isset($product['id'],$product['name']) || !is_string($product['id']) || !is_string($product['name'])) throw new \UnexpectedValueException('Catalog MEX product has no ID or name');
        self::id($product['id']);
        $out = ['id'=>$product['id'], 'name'=>$product['name'], 'description'=>$product['description'] ?? '', 'isHidden'=>($product['is_hidden'] ?? false) === true];
        foreach (['retailer_id'=>'retailerId','url'=>'url','shimmed_url'=>'shimmedUrl','currency'=>'currency','product_availability'=>'availability','max_available'=>'maxAvailable','is_sanctioned'=>'isSanctioned','belongs_to'=>'belongsTo','variant_info'=>'variantInfo','compliance_info'=>'complianceInfo'] as $wire=>$field) if (isset($product[$wire])) $out[$field] = $product[$wire];
        if (isset($product['price'])) {
            if (!is_string($product['price'])) throw new \UnexpectedValueException('Catalog MEX price must be string');
            $out['price'] = self::number($product['price']);
        }
        if (isset($product['sale_price'])) {
            $sale = self::objectAt($product, ['sale_price']);
            if (isset($sale['price'])) {
                if (!is_string($sale['price'])) throw new \UnexpectedValueException('Catalog MEX sale price must be string');
                $out['salePrice'] = self::number($sale['price']);
            }
            if (isset($sale['start_date'])) $out['saleStartDate'] = $sale['start_date'];
            if (isset($sale['end_date'])) $out['saleEndDate'] = $sale['end_date'];
        }
        if (isset($product['status_info'])) $out['reviewStatus'] = self::jsonStatus(self::objectAt($product, ['status_info']));
        $images = [];
        $media = isset($product['media']) ? self::objectAt($product, ['media']) : [];
        foreach (self::objectList($media['images'] ?? [], 'product images') as $image) {
            $row = [];
            foreach (['id'=>'id','request_image_url'=>'requested','original_image_url'=>'original'] as $wire=>$field) if (isset($image[$wire])) $row[$field] = $image[$wire];
            $images[] = $row;
        }
        $out['images'] = $images;
        $out['imageUrls'] = $images[0] ?? [];
        if (isset($media['videos'])) $out['videos'] = self::objectList($media['videos'], 'product videos');
        if (isset($product['compliance_info']['country_code_origin'])) $out['originCountryCode'] = $product['compliance_info']['country_code_origin'];
        if (($product['compliance_category'] ?? '') === 'COUNTRY_ORIGIN_EXEMPT') $out['originCountryCode'] = null;
        return $out;
    }

    /**
     * @param array<string,mixed> $status
     * @return array<string,mixed> */
    private static function jsonStatus(array $status): array
    {
        $out = ['status'=>$status['status'] ?? null, 'canAppeal'=>in_array($status['can_appeal'] ?? false, [true,'true'], true)];
        foreach (['reject_reason'=>'rejectReason','commerce_url'=>'commerceUrl'] as $wire=>$field) if (isset($status[$wire])) $out[$field] = $status[$wire];
        return $out;
    }

    /**
     * @param array<mixed> $request */
    private static function safeRequest(array $request): void
    {
        try { $json = json_encode($request, JSON_THROW_ON_ERROR, 20); }
        catch (\JsonException $e) { throw new \InvalidArgumentException('Checkout request must contain finite JSON values', 0, $e); }
        if (strlen($json) > 262144) throw new \LengthException('Checkout request too large');
        array_walk_recursive($request, static function(mixed $value):void { if (is_object($value) || is_resource($value)) throw new \InvalidArgumentException('Checkout request must contain JSON values'); });
    }

    /**
     * @return list<BinaryNode> */
    private function dimensions(int $limit): array { self::limit($limit); return [new BinaryNode('limit', [], (string)$limit), ...$this->imageDimensions()]; }
    /**
     * @return list<BinaryNode> */
    private function imageDimensions(): array { return [new BinaryNode('width', [], '100'), new BinaryNode('height', [], '100')]; }
    private static function limit(int $limit): void { if ($limit < 1 || $limit > 1000) throw new \InvalidArgumentException('Catalog page limit must be between 1 and 1000'); }
    private static function id(string $id): string { return self::textInput($id, 'id', 256); }
    /**
     * @param list<string> $ids
     * @return list<string> */
    private static function ids(array $ids): array
    {
        if (!array_is_list($ids) || $ids === [] || count($ids) > 100) throw new \InvalidArgumentException('One to one hundred product IDs required');
        foreach ($ids as $id) { if (!is_string($id)) throw new \InvalidArgumentException('Product ID must be string'); self::id($id); }
        return array_values(array_unique($ids));
    }
    private static function textInput(string $value, string $field, int $limit, bool $allowEmpty = false): string
    {
        if ((!$allowEmpty && trim($value) === '') || strlen($value) > $limit || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) throw new \InvalidArgumentException('Invalid catalog '.$field);
        return $value;
    }
    private static function money(mixed $value, string $field): string
    {
        if (is_int($value) && $value >= 0) return (string)$value;
        if (is_string($value) && preg_match('/^(0|[1-9][0-9]{0,18})$/D', $value) === 1) return $value;
        throw new \InvalidArgumentException('Product '.$field.' must be a nonnegative integer in catalog units');
    }
    private static function number(string $value): int|string
    {
        $valid = self::money($value, 'response amount');
        if (strlen($valid) < strlen((string)PHP_INT_MAX) || (strlen($valid) === strlen((string)PHP_INT_MAX) && strcmp($valid, (string)PHP_INT_MAX) <= 0)) return (int)$valid;
        return $valid;
    }
    private static function httpUrl(string $value): bool
    {
        $parts = parse_url($value);
        return is_array($parts) && in_array($parts['scheme'] ?? '', ['https','http'], true) && isset($parts['host']) && !isset($parts['user']) && !isset($parts['pass']) && preg_match('/[\x00-\x20\x7f]/', $value) !== 1;
    }
    private static function childText(?BinaryNode $parent, string $tag): ?string { return ServiceTransport::text($parent?->child($tag)); }
    private static function requiredText(BinaryNode $parent, string $tag): string
    {
        $value = self::childText($parent, $tag);
        if ($value === null || $value === '') throw new \UnexpectedValueException('Catalog response missing '.$tag);
        return $value;
    }
    private static function required(BinaryNode $parent, string $tag, string $operation): BinaryNode
    {
        self::assertNode($parent, $operation);
        $node = $parent->child($tag);
        if ($node === null) throw new \UnexpectedValueException('Catalog response missing '.$tag);
        self::assertNode($node, $operation);
        return $node;
    }
    private static function assertNode(BinaryNode $node, string $operation): void
    {
        $error = $node->child('error');
        if ($error !== null || isset($node->attrs['error'])) throw new ServiceException($operation, $error?->attrs['code'] ?? $node->attrs['error'] ?? null, $error?->attrs['text'] ?? ServiceTransport::text($error) ?? 'catalog rejected');
    }
}
