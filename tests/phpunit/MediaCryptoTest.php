<?php
declare(strict_types=1);

namespace MVO\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Media\MediaCrypto;

final class MediaCryptoTest extends TestCase
{
    public function testMediaMatchesIndependentCryptographyVector(): void
    {
        $vector = json_decode(file_get_contents(dirname(__DIR__).'/media-vector.json'), true, 512, JSON_THROW_ON_ERROR);
        $key = hex2bin($vector['key']);
        $plain = hex2bin($vector['plain']);
        $body = hex2bin($vector['body']);

        self::assertSame($body, MediaCrypto::encrypt($plain, 'image', $key)['body']);
        self::assertSame($plain, MediaCrypto::decrypt($body, 'image', $key));
    }

    #[DataProvider('mediaPayloads')]
    public function testSupportedMediaPreservesPayloadAndHashes(string $type, string $plain): void
    {
        $encrypted = MediaCrypto::encrypt($plain, $type, str_repeat('k', 32));

        self::assertSame(strlen($plain), $encrypted['fileLength']);
        self::assertSame(hash('sha256', $plain, true), $encrypted['fileSha256']);
        self::assertSame(hash('sha256', $encrypted['body'], true), $encrypted['fileEncSha256']);
        self::assertSame($plain, MediaCrypto::decrypt(
            $encrypted['body'], $type, $encrypted['mediaKey'],
            $encrypted['fileSha256'], $encrypted['fileEncSha256'],
        ));
    }

    public static function mediaPayloads(): iterable
    {
        $types = [
            'image', 'sticker', 'xma-image', 'video', 'gif', 'ptv', 'audio', 'ptt',
            'document', 'history', 'md-msg-hist', 'md-app-state', 'group-history',
            'sticker-pack', 'thumbnail-sticker-pack', 'thumbnail-link',
        ];
        foreach ($types as $type) {
            yield $type.' empty' => [$type, ''];
            yield $type.' binary' => [$type, "\0hello\xff"];
        }
    }

    public function testTamperedCiphertextIsRejectedByMacWithoutHashes(): void
    {
        $encrypted = MediaCrypto::encrypt('payload', 'image', str_repeat('k', 32));
        $body = $encrypted['body'];
        $body[0] = chr(ord($body[0]) ^ 1);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Media MAC mismatch');
        MediaCrypto::decrypt($body, 'image', $encrypted['mediaKey']);
    }

    public function testEncryptedHashMismatchIsRejected(): void
    {
        $encrypted = MediaCrypto::encrypt('payload', 'image');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Encrypted hash mismatch');
        MediaCrypto::decrypt($encrypted['body'], 'image', $encrypted['mediaKey'], fileEncSha256: str_repeat('x', 32));
    }

    public function testPlaintextHashMismatchIsRejected(): void
    {
        $encrypted = MediaCrypto::encrypt('payload', 'image');

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Plaintext hash mismatch');
        MediaCrypto::decrypt($encrypted['body'], 'image', $encrypted['mediaKey'], fileSha256: str_repeat('x', 32));
    }

    public function testTruncatedCiphertextIsRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        MediaCrypto::decrypt(str_repeat('x', 25), 'image', str_repeat('k', 32));
    }

    public function testUnknownMediaTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        MediaCrypto::deriveKeys('unsupported', str_repeat('k', 32));
    }
}
