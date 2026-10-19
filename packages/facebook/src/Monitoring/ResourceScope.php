<?php
declare(strict_types=1);

namespace MVO\Facebook\Monitoring;

/** Exact native account, acting identity and resource; no cross-channel aliases. */
final readonly class ResourceScope
{
    public function __construct(
        public string $accountId,
        public string $identityId,
        public string $resourceType,
        public string $resourceId,
    ) {
        foreach ([$accountId, $identityId, $resourceId] as $id) {
            if ($id === '' || strlen($id) > 512 || preg_match('/[\x00-\x20\x7f]/', $id) === 1 || !preg_match('//u', $id)) {
                throw new \InvalidArgumentException('Expected an exact native Facebook identity/resource ID');
            }
        }
        if (preg_match('/^[a-z][a-z0-9_.-]{0,63}$/D', $resourceType) !== 1) {
            throw new \InvalidArgumentException('Invalid normalized monitor resource type');
        }
    }

    public function key(): string
    {
        return hash('sha256', json_encode([$this->accountId, $this->identityId, $this->resourceType, $this->resourceId], JSON_THROW_ON_ERROR));
    }

    /** @return array{account_id:string,identity_id:string,resource_type:string,resource_id:string} */
    public function toArray(): array
    {
        return ['account_id' => $this->accountId, 'identity_id' => $this->identityId, 'resource_type' => $this->resourceType, 'resource_id' => $this->resourceId];
    }
}
