<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee;

use MVO\Messenger\Auth\SessionStore;
use MVO\Messenger\E2ee\Noise\NoiseConnection;
use MVO\Messenger\E2ee\Protocol\{Address,BinaryCodec,BinaryNode,BlueWebVersion,ClientPayload,DeviceDirectory,RosterVerifier};
use MVO\Messenger\E2ee\Signal\{SignalProtocol,SignalStore};
use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\BootstrapConfig;
use MVO\Messenger\Transport\{Socket,WebSocket};

/** Explicit owner-bound encrypted lifecycle. Never provisions/registers a device.
 * No production ICDC verifier is bundled: the default runtime can authenticate,
 * initialize prekeys and inspect rosters, but cannot encrypt/decrypt application text.
 */
final class Runtime implements \JsonSerializable
{
    private \Closure $bootstrapProvider;private ?\Closure $ownerGuard;private ?\Closure $socketFactory;private ?\Closure $eventHandler;
    private ?NoiseConnection $connection=null;private ?SignalStore $state=null;private ?SignalProtocol $signal=null;private ?MessageEngine $engine=null;
    private ?string $ownDevice=null;private bool $loggedIn=false;private bool $prekeysConfirmed=false;private bool $inCallback=false;private bool $busy=false;
    private int $catExpiresAt=0;
    /** @var array<string,mixed>|null */private ?array $review=null;

    /** @param callable():BootstrapConfig $bootstrapProvider Current private owner bootstrap, including CAT and SiteData.
     * @param callable():void|null $ownerGuard Parent lifecycle/callback guard; never grants ownership by itself.
     * @param callable(string,string,array<string,string>):Socket|null $socketFactory Explicit test/transport seam.
     * @param callable(string,array<string,mixed>):void|null $eventHandler Private application events; callbacks cannot execute commands.
     * @param string|null $testRootPublic Synthetic Noise peer root only. Production leaves null to pin the real root.
     */
    public function __construct(
        private readonly SessionStore $store,private readonly string $accountId,callable $bootstrapProvider,
        private readonly string $browserAgent,?callable $ownerGuard=null,?callable $socketFactory=null,
        private readonly ?RosterVerifier $rosterVerifier=null,?callable $eventHandler=null,
        private readonly float $timeoutSeconds=20,private readonly ?string $testRootPublic=null,
    ) {
        if(preg_match('/^[1-9][0-9]{0,24}$/D',$accountId)!==1||!is_finite($timeoutSeconds)||$timeoutSeconds<=0||$timeoutSeconds>120)throw new \InvalidArgumentException('Invalid encrypted runtime owner or timeout');
        if($browserAgent===''||strlen($browserAgent)>2048||preg_match('/[\x00-\x1f\x7f]/',$browserAgent))throw new \InvalidArgumentException('Use the current browser user agent');
        $this->bootstrapProvider=\Closure::fromCallable($bootstrapProvider);$this->ownerGuard=$ownerGuard===null?null:\Closure::fromCallable($ownerGuard);$this->socketFactory=$socketFactory===null?null:\Closure::fromCallable($socketFactory);$this->eventHandler=$eventHandler===null?null:\Closure::fromCallable($eventHandler);
        $this->guard();
    }

