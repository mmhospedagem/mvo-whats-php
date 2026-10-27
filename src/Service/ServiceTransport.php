<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Protocol\BinaryNode;
use MVO\Protocol\Jid;

/** Shared transport; never fabricates success and never retains account credentials.
 * @phpstan-type LidSyncError array{code?:string,text?:string,backoffSeconds?:int}
 * @phpstan-type LidSyncResult array{queriedJid:string,phoneJid:?string,lidJid:?string,exists:?bool,invalid:bool,jid:?string,pn:?string,lid:?string,status:'in'|'out'|'invalid'|'error'|'unanswered',error?:LidSyncError}
 */
final class ServiceTransport
{
    /** @var \Closure(BinaryNode,float):BinaryNode */
    private \Closure $query;
    /** @var \Closure(BinaryNode):void */
    private \Closure $send;
    /** @var \Closure():array<string,mixed> */
    private \Closure $credentials;
    /** @var \Closure(string,string|array<string,mixed>):array<string,mixed>|null */
    private ?\Closure $publish;
    private ?TrustedContactTokenService $privacyTokens=null;
    private ?IdentityService $identities=null;
    /**
     * @param callable(BinaryNode,float):BinaryNode $query
     *
     * @param callable(BinaryNode):void $send
     *
     * @param callable():array<string,mixed> $credentials
     *
     * @param callable(string,string|array<string,mixed>):array<string,mixed>|null $sendMessage */
    public function __construct(callable $query, callable $send, callable $credentials, ?callable $sendMessage = null)
    {
        $this->query = \Closure::fromCallable($query); $this->send = \Closure::fromCallable($send);
        $this->credentials = \Closure::fromCallable($credentials); $this->publish = $sendMessage === null ? null : \Closure::fromCallable($sendMessage);
    }
    /**
     * @param list<BinaryNode>|null $children
     * @param array<string,string> $attrs */
    public function iq(string $operation, string $type, string $to, string $xmlns, ?array $children = null, array $attrs = [], float $timeout = 30.0): BinaryNode
    {
        $node = new BinaryNode('iq', ['type'=>$type,'to'=>$to,'xmlns'=>$xmlns]+$attrs, $children);
        return $this->request($operation, $node, $timeout);
    }
    public function request(string $operation, BinaryNode $node, float $timeout = 30.0): BinaryNode
    {
        if($this->privacyTokens!==null && $node->tag==='iq' && ($node->attrs['xmlns'] ?? '')==='w:profile:picture' && ($node->attrs['type'] ?? '')==='get') {
            $picture=$node->child('picture');$token=$this->privacyTokens->resolveReceiverTokenNode($node->attrs['target'] ?? '');
            if($picture!==null && $token!==null && $picture->child('tctoken')===null){$replacement=new BinaryNode('picture',$picture->attrs,[...self::children($picture),$token]);$node=new BinaryNode($node->tag,$node->attrs,array_map(static fn(BinaryNode $child):BinaryNode=>$child===$picture ? $replacement : $child,self::children($node)));}
        }
        try { $result = ($this->query)($node, $timeout); }
        catch (ServiceException $error) { throw new ServiceException($operation,$error->serverCode,$error->reason); }
        self::assertResult($result,$operation); return $result;
    }
    public static function assertResult(BinaryNode $result,string $operation): void
    {
        if ($result->tag !== 'iq' || ($result->attrs['type'] ?? '') !== 'result') {
            $error = $result->child('error');
            throw new ServiceException($operation, $error?->attrs['code'] ?? $result->attrs['code'] ?? null, $error?->attrs['text'] ?? self::text($error) ?? 'expected an IQ result');
        }
    }
    public function configurePrivacyTokens(TrustedContactTokenService $service):void {$this->privacyTokens=$service;}
    public function configureIdentities(IdentityService $service):void {$this->identities=$service;}
    public function send(BinaryNode $node): void {
        if($this->privacyTokens!==null && $node->tag==='presence' && ($node->attrs['type'] ?? '')==='subscribe' && $node->child('tctoken')===null){$token=$this->privacyTokens->resolveReceiverTokenNode($node->attrs['to'] ?? '');if($token!==null)$node=new BinaryNode($node->tag,$node->attrs,[...self::children($node),$token]);}
        ($this->send)($node);
    }
    /**
     * @return array{id:string,status:string,ack:BinaryNode} */
    public function publishNode(BinaryNode $node,float $timeout=30.0): array
    {
        if ($node->tag !== 'message' || !isset($node->attrs['id'])) throw new \InvalidArgumentException('Message id required');
        $ack=($this->query)($node,$timeout);
        if ($ack->tag !== 'ack' || ($ack->attrs['class'] ?? '') !== 'message' || ($ack->attrs['id'] ?? '') !== $node->attrs['id'] || isset($ack->attrs['error'])) throw new ServiceException('message.publish',$ack->attrs['error'] ?? null,'message was not acknowledged');
        return ['id'=>$node->attrs['id'],'status'=>'server_ack','ack'=>$ack];
    }
    /**
     * @return array<string,mixed> */
    public function credentials(): array { return ($this->credentials)(); }
    /**
     * @param string|array<string,mixed> $content
     * @return array<string,mixed> */
    public function publish(string $jid,string|array $content): array
    {
        if ($this->publish === null) throw new \LogicException('Message publisher is not configured');
        return ($this->publish)($jid,$content);
    }
    /**
     * @return list<BinaryNode> */
    public static function children(?BinaryNode $node, ?string $tag = null): array
    {
        if (!is_array($node?->content)) return [];
        return array_values(array_filter($node->content, static fn(BinaryNode $n):bool=>$tag === null || $n->tag === $tag));
    }
    public static function text(?BinaryNode $node): ?string { return is_string($node?->content) ? $node->content : null; }
    public static function jid(string $jid, ?string $server = null): string
    {
        if (!preg_match('/^[^\s@]+@([^\s@]+)$/D',$jid,$m) || ($server !== null && $m[1] !== $server)) throw new \InvalidArgumentException('Invalid '.($server ?? '').' JID');
        return $jid;
    }
    public static function user(string $jid): string
    {
        if (preg_match('/^\+?[0-9 ()-]+$/D',$jid)) $jid = preg_replace('/\D/','',$jid).'@s.whatsapp.net';
        self::jid($jid); if (!preg_match('/@(s\.whatsapp\.net|lid)$/D',$jid)) throw new \InvalidArgumentException('Expected a user JID'); return $jid;
    }
    /**
     * @param list<string> $jids
     * @return list<BinaryNode> */
    public static function users(array $jids,string $tag='participant',?string $server=null): array
    {
        if ($jids === []) throw new \InvalidArgumentException('At least one JID is required');
        $nodes=[]; foreach (array_unique($jids) as $jid) $nodes[] = new BinaryNode($tag,['jid'=>self::jid($jid,$server)]); return $nodes;
    }
    /**
     * @param list<BinaryNode> $protocols
     * @param list<BinaryNode> $users
     * @return list<BinaryNode> */
    public function usync(array $protocols,array $users): array
    {
        $result=$this->usyncResult($protocols,$users);
        self::assertUsyncProtocols($result);
        return self::children($result->child('usync')?->child('list'),'user');
    }

