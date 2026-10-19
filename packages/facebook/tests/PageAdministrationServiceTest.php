<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\PageManagementDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Service\PageAdministrationService;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageManagementFixture.php';

final class PageAdministrationServiceTest extends TestCase
{
    private const ENCRYPTED='#PWD_BROWSER:5:1792373000:U1lOVEhFVElDX0VOVkVMT1BF';
    public function testPermissionsAreScopedToTheConnectedAccount():void
    {
        $f=new PageManagementFixture(['user'=>['id'=>'20002','perms_for_limited_access_roles'=>[['permissions'=>['PROFILE_PLUS_CREATE_CONTENT','PROFILE_PLUS_ANALYZE']]],'profile_insights_id'=>'30003']]);
        try{
            $service=new PageAdministrationService($f->graph);$r=$service->permissions('20002');
            self::assertSame('10001',$r['account_id']);self::assertFalse($r['all_administrators']);
            self::assertSame('10001',$f->session->variables()['actorID']);self::assertCount(2,$r['permissions']);
        }finally{unset($service);$f->close();}
    }

    public function testAccessRemovalCannotSkipThePasswordCheck():void
    {
        $f=new PageManagementFixture();
        try{
            $service=new PageAdministrationService($f->graph);
            try{$service->removeMyLimitedAccess('20002','remove-key');self::fail('Reauthentication required');}
            catch(FacebookException $e){self::assertSame('facebook_reauthentication_required',$e->reason);}
            self::assertCount(0,$f->session->requests);
        }finally{unset($service);$f->close();}
    }

    public function testOldConfirmedReauthenticationCannotRefreshTheWindowThroughReplay():void
    {
        $f=new PageManagementFixture(['admin_management_mark_reauthed'=>['reauth_is_successful'=>true]]);
        try{
            $arguments=PageManagementDocuments::arguments('ProfilePlusMarkReauthedMutation',['input'=>['password'=>['sensitive_string_value'=>self::ENCRYPTED]]]);
            $command=$f->ledger->reserve('10001','ProfilePlusMarkReauthedMutation','10001',['origin'=>'https://www.facebook.com','variables'=>$arguments],'old-reauth');
            $f->ledger->finish($command['id'],'confirmed',['projection'=>['account_id'=>'10001','reauthenticated'=>true,'verified_at'=>time()-3600]]);
            $service=new PageAdministrationService($f->graph);
            try{$service->reauthenticateEncrypted(self::ENCRYPTED,'old-reauth');self::fail('Old proof');}
            catch(FacebookException $e){self::assertSame('facebook_reauthentication_expired',$e->reason);}
            try{$service->removeMyLimitedAccess('20002','remove-key');self::fail('Replayed proof');}
            catch(FacebookException $e){self::assertSame('facebook_reauthentication_required',$e->reason);}
            self::assertCount(0,$f->session->requests);
        }finally{unset($service);$f->close();}
    }

    public function testFreshReauthenticationAndRemovalConfirmOnlyThisAccountsAccess():void
    {
        $f=new PageManagementFixture(['admin_management_mark_reauthed'=>['reauth_is_successful'=>true]]);
        try{
            $service=new PageAdministrationService($f->graph);$proof=$service->reauthenticateEncrypted(self::ENCRYPTED,'reauth-key');
            self::assertSame($proof,$service->reauthenticateEncrypted(self::ENCRYPTED,'reauth-key'));self::assertCount(1,$f->session->requests);
            $f->session->data=['remove_limited_access_role_from_profile_with_biz_tools'=>['id'=>'20002','perms_for_limited_access_roles'=>[['admin_profile'=>['id'=>'30003']]]]];
            $result=$service->removeMyLimitedAccess('20002','remove-key');self::assertSame('limited_access_removed',$result['status']);
            self::assertSame(['limited_access_role_id'=>'10001','profile_with_biz_tools'=>'20002'],$f->session->variables(1)['input']);
            self::assertSame($result,$service->removeMyLimitedAccess('20002','remove-key'));self::assertCount(2,$f->session->requests);
        }finally{unset($service);$f->close();}
    }

    public function testBusinessPeopleRequireTheActualProviderAndKeepServerTaskIDs():void
    {
        $row=['id'=>'50005','__typename'=>'BusinessUser','name'=>'Pessoa fictícia','email'=>'person@example.test','permitted_business_account_tasks'=>[['task_id'=>'SYNTHETIC_TASK']]];
        $f=new PageManagementFixture(['business'=>['id'=>'40004','business_users_and_invitations'=>['edges'=>[['node'=>['id'=>'50005'],'userInfoForSelection'=>$row,'cursor'=>'PERSON_CURSOR']],
            'page_info'=>['end_cursor'=>'PERSON_CURSOR','has_next_page'=>true]]]]);
        try{
            $service=new PageAdministrationService($f->graph);
            try{$service->people('40004',[]);self::fail('Provider missing');}
            catch(FacebookException $e){self::assertSame('facebook_provider_context_required',$e->reason);}
            self::assertCount(0,$f->session->requests);
            $r=$service->people('40004',['__relay_internal__pv__BizKitSettingsPeopleEmptyStaterelayprovider'=>false]);
            self::assertSame(['SYNTHETIC_TASK'],$r['items'][0]['permitted_business_account_task_ids']);self::assertFalse($r['exhaustive']);
            self::assertSame('40004',$f->session->variables()['businessID']);
        }finally{unset($service);$f->close();}
    }

