<?php
declare(strict_types=1);
namespace MVO\Testing;

use MVO\Crypto\{Crypto,XEdDsa};
use MVO\Mobile\CompanionCrypto;
use MVO\Protocol\{BinaryNode,Jid,SchemaCodec};

/** Local primary simulator: verifies registration, QR/code binding and the signed ADV response. */
final class FakePairing
{
    /** @var array<string,mixed>|null */
    private ?array $registration=null;
    /** @var list<string> */
    private array $refs=[];
    /** @var array<string,mixed>|null */
    private ?array $code=null;
    /** @var array<string,mixed>|null */
    private ?array $issued=null;
    /** @var array<string,mixed>|null */
    private ?array $completed=null;
    private float $expires=0;
    /** @param array<string,mixed> $primaryCredentials
     * @param \Closure():float|null $clock */
    public function __construct(private readonly array $primaryCredentials,private int $deviceIndex=1,private readonly ?\Closure $clock=null)
    {
        if($deviceIndex<1 || $deviceIndex>1024)throw new \InvalidArgumentException('Invalid fake companion device index');$identity=$primaryCredentials['identityKeyPair']??null;if(!is_array($identity) || !is_string($identity['private']??null) || !is_string($identity['public']??null))throw new \InvalidArgumentException('Fake pairing requires a primary identity keypair');Crypto::length($identity['private'],32);Crypto::length($identity['public'],32);if(!hash_equals(sodium_crypto_scalarmult_base($identity['private']),$identity['public']))throw new \InvalidArgumentException('Inconsistent fake primary identity');$jid=Jid::parse($primaryCredentials['meJid']??'');if($jid->server!=='s.whatsapp.net' || $jid->device!==0)throw new \InvalidArgumentException('Fake primary must have a primary phone JID');
    }
    /** @return list<BinaryNode> */
    public function start(string $registrationPayload,string $clientStatic):array
    {
        Crypto::length($clientStatic,32);$payload=SchemaCodec::decode('ClientPayload',$registrationPayload);$data=$payload['devicePairingData']??null;if(!is_array($data) || isset($payload['username']))throw new \UnexpectedValueException('Fake pairing requires registration ClientPayload');
        $identity=self::bytes($data['eIdent']??null,32);$signed=self::bytes($data['eSkeyVal']??null,32);$signature=self::bytes($data['eSkeySig']??null,64);$reg=unpack('N',self::bytes($data['eRegid']??null,4))[1];$signedId=unpack('N',"\0".self::bytes($data['eSkeyId']??null,3))[1];if($reg<1 || $reg>16380 || $signedId<1 || ($data['eKeytype']??null)!=="\x05" || !XEdDsa::verify($identity,"\x05".$signed,$signature))throw new \UnexpectedValueException('Fake registration prekey proof is invalid');
        $this->registration=['noiseKeyPair'=>['public'=>$clientStatic],'identityKeyPair'=>['public'=>$identity],'registrationId'=>$reg,'signedPreKey'=>['id'=>$signedId,'public'=>$signed,'signature'=>$signature]];$this->refs=[];for($i=0;$i<6;$i++)$this->refs[]='fake-'.bin2hex(random_bytes(16));$this->code=$this->issued=$this->completed=null;$this->expires=$this->now()+180;
        return [new BinaryNode('iq',['from'=>'s.whatsapp.net','type'=>'set','id'=>'PAIR-'.bin2hex(random_bytes(8))],[new BinaryNode('pair-device',[],array_map(static fn($ref)=>new BinaryNode('ref',[],$ref),$this->refs))])];
    }
    /** Scanning consumes only a QR issued by this authenticated registration connection. */
    public function scanQr(string $qr):BinaryNode
    {
        $registration=$this->active();if($this->issued!==null)throw new \LogicException('A pairing proof is already awaiting device signature');$data=CompanionCrypto::parseQr($qr);if(!in_array($data['ref'],$this->refs,true) || !hash_equals($registration['noiseKeyPair']['public'],$data['noisePublicKey']) || !hash_equals($registration['identityKeyPair']['public'],$data['identityPublicKey']))throw new \UnexpectedValueException('Fake QR is not bound to the registered Noise/identity keys');$this->refs=[];return $this->pairSuccess($data['advSecretKey']);
    }
    /** Simulates the primary entering the code; no code is accepted without its cryptographic finish. */
    public function approveCode(string $code):BinaryNode
    {
        $this->active();if($this->code===null || isset($this->code['shared']))throw new \LogicException('No fresh companion code hello is pending');$hello=CompanionCrypto::preparePrimaryHello($code,$this->code['wrapped']);$this->code['shared']=$hello['sharedEphemeral'];return new BinaryNode('notification',['from'=>'s.whatsapp.net','type'=>'link_code_companion_reg','id'=>'PRIMARY-'.bin2hex(random_bytes(8))],[new BinaryNode('link_code_companion_reg',['stage'=>'primary_hello'],[new BinaryNode('link_code_pairing_ref',[],$this->code['ref']),new BinaryNode('link_code_pairing_wrapped_primary_ephemeral_pub',[],$hello['wrappedPrimaryEphemeralPub']),new BinaryNode('primary_identity_pub',[],$this->primaryCredentials['identityKeyPair']['public'])])]);
    }
    /** null means the request belongs to another fake protocol service.
     * @return list<BinaryNode>|null */
    public function handle(BinaryNode $node):?array
    {
        $link=$node->child('link_code_companion_reg');if($node->tag==='iq' && $link!==null){$registration=$this->active();if(($node->attrs['type']??null)!=='set' || ($node->attrs['xmlns']??null)!=='md' || ($link->attrs['jid']??null)!==Jid::parse($this->primaryCredentials['meJid'])->bare())throw new \UnexpectedValueException('Invalid fake companion code account/envelope');$stage=$link->attrs['stage']??'';
            if($stage==='companion_hello'){if($this->issued!==null)throw new \LogicException('Device signature is already pending');$noise=self::bytes($link->child('companion_server_auth_key_pub')?->content,32);if(!hash_equals($registration['noiseKeyPair']['public'],$noise))throw new \UnexpectedValueException('Code hello changed authenticated Noise identity');$this->code=['wrapped'=>self::bytes($link->child('link_code_pairing_wrapped_companion_ephemeral_pub')?->content,80),'ref'=>'code-'.bin2hex(random_bytes(16))];$this->refs=[];return [self::reply($node,[new BinaryNode('link_code_companion_reg',[],[new BinaryNode('link_code_pairing_ref',[],$this->code['ref'])])])];}
            if($stage==='companion_finish'){if($this->code===null || !isset($this->code['shared']) || $this->issued!==null)throw new \LogicException('Primary code approval has not been made');$ref=$link->child('link_code_pairing_ref')?->content;$identity=self::bytes($link->child('companion_identity_public')?->content,32);if(!is_string($ref) || !hash_equals($this->code['ref'],$ref) || !hash_equals($registration['identityKeyPair']['public'],$identity))throw new \UnexpectedValueException('Companion code finish changed ref or identity');$adv=CompanionCrypto::completePrimary($this->code['shared'],self::bytes($link->child('link_code_pairing_wrapped_key_bundle')?->content,156),$identity,$this->primaryCredentials['identityKeyPair']);$this->code=null;return [self::reply($node),$this->pairSuccess($adv)];}
            throw new \UnexpectedValueException('Unsupported fake pairing code stage');
        }
        $response=$node->child('pair-device-sign');if($node->tag==='iq' && $response!==null){$registration=$this->active();if($this->issued===null || ($node->attrs['type']??null)!=='result' || ($node->attrs['id']??null)!==$this->issued['id'])throw new \UnexpectedValueException('Unexpected fake ADV device response');$identity=$response->child('device-identity');if($identity===null || ($identity->attrs['key-index']??null)!==(string)$this->deviceIndex || !is_string($identity->content))throw new \UnexpectedValueException('Fake ADV response key index is invalid');$signed=SchemaCodec::decode('ADVSignedDeviceIdentity',$identity->content);$issued=$this->issued['signed'];foreach(['details','accountSignature'] as $field)if(!is_string($signed[$field]??null) || !hash_equals($issued[$field],$signed[$field]))throw new \UnexpectedValueException('Fake ADV response changed signed account details');$signature=self::bytes($signed['deviceSignature']??null,64);$public=$registration['identityKeyPair']['public'];if(!XEdDsa::verify($public,"\x06\x01".$issued['details'].$public.$this->primaryCredentials['identityKeyPair']['public'],$signature))throw new \UnexpectedValueException('Fake ADV companion signature is invalid');$signed['accountSignatureKey']=$this->primaryCredentials['identityKeyPair']['public'];$this->completed=[...$registration,'registered'=>true,'meJid'=>$this->issued['jid'],'meLid'=>$this->issued['lid'],'signedIdentity'=>$signed];$this->issued=null;$this->refs=[];$this->code=null;++$this->deviceIndex;return [new BinaryNode('stream:error',['code'=>'515'])];}
        return null;
    }
    /** Public server registry entry, never private companion key material.
     * @return array<string,mixed>|null */
    public function completeCredentials():?array { return $this->completed; }
    private function pairSuccess(string $adv):BinaryNode
    {
        $registration=$this->active();$signed=CompanionCrypto::signedCompanion($this->primaryCredentials['identityKeyPair'],$registration['identityKeyPair']['public'],$adv,12345,$this->deviceIndex,(int)$this->now(),[0,$this->deviceIndex]);$wrapped=SchemaCodec::decode('ADVSignedDeviceIdentityHMAC',$signed['deviceIdentityBytes']);$identity=SchemaCodec::decode('ADVSignedDeviceIdentity',$wrapped['details']);$phone=Jid::parse($this->primaryCredentials['meJid']);$jid=$phone->user.':'.$this->deviceIndex.'@s.whatsapp.net';$lid=is_string($this->primaryCredentials['meLid']??null)?Jid::parse($this->primaryCredentials['meLid'])->user.':'.$this->deviceIndex.'@lid':null;$id='SUCCESS-'.bin2hex(random_bytes(8));$this->issued=['id'=>$id,'jid'=>$jid,'lid'=>$lid,'signed'=>$identity];return new BinaryNode('iq',['from'=>'s.whatsapp.net','type'=>'set','id'=>$id],[new BinaryNode('pair-success',[],[new BinaryNode('device',array_filter(['jid'=>$jid,'lid'=>$lid],static fn($value)=>$value!==null)),new BinaryNode('platform',['name'=>'android']),new BinaryNode('device-identity',[],$signed['deviceIdentityBytes'])])]);
    }
    /** @return array<string,mixed> */
    private function active():array { if($this->registration===null || $this->completed!==null)throw new \LogicException('No pending fake registration');if($this->now()>=$this->expires)throw new \UnexpectedValueException('Fake registration expired');return $this->registration; }
    /** @param list<BinaryNode>|null $children */
    private static function reply(BinaryNode $request,?array $children=null):BinaryNode { return new BinaryNode('iq',['from'=>'s.whatsapp.net','type'=>'result','id'=>$request->attrs['id']??''],$children); }
    private static function bytes(mixed $bytes,int $length):string { if(!is_string($bytes))throw new \UnexpectedValueException('Missing fake pairing binary field');Crypto::length($bytes,$length);return $bytes; }
    private function now():float { return $this->clock===null?microtime(true):($this->clock)(); }
}
