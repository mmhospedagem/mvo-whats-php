<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee;
use MVO\Messenger\E2ee\Protocol\{Address,BinaryCodec,BinaryNode,MessageEnvelope};
use MVO\Messenger\E2ee\Signal\{SignalProtocol,SignalStore};
use MVO\Messenger\MessengerException;

/** Atomic Signal + application journal. Network submission and receipt sending stay outside transactions. */
final class MessageEngine
{
    private BinaryCodec $codec;private string $ownDevice;private string $ownUser;
    public function __construct(private readonly SignalStore $store,private readonly SignalProtocol $signal,string $ownDevice)
    {
        $this->ownDevice=Address::device($ownDevice);$this->ownUser=Address::bare($ownDevice);$this->codec=new BinaryCodec();
    }
    /** Target devices must come from current authenticated discovery (recipient AND own other devices).
     * Every target needs an explicitly trusted, signature-verified Signal session first. No partial fan-out.
     * @param list<string> $targetDevices
     * @return array<string,mixed> Safe outbox projection; no ciphertext or franking keys.
     */
    public function prepareText(string $messageId,string $idempotencyKey,string $recipient,string $text,array $targetDevices):array
    {
        self::id($messageId);if($idempotencyKey===''||strlen($idempotencyKey)>256)throw new \InvalidArgumentException('Use a bounded encrypted message idempotency key');
        if(Address::bare($recipient)!==$recipient||$recipient===$this->ownUser)throw new \InvalidArgumentException('Use the other recipient native Messenger user');
        if(!array_is_list($targetDevices)||$targetDevices===[]||count($targetDevices)>512)throw new \InvalidArgumentException('Use the current bounded authenticated device roster');
        $targets=[];$hasRecipient=false;
        foreach($targetDevices as$target){$target=Address::device($target);if($target===$this->ownDevice)continue;$bare=Address::bare($target);if(!in_array($bare,[$recipient,$this->ownUser],true)||isset($targets[$target]))throw new \InvalidArgumentException('Wrong or duplicate encrypted fan-out target');$targets[$target]=true;if($bare===$recipient)$hasRecipient=true;}
        if(!$hasRecipient)throw new MessengerException('messenger_recipient_devices_unavailable','No recipient device was confirmed');$devices=array_keys($targets);sort($devices,SORT_STRING);
        $key=hash('sha256',$idempotencyKey);$hash=hash('sha256',json_encode([$messageId,$recipient,$text,$devices],JSON_THROW_ON_ERROR));
        return $this->store->transaction(function()use($messageId,$recipient,$text,$devices,$key,$hash){
            $existing=$this->store->get('outbox-keys',$key);
            if($existing!==null){$saved=$this->record('outbox',$existing);if(($saved['payload_hash']??null)!==$hash)throw new MessengerException('messenger_idempotency_conflict','This encrypted command key belongs to different message content');return self::project($saved);}
            if($this->store->get('outbox',$messageId)!==null)throw new MessengerException('messenger_message_id_conflict','This encrypted stanza ID already exists');
            $envelope=MessageEnvelope::text($text);$children=[];
            foreach($devices as$device){$dsm=Address::bare($device)===$this->ownUser?$recipient:null;$encrypted=$this->signal->encrypt($device,MessageEnvelope::transport($envelope['application'],$dsm));$children[]=new BinaryNode('to',['jid'=>$device],[new BinaryNode('enc',['v'=>'3','type'=>$encrypted['type']],$encrypted['ciphertext'])]);}
            $trace=random_bytes(16);$trace[6]=chr((ord($trace[6])&15)|64);$trace[8]=chr((ord($trace[8])&63)|128);
            $node=new BinaryNode('message',['id'=>$messageId,'to'=>$recipient,'type'=>'text'],[new BinaryNode('participants',[],$children),new BinaryNode('franking',[],[new BinaryNode('franking_tag',[],$envelope['franking_tag'])]),new BinaryNode('trace',[],[new BinaryNode('request_id',[],$trace)])]);
            $record=['id'=>$messageId,'recipient'=>$recipient,'payload_hash'=>$hash,'state'=>'prepared','wire'=>base64_encode($this->codec->encode($node,true)),'created_at'=>time(),'updated_at'=>time()];
            $this->save('outbox',$messageId,$record);$this->store->set('outbox-keys',$key,$messageId);return self::project($record);
        });
    }
    /** All ratchet/ciphertext preparation is already durable. Never resends after submission uncertainty.
     * @param callable(BinaryNode):void $send Certificate-authenticated NoiseConnection::sendNode.
     * @return array<string,mixed>
     */
    public function submit(string $messageId,callable $send):array
    {
        $record=$this->store->transaction(function()use($messageId){$record=$this->record('outbox',$messageId);if(in_array($record['state'],['server_ack','delivered','read'],true))return $record;if($record['state']!=='prepared')throw new MessengerException('messenger_encrypted_send_uncertain','This encrypted message may already have been submitted; reconcile its receipts before another send');$record['state']='unknown';$record['updated_at']=time();$this->save('outbox',$messageId,$record);return $record;});
        if(in_array($record['state'],['server_ack','delivered','read'],true))return self::project($record);
        try{$wire=base64_decode($record['wire'],true);if($wire===false)throw new \UnexpectedValueException('Corrupt encrypted outbox');$send($this->codec->decode($wire,true));}
        catch(\Throwable){throw new MessengerException('messenger_encrypted_send_uncertain','Encrypted submission has an uncertain outcome; the same saved message must not be retransmitted automatically');}
        return $this->store->transaction(function()use($messageId){$record=$this->record('outbox',$messageId);if($record['state']==='unknown'){$record['state']='submitted';$record['updated_at']=time();$this->save('outbox',$messageId,$record);}return self::project($record);});
    }
    /** @return array<string,mixed>|null */
    public function outgoing(string $messageId):?array{self::id($messageId);return $this->store->get('outbox',$messageId)===null?null:self::project($this->record('outbox',$messageId));}
    /** Only pass nodes received from the authenticated account socket, never arbitrary Web metadata.
     * @return array<string,mixed>
     */
    public function acknowledge(BinaryNode $node):array
    {
        $id=$node->attrs['id']??'';self::id($id);
        return $this->store->transaction(function()use($node,$id){$record=$this->record('outbox',$id);$next=null;
            if($node->tag==='ack'&&($node->attrs['class']??null)==='message'&&!isset($node->attrs['error'])&&$node->child('error')===null){if(isset($node->attrs['from'])&&Address::bare($node->attrs['from'])!==$record['recipient'])throw new \UnexpectedValueException('Wrong encrypted message ACK source');$next='server_ack';}
            elseif($node->tag==='receipt'){$from=Address::bare($node->attrs['from']??'');if($from!==$record['recipient'])throw new \UnexpectedValueException('Wrong encrypted receipt sender');$type=$node->attrs['type']??'delivery';$next=match($type){'delivery'=>'delivered','read'=>'read',default=>throw new \UnexpectedValueException('Unsupported encrypted receipt type')};}
            if($next===null||$record['state']==='prepared')throw new \UnexpectedValueException('Uncorrelated encrypted message result');
            $rank=['unknown'=>0,'submitted'=>0,'server_ack'=>1,'delivered'=>2,'read'=>3];if($rank[$next]>($rank[$record['state']]??0)){$record['state']=$next;$record['updated_at']=time();$this->save('outbox',$id,$record);}return self::project($record);
        });
    }
    /** Decrypt + envelope/franking validation + deduplication + receipt journal commit together.
     * @return array<string,mixed>
     */
    public function receive(BinaryNode $node):array
    {
        if($node->tag!=='message')throw new \InvalidArgumentException('Expected an encrypted Messenger message');$id=$node->attrs['id']??'';self::id($id);$from=$node->attrs['from']??'';$sender=Address::device($node->attrs['participant']??$from);
        if(Address::bare($sender)!==Address::bare($from)||$sender===$this->ownDevice)throw new \UnexpectedValueException('Invalid encrypted sender scope');
        $enc=self::single($node,'enc');if(($enc->attrs['v']??null)!=='3'||!in_array($enc->attrs['type']??null,['msg','pkmsg'],true)||!is_string($enc->content))throw new \UnexpectedValueException('Unsupported encrypted Messenger body');
        $tag=null;$franking=$node->child('franking');if($franking!==null){$tagNode=self::single($franking,'franking_tag');if(!is_string($tagNode->content))throw new \UnexpectedValueException('Invalid franking bytes');$tag=$tagNode->content;}
        $dedup=hash('sha256',$sender."\0".$id);$digest=hash('sha256',$enc->attrs['type']."\0".$enc->content."\0".($tag??''));
        return $this->store->transaction(function()use($id,$sender,$enc,$tag,$dedup,$digest){
            $seen=$this->store->get('inbox-dedup',$dedup);if($seen!==null){if(!hash_equals($seen,$digest))throw new \UnexpectedValueException('Conflicting encrypted message replay');return ['id'=>$id,'sender'=>$sender,'duplicate'=>true];}
            $plain=$this->signal->decrypt($sender,$enc->attrs['type'],$enc->content);$envelope=MessageEnvelope::read($plain,$tag);$fromMe=Address::bare($sender)===$this->ownUser;
            if(!$fromMe&&$envelope['destination']!==null)throw new \UnexpectedValueException('Unexpected DSM in recipient delivery');if($fromMe&&$envelope['destination']===null)throw new \UnexpectedValueException('Missing destination on own-device copy');
            $seq=(int)($this->store->get('meta','inbox-sequence')??'0')+1;if($seq<1)throw new \OverflowException('Encrypted inbox sequence exhausted');
            $message=['sequence'=>$seq,'id'=>$id,'sender'=>$sender,'conversation'=>$fromMe?$envelope['destination']:Address::bare($sender),'from_me'=>$fromMe,'text'=>$envelope['text'],'franking_verified'=>$envelope['franking_verified'],'received_at'=>time(),'duplicate'=>false];
            $this->save('inbox',(string)$seq,$message);$this->store->set('inbox-dedup',$dedup,$digest);$this->store->set('meta','inbox-sequence',(string)$seq);
            $this->save('pending-receipts',(string)$seq,['id'=>$id,'to'=>$sender,'sent'=>false]);return $message;
        });
    }
    /** Plaintext content is private application data; consumers decide where to expose it.
     * @return list<array<string,mixed>>
     */
    public function messages(int $after=0,int $limit=100):array
    {
        if($after<0||$limit<1||$limit>500)throw new \InvalidArgumentException('Use bounded encrypted inbox pagination');$last=(int)($this->store->get('meta','inbox-sequence')??'0');$out=[];for($id=$after+1;$id<=$last&&count($out)<$limit;++$id)$out[]=$this->record('inbox',(string)$id);return $out;
    }
    /** @return list<array{sequence:int,node:BinaryNode}> */
    public function pendingReceipts(int $after=0,int $limit=100):array
    {
        if($after<0||$limit<1||$limit>500)throw new \InvalidArgumentException('Use bounded receipt pagination');$last=(int)($this->store->get('meta','inbox-sequence')??'0');$out=[];
        for($i=$after+1;$i<=$last&&$i<=$after+500;++$i){$receipt=$this->record('pending-receipts',(string)$i);if($receipt['sent']===false)$out[]=['sequence'=>$i,'node'=>new BinaryNode('receipt',['to'=>$receipt['to'],'id'=>$receipt['id']])];if(count($out)>=$limit)break;}return $out;
    }
    public function receiptSent(int $sequence):void{$this->store->transaction(function()use($sequence){$record=$this->record('pending-receipts',(string)$sequence);$record['sent']=true;$this->save('pending-receipts',(string)$sequence,$record);});}
    /** @return array<string,mixed> */
    private function record(string $namespace,string $id):array{$raw=$this->store->get($namespace,$id);if($raw===null)throw new \UnexpectedValueException('Encrypted journal record not found');$record=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($record)||array_is_list($record))throw new \UnexpectedValueException('Invalid encrypted journal record');return $record;}
    /** @param array<string,mixed> $record */
    private function save(string $namespace,string $id,#[\SensitiveParameter]array $record):void{$this->store->set($namespace,$id,json_encode($record,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));}
    /** @param array<string,mixed> $record
     * @return array<string,mixed>
     */
    private static function project(array $record):array{return ['id'=>$record['id'],'recipient'=>$record['recipient'],'state'=>$record['state'],'created_at'=>$record['created_at'],'updated_at'=>$record['updated_at'],'delivery_confirmed'=>in_array($record['state'],['delivered','read'],true),'read_confirmed'=>$record['state']==='read'];}
    private static function id(string $id):void{if($id===''||strlen($id)>128||preg_match('/[\x00-\x20\x7f]/',$id))throw new \InvalidArgumentException('Invalid encrypted message ID');}
    private static function single(BinaryNode $node,string $tag):BinaryNode{$found=[];if(is_array($node->content))foreach($node->content as$child)if($child->tag===$tag)$found[]=$child;if(count($found)!==1)throw new \UnexpectedValueException('Ambiguous encrypted Messenger child');return $found[0];}
    public function __debugInfo():array{return ['encrypted_message_engine'=>'[PRIVATE]'];}
    public function __serialize():array{throw new \LogicException('Do not serialize an encrypted message engine');}
}
