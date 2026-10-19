<?php
declare(strict_types=1);
namespace MVO\Facebook\Protocol;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Storage\CommandLedger;
use MVO\Messenger\Auth\WebSession;

/** Internal Web protocol, using the existing account's cookie/CSRF boundary. */
final class WebGraphQL
{
    private int $sequence=0;
    public function __construct(private readonly WebSession $session,private readonly DocumentRegistry $documents,private readonly ?CommandLedger $commands=null){}
    public function accountId():string{return $this->session->accountId();}
    /** @param array<string,mixed> $variables
     * @param callable(array<string,mixed>):array<string,mixed>|null $projector Required for mutations; only its result is persisted.
     * @return array<string,mixed>
     */
    public function execute(string $name,array $variables,?string $actorId=null,string $origin='https://www.facebook.com',?string $idempotencyKey=null,?callable $projector=null):array
    {
        $document=$this->documents->get($name);$arguments=$document->arguments($variables);
        if(!in_array($origin,['https://www.facebook.com','https://business.facebook.com','https://adsmanager.facebook.com'],true))throw new \InvalidArgumentException('Unsupported Facebook Web origin');
        if($actorId!==null&&!preg_match('/^[1-9][0-9]{0,24}$/D',$actorId))throw new \InvalidArgumentException('Use the exact Facebook actor ID');
        $fields=$this->session->privateRequestFields();
        if(($fields['__user']??null)!==$this->session->accountId()||(!isset($fields['fb_dtsg'])&&!isset($fields['lsd'])))throw new FacebookException('facebook_csrf_unavailable','The authenticated bootstrap did not supply current Facebook request fields');
        $form=[...$fields,'av'=>$actorId??$this->session->accountId(),'__req'=>base_convert((string)++$this->sequence,10,36),
            'fb_api_caller_class'=>'RelayModern','fb_api_req_friendly_name'=>$document->name,'doc_id'=>$document->id,
            'variables'=>json_encode($arguments,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),'server_timestamps'=>'true'];
        $command=null;
        if($document->kind==='mutation'){
            if($idempotencyKey===null||$this->commands===null)throw new FacebookException('facebook_mutation_requires_idempotency','Configure a private command ledger and provide an idempotency key before changing Facebook data');
            if($projector===null)throw new FacebookException('facebook_mutation_requires_projection','A mutation must validate and explicitly project its application result');
            $command=$this->commands->reserve($this->session->accountId(),$name,$actorId,['origin'=>$origin,'variables'=>$arguments],$idempotencyKey);
            if(!$command['execute']){
                if($command['state']==='confirmed'&&is_array($command['result']['projection']??null))return $command['result']['projection'];
                throw new FacebookException('facebook_command_uncertain','This command already exists without a confirmed result; inspect the command before trying another key','unknown',false,$command['id']);
            }
            $this->commands->finish($command['id'],'unknown');
        }
        try{
            $data=$this->perform($document,$form,$origin);
            if($command!==null)$this->commands?->finish($command['id'],'response_received');
            $result=$projector===null?$data:$projector($data);
            if(!is_array($result))throw new \UnexpectedValueException('The Facebook result projector did not return an object');
            if($command!==null)$this->commands?->finish($command['id'],'confirmed',['projection'=>$result]);
            return $result;
        }catch(\Throwable $error){
            if($command===null)throw $error;
            throw new FacebookException($error instanceof FacebookException?$error->reason:'facebook_command_unconfirmed',
                $error instanceof FacebookException?$error->getMessage():'The Facebook command has no durable confirmed result',
                $error instanceof FacebookException?$error->outcome:'unknown',false,$command['id']);
        }
    }
    /** @param array<string,string> $form
     * @return array<string,mixed>
     */
    private function perform(Document $document,#[\SensitiveParameter] array $form,string $origin):array
    {
        try{$response=$this->session->request('POST',$origin.'/api/graphql/',$form);}
        catch(\Throwable){throw new FacebookException('facebook_transport_failed','The Facebook Web request did not complete',$document->kind==='mutation'?'unknown':'not_confirmed',true);}
        if(in_array($response->status,[301,302,303,307,308,401],true))throw new FacebookException('facebook_authentication_required','Facebook requires authentication or account verification',$document->kind==='mutation'?'unknown':'not_confirmed');
        if($response->status===429)throw new FacebookException('facebook_rate_limited','Facebook temporarily limited this operation',$document->kind==='mutation'?'unknown':'not_confirmed',true);
        if($response->status!==200)throw new FacebookException('facebook_http_failed','Facebook did not return a successful Web response',$document->kind==='mutation'?'unknown':'not_confirmed',$response->status>=500);
        try{return (new GraphQLDecoder())->decode($response->body);}
        catch(FacebookException $error){
            if($document->kind!=='mutation')throw $error;
            throw new FacebookException($error->reason,$error->getMessage(),$error->outcome==='partial'?'partial':'not_confirmed',$error->retryable);
        }
    }
}