    /** Opens Noise using a confirmed local registration, then waits for the actual
     * encrypted login success and the matching prekey-upload IQ result.
     * This explicit operation may publish saved public prekeys; never registers a device.
     * @return array<string,mixed>
     */
    public function connect(int $initialPrekeys=50):array
    {
        $this->guard();if($initialPrekeys<1||$initialPrekeys>1000)throw new \InvalidArgumentException('Use 1 to 1000 initial prekeys');
        foreach(['gmp','zlib']as$extension)if(!extension_loaded($extension))throw new MessengerException('messenger_e2ee_dependency_missing','The encrypted runtime requires the '.$extension.' extension before opening its transport');
        if($this->loggedIn&&$this->connection?->isEstablished())return $this->status();
        if($this->busy)throw new \LogicException('Encrypted runtime is already processing a command');$this->busy=true;
        try{
            $bootstrap=($this->bootstrapProvider)();
            if($bootstrap->userId!==$this->accountId)throw new MessengerException('messenger_device_identity_mismatch','The current encrypted bootstrap belongs to another account');
            if(!in_array($bootstrap->origin,['https://www.facebook.com','https://www.messenger.com','https://business.facebook.com'],true))throw new MessengerException('messenger_encrypted_origin_unavailable','Use the authenticated Facebook or Messenger Web origin');
            [$keys,$device]=$this->registeredDevice();$this->ownDevice=$device;
            $token=CryptoAuthToken::fromBootstrap($bootstrap);$version=BlueWebVersion::fromBootstrap($bootstrap);
            $payload=ClientPayload::login($keys,Address::parse($device)['device'],$token,$version,$this->browserAgent);$this->catExpiresAt=$token->expiresAt;
            $this->state=new SignalStore($this->store,$this->accountId);$this->signal=new SignalProtocol($this->state,$keys->signalCredentials());$this->engine=new MessageEngine($this->state,$this->signal,$device);
            $publication=$this->record('runtime-prekeys',$device);
            if(($publication['state']??null)==='unknown')throw new MessengerException('messenger_prekey_upload_uncertain','A previous public-prekey upload has an uncertain outcome; reconcile it before another upload');
            $headers=['User-Agent'=>$this->browserAgent];
            $socket=$this->socketFactory!==null?($this->socketFactory)(NoiseConnection::URL,$bootstrap->origin,$headers):new WebSocket(NoiseConnection::URL,$bootstrap->origin,$headers,timeoutSeconds:$this->timeoutSeconds);
            $this->connection=new NoiseConnection($socket,$this->timeoutSeconds,$this->testRootPublic);$this->connection->start($keys->noiseKeyPair(),$payload);
            $deadline=microtime(true)+$this->timeoutSeconds;$count=0;
            do{
                $this->guard();$node=$this->connection->receiveNode(max(0,$deadline-microtime(true)));
                if($node===null)throw new MessengerException('messenger_encrypted_login_timeout','Noise was authenticated but Messenger did not confirm the encrypted login');
                if($node->tag==='success'){
                    if(isset($node->attrs['jid'])&&(($jid=Address::device($node->attrs['jid']))!==$device&&$node->attrs['jid']!==Address::bare($device)))throw new MessengerException('messenger_device_identity_mismatch','The encrypted success identifies another account or device');
                    if($node->child('error')!==null)throw new MessengerException('messenger_encrypted_login_rejected','Messenger did not confirm the encrypted login');
                    $this->loggedIn=true;break;
                }
                if(in_array($node->tag,['failure','stream:error','stream:end'],true))throw new MessengerException('messenger_encrypted_login_rejected','Messenger rejected or closed the encrypted login');
                // Do not interpret metadata or a certificate as login success.
                if(++$count>100)throw new MessengerException('messenger_encrypted_login_unconfirmed','No recognized encrypted login result was received');
            }while(microtime(true)<$deadline);
            if(!$this->loggedIn)throw new MessengerException('messenger_encrypted_login_timeout','The encrypted login was not confirmed before its deadline');
            $pending=$this->state->pendingPreKeys();
            if($pending===[]&&($publication['state']??null)!=='confirmed'){$this->state->generatePreKeys($initialPrekeys);$pending=$this->state->pendingPreKeys();}
            if($pending!==[]){
                $id=self::requestId();$ids=array_column($pending,'id');
                $this->save('runtime-prekeys',$device,['state'=>'unknown','ids'=>$ids,'request_id'=>$id,'identity_fingerprint'=>hash('sha256',$keys->identityPublic())]);
                try{$reply=$this->request(DeviceDirectory::uploadPrekeys($id,$keys,$pending));DeviceDirectory::result($reply,$id);
                    $this->state->transaction(function()use($device,$ids,$keys){$this->state->markPreKeysUploaded($ids);$this->save('runtime-prekeys',$device,['state'=>'confirmed','ids'=>$ids,'identity_fingerprint'=>hash('sha256',$keys->identityPublic())]);});
                }catch(\Throwable){throw new MessengerException('messenger_prekey_upload_uncertain','The encrypted prekey upload was not confirmed; its saved keys must not be regenerated or blindly republished');}
            }
            $this->prekeysConfirmed=true;$this->emit('encrypted_transport_connected',$this->status());return $this->status();
        }catch(\Throwable $error){$this->closeInternal();throw $error;}finally{$this->busy=false;}
    }

