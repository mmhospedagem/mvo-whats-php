<?php
declare(strict_types=1);
namespace MVO\Service;
use MVO\Protocol\{BinaryNode,ArgoDecoder};

/** GraphQL persisted operations from the pinned WhatsApp Web reference specification. */
final class MexService
{
    /** @var array<string,array{docId:string,clientDocId:string}>|null */
    private static ?array $operations=null;
    public function __construct(private readonly ServiceTransport $transport) {}
    /**
     * @return list<string> */
    public function operations(): array { return array_keys(self::manifest()); }
    /**
     * @param array<string,mixed> $variables
     * @return array<string,mixed>|null */
    public function run(string $operation,array $variables=[],float $timeout=32.0): ?array
    {
        $op=self::manifest()[$operation] ?? throw new \InvalidArgumentException('Unknown MEX operation');
        return $this->dispatch($op['docId'],$op['clientDocId'],$variables,$operation,$timeout);
    }
    /**
     * @param array<string,mixed> $variables
     * @return array<string,mixed>|null */
    public function dispatch(string $docId,string $clientDocId,array $variables,string $operation='custom',float $timeout=32.0): ?array
    {
        $body=json_encode(['queryId'=>$clientDocId,'variables'=>$variables === [] ? new \stdClass() : $variables],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
        $r=$this->transport->iq('mex.'.$operation,'get','s.whatsapp.net','w:mex',[new BinaryNode('query',['query_id'=>$docId],$body)],[],$timeout);
        $result=$r->child('result') ?? throw new \UnexpectedValueException('MEX result missing');
        $format=$result->attrs['format'] ?? 'json';
        $payload=ServiceTransport::text($result) ?? throw new \UnexpectedValueException('MEX payload missing');
        $json=match($format) { 'json'=>json_decode($payload,true,512,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING),'argo'=>ArgoDecoder::decode($payload),default=>throw new \UnexpectedValueException('Unsupported MEX response format: '.$format) };
        if (!is_array($json)) throw new \UnexpectedValueException('Invalid MEX envelope');
        if (!empty($json['errors'])) {
            $codes=[]; $details=[]; foreach ($json['errors'] as $error) { $codes[]=(string)($error['extensions']['error_code'] ?? $error['extensions']['code'] ?? '?'); $details[]=($error['message'] ?? 'GraphQL operation rejected').(isset($error['extensions']['severity']) ? ' '.$error['extensions']['severity'] : ''); }
            throw new ServiceException('mex.'.$operation,implode(',',$codes),implode('; ',$details));
        }
        $data=$json['data'] ?? null; if ($data !== null && !is_array($data)) throw new \UnexpectedValueException('Invalid MEX data'); return $data;
    }
    /**
     * @return array<string,array{docId:string,clientDocId:string}> */
    private static function manifest(): array
    {
        if (self::$operations === null) { $json=file_get_contents(__DIR__.'/mex-operations.json'); if ($json === false) throw new \RuntimeException('MEX manifest missing'); self::$operations=json_decode(ltrim($json,"\xEF\xBB\xBF"),true,512,JSON_THROW_ON_ERROR); }
        return self::$operations;
    }
}
