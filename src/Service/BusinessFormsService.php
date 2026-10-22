<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Message\{BusinessFormBuilder,ContentBuilder};
use MVO\Protocol\BinaryData;
use MVO\Signal\SignalStore;

/** Saved local Business templates and explicit remote publication over the current session. */
final class BusinessFormsService
{
    private \Closure $publisher;
    private \Closure $sender;
    private ?\Closure $generator;
    private ?\Closure $authentication;
    private \Closure $clock;

    /**
     * @param callable(array<string,mixed>,string):array<string,mixed> $publishForm
     * @param callable(string,array<string,mixed>):array<string,mixed> $sendMessage
     * @param callable(string):array<string,mixed>|null $generateForm
     * @param callable():int|null $clock Unix milliseconds.
     * @param callable():array<string,mixed>|null $authenticationStatus Safe projection, never tokens/nonces/cookies.
     */
    public function __construct(
        private readonly BusinessFormStore $catalog,
        private readonly SignalStore $store,
        callable $publishForm,
        callable $sendMessage,
        ?callable $generateForm=null,
        private readonly ?GalaxyResponseService $exports=null,
        private readonly ?GalaxyMetadataService $metadata=null,
        ?callable $clock=null,
        ?callable $authenticationStatus=null,
    ) {
        $this->publisher=\Closure::fromCallable($publishForm);$this->sender=\Closure::fromCallable($sendMessage);
        $this->generator=$generateForm===null?null:\Closure::fromCallable($generateForm);
        $this->authentication=$authenticationStatus===null?null:\Closure::fromCallable($authenticationStatus);
        $this->clock=$clock===null?static fn():int=>(int)floor(microtime(true)*1000):\Closure::fromCallable($clock);
    }

    /** Local templates are isolated in this SDK session, not synchronized into the phone's private wa_props.
     * @param array<string,mixed> $form
     * @return array<string,mixed> */
    public function create(array $form):array{return $this->catalog->create($form);}
    /** @return array<string,mixed>|null */
    public function get(string $id):?array{return $this->catalog->get($id);}
    /** @return list<array<string,mixed>> */
    public function list(int $limit=100,string $after=''):array{return $this->catalog->list($limit,$after);}
    /** @param array<string,mixed> $changes
     * @return array<string,mixed> */
    public function update(string $id,array $changes):array{return $this->catalog->update($id,$changes);}
    /** @return array<string,mixed> */
    public function rename(string $id,string $name):array{return $this->catalog->rename($id,$name);}
    public function delete(string $id):bool{return $this->catalog->delete($id);}
    /** @return array<string,mixed> */
    public function duplicate(string $id,?string $name=null):array{return $this->catalog->duplicate($id,$name);}
    /** @return array<array-key,mixed> */
    public function templates():array{return $this->catalog->templates();}
    public function exportTemplates():string{return $this->catalog->exportTemplates();}
    /** @return array<array-key,mixed> */
    public function importTemplates(string $json,bool $replace=false):array{return $this->catalog->importTemplates($json,$replace);}

    /** Creates the remote resource once per request ID. A timeout is never retried implicitly.
     * @return array<string,mixed> */
    public function publish(string $id,string $countryCode,?string $requestId=null):array
    {
        $form=$this->requireForm($id);self::country($countryCode);BusinessFormBuilder::validateForm($form);
        $requestId??=bin2hex(random_bytes(16));self::requestId($requestId);
        $fingerprint=PublicationPolicy::fingerprint(['form'=>$form,'countryCode'=>$countryCode]);
        $existing=$this->publicationStatus($requestId);
        if($existing!==null){if($existing['fingerprint']!==$fingerprint)throw new \LogicException('Publication request ID belongs to a different form or country');return $existing;}
        $record=$this->store->transaction(function()use($id,$form,$countryCode,$requestId,$fingerprint):array{
            if($this->publicationStatus($requestId)!==null)throw new \LogicException('Publication is already reserved');
            $last=$this->lastPublication($id);
            if($last!==null&&in_array($last['state'],['prepared','unknown'],true))throw new \LogicException('Previous form publication has an unresolved outcome: '.$last['requestId']);
            $now=($this->clock)();
            $record=['requestId'=>$requestId,'formId'=>$id,'state'=>'prepared','fingerprint'=>$fingerprint,'countryCode'=>$countryCode,'form'=>$form,'createdAt'=>$now,'updatedAt'=>$now];
            $this->savePublication($record);$this->store->set('business-form-last-publication',$id,$requestId);return $record;
        });
        try{
            $publication=($this->publisher)($form,$countryCode);
            self::assertPublication($publication);
            $record['publication']=$publication;$record['state']='published';$record['updatedAt']=($this->clock)();$this->savePublication($record);
            return $record;
        }catch(\Throwable $error){
            $record['state']=$error instanceof ServiceException?'rejected':'unknown';
            $record['error']=$error instanceof ServiceException?'business_form_publication_rejected':'business_form_publication_outcome_unknown';
            if($error instanceof ServiceException)$record['serverCode']=$error->serverCode;
            $record['updatedAt']=($this->clock)();$this->savePublication($record);throw $error;
        }
    }

