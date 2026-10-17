<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Crypto\Crypto;
use MVO\Protocol\{BinaryData,BinaryNode,Jid,SchemaCodec};
use MVO\Signal\SignalStore;

/** Sealed media reupload requests. Notification processing performs no network I/O. */
final class MediaRetryService
{
    /** @param \Closure():array<string,mixed> $credentials
     * @param \Closure(BinaryNode):void $send
     * @param \Closure(string,array<string,mixed>):void $emit */
    public function __construct(private readonly SignalStore $store,private readonly \Closure $credentials,
        private readonly \Closure $send,private readonly \Closure $emit) {}

    /** @param array<string,mixed> $input */
    public function begin(array $input,float $timeout):string
    {
        $id=$input['messageId'] ?? null; $chat=$input['chatJid'] ?? null; $key=$input['mediaKey'] ?? null;
        if (!is_string($id) || $id==='' || !is_string($chat) || !is_string($key)) throw new \InvalidArgumentException('Media reupload requires messageId, chatJid and mediaKey');
        if (isset($input['fromMe']) && !is_bool($input['fromMe'])) throw new \InvalidArgumentException('Media reupload fromMe must be boolean');
        if (!is_finite($timeout) || $timeout<=0 || $timeout>300) throw new \InvalidArgumentException('Invalid media reupload timeout');
        Crypto::length($key,32); $address=Jid::parse($chat)->canonical();
        if (!in_array($address->server,['s.whatsapp.net','lid','g.us','broadcast','bot'],true)) throw new \InvalidArgumentException('Media reupload does not support public channels');
        $existing=$this->store->get('media-retry-pending',$id);
        if ($existing!==null) {
            $pending=BinaryData::decode($existing);
            if (($pending['expiresAt'] ?? 0)>microtime(true)) {
                if (!hash_equals($pending['mediaKey'],$key) || $pending['chatJid']!==$address->bare()) throw new \LogicException('Conflicting media reupload request for this ID');
                return $id;
            }
        }
        $credentials=($this->credentials)(); $own=$credentials['meLid'] ?? $credentials['meJid'] ?? null;
        if (!is_string($own)) throw new \LogicException('Media reupload requires paired identity');
        $iv=random_bytes(12); $retryKey=Crypto::hkdf($key,32,'WhatsApp Media Retry Notification');
        $cipher=Crypto::encryptGcm($retryKey,$iv,SchemaCodec::encode('ServerErrorReceipt',['stanzaId'=>$id]),$id);
        $rmr=['jid'=>$address->bare(),'from_me'=>($input['fromMe'] ?? false) ? 'true' : 'false'];
        if (isset($input['participant']) && in_array($address->server,['g.us','broadcast'],true)) $rmr['participant']=Jid::parse($input['participant'])->canonical()->bare();
        $node=new BinaryNode('receipt',['id'=>$id,'to'=>Jid::parse($own)->canonical()->bare(),'type'=>'server-error'],[
            new BinaryNode('encrypt',[],[new BinaryNode('enc_p',[],$cipher),new BinaryNode('enc_iv',[],$iv)]),new BinaryNode('rmr',$rmr)]);
        $this->store->transaction(function() use($id,$key,$address,$timeout):void {
            $this->store->delete('media-retry-result',$id);
            $this->store->set('media-retry-pending',$id,BinaryData::encode(['mediaKey'=>$key,'chatJid'=>$address->bare(),'expiresAt'=>microtime(true)+$timeout]));
        });
        try { ($this->send)($node); }
        catch (\Throwable $error) { $this->store->delete('media-retry-pending',$id); throw $error; }
        return $id;
    }

    public function handleNotification(BinaryNode $node):bool
    {
        if ($node->tag!=='notification' || ($node->attrs['type'] ?? '')!=='mediaretry') return false;
        $id=$node->attrs['id'] ?? ''; $bytes=$this->store->get('media-retry-pending',$id); if ($bytes===null) return true;
        $pending=BinaryData::decode($bytes); if (!is_array($pending)) throw new \UnexpectedValueException('Invalid media retry pending state');
        $credentials=($this->credentials)(); $from=$node->attrs['from'] ?? ''; $own=false;
        try {
            foreach (['meJid','meLid'] as $field) if (is_string($credentials[$field] ?? null) && $from!=='' &&
                Jid::parse($from)->canonical()->bare()===Jid::parse($credentials[$field])->canonical()->bare()) $own=true;
        } catch (\InvalidArgumentException) { $own=false; }
        if (!$own) { ($this->emit)('media_reupload_error',['messageId'=>$id,'reason'=>'foreign_notification']); return true; }
        try {
            $code=$node->child('error')?->attrs['code'] ?? null; $direct=null;
            if ($code!==null) {
                if (!ctype_digit($code)) throw new \UnexpectedValueException('Invalid mediaretry error code');
                $code=(int)$code;
            } else {
                $encrypted=$node->child('encrypt'); $iv=$encrypted?->child('enc_iv')?->content; $cipher=$encrypted?->child('enc_p')?->content;
                if (!is_string($iv) || !is_string($cipher)) throw new \UnexpectedValueException('Missing encrypted mediaretry answer');
                $plain=Crypto::decryptGcm(Crypto::hkdf($pending['mediaKey'],32,'WhatsApp Media Retry Notification'),$iv,$cipher,$id);
                $decoded=SchemaCodec::decode('MediaRetryNotification',$plain);
                if (($decoded['stanzaId'] ?? '')!==$id) throw new \UnexpectedValueException('Mediaretry stanza ID mismatch');
                $code=$decoded['result'] ?? 0; $direct=$decoded['directPath'] ?? null;
            }
            $result=['messageId'=>$id,'result'=>match($code){1=>'success',2=>'not_found',3=>'decryption_error',default=>'general_error'},'resultCode'=>$code];
            if (is_string($direct) && $direct!=='') $result['directPath']=$direct;
            if ($code===1 && (!is_string($direct) || $direct==='')) throw new \UnexpectedValueException('Successful mediaretry answer missing directPath');
        } catch (\Throwable $error) {
            $result=['messageId'=>$id,'error'=>$error->getMessage()];
        }
        $this->store->transaction(function() use($id,$result):void {
            $this->store->set('media-retry-result',$id,BinaryData::encode($result));
            $this->store->delete('media-retry-pending',$id);
        });
        ($this->emit)('media_reupload',$result);
        return true;
    }

    /** @return array<string,mixed>|null */
    public function result(string $id):?array
    {
        $bytes=$this->store->get('media-retry-result',$id); if ($bytes===null) return null;
        $result=BinaryData::decode($bytes); if (!is_array($result)) throw new \UnexpectedValueException('Invalid media retry result');
        return $result;
    }
    public function expire(string $id):void { $this->store->delete('media-retry-pending',$id); }
}