    /** Queries recipient and own devices/bundles. Does not approve identities.
     * Fingerprints/snapshot are safe to review; no private keys or CAT are returned.
     * @return array<string,mixed>
     */
    public function reviewPeer(string $recipient):array
    {
        $this->requireTextTransport();return $this->command(fn()=>$this->fetchReview($recipient));
    }

    /** Explicitly approves the exact currently inspected identities. Native roster
     * verification is independently mandatory; a reviewed hash cannot replace ICDC.
     * @return array<string,mixed>
     */
    public function approvePeer(string $snapshotHash):array
    {
        $this->requireTextTransport();if($this->busy)throw new \LogicException('Encrypted runtime commands cannot overlap');
        if(preg_match('/^[a-f0-9]{64}$/D',$snapshotHash)!==1||$this->review===null||!hash_equals($this->review['snapshot_hash'],$snapshotHash))throw new \InvalidArgumentException('Review the current exact Messenger peer snapshot');
        $this->requireVerifiedRoster();$review=$this->review;
        $this->state->transaction(function()use($review){foreach($review['bundles']as$jid=>$bundle){$this->state->approveIdentity($jid,$bundle['identityKey'],hash('sha256',$bundle['identityKey']));$this->signal->ensureSession($jid,$bundle);$this->save('runtime-reviewed-devices',$jid,['identity_fingerprint'=>hash('sha256',$bundle['identityKey'])]);}$this->save('runtime-reviewed-rosters',$review['recipient'],['snapshot_hash'=>$review['snapshot_hash']]);});
        return self::reviewProjection($review)+['identities_approved'=>true];
    }

    /** Fresh directory/prekey/roster validation precedes a NEW encrypted command.
     * Confirmed and uncertain replays are resolved locally, without discovery or resend.
     * @return array<string,mixed>
     */
    public function sendText(string $recipient,string $text,string $idempotencyKey,?string $messageId=null):array
    {
        $this->requireTextTransport();if($this->busy)throw new \LogicException('Encrypted runtime commands cannot overlap');$this->recipient($recipient);
        if($idempotencyKey===''||strlen($idempotencyKey)>256)throw new \InvalidArgumentException('Use a bounded encrypted command idempotency key');
        $hash=hash('sha256',json_encode([$recipient,$text],JSON_THROW_ON_ERROR));$commandKey=hash('sha256',$idempotencyKey);$old=$this->record('runtime-commands',$commandKey);
        if($old!==null){if(!hash_equals($old['payload_hash'],$hash)||($messageId!==null&&$old['id']!==$messageId))throw new MessengerException('messenger_idempotency_conflict','This encrypted command key belongs to different content');return $this->engine->submit($old['id'],$this->sendNode(...));}
        $this->requireVerifier();
        return $this->command(function()use($recipient,$text,$idempotencyKey,$messageId,$hash,$commandKey){
            $this->fetchReview($recipient);$this->requireVerifiedRoster();$review=$this->review;$approved=$this->record('runtime-reviewed-rosters',$recipient);
            if(($approved['snapshot_hash']??null)!==$review['snapshot_hash'])throw new MessengerException('messenger_peer_roster_review_required','Review and approve the current complete peer and own-device identity snapshot before encrypted text');
            $id=$messageId??strtoupper(bin2hex(random_bytes(16)));
            $this->state->transaction(function()use($review,$id,$idempotencyKey,$recipient,$text,$commandKey,$hash){foreach($review['bundles']as$jid=>$bundle)$this->signal->ensureSession($jid,$bundle);$this->engine->prepareText($id,$idempotencyKey,$recipient,$text,$review['targets']);$this->save('runtime-commands',$commandKey,['id'=>$id,'payload_hash'=>$hash]);});
            return $this->engine->submit($id,$this->sendNode(...));
        });
    }

