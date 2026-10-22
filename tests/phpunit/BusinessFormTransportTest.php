<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Message\{ContentDecoder,MessageCodec,MessageService};
use MVO\Protocol\{BinaryCodec,BinaryData,BinaryNode,Jid,SchemaCodec};
use MVO\Service\{BusinessFormStore,BusinessFormsService};
use MVO\Signal\{SignalProtocol,SignalStore};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Synthetic peer integration: publication snapshots survive actual Signal fanout, storage and retry. */
final class BusinessFormTransportTest extends TestCase
{
    private array $paths=[];
    private array $credentials;
    private SignalStore $store;
    private SignalProtocol $signal;
    private array $events=[];
    private array $receipts=[];

    protected function setUp():void
    {
        $this->credentials=self::credentials('5511000000000:5@s.whatsapp.net');$this->store=new SignalStore($this->path());$this->signal=new SignalProtocol($this->store,$this->credentials);
    }
    protected function tearDown():void
    {
        unset($this->signal,$this->store);gc_collect_cycles();foreach($this->paths as$path)foreach([$path,$path.'-wal',$path.'-shm']as$file)if(is_file($file))@unlink($file);
    }
    public static function forms():iterable
    {
        yield 'customer PN'=>['5511999999999@s.whatsapp.net',['form_title'=>'Cadastro MVO','form_type'=>'CUSTOMER_INFO','message_text'=>'Dados para atendimento','selected_field_list'=>['NAME','EMAIL','PHONE_NUMBER','CUSTOM','CUSTOM'],
            'custom_field_names'=>['Plano','Observações'],'custom_field_supporting_texts'=>['Selecione um plano','Detalhes opcionais'],'custom_field_input_types'=>['DROPDOWN','TEXT_AREA'],'custom_field_options'=>[['Básico','Avançado'],[]],'edited_field_keys'=>['NAME'],'edited_field_names'=>['Nome completo']],
            'contact_details','template'];
        yield 'AI offer LID'=>['81234567890123@lid',['form_title'=>'Oferta especial','form_description'=>'Inscreva-se na oferta','form_type'=>'OFFER_SIGNUP','message_text'=>'Conheça esta oferta','selected_field_list'=>['NAME','CUSTOM'],
            'custom_field_names'=>['Empresa'],'custom_field_supporting_texts'=>['Razão social'],'custom_field_input_types'=>['TEXT_INPUT'],'custom_field_options'=>[[]],'is_ai_form'=>true],
            'contact_details','ai_form_attachment_tray'];
        yield 'delivery only'=>['5511999999999@s.whatsapp.net',['form_title'=>'Entrega','form_type'=>'CUSTOMER_INFO','message_text'=>'Informe seu endereço','selected_field_list'=>['DELIVERY_ADDRESS']],
            'delivery_address','template'];
    }

    public static function senderRoles():iterable
    {
        foreach(self::forms()as$name=>$form){yield 'companion '.$name=>[...$form,false];yield 'primary '.$name=>[...$form,true];}
    }

