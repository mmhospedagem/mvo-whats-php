<?php
declare(strict_types=1);
namespace MVO\Auth;

use MVO\Crypto\Crypto;
use MVO\Protocol\{BinaryNode,SchemaCodec};

/** Companion-side Shortcake passkey handshake. The WebAuthn authenticator is supplied by the application. */
final class ShortcakeFlow
{
    public const TTL_SECONDS=300;
    /** @var array<string,mixed>|null */
    private ?array $session=null;
    /** @var array{key:string,createdAt:float}|null */
    private ?array $handoff=null;
    /** @var list<array{node:BinaryNode,createdAt:float,notified:bool}> */
    private array $pending=[];
    /** @var array<string,true> */
    private array $seen=[];
    /** @param \Closure():array<string,mixed> $getCredentials
     * @param \Closure(array<string,mixed>):void $saveCredentials
     * @param \Closure(BinaryNode):void $sendNode
     * @param \Closure(BinaryNode,float):BinaryNode $query
     * @param \Closure(string,array<string,mixed>):void $emit
     * @param \Closure(string):array{credentialId:string,webauthnAssertion:string}|null $signAssertion
     * @param \Closure():float|null $clock */
    public function __construct(
        private readonly \Closure $getCredentials,
        private readonly \Closure $saveCredentials,
        private readonly \Closure $sendNode,
        private readonly \Closure $query,
        private readonly \Closure $emit,
        private ?\Closure $signAssertion=null,
        private readonly int $deviceType=1,
        private readonly bool $autoConfirm=true,
        private readonly ?\Closure $clock=null,
    ) { if($deviceType<0)throw new \InvalidArgumentException('Invalid Shortcake platform'); }

    public function hasSession(): bool { return $this->session!==null; }
    public function getVerificationCode(): ?string { return $this->session['verificationCode']??null; }
    public function stage(): string { return $this->session['stage']??'idle'; }
    public function clear(): void { $this->session=null;$this->handoff=null;$this->pending=[];$this->seen=[]; }
    /** @param \Closure(string):array{credentialId:string,webauthnAssertion:string} $signer */
    public function setSigner(\Closure $signer): void { $this->signAssertion=$signer; }

    /** Fetches server request options and asks the application's authenticator to produce an assertion. */
    public function requestPasskeyPairing(?string $requestOptions=null,?string $handoffProof=null): void
    {
        if(($this->getCredentials)()['registered']??false)throw new \LogicException('Device is already registered');
        if($this->signAssertion===null)throw new \LogicException('Passkey pairing requires an external WebAuthn assertion signer');
        $requestOptions??=self::content($this->request('get','passkey_request_options')->child('passkey_request_options'),'passkey request options');
        if(strlen($requestOptions)>1048576)throw new \LengthException('Shortcake request options exceed 1 MiB');
        $assertion=($this->signAssertion)($requestOptions);
        foreach(['credentialId','webauthnAssertion'] as $field)if(!is_string($assertion[$field]??null) || $assertion[$field]==='' || strlen($assertion[$field])>1048576)throw new \UnexpectedValueException('Shortcake signer returned invalid '.$field);
        $ref=self::content($this->request('get','ref')->child('ref'),'pairing ref');
        $companion=ShortcakeCrypto::companion($ref,$this->deviceType);
        $handoff=$this->handoff;$this->handoff=null;
        if($handoffProof===null && $handoff!==null && $this->now()-$handoff['createdAt']<self::TTL_SECONDS)$handoffProof=Crypto::hmac($handoff['key'],$companion['prologuePayloadBytes']);
        if($handoffProof!==null)Crypto::length($handoffProof,32);
        $children=[new BinaryNode('credential_id',[],$assertion['credentialId']),new BinaryNode('webauthn_assertion',[],$assertion['webauthnAssertion']),new BinaryNode('prologue_payload',[],$companion['prologuePayloadBytes'])];
        if($handoffProof!==null)$children[]=new BinaryNode('pairing_handoff_proof',[],$handoffProof);
        $this->request('set','passkey_prologue',$children);
        $this->session=['companion'=>$companion,'ref'=>$ref,'stage'=>'waiting_for_primary_identity','encryptionKey'=>null,'verificationCode'=>null,'createdAt'=>$this->now()];
        ($this->emit)('auth_passkey_prologue',['skipHandoffUx'=>$handoffProof!==null]);
    }

    /** Server notifications are acknowledged and deferred; no signer/query is called during node dispatch. */
    public function handleNode(BinaryNode $node): bool
    {
        if($node->tag!=='notification' || !in_array($node->attrs['type']??'',['passkey_prologue_request','crsc_continuation'],true))return false;
        if(($node->attrs['from']??'')!=='s.whatsapp.net')throw new \UnexpectedValueException('Shortcake notifications must come from the server');
        $id=$node->attrs['id']??'';
        if($id==='')throw new \UnexpectedValueException('Shortcake notification missing ID');
        ($this->sendNode)(new BinaryNode('ack',['to'=>'s.whatsapp.net','class'=>'notification','type'=>$node->attrs['type'],'id'=>$id]));
        $key=$node->attrs['type']."\0".$id;
        if(isset($this->seen[$key]))return true;
        if(count($this->pending)>=16)throw new \LengthException('Shortcake notification queue is full');
        $this->seen[$key]=true;
        if(count($this->seen)>64)array_shift($this->seen);
        $this->pending[]=['node'=>$node,'createdAt'=>$this->now(),'notified'=>false];
        return true;
    }