    /** Processes one authenticated node. Unknown peer bodies stay in the private
     * durable queue and are not exposed as plaintext or acknowledged as read.
     * @return array<string,mixed>|null
     */
    public function poll(float $timeoutSeconds=0.5):?array
    {
        $this->requireTextTransport();if(!is_finite($timeoutSeconds)||$timeoutSeconds<0||$timeoutSeconds>120)throw new \InvalidArgumentException('Use a bounded encrypted poll timeout');
        return $this->command(function()use($timeoutSeconds){$node=$this->connection->receiveNode($timeoutSeconds);if($node===null){if(!$this->connection->isEstablished()){$this->closeInternal();throw new MessengerException('messenger_encrypted_connection_closed','The encrypted connection closed');}return null;}return $this->process($node);});
    }

    /** @return list<array<string,mixed>> */
    public function retryPendingIncoming(int $after=0,int $limit=100):array
    {
        $this->requireTextTransport();if($after<0||$limit<1||$limit>500)throw new \InvalidArgumentException('Use bounded encrypted inbox processing');$this->requireVerifier();
        return $this->command(function()use($after,$limit){$out=[];$last=(int)($this->state->get('runtime-meta','pending-incoming-sequence')??'0');for($i=$after+1;$i<=$last&&$i<=$after+500&&count($out)<$limit;++$i){$wire=$this->state->get('runtime-pending-incoming',(string)$i);if($wire===null)continue;$decoded=base64_decode($wire,true);if($decoded===false)throw new \UnexpectedValueException('Corrupt pending encrypted body');$out[]=$this->acceptPending((new BinaryCodec())->decode($decoded,true),$i);}return $out;});
    }

    /** Delivery receipts only. No implicit read receipt. @return int Number sent. */
    public function flushReceipts(int $after=0,int $limit=100):int
    {
        $this->requireTextTransport();return $this->command(function()use($after,$limit){$sent=0;foreach($this->engine->pendingReceipts($after,$limit)as$receipt){$this->sendNode($receipt['node']);$this->engine->receiptSent($receipt['sequence']);++$sent;}return $sent;});
    }
    /** @return list<array<string,mixed>> */
    public function messages(int $after=0,int $limit=100):array{$this->guard();return $this->engine?->messages($after,$limit)??[];}
    /** @return array<string,mixed>|null */
    public function outgoing(string $id):?array{$this->guard();return $this->engine?->outgoing($id);}
    public function close():void{$this->guard();$this->closeInternal();}
    /** Idempotent cleanup hook for the parent owner, including after its lock was released. */
    public function ownerStopped():void{$this->closeInternal();}

    /** @return array<string,mixed> */
    public function status():array
    {
        $active=$this->loggedIn&&$this->prekeysConfirmed&&$this->connection?->isEstablished()&&$this->store->ownsLock()&&$this->store->get('account_id')===$this->accountId&&time()<$this->catExpiresAt;
        return ['account_id'=>$this->accountId,'device_address'=>$this->ownDevice,'noise_established'=>$this->connection?->isEstablished()??false,'login_confirmed'=>$active&&$this->loggedIn,'prekeys_confirmed'=>$active&&$this->prekeysConfirmed,
            'roster_verifier_configured'=>$this->rosterVerifier!==null,'e2ee_ready'=>false,'secure_history_available'=>false,
            'missing'=>$this->rosterVerifier===null?['native_icdc_roster_verification']:['live_server_interoperability_validation','maintenance_retry_and_batch_receipt_profiles']];
    }

