<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Message\BusinessFormBuilder;

/** Observed Android Business persisted GraphQL contracts; transport/authentication is injected separately. */
final class BusinessFormGraphqlService
{
    public const PUBLISH_DOC_ID='26463307113337151';
    public const GENERATE_DOC_ID='27378498295139173';
    private \Closure $request;
    /** Receives a server document ID and its variables, returns GraphQL data after transport/error handling.
     * @param callable(string,array<string,mixed>):array<string,mixed> $request */
    public function __construct(callable $request){$this->request=\Closure::fromCallable($request);}

    /** Creates a remote resource; caller owns durable request reservation and uncertain-outcome policy.
     * @param array<string,mixed> $form
     * @return array<string,mixed> */
    public function publish(array $form,string $countryCode):array
    {
        BusinessFormBuilder::validateForm($form);
        if(preg_match('/^[A-Z]{2}$/D',$countryCode)!==1)throw new \InvalidArgumentException('Business form country must be a two-letter uppercase code');
        $variables=['form_title'=>$form['form_title'],'form_message'=>$form['message_text']??'','custom_fields'=>self::customFields($form)];
        if(!($form['is_ai_form']??false))$variables['template_name']=$form['form_type']==='OFFER_SIGNUP'?'FLEXIBLE_CHECKOUT_OFFER_SIGN_UP':match($countryCode){
            'CO'=>'FLEXIBLE_CHECKOUT_CUSTOMER_INFO_COLOMBIA','MX'=>'FLEXIBLE_CHECKOUT_CUSTOMER_INFO_MEXICO','US'=>'FLEXIBLE_CHECKOUT_CUSTOMER_INFO_US',default=>'FLEXIBLE_CHECKOUT_CUSTOMER_INFO_BRAZIL',
        };
        $data=($this->request)(self::PUBLISH_DOC_ID,$variables);$response=$data['xwa_flexible_checkout_create_flow']??null;
        if(!is_array($response)||array_is_list($response))throw new \UnexpectedValueException('Business form publication response is missing xwa_flexible_checkout_create_flow');
        $id=$response['id']??null;if(is_int($id))$id=(string)$id;
        if(!is_string($id)||preg_match('/^[1-9][0-9]{0,63}$/D',$id)!==1)throw new \UnexpectedValueException('Business form publication response is missing a remote Flow ID');
        // GTw.A02 / PublishFlexibleCheckoutFlowGraphqlResponse: the native baseline is V700.
        // Apply it only when absent; explicit null/malformed values still require reconciliation.
        $version=self::responseText(array_key_exists('version',$response)?$response['version']:'V700','version',128,false);
        $publication=['flowId'=>$id,'wellVersion'=>$version];
        foreach(['name'=>'flowName','category'=>'category']as$field=>$alias)if(array_key_exists($field,$response))$publication[$alias]=self::responseText($response[$field],$field,4096);
        return $publication;
    }

    /** Returns the original generated/rejected object for application review; never saves or publishes it.
     * @return array<string,mixed> */
    public function generate(string $prompt):array
    {
        if(trim($prompt)===''||strlen($prompt)>16000||preg_match('//u',$prompt)!==1||str_contains($prompt,"\0"))throw new \InvalidArgumentException('Business form prompt must contain 1..16000 UTF-8 bytes');
        $data=($this->request)(self::GENERATE_DOC_ID,['prompt'=>$prompt]);$response=$data['whatsapp_smb_genai_form_generation']??null;
        if(!is_array($response)||array_is_list($response)||!is_bool($response['can_convert_to_form']??null))throw new \UnexpectedValueException('Invalid Business form generation response');
        try{$json=json_encode($response,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}catch(\JsonException $error){throw new \UnexpectedValueException('Invalid Business form generation JSON',0,$error);}
        if(strlen($json)>2097152)throw new \LengthException('Business form generation exceeds the SDK 2 MiB limit');
        foreach(['form_title','custom_message','suggested_message','rejection_type','rejection_reason']as$field)if(isset($response[$field]))self::responseText($response[$field],$field,65536);
        if(isset($response['flagged_fields'])){
            if(!is_array($response['flagged_fields'])||!array_is_list($response['flagged_fields'])||count($response['flagged_fields'])>100)throw new \UnexpectedValueException('Invalid generated form flagged_fields');
            foreach($response['flagged_fields']as$value)self::responseText($value,'flagged_fields',4096);
        }
        if(isset($response['fields'])){
            if(!is_array($response['fields'])||!array_is_list($response['fields'])||count($response['fields'])>100)throw new \UnexpectedValueException('Invalid generated form fields');
            foreach($response['fields']as$field){if(!is_array($field)||array_is_list($field))throw new \UnexpectedValueException('Invalid generated form field object');
                foreach(['title','type','helper_text','supporting_text']as$key)if(isset($field[$key]))self::responseText($field[$key],$key,65536);
            }
        }
        return $response;
    }

    /** Mirrors the custom Gxk projection used by the Android publisher and message builder.
     * @param array<string,mixed> $form
     * @return list<array<string,mixed>> */
    private static function customFields(array $form):array
    {
        $renamed=[];foreach($form['edited_field_keys']??[]as$index=>$key)$renamed[$key]=$form['edited_field_names'][$index];$custom=[];$index=0;
        foreach($form['selected_field_list']as$key){
            if($key==='CUSTOM'){
                $field=['type'=>$form['custom_field_input_types'][$index],'label'=>$form['custom_field_names'][$index]];
                $helper=$form['custom_field_supporting_texts'][$index];if($helper!=='')$field['helper_text']=$helper;
                $options=$form['custom_field_options'][$index];if($options!==[])$field['options']=$options;$custom[]=$field;$index++;
            }elseif(isset($renamed[$key])||$key==='DELIVERY_ADDRESS')$custom[]=['type'=>'TEXT_INPUT','label'=>$renamed[$key]??'Endereço de entrega'];
        }return $custom;
    }
    private static function responseText(mixed $value,string $field,int $limit,bool $empty=true):string
    {
        if(!is_string($value)||strlen($value)>$limit||preg_match('//u',$value)!==1||str_contains($value,"\0")||(!$empty&&trim($value)===''))throw new \UnexpectedValueException('Invalid Business form response '.$field);return $value;
    }
}
