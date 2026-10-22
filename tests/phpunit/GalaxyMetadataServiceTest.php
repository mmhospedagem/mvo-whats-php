<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Protocol\BinaryNode;
use MVO\Service\{GalaxyMetadataService, MexService, ServiceException, ServiceTransport};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GalaxyMetadataServiceTest extends TestCase
{
    /** @var list<array{node:BinaryNode,timeout:float}> */
    private array $queries = [];

    private function service(BinaryNode $response): GalaxyMetadataService
    {
        $transport = new ServiceTransport(
            function (BinaryNode $node, float $timeout) use ($response): BinaryNode {
                $this->queries[] = ['node' => $node, 'timeout' => $timeout];
                return $response;
            },
            static function (BinaryNode $node): void { self::fail('Metadata must use an IQ, not send a message'); },
            static fn(): array => [],
        );
        return new GalaxyMetadataService(new MexService($transport));
    }

    /** @param array<string,mixed>|null $data */
    private static function iqResult(?array $data): BinaryNode
    {
        return new BinaryNode('iq', ['type' => 'result'], [
            new BinaryNode('result', ['format' => 'json'], json_encode(['data' => $data], JSON_THROW_ON_ERROR)),
        ]);
    }

    public function testPinnedQueryPreservesMetadataAndExactBusinessIdentity(): void
    {
        $data = ['xwa_extensions_get_flow_data' => [
            'extensions_flow_data' => [[
                'flow_id' => '12345678901234567890',
                'extensions' => ['metadata' => [
                    'flow_name' => 'Cadastro de demonstração', 'state' => 'PUBLISHED',
                    'categories' => ['CUSTOMER_SUPPORT'], 'creation_source' => 'BUSINESS_PLATFORM',
                    'www_proxy_secret' => 'fixture-proxy-secret', 'flow_token_signature' => 'fixture-signature',
                    'future_metadata' => ['retained' => true],
                ]],
            ]],
            'endpoint_public_key' => ['key' => 'fixture-public-key', 'signature' => 'fixture-key-signature'],
            'future_response' => ['available' => true],
        ]];
        $service = $this->service(self::iqResult($data));
        self::assertSame($data, $service->getFlowData('123456789@lid', '12345678901234567890', 4.5));
        self::assertCount(1, $this->queries);
        $query = $this->queries[0]['node'];
        self::assertSame(['type' => 'get', 'to' => 's.whatsapp.net', 'xmlns' => 'w:mex'], $query->attrs);
        self::assertSame(['query_id' => '32671434985780838'], $query->child('query')?->attrs);
        self::assertSame([
            'queryId' => '24989855014035777',
            'variables' => ['request' => ['extensions' => [
                'biz_jid' => '123456789@lid', 'flow_id' => '12345678901234567890',
            ]]],
        ], json_decode((string)$query->child('query')?->content, true, 512, JSON_THROW_ON_ERROR));
        self::assertSame(4.5, $this->queries[0]['timeout']);
    }

    public function testNullAndEmptyMetadataAreNotFabricated(): void
    {
        self::assertNull($this->service(self::iqResult(null))->getFlowData('5511999999999@s.whatsapp.net', '123'));
        $data = ['xwa_extensions_get_flow_data' => ['extensions_flow_data' => []]];
        self::assertSame($data, $this->service(self::iqResult($data))->getFlowData('5511999999999@s.whatsapp.net', '123'));
    }

    public function testIqPermissionRefusalIsPropagated(): void
    {
        $service = $this->service(new BinaryNode('iq', ['type' => 'error'], [
            new BinaryNode('error', ['code' => '403', 'text' => 'not-authorized']),
        ]));
        try {
            $service->getFlowData('123@lid', '123');
            self::fail('Accepted an IQ permission refusal');
        } catch (ServiceException $error) {
            self::assertSame('403', $error->serverCode);
            self::assertSame('mex.galaxy.getFlowData', $error->operation);
            self::assertSame('not-authorized', $error->reason);
        }
    }

    public function testGraphQlRefusalKeepsCodeAndReason(): void
    {
        $service = $this->service(new BinaryNode('iq', ['type' => 'result'], [
            new BinaryNode('result', [], '{"errors":[{"message":"Flow access denied","extensions":{"error_code":403,"severity":"ERROR"}}],"data":null}'),
        ]));
        try {
            $service->getFlowData('123@lid', '123');
            self::fail('Accepted a GraphQL permission refusal');
        } catch (ServiceException $error) {
            self::assertSame('403', $error->serverCode);
            self::assertSame('mex.galaxy.getFlowData', $error->operation);
            self::assertSame('Flow access denied ERROR', $error->reason);
        }
    }

    /** @return iterable<string,array{string,string,float}> */
    public static function invalidInputs(): iterable
    {
        yield 'phone number is not a JID' => ['5511999999999', '123', 1.0];
        yield 'group is not the business' => ['123@g.us', '123', 1.0];
        yield 'newsletter is not the business' => ['123@newsletter', '123', 1.0];
        yield 'device is not a bare business identity' => ['123:2@s.whatsapp.net', '123', 1.0];
        yield 'username is not a fabricated JID' => ['example@lid', '123', 1.0];
        yield 'empty ID' => ['123@lid', '', 1.0];
        yield 'URL is not a form ID' => ['123@lid', 'https://example.invalid/form', 1.0];
        yield 'whitespace ID' => ['123@lid', ' 123', 1.0];
        yield 'oversized ID' => ['123@lid', str_repeat('1', 65), 1.0];
        yield 'zero timeout' => ['123@lid', '123', 0.0];
        yield 'negative timeout' => ['123@lid', '123', -1.0];
        yield 'infinite timeout' => ['123@lid', '123', INF];
        yield 'NaN timeout' => ['123@lid', '123', NAN];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidInputsDoNotReachTransport(string $jid, string $flowId, float $timeout): void
    {
        try {
            $this->service(self::iqResult(null))->getFlowData($jid, $flowId, $timeout);
            self::fail('Invalid metadata input reached the transport');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $this->queries);
        }
    }
}