    /** @return array<string,mixed> */
    private function fetchReview(string $recipient):array
    {
        $this->recipient($recipient);$this->review=null;$own=Address::bare($this->ownDevice);$users=[$recipient,$own];$id=self::requestId();$directory=$this->request(DeviceDirectory::discovery($id,$users));$rosters=DeviceDirectory::devices($directory,$id,$users);
        if(!in_array($this->ownDevice,$rosters[$own]['devices'],true))throw new MessengerException('messenger_registered_device_not_listed','The current discovery does not include the registered local device');
        $targets=array_values(array_filter([...$rosters[$recipient]['devices'],...$rosters[$own]['devices']],fn(string $jid):bool=>$jid!==$this->ownDevice));sort($targets,SORT_STRING);
        if($rosters[$recipient]['devices']===[]||count($targets)>256)throw new MessengerException('messenger_recipient_devices_unavailable','No supported complete recipient-device roster is available');
        $id=self::requestId();$bundles=DeviceDirectory::bundles($this->request(DeviceDirectory::prekeys($id,$targets)),$id,$targets);$verified=false;
        if($this->rosterVerifier!==null){$this->rosterVerifier->verify($directory,$rosters,$bundles);$verified=true;}
        $fingerprints=[];foreach($targets as$target)$fingerprints[$target]=hash('sha256',$bundles[$target]['identityKey']);
        $scope=[];ksort($rosters,SORT_STRING);foreach($rosters as$user=>$roster){$devices=$roster['devices'];sort($devices,SORT_STRING);$scope[$user]=['dhash'=>$roster['dhash'],'devices'=>$devices];}
        $hash=hash('sha256',json_encode([$this->accountId,$this->ownDevice,$recipient,$scope,$fingerprints],JSON_THROW_ON_ERROR));
        $this->review=['recipient'=>$recipient,'snapshot_hash'=>$hash,'device_fingerprints'=>$fingerprints,'native_roster_verified'=>$verified,'targets'=>$targets,'bundles'=>$bundles];return self::reviewProjection($this->review);
    }

    private function requireVerifier():void{if($this->rosterVerifier===null)throw new MessengerException('messenger_icdc_verification_unavailable','Native ICDC roster verification is not implemented; an inspected hash or identity approval cannot replace that protocol verification');}
    private function requireVerifiedRoster():void{$this->requireVerifier();if(($this->review['native_roster_verified']??false)!==true)throw new MessengerException('messenger_peer_roster_unverified','The current peer roster did not pass native identity verification');}
    private function recipient(string $recipient):void{if(Address::bare($recipient)!==$recipient||$recipient===$this->accountId.'@msgr')throw new \InvalidArgumentException('Use the other recipient native Messenger user ID');}

