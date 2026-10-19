<?php
declare(strict_types=1);

namespace MVO\Facebook\Media;

/** The photo configuration from the current account's ReactComposerMediaConfig bootstrap.
 * This records an explicit account/actor binding; it does not discover or switch an actor.
 */
final class PhotoUploadContext
{
    public const ENDPOINT = 'https://upload.facebook.com/ajax/react_composer/attachments/photo/upload';
    /** @var array<string,string> */
    private readonly array $data;

    /** $photo is exactly ReactComposerMediaConfig.photo, supplied by the authenticated application.
     * @param array<string,mixed> $photo
     */
    public function __construct(public readonly string $accountId, public readonly string $actorId, array $photo)
    {
        foreach ([$accountId, $actorId] as $id) if (!preg_match('/^[1-9][0-9]{0,24}$/D', $id)) throw new \InvalidArgumentException('Bind the upload context to the exact account and actor IDs');
        $data = $photo['uploadData'] ?? null;
        if (($photo['uploadEndpoint'] ?? null) !== self::ENDPOINT || !is_array($data)
            || array_diff(array_keys($data), ['source', 'profile_id', 'waterfallxapp']) !== []
            || ($data['source'] ?? null) !== 8 || ($data['waterfallxapp'] ?? null) !== 'comet'
            || !is_string($data['profile_id'] ?? null) || !preg_match('/^(?:0|[1-9][0-9]{0,24})$/D', $data['profile_id'])) {
            throw new \InvalidArgumentException('Supply the current, observed Comet photo upload configuration; unsupported endpoint or fields');
        }
        $this->data = ['source' => '8', 'profile_id' => $data['profile_id'], 'waterfallxapp' => 'comet'];
    }

    /** @return array<string,string> */
    public function formData(): array { return $this->data; }
}
