<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Service\{BusinessFormCatalogHttpTransport, GalaxyMetadataService, ServiceException};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BusinessFormCatalogHttpTransportTest extends TestCase
{
    public function testFixedCatalogQueryUsesObjectExtensionsAndPreservesOriginalData(): void
    {
        $calls = []; $providerCalls = 0;
        $data = ['xwa_extensions_get_flow_data' => [
            'extensions_flow_data' => [[
                'flow_id' => '12345678901234567890',
                'extensions' => ['metadata' => ['state' => 'PUBLISHED', 'flow_name' => 'Cadastro fictício', 'flow_token_signature' => 'fixture-signature', 'future_metadata' => ['retained' => true]]],
            ]],
            'endpoint_public_key' => ['key' => 'fixture-key', 'signature' => 'fixture-key-signature'],
        ]];
        $http = new BusinessFormCatalogHttpTransport(
            static function () use (&$providerCalls): string { $providerCalls++; return 'FIXTURE-CATALOG-CONFIGURATION'; },
            language: 'en_US',
            exchange: static function (string $url, array $headers, string $body, float $timeout) use (&$calls, $data): array {
                $calls[] = [$url, $headers, json_decode($body, true, 128, JSON_THROW_ON_ERROR), $timeout];
                return ['status' => 200, 'body' => json_encode(['data' => $data], JSON_THROW_ON_ERROR)];
            },
        );
        self::assertSame($data, (new GalaxyMetadataService($http))->getFlowData('123456789@lid', '12345678901234567890', 4.5));
        self::assertSame(1, $providerCalls); self::assertCount(1, $calls);
        self::assertSame('https://graph.whatsapp.com/graphql/catalog', $calls[0][0]);
        self::assertSame(['Content-Type: application/json', 'Accept: application/json', 'Accept-Encoding: gzip'], $calls[0][1]);
        self::assertSame([
            'access_token' => 'FIXTURE-CATALOG-CONFIGURATION', 'doc_id' => '24989855014035777',
            'variables' => ['request' => ['extensions' => ['biz_jid' => '123456789@lid', 'flow_id' => '12345678901234567890']]], 'lang' => 'en_US',
        ], $calls[0][2]);
        self::assertSame(4.5, $calls[0][3]);
    }

    public function testNullAndEmptyDataArePreservedWithoutInventingMetadata(): void
    {
        foreach (['{"data":null}' => null, '{"data":{}}' => []] as $body => $expected) {
            $http = new BusinessFormCatalogHttpTransport(static fn(): string => 'FIXTURE', exchange: static fn(): array => ['status' => 200, 'body' => $body]);
            self::assertSame($expected, $http->getFlowData('5511999999999@s.whatsapp.net', '123'));
        }
    }

    public function testEveryRequestReadsRotatedConfigurationAndDoesNotReusePreviousToken(): void
    {
        $token = 'FIXTURE-FIRST'; $seen = [];
        $http = new BusinessFormCatalogHttpTransport(static function () use (&$token): string { return $token; }, exchange: static function (string $url, array $headers, string $body) use (&$seen): array {
            $seen[] = json_decode($body, true, 128, JSON_THROW_ON_ERROR)['access_token']; return ['status' => 200, 'body' => '{"data":{}}'];
        });
        $http->getFlowData('123@lid', '123'); $token = 'FIXTURE-SECOND'; $http->getFlowData('123@lid', '123'); $token = '';
        try { $http->getFlowData('123@lid', '123'); self::fail('Removed configuration was reused'); } catch (\LogicException) {}
        self::assertSame(['FIXTURE-FIRST', 'FIXTURE-SECOND'], $seen);
    }

    public function testAuthorizationIsCheckedBeforeProviderAfterProviderAndAfterHttp(): void
    {
        $active = false; $providers = 0; $exchanges = 0;
        $guard = static function () use (&$active): void { if (!$active) throw new \LogicException('Access withdrawn'); };
        $http = new BusinessFormCatalogHttpTransport(static function () use (&$providers): string { $providers++; return 'FIXTURE'; }, exchange: static function () use (&$active, &$exchanges): array { $exchanges++; $active = false; return ['status' => 200, 'body' => '{"data":{"private":"fixture"}}']; }, authorizationGuard: $guard);
        try { $http->getFlowData('123@lid', '123'); self::fail('Provider ran without authorization'); } catch (\LogicException $error) { self::assertSame('Access withdrawn', $error->getMessage()); }
        self::assertSame(0, $providers); self::assertSame(0, $exchanges); $active = true;
        try { $http->getFlowData('123@lid', '123'); self::fail('Metadata escaped after withdrawal'); } catch (\LogicException $error) { self::assertSame('Access withdrawn', $error->getMessage()); }
        self::assertSame(1, $providers); self::assertSame(1, $exchanges);
        $active = true;
        $http = new BusinessFormCatalogHttpTransport(static function () use (&$active): string { $active = false; return 'FIXTURE'; }, exchange: static function (): never { self::fail('HTTP ran after provider withdrew access'); }, authorizationGuard: $guard);
        try { $http->getFlowData('123@lid', '123'); self::fail('HTTP escaped after withdrawal'); } catch (\LogicException $error) { self::assertSame('Access withdrawn', $error->getMessage()); }
    }

    public function testProviderAndTransportErrorsNeverExposeTheirOriginalMessageOrPreviousException(): void
    {
        foreach ([true, false] as $providerFailure) {
            $http = new BusinessFormCatalogHttpTransport(static function () use ($providerFailure): string { if ($providerFailure) throw new \RuntimeException('PRIVATE-TOKEN=fixture'); return 'FIXTURE'; }, exchange: static function (): never { throw new \RuntimeException('PRIVATE-COOKIE=fixture'); });
            try { $http->getFlowData('123@lid', '123'); self::fail('Failure accepted'); }
            catch (\Throwable $error) { self::assertStringNotContainsString('PRIVATE', $error->getMessage()); self::assertNull($error->getPrevious()); self::assertNotInstanceOf(ServiceException::class, $error); }
        }
    }

    public function testAuthorizationWithdrawalDuringFailureKeepsTheGuardException(): void
    {
        foreach ([true, false] as $providerFailure) {
            $active = true;
            $http = new BusinessFormCatalogHttpTransport(static function () use (&$active, $providerFailure): string {
                if ($providerFailure) { $active = false; throw new \RuntimeException('PRIVATE provider failure'); } return 'FIXTURE';
            }, exchange: static function () use (&$active): never { $active = false; throw new \RuntimeException('PRIVATE transfer failure'); },
                authorizationGuard: static function () use (&$active): void { if (!$active) throw new \LogicException('Access withdrawn'); });
            try { $http->getFlowData('123@lid', '123'); self::fail('Withdrawal was replaced by a transport error'); }
            catch (\LogicException $error) { self::assertSame('Access withdrawn', $error->getMessage()); self::assertNull($error->getPrevious()); }
        }
    }

    /** @return iterable<string,array{string,?string}> */
    public static function invalidConfiguration(): iterable
    {
        yield 'language injection' => ["pt_BR\r\nCookie: PRIVATE", null];
        yield 'unsupported proxy scheme' => ['pt_BR', 'file:///tmp/proxy'];
        yield 'proxy query' => ['pt_BR', 'http://example.invalid/?token=PRIVATE'];
        yield 'proxy fragment' => ['pt_BR', 'http://example.invalid/#PRIVATE'];
        yield 'proxy control' => ['pt_BR', "http://example.invalid/\r\nPRIVATE"];
    }
    #[DataProvider('invalidConfiguration')]
    public function testInvalidConfigurationIsRejectedBeforeAnyProviderCall(string $language, ?string $proxy): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new BusinessFormCatalogHttpTransport(static function (): never { self::fail('Invalid configuration called provider'); }, language: $language, proxy: $proxy);
    }

    /** @return iterable<string,array{int,string,?string}> */
    public static function refusals(): iterable
    {
        yield 'GraphQL code' => [200, '{"errors":[{"message":"PRIVATE arbitrary message","extensions":{"error_code":403}}],"data":null}', '403'];
        yield 'Meta error' => [400, '{"error":{"code":190,"message":"PRIVATE token"}}', '190'];
        yield 'unsafe code' => [200, '{"error":{"code":"PRIVATE-CODE","message":"PRIVATE"}}', null];
        yield 'redirect is not followed or accepted' => [302, '{"data":{"private":"PRIVATE"}}', '302'];
        yield 'HTML refusal' => [403, '<html>PRIVATE</html>', '403'];
    }
    #[DataProvider('refusals')]
    public function testExplicitRefusalsExposeOnlySafeNumericCode(int $status, string $body, ?string $code): void
    {
        $calls = 0;
        $http = new BusinessFormCatalogHttpTransport(static fn(): string => 'FIXTURE', exchange: static function () use ($status, $body, &$calls): array { $calls++; return ['status' => $status, 'body' => $body]; });
        try { $http->getFlowData('123@lid', '123'); self::fail('Refusal accepted'); }
        catch (ServiceException $error) { self::assertSame($code, $error->serverCode); self::assertSame('galaxy.getFlowData', $error->operation); self::assertStringNotContainsString('PRIVATE', $error->getMessage()); self::assertNull($error->errorKind); }
        self::assertSame(1, $calls);
    }

    /** @return iterable<string,array{int,string}> */
    public static function unconfirmedResponses(): iterable
    {
        yield 'partial data and errors' => [200, '{"data":{"metadata":"PRIVATE"},"errors":[{"code":400,"message":"PRIVATE"}]}'];
        yield 'upstream error' => [503, '{"error":{"message":"PRIVATE"}}'];
        yield 'malformed JSON' => [200, '{PRIVATE'];
        yield 'invalid UTF8' => [200, "{\"data\":\"\xff\"}"];
        yield 'list envelope' => [200, '[]'];
        yield 'list data' => [200, '{"data":[]}'];
        yield 'missing data' => [200, '{}'];
        yield 'unexpected success status' => [201, '{"data":{}}'];
        yield 'oversized body' => [200, str_repeat('P', BusinessFormCatalogHttpTransport::MAX_BYTES + 1)];
    }
    #[DataProvider('unconfirmedResponses')]
    public function testInvalidOrPartialResponsesRemainUnconfirmedWithoutRetry(int $status, string $body): void
    {
        $calls = 0;
        $http = new BusinessFormCatalogHttpTransport(static fn(): string => 'FIXTURE', exchange: static function () use ($status, $body, &$calls): array { $calls++; return ['status' => $status, 'body' => $body]; });
        try { $http->getFlowData('123@lid', '123'); self::fail('Unconfirmed response accepted'); }
        catch (\RuntimeException $error) { self::assertNotInstanceOf(ServiceException::class, $error); self::assertStringNotContainsString('PRIVATE', $error->getMessage()); }
        self::assertSame(1, $calls);
    }

    /** @return iterable<string,array{string,string,float}> */
    public static function invalidInputs(): iterable
    {
        yield 'number without JID' => ['5511999999999', '123', 1.0];
        yield 'group' => ['123@g.us', '123', 1.0];
        yield 'device' => ['123:2@s.whatsapp.net', '123', 1.0];
        yield 'URL flow ID' => ['123@lid', 'https://example.invalid/', 1.0];
        yield 'zero timeout' => ['123@lid', '123', 0.0];
        yield 'too long timeout' => ['123@lid', '123', 32.01];
        yield 'infinite timeout' => ['123@lid', '123', INF];
    }
    #[DataProvider('invalidInputs')]
    public function testInvalidInputsDoNotRequestConfigurationOrHttp(string $jid, string $flowId, float $timeout): void
    {
        $http = new BusinessFormCatalogHttpTransport(static function (): never { self::fail('Provider ran for invalid input'); }, exchange: static function (): never { self::fail('HTTP ran for invalid input'); });
        $this->expectException(\InvalidArgumentException::class); $http->getFlowData($jid, $flowId, $timeout);
    }

    /** @return iterable<string,array{string}> */
    public static function invalidTokens(): iterable
    {
        yield 'empty' => ['']; yield 'whitespace' => ['TOKEN PRIVATE']; yield 'control' => ["TOKEN\0PRIVATE"]; yield 'invalid UTF8' => ["\xff"]; yield 'oversized' => [str_repeat('t', 65537)];
    }
    #[DataProvider('invalidTokens')]
    public function testInvalidConfigurationNeverReachesHttp(string $token): void
    {
        $http = new BusinessFormCatalogHttpTransport(static fn(): string => $token, exchange: static function (): never { self::fail('Invalid token reached HTTP'); });
        $this->expectException(\LogicException::class); $http->getFlowData('123@lid', '123');
    }
}
