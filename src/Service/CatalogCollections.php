<?php
declare(strict_types=1);

namespace MVO\Service;

use MVO\Protocol\BinaryNode;

/** Catalog collections and cart settings use their own acknowledged IQ mutations. */
trait CatalogCollections
{
    /** Searches the business category hierarchy using the server's localized taxonomy.
     *
     * @return array<string,mixed> */
    public function getBusinessCategories(string $query, string $locale = 'pt_BR', bool $v2 = true): array
    {
        $query = self::textInput($query, 'category query', 256, true);
        if (preg_match('/^[a-z]{2,3}_[A-Z]{2}$/D', $locale) !== 1) throw new \InvalidArgumentException('Category locale must use language_COUNTRY');
        $data = $this->mex($v2 ? 'BizGetCategoriesV2' : 'BizGetCategories', ['query_params'=>['query'=>$query,'locale'=>$locale,'operation'=>'PROFILE_TYPEAHEAD','version'=>$v2 ? 'V_2' : 'V_1']]);
        $categories = self::objectAt($data, ['whatsapp_catkit_typeahead_proxy']);
        self::categoryRows($categories['categories'] ?? null);
        if (isset($categories['not_a_biz'])) self::categoryRows([$categories['not_a_biz']]);
        return $categories;
    }

    /** @return array{created:true} */
    public function createCatalog(): array
    {
        $result = $this->query('catalog.create', 'set', new BinaryNode('product_catalog_create', ['version'=>'1']));
        self::required($result, 'product_catalog_create', 'catalog.create');
        return ['created'=>true];
    }

    /**
     * @param list<string> $products
     * @return array<string,mixed> */
    public function createCollection(string $name, array $products = [], ?string $catalogSessionId = null): array
    {
        $name = self::textInput($name, 'collection name', 1024);
        $ids = self::collectionIds($products, true);
        $children = [new BinaryNode('name', [], $name)];
        foreach ($ids as $id) $children[] = new BinaryNode('product', [], [new BinaryNode('id', [], $id)]);
        $children[] = new BinaryNode('catalog_session_id', [], self::catalogSession($catalogSessionId));
        $result = $this->query('catalog.collectionCreate', 'set', new BinaryNode('collection', ['op'=>'create'], $children), ['smax_id'=>'36']);
        return self::collectionMutationResult($result, 'catalog.collectionCreate');
    }

    /**
     * @param list<string> $products
     * @return array<string,mixed> */
    public function collectionCreate(string $name, array $products = [], ?string $catalogSessionId = null): array
    {
        return $this->createCollection($name, $products, $catalogSessionId);
    }

    /** Only supplied fields change; product membership is added/removed explicitly.
     *
     * @param array<string,mixed> $update
     * @return array<string,mixed> */
    public function updateCollection(string $id, array $update): array
    {
        self::id($id);
        foreach (array_keys($update) as $key) if (!in_array($key, ['name','productsToAdd','productsToRemove','catalogSessionId'], true)) throw new \InvalidArgumentException('Unknown collection field '.$key);
        $children = [new BinaryNode('id', [], $id)];
        $changed = false;
        if (array_key_exists('name', $update)) {
            if (!is_string($update['name'])) throw new \InvalidArgumentException('Collection name must be a string');
            $children[] = new BinaryNode('name', [], self::textInput($update['name'], 'collection name', 1024));
            $changed = true;
        }
        $add = [];
        $remove = [];
        foreach (['productsToAdd'=>'add','productsToRemove'=>'remove'] as $field=>$tag) {
            if (!array_key_exists($field, $update)) continue;
            if (!is_array($update[$field])) throw new \InvalidArgumentException('Collection products must be a list');
            $ids = self::collectionIds($update[$field], true);
            if ($tag === 'add') $add = $ids; else $remove = $ids;
            if ($ids === []) continue;
            $children[] = new BinaryNode($tag, [], array_map(static fn(string $value):BinaryNode=>new BinaryNode('id', [], $value), $ids));
            $changed = true;
        }
        if (array_intersect($add, $remove) !== []) throw new \InvalidArgumentException('A product cannot be added and removed in the same update');
        if (!$changed) throw new \InvalidArgumentException('Empty collection update');
        $session = $update['catalogSessionId'] ?? null;
        if ($session !== null && !is_string($session)) throw new \InvalidArgumentException('Catalog session ID must be a string');
        $children[] = new BinaryNode('catalog_session_id', [], self::catalogSession($session));
        $result = self::collectionMutationResult($this->query('catalog.collectionUpdate', 'set', new BinaryNode('collection', ['op'=>'update'], $children), ['smax_id'=>'40']), 'catalog.collectionUpdate');
        if ($result['id'] !== $id) throw new \UnexpectedValueException('Updated collection response has a different ID');
        return $result;
    }