    /** @param list<BinaryNode> $protocols
     * @param list<BinaryNode> $users */
    private function usyncResult(array $protocols,array $users): BinaryNode
    {
        if($this->privacyTokens!==null && array_filter($protocols,static fn(BinaryNode $p):bool=>in_array($p->tag,['status','picture'],true))!==[]) {
            $users=array_map(function(BinaryNode $u):BinaryNode {if($u->child('tctoken')!==null)return $u;$token=$this->privacyTokens?->resolveReceiverTokenNode($u->attrs['jid'] ?? '');return $token===null ? $u : new BinaryNode($u->tag,$u->attrs,[...self::children($u),$token]);},$users);
        }
        return $this->iq('usync','get','s.whatsapp.net','usync',[new BinaryNode('usync',['sid'=>bin2hex(random_bytes(12)),'index'=>'0','last'=>'true','mode'=>'query','context'=>'interactive'],[new BinaryNode('query',[],$protocols),new BinaryNode('list',[],$users)])]);
    }

    private static function assertUsyncProtocols(BinaryNode $result):void
    {
        foreach(self::children($result->child('usync')?->child('result'))as$protocol)if($protocol->child('error')!==null)throw new ServiceException('usync.'.$protocol->tag,$protocol->child('error')?->attrs['code']??null,'protocol rejected');
    }
    /** Resolves migrated phone users before privacy mutations.
     * @return array{jid:string,pn:?string,lid:?string} */
    public function userPair(string $input): array
    {
        $jid=self::user($input);
        if (str_ends_with($jid,'@lid')) return ['jid'=>$jid,'pn'=>null,'lid'=>$jid];
        $row=$this->resolveUserPairs([$input],false,true)[0];
        if($row['invalid'])throw new \InvalidArgumentException('Invalid phone recipient');
        return ['jid'=>$row['jid']??$jid,'pn'=>$row['pn'],'lid'=>$row['lid']];
    }

