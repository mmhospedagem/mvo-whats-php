<?php
declare(strict_types=1);

use MVO\Messenger\Auth\{BoundWebSession,CookieJar};
use MVO\Messenger\Transport\{CurlHttpClient,CurlTransportCapture,HttpResponse,UploadFile};
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/fixtures/curl-capture.php';

final class MultipartUploadTest extends TestCase
{
    private string $file;
    protected function setUp(): void
    {
        $this->file = tempnam(sys_get_temp_dir(), 'mvo-upload-');
        file_put_contents($this->file, 'SYNTHETIC-FILE-BYTES');
        CurlTransportCapture::$active = true;
        CurlTransportCapture::$options = [];
        CurlTransportCapture::$headers = [];
        CurlTransportCapture::$body = '{"payload":{"id":"synthetic"}}';
        CurlTransportCapture::$status = 200;
    }
    protected function tearDown(): void
    {
        CurlTransportCapture::$active = false;
        CurlTransportCapture::$options = [];
        if (is_file($this->file)) unlink($this->file);
    }
    public function testMultipartPreservesScopedCookiesAndNativeFileBoundary(): void
    {
        $selected = new UploadFile($this->file, 'example.txt', 'text/plain');
        $response = (new CurlHttpClient())->upload('https://www.facebook.com/upload-test', CookieJar::fromJson('{"c_user":"10001","xs":"SYNTHETIC-COOKIE"}'), ['csrf' => 'SYNTHETIC-CSRF'], ['source' => $selected]);
        self::assertSame(200, $response->status);
        self::assertSame(false, CurlTransportCapture::$options[CURLOPT_FOLLOWLOCATION]);
        self::assertTrue(CurlTransportCapture::$options[CURLOPT_SSL_VERIFYPEER]);
        $fields = CurlTransportCapture::$options[CURLOPT_POSTFIELDS];
        self::assertSame('SYNTHETIC-CSRF', $fields['csrf']);
        self::assertInstanceOf(CURLFile::class, $fields['source']);
        self::assertSame('example.txt', $fields['source']->getPostFilename());
        self::assertSame('text/plain', $fields['source']->getMimeType());
        self::assertStringNotContainsString($this->file, print_r($selected, true));
        try { serialize($selected); self::fail('Private paths must not be serializable'); } catch (LogicException) {}
    }
    public function testChangedFileAndUnapprovedUrlFailBeforeAnyTransfer(): void
    {
        $selected = new UploadFile($this->file, 'example.txt', 'text/plain');
        file_put_contents($this->file, 'changed-content-of-a-different-size');
        $cookies = CookieJar::fromJson('{"c_user":"10001","xs":"SYNTHETIC-COOKIE"}');
        try { (new CurlHttpClient())->upload('https://www.facebook.com/upload-test', $cookies, [], ['source' => $selected]); self::fail('Changed file'); } catch (RuntimeException) {}
        $selected = new UploadFile($this->file, 'example.txt', 'text/plain');
        try { (new CurlHttpClient())->upload('https://attacker.invalid/upload', $cookies, [], ['source' => $selected]); self::fail('Cookie origin boundary'); } catch (InvalidArgumentException) { self::assertTrue(true); }
        self::assertSame([], CurlTransportCapture::$options);
    }
    public function testFileNamesAndPathsCannotBecomeMultipartHeaderOrRemoteInputs(): void
    {
        foreach (["bad\r\nHeader.txt", '../escape.txt', 'folder\\escape.txt'] as $name) {
            try { new UploadFile($this->file, $name, 'text/plain'); self::fail('Invalid file name'); } catch (InvalidArgumentException) { self::assertTrue(true); }
        }
        try { new UploadFile('https://attacker.invalid/file', 'example.txt', 'text/plain'); self::fail('Local files only'); } catch (InvalidArgumentException) { self::assertTrue(true); }
        try { new UploadFile($this->file, 'example.txt', "text/plain\r\nHeader"); self::fail('MIME injection'); } catch (InvalidArgumentException) { self::assertTrue(true); }
    }
    public function testReplacingFileContentsWithTheSameSizeCannotReuseItsSelectedDigest(): void
    {
        $selected = new UploadFile($this->file, 'example.txt', 'text/plain');
        file_put_contents($this->file, str_repeat('X', $selected->size));
        $this->expectException(RuntimeException::class);
        $selected->sha256();
    }
    public function testBoundUploadsUseOwnerGuardWithoutExposingOrCopyingCredentials(): void
    {
        $active = true; $calls = 0;
        $session = new BoundWebSession('10001', static fn(): array => [], static fn(): HttpResponse => new HttpResponse(200, '', []),
            static function (string $url, array $form, array $files) use (&$active, &$calls): HttpResponse {
                if (!$active) throw new LogicException('Owner stopped');
                $calls++; return new HttpResponse(200, '', []);
            });
        $selected = new UploadFile($this->file, 'example.txt', 'text/plain');
        $session->upload('https://www.facebook.com/upload-test', [], ['source' => $selected]);
        $active = false;
        try { $session->upload('https://www.facebook.com/upload-test', [], ['source' => $selected]); self::fail('Stopped owner'); } catch (LogicException) {}
        self::assertSame(1, $calls);
        $legacy = new BoundWebSession('10001', static fn(): array => [], static fn(): HttpResponse => new HttpResponse(200, '', []));
        $this->expectException(LogicException::class);
        $legacy->upload('https://www.facebook.com/upload-test', [], ['source' => $selected]);
    }
}