    /** @param array<string,mixed> $update
     * @return array<string,mixed> */
    public function collectionUpdate(string $id, array $update): array { return $this->updateCollection($id, $update); }

    /** Sends one acknowledged deletion per collection; the batch is not atomic.
     *
     * @param list<string> $ids
     * @return array{deleted:int,ids:list<string>,results:list<array<string,mixed>>} */
    public function deleteCollections(array $ids, ?string $catalogSessionId = null): array
    {
        $ids = self::collectionIds($ids);
        $session = self::catalogSession($catalogSessionId);
        $deleted = [];
        $results = [];
        foreach ($ids as $id) {
            try {
                $result = $this->query('catalog.collectionDelete', 'set', new BinaryNode('collections', ['op'=>'delete'], [new BinaryNode('id', [], $id), new BinaryNode('catalog_session_id', [], $session)]), ['smax_id'=>'43']);
                self::collectionSuccess($result, 'catalog.collectionDelete');
                $deleted[] = $id;
                $results[] = ['id'=>$id,'deleted'=>true];
            } catch (ServiceException $error) {
                if (count($ids) === 1) throw $error;
                $results[] = ['id'=>$id,'deleted'=>false,'error'=>['code'=>$error->serverCode,'message'=>$error->getMessage()]];
            }
        }
        return ['deleted'=>count($deleted), 'ids'=>$deleted,'results'=>$results];
    }

    /** @return array{deleted:int,ids:list<string>,results:list<array<string,mixed>>} */
    public function deleteCollection(string $id, ?string $catalogSessionId = null): array { return $this->deleteCollections([$id], $catalogSessionId); }

    /** @param list<string> $ids
     * @return array{deleted:int,ids:list<string>,results:list<array<string,mixed>>} */
    public function collectionDelete(array $ids, ?string $catalogSessionId = null): array { return $this->deleteCollections($ids, $catalogSessionId); }

    /** @return array{success:true} */
    public function appealCollection(string $id, ?string $reason = null): array
    {
        self::id($id);
        $children = $reason === null ? [] : [new BinaryNode('reason', [], self::textInput($reason, 'appeal reason', 10000))];
        $result = $this->query('catalog.collectionAppeal', 'set', new BinaryNode('collection', ['op'=>'appeal','id'=>$id], $children), ['smax_id'=>'48']);
        self::collectionSuccess($result, 'catalog.collectionAppeal');
        return ['success'=>true];
    }

    /** Moves contain the current and desired zero-based indexes supplied by the caller.
     *
     * @param list<array{id:string,fromIndex:int,toIndex:int}> $moves
     * @return array{success:true} */
    public function reorderCollections(array $moves): array
    {
        if (!array_is_list($moves) || $moves === [] || count($moves) > 100) throw new \InvalidArgumentException('One to one hundred collection moves required');
        $nodes = [];
        $seen = [];
        foreach ($moves as $move) {
            if (!is_array($move) || count($move) !== 3 || !is_string($move['id'] ?? null) || !is_int($move['fromIndex'] ?? null) || !is_int($move['toIndex'] ?? null) || $move['fromIndex'] < 0 || $move['toIndex'] < 0) throw new \InvalidArgumentException('Collection move requires id and nonnegative integer indexes');
            $id = self::id($move['id']);
            if (isset($seen[$id])) throw new \InvalidArgumentException('A collection may only be moved once per request');
            $seen[$id] = true;
            $nodes[] = new BinaryNode('id', ['from_index'=>(string)$move['fromIndex'],'to_index'=>(string)$move['toIndex']], $id);
        }
        $result = $this->query('catalog.collectionReorder', 'set', new BinaryNode('collections', ['op'=>'update'], [new BinaryNode('move', [], $nodes)]), ['smax_id'=>'92']);
        self::collectionSuccess($result, 'catalog.collectionReorder');
        return ['success'=>true];
    }

