<?php
declare(strict_types=1);
namespace MVO\Service;

/** Native Business Agent persisted operations observed in the pinned Web reference. */
final class BusinessAgentService
{
    /** @var array<string,mixed>|null */
    private static ?array $catalog=null;
    /** @var \Closure(string,array<string,mixed>):array<string,mixed> */
    private \Closure $request;

    /** @param callable(string,array<string,mixed>):array<string,mixed> $request */
    public function __construct(callable $request)
    {
        $this->request=\Closure::fromCallable($request);
    }

    /** @return array<string,array<string,mixed>> */
    public static function operations():array
    {
        return self::catalog()['operations'];
    }

    /** @return array<string,mixed> */
    public static function source():array
    {
        return self::catalog()['source'];
    }

    /** Validate the recovered top-level variable names and JSON envelope before authentication.
     * Nested GraphQL input types were stripped from the compiled reference.
     * @param array<string,mixed> $variables
     */
    public static function validate(string $operation,array $variables):void
    {
        $definition=self::operations()[$operation] ?? throw new \InvalidArgumentException('Unknown Business Agent operation');
        if ($variables!==[] && array_is_list($variables)) throw new \InvalidArgumentException('Business Agent variables must be an object');
        foreach ($variables as $name=>$value) {
            if (!in_array($name,$definition['variables'],true)) throw new \InvalidArgumentException('Unknown Business Agent variable');
        }
        if(isset($definition['inputContract']))BusinessAgentInput::validate($variables,$definition['inputContract']);
        try { $json=json_encode($variables,JSON_THROW_ON_ERROR,64); }
        catch (\JsonException $error) { throw new \InvalidArgumentException('Business Agent variables must contain valid JSON',0,$error); }
        if (strlen($json)>BusinessFormHttpTransport::MAX_BYTES) throw new \LengthException('Business Agent variables exceed 2 MiB');
    }

    /** No operation is automatically repeated after an uncertain HTTP result.
     * @param array<string,mixed> $variables Native variables, including input/request objects.
     * @return array{operation:string,kind:string,outcome:string,data:array<string,mixed>,source:string,supportVerified:bool}
     */
    public function run(string $operation,array $variables=[]):array
    {
        self::validate($operation,$variables);
        $definition=self::operations()[$operation];
        $response=($this->request)($definition['documentId'],$variables);
        $data=self::project($response,$definition['response']);
        foreach ($definition['response'] as $field) {
            if (isset($field['name']) && !array_key_exists($field['name'],$data))
                throw new \UnexpectedValueException('Business Agent response is missing its expected result');
        }
        return [
            'operation'=>$operation,'kind'=>$definition['kind'],
            'outcome'=>self::isRead($definition) ? 'observed' : self::mutationOutcome($operation,$variables,$data),
            'data'=>$data,'source'=>'native_graphql','supportVerified'=>($definition['validation']['liveValidated']??false)===true,
        ];
    }

    /** @param array<string,mixed> $variables
     * @param array<string,mixed> $data */
    private static function mutationOutcome(string $operation,array $variables,array $data):string
    {
        $result=reset($data);
        if (!is_array($result) || $result===[]) return 'unknown';
        // The shipped caller confirms this mutation by the returned applied settings.
        if($operation==='reengagement-update'){
            $request=$variables['request']??null;
            if(!is_array($request)||!is_bool($request['enabled']??null)||($result['enabled']??null)!==$request['enabled'])return 'unknown';
            if(array_key_exists('amount',$request)&&($result['amount']??null)!==$request['amount'])return 'unknown';
            return 'accepted';
        }
        $items=array_is_list($result) ? $result : [$result];
        $accepted=0;
        $rejected=0;
        foreach ($items as $item) {
            if (!is_array($item) || !is_bool($item['success'] ?? null)) return 'unknown';
            if ($item['success']) ++$accepted;
            else ++$rejected;
        }
        if ($accepted>0 && $rejected>0) return 'partial';
        return $rejected>0 ? 'rejected' : 'accepted';
    }

    /** GraphQL also exposes read-only fetches as persisted mutations.
     * @param array<string,mixed> $definition */
    public static function isRead(array $definition):bool
    {
        return $definition['kind']==='query'||($definition['effect']??null)==='read';
    }

    /** Only fields selected by the pinned native operation reach the public result.
     * @param array<string,mixed> $record
     * @param list<array<string,mixed>> $selections
     * @return array<string,mixed>
     */
    private static function project(array $record,array $selections):array
    {
        $result=[];
        foreach ($selections as $field) {
            if (($field['kind'] ?? '')==='inlineFragment') {
                if (($record['__typename'] ?? null)===$field['onType'])
                    $result=array_replace($result,self::project($record,$field['selections']));
                continue;
            }
            $name=$field['alias'] ?? $field['name'] ?? null;
            if (!is_string($name) || !array_key_exists($name,$record)) continue;
            $value=$record[$name];
            if ($field['kind']==='scalar') {
                if ($value!==null && !is_scalar($value)) throw new \UnexpectedValueException('Invalid Business Agent scalar');
                $result[$name]=$value;
                continue;
            }
            if ($field['kind']!=='linked') continue;
            if ($value===null) { $result[$name]=null; continue; }
            if (!is_array($value)) throw new \UnexpectedValueException('Invalid Business Agent result object');
            if (!$field['plural']) {
                if ($value!==[] && array_is_list($value)) throw new \UnexpectedValueException('Invalid Business Agent result object');
                $result[$name]=self::project($value,$field['selections']);
                continue;
            }
            if (!array_is_list($value)) throw new \UnexpectedValueException('Invalid Business Agent result list');
            $items=[];
            foreach ($value as $item) {
                if ($item===null) { $items[]=null; continue; }
                if (!is_array($item) || ($item!==[] && array_is_list($item))) throw new \UnexpectedValueException('Invalid Business Agent list item');
                $items[]=self::project($item,$field['selections']);
            }
            $result[$name]=$items;
        }
        return $result;
    }

    /** @return array<string,mixed> */
    private static function catalog():array
    {
        if (self::$catalog===null) {
            $json=file_get_contents(dirname(__DIR__,2).'/resources/business-agent-operations.json');
            if ($json===false) throw new \RuntimeException('Business Agent operation catalog is missing');
            self::$catalog=json_decode($json,true,64,JSON_THROW_ON_ERROR);
        }
        return self::$catalog;
    }
}