    /** @return array<string,mixed> */
    private function process(BinaryNode $node):array
    {
        if(in_array($node->tag,['failure','stream:error','stream:end'],true)){$this->closeInternal();throw new MessengerException('messenger_encrypted_connection_closed','Messenger closed or rejected the encrypted connection');}
        if($node->tag==='message'){
            $id=$node->attrs['id']??'';$sender=Address::device($node->attrs['participant']??($node->attrs['from']??''));if($id===''||strlen($id)>128||preg_match('/[\x00-\x20\x7f]/',$id)||Address::bare($sender)!==Address::bare($node->attrs['from']??''))throw new \UnexpectedValueException('Invalid encrypted incoming identifier or sender scope');$key=hash('sha256',$sender."\0".$id);$wire=base64_encode((new BinaryCodec())->encode($node,true));
            $sequence=$this->state->transaction(function()use($key,$wire){$existing=$this->state->get('runtime-incoming-keys',$key);if($existing!==null){$saved=$this->state->get('runtime-pending-incoming',$existing);if($saved!==null&&!hash_equals($saved,$wire))throw new \UnexpectedValueException('Conflicting pending encrypted body');return(int)$existing;}$next=(int)($this->state->get('runtime-meta','pending-incoming-sequence')??'0')+1;$this->state->set('runtime-pending-incoming',(string)$next,$wire);$this->state->set('runtime-incoming-keys',$key,(string)$next);$this->state->set('runtime-meta','pending-incoming-sequence',(string)$next);return$next;});
            // Server ACK means durable receipt of ciphertext, never decryption/read.
            $this->sendNode(new BinaryNode('ack',['class'=>'message','id'=>$id,'to'=>$node->attrs['from']??$sender]));
            return $this->acceptPending($node,$sequence);
        }
        if(($node->tag==='ack'&&($node->attrs['class']??null)==='message')||$node->tag==='receipt'){
            $id=$node->attrs['id']??'';if($this->engine->outgoing($id)===null)return['type'=>'uncorrelated_receipt'];$result=$this->engine->acknowledge($node);
            if($node->tag==='receipt'){$attrs=['class'=>'receipt','id'=>$id,'to'=>$node->attrs['from']];if(isset($node->attrs['type']))$attrs['type']=$node->attrs['type'];$this->sendNode(new BinaryNode('ack',$attrs));}
            $this->emit('encrypted_message_receipt',$result);return['type'=>'receipt','result'=>$result];
        }
        return ['type'=>'unhandled_node','tag'=>$node->tag];
    }
    /** @return array<string,mixed> */
    private function acceptPending(BinaryNode $node,int $sequence):array
    {
        try{
            $this->requireVerifier();$sender=Address::device($node->attrs['participant']??($node->attrs['from']??''));$approved=$this->record('runtime-reviewed-devices',$sender);
            if($approved===null)throw new MessengerException('messenger_peer_roster_review_required','Review and approve this peer device roster before exposing encrypted plaintext');
            $message=$this->state->transaction(function()use($node,$sequence){$message=$this->engine->receive($node);$this->state->delete('runtime-pending-incoming',(string)$sequence);return$message;});
            $this->emit('encrypted_message',$message);return['type'=>'message','message'=>$message];
        }catch(MessengerException $error){$result=['type'=>'message_blocked','id'=>$node->attrs['id']??null,'reason'=>$error->reason,'pending_sequence'=>$sequence];$this->emit('encrypted_message_blocked',$result);return$result;}
    }

