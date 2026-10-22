<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\BusinessFormBuilder;
use MVO\Service\{BusinessFormGraphqlService,ServiceException};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** DTO fixtures derived from Android serializers, not live GraphQL responses or transport validation. */
final class BusinessFormGraphqlServiceTest extends TestCase
{
    private array $requests=[];
    private function service(array $data=[]):BusinessFormGraphqlService{return new BusinessFormGraphqlService(function(string $docId,array $variables)use($data):array{$this->requests[]=[$docId,$variables];return $data;});}
    private static function form(array $changes=[]):array{return $changes+['form_title'=>'Dados do cliente','form_description'=>'Descrição','form_type'=>'CUSTOMER_INFO','message_text'=>'Preencha seus dados.','selected_field_list'=>['NAME','EMAIL','PHONE_NUMBER']];}
    private static function published(array $changes=[]):array{return ['xwa_flexible_checkout_create_flow'=>$changes+['id'=>'9007199254740993','name'=>'Dados do cliente','category'=>'CUSTOMER_INFO','version'=>'V700']];}

    #[DataProvider('countries')]
    public function testCustomerTemplateMappingMatchesNativeCountryBranches(string $country,string $template):void
    {
        $result=$this->service(self::published())->publish(self::form(),$country);self::assertSame(['flowId'=>'9007199254740993','wellVersion'=>'V700','flowName'=>'Dados do cliente','category'=>'CUSTOMER_INFO'],$result);
        self::assertSame([BusinessFormGraphqlService::PUBLISH_DOC_ID,['form_title'=>'Dados do cliente','form_message'=>'Preencha seus dados.','custom_fields'=>[],'template_name'=>$template]],$this->requests[0]);
    }
    public static function countries():iterable
    {
        yield 'Brazil'=>['BR','FLEXIBLE_CHECKOUT_CUSTOMER_INFO_BRAZIL'];yield 'Colombia'=>['CO','FLEXIBLE_CHECKOUT_CUSTOMER_INFO_COLOMBIA'];yield 'Mexico'=>['MX','FLEXIBLE_CHECKOUT_CUSTOMER_INFO_MEXICO'];yield 'United States'=>['US','FLEXIBLE_CHECKOUT_CUSTOMER_INFO_US'];yield 'native fallback'=>['PT','FLEXIBLE_CHECKOUT_CUSTOMER_INFO_BRAZIL'];
    }
    public function testOfferTemplateAndAiTemplateOmissionMatchNativeSerializer():void
    {
        $service=$this->service(self::published());$service->publish(self::form(['form_type'=>'OFFER_SIGNUP']),'US');self::assertSame('FLEXIBLE_CHECKOUT_OFFER_SIGN_UP',$this->requests[0][1]['template_name']);
        $service->publish(self::form(['is_ai_form'=>true]),'BR');self::assertArrayNotHasKey('template_name',$this->requests[1][1]);self::assertSame(['form_title','form_message','custom_fields'],array_keys($this->requests[1][1]));
    }
    public function testCustomProjectionMatchesMessageDataIncludingRenamedAndDeliveryFields():void
    {
        $form=self::form(['selected_field_list'=>['NAME','EMAIL','DELIVERY_ADDRESS','CUSTOM','CUSTOM','CUSTOM'],'edited_field_keys'=>['NAME'],'edited_field_names'=>['Nome completo'],'custom_field_names'=>['Empresa','Detalhes','Plano'],'custom_field_supporting_texts'=>['Razão social','','Escolha um plano'],'custom_field_input_types'=>['TEXT_INPUT','TEXT_AREA','DROPDOWN'],'custom_field_options'=>[[],[],['Básico','Avançado']]]);
        $publication=$this->service(self::published())->publish($form,'BR');$fields=$this->requests[0][1]['custom_fields'];
        self::assertSame([['type'=>'TEXT_INPUT','label'=>'Nome completo'],['type'=>'TEXT_INPUT','label'=>'Endereço de entrega'],['type'=>'TEXT_INPUT','label'=>'Empresa','helper_text'=>'Razão social'],['type'=>'TEXT_AREA','label'=>'Detalhes'],['type'=>'DROPDOWN','label'=>'Plano','helper_text'=>'Escolha um plano','options'=>['Básico','Avançado']]],$fields);
        $message=BusinessFormBuilder::build(['type'=>'business-form','form'=>$form,'publication'=>$publication]);$params=json_decode($message['interactiveMessage']['nativeFlowMessage']['buttons'][0]['buttonParamsJson'],true,512,JSON_THROW_ON_ERROR);self::assertSame($fields,$params['flow_action_payload']['data']['custom_fields']);
    }
    public function testMissingOptionalPublicationMetadataIsNotInvented():void
    {
        self::assertSame(['flowId'=>'123','wellVersion'=>'V710'],$this->service(['xwa_flexible_checkout_create_flow'=>['id'=>123,'version'=>'V710']])->publish(self::form(),'BR'));
    }
    public function testAbsentPublicationVersionUsesObservedNativeV700BaselineOnly():void
    {
        self::assertSame(['flowId'=>'123','wellVersion'=>'V700'],$this->service(['xwa_flexible_checkout_create_flow'=>['id'=>'123']])->publish(self::form(),'BR'));
        self::assertCount(1,$this->requests,'A parser default cannot retry publication');
    }
    #[DataProvider('invalidPublicationResponses')]
    public function testMissingOrMalformedPublicationFieldsCannotBecomeSuccess(array $data):void
    {
        $this->expectException(\UnexpectedValueException::class);$this->service($data)->publish(self::form(),'BR');
    }
    public static function invalidPublicationResponses():iterable
    {
        yield 'missing root'=>[[]];yield 'null root'=>[['xwa_flexible_checkout_create_flow'=>null]];yield 'list root'=>[['xwa_flexible_checkout_create_flow'=>[['id'=>'123']]]];
        foreach([['id'=>'local-form'],['id'=>1.2],['id'=>'0'],['version'=>''],['version'=>null],['version'=>700],['name'=>['not text']],['category'=>"\xff"]]as$index=>$changes)yield 'bad field '.$index=>[self::published($changes)];
    }
    public function testPureValidationRunsBeforeInjectedRequest():void
    {
        $service=$this->service();foreach([
            static fn()=>$service->publish(self::form(['selected_field_list'=>['ADDRESS']]),'BR'),
            static fn()=>$service->publish(self::form(),'br'),
            static fn()=>$service->publish(self::form(['is_ai_form'=>'true']),'BR'),
            static fn()=>$service->generate(' '),static fn()=>$service->generate("\0"),static fn()=>$service->generate("\xff"),
        ]as$call){try{$call();self::fail('Invalid input accepted');}catch(\InvalidArgumentException){}}self::assertSame([],$this->requests);
    }
    public function testRequestFailurePropagatesWithoutRetryOrFallback():void
    {
        $calls=0;$error=new ServiceException('native.graphql','403','not authorized');$service=new BusinessFormGraphqlService(static function()use(&$calls,$error):never{$calls++;throw $error;});
        try{$service->publish(self::form(),'BR');self::fail('Refusal ignored');}catch(ServiceException $caught){self::assertSame($error,$caught);}self::assertSame(1,$calls);
    }
    public function testGenerationUsesObservedDocAndPromptAndPreservesGeneratedObject():void
    {
        $generated=['can_convert_to_form'=>true,'form_title'=>'Cadastro','custom_message'=>'Preencha','fields'=>[['title'=>'Nome','type'=>'TEXT_INPUT','helper_text'=>'Seu nome','future'=>['keep'=>true]]],'future_metadata'=>['id'=>'raw-metadata']];
        self::assertSame($generated,$this->service(['whatsapp_smb_genai_form_generation'=>$generated])->generate('Crie um cadastro'));
        self::assertSame([BusinessFormGraphqlService::GENERATE_DOC_ID,['prompt'=>'Crie um cadastro']],$this->requests[0]);
    }
    public function testGenerationRejectionIsReturnedWithoutLocalTemplateOrFakeSuccess():void
    {
        $rejected=['can_convert_to_form'=>false,'rejection_type'=>'UNSUPPORTED','rejection_reason'=>'Revise a solicitação','flagged_fields'=>['payment'],'future'=>'preserved'];self::assertSame($rejected,$this->service(['whatsapp_smb_genai_form_generation'=>$rejected])->generate('Solicitação de teste'));
    }
    public function testNullableNativeGenerationArraysPreserveOriginalRejectionAndOutput():void
    {
        $rejection=['can_convert_to_form'=>false,'rejection_type'=>'UNSUPPORTED','rejection_reason'=>'Revise a solicitação','flagged_fields'=>null,'fields'=>null];
        self::assertSame($rejection,$this->service(['whatsapp_smb_genai_form_generation'=>$rejection])->generate('Solicitação recusada'));
        $generated=['can_convert_to_form'=>true,'form_title'=>null,'custom_message'=>null,'suggested_message'=>'Revise os campos','fields'=>null];
        self::assertSame($generated,$this->service(['whatsapp_smb_genai_form_generation'=>$generated])->generate('Solicitação vazia'));
    }
    #[DataProvider('invalidGenerationResponses')]
    public function testInvalidGenerationDataIsRejected(array $data):void
    {
        $this->expectException(\UnexpectedValueException::class);$this->service($data)->generate('Cadastro');
    }
    public static function invalidGenerationResponses():iterable
    {
        yield 'missing root'=>[[]];yield 'missing boolean'=>[['whatsapp_smb_genai_form_generation'=>['fields'=>[]]]];yield 'boolean string'=>[['whatsapp_smb_genai_form_generation'=>['can_convert_to_form'=>'true']]];
        foreach([['fields'=>'string'],['fields'=>['name'=>[]]],['fields'=>['not an object']],['flagged_fields'=>false],['rejection_reason'=>123],['fields'=>[['title'=>false]]]]as$index=>$changes)yield 'bad generated shape '.$index=>[['whatsapp_smb_genai_form_generation'=>$changes+['can_convert_to_form'=>false]]];
    }
}