    /** @return array{cartEnabled:bool} */
    public function setCartEnabled(bool $enabled): array
    {
        $result = $this->transport->iq('catalog.cartSet', 'set', 's.whatsapp.net', 'fb:thrift_iq', [new BinaryNode('commerce_settings', [], [new BinaryNode('cart', ['enabled'=>$enabled ? 'true' : 'false'])])], ['smax_id'=>'25']);
        $settings = self::required($result, 'commerce_settings', 'catalog.cartSet');
        $cart = self::required($settings, 'cart', 'catalog.cartSet');
        return ['cartEnabled'=>self::cartBoolean($cart->attrs['enabled'] ?? null)];
    }

    /** @return array{cartEnabled:bool} */
    public function updateCartEnabled(bool $enabled): array { return $this->setCartEnabled($enabled); }

    /** Cart visibility is published in the business profile, not a local preference.
     *
     * @return array{cartEnabled:bool,commerceExperience?:string} */
    public function getCartSettings(?string $jid = null): array
    {
        $jid = $this->owner($jid);
        $response = $this->transport->iq('catalog.cartGet', 'get', 's.whatsapp.net', 'w:biz', [new BinaryNode('business_profile', ['v'=>'116'], [new BinaryNode('profile', ['jid'=>$jid])])]);
        $profiles = self::required($response, 'business_profile', 'catalog.cartGet');
        foreach (ServiceTransport::children($profiles, 'profile') as $profile) {
            if (($profile->attrs['jid'] ?? null) !== $jid) continue;
            $options = self::required($profile, 'profile_options', 'catalog.cartGet');
            $result = ['cartEnabled'=>self::cartBoolean(self::childText($options, 'cart_enabled'))];
            $experience = self::childText($options, 'commerce_experience');
            if ($experience !== null) $result['commerceExperience'] = $experience;
            return $result;
        }
        throw new \UnexpectedValueException('Cart settings response missing requested business profile');
    }

    /** @return array<string,mixed> */
    private static function collectionMutationResult(BinaryNode $result, string $operation): array
    {
        $node = self::required($result, 'collection', $operation);
        $out = ['id'=>self::id(self::requiredText($node, 'id'))];
        $status = $node->child('status_info');
        if ($status !== null) {
            self::assertNode($status, $operation);
            $text = self::childText($status, 'status');
            if ($text !== null) $out['reviewStatus'] = $text;
            $out['status'] = ['status'=>$text,'canAppeal'=>self::childText($status, 'can_appeal') === 'true'];
            foreach (['reject_reason'=>'rejectReason','commerce_url'=>'commerceUrl'] as $tag=>$field) {
                $text = self::childText($status, $tag);
                if ($text !== null) $out['status'][$field] = $text;
            }
        }
        return $out;
    }

    private static function collectionSuccess(BinaryNode $result, string $operation): void
    {
        $response = self::required($result, 'response', $operation);
        self::required($response, 'success', $operation);
    }

    private static function cartBoolean(mixed $value): bool
    {
        if (!in_array($value, ['true','false'], true)) throw new \UnexpectedValueException('Cart response missing valid enabled setting');
        return $value === 'true';
    }

    private static function catalogSession(?string $id): string
    {
        return $id === null ? (string)random_int(0, 2147483647) : self::textInput($id, 'catalog session ID', 128);
    }

    private static function categoryRows(mixed $rows, int $depth = 0): void
    {
        if ($depth > 6 || !is_array($rows) || !array_is_list($rows) || count($rows) > 1000) throw new \UnexpectedValueException('Invalid business category hierarchy');
        foreach ($rows as $row) {
            if (!is_array($row) || !is_string($row['id'] ?? null) || !is_string($row['display_name'] ?? null) || $row['id'] === '' || $row['display_name'] === '') throw new \UnexpectedValueException('Business category response missing id or display name');
            if (isset($row['categories'])) self::categoryRows($row['categories'], $depth + 1);
        }
    }

    /** @param array<mixed> $ids
     * @return list<string> */
    private static function collectionIds(array $ids, bool $allowEmpty = false): array
    {
        if ($allowEmpty && $ids === []) return [];
        return self::ids($ids);
    }
}
