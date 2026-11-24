<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Service\{BusinessAgentService,BusinessFormHttpTransport};
use PHPUnit\Framework\TestCase;

final class BusinessAgentServiceTest extends TestCase
{
    public function testKnowledgeUsesNativeHttpDocumentAndProjectsOnlySelectedFields():void
    {
        $calls=0;
        $http=new BusinessFormHttpTransport(static fn():string=>'test-only-business-token',documents:array_column(BusinessAgentService::operations(),'documentId'),exchange:static function(string $url,array $headers,string $body,float $timeout) use(&$calls):array {
            ++$calls;
            self::assertSame('https://graph.facebook.com/graphql',$url);
            $request=json_decode($body,true,64,JSON_THROW_ON_ERROR);
            self::assertSame('test-only-business-token',$request['access_token']);
            self::assertSame(27046558284963667,$request['doc_id']);
            return ['status'=>200,'body'=>json_encode(['data'=>['xfb_meta_ai_biz_agent_wa_ai_home'=>[
                'ordered_knowledge'=>[['knowledge_type'=>'BUSINESS_INFO','string_data'=>'Loja de livros','access_token'=>'PRIVATE'],null],
                'knowledge'=>['websites'=>[['website_type'=>'BUSINESS','website_url'=>'https://example.com']]],
                'knowledge_sources'=>[['__typename'=>'XFBMetaAIBizAgentFileUploadKnowledgeSource','source_type'=>'FILE','id'=>'file-1','user_provided_file_name'=>'precos.pdf','mime_type'=>'application/pdf','private'=>'PRIVATE']],
                'access_token'=>'PRIVATE',
            ],'credentials'=>'PRIVATE']],JSON_THROW_ON_ERROR)];
        });
        $service=new BusinessAgentService($http->request(...));
        $result=$service->run('knowledge-get');
        self::assertSame(1,$calls);
        self::assertSame('observed',$result['outcome']);
        $knowledge=$result['data']['xfb_meta_ai_biz_agent_wa_ai_home'];
        self::assertSame('Loja de livros',$knowledge['ordered_knowledge'][0]['string_data']);
        self::assertNull($knowledge['ordered_knowledge'][1]);
        self::assertSame('precos.pdf',$knowledge['knowledge_sources'][0]['user_provided_file_name']);
        self::assertStringNotContainsString('PRIVATE',json_encode($result,JSON_THROW_ON_ERROR));
        self::assertTrue($result['supportVerified']);
    }

    public function testConversationControlPreservesTheNativeVariablesAndExplicitRejection():void
    {
        $variables=['consumer_lid'=>'77770000000','phone_number'=>'5511000000000','thread_status'=>'ENABLED'];
        $service=new BusinessAgentService(static function(string $document,array $actual) use($variables):array {
            self::assertSame('27338647792432014',$document);
            self::assertSame($variables,$actual);
            return ['xfb_whatsapp_smb_maiba_status_update'=>['success'=>false,'update_timestamp_ms'=>1795514400000]];
        });
        $result=$service->run('conversation-replies',$variables);
        self::assertSame('rejected',$result['outcome']);
        self::assertFalse($result['data']['xfb_whatsapp_smb_maiba_status_update']['success']);
    }

    public function testProductDeletionReportsPartialAcceptanceWithoutHidingIndividualResults():void
    {
        $service=new BusinessAgentService(static fn():array=>['xfb_maiba_multi_delete_product_info_knowledge'=>[
            ['product_id'=>'product-1','success'=>true],['product_id'=>'product-2','success'=>false],
        ]]);
        $result=$service->run('products-delete',['product_ids'=>['product-1','product-2']]);
        self::assertSame('partial',$result['outcome']);
        self::assertSame([true,false],array_column($result['data']['xfb_maiba_multi_delete_product_info_knowledge'],'success'));
    }

    public function testAnHttpResponseWithoutExplicitMutationConfirmationStaysUnknown():void
    {
        $service=new BusinessAgentService(static fn():array=>['xfb_meta_ai_biz_agent_wa_update_reengagement'=>['enabled'=>false,'amount'=>0]]);
        $result=$service->run('reengagement-update',['request'=>['enabled'=>true,'amount'=>1]]);
        self::assertSame('unknown',$result['outcome']);
        self::assertSame(['enabled'=>false,'amount'=>0],$result['data']['xfb_meta_ai_biz_agent_wa_update_reengagement']);
    }

    public function testUnknownOperationAndUnknownVariableFailBeforeAuthentication():void
    {
        $calls=0;
        $service=new BusinessAgentService(static function() use(&$calls):array { ++$calls; return []; });
        foreach ([['27338647792432014',[]],['knowledge-get',['access_token'=>'forbidden']],['knowledge-get',['doc_id'=>'123']]] as [$operation,$variables]) {
            try { $service->run($operation,$variables); self::fail('Unexpected native operation or variable was accepted'); }
            catch (\InvalidArgumentException) { self::addToAssertionCount(1); }
        }
        self::assertSame(0,$calls);
    }

