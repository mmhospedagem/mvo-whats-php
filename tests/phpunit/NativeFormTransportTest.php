<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Message\{ContentDecoder,MessageCodec,MessageService};
use MVO\Protocol\{BinaryCodec,BinaryData,BinaryNode,Jid,SchemaCodec};
use MVO\Signal\{SignalProtocol,SignalStore};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Native forms need the matching business companion as well as their encrypted JSON. */
final class NativeFormTransportTest extends TestCase
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

    /** @return iterable<string,array{string,string}> */
    public static function selections():iterable
    {
        yield 'single selection'=>['single','mutuallyExclusive'];
        yield 'multiple selection'=>['multiple','multipleSelection'];
    }

    #[DataProvider('selections')]
    public function testEncryptedNativeFormFanoutAndExactRetrySurviveRestart(string $selection,string $variant):void
    {
        $jid='5522000000000@s.whatsapp.net';
        $own=Jid::parse($this->credentials['meJid'])->bare();
        $peers=$this->peers([$jid,$own]);
        $wire=null;$id=null;$secret=null;$attempt=0;$seen=[];
        $query=function(BinaryNode $node)use($jid,$own,$peers,$variant,&$wire,&$id,&$secret,&$attempt,&$seen):BinaryNode {
            if($node->tag==='iq' && ($node->attrs['xmlns']??'')==='usync') return self::devices([$jid=>[0],$own=>[0,5]]);
            if($node->tag==='iq') return self::bundles($peers);
            self::assertSame('message',$node->tag);
            self::assertSame('false',$node->attrs['device_fanout']);
            self::assertSame('text',$node->attrs['type']);
            $biz=$node->child('biz');
            self::assertNotNull($biz);
            self::assertSame([],$biz->attrs,'Native forms use the public business companion without mixed-message actor attributes');
            self::assertCount(1,$biz->content);
            $interactive=$biz->child('interactive');
            self::assertNotNull($interactive);
            self::assertEquals(['v'=>'1','type'=>'native_flow'],$interactive->attrs);
            self::assertCount(1,$interactive->content);
            $nativeCompanion=$interactive->child('native_flow');
            self::assertNotNull($nativeCompanion);
            self::assertSame(['name'=>'form_message'],$nativeCompanion->attrs,'Android chooses its native form parser from this companion name');
            self::assertNull($biz->child('quality_control'));
            $encoded=(new BinaryCodec())->encode($node);
            $stored=$this->store->get('message-outbox',$node->attrs['id']);
            self::assertNotNull($stored,'Persist the encrypted native form envelope before transport');
            self::assertSame($encoded,base64_decode(json_decode($stored,true,512,JSON_THROW_ON_ERROR)['node'],true));
            if($attempt++>0) {
                self::assertSame($wire,$encoded,'Restart retry must reuse the exact ciphertext, ID and form companion');
                return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
            }
            $wire=$encoded;$id=$node->attrs['id'];
            foreach($node->child('participants')->content as $recipient) {
                $device=$recipient->attrs['jid'];$enc=$recipient->child('enc');
                self::assertArrayNotHasKey('mediatype',$enc->attrs);
                $plain=$peers[$device]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content);
                $decoded=MessageCodec::decode(MessageCodec::unpad($plain));
                self::assertSame('interactive',$decoded['kind']);
                self::assertSame('Escolha para continuar',$decoded['data']['body']['text']);
                $native=$decoded['data']['nativeFlowMessage'];
                self::assertSame(1,$native['messageVersion']);
                self::assertCount(1,$native['buttons']);
                self::assertSame('form_message',$native['buttons'][0]['name']);
                $button=json_decode($native['buttons'][0]['buttonParamsJson'],false,512,JSON_THROW_ON_ERROR);
                self::assertSame('Responder',$button->display_text);
                self::assertObjectNotHasProperty('flow_id',$button);
                self::assertObjectNotHasProperty('flow_token',$button);
                self::assertObjectNotHasProperty('flow_action',$button);
                $params=json_decode($native['messageParamsJson'],false,512,JSON_THROW_ON_ERROR);
                self::assertSame('2',$params->version);
                self::assertSame('a2ui_form',$params->payload->type);
                $data=$params->payload->data;
                self::assertInstanceOf(\stdClass::class,$data);
                self::assertNotSame('',$data->root);
                self::assertCount(2,$data->components);
                $components=[];
                foreach($data->components as $component) $components[$component->id]=$component;
                self::assertSame('form',$components[$data->root]->component);
                self::assertSame(['name___MVO_MARKER__'],$components[$data->root]->children);
                $picker=$components['name___MVO_MARKER__'];
                self::assertSame('ChoicePicker',$picker->component);
                self::assertSame($variant,$picker->variant);
                self::assertSame('Nome',$picker->label);
                self::assertTrue($picker->allow_custom_answer);
                self::assertCount(2,$picker->options);
                self::assertEquals((object)['id'=>'mvo','title'=>'Teste MVO'],$picker->options[0]);
                self::assertEquals((object)['id'=>'other','title'=>'Outro'],$picker->options[1]);
                self::assertObjectNotHasProperty('flow_id',$params);
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
            throw new \RuntimeException('simulated native form transport timeout');
        };
        $service=$this->service($query);
        $content=['type'=>'form','text'=>'Escolha para continuar','cta'=>'Responder','steps'=>[
            ['id'=>'name___MVO_MARKER__','label'=>'Nome','selection'=>$selection,'allow_custom_answer'=>true,
                'options'=>[['id'=>'mvo','title'=>'Teste MVO'],['id'=>'other','title'=>'Outro']]],
        ]];
        try { $service->sendMessage($jid,$content);self::fail('Expected interrupted transport'); }
        catch(\RuntimeException $error) { self::assertSame('simulated native form transport timeout',$error->getMessage()); }
        self::assertEqualsCanonicalizing([$jid,$own],$seen);
        self::assertNotNull($id);
        self::assertSame('unknown',$service->getMessageStatus($id)['status']);
        unset($service,$this->signal,$this->store);
        $this->store=new SignalStore($this->paths[0]);
        $this->signal=new SignalProtocol($this->store,$this->credentials);
        $service=$this->service($query);
        $result=$service->retryMessage($id);
        self::assertSame('server_ack',$result['status']);
        self::assertSame($id,$result['id']);
        $service->retryMessage($id);
        self::assertSame(2,$attempt,'An acknowledged native form must not be duplicated');
    }

    public function testAndroidTextInputFanoutAndAuthenticatedFlatReplyKeepCallerFieldId():void
    {
        $jid='5522000000000@s.whatsapp.net';
        $own=Jid::parse($this->credentials['meJid'])->bare();
        $peers=$this->peers([$jid,$own]);$seen=[];
        $query=function(BinaryNode $node)use($jid,$own,$peers,&$seen):BinaryNode {
            if($node->tag==='iq' && ($node->attrs['xmlns']??'')==='usync') return self::devices([$jid=>[0],$own=>[0,5]]);
            if($node->tag==='iq') return self::bundles($peers);
            $biz=$node->child('biz');
            self::assertNotNull($biz);
            self::assertSame([],$biz->attrs);
            self::assertEquals(['v'=>'1','type'=>'native_flow'],$biz->child('interactive')->attrs);
            self::assertSame(['name'=>'form_message'],$biz->child('interactive')->child('native_flow')->attrs);
            foreach($node->child('participants')->content as $recipient) {
                $device=$recipient->attrs['jid'];$enc=$recipient->child('enc');
                $plain=$peers[$device]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content);
                $decoded=MessageCodec::decode(MessageCodec::unpad($plain));
                self::assertSame('interactive',$decoded['kind']);
                self::assertSame('Informe seu nome',$decoded['data']['body']['text']);
                $native=$decoded['data']['nativeFlowMessage'];
                self::assertSame(1,$native['messageVersion']);
                self::assertSame('form_message',$native['buttons'][0]['name']);
                $params=json_decode($native['messageParamsJson'],false,512,JSON_THROW_ON_ERROR);
                self::assertSame('2',$params->version);
                self::assertSame('a2ui_form',$params->payload->type);
                $components=[];
                foreach($params->payload->data->components as $component) $components[$component->id]=$component;
                self::assertSame(['dados'],$components[$params->payload->data->root]->children);
                $input=$components['dados'];
                self::assertSame('InputFields',$input->component);
                self::assertSame('Cadastro MVO',$input->label);
                self::assertSame('Enviar',$input->button);
                self::assertCount(1,$input->fields);
                self::assertSame('name',$input->fields[0]->id,'Caller field ID becomes the flat response key');
                self::assertSame('Nome',$input->fields[0]->label);
                self::assertSame('text',$input->fields[0]->type);
                self::assertTrue($input->fields[0]->required);
                self::assertSame('Maik',$input->fields[0]->default);
                self::assertObjectNotHasProperty('flow_id',$params);
                self::assertSame(32,strlen($decoded['messageContextInfo']['messageSecret']));
                if($device===$own) self::assertSame($jid,$decoded['destination']);
                $seen[]=$device;
            }
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        };
        $service=$this->service($query);
        $outgoing=$service->sendMessage($jid,['type'=>'form','platform'=>'android','text'=>'Informe seu nome','cta'=>'Responder',
            'steps'=>[['id'=>'dados','label'=>'Cadastro MVO','kind'=>'input','button'=>'Enviar','fields'=>[
                ['id'=>'name','label'=>'Nome','type'=>'text','required'=>true,'default'=>'Maik'],
            ]]],
        ]);
        self::assertSame('server_ack',$outgoing['status']);
        self::assertEqualsCanonicalizing([$jid,$own],$seen);
        $params='{"name":"Maik"}';
        $response=['interactiveResponseMessage'=>[
            'nativeFlowResponseMessage'=>['name'=>'form_message','version'=>3,'paramsJson'=>$params],
            'contextInfo'=>['stanzaId'=>$outgoing['id'],'participant'=>$this->credentials['meJid']],
        ]];
        $encrypted=$peers[$jid]['signal']->encrypt($this->credentials['meJid'],MessageCodec::pad(SchemaCodec::encode('Message',$response)));
        $node=new BinaryNode('message',['id'=>'android-name-response','from'=>$jid,'type'=>'text'],[
            new BinaryNode('enc',['type'=>$encrypted['type'],'v'=>'2'],$encrypted['ciphertext']),
        ]);
        self::assertTrue($service->handleNode($node));
        $incoming=array_values(array_filter($this->events,static fn(array $event):bool=>!($event[1]['fromMe']??true)));
        self::assertCount(1,$incoming);
        $event=$incoming[0][1];
        self::assertSame('interactive-response',$event['type']);
        self::assertSame('form_message',$event['parsedFormResponse']['name']);
        self::assertSame(3,$event['parsedFormResponse']['version']);
        self::assertInstanceOf(\stdClass::class,$event['parsedFormResponse']['params']);
        self::assertEquals((object)['name'=>'Maik'],$event['parsedFormResponse']['params']);
        self::assertSame($params,$event['data']['nativeFlowResponseMessage']['paramsJson']);
        self::assertSame($outgoing['id'],$event['context']['stanzaId']);
        self::assertSame('receipt',$this->sent[0]->tag);
    }

    #[DataProvider('responseVersions')]
    public function testPortableTextAndRealCancellationSurviveSignalFanoutAndAuthenticatedReplies(int $version):void
    {
        $jid='5522000000000@s.whatsapp.net';
        $own=Jid::parse($this->credentials['meJid'])->bare();
        $peers=$this->peers([$jid,$own]);$seen=[];
        $cancel=['id'=>'cancelar_consulta_42','title'=>'Cancelar consulta'];
        $query=function(BinaryNode $node)use($jid,$own,$peers,$cancel,&$seen):BinaryNode {
            if($node->tag==='iq' && ($node->attrs['xmlns']??'')==='usync') return self::devices([$jid=>[0],$own=>[0,5]]);
            if($node->tag==='iq') return self::bundles($peers);
            self::assertSame('false',$node->attrs['device_fanout']);
            $biz=$node->child('biz');
            self::assertNotNull($biz);
            self::assertSame([],$biz->attrs);
            self::assertEquals(['v'=>'1','type'=>'native_flow'],$biz->child('interactive')->attrs);
            self::assertSame(['name'=>'form_message'],$biz->child('interactive')->child('native_flow')->attrs);
            foreach($node->child('participants')->content as $recipient) {
                $device=$recipient->attrs['jid'];$enc=$recipient->child('enc');
                $plain=$peers[$device]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content);
                $decoded=MessageCodec::decode(MessageCodec::unpad($plain));
                self::assertSame('interactive',$decoded['kind']);
                self::assertSame('Informe os dados da consulta',$decoded['data']['body']['text']);
                $native=$decoded['data']['nativeFlowMessage'];
                self::assertSame(1,$native['messageVersion']);
                self::assertSame('form_message',$native['buttons'][0]['name']);
                $params=json_decode($native['messageParamsJson'],false,512,JSON_THROW_ON_ERROR);
                self::assertSame('2',$params->version);
                self::assertSame('a2ui_form',$params->payload->type);
                $graph=$params->payload->data;
                self::assertSame(['name','consulta.observacao'],$graph->components[0]->children);
                self::assertSame($graph->root,$graph->components[0]->id);
                self::assertCount(3,$graph->components);
                foreach(array_slice($graph->components,1) as $index=>$component) {
                    self::assertSame(['name','consulta.observacao'][$index],$component->id);
                    self::assertSame('ChoicePicker',$component->component);
                    self::assertSame('mutuallyExclusive',$component->variant);
                    self::assertSame('Continuar',$component->button);
                    self::assertTrue($component->allow_custom_answer);
                    self::assertEquals([(object)$cancel],$component->options,'Cancellation is the real caller-owned option');
                    self::assertSame("Consulta MVO\n".['Nome','Observação'][$index],$component->label);
                    self::assertObjectNotHasProperty('fields',$component);
                    self::assertObjectNotHasProperty('default',$component);
                    self::assertObjectNotHasProperty('required',$component);
                }
                self::assertObjectNotHasProperty('flow_id',$params);
                self::assertSame(32,strlen($decoded['messageContextInfo']['messageSecret']));
                if($device===$own) self::assertSame($jid,$decoded['destination']);
                $seen[]=$device;
            }
            return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);
        };
        $service=$this->service($query);
        $content=['type'=>'form','text'=>'Informe os dados da consulta','cta'=>'Responder','steps'=>[
            ['id'=>'consulta','label'=>'Consulta MVO','kind'=>'input','button'=>'Continuar','cancel_option'=>$cancel,'fields'=>[
                ['id'=>'name','label'=>'Nome','type'=>'text','required'=>true,'default'=>''],
                ['id'=>'consulta.observacao','label'=>'Observação','type'=>'text','required'=>false],
            ]],
        ]];
        $original=$content;
        $outgoing=$service->sendMessage($jid,$content);
        self::assertSame($original,$content);
        self::assertSame('server_ack',$outgoing['status']);
        self::assertEqualsCanonicalizing([$jid,$own],$seen);
        foreach(['texto'=>['name'=>'Teste MVO','consulta.observacao'=>'Horário da manhã'],
            'cancelamento'=>['name'=>$cancel['id'],'consulta.observacao'=>$cancel['id']]] as $case=>$answers) {
            $params=json_encode($answers,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
            $response=['interactiveResponseMessage'=>[
                'nativeFlowResponseMessage'=>['name'=>'form_message','version'=>$version,'paramsJson'=>$params],
                'contextInfo'=>['stanzaId'=>$outgoing['id'],'participant'=>$this->credentials['meJid']],
            ]];
            $encrypted=$peers[$jid]['signal']->encrypt($this->credentials['meJid'],MessageCodec::pad(SchemaCodec::encode('Message',$response)));
            $node=new BinaryNode('message',['id'=>'portable-'.$case.'-'.$version,'from'=>$jid,'type'=>'text'],[
                new BinaryNode('enc',['type'=>$encrypted['type'],'v'=>'2'],$encrypted['ciphertext']),
            ]);
            self::assertTrue($service->handleNode($node));
            $incoming=array_values(array_filter($this->events,static fn(array $event):bool=>!($event[1]['fromMe']??true)));
            $event=$incoming[array_key_last($incoming)][1];
            self::assertSame('form_message',$event['parsedFormResponse']['name']);
            self::assertSame($version,$event['parsedFormResponse']['version']);
            self::assertEquals((object)$answers,$event['parsedFormResponse']['params']);
            self::assertSame(['name','consulta.observacao'],array_keys(get_object_vars($event['parsedFormResponse']['params'])));
            self::assertSame($params,$event['data']['nativeFlowResponseMessage']['paramsJson']);
            self::assertSame($outgoing['id'],$event['context']['stanzaId']);
            self::assertSame('receipt',$this->sent[array_key_last($this->sent)]->tag);
        }
        self::assertCount(2,$incoming,'Text and cancellation remain distinct authenticated replies');
    }

    /** @return iterable<string,array{int}> */
    public static function responseVersions():iterable
    {
        yield 'native form response version 1'=>[1];
        yield 'native form response version 3'=>[3];
    }

    #[DataProvider('responseVersions')]
    public function testAuthenticatedIncomingNativeFormResponsePreservesObjectsAndQuoteAcrossRestart(int $version):void
    {
        $peerJid='5522000000000:2@s.whatsapp.net';$bare=Jid::parse($peerJid)->bare();
        $peer=$this->peers([$peerJid])[$peerJid];
        $localKey=$this->store->generatePreKeys(1)[0];
        $peer['signal']->ensureSession($this->credentials['meJid'],self::bundle($this->credentials,$localKey));
        $params='{"name___MVO_MARKER__":"Teste MVO","empty":{},"selected":["mvo"],"optional":null}';
        $proto=['interactiveResponseMessage'=>[
            'body'=>['text'=>'Formulário enviado'],
            'nativeFlowResponseMessage'=>['name'=>'form_message','version'=>$version,'paramsJson'=>$params],
            'contextInfo'=>['stanzaId'=>'outgoing-native-form-42','participant'=>$this->credentials['meJid']],
        ]];
        $bytes=SchemaCodec::encode('Message',$proto);
        $parsed=ContentDecoder::decode($bytes)['parsedFormResponse'];
        self::assertSame('form_message',$parsed['name']);
        self::assertSame($version,$parsed['version']);
        self::assertInstanceOf(\stdClass::class,$parsed['params']);
        self::assertSame('Teste MVO',$parsed['params']->name___MVO_MARKER__);
        self::assertInstanceOf(\stdClass::class,$parsed['params']->empty);
        self::assertSame('{}',json_encode($parsed['params']->empty));
        self::assertSame(['mvo'],$parsed['params']->selected);
        self::assertNull($parsed['params']->optional);
        $encrypted=$peer['signal']->encrypt($this->credentials['meJid'],MessageCodec::pad($bytes));
        $id='incoming-native-form-'.$version;
        $node=new BinaryNode('message',['id'=>$id,'from'=>$peerJid,'type'=>'text','t'=>'1760000000'],[
            new BinaryNode('enc',['type'=>$encrypted['type'],'v'=>'2'],$encrypted['ciphertext']),
        ]);
        $query=static fn():BinaryNode=>throw new \LogicException('Incoming native form response must not need network queries');
        $service=$this->service($query);
        self::assertTrue($service->handleNode($node));
        self::assertCount(1,$this->events);
        self::assertSame('message',$this->events[0][0]);
        $event=$this->events[0][1];
        self::assertSame('interactive-response',$event['type']);
        self::assertSame($bare,$event['from']);
        self::assertFalse($event['fromMe']);
        self::assertSame($params,$event['data']['nativeFlowResponseMessage']['paramsJson']);
        self::assertSame($version,$event['parsedFormResponse']['version']);
        self::assertInstanceOf(\stdClass::class,$event['parsedFormResponse']['params']);
        self::assertSame('Teste MVO',$event['parsedFormResponse']['params']->name___MVO_MARKER__);
        self::assertInstanceOf(\stdClass::class,$event['parsedFormResponse']['params']->empty);
        self::assertSame('receipt',$this->sent[0]->tag);
        self::assertArrayNotHasKey('type',$this->sent[0]->attrs,'Delivery must not silently mark the native form response as read');
        unset($service,$this->signal,$this->store);
        $this->store=new SignalStore($this->paths[0]);
        $this->signal=new SignalProtocol($this->store,$this->credentials);
        $service=$this->service($query);
        $saved=$service->history()->store->getMessage($bare,$id);
        self::assertNotNull($saved);
        self::assertSame($params,$saved['data']['nativeFlowResponseMessage']['paramsJson']);
        self::assertSame('outgoing-native-form-42',$saved['context']['stanzaId']);
        $restored=ContentDecoder::decode(SchemaCodec::encode('Message',$saved['proto']))['parsedFormResponse'];
        self::assertSame($version,$restored['version']);
        self::assertSame('Teste MVO',$restored['params']->name___MVO_MARKER__);
        self::assertInstanceOf(\stdClass::class,$restored['params']->empty);
        $service->handleNode($node);
        self::assertCount(1,$this->events,'An authenticated replay after restart must not duplicate the native form response');
        self::assertCount(2,$this->sent);
    }

    #[DataProvider('responseVersions')]
    public function testPendingNativeFormResponseReplaysObjectsAfterCallbackFailureAndRestart(int $version):void
    {
        $peerJid='5522000000000:2@s.whatsapp.net';
        $peer=$this->peers([$peerJid])[$peerJid];
        $key=$this->store->generatePreKeys(1)[0];
        $peer['signal']->ensureSession($this->credentials['meJid'],self::bundle($this->credentials,$key));
        $proto=['interactiveResponseMessage'=>['nativeFlowResponseMessage'=>[
            'name'=>'form_message','version'=>$version,'paramsJson'=>'{"name":"Teste MVO","empty":{},"list":[]}',
        ]]];
        $encrypted=$peer['signal']->encrypt($this->credentials['meJid'],MessageCodec::pad(SchemaCodec::encode('Message',$proto)));
        $node=new BinaryNode('message',['id'=>'pending-native-form-'.$version,'from'=>$peerJid,'type'=>'text'],[
            new BinaryNode('enc',['type'=>$encrypted['type'],'v'=>'2'],$encrypted['ciphertext']),
        ]);
        $query=static fn():BinaryNode=>throw new \LogicException('No queries for an incoming native form response');
        $service=$this->service($query,function(string $name,array $event)use($version):void {
            self::assertSame('message',$name);
            self::assertFalse($event['replayed']);
            self::assertSame($version,$event['parsedFormResponse']['version']);
            self::assertInstanceOf(\stdClass::class,$event['parsedFormResponse']['params']);
            self::assertInstanceOf(\stdClass::class,$event['parsedFormResponse']['params']->empty);
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
        self::assertSame($version,$event['parsedFormResponse']['version']);
        self::assertInstanceOf(\stdClass::class,$event['parsedFormResponse']['params']);
        self::assertSame('Teste MVO',$event['parsedFormResponse']['params']->name);
        self::assertInstanceOf(\stdClass::class,$event['parsedFormResponse']['params']->empty);
        self::assertSame('{}',json_encode($event['parsedFormResponse']['params']->empty));
        self::assertSame([],$event['parsedFormResponse']['params']->list);
        $service->handleNode($node);
        self::assertCount(1,$this->events,'Successful replay consumes the durable pending native form event once');
    }

    private function path():string
    {
        $path=tempnam(sys_get_temp_dir(),'mvo-native-form-');
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