    #[DataProvider('senderRoles')]
    public function testPublishedFormSurvivesSignalFanoutTimeoutTemplateDeletionAndExactRestartRetry(string $jid,array $definition,string $screen,string $wireFormType,bool $isPrimary):void
    {
        $own=Jid::parse($this->credentials['meJid'])->bare();$ownCopy=$own;
        if($isPrimary){$this->credentials['role']='primary';$this->credentials['meJid']=$own;$this->signal=new SignalProtocol($this->store,$this->credentials);$address=Jid::parse($own);$ownCopy=(string)new Jid($address->user,$address->server,5);}
        $peers=$this->peers([$jid,$ownCopy]);$requestId='PUBLISH-TRANSPORT';$wire=null;$messageId=null;$attempts=0;$decodedByDevice=[];$forms=null;$publications=0;$secret=null;
        $query=function(BinaryNode $node)use($jid,$own,$ownCopy,$isPrimary,$peers,$requestId,$definition,$screen,$wireFormType,&$wire,&$messageId,&$attempts,&$decodedByDevice,&$forms,&$secret):BinaryNode{
            if($node->tag==='iq')return ($node->attrs['xmlns']??'')==='usync'?self::devices([$jid=>[0],$own=>[0,5]]):self::bundles($peers);
            self::assertSame('message',$node->tag);self::assertArrayNotHasKey('device_fanout',$node->attrs);self::assertSame('text',$node->attrs['type']);self::assertSame('high',$node->attrs['push_priority']);
            $biz=$node->child('biz');self::assertNotNull($biz);
            if($isPrimary){
                self::assertSame([],$biz->attrs);$interactive=$biz->child('interactive');self::assertSame(['type'=>'native_flow','v'=>'1'],$interactive->attrs);
                $nativeFlow=$interactive->child('native_flow');self::assertSame(['name'=>'galaxy_message'],$nativeFlow->attrs);
                self::assertCount(1,$nativeFlow->content);self::assertSame(['well_version'=>'700','flow_message_version'=>'4'],$nativeFlow->child('extensions_metadata')->attrs);
            }else{self::assertSame(['native_flow_name'=>'galaxy_message'],$biz->attrs);self::assertNull($biz->content);}
            $encoded=(new BinaryCodec())->encode($node);$persisted=$this->store->get('message-outbox',$node->attrs['id']);self::assertNotNull($persisted);
            self::assertSame($encoded,base64_decode(json_decode($persisted,true,512,JSON_THROW_ON_ERROR)['node'],true),'Complete encrypted message must be durable before transport');
            if($attempts++>0){self::assertSame($wire,$encoded,'Retry must preserve exact ciphertext, business envelope, ID and metadata');return new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]);}
            $wire=$encoded;$messageId=$node->attrs['id'];$reserved=$forms->sendStatus($requestId);self::assertSame($messageId,$reserved['messageId']);self::assertSame('prepared',$reserved['state']);
            foreach($node->child('participants')->content as$recipient){
                $device=$recipient->attrs['jid'];$enc=$recipient->child('enc');self::assertArrayNotHasKey('mediatype',$enc->attrs);self::assertArrayNotHasKey('native_flow_name',$enc->attrs);
                $plain=$peers[$device]['signal']->decrypt($this->credentials['meJid'],$enc->attrs['type'],$enc->content);$decoded=MessageCodec::decode(MessageCodec::unpad($plain));$decodedByDevice[$device]=$decoded;
                self::assertSame('interactive',$decoded['kind']);self::assertSame($definition['message_text'],$decoded['data']['body']['text']);self::assertSame('MVO PHP',$decoded['data']['header']['title']);self::assertSame('Formulário de teste',$decoded['data']['footer']['text']);
                $native=$decoded['data']['nativeFlowMessage'];self::assertSame(3,$native['messageVersion']);self::assertSame('{}',$native['messageParamsJson']);self::assertCount(1,$native['buttons']);self::assertSame('galaxy_message',$native['buttons'][0]['name']);
                $params=json_decode($native['buttons'][0]['buttonParamsJson'],false,512,JSON_THROW_ON_ERROR);self::assertSame('4',$params->flow_message_version);self::assertSame('9007199254740993',$params->flow_id);self::assertSame('V700',$params->well_version);
                self::assertSame('__localize:FLOWS_COMPLETE_FORM_BUTTON_TITLE',$params->flow_cta);self::assertSame(base64_encode($definition['form_type']),$params->flow_token);self::assertSame('navigate',$params->flow_action);self::assertSame($wireFormType,$params->form_type);self::assertSame($screen,$params->flow_action_payload->screen);self::assertObjectNotHasProperty('flow_json',$params);
                if($definition['form_type']==='OFFER_SIGNUP'){self::assertSame('Oferta especial',$params->flow_action_payload->data->offer_name);self::assertSame('Inscreva-se na oferta',$params->flow_action_payload->data->offer_description);self::assertSame('Empresa',$params->flow_action_payload->data->custom_fields[0]->label);}
                elseif($screen==='delivery_address'){self::assertFalse($params->flow_action_payload->data->delivery_address_visible);self::assertSame('Endereço de entrega',$params->flow_action_payload->data->custom_fields[0]->label);}
                else{self::assertFalse($params->flow_action_payload->data->full_name_visible);self::assertTrue($params->flow_action_payload->data->email_visible);self::assertTrue($params->flow_action_payload->data->phone_number_visible);self::assertSame('Nome completo',$params->flow_action_payload->data->custom_fields[0]->label);self::assertSame(['Básico','Avançado'],$params->flow_action_payload->data->custom_fields[1]->options);}
                $ctx=$decoded['data']['contextInfo'];self::assertSame('QUOTED-ORIGINAL',$ctx['stanzaId']);self::assertSame('Conversa original',$ctx['quotedMessage']['conversation']);self::assertSame([$jid],$ctx['mentionedJid']);
                $context=$decoded['messageContextInfo'];self::assertSame(32,strlen($context['messageSecret']));$secret??=$context['messageSecret'];self::assertSame($secret,$context['messageSecret']);
                if($device===$ownCopy){self::assertSame($jid,$decoded['destination']);self::assertSame($context,$decoded['proto']['messageContextInfo']);self::assertSame($context,$decoded['proto']['deviceSentMessage']['message']['messageContextInfo']);}
            }
            self::assertSame($secret,BinaryData::decode($this->store->get('message-secrets',hash('sha256',$jid."\0".$messageId)))['secret']);throw new \RuntimeException('Synthetic ACK timeout');
        };
        $messages=$this->messages($query);$catalog=new BusinessFormStore($this->store);$form=$catalog->create($definition);
        $forms=new BusinessFormsService($catalog,$this->store,function(array $snapshot,string $country)use(&$publications,$form):array{$publications++;self::assertSame($form,$snapshot);self::assertSame('BR',$country);return ['flowId'=>'9007199254740993','wellVersion'=>'V700'];},$messages->sendMessage(...));
        $options=['title'=>'MVO PHP','footer'=>'Formulário de teste','pushPriority'=>'high','mentions'=>[$jid],'quote'=>['id'=>'QUOTED-ORIGINAL','remoteJid'=>$jid,'message'=>['conversation'=>'Conversa original']]];
        try{$forms->send($form['id'],$jid,'BR',$options,$requestId);self::fail('Timeout should remain uncertain');}catch(\RuntimeException $error){self::assertSame('Synthetic ACK timeout',$error->getMessage());}
        self::assertSame(1,$publications);self::assertEqualsCanonicalizing([$jid,$ownCopy],array_keys($decodedByDevice));self::assertSame('unknown',$messages->getMessageStatus($messageId)['status']);self::assertSame('unknown',$forms->sendStatus($requestId)['state']);
        $forms->rename($form['id'],'Edited after timeout');$forms->delete($form['id']);self::assertSame($form,$forms->publicationStatus($requestId)['form']);
        unset($messages,$catalog,$forms,$this->signal,$this->store);gc_collect_cycles();$this->store=new SignalStore($this->paths[0]);$this->signal=new SignalProtocol($this->store,$this->credentials);$messages=$this->messages($query);
        $catalog=new BusinessFormStore($this->store);$forms=new BusinessFormsService($catalog,$this->store,static fn():array=>throw new \LogicException('Retry must not republish'),$messages->sendMessage(...));self::assertNull($forms->get($form['id']));self::assertSame($messageId,$forms->sendStatus($requestId)['messageId']);
        $result=$messages->retryMessage($messageId);self::assertSame('server_ack',$result['status']);self::assertSame($messageId,$result['id']);$messages->retryMessage($messageId);self::assertSame(2,$attempts,'An ACKed retry must not send again');
    }

    public function testFlexibleCheckoutResponseVersionAndCreationSourceSurviveRealSignalReceiveAndHistoryRestart():void
    {
        $peerJid='5511999999999:2@s.whatsapp.net';$peer=$this->peers([$peerJid])[$peerJid];$localKey=$this->store->generatePreKeys(1)[0];$peer['signal']->ensureSession($this->credentials['meJid'],self::bundle($this->credentials,$localKey));
        $params='{"flow_token":"Q1VTVE9NRVJfSU5GTw==","wa_flow_response_params":{"flow_creation_source":"FLEXIBLE_CHECKOUT","flow_id":"9007199254740993","response":[{"components":[{"name":"name","value":"Teste MVO"},{"name":"plan","value":"Avançado"}]}]},"optional":null}';
        $proto=['interactiveResponseMessage'=>['body'=>['text'=>'Dados enviados'],'nativeFlowResponseMessage'=>['name'=>'galaxy_message','version'=>4,'paramsJson'=>$params],
            'contextInfo'=>['stanzaId'=>'PUBLISHED-FORM-MESSAGE','participant'=>$this->credentials['meJid']]]];
        $encrypted=$peer['signal']->encrypt($this->credentials['meJid'],MessageCodec::pad(SchemaCodec::encode('Message',$proto)));$node=new BinaryNode('message',['id'=>'INCOMING-BUSINESS-FORM','from'=>$peerJid,'type'=>'text','t'=>'1792666800'],[new BinaryNode('enc',['type'=>$encrypted['type'],'v'=>'2'],$encrypted['ciphertext'])]);
        $query=static fn():BinaryNode=>throw new \LogicException('Receive must not query or publish');$messages=$this->messages($query);self::assertTrue($messages->handleNode($node));self::assertCount(1,$this->events);$event=$this->events[0][1];
        self::assertSame('message',$this->events[0][0]);self::assertSame('interactive-response',$event['type']);self::assertFalse($event['fromMe']);self::assertSame($params,$event['data']['nativeFlowResponseMessage']['paramsJson']);
        $response=$event['parsedFlowResponse'];self::assertSame(4,$response['version']);self::assertSame('Q1VTVE9NRVJfSU5GTw==',$response['flowToken']);self::assertSame('FLEXIBLE_CHECKOUT',$response['response']->flow_creation_source);self::assertSame('Teste MVO',$response['response']->response[0]->components[0]->value);self::assertObjectNotHasProperty('parsedFormResponse',(object)$event);
        self::assertSame('receipt',$this->receipts[0]->tag);self::assertArrayNotHasKey('type',$this->receipts[0]->attrs,'Delivery is distinct from reading a Business form response');
        unset($messages,$this->signal,$this->store);gc_collect_cycles();$this->store=new SignalStore($this->paths[0]);$this->signal=new SignalProtocol($this->store,$this->credentials);$messages=$this->messages($query);
        $saved=$messages->history()->store->getMessage(Jid::parse($peerJid)->bare(),'INCOMING-BUSINESS-FORM');self::assertNotNull($saved);self::assertSame($params,$saved['data']['nativeFlowResponseMessage']['paramsJson']);self::assertSame('PUBLISHED-FORM-MESSAGE',$saved['context']['stanzaId']);
        $parsed=ContentDecoder::decode(SchemaCodec::encode('Message',$saved['proto']))['parsedFlowResponse'];self::assertSame(4,$parsed['version']);self::assertSame('FLEXIBLE_CHECKOUT',$parsed['response']->flow_creation_source);$messages->handleNode($node);self::assertCount(1,$this->events,'Restart replay must not duplicate application delivery');
    }

    private function path():string{$path=tempnam(sys_get_temp_dir(),'mvo-business-form-transport-');if($path===false)throw new \RuntimeException('Cannot create test store');$this->paths[]=$path;return $path;}
    private static function credentials(string $jid):array
    {
        $credentials=CredentialStore::fresh();$credentials['registered']=true;$credentials['meJid']=$jid;$credentials['serverHasPreKeys']=true;$credentials['signedIdentity']=['details'=>'fixture','accountSignatureKey'=>random_bytes(32),'accountSignature'=>random_bytes(64),'deviceSignature'=>random_bytes(64)];return $credentials;
    }
    private function messages(\Closure $query):MessageService
    {
        return new MessageService($this->signal,$this->store,fn():array=>$this->credentials,function(array $credentials):void{$this->credentials=$credentials;},$query,function(BinaryNode $node):void{$this->receipts[]=$node;},function(string $name,array $event):void{$this->events[]=[$name,$event];});
    }
    private function peers(array $jids):array
    {
        $peers=[];foreach($jids as$jid){$credentials=self::credentials($jid);$store=new SignalStore($this->path());$peers[$jid]=['credentials'=>$credentials,'key'=>$store->generatePreKeys(1)[0],'signal'=>new SignalProtocol($store,$credentials),'store'=>$store];}return $peers;
    }
    private static function devices(array $devices):BinaryNode
    {
        $users=[];foreach($devices as$jid=>$ids)$users[]=new BinaryNode('user',['jid'=>$jid],[new BinaryNode('devices',[],[new BinaryNode('device-list',[],array_map(static fn(int $id):BinaryNode=>new BinaryNode('device',['id'=>(string)$id]),$ids))])]);return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('usync',[],[new BinaryNode('list',[],$users)])]);
    }
    private static function bundles(array $peers):BinaryNode
    {
        $users=[];foreach($peers as$jid=>$peer){$credentials=$peer['credentials'];$key=$peer['key'];$signed=$credentials['signedPreKey'];$users[]=new BinaryNode('user',['jid'=>$jid],[new BinaryNode('registration',[],pack('N',$credentials['registrationId'])),new BinaryNode('identity',[],$credentials['identityKeyPair']['public']),new BinaryNode('skey',[],[new BinaryNode('id',[],substr(pack('N',$signed['id']),1)),new BinaryNode('value',[],$signed['public']),new BinaryNode('signature',[],$signed['signature'])]),new BinaryNode('key',[],[new BinaryNode('id',[],substr(pack('N',$key['id']),1)),new BinaryNode('value',[],$key['public'])])]);}return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('list',[],$users)]);
    }
    private static function bundle(array $credentials,array $key):array{return ['registrationId'=>$credentials['registrationId'],'identityKey'=>$credentials['identityKeyPair']['public'],'signedPreKey'=>['id'=>$credentials['signedPreKey']['id'],'public'=>$credentials['signedPreKey']['public'],'signature'=>$credentials['signedPreKey']['signature']],'preKey'=>['id'=>$key['id'],'public'=>$key['public']]];}
}