    public function testAnUncertainTransferIsAttemptedOnceAndDoesNotReturnSuccess():void
    {
        $calls=0;
        $http=new BusinessFormHttpTransport(static fn():string=>'test-only-business-token',documents:array_column(BusinessAgentService::operations(),'documentId'),exchange:static function() use(&$calls):array {
            ++$calls;
            throw new \RuntimeException('Connection lost after write');
        });
        $service=new BusinessAgentService($http->request(...));
        try { $service->run('rule-delete',['request'=>['rule_id'=>'rule-1']]); self::fail('Uncertain write claimed success'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('unconfirmed',$error->getMessage()); }
        self::assertSame(1,$calls);
    }

    public function testMissingResultAndInvalidSuccessTypeNeverConfirmAMutation():void
    {
        $service=new BusinessAgentService(static fn():array=>['wrong_result'=>['success'=>true]]);
        try { $service->run('rule-delete',['request'=>['rule_id'=>'rule-1']]); self::fail('Wrong GraphQL root was accepted'); }
        catch (\UnexpectedValueException) { self::addToAssertionCount(1); }
        $service=new BusinessAgentService(static fn():array=>['xfb_meta_ai_biz_agent_wa_delete_rule'=>['success'=>'true']]);
        self::assertSame('unknown',$service->run('rule-delete',['request'=>['rule_id'=>'rule-1']])['outcome']);
    }

    public function testReengagementConfirmsOnlyTheReturnedAppliedSettings():void
    {
        $service=new BusinessAgentService(static fn():array=>['xfb_meta_ai_biz_agent_wa_update_reengagement'=>['enabled'=>false,'amount'=>0]]);
        self::assertSame('accepted',$service->run('reengagement-update',['request'=>['enabled'=>false,'amount'=>0]])['outcome']);
        $service=new BusinessAgentService(static fn():array=>['xfb_meta_ai_biz_agent_wa_update_reengagement'=>['enabled'=>true]]);
        self::assertSame('unknown',$service->run('reengagement-update',['request'=>['enabled'=>true,'amount'=>2]])['outcome']);
    }

    public function testRecoveredInputContractsRejectInvalidSettingsBeforeAnyRequest():void
    {
        $calls=0;$service=new BusinessAgentService(static function()use(&$calls):array{++$calls;return [];});
        foreach([
            ['response-settings-v2-update',[]],['response-settings-v2-update',['input'=>[]]],
            ['response-settings-v2-update',['input'=>['status'=>'DISABLED']]],
            ['response-settings-v2-update',['input'=>['audience_rules'=>[['mode'=>'MUTED','selector_type'=>'INVENTED']]]]],
            ['schedule-update',['input'=>['enabled_time'=>'ANYTIME','from_sec_in_day'=>-1]]],
            ['schedule-update',['input'=>['enabled_time'=>'SELECTIVE_HOURS']]],
            ['reengagement-update',['request'=>['enabled'=>'false']]],['rule-delete',['request'=>['id'=>'rule-1']]],
        ]as[$operation,$variables]){
            try{$service->run($operation,$variables);self::fail('Invalid native input reached authentication');}
            catch(\InvalidArgumentException){self::addToAssertionCount(1);}
        }
        self::assertSame(0,$calls);
    }

    public function testNativeMutationTransportCanRepresentAReadOnlySettingsFetch():void
    {
        $service=new BusinessAgentService(static function(string $document,array $variables):array{
            self::assertSame('28169016829397830',$document);self::assertSame([],$variables);
            return ['xfb_meta_ai_biz_agent_wa_fetch_response_settings_v2'=>['status'=>'ENABLED','audience_rules'=>[['mode'=>'UNMUTED','selector_type'=>'ALL']]]];
        });
        $result=$service->run('response-settings-v2-get');
        self::assertSame('mutation',$result['kind']);self::assertSame('observed',$result['outcome']);
        self::assertSame('ENABLED',$result['data']['xfb_meta_ai_biz_agent_wa_fetch_response_settings_v2']['status']);
    }

    public function testKnowledgeRefusalPreservesNativeWebsiteValidationReasons():void
    {
        $service=new BusinessAgentService(static fn():array=>['xfb_meta_ai_biz_agent_wa_update_knowledge'=>[
            'success'=>false,'website_statuses'=>[['is_valid'=>false,'website_url'=>'https://example.com','error_code'=>'DOMAIN_BLOCKLISTED','access_token'=>'PRIVATE']],
        ]]);
        $result=$service->run('knowledge-update',['input'=>['websites'=>[['website_url'=>'https://example.com']]]]);
        self::assertSame('rejected',$result['outcome']);
        self::assertSame('DOMAIN_BLOCKLISTED',$result['data']['xfb_meta_ai_biz_agent_wa_update_knowledge']['website_statuses'][0]['error_code']);
        self::assertStringNotContainsString('PRIVATE',json_encode($result,JSON_THROW_ON_ERROR));
    }
}
