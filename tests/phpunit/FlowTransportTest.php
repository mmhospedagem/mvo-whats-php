<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Message\{ContentBuilder,ContentDecoder,MessageCodec,MessageService};
use MVO\Protocol\{BinaryCodec,BinaryData,BinaryNode,Jid,SchemaCodec};
use MVO\Signal\{SignalProtocol,SignalStore};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** The public Flow contract must survive Signal fanout, durable retry and incoming decryption. */
final class FlowTransportTest extends TestCase
{
    /** @var list<string> */
    private array $paths=[];
    /** @var array<string,mixed> */
    private array $credentials;
    private SignalStore $store;
    private SignalProtocol $signal;
    /** @var list<array{string,array<string,mixed>}> */
    private array $events=[];
    /** @var list<BinaryNode> */
    private array $sent=[];

    protected function setUp():void
    {
        $this->credentials=self::credentials('5511000000000:5@s.whatsapp.net');
        $this->store=new SignalStore($this->path());
        $this->signal=new SignalProtocol($this->store,$this->credentials);
    }

    protected function tearDown():void
    {
        unset($this->signal,$this->store);
        gc_collect_cycles();
        foreach($this->paths as $path) foreach([$path,$path.'-wal',$path.'-shm'] as $file) if(is_file($file)) @unlink($file);
    }

    /** @return iterable<string,array{array<string,mixed>,string,?string}> */
    public static function flows():iterable
    {
        yield 'flow with initial form data'=>[
            ['type'=>'flow','flow_action'=>'navigate','screen'=>'FORM','data'=>['customer'=>'Maik','quantity'=>2]],
            'navigate','FORM',
        ];
        yield 'flow navigate with an empty object'=>[
            ['type'=>'flow-navigate','screen'=>'WELCOME','data'=>[]],
            'navigate','WELCOME',
        ];
        yield 'flow exchange without navigation payload'=>[
            ['type'=>'flow-exchange'],
            'data_exchange',null,
        ];
        yield 'registered navigate opts into verified endpoint metadata'=>[
            ['type'=>'flow-navigate','data_api_version'=>'3.0','screen'=>'WELCOME','data'=>[]],
            'navigate','WELCOME',
        ];
        foreach(['flow','flow-navigate']as$type)yield $type.' with inline JSON and inferred first screen'=>[
            ['type'=>$type,'flow_json'=>json_decode('{"version":"7.3","routing_model":{"INICIO":[]},"screens":[{"id":"INICIO","terminal":true,"success":true,"data":{},"layout":{"type":"SingleColumnLayout","children":[{"type":"TextHeading","text":"Cadastro MVO"},{"type":"Form","name":"cadastro","children":[{"type":"TextInput","name":"nome","label":"Nome","required":true},{"type":"Footer","label":"Concluir","on-click-action":{"name":"complete","payload":{"nome":"${form.nome}"}}}]}]}}]}',false,128,JSON_THROW_ON_ERROR),
                'data'=>(object)['empty'=>(object)[],'answers'=>[(object)['name'=>'Maik']]]],
            'navigate','INICIO',
        ];
    }