    private function request(BinaryNode $request):BinaryNode
    {
        $this->sendNode($request);$deadline=microtime(true)+$this->timeoutSeconds;$count=0;
        do{$this->guard();$node=$this->connection->receiveNode(max(0,$deadline-microtime(true)));if($node===null)break;
            if($node->tag==='iq'&&($node->attrs['id']??null)===$request->attrs['id']){if(isset($node->attrs['from'])&&!in_array($node->attrs['from'],['s.whatsapp.net',$this->accountId.'@msgr',$this->ownDevice],true))throw new \UnexpectedValueException('Wrong Messenger IQ result source');DeviceDirectory::result($node,$request->attrs['id']);return$node;}
            $this->process($node);if(++$count>1000)break;
        }while(microtime(true)<$deadline);
        $this->closeInternal();throw new MessengerException('messenger_encrypted_iq_unconfirmed','No matching encrypted IQ result arrived before its deadline');
    }
    private function sendNode(BinaryNode $node):void{$this->guard();if(!$this->loggedIn||!$this->connection?->isEstablished())throw new MessengerException('messenger_encrypted_login_required','An authenticated encrypted login is required');$this->connection->sendNode($node);}
    private function requireTextTransport():void{$this->guard();if(!$this->loggedIn||!$this->prekeysConfirmed||!$this->connection?->isEstablished())throw new MessengerException('messenger_encrypted_initialization_required','Wait for real encrypted login and confirmed prekey initialization');}
    private function guard():void
    {
        if($this->inCallback)throw new \LogicException('Execute encrypted commands in the outer loop after callbacks return');
        try{($this->ownerGuard)?->__invoke();if(!$this->store->ownsLock())throw new \LogicException('Encrypted runtime requires the Messenger owner lock');if($this->store->get('account_id')!==$this->accountId)throw new MessengerException('messenger_device_identity_mismatch','The encrypted runtime owner account changed');if($this->loggedIn&&time()>=$this->catExpiresAt)throw new MessengerException('messenger_cat_expired','Refresh the owner bootstrap and explicitly reconnect the encrypted transport');}
        catch(\Throwable $error){$this->closeInternal();throw$error;}
    }
    /** @template T
     * @param callable():T $operation
     * @return T
     */
    private function command(callable $operation):mixed{if($this->busy)throw new \LogicException('Encrypted runtime commands cannot overlap');$this->busy=true;try{return$operation();}finally{$this->busy=false;}}
    private function closeInternal():void{$this->connection?->close();$this->connection=null;$this->loggedIn=false;$this->prekeysConfirmed=false;$this->review=null;}
    /** @return array{DeviceKeys,string} */
    private function registeredDevice():array
    {
        $saved=$this->store->get('e2ee.device.keys.v1');$raw=$this->store->get('e2ee.device.registration.v1');$registration=$raw===null?null:json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if($saved===null||!is_array($registration)||($registration['state']??null)!=='registered'||!is_int($registration['device_id']??null)||$registration['device_id']<1||$registration['device_id']>65535)throw new MessengerException('messenger_device_registration_required','Explicitly provision and confirm the encrypted device before connecting its runtime');
        $keys=DeviceKeys::fromPrivateJson($saved,$this->accountId);
        if(($registration['device_uuid']??null)!==$keys->uuid||!is_string($registration['identity_fingerprint']??null)||!hash_equals(hash('sha256',$keys->identityPublic()),$registration['identity_fingerprint']))throw new MessengerException('messenger_device_identity_mismatch','The confirmed registration does not bind the current local encrypted device keys');
        return[$keys,$this->accountId.':'.$registration['device_id'].'@msgr'];
    }
    /** @return array<string,mixed>|null */
    private function record(string $namespace,string $id):?array{$raw=$this->state?->get($namespace,$id);if($raw===null)return null;$value=json_decode($raw,true,32,JSON_THROW_ON_ERROR);if(!is_array($value)||array_is_list($value))throw new \UnexpectedValueException('Invalid encrypted runtime journal');return$value;}
    /** @param array<string,mixed> $value */
    private function save(string $namespace,string $id,array $value):void{if($this->state===null)throw new \LogicException('Encrypted store unavailable');$this->state->set($namespace,$id,json_encode($value,JSON_THROW_ON_ERROR));}
    /** @param array<string,mixed> $review
     * @return array<string,mixed>
     */
    private static function reviewProjection(array $review):array{return array_intersect_key($review,array_flip(['recipient','snapshot_hash','device_fingerprints','native_roster_verified']));}
    /** @param array<string,mixed> $payload */
    private function emit(string $event,array $payload):void{if($this->eventHandler===null)return;$this->inCallback=true;try{($this->eventHandler)($event,$payload);}finally{$this->inCallback=false;}}
    private static function requestId():string{return'MVO-'.bin2hex(random_bytes(12));}
    /** @return array<string,mixed> */public function jsonSerialize():array{return$this->status();}
    /** @return array<string,mixed> */public function __debugInfo():array{return$this->status();}
    public function __serialize():array{throw new \LogicException('Do not serialize a private encrypted runtime');}
    public function __destruct(){try{$this->closeInternal();}catch(\Throwable){/* Cleanup never requires a still-connected parent or owner lock. */}}
}