    /** @return array<string,mixed>|null */
    public function publicationStatus(string $requestId):?array
    {
        self::requestId($requestId);$bytes=$this->store->get('business-form-publications',$requestId);
        if($bytes===null)return null;$record=BinaryData::decode($bytes);
        if(!is_array($record)||($record['requestId']??null)!==$requestId)throw new \UnexpectedValueException('Invalid saved form publication');return $record;
    }
    /** @return array<string,mixed>|null */
    public function lastPublication(string $id):?array
    {
        $this->requireForm($id);$requestId=$this->store->get('business-form-last-publication',$id);
        return $requestId===null?null:$this->publicationStatus($requestId);
    }

    /** Explicit local acknowledgement of uncertainty; this does not cancel/delete any remote Flow.
     * @return array<string,mixed> */
    public function abandonPublication(string $requestId):array
    {
        $record=$this->publicationStatus($requestId)??throw new \OutOfBoundsException('Form publication not found');
        if(!in_array($record['state'],['prepared','unknown'],true))throw new \LogicException('Only an unresolved publication can be abandoned');
        $record['state']='abandoned';$record['updatedAt']=($this->clock)();$this->savePublication($record);return $record;
    }

    /** Publishes, then sends. The successful publication stays queryable even if message submission fails.
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function send(string $id,string $to,string $countryCode,array $options=[],?string $requestId=null):array
    {
        $to=RecipientResolver::normalize($to);self::messageOptions($options);
        BusinessFormBuilder::validateForm($this->requireForm($id));
        // Validate the common context/wrapper options without inventing a remote Flow ID or doing I/O.
        ContentBuilder::build(['type'=>'raw','message'=>['conversation'=>'Business form validation'],...$options],$to);
        $publication=$this->publish($id,$countryCode,$requestId);
        $requestId=$publication['requestId'];
        if($publication['state']!=='published')throw new \LogicException('Form publication is not confirmed; inspect publicationStatus before sending');
        $fingerprint=PublicationPolicy::fingerprint(['to'=>$to,'options'=>$options]);
        $attempt=$this->sendStatus($requestId);
        if($attempt!==null){
            if($attempt['fingerprint']!==$fingerprint)throw new \LogicException('Form send request ID belongs to different message content or recipient');
            if($attempt['state']==='server_ack')return ['publication'=>$publication,'message'=>$attempt['message']];
            throw new \LogicException('Previous form message has an unresolved or rejected outcome; inspect sendStatus and retry the stored message explicitly');
        }
        $options['messageId']??='3EB0'.strtoupper(bin2hex(random_bytes(9)));
        $attempt=['requestId'=>$requestId,'fingerprint'=>$fingerprint,'messageId'=>$options['messageId'],'to'=>$to,'state'=>'prepared','createdAt'=>($this->clock)()];
        $this->store->set('business-form-send-attempts',$requestId,BinaryData::encode($attempt));
        try{
            $result=$this->sendPublication($requestId,$to,$options);
            $attempt['message']=$result['message'];$attempt['state']=($result['message']['id']??null)===$attempt['messageId']&&in_array($result['message']['status']??null,['server_ack','delivered','read'],true)?'server_ack':'unknown';
            $this->store->set('business-form-send-attempts',$requestId,BinaryData::encode($attempt));return $result;
        }catch(\Throwable $error){$attempt['state']=$error instanceof ServiceException?'rejected':'unknown';$this->store->set('business-form-send-attempts',$requestId,BinaryData::encode($attempt));throw $error;}
    }

    /** @return array<string,mixed>|null */
    public function sendStatus(string $requestId):?array
    {
        self::requestId($requestId);$bytes=$this->store->get('business-form-send-attempts',$requestId);if($bytes===null)return null;
        $attempt=BinaryData::decode($bytes);if(!is_array($attempt)||($attempt['requestId']??null)!==$requestId)throw new \UnexpectedValueException('Invalid saved Business form send attempt');return $attempt;
    }