    public function testBusinessPermissionResponseIsBoundToTheExactBusinessAndDoesNotClaimActivation():void
    {
        $f=new PageManagementFixture(['business_settings_update_business_user'=>['id'=>'50005','business'=>['id'=>'40004'],'name'=>'Teste','email'=>'person@example.test',
            'permitted_business_account_tasks'=>[['task_id'=>'SYNTHETIC_TASK']],'has_permission_change_under_integrity_review'=>true]]);
        try{
            $service=new PageAdministrationService($f->graph);$values=['firstName'=>'Pessoa','lastName'=>'Teste','email'=>'person@example.test',
                'business_account_task_ids'=>['SYNTHETIC_TASK'],'expireTime'=>null,'clientTimezoneID'=>0];
            try{$service->updateBusinessUser('40004','50005',$values,'permission-key');self::fail('Unknown business scope');}
            catch(FacebookException $e){self::assertSame('facebook_business_user_scope_required',$e->reason);self::assertCount(0,$f->session->requests);}
            $mutationResponse=$f->session->data;
            $f->session->data=['business'=>['id'=>'40004','business_users_and_invitations'=>['edges'=>[['userInfoForSelection'=>[
                'id'=>'50005','__typename'=>'BusinessUser','name'=>'Pessoa','permitted_business_account_tasks'=>[['task_id'=>'SYNTHETIC_TASK']]]]],
                'page_info'=>['end_cursor'=>null,'has_next_page'=>false]]]];
            $service->people('40004',['__relay_internal__pv__BizKitSettingsPeopleEmptyStaterelayprovider'=>false]);$f->session->data=$mutationResponse;
            $r=$service->updateBusinessUser('40004','50005',$values,'permission-key');
            self::assertSame('pending_integrity_review',$r['status']);self::assertFalse($r['role_change_effective_confirmed']);
            self::assertSame('50005',$f->session->variables(1)['businessUserID']);
            self::assertSame($r,$service->updateBusinessUser('40004','50005',$values,'permission-key'));self::assertCount(2,$f->session->requests);
        }finally{unset($service);$f->close();}
    }

    public function testInvitationsPreserveExplicitAssetTasksAndPartialResponseWithoutResend():void
    {
        $f=new PageManagementFixture(['business_settings_invite_business_users'=>['business_role_requests'=>[['id'=>'60006','role_request_status'=>'PENDING','expiration_time'=>1792459400]]]]);
        try{
            $service=new PageAdministrationService($f->graph);$context=self::inviteContext();
            $result=$service->inviteBusinessPeople('40004',['first@example.test','second@example.test'],$context,'invitation-key');
            self::assertSame('partial_invitation_response',$result['status']);self::assertFalse($result['access_accepted_confirmed']);
            self::assertSame(1,$result['returned_invitation_count']);
            self::assertSame($context['assets'],$f->session->variables()['input']['assets']);
            self::assertSame('40004',$f->session->variables()['input']['business_id']);
            self::assertSame($result,$service->inviteBusinessPeople('40004',['first@example.test','second@example.test'],$context,'invitation-key'));
            self::assertCount(1,$f->session->requests);
        }finally{unset($service);$f->close();}
    }

    public function testUncertainInvitationDoesNotSendAnotherEmail():void
    {
        $f=new PageManagementFixture([],true);
        try{
            $service=new PageAdministrationService($f->graph);
            foreach(['facebook_transport_failed','facebook_command_uncertain']as $reason){
                try{$service->inviteBusinessPeople('40004',['person@example.test'],self::inviteContext(),'invitation-key');self::fail('Uncertain invitation');}
                catch(FacebookException $e){self::assertSame($reason,$e->reason);self::assertNotNull($e->commandId);}
            }
            self::assertCount(1,$f->session->requests);
        }finally{unset($service);$f->close();}
    }

    /** @return array{business_account_task_ids:list<string>,assets:list<array{asset_id:string,permitted_task_ids:list<string>}>,invite_origin_surface:string,expiry_time:null,client_timezone_id:string,auto_assign_access:bool} */
    private static function inviteContext():array
    {
        return ['business_account_task_ids'=>['SYNTHETIC_BUSINESS_TASK'],'assets'=>[['asset_id'=>'20002','permitted_task_ids'=>['SYNTHETIC_PAGE_TASK']]],
            'invite_origin_surface'=>'MBS_INVITE_USER_FLOW','expiry_time'=>null,'client_timezone_id'=>'America/Sao_Paulo','auto_assign_access'=>false];
    }
}