    /**
     * Resolve a batch without confusing reordered responses or canonical phone fixes.
     * A LID alone proves a mapping, never the contact protocol's existence result.
     * @param array<array-key,mixed> $inputs
     * `exists` stays null for an unanswered/error result, including a LID-only
     * mapping without a contact verdict; status never turns silence into `out`.
     * @return list<LidSyncResult>
     */
    public function userPairs(array $inputs,bool $strictPhone=true):array
    {
        return $this->resolveUserPairs($inputs,$strictPhone,false);
    }

    /** @param array<array-key,mixed> $inputs
     * @return list<LidSyncResult> */
    private function resolveUserPairs(array $inputs,bool $strictPhone,bool $throwProtocolErrors):array
    {
        $rows=[];$pending=[];
        foreach($inputs as$input){
            $raw=is_string($input)?trim($input):'';$phone=null;
            try{
                if(!is_string($input))throw new \InvalidArgumentException('Expected phone string');
                $address=Jid::recipient($raw)->canonical();
                if($address->server!=='s.whatsapp.net'||$address->device!==0||preg_match($strictPhone?'/^[1-9][0-9]{6,14}$/D':'/^[1-9][0-9]{0,14}$/D',$address->user)!==1)throw new \InvalidArgumentException('Expected phone number');
                $phone=$address->bare();
            }catch(\InvalidArgumentException){}
            $rows[]=['queriedJid'=>$phone??$raw,'phoneJid'=>$phone,'lidJid'=>null,'exists'=>$phone===null?false:null,'invalid'=>$phone===null,'jid'=>$phone,'pn'=>$phone,'lid'=>null,'status'=>$phone===null?'invalid':'unanswered'];
            if($phone!==null)$pending[$phone]=true;
        }
        $resolved=[];$unansweredErrors=[];
        foreach(array_chunk(array_keys($pending),200)as$chunk){
            $users=array_map(static fn(string $jid):BinaryNode=>new BinaryNode('user',['jid'=>$jid],[new BinaryNode('contact',[],'+'.Jid::parse($jid)->user)]),$chunk);
            $response=$this->usyncResult([new BinaryNode('contact'),new BinaryNode('lid')],$users);
            if($throwProtocolErrors)self::assertUsyncProtocols($response);
            $usync=$response->child('usync');
            if($usync===null||$usync->child('list')===null)throw new \UnexpectedValueException('LID sync response is missing its user list');
            $protocolError=self::lidSyncProtocolError($usync);
            if($protocolError!==null)foreach($chunk as$phone)$unansweredErrors[$phone]=$protocolError;
            foreach(self::children($usync->child('list'),'user')as$user){
                $contact=$user->child('contact');$echo=self::text($contact);$echoJid=null;$responseJid=null;
                try{if($echo!==null)$echoJid=self::phoneAddress($echo,$strictPhone);}catch(\InvalidArgumentException){}
                try{if(isset($user->attrs['pn_jid'])||isset($user->attrs['jid']))$responseJid=self::phoneAddress($user->attrs['pn_jid']??$user->attrs['jid'],$strictPhone);}catch(\InvalidArgumentException){}
                if($echo!==null&&trim($echo)!==''&&$echoJid===null)continue;
                if($echoJid!==null&&!in_array($echoJid,$chunk,true)&&$echoJid!==$responseJid)continue;
                // An echoed input takes precedence over the server-corrected PN.
                $queried=$echoJid!==null&&isset($pending[$echoJid])&&in_array($echoJid,$chunk,true)?$echoJid:($responseJid!==null&&in_array($responseJid,$chunk,true)?$responseJid:null);
                if($queried===null&&($echoJid===null||$echoJid===$responseJid)&&$responseJid!==null){$matches=array_values(array_filter($chunk,static fn(string $phone):bool=>self::brazilianAlias($phone,$responseJid)));if(count($matches)===1)$queried=$matches[0];}
                if($queried===null)continue;
                $canonical=$responseJid??$queried;
                try{if(isset($user->attrs['pn_jid']))$canonical=self::phoneAddress($user->attrs['pn_jid'],$strictPhone);}catch(\InvalidArgumentException){continue;}
                $contactError=$user->child('error')??$contact?->child('error');
                $error=$contactError!==null;
                $type=$contact?->attrs['type']??null;
                $exists=$error?null:match($type){'in'=>true,'out','invalid'=>false,default=>null};
                $invalid=!$error&&$type==='invalid';$lid=null;
                $lidNode=$user->child('lid');$rawLid=$lidNode?->attrs['val']??$lidNode?->attrs['jid']??null;
                $lidError=$lidNode?->child('error');
                if(!$error&&!$invalid&&$exists!==false&&$lidError===null&&is_string($rawLid)){
                    try{$address=Jid::parse(str_contains($rawLid,'@')?$rawLid:$rawLid.'@lid')->canonical();if($address->server==='lid'&&$address->device===0&&ctype_digit($address->user))$lid=$address->bare();}catch(\InvalidArgumentException){}
                }
                $status=$error?'error':match($type){'in'=>'in','out'=>'out','invalid'=>'invalid',default=>$lidError===null?'unanswered':'error'};
                $ownError=$contactError??(in_array($status,['in','error'],true)?$lidError:null);
                $details=$ownError===null?null:self::lidSyncError($ownError);
                if($details===null&&in_array($status,['error','unanswered'],true))$details=$protocolError;
                $value=['queriedJid'=>$queried,'phoneJid'=>$canonical,'lidJid'=>$lid,'exists'=>$exists,'invalid'=>$invalid,'jid'=>$lid??$canonical,'pn'=>$canonical,'lid'=>$lid,'status'=>$status];
                if($details!==null)$value['error']=$details;
                // Ambiguous duplicate rows are not a source of trusted mappings.
                if(array_key_exists($queried,$resolved)&&$resolved[$queried]!==$value){$resolved[$queried]=null;continue;}
                $resolved[$queried]=$value;
            }
        }
        foreach($resolved as$queried=>$value)if($value!==null&&$value['lidJid']!==null){
            $this->identities?->confirmPair($queried,$value['phoneJid'],$value['lidJid']);
        }
        foreach($rows as&$row){
            if(isset($resolved[$row['queriedJid']]))$row=$resolved[$row['queriedJid']];
            elseif($row['status']==='unanswered'&&isset($unansweredErrors[$row['queriedJid']]))$row['error']=$unansweredErrors[$row['queriedJid']];
        }
        unset($row);return $rows;
    }

