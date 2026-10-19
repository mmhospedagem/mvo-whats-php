<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\PageAdministrationDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Service\PageAdministrationService;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageAdministrationNativeFixture.php';

final class PageAdministrationNativeTest extends TestCase
{
    private const ENCRYPTED='#PWD_BROWSER:5:1792458000:U1lOVEhFVElDX0VOVkVMT1BF';
    private const MUTATION='BizKitSettingsPageRolesMutationUserBasedRoleMutation';

    public function testCatalogPreservesTheObservedRoleValuesAndPublicSourceDigests():void
    {
        self::assertSame(['ADMIN'=>0,'EDITOR'=>1,'MODERATOR'=>2,'ADVERTISER'=>3,'ANALYST'=>4,'CUSTOM'=>7],PageAdministrationDocuments::roles());
        $documents=PageAdministrationDocuments::documents();self::assertCount(11,$documents);
        foreach($documents as $document){self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$document->sourceSha256);self::assertStringStartsWith('https://static.xx.fbcdn.net/rsrc.php/',$document->sourceUrl);}
    }

    public function testNativeListAndCursorKeepThePageActorAndSeparatePendingRoles():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new PageAdministrationService($f->graph);$f->session->responses['BusinessCometBizSuiteSettingsPageRolesViewContainerQuery']=self::roles();
            $result=$service->pageRoles('20002');self::assertSame('ADMIN',$result['items'][0]['role_name']);
            self::assertTrue($result['items'][0]['is_pending']);self::assertFalse($result['all_administrators']);self::assertFalse($result['exhaustive']);
            $f->session->responses['BizKitSettingsPageRolesListViewPaginationQuery']=['node'=>[...self::roles()['page'],'__typename'=>'Page']];
            $service->nextPageRoles('20002','NEXT_CURSOR',12);
            self::assertSame(['count'=>12,'id'=>'20002','pageID'=>'20002','cursor'=>'NEXT_CURSOR'],$f->session->variables('BizKitSettingsPageRolesListViewPaginationQuery')[0]);
            foreach($f->session->requests as $request)self::assertSame('20002',$request['form']['av']);
        }finally{unset($service);$f->close();}
    }

    public function testNativeListRejectsAViewerOrPageFromAnotherScope():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new PageAdministrationService($f->graph);$data=self::roles();$data['viewer']['account_user']['id']='99999';
            $f->session->responses['BusinessCometBizSuiteSettingsPageRolesViewContainerQuery']=$data;
            $this->expectException(FacebookException::class);$service->pageRoles('20002');
        }finally{unset($service);$f->close();}
    }

    public function testReauthenticationKeepsAuthTokenOutOfResultsLedgerAndDebugOutput():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new PageAdministrationService($f->graph);$result=$service->reauthenticatePageRolesEncrypted('20002',self::ENCRYPTED,'reauth-key');
            self::assertTrue($result['reauthenticated']);self::assertArrayNotHasKey('auth_token',$result);
            self::assertSame($result,$service->reauthenticatePageRolesEncrypted('20002',self::ENCRYPTED,'reauth-key'));
            self::assertCount(1,$f->session->variables('BizKitSettingsPageRolesReauthPasswordDialogMutation'));
            $catalog=PageAdministrationDocuments::registry()->get('BizKitSettingsPageRolesReauthPasswordDialogMutation');
            $record=$f->ledger->reserve('10001',$catalog->name,'20002',['origin'=>'https://business.facebook.com','variables'=>['input'=>['password'=>['sensitive_string_value'=>self::ENCRYPTED]]]],'reauth-key');
            self::assertStringNotContainsString('SYNTHETIC_SECRET_AUTH_TOKEN',json_encode($record,JSON_THROW_ON_ERROR));
            ob_start();var_dump($service);$debug=ob_get_clean();self::assertIsString($debug);self::assertStringNotContainsString('SYNTHETIC_SECRET_AUTH_TOKEN',$debug);
            try{serialize($service);self::fail('Authentication contexts cannot be persisted');}catch(\LogicException $e){self::assertStringContainsString('cannot be serialized',$e->getMessage());}
            $restarted=new PageAdministrationService($f->graph);
            try{$restarted->reauthenticatePageRolesEncrypted('20002',self::ENCRYPTED,'reauth-key');self::fail('Replayed token is not restored');}
            catch(FacebookException $e){self::assertSame('facebook_page_role_reauthentication_required',$e->reason);}
            self::assertCount(1,$f->session->variables('BizKitSettingsPageRolesReauthPasswordDialogMutation'));
        }finally{unset($service,$restarted);$f->close();}
    }

    public function testNativeInvitationUsesExactInputAndConfirmedReplayDoesNotRunPreflightAgain():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new PageAdministrationService($f->graph);$service->reauthenticatePageRolesEncrypted('20002',self::ENCRYPTED,'reauth');
            $f->session->responses[self::MUTATION]=self::mutation(2,true);
            $result=$service->invitePageRole('20002','30003',2,'invite-role');self::assertSame('invitation_pending',$result['status']);
            self::assertFalse($result['role_change_effective_confirmed']);
            self::assertSame(['page_id'=>'20002','user_id'=>'30003','role'=>2,'mutation_type'=>'ADD_ROLE','auth_token'=>['sensitive_string_value'=>'SYNTHETIC_SECRET_AUTH_TOKEN']],$f->session->variables(self::MUTATION)[0]['input']);
            $before=count($f->session->requests);$f->session->responses['BizKitSettingsPageRolesContextProviderQuery']=['page'=>['id'=>'20002','if_viewer_can_manage_admins'=>null]];
            self::assertSame($result,$service->invitePageRole('20002','30003',2,'invite-role'));self::assertCount($before,$f->session->requests);
        }finally{unset($service);$f->close();}
    }

    public function testChangingAndRemovingRequireAnObservedAcceptedRoleFromThisPage():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new PageAdministrationService($f->graph);$service->reauthenticatePageRolesEncrypted('20002',self::ENCRYPTED,'reauth');
            try{$service->changePageRole('20002','30003',1,'unscoped');self::fail('Missing Page role observation');}
            catch(FacebookException $e){self::assertSame('facebook_page_role_scope_required',$e->reason);self::assertSame('not_executed',$e->outcome);}
            self::assertCount(0,$f->session->variables(self::MUTATION));
            $f->session->responses['BusinessCometBizSuiteSettingsPageRolesViewContainerQuery']=self::roles(0,false);$service->pageRoles('20002');
            $f->session->responses[self::MUTATION]=self::mutation(1,false);$service->changePageRole('20002','30003',1,'change-role');
            $f->session->responses['BusinessCometBizSuiteSettingsPageRolesViewContainerQuery']=self::roles(1,false);$service->pageRoles('20002');
            $f->session->responses[self::MUTATION]=self::mutation(1,false);$result=$service->removePageRole('20002','30003',1,'remove-role');
            self::assertSame('access_removal_response_received',$result['status']);self::assertFalse($result['scheduled_posts_deletion_confirmed']);
            self::assertSame('DELETE',$f->session->variables(self::MUTATION)[1]['input']['mutation_type']);
        }finally{unset($service);$f->close();}
    }

    public function testPendingInvitationUsesCancellationAndCustomAccessCannotBeEdited():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new PageAdministrationService($f->graph);$service->reauthenticatePageRolesEncrypted('20002',self::ENCRYPTED,'reauth');
            $f->session->responses['BusinessCometBizSuiteSettingsPageRolesViewContainerQuery']=self::roles();$service->pageRoles('20002');
            try{$service->removePageRole('20002','30003',0,'wrong-action');self::fail('Pending must cancel');}
            catch(FacebookException $e){self::assertSame('facebook_page_role_state_conflict',$e->reason);}
            $f->session->responses[self::MUTATION]=self::mutation(0,true);$service->cancelPageInvitation('20002','30003',0,'cancel-role');
            self::assertSame('CANCEL_INVITATION',$f->session->variables(self::MUTATION)[0]['input']['mutation_type']);
            $f->session->responses['BusinessCometBizSuiteSettingsPageRolesViewContainerQuery']=self::roles(7,false);$service->pageRoles('20002');
            try{$service->changePageRole('20002','30003',1,'custom-role');self::fail('UI disallows custom access mutation');}
            catch(FacebookException $e){self::assertSame('facebook_page_role_custom_access',$e->reason);}
            self::assertCount(1,$f->session->variables(self::MUTATION));
        }finally{unset($service);$f->close();}
    }

    public function testRolePermissionDeniedPreventsMutationAndReplayCannotAcquireNewPermissions():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new PageAdministrationService($f->graph);$service->reauthenticatePageRolesEncrypted('20002',self::ENCRYPTED,'reauth');
            $f->session->responses['BizKitSettingsPageRolesContextProviderQuery']=['page'=>['id'=>'20002','if_viewer_can_manage_admins'=>null]];
            foreach(['facebook_page_role_permission_required','facebook_command_not_executed']as $reason){
                try{$service->invitePageRole('20002','30003',0,'denied-role');self::fail('No access');}
                catch(FacebookException $e){self::assertSame($reason,$e->reason);self::assertSame('not_executed',$e->outcome);}
            }
            self::assertCount(0,$f->session->variables(self::MUTATION));
        }finally{unset($service);$f->close();}
    }

    public function testUncertainNativeInvitationIsNotResentAndEmbeddedRejectionCannotBecomeSuccess():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new PageAdministrationService($f->graph);$service->reauthenticatePageRolesEncrypted('20002',self::ENCRYPTED,'reauth');
            $f->session->fail[self::MUTATION]=true;
            foreach(['facebook_transport_failed','facebook_command_uncertain']as $reason){
                try{$service->invitePageRole('20002','30003',0,'uncertain-role');self::fail('No confirmation');}
                catch(FacebookException $e){self::assertSame($reason,$e->reason);}
            }
            self::assertCount(1,$f->session->variables(self::MUTATION));$f->session->fail[self::MUTATION]=false;
            $f->session->responses[self::MUTATION]=['page_add_new_role_or_mutate_existing_role'=>['error'=>'SYNTHETIC_REJECTION','response'=>null]];
            try{$service->invitePageRole('20002','30003',1,'rejected-role');self::fail('Upstream rejection');}
            catch(FacebookException $e){self::assertSame('facebook_page_role_rejected',$e->reason);}
        }finally{unset($service);$f->close();}
    }

    public function testPageAssetContextPreservesDirectAndPortfolioIdentitiesWithoutInventingTasks():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new PageAdministrationService($f->graph);$f->session->responses['BizKitRemoveUserAssetConnectionModalQuery']=self::assetContext();
            $result=$service->pageAssetAccess('40004','20002','30003');self::assertTrue($result['is_direct_admin']);
            self::assertSame('NonBusinessScopedPermittedUser',$result['user_type']);self::assertArrayNotHasKey('task_ids',$result);
            self::assertSame('10001',$f->session->requests[0]['form']['av']);
            self::assertSame(['businessID'=>'40004','assetID'=>'20002','userID'=>'30003'],$f->session->variables('BizKitRemoveUserAssetConnectionModalQuery')[0]);
        }finally{unset($service);$f->close();}
    }

    public function testPageTaskAssignmentRequiresRecentPortfolioMembershipAndDoesNotClaimActivation():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new PageAdministrationService($f->graph);$name='BizKitSettingsAddUserAssetConnectionMutation';
            try{$service->assignPageAssetTasks('40004','20002','30003',['SYNTHETIC_TASK'],'unscoped-asset');self::fail('Unknown membership');}
            catch(FacebookException $e){self::assertSame('facebook_business_user_scope_required',$e->reason);self::assertSame('not_executed',$e->outcome);}
            self::assertCount(0,$f->session->variables($name));
            $f->session->responses['BusinessCometBizSuiteSettingsBusinessUsersViewContainerQuery']=['business'=>['id'=>'40004','business_users_and_invitations'=>[
                'edges'=>[['userInfoForSelection'=>['__typename'=>'BusinessUser','id'=>'30003','permitted_business_account_tasks'=>[]]]],
                'page_info'=>['end_cursor'=>null,'has_next_page'=>false]]]];
            $service->people('40004',['__relay_internal__pv__BizKitSettingsPeopleEmptyStaterelayprovider'=>false]);
            $f->session->responses[$name]=['business_settings_add_user_asset_connection'=>['business_asset'=>['id'=>'20002'],'business_scoped_user_or_request'=>['id'=>'30003']]];
            $result=$service->assignPageAssetTasks('40004','20002','30003',['SYNTHETIC_TASK'],'assign-asset');
            self::assertFalse($result['tasks_effective_confirmed']);self::assertSame(['PAGE'],$f->session->variables($name)[0]['assetTypes']);
            self::assertSame(['SYNTHETIC_TASK'],$f->session->variables($name)[0]['taskIDs']);
            $count=count($f->session->requests);self::assertSame($result,$service->assignPageAssetTasks('40004','20002','30003',['SYNTHETIC_TASK'],'assign-asset'));
            self::assertCount($count,$f->session->requests);
        }finally{unset($service);$f->close();}
    }

    public function testAssetRemovalNeedsTheCurrentProviderAndObservedDirectAdminBranch():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new PageAdministrationService($f->graph);$name='BizKitRemoveUserAssetConnectionMutation';
            try{$service->removePageAssetAccess('40004','20002','30003',true,[],'missing-provider');self::fail('No gate guessed');}
            catch(FacebookException $e){self::assertSame('facebook_provider_context_required',$e->reason);self::assertCount(0,$f->session->requests);}
            $providers=['__relay_internal__pv__BillingHubContextFieldCutoverrelayprovider'=>false];
            $f->session->responses['BizKitRemoveUserAssetConnectionModalQuery']=self::assetContext();
            try{$service->removePageAssetAccess('40004','20002','30003',false,$providers,'wrong-branch');self::fail('Wrong user kind');}
            catch(FacebookException $e){self::assertSame('facebook_page_asset_access_branch_mismatch',$e->reason);}
            self::assertCount(0,$f->session->variables($name));
            $f->session->responses[$name]=['business_settings_remove_direct_admin_asset_connection'=>['business_asset'=>['id'=>'20002','assigned_users'=>['nodes'=>[['id'=>'99999']]]]]];
            $result=$service->removePageAssetAccess('40004','20002','30003',true,$providers,'remove-direct');
            self::assertSame('page_asset_removal_response_received',$result['status']);self::assertFalse($result['access_removed_confirmed']);
            self::assertTrue($f->session->variables($name)[0]['isDirectAdmin']);
            self::assertSame(['PAGE'],$f->session->variables($name)[0]['assetTypes']);
        }finally{unset($service);$f->close();}
    }

    public function testDirectAdminRemovalCannotConfirmWhenTheReturnedAssignmentsStillContainTheTarget():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new PageAdministrationService($f->graph);$f->session->responses['BizKitRemoveUserAssetConnectionModalQuery']=self::assetContext();
            $f->session->responses['BizKitRemoveUserAssetConnectionMutation']=['business_settings_remove_direct_admin_asset_connection'=>[
                'business_asset'=>['id'=>'20002','assigned_users'=>['nodes'=>[['id'=>'30003']]]]]];
            try{$service->removePageAssetAccess('40004','20002','30003',true,['__relay_internal__pv__BillingHubContextFieldCutoverrelayprovider'=>true],'not-removed');self::fail('Target still present');}
            catch(FacebookException $e){self::assertSame('facebook_access_removal_unconfirmed',$e->reason);self::assertNotNull($e->commandId);}
        }finally{unset($service);$f->close();}
    }

    public function testExpiredPageProofBlocksANewWriteButDoesNotInvalidateAConfirmedReplay():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new PageAdministrationService($f->graph);$service->reauthenticatePageRolesEncrypted('20002',self::ENCRYPTED,'reauth');
            $f->session->responses[self::MUTATION]=self::mutation(1,true);$saved=$service->invitePageRole('20002','30003',1,'confirmed-role');
            $property=new \ReflectionProperty($service,'pageRoleProofs');$proofs=$property->getValue($service);$proofs['20002']['verified_at']=time()-3600;$property->setValue($service,$proofs);
            $count=count($f->session->requests);self::assertSame($saved,$service->invitePageRole('20002','30003',1,'confirmed-role'));self::assertCount($count,$f->session->requests);
            try{$service->invitePageRole('20002','30003',1,'new-expired-role');self::fail('Old proof');}
            catch(FacebookException $e){self::assertSame('facebook_page_role_reauthentication_required',$e->reason);self::assertSame('not_executed',$e->outcome);}
            self::assertCount(1,$f->session->variables(self::MUTATION));
        }finally{unset($service);$f->close();}
    }

    /** @return array<string,mixed> */
    private static function assetContext():array
    {
        return ['business_object_rendered_in_ui'=>['id'=>'20002','__typename'=>'Page','user_assigned_permissions'=>[
            'asset'=>['id'=>'20002','business_asset_type'=>'PAGE','business_object_name'=>'Página fictícia'],
            'user'=>['id'=>'30003','__typename'=>'NonBusinessScopedPermittedUser','name'=>'Pessoa fictícia']]]];
    }

    /** @return array<string,mixed> */
    private static function roles(int $role=0,bool $pending=true):array
    {
        return ['page'=>['id'=>'20002','page_roles'=>['edges'=>[['node'=>['user'=>['id'=>'30003','name'=>'Pessoa fictícia'],'role_value'=>$role,'is_pending'=>$pending],'cursor'=>'ROLE_CURSOR']],
            'page_info'=>['end_cursor'=>'ROLE_CURSOR','has_next_page'=>true]]],
            'viewer'=>['account_user'=>['id'=>'10001'],'actor'=>['__typename'=>'Page','id'=>'20002','if_viewer_can_manage_admins'=>['id'=>'20002']]]];
    }
    /** @return array<string,mixed> */
    private static function mutation(int $role,bool $pending):array
    {
        return ['page_add_new_role_or_mutate_existing_role'=>['error'=>null,'response'=>['user'=>['id'=>'30003'],'role_value'=>$role,'is_pending'=>$pending]]];
    }
}