    /** Run from the outer event loop, after incoming node handling returns. */
    public function drainPending(): void
    {
        $this->tick();
        while($this->pending!==[]) {
            $job=$this->pending[0];$node=$job['node'];
            if($this->now()-$job['createdAt']>=self::TTL_SECONDS){array_shift($this->pending);($this->emit)('auth_passkey_expired',[]);continue;}
            if($node->attrs['type']==='passkey_prologue_request' && $this->signAssertion===null) {
                if(!$job['notified']) { $this->pending[0]['notified']=true;($this->emit)('auth_passkey_required',['requestOptions'=>$node->child('passkey_request_options')?->content]); }
                return;
            }
            array_shift($this->pending);
            try {
                if($node->attrs['type']==='passkey_prologue_request') {
                    $this->rotateAdvForHandoff();
                    $options=$node->child('passkey_request_options');
                    $this->requestPasskeyPairing($options===null?null:self::content($options,'passkey request options'));
                }else $this->continueWithPrimary($node);
            }catch(\Throwable $error) {
                $this->session=null;$this->handoff=null;
                ($this->emit)('auth_passkey_error',['error'=>$error->getMessage()]);
                throw $error;
            }
        }
    }

    public function confirmVerificationCode(): void
    {
        $this->tick();$session=$this->session;
        if($session===null || $session['stage']!=='waiting_for_confirmation' || !is_string($session['encryptionKey']))throw new \LogicException('No Shortcake verification code awaiting confirmation');
        $credentials=($this->getCredentials)();
        $noise=$credentials['noiseKeyPair']['public']??null;$identity=$credentials['identityKeyPair']['public']??null;$adv=$credentials['advSecretKey']??null;
        foreach([$noise,$identity,$adv] as $key)if(!is_string($key) || strlen($key)!==32)throw new \UnexpectedValueException('Shortcake credentials are not initialized');
        $plain=SchemaCodec::encode('PairingRequest',['companionPublicKey'=>$noise,'companionIdentityKey'=>$identity,'advSecret'=>$adv]);
        $envelope=ShortcakeCrypto::encryptPairingRequest($session['encryptionKey'],$plain);
        $this->request('set','encrypted_pairing_request',$envelope);
        $this->session['stage']='waiting_for_pairing';
        ($this->emit)('auth_passkey_pairing_requested',[]);
    }

    public function tick(?float $now=null): void
    {
        $now??=$this->now();
        if($this->session!==null && $now-$this->session['createdAt']>=self::TTL_SECONDS){$this->session=null;($this->emit)('auth_passkey_expired',[]);}
        if($this->handoff!==null && $now-$this->handoff['createdAt']>=self::TTL_SECONDS)$this->handoff=null;
    }
    private function continueWithPrimary(BinaryNode $node): void
    {
        $session=$this->session;
        if($session===null || $session['stage']!=='waiting_for_primary_identity')return;
        $primary=ShortcakeCrypto::decodePrimary(self::content($node->child('primary_ephemeral_identity'),'primary ephemeral identity'));
        $key=ShortcakeCrypto::encryptionKey($session['companion']['keyPair']['private'],$primary['publicKey'],$this->deviceType,$session['ref']);
        $this->request('set','companion_nonce',$session['companion']['companionNonce']);
        $code=ShortcakeCrypto::verificationCode($session['companion']['companionNonce'],$primary);
        $this->session['encryptionKey']=$key;$this->session['verificationCode']=$code;$this->session['stage']='waiting_for_confirmation';
        ($this->emit)('auth_verification_code',['code'=>$code]);
        if($this->autoConfirm)$this->confirmVerificationCode();
    }
    private function rotateAdvForHandoff(): void
    {
        $credentials=($this->getCredentials)();$adv=$credentials['advSecretKey']??null;
        if(!is_string($adv) || strlen($adv)!==32)throw new \UnexpectedValueException('Shortcake ADV secret is missing');
        $key=ShortcakeCrypto::handoffKey($adv);$credentials['advSecretKey']=random_bytes(32);
        ($this->saveCredentials)($credentials);
        $this->handoff=['key'=>$key,'createdAt'=>$this->now()];
    }
    /** @param list<BinaryNode>|string|null $content */
    private function request(string $type,string $tag,array|string|null $content=null): BinaryNode
    {
        $response=($this->query)(new BinaryNode('iq',['to'=>'s.whatsapp.net','xmlns'=>'md','type'=>$type],[new BinaryNode($tag,[],$content)]),30.0);
        if($response->tag!=='iq' || ($response->attrs['type']??'')!=='result')throw new \RuntimeException('Shortcake '.$tag.' request failed');
        return $response;
    }
    private static function content(?BinaryNode $node,string $name): string { if($node===null || !is_string($node->content) || $node->content==='')throw new \UnexpectedValueException('Shortcake missing '.$name);return $node->content; }
    private function now(): float { return $this->clock===null?microtime(true):($this->clock)(); }
}