    #[DataProvider('flows')]
    public function testEncryptedFlowFanoutAndExactRetrySurviveRestart(array $variant,string $action,?string $screen):void
    {
        $jid='5522000000000@s.whatsapp.net';
        $own=Jid::parse($this->credentials['meJid'])->bare();
        $peers=$this->peers([$jid,$own]);
        $wire=null;$id=null;$secret=null;$attempt=0;$seen=[];$inlineId=null;
        $query=function(BinaryNode $node)use($jid,$own,$peers,$variant,$action,$screen,&$wire,&$id,&$secret,&$attempt,&$seen,&$inlineId):BinaryNode {
            if($node->tag==='iq' && ($node->attrs['xmlns']??'')==='usync') return self::devices([$jid=>[0],$own=>[0,5]]);
            if($node->tag==='iq') return self::bundles($peers);
            self::assertSame('message',$node->tag);
            self::assertSame('false',$node->attrs['device_fanout']);
            self::assertSame('text',$node->attrs['type']);
            $biz=$node->child('biz');
            self::assertNotNull($biz);
            self::assertSame('2',$biz->attrs['actual_actors']);
            self::assertSame('2',$biz->attrs['host_storage']);
            self::assertMatchesRegularExpression('/^[0-9]+$/D',$biz->attrs['privacy_mode_ts']);
            self::assertCount(1,$biz->content);
            $interactive=$biz->child('interactive');
            self::assertNotNull($interactive);
            self::assertEquals(['v'=>'1','type'=>'native_flow'],$interactive->attrs);
            self::assertCount(1,$interactive->content);
            self::assertSame(['v'=>'9','name'=>'mixed'],$interactive->child('native_flow')->attrs);
            self::assertNull($biz->child('quality_control'));
            $encoded=(new BinaryCodec())->encode($node);
            $stored=$this->store->get('message-outbox',$node->attrs['id']);
            self::assertNotNull($stored,'Persist the complete encrypted envelope before transport');
            self::assertSame($encoded,base64_decode(json_decode($stored,true,512,JSON_THROW_ON_ERROR)['node'],true));
            if($attempt++>0) {
                self::assertSame($wire,$encoded,'Restart retry must reuse ciphertext, message ID and Flow metadata');
                return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
            }
            $wire=$encoded;$id=$node->attrs['id'];
            foreach($node->child('participants')->content as $recipient) {
                $device=$recipient->attrs['jid'];$enc=$recipient->child('enc');
                self::assertArrayNotHasKey('mediatype',$enc->attrs);
                $plain=$peers[$device]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content);
                $decoded=MessageCodec::decode(MessageCodec::unpad($plain));
                self::assertSame('interactive',$decoded['kind']);
                self::assertSame('Preencha o formulário',$decoded['data']['body']['text']);
                $native=$decoded['data']['nativeFlowMessage'];
                self::assertSame(1,$native['messageVersion']);
                self::assertCount(1,$native['buttons']);
                self::assertSame('galaxy_message',$native['buttons'][0]['name']);
                $params=json_decode($native['buttons'][0]['buttonParamsJson'],false,512,JSON_THROW_ON_ERROR);
                self::assertSame('3',$params->flow_message_version);
                if(array_key_exists('flow_json',$variant)){
                    self::assertMatchesRegularExpression('/^[0-9]{1,64}$/D',$params->flow_id);
                    $inlineId??=$params->flow_id;self::assertSame($inlineId,$params->flow_id);
                    self::assertEquals($variant['flow_json'],$params->flow_json);
                    self::assertInstanceOf(\stdClass::class,$params->flow_json->screens[0]->data);
                    self::assertSame('{}',json_encode($params->flow_json->screens[0]->data));
                    self::assertSame('SingleColumnLayout',$params->flow_json->screens[0]->layout->type);
                }else self::assertSame('123456789012345',$params->flow_id);
                self::assertSame('fixture-token',$params->flow_token);
                self::assertSame('Abrir formulário',$params->flow_cta);
                self::assertSame($action,$params->flow_action);
                if($action==='data_exchange'||isset($variant['data_api_version'])){
                    self::assertEquals((object)['data_api_version'=>'3.0'],$params->flow_metadata);
                    self::assertObjectNotHasProperty('data_api_version',$params);
                }else self::assertObjectNotHasProperty('flow_metadata',$params);
                if($screen===null) {
                    self::assertFalse(property_exists($params,'flow_action_payload'));
                } else {
                    self::assertSame($screen,$params->flow_action_payload->screen);
                    self::assertInstanceOf(\stdClass::class,$params->flow_action_payload->data);
                    self::assertSame(json_encode((object)$variant['data']),json_encode($params->flow_action_payload->data));
                }
                $context=$decoded['messageContextInfo'];
                self::assertSame(32,strlen($context['messageSecret']));
                $secret??=$context['messageSecret'];
                self::assertSame($secret,$context['messageSecret']);
                if($device===$own) {
                    self::assertSame($jid,$decoded['destination']);
                    self::assertSame($context,$decoded['proto']['messageContextInfo']);
                    self::assertSame($context,$decoded['proto']['deviceSentMessage']['message']['messageContextInfo']);
                }
                $seen[]=$device;
            }
            self::assertSame($secret,BinaryData::decode($this->store->get('message-secrets',hash('sha256',$jid."\0".$id)))['secret']);
            throw new \RuntimeException('simulated transport timeout');
        };
        $service=$this->service($query);
        $content=[...$variant,'text'=>'Preencha o formulário','flow_id'=>'123456789012345','flow_token'=>'fixture-token','flow_cta'=>'Abrir formulário'];
        if(array_key_exists('flow_json',$variant)){
            unset($content['flow_id']);self::assertArrayNotHasKey('screen',$content);
        }
        try { $service->sendMessage($jid,$content);self::fail('Expected interrupted transport'); }
        catch(\RuntimeException $error) { self::assertSame('simulated transport timeout',$error->getMessage()); }
        self::assertEqualsCanonicalizing([$jid,$own],$seen);
        self::assertNotNull($id);
        self::assertSame('unknown',$service->getMessageStatus($id)['status']);
        unset($service,$this->signal,$this->store);
        $this->store=new SignalStore($this->paths[0]);
        $this->signal=new SignalProtocol($this->store,$this->credentials);
        $service=$this->service($query);
        if(array_key_exists('flow_json',$variant)){
            $rebuilt=ContentBuilder::build([...$content,'flow_token'=>'another-send-token'],$jid)['proto']['interactiveMessage']['nativeFlowMessage']['buttons'][0]['buttonParamsJson'];
            self::assertSame($inlineId,json_decode($rebuilt,false,128,JSON_THROW_ON_ERROR)->flow_id,'The same inline form retains its local identity across restart and send tokens');
        }
        $result=$service->retryMessage($id);
        self::assertSame('server_ack',$result['status']);
        self::assertSame($id,$result['id']);
        $service->retryMessage($id);
        self::assertSame(2,$attempt,'An acknowledged Flow must not be duplicated');
    }

    public function testAuthenticatedIncomingFlowResponsePreservesFormAndTokenAcrossRestart():void
    {
        $peerJid='5522000000000:2@s.whatsapp.net';$bare=Jid::parse($peerJid)->bare();
        $peer=$this->peers([$peerJid])[$peerJid];
        $localKey=$this->store->generatePreKeys(1)[0];
        $peer['signal']->ensureSession($this->credentials['meJid'],self::bundle($this->credentials,$localKey));
        $params='{"flow_token":"order-42","wa_flow_response_params":{"screen":"FORM","customer":"Maik","accepted":true},"optional":null}';
        $proto=['interactiveResponseMessage'=>[
            'body'=>['text'=>'Formulário enviado'],
            'nativeFlowResponseMessage'=>['name'=>'galaxy_message','version'=>3,'paramsJson'=>$params],
            'contextInfo'=>['stanzaId'=>'outgoing-flow-42','participant'=>$this->credentials['meJid']],
        ]];
        $bytes=SchemaCodec::encode('Message',$proto);
        $parsed=ContentDecoder::decode($bytes)['parsedFlowResponse'];
        self::assertSame('galaxy_message',$parsed['name']);
        self::assertSame(3,$parsed['version']);
        self::assertSame('order-42',$parsed['flowToken']);
        self::assertInstanceOf(\stdClass::class,$parsed['params']);
        self::assertSame('Maik',$parsed['response']->customer);
        self::assertTrue($parsed['response']->accepted);
        $encrypted=$peer['signal']->encrypt($this->credentials['meJid'],MessageCodec::pad($bytes));
        $node=new BinaryNode('message',['id'=>'incoming-flow-42','from'=>$peerJid,'type'=>'text','t'=>'1760000000'],[
            new BinaryNode('enc',['type'=>$encrypted['type'],'v'=>'2'],$encrypted['ciphertext']),
        ]);
        $query=static fn():BinaryNode=>throw new \LogicException('Incoming response must not need network queries');
        $service=$this->service($query);
        self::assertTrue($service->handleNode($node));
        self::assertCount(1,$this->events);
        self::assertSame('message',$this->events[0][0]);
        $event=$this->events[0][1];
        self::assertSame('interactive-response',$event['type']);
        self::assertSame($bare,$event['from']);
        self::assertFalse($event['fromMe']);
        self::assertSame($params,$event['data']['nativeFlowResponseMessage']['paramsJson']);
        self::assertSame('order-42',$event['parsedFlowResponse']['flowToken']);
        self::assertInstanceOf(\stdClass::class,$event['parsedFlowResponse']['params']);
        self::assertSame('Maik',$event['parsedFlowResponse']['response']->customer);
        self::assertSame('receipt',$this->sent[0]->tag);
        self::assertArrayNotHasKey('type',$this->sent[0]->attrs,'Delivery must not silently mark a form response as read');
        unset($service,$this->signal,$this->store);
        $this->store=new SignalStore($this->paths[0]);
        $this->signal=new SignalProtocol($this->store,$this->credentials);
        $service=$this->service($query);
        $saved=$service->history()->store->getMessage($bare,'incoming-flow-42');
        self::assertNotNull($saved);
        self::assertSame($params,$saved['data']['nativeFlowResponseMessage']['paramsJson']);
        self::assertSame('outgoing-flow-42',$saved['context']['stanzaId']);
        self::assertSame('order-42',ContentDecoder::decode(SchemaCodec::encode('Message',$saved['proto']))['parsedFlowResponse']['flowToken']);
        $service->handleNode($node);
        self::assertCount(1,$this->events,'An authenticated replay after restart must not duplicate the form response');
        self::assertCount(2,$this->sent);
    }

    public function testPendingFlowResponseReplaysJsonObjectsAfterCallbackFailureAndRestart():void
    {
        $peerJid='5522000000000:2@s.whatsapp.net';
        $peer=$this->peers([$peerJid])[$peerJid];
        $key=$this->store->generatePreKeys(1)[0];
        $peer['signal']->ensureSession($this->credentials['meJid'],self::bundle($this->credentials,$key));
        $proto=['interactiveResponseMessage'=>['nativeFlowResponseMessage'=>[
            'name'=>'galaxy_message','version'=>3,'paramsJson'=>'{"flow_token":"retry-token","wa_flow_response_params":{}}',
        ]]];
        $encrypted=$peer['signal']->encrypt($this->credentials['meJid'],MessageCodec::pad(SchemaCodec::encode('Message',$proto)));
        $node=new BinaryNode('message',['id'=>'pending-flow','from'=>$peerJid,'type'=>'text'],[
            new BinaryNode('enc',['type'=>$encrypted['type'],'v'=>'2'],$encrypted['ciphertext']),
        ]);
        $query=static fn():BinaryNode=>throw new \LogicException('No queries for an incoming response');
        $service=$this->service($query,function(string $name,array $event):void {
            self::assertSame('message',$name);
            self::assertFalse($event['replayed']);
            self::assertInstanceOf(\stdClass::class,$event['parsedFlowResponse']['response']);
            self::assertSame('{}',json_encode($event['parsedFlowResponse']['response']));
            throw new \RuntimeException('Application temporarily unavailable');
        });
        try { $service->handleNode($node);self::fail('Expected application callback failure'); }
        catch(\RuntimeException $error) { self::assertSame('Application temporarily unavailable',$error->getMessage()); }
        self::assertSame([],$this->sent,'Receipt follows successful durable application delivery');
        unset($service,$this->signal,$this->store);
        $this->store=new SignalStore($this->paths[0]);
        $this->signal=new SignalProtocol($this->store,$this->credentials);
        $service=$this->service($query);
        self::assertTrue($service->handleNode($node));
        self::assertCount(1,$this->events);
        $event=$this->events[0][1];
        self::assertTrue($event['replayed']);
        self::assertSame('retry-token',$event['parsedFlowResponse']['flowToken']);
        self::assertInstanceOf(\stdClass::class,$event['parsedFlowResponse']['params']);
        self::assertInstanceOf(\stdClass::class,$event['parsedFlowResponse']['response']);
        self::assertSame('{}',json_encode($event['parsedFlowResponse']['response']));
        $service->handleNode($node);
        self::assertCount(1,$this->events,'Successful replay consumes the durable pending event once');
    }

    private function path():string
    {
        $path=tempnam(sys_get_temp_dir(),'mvo-flow-');
        if($path===false) throw new \RuntimeException('Cannot create temporary store');
        $this->paths[]=$path;return $path;
    }

    /** @return array<string,mixed> */
    private static function credentials(string $jid):array
    {
        $credentials=CredentialStore::fresh();
        $credentials['registered']=true;$credentials['meJid']=$jid;$credentials['serverHasPreKeys']=true;
        $credentials['signedIdentity']=['details'=>'fixture','accountSignatureKey'=>random_bytes(32),
            'accountSignature'=>random_bytes(64),'deviceSignature'=>random_bytes(64)];
        return $credentials;
    }

    private function service(\Closure $query,?\Closure $emit=null):MessageService
    {
        return new MessageService($this->signal,$this->store,fn():array=>$this->credentials,
            function(array $credentials):void{$this->credentials=$credentials;},$query,
            function(BinaryNode $node):void{$this->sent[]=$node;},
            $emit??function(string $event,array $data):void{$this->events[]=[$event,$data];});
    }

    /** @param list<string> $jids @return array<string,array<string,mixed>> */
    private function peers(array $jids):array
    {
        $peers=[];
        foreach($jids as $jid) {
            $credentials=self::credentials($jid);$store=new SignalStore($this->path());
            $peers[$jid]=['credentials'=>$credentials,'key'=>$store->generatePreKeys(1)[0],
                'signal'=>new SignalProtocol($store,$credentials),'store'=>$store];
        }
        return $peers;
    }

    /** @param array<string,list<int>> $devices */
    private static function devices(array $devices):BinaryNode
    {
        $users=[];
        foreach($devices as $jid=>$ids) $users[]=new BinaryNode('user',['jid'=>$jid],[new BinaryNode('devices',[],[
            new BinaryNode('device-list',[],array_map(static fn(int $id):BinaryNode=>new BinaryNode('device',['id'=>(string)$id]),$ids)),
        ])]);
        return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('usync',[],[new BinaryNode('list',[],$users)])]);
    }

    /** @param array<string,array<string,mixed>> $peers */
    private static function bundles(array $peers):BinaryNode
    {
        $users=[];
        foreach($peers as $jid=>$peer) {
            $credentials=$peer['credentials'];$key=$peer['key'];$signed=$credentials['signedPreKey'];
            $users[]=new BinaryNode('user',['jid'=>$jid],[
                new BinaryNode('registration',[],pack('N',$credentials['registrationId'])),
                new BinaryNode('identity',[],$credentials['identityKeyPair']['public']),
                new BinaryNode('skey',[],[new BinaryNode('id',[],substr(pack('N',$signed['id']),1)),
                    new BinaryNode('value',[],$signed['public']),new BinaryNode('signature',[],$signed['signature'])]),
                new BinaryNode('key',[],[new BinaryNode('id',[],substr(pack('N',$key['id']),1)),new BinaryNode('value',[],$key['public'])]),
            ]);
        }
        return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('list',[],$users)]);
    }

    /** @param array<string,mixed> $credentials @param array<string,mixed> $key @return array<string,mixed> */
    private static function bundle(array $credentials,array $key):array
    {
        return ['registrationId'=>$credentials['registrationId'],'identityKey'=>$credentials['identityKeyPair']['public'],
            'signedPreKey'=>['id'=>$credentials['signedPreKey']['id'],'public'=>$credentials['signedPreKey']['public'],'signature'=>$credentials['signedPreKey']['signature']],
            'preKey'=>['id'=>$key['id'],'public'=>$key['public']]];
    }
}