    /** Reuses the exact published snapshot, including after the saved template was edited or deleted.
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendPublication(string $requestId,string $to,array $options=[]):array
    {
        $to=RecipientResolver::normalize($to);self::messageOptions($options);
        $record=$this->publicationStatus($requestId)??throw new \OutOfBoundsException('Form publication not found');
        if($record['state']!=='published')throw new \LogicException('Form publication is not confirmed; inspect publicationStatus before sending');
        $content=['type'=>'business-form','form'=>$record['form'],'publication'=>$record['publication'],...$options];
        BusinessFormBuilder::build($content);
        $message=($this->sender)($to,$content);
        return ['publication'=>$record,'message'=>$message];
    }

    /** AI output/rejections are returned for review; generation does not save or publish a template.
     * @return array<string,mixed> */
    public function generate(string $prompt):array
    {
        if(trim($prompt)===''||strlen($prompt)>16000||preg_match('//u',$prompt)!==1||str_contains($prompt,"\0"))throw new \InvalidArgumentException('Form prompt must contain 1..16000 UTF-8 bytes');
        return ($this->generator??throw new \LogicException('Business form generation is not configured'))($prompt);
    }
    /** @return array<string,mixed> */
    public function authenticationStatus():array
    {return $this->authentication===null?['state'=>'not_configured','authenticated'=>false]:($this->authentication)();}
    /** @return array<string,mixed>|null */
    public function getFlowData(string $businessJid,string $flowId,float $timeout=32.0):?array
    {return ($this->metadata??throw new \LogicException('Galaxy metadata is not configured'))->getFlowData($businessJid,$flowId,$timeout);}
    /** @return array<string,mixed> */
    public function requestResponses(string $flowId,?string $requestId=null):array
    {return ($this->exports??throw new \LogicException('Galaxy response export is not configured'))->requestResponses($flowId,$requestId);}
    /** @return array<string,mixed>|null */
    public function responseStatus(string $requestId):?array
    {return ($this->exports??throw new \LogicException('Galaxy response export is not configured'))->requestStatus($requestId);}
    public function downloadResponses(string $requestId,?string $destination=null):string
    {return ($this->exports??throw new \LogicException('Galaxy response export is not configured'))->downloadResponses($requestId,$destination);}

    /** @param array<string,mixed> $publication */
    public static function assertPublication(array $publication):void
    {
        if(!is_string($publication['flowId']??null)||!preg_match('/^[1-9][0-9]{0,63}$/D',$publication['flowId']))throw new \UnexpectedValueException('Published form response missing a real Flow ID');
        if(!is_string($publication['wellVersion']??null)||trim($publication['wellVersion'])===''||strlen($publication['wellVersion'])>128||preg_match('//u',$publication['wellVersion'])!==1)throw new \UnexpectedValueException('Published form response missing wellVersion');
    }
    /** @return array<string,mixed> */
    private function requireForm(string $id):array{return $this->catalog->get($id)??throw new \OutOfBoundsException('Saved Business form not found');}
    private static function country(string $value):void{if(!preg_match('/^[A-Z]{2}$/D',$value))throw new \InvalidArgumentException('Form country code must be a two-letter uppercase ISO country code');}
    private static function requestId(string $id):void{if(!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$id))throw new \InvalidArgumentException('Invalid form publication request ID');}
    /** @param array<string,mixed> $record */
    private function savePublication(array $record):void{$this->store->set('business-form-publications',$record['requestId'],BinaryData::encode($record));}
    /** @param array<string,mixed> $options */
    private static function messageOptions(array $options):void
    {
        BusinessFormBuilder::validateOptions($options);
    }
}
