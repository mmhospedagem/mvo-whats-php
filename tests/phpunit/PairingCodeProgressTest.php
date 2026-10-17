<?php
declare(strict_types=1);

namespace MVO\Tests;

use MVO\Auth\PairingFlow;
use MVO\Crypto\{Crypto,XEdDsa};
use MVO\Protocol\{BinaryNode,Protobuf};
use PHPUnit\Framework\TestCase;

final class PairingCodeProgressTest extends TestCase
{
    private array $credentials;
    private array $events = [];
    private array $queries = [];
    private bool $rejectFinish = false;
    private ?\Closure $onProgress = null;
    private ?\Closure $onFinish = null;

    protected function setUp(): void
    {
        $this->credentials = ['noiseKeyPair'=>Crypto::x25519KeyPair(),'identityKeyPair'=>Crypto::x25519KeyPair(),'advSecretKey'=>random_bytes(32),'registered'=>false,'meJid'=>null];
    }

    private function flow(): PairingFlow
    {
        return new PairingFlow(
            fn():array=>$this->credentials,
            function(array $credentials):void{$this->credentials=$credentials;},
            static function(BinaryNode $node):void{},
            function(BinaryNode $node,float $timeout):BinaryNode{
                $this->queries[]=$node;
                if (($node->child('link_code_companion_reg')?->attrs['stage']??'')==='companion_finish') {
                    $this->onFinish?->__invoke();
                    return new BinaryNode('iq',['type'=>$this->rejectFinish?'error':'result']);
                }
                return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('link_code_companion_reg',[],[new BinaryNode('link_code_pairing_ref',[],'private-pairing-ref')])]);
            },
            function(string $event,array $data):void{
                if($event==='auth_code_progress'){$this->events[]=$data;$this->onProgress?->__invoke($data);}
            },
        );
    }

    private function hello(string $ref='private-pairing-ref'): BinaryNode
    {
        $primary=Crypto::x25519KeyPair();$identity=Crypto::x25519KeyPair();$salt=random_bytes(32);$counter=random_bytes(16);
        $key=hash_pbkdf2('sha256','ABCD1234',$salt,131072,32,true);
        $wrapped=$salt.$counter.openssl_encrypt($primary['public'],'aes-256-ctr',$key,OPENSSL_RAW_DATA,$counter);
        return new BinaryNode('notification',['id'=>'private-notification','from'=>'s.whatsapp.net'],[new BinaryNode('link_code_companion_reg',['stage'=>'primary_hello'],[
            new BinaryNode('link_code_pairing_ref',[],$ref),new BinaryNode('link_code_pairing_wrapped_primary_ephemeral_pub',[],$wrapped),new BinaryNode('primary_identity_pub',[],$identity['public']),
        ])]);
    }

    private function pairSuccess(bool $invalid=false): BinaryNode
    {
        $account=Crypto::x25519KeyPair();
        $details=Protobuf::encode([['number'=>1,'wire'=>0,'value'=>9],['number'=>3,'wire'=>0,'value'=>19]]);
        $signature=XEdDsa::sign($account['private'],"\x06\0".$details.$this->credentials['identityKeyPair']['public']);
        $identity=Protobuf::encode([['number'=>1,'wire'=>2,'value'=>$details],['number'=>2,'wire'=>2,'value'=>$account['public']],['number'=>3,'wire'=>2,'value'=>$signature]]);
        $hmac=Crypto::hmac($this->credentials['advSecretKey'],$identity);if($invalid)$hmac[0]=$hmac[0]^"\x01";
        $wrapped=Protobuf::encode([['number'=>1,'wire'=>2,'value'=>$identity],['number'=>2,'wire'=>2,'value'=>$hmac]]);
        return new BinaryNode('iq',['id'=>'private-pair-success','type'=>'set'],[new BinaryNode('pair-success',[],[
            new BinaryNode('device-identity',[],$wrapped),new BinaryNode('device',['jid'=>'5511000000000:7@s.whatsapp.net']),new BinaryNode('platform',['name'=>'android']),
        ])]);
    }

    public function testMatchedCodeReportsOnlySafeStagesAndAttempts(): void
    {
        $flow=$this->flow();$flow->requestCode('5511000000000','ABCD1234');$flow->handleNode($this->hello());$flow->handleNode($this->pairSuccess());
        self::assertSame([
            ['stage'=>'primary_hello_received','attempt'=>1],['stage'=>'companion_finish_sent','attempt'=>1],['stage'=>'companion_finish_accepted','attempt'=>1],
            ['stage'=>'pair_success_received'],['stage'=>'pair_success_validated'],
        ],$this->events);
        $json=json_encode($this->events,JSON_THROW_ON_ERROR);
        foreach(['5511000000000','ABCD1234','private-pairing-ref','private-notification','identityKeyPair',base64_encode($this->credentials['identityKeyPair']['public'])]as$secret)self::assertStringNotContainsString($secret,$json);
        self::assertTrue($this->credentials['registered']);self::assertCount(2,$this->queries);
    }

    public function testQrPairingDoesNotPublishCodeProgress(): void
    {
        $flow=$this->flow();$flow->handleNode($this->pairSuccess());self::assertSame([],$this->events);self::assertTrue($this->credentials['registered']);
    }

    public function testUnrelatedAndExpiredPrimaryHelloPublishNothing(): void
    {
        $flow=$this->flow();$flow->requestCode('5511000000000','ABCD1234');$flow->handleNode($this->hello('unrelated'));
        $flow->tick(microtime(true)+181);$flow->handleNode($this->hello());self::assertSame([],$this->events);self::assertCount(1,$this->queries);
    }

    public function testRejectedFinishCannotReportAcceptedOrValidated(): void
    {
        $flow=$this->flow();$flow->requestCode('5511000000000','ABCD1234');$this->rejectFinish=true;
        try{$flow->handleNode($this->hello());self::fail('Expected server rejection');}catch(\UnexpectedValueException){}
        self::assertSame(['primary_hello_received','companion_finish_sent'],array_column($this->events,'stage'));self::assertFalse($this->credentials['registered']);
    }

    public function testInvalidPairSuccessCannotReportValidated(): void
    {
        $flow=$this->flow();$flow->requestCode('5511000000000','ABCD1234');
        try{$flow->handleNode($this->pairSuccess(true));self::fail('Expected failed identity validation');}catch(\UnexpectedValueException){}
        self::assertSame([['stage'=>'pair_success_received']],$this->events);self::assertFalse($this->credentials['registered']);
    }

    public function testProgressListenerCanClearTheFlowWithoutIssuingAFinishQuery(): void
    {
        foreach(['primary_hello_received','companion_finish_sent']as$stopStage){
            $this->events=[];$this->queries=[];$flow=$this->flow();$flow->requestCode('5511000000000','ABCD1234');
            $this->onProgress=static function(array $data)use($flow,$stopStage):void{if($data['stage']===$stopStage)$flow->clear();};
            $flow->handleNode($this->hello());self::assertCount(1,$this->queries);self::assertFalse($this->credentials['registered']);$this->onProgress=null;
        }
    }

    public function testReentrantSuccessKeepsItsCodeContextWhenSessionIsCleared(): void
    {
        $flow=$this->flow();$flow->requestCode('5511000000000','ABCD1234');$this->onFinish=function()use($flow):void{$flow->handleNode($this->pairSuccess());};
        $flow->handleNode($this->hello());
        self::assertSame(['primary_hello_received','companion_finish_sent','pair_success_received','pair_success_validated','companion_finish_accepted'],array_column($this->events,'stage'));
        self::assertTrue($this->credentials['registered']);
    }

    public function testSuccessProgressListenerCanCancelBeforeCredentialsAreCommitted(): void
    {
        foreach(['pair_success_received','pair_success_validated']as$stopStage){
            $this->events=[];$flow=$this->flow();$flow->requestCode('5511000000000','ABCD1234');
            $this->onProgress=static function(array $data)use($flow,$stopStage):void{if($data['stage']===$stopStage)$flow->clear();};
            $flow->handleNode($this->pairSuccess());self::assertFalse($this->credentials['registered']);self::assertNull($this->credentials['meJid']);$this->onProgress=null;
        }
    }
}