    /** @return LidSyncError|null */
    private static function lidSyncProtocolError(BinaryNode $usync):?array
    {
        foreach(['contact','lid']as$tag){
            $error=$usync->child('result')?->child($tag)?->child('error');
            if($error!==null)return self::lidSyncError($error,true);
        }
        return null;
    }

    /** @return LidSyncError|null */
    private static function lidSyncError(BinaryNode $error,bool $includeBackoff=false):?array
    {
        $out=[];
        foreach(['code','text']as$name)if(isset($error->attrs[$name]))$out[$name]=$error->attrs[$name];
        if($includeBackoff&&isset($error->attrs['backoff'])&&preg_match('/^[0-9]+$/D',$error->attrs['backoff'])===1){
            $digits=ltrim($error->attrs['backoff'],'0');
            $value=filter_var($digits===''?'0':$digits,FILTER_VALIDATE_INT,['options'=>['min_range'=>0]]);
            if($value!==false)$out['backoffSeconds']=$value;
        }
        return $out===[]?null:$out;
    }

    private static function phoneAddress(string $input,bool $strict=true):string
    {
        $jid=Jid::recipient($input)->canonical();
        if($jid->server!=='s.whatsapp.net'||$jid->device!==0||preg_match($strict?'/^[1-9][0-9]{6,14}$/D':'/^[1-9][0-9]{0,14}$/D',$jid->user)!==1)throw new \InvalidArgumentException('Invalid phone JID');
        return $jid->bare();
    }

    /** Correlate only a canonical PN actually returned by the server; never add a digit locally. */
    private static function brazilianAlias(string $query,string $canonical):bool
    {
        $a=Jid::parse($query)->user;$b=Jid::parse($canonical)->user;
        return strlen($a)===12&&strlen($b)===13&&str_starts_with($a,'55')&&substr($a,0,4)===substr($b,0,4)&&$b[4]==='9'&&substr($a,4)===substr($b,5);
    }
}
