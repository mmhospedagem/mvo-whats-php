<?php
declare(strict_types=1);

namespace MVO\Facebook\Service;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Catalog\PageManagementDocuments;
use MVO\Facebook\Catalog\PageAdministrationDocuments;
use MVO\Facebook\Protocol\WebGraphQL;

/** Observed Page roles, limited-access administration and business portfolio task management. */
final class PageAdministrationService
{
    private ?int $reauthenticatedAt=null;
    /** @var array<string,array<string,int>> Recent business-scoped table rows, never inferred from a global person ID. */
    private array $observedBusinessUsers=[];
    /** @var array<string,array<string,array{role:int,pending:bool,observed_at:int}>> */
    private array $observedPageRoles=[];
    /** In-memory secrets only; never return auth_token or persist it in a command projection.
     * @var array<string,array{token:string,verified_at:int}>
     */
    private array $pageRoleProofs=[];
    public function __construct(private readonly WebGraphQL $graph) {}

    /** Business portfolio people and their server task IDs; this does not imply Page ownership.
     * @param array<string,mixed> $providers Current Relay providers from the open settings context.
     * @return array<string,mixed>
     */
    public function people(string $businessId,array $providers,int $count=20):array
    {
        ServiceInput::actor($businessId);self::count($count);
        $arguments=PageManagementDocuments::arguments('BusinessCometBizSuiteSettingsBusinessUsersViewContainerQuery',
            [...$providers,'businessID'=>$businessId,'first'=>$count,'orderBy'=>null,'isUnifiedSettings'=>true]);
        $result=self::peopleProjection($this->graph->execute('BusinessCometBizSuiteSettingsBusinessUsersViewContainerQuery',$arguments,
            $this->graph->accountId(),'https://business.facebook.com'),$businessId);
        $this->rememberPeople($businessId,$result);return $result;
    }

    /** @param array<string,mixed> $providers
     * @return array<string,mixed>
     */
    public function nextPeople(string $businessId,string $cursor,array $providers,int $count=20):array
    {
        ServiceInput::actor($businessId);ServiceInput::opaque($cursor,'business people cursor');self::count($count);
        $arguments=PageManagementDocuments::arguments('BizKitSettingsPeopleTableListPaginationQuery',
            [...$providers,'id'=>$businessId,'cursor'=>$cursor,'first'=>$count,'isUnifiedSettings'=>true]);
        $data=$this->graph->execute('BizKitSettingsPeopleTableListPaginationQuery',$arguments,$this->graph->accountId(),'https://business.facebook.com');
        // The refetch binds node(id), while the initial route binds business(id).
        $result=self::peopleProjection(['business'=>$data['node']??null],$businessId);
        $this->rememberPeople($businessId,$result);return $result;
    }

    /** Whether the current viewer can manage this Page's admins; no permission is granted here.
     * @return array{page_id:string,can_manage_admins:bool}
     */
    public function pageAccess(string $pageId):array
    {
        ServiceInput::actor($pageId);
        $data=$this->graph->execute('BizKitSettingsPageRolesContextProviderQuery',['pageID'=>$pageId],$this->graph->accountId(),'https://business.facebook.com');
        $page=$data['page']??null;
        if(!is_array($page)||($page['id']??null)!==$pageId||!array_key_exists('if_viewer_can_manage_admins',$page))ServiceInput::unexpected();
        return ['page_id'=>$pageId,'can_manage_admins'=>is_array($page['if_viewer_can_manage_admins'])&&($page['if_viewer_can_manage_admins']['id']??null)===$pageId];
    }

    /** The native Page-role collection, including pending invitations, not portfolio people.
     * @return array<string,mixed>
     */
    public function pageRoles(string $pageId):array
    {
        ServiceInput::actor($pageId);
        $data=$this->graph->execute('BusinessCometBizSuiteSettingsPageRolesViewContainerQuery',
            ['pageID'=>$pageId],$pageId,'https://business.facebook.com');
        $viewer=$data['viewer']??null;
        if(!is_array($viewer)||($viewer['account_user']['id']??null)!==$this->graph->accountId()
            ||($viewer['actor']['id']??null)!==$pageId||($viewer['actor']['__typename']??null)!=='Page')ServiceInput::unexpected();
        $result=self::pageRolesProjection($data['page']??null,$pageId);
        $result['can_manage_admins']=($viewer['actor']['if_viewer_can_manage_admins']['id']??null)===$pageId;
        $this->rememberPageRoles($pageId,$result);return $result;
    }

    /** @return array<string,mixed> */
    public function nextPageRoles(string $pageId,string $cursor,int $count=10):array
    {
        ServiceInput::actor($pageId);ServiceInput::opaque($cursor,'Page roles cursor');self::count($count);
        $data=$this->graph->execute('BizKitSettingsPageRolesListViewPaginationQuery',
            ['id'=>$pageId,'pageID'=>$pageId,'cursor'=>$cursor,'count'=>$count],$pageId,'https://business.facebook.com');
        if(($data['node']['__typename']??null)!=='Page')ServiceInput::unexpected();
        $result=self::pageRolesProjection($data['node']??null,$pageId);
        $this->rememberPageRoles($pageId,$result);return $result;
    }

    /** Password encryption key of the connected person, queried in the target Page context.
     * @return array{page_id:string,account_id:string,key_id:int,public_key:string}
     */
    public function pageRoleReauthenticationKey(string $pageId):array
    {
        ServiceInput::actor($pageId);
        $data=$this->graph->execute('BizKitSettingsPageRolesReauthPasswordDialogQuery',
            ['pageID'=>$pageId],$pageId,'https://business.facebook.com');
        $user=$data['viewer']['account_user']??null;
        $key=is_array($user)?($user['public_key_and_id_for_encryption']??null):null;
        if(!is_array($user)||($user['id']??null)!==$this->graph->accountId()||!is_array($key)
            ||!is_string($key['public_key']??null)||preg_match('/^[a-fA-F0-9]{64}$/D',$key['public_key'])!==1)ServiceInput::unexpected();
        $keyId=$key['key_id']??null;if(is_string($keyId)&&ctype_digit($keyId))$keyId=(int)$keyId;
        if(!is_int($keyId)||$keyId<0||$keyId>255)ServiceInput::unexpected();
        return ['page_id'=>$pageId,'account_id'=>$this->graph->accountId(),'key_id'=>$keyId,'public_key'=>$key['public_key']];
    }

    /** A Page-role auth token is kept only in this object, never in the returned/durable projection.
     * Replaying an old command cannot create a missing token or refresh its timestamp.
     * @return array{page_id:string,account_id:string,reauthenticated:bool,verified_at:int}
     */
    public function reauthenticatePageRolesEncrypted(string $pageId,#[\SensitiveParameter] string $encryptedPassword,string $idempotencyKey):array
    {
        ServiceInput::actor($pageId);ServiceInput::key($idempotencyKey);
        self::encryptedPassword($encryptedPassword);$account=$this->graph->accountId();
        $result=$this->graph->execute('BizKitSettingsPageRolesReauthPasswordDialogMutation',
            ['input'=>['password'=>['sensitive_string_value'=>$encryptedPassword]]],$pageId,'https://business.facebook.com',$idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array{page_id:string,account_id:string,reauthenticated:bool,verified_at:int}
             */
            function(array $data)use($pageId,$account):array{
                $proof=$data['page_pres_perm_management_reauth']??null;
                if(!is_array($proof)||($proof['reauth_is_successful']??null)!==true
                    ||!is_string($proof['auth_token']??null)||$proof['auth_token']===''||strlen($proof['auth_token'])>12000){
                    throw new FacebookException('facebook_page_role_reauthentication_failed','Facebook did not confirm a usable Page-role authentication token','not_confirmed');
                }
                $at=time();$this->pageRoleProofs[$pageId]=['token'=>$proof['auth_token'],'verified_at'=>$at];
                return ['page_id'=>$pageId,'account_id'=>$account,'reauthenticated'=>true,'verified_at'=>$at];
            },fn()=>$this->requirePageRolePermission($pageId));
        $proof=$this->pageRoleProofs[$pageId]??null;
        if($proof===null||($result['verified_at']??null)!==$proof['verified_at']||time()-$proof['verified_at']>300){
            throw new FacebookException('facebook_page_role_reauthentication_required','A saved reauthentication replay has no fresh in-memory Page-role token; complete a new explicit password check');
        }
        return $result;
    }

    /** Send a native Page-role invitation to the exact person selected by the application.
     * @return array<string,mixed>
     */
    public function invitePageRole(string $pageId,string $userId,int $role,string $idempotencyKey):array
    {
        return $this->mutatePageRole($pageId,$userId,$role,'ADD_ROLE',$idempotencyKey);
    }

    /** @return array<string,mixed> */
    public function changePageRole(string $pageId,string $userId,int $role,string $idempotencyKey):array
    {
        return $this->mutatePageRole($pageId,$userId,$role,'CHANGE_ROLE',$idempotencyKey);
    }

    /** Use the current role_value from pageRoles()/nextPageRoles(); scheduled-post deletion is separate and explicit.
     * @return array<string,mixed>
     */
    public function removePageRole(string $pageId,string $userId,int $currentRole,string $idempotencyKey,bool $deleteScheduledPosts=false):array
    {
        return $this->mutatePageRole($pageId,$userId,$currentRole,$deleteScheduledPosts?'DELETE_ROLE_AND_SCHEDULED_POSTS':'DELETE',$idempotencyKey);
    }

    /** @return array<string,mixed> */
    public function cancelPageInvitation(string $pageId,string $userId,int $currentRole,string $idempotencyKey):array
    {
        return $this->mutatePageRole($pageId,$userId,$currentRole,'CANCEL_INVITATION',$idempotencyKey);
    }

    /** Current Business Suite permission-management relationship for this exact Page/person pair.
     * It identifies a portfolio user/request versus a direct administrator; it does not enumerate tasks.
     * @return array<string,mixed>
     */
    public function pageAssetAccess(string $businessId,string $pageId,string $userId):array
    {
        ServiceInput::actor($businessId);ServiceInput::actor($pageId);ServiceInput::actor($userId);
        $data=$this->graph->execute('BizKitRemoveUserAssetConnectionModalQuery',
            ['businessID'=>$businessId,'assetID'=>$pageId,'userID'=>$userId],$this->graph->accountId(),'https://business.facebook.com');
        $object=$data['business_object_rendered_in_ui']??null;$permissions=is_array($object)?($object['user_assigned_permissions']??null):null;
        $user=is_array($permissions)?($permissions['user']??null):null;$asset=is_array($permissions)?($permissions['asset']??null):null;
        if(!is_array($object)||($object['id']??null)!==$pageId||!is_array($asset)||($asset['id']??null)!==$pageId
            ||($asset['business_asset_type']??null)!=='PAGE'||!is_array($user)||($user['id']??null)!==$userId
            ||!in_array($user['__typename']??null,['BusinessUser','BusinessRoleRequest','NonBusinessScopedPermittedUser'],true))ServiceInput::unexpected();
        return ['business_id'=>$businessId,'page_id'=>$pageId,'user_id'=>$userId,'user_type'=>$user['__typename'],
            'is_direct_admin'=>$user['__typename']==='NonBusinessScopedPermittedUser',
            'name'=>is_string($user['name']??null)?$user['name']:null,
            'asset_name'=>is_string($asset['business_object_name']??null)?$asset['business_object_name']:null,
            'scope'=>'page_asset_permission_management'];
    }

    /** Assign the explicit selected Page task IDs to an observed Business User. No role-name translation.
     * @param list<string> $taskIds
     * @return array<string,mixed>
     */
    public function assignPageAssetTasks(string $businessId,string $pageId,string $businessUserId,array $taskIds,string $idempotencyKey):array
    {
        ServiceInput::actor($businessId);ServiceInput::actor($pageId);ServiceInput::actor($businessUserId);ServiceInput::key($idempotencyKey);
        self::inputTaskIds($taskIds);if(count(array_unique($taskIds))!==count($taskIds))throw new \InvalidArgumentException('Do not repeat a selected Page task ID');
        return $this->graph->execute('BizKitSettingsAddUserAssetConnectionMutation',
            ['businessID'=>$businessId,'userID'=>$businessUserId,'assetID'=>$pageId,'taskIDs'=>$taskIds,'assetTypes'=>['PAGE']],
            $this->graph->accountId(),'https://business.facebook.com',$idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array<string,mixed>
             */
            static function(array $data)use($businessId,$pageId,$businessUserId,$taskIds):array{
                $payload=$data['business_settings_add_user_asset_connection']??null;
                if(!is_array($payload)||($payload['business_asset']['id']??null)!==$pageId
                    ||($payload['business_scoped_user_or_request']['id']??null)!==$businessUserId)ServiceInput::unexpected(true);
                return ['business_id'=>$businessId,'page_id'=>$pageId,'business_user_id'=>$businessUserId,
                    'requested_task_ids'=>$taskIds,'status'=>'page_asset_assignment_response_received','tasks_effective_confirmed'=>false];
            },function()use($businessId,$pageId,$businessUserId):void{
                $at=$this->observedBusinessUsers[$businessId][$businessUserId]??null;
                if($at===null||time()-$at>300)throw new FacebookException('facebook_business_user_scope_required','Read this Business User in the current portfolio before assigning Page tasks');
                $this->requirePageRolePermission($pageId);
            });
    }

    /** Remove one Page asset connection using the observed direct-admin/portfolio branch.
     * @param array<string,mixed> $providers Current Web provider values; do not guess them.
     * @return array<string,mixed>
     */
    public function removePageAssetAccess(string $businessId,string $pageId,string $userId,bool $isDirectAdmin,array $providers,string $idempotencyKey):array
    {
        ServiceInput::actor($businessId);ServiceInput::actor($pageId);ServiceInput::actor($userId);ServiceInput::key($idempotencyKey);
        $variables=PageAdministrationDocuments::arguments('BizKitRemoveUserAssetConnectionMutation',[
            ...$providers,'businessID'=>$businessId,'userID'=>$userId,'assetID'=>$pageId,'assetTypes'=>['PAGE'],'isDirectAdmin'=>$isDirectAdmin]);
        return $this->graph->execute('BizKitRemoveUserAssetConnectionMutation',$variables,$this->graph->accountId(),'https://business.facebook.com',$idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array<string,mixed>
             */
            static function(array $data)use($businessId,$pageId,$userId,$isDirectAdmin):array{
                $field=$isDirectAdmin?'business_settings_remove_direct_admin_asset_connection':'business_settings_remove_user_asset_connection';
                $payload=$data[$field]??null;
                if(!is_array($payload)||($payload['business_asset']['id']??null)!==$pageId)ServiceInput::unexpected(true);
                if(!$isDirectAdmin&&($payload['business_scoped_user_or_request']['id']??null)!==$userId)ServiceInput::unexpected(true);
                if($isDirectAdmin){
                    $nodes=$payload['business_asset']['assigned_users']['nodes']??null;
                    if(!is_array($nodes)||!array_is_list($nodes))ServiceInput::unexpected(true);
                    foreach($nodes as $node){
                        if(!is_array($node)||!is_string($node['id']??null))ServiceInput::unexpected(true);
                        if($node['id']===$userId)throw new FacebookException('facebook_access_removal_unconfirmed','The returned Page assignment still contains this direct administrator','not_confirmed');
                    }
                }
                return ['business_id'=>$businessId,'page_id'=>$pageId,'user_id'=>$userId,'is_direct_admin'=>$isDirectAdmin,
                    'status'=>'page_asset_removal_response_received','access_removed_confirmed'=>false];
            },function()use($businessId,$pageId,$userId,$isDirectAdmin):void{
                $context=$this->pageAssetAccess($businessId,$pageId,$userId);
                if($context['is_direct_admin']!==$isDirectAdmin)throw new FacebookException('facebook_page_asset_access_branch_mismatch','Use the actual direct-administrator or portfolio-user branch returned by the Page permission context');
                $this->requirePageRolePermission($pageId);
            });
    }

    /** @return array<string,mixed> */
    private function mutatePageRole(string $pageId,string $userId,int $role,string $mutation,string $key):array
    {
        ServiceInput::actor($pageId);ServiceInput::actor($userId);ServiceInput::key($key);
        if(!in_array($role,[0,1,2,3,4],true))throw new \InvalidArgumentException('Use an observed editable native Page role value; CUSTOM access cannot be changed with this binding');
        $proof=$this->pageRoleProofs[$pageId]??null;
        if($proof===null)throw new FacebookException('facebook_page_role_reauthentication_required','Complete the native Page-role password check on this service before changing access');
        $result=$this->graph->execute('BizKitSettingsPageRolesMutationUserBasedRoleMutation',
            ['input'=>['page_id'=>$pageId,'user_id'=>$userId,'role'=>$role,'mutation_type'=>$mutation,
                'auth_token'=>['sensitive_string_value'=>$proof['token']]],'pageID'=>$pageId],
            $pageId,'https://business.facebook.com',$key,
            /** @param array<string,mixed> $data
             * @return array<string,mixed>
             */
            static function(array $data)use($pageId,$userId,$role,$mutation):array{
                $payload=$data['page_add_new_role_or_mutate_existing_role']??null;
                if(!is_array($payload)||!array_key_exists('error',$payload))ServiceInput::unexpected(true);
                if($payload['error']!==null&&$payload['error']!=='')throw new FacebookException('facebook_page_role_rejected','Facebook rejected the native Page-role operation','not_confirmed');
                $response=$payload['response']??null;
                if(!is_array($response)||($response['user']['id']??null)!==$userId)ServiceInput::unexpected(true);
                $item=self::pageRoleProjection($response,true);
                if(in_array($mutation,['ADD_ROLE','CHANGE_ROLE'],true)&&$item['role_value']!==$role){
                    throw new FacebookException('facebook_page_role_unconfirmed','The returned role differs from the requested Page role','not_confirmed');
                }
                $write=in_array($mutation,['ADD_ROLE','CHANGE_ROLE'],true);
                return ['page_id'=>$pageId,'user_id'=>$userId,'mutation_type'=>$mutation,'role'=>$item,
                    'status'=>$write?($item['is_pending']?'invitation_pending':'role_response_received'):'access_removal_response_received',
                    'role_change_effective_confirmed'=>false,'scheduled_posts_deletion_confirmed'=>false];
            },function()use($pageId,$userId,$role,$mutation,$proof):void{
                if(time()-$proof['verified_at']>300)throw new FacebookException('facebook_page_role_reauthentication_required','The in-memory Page-role authentication proof has expired');
                $this->requirePageRolePermission($pageId);
                if($mutation==='ADD_ROLE')return;
                $observed=$this->observedPageRoles[$pageId][$userId]??null;
                if($observed===null||time()-$observed['observed_at']>300)throw new FacebookException('facebook_page_role_scope_required','Read this person in the target Page role collection on this service before changing or removing access');
                if($observed['role']===7)throw new FacebookException('facebook_page_role_custom_access','The observed UI disables editing or removing CUSTOM access in this flow');
                if(($mutation==='CANCEL_INVITATION')!==$observed['pending'])throw new FacebookException('facebook_page_role_state_conflict','Use cancellation only for a pending Page invitation and role changes/removal only for an accepted role');
                if($mutation!=='CHANGE_ROLE'&&$observed['role']!==$role)throw new FacebookException('facebook_page_role_state_conflict','Preserve the current native role when cancelling or removing Page access');
            });
        unset($this->observedPageRoles[$pageId][$userId]);
        return $result;
    }

    private function requirePageRolePermission(string $pageId):void
    {
        if(!$this->pageAccess($pageId)['can_manage_admins'])throw new FacebookException('facebook_page_role_permission_required','The connected person does not have the observed Page administration permission');
    }

    /** @param array<string,mixed> $result */
    private function rememberPageRoles(string $pageId,array $result):void
    {
        foreach($result['items']as $item)$this->observedPageRoles[$pageId][$item['user_id']]=[
            'role'=>$item['role_value'],'pending'=>$item['is_pending'],'observed_at'=>time()];
    }

    /** @return array<string,mixed> */
    private static function pageRolesProjection(mixed $page,string $pageId):array
    {
        if(!is_array($page)||($page['id']??null)!==$pageId)ServiceInput::unexpected();
        $collection=$page['page_roles']??null;
        if(!is_array($collection)||!is_array($collection['edges']??null)||!array_is_list($collection['edges']))ServiceInput::unexpected();
        $items=[];
        foreach($collection['edges']as $edge){
            if(!is_array($edge)||!is_array($edge['node']??null))ServiceInput::unexpected();
            $items[]=[...self::pageRoleProjection($edge['node']),'cursor'=>is_string($edge['cursor']??null)?$edge['cursor']:null];
        }
        $pageInfo=$collection['page_info']??null;
        if(!is_array($pageInfo)||!is_bool($pageInfo['has_next_page']??null)||!array_key_exists('end_cursor',$pageInfo)
            ||($pageInfo['end_cursor']!==null&&!is_string($pageInfo['end_cursor']))
            ||($pageInfo['has_next_page']&&($pageInfo['end_cursor']===null||$pageInfo['end_cursor']==='')))ServiceInput::unexpected();
        return ['page_id'=>$pageId,'items'=>$items,'page_info'=>['end_cursor'=>$pageInfo['end_cursor'],'has_next_page'=>$pageInfo['has_next_page']],
            'scope'=>'native_page_roles','exhaustive'=>false,'all_administrators'=>false];
    }

    /** @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private static function pageRoleProjection(array $row,bool $mutation=false):array
    {
        $user=$row['user']??null;$role=$row['role_value']??null;
        if(!is_array($user)||!is_string($user['id']??null)||preg_match('/^[1-9][0-9]{0,24}$/D',$user['id'])!==1
            ||!is_int($role)||$role<0||!is_bool($row['is_pending']??null))ServiceInput::unexpected($mutation);
        $label=array_search($role,PageAdministrationDocuments::roles(),true);
        return ['user_id'=>$user['id'],'name'=>is_string($user['name']??null)?$user['name']:null,
            'email'=>is_string($user['user_primary_email']??null)?$user['user_primary_email']:null,
            'role_value'=>$role,'role_name'=>$label===false?null:$label,'is_pending'=>$row['is_pending']];
    }

    private static function encryptedPassword(#[\SensitiveParameter] string $value):void
    {
        if(strlen($value)>12000||preg_match('/^#PWD_BROWSER:5:[0-9]{1,15}:[A-Za-z0-9+\/=]+$/D',$value)!==1)throw new \InvalidArgumentException('Use the encrypted browser password envelope from the current public encryption key');
    }

    /** @return array<string,mixed> */
    public function __debugInfo():array{return ['account_id'=>$this->graph->accountId(),'page_role_authentication'=>'[PRIVATE]'];}

    /** @return array<string,mixed> */
    public function __serialize():array{throw new \LogicException('Page administration contexts contain in-memory authentication state and cannot be serialized');}

    /** @param array<string,mixed> $data */
    public function __unserialize(array $data):void{throw new \LogicException('Restore the owner connection and obtain a new administration service');}

    /** Full current editable values are required to avoid clearing fields accidentally. Task IDs must be
     * copied from the current Business Suite permission context; they are not Page role names.
     * @param array{firstName:string,lastName:string,email:string,business_account_task_ids:list<string>,expireTime:int|null,clientTimezoneID:int} $values
     * @return array<string,mixed>
     */
    public function updateBusinessUser(string $businessId,string $businessUserId,array $values,string $idempotencyKey):array
    {
        ServiceInput::actor($businessId);ServiceInput::actor($businessUserId);ServiceInput::key($idempotencyKey);
        ServiceInput::keys($values,['firstName','lastName','email','business_account_task_ids','expireTime','clientTimezoneID']);
        foreach(['firstName','lastName','email']as $field)ServiceInput::requiredString($values,$field);
        if(filter_var($values['email'],FILTER_VALIDATE_EMAIL)===false)throw new \InvalidArgumentException('Use the current business user email address');
        $tasks=ServiceInput::requiredArray($values,'business_account_task_ids');
        if(!array_is_list($tasks)||count($tasks)>100)throw new \InvalidArgumentException('Supply the exact current list of business task IDs');
        foreach($tasks as $task)if(!is_string($task)||preg_match('/^[A-Za-z0-9_:-]{1,160}$/D',$task)!==1)throw new \InvalidArgumentException('Preserve task IDs returned by the current permission context');
        if(!array_key_exists('expireTime',$values)||($values['expireTime']!==null&&(!is_int($values['expireTime'])||$values['expireTime']<0))
            ||!is_int($values['clientTimezoneID']??null)||$values['clientTimezoneID']<0)throw new \InvalidArgumentException('Supply the current expiry and numeric Facebook client timezone ID');
        if(!isset($this->observedBusinessUsers[$businessId][$businessUserId])||time()-$this->observedBusinessUsers[$businessId][$businessUserId]>300){
            throw new FacebookException('facebook_business_user_scope_required','Read the current business people table on this service to confirm this Business User belongs to the target portfolio before updating it');
        }
        $result=$this->graph->execute('BizKitSettingsUpdateBusinessUserMutation',
            [...$values,'businessUserID'=>$businessUserId,'isUnifiedSettings'=>true],$this->graph->accountId(),'https://business.facebook.com',$idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array<string,mixed>
             */
            static function(array $data)use($businessId,$businessUserId):array{
                $user=$data['business_settings_update_business_user']??null;
                if(!is_array($user)||($user['business']['id']??null)!==$businessId
                    ||(($user['businessUserID']??null)!==$businessUserId&&($user['id']??null)!==$businessUserId))ServiceInput::unexpected(true);
                $taskIds=self::taskIds($user['permitted_business_account_tasks']??null);
                $pending=($user['has_permission_change_under_integrity_review']??false)===true||($user['has_admin_removal_under_integrity_review']??false)===true;
                return ['business_id'=>$businessId,'business_user_id'=>$businessUserId,'name'=>is_string($user['name']??null)?$user['name']:null,
                    'email'=>is_string($user['email']??null)?$user['email']:null,'permitted_business_account_task_ids'=>$taskIds,
                    'status'=>$pending?'pending_integrity_review':'business_user_change_response_received','role_change_effective_confirmed'=>false];
            });
        if(($result['business_id']??null)!==$businessId)throw new FacebookException('facebook_scope_mismatch','The saved business user command belongs to a different business');
        return $result;
    }

    /** Send invitations using explicit server business tasks and asset task IDs. This can trigger an
     * additional Meta security challenge; the SDK neither bypasses it nor resends an uncertain request.
     * @param list<string> $emails
     * @param array{business_account_task_ids:list<string>,assets:list<array{asset_id:string,permitted_task_ids:list<string>}>,invite_origin_surface:string,expiry_time:int|null,client_timezone_id:string,auto_assign_access:bool} $context
     * @return array<string,mixed>
     */
    public function inviteBusinessPeople(string $businessId,array $emails,array $context,string $idempotencyKey):array
    {
        ServiceInput::actor($businessId);ServiceInput::key($idempotencyKey);
        if(!array_is_list($emails)||$emails===[]||count($emails)>100)throw new \InvalidArgumentException('Use 1 to 100 explicit business invitation email addresses');
        foreach($emails as $email)if(!is_string($email)||filter_var($email,FILTER_VALIDATE_EMAIL)===false)throw new \InvalidArgumentException('Use valid business invitation email addresses');
        if(count(array_unique($emails))!==count($emails))throw new \InvalidArgumentException('Do not repeat an invitation address');
        ServiceInput::keys($context,['business_account_task_ids','assets','invite_origin_surface','expiry_time','client_timezone_id','auto_assign_access']);
        $tasks=ServiceInput::requiredArray($context,'business_account_task_ids');self::inputTaskIds($tasks);
        $assets=ServiceInput::requiredArray($context,'assets');
        if(!array_is_list($assets)||count($assets)>100)throw new \InvalidArgumentException('Use an explicit list of at most 100 assigned business assets');
        foreach($assets as $asset){
            if(!is_array($asset))throw new \InvalidArgumentException('Use the current asset assignment records');
            ServiceInput::keys($asset,['asset_id','permitted_task_ids']);ServiceInput::actor(ServiceInput::requiredString($asset,'asset_id'));
            self::inputTaskIds(ServiceInput::requiredArray($asset,'permitted_task_ids'));
        }
        ServiceInput::requiredString($context,'invite_origin_surface');$timezone=ServiceInput::requiredString($context,'client_timezone_id');
        if(!in_array($timezone,\DateTimeZone::listIdentifiers(\DateTimeZone::ALL_WITH_BC),true)&&$timezone!=='UTC')throw new \InvalidArgumentException('Use the current browser IANA timezone name');
        if(!array_key_exists('expiry_time',$context)||($context['expiry_time']!==null&&(!is_int($context['expiry_time'])||$context['expiry_time']<0))
            ||!is_bool($context['auto_assign_access']??null))throw new \InvalidArgumentException('Supply the current invitation expiry and explicit auto-assignment choice');
        return $this->graph->execute('BizKitSettingsInvitePeopleModalMutation',['input'=>[...$context,'business_id'=>$businessId,
            'business_emails'=>$emails,'use_detailed_coded_exception'=>true,'is_spark_permission'=>false]],$this->graph->accountId(),'https://business.facebook.com',$idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array<string,mixed>
             */
            static function(array $data)use($businessId,$emails):array{
                $rows=$data['business_settings_invite_business_users']['business_role_requests']??null;
                if(!is_array($rows)||!array_is_list($rows)||count($rows)>count($emails))ServiceInput::unexpected(true);
                $requests=[];
                foreach($rows as $row){
                    if(!is_array($row)||!is_string($row['id']??null)||$row['id']==='')ServiceInput::unexpected(true);
                    $requests[]=['id'=>$row['id'],'role_request_status'=>is_string($row['role_request_status']??null)?$row['role_request_status']:null,
                        'expiration_time'=>is_int($row['expiration_time']??null)?$row['expiration_time']:null];
                }
                return ['business_id'=>$businessId,'requested_count'=>count($emails),'returned_invitation_count'=>count($requests),'invitations'=>$requests,
                    'status'=>count($requests)===count($emails)?'invitation_response_received':'partial_invitation_response','access_accepted_confirmed'=>false];
            });
    }

    /** Permissions of this authenticated person on the selected profile, not an all-admin inventory.
     * @return array<string,mixed>
     */
    public function permissions(string $profileId):array
    {
        ServiceInput::actor($profileId);
        $data=$this->graph->execute('ProfilePlusCometLARManagePageDialogQuery',
            ['profileID'=>$profileId,'actorID'=>$this->graph->accountId()],$this->graph->accountId());
        $user=$data['user']??null;
        if(!is_array($user)||($user['id']??null)!==$profileId||!is_array($user['perms_for_limited_access_roles']??null)
            ||!array_is_list($user['perms_for_limited_access_roles']))ServiceInput::unexpected();
        $permissions=[];
        foreach($user['perms_for_limited_access_roles'] as $row){
            if(!is_array($row)||!is_array($row['permissions']??null)||!array_is_list($row['permissions']))ServiceInput::unexpected();
            foreach($row['permissions'] as $permission){if(!is_string($permission))ServiceInput::unexpected();$permissions[$permission]=true;}
        }
        return ['profile_id'=>$profileId,'account_id'=>$this->graph->accountId(),'permissions'=>array_keys($permissions),
            'profile_insights_id'=>ServiceInput::scalarId($user['profile_insights_id']??null),
            'primary_platform_tool'=>is_string($user['profile_plus_primary_platform_tool']??null)?$user['profile_plus_primary_platform_tool']:null,
            'pending_core_app_access_approval'=>is_bool($user['viewer_has_pending_core_app_access_approval']??null)?$user['viewer_has_pending_core_app_access_approval']:null,
            'all_administrators'=>false];
    }

    /** Public encryption key required by the actual password-confirmation dialog.
     * @return array{account_id:string,key_id:int,public_key:string}
     */
    public function reauthenticationKey():array
    {
        $account=$this->graph->accountId();
        $data=$this->graph->execute('PagesCometPageWithLimitedAccessRemoveYourselfDialogQuery',['id'=>$account],$account);
        $user=$data['user']??null;$key=is_array($user)?($user['public_key_and_id_for_encryption']??null):null;
        if(!is_array($user)||($user['id']??null)!==$account||!is_array($key)||!is_string($key['public_key']??null)
            ||preg_match('/^[a-fA-F0-9]{64}$/D',$key['public_key'])!==1)ServiceInput::unexpected();
        $keyId=$key['key_id']??null;
        if(is_string($keyId)&&ctype_digit($keyId))$keyId=(int)$keyId;
        if(!is_int($keyId)||$keyId<0||$keyId>255)ServiceInput::unexpected();
        return ['account_id'=>$account,'key_id'=>$keyId,'public_key'=>$key['public_key']];
    }

    /** Pass the exact encrypted browser envelope once, and retain it privately for the same command key.
     * Plaintext passwords, keys and response trees are not stored in the command ledger.
     * @return array{account_id:string,reauthenticated:bool,verified_at:int}
     */
    public function reauthenticateEncrypted(#[\SensitiveParameter] string $encryptedPassword,string $idempotencyKey):array
    {
        ServiceInput::key($idempotencyKey);
        if(strlen($encryptedPassword)>12000||preg_match('/^#PWD_BROWSER:5:[0-9]{1,15}:[A-Za-z0-9+\/=]+$/D',$encryptedPassword)!==1)throw new \InvalidArgumentException('Use the encrypted browser password envelope from the current public encryption key');
        $account=$this->graph->accountId();$this->reauthenticatedAt=null;
        $result=$this->graph->execute('ProfilePlusMarkReauthedMutation',['input'=>['password'=>['sensitive_string_value'=>$encryptedPassword]]],
            $account,'https://www.facebook.com',$idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array{account_id:string,reauthenticated:bool,verified_at:int}
             */
            static function(array $data)use($account):array{
                if(($data['admin_management_mark_reauthed']['reauth_is_successful']??null)!==true)throw new FacebookException('facebook_reauthentication_failed','Facebook did not confirm the administration password check','not_confirmed');
                return ['account_id'=>$account,'reauthenticated'=>true,'verified_at'=>time()];
            });
        $verifiedAt=$result['verified_at']??null;
        if(!is_int($verifiedAt)||$verifiedAt>time()||time()-$verifiedAt>300)throw new FacebookException('facebook_reauthentication_expired','The saved password-confirmation result has expired; a replay cannot refresh authentication');
        $this->reauthenticatedAt=$verifiedAt;return $result;
    }

    /** Remove this account's limited-access role, only after a fresh password-confirmation response on this service.
     * This deliberately cannot remove an unrelated administrator or transfer ownership.
     * @return array{profile_id:string,account_id:string,status:string}
     */
    public function removeMyLimitedAccess(string $profileId,string $idempotencyKey,float $scale=1):array
    {
        ServiceInput::actor($profileId);ServiceInput::key($idempotencyKey);
        if(!is_finite($scale)||$scale<=0||$scale>8)throw new \InvalidArgumentException('Use a finite rendering scale between 0 and 8');
        if($this->reauthenticatedAt===null||time()-$this->reauthenticatedAt>300)throw new FacebookException('facebook_reauthentication_required','Complete the current administration password check on this service before removing your access');
        $account=$this->graph->accountId();
        return $this->graph->execute('ProfilePlusRemoveLimitedAccessRoleMutation',
            ['input'=>['limited_access_role_id'=>$account,'profile_with_biz_tools'=>$profileId],'scale'=>$scale],
            $account,'https://www.facebook.com',$idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array{profile_id:string,account_id:string,status:string}
             */
            static function(array $data)use($profileId,$account):array{
                $profile=$data['remove_limited_access_role_from_profile_with_biz_tools']??null;
                if(!is_array($profile)||($profile['id']??null)!==$profileId||!is_array($profile['perms_for_limited_access_roles']??null)
                    ||!array_is_list($profile['perms_for_limited_access_roles']))ServiceInput::unexpected(true);
                foreach($profile['perms_for_limited_access_roles'] as $role){
                    if(!is_array($role)||!is_array($role['admin_profile']??null)||!is_string($role['admin_profile']['id']??null))ServiceInput::unexpected(true);
                    if($role['admin_profile']['id']===$account)throw new FacebookException('facebook_access_removal_unconfirmed','The returned access list still contains this account','not_confirmed');
                }
                return ['profile_id'=>$profileId,'account_id'=>$account,'status'=>'limited_access_removed'];
            });
    }

    /** @return array<string,mixed> */
    public function availability():array
    {
        return ['validated_in_account'=>false,'operations'=>['permissions'=>true,'reauthentication_key'=>true,
            'reauthenticate_encrypted'=>true,'remove_my_limited_access'=>true,'all_administrators'=>false,'invite_admin'=>true,
            'change_admin_role'=>true,'remove_other_admin'=>true,'transfer_ownership'=>false,
            'native_page_roles'=>true,'next_native_page_roles'=>true,'page_role_reauthentication'=>true,'cancel_page_invitation'=>true,
            'page_asset_access'=>true,'assign_page_asset_tasks'=>true,'remove_page_asset_access'=>true,
            'business_people'=>true,'next_business_people'=>true,'page_access'=>true,'update_business_user_and_tasks'=>true,'invite_business_people'=>true],
            'limit'=>'Native Page-role inventory and invitation/change/removal/cancellation for ADMIN, EDITOR, MODERATOR, ADVERTISER and ANALYST require current permissions and in-memory reauthentication. CUSTOM roles, full modern-access equivalence and ownership transfer remain unavailable'];
    }

    private static function count(int $count):void{if($count<1||$count>100)throw new \InvalidArgumentException('Use a business people count between 1 and 100');}
    /** @param array<string,mixed> $result */
    private function rememberPeople(string $businessId,array $result):void
    {
        foreach($result['items'] as $item)if($item['type']==='BusinessUser')$this->observedBusinessUsers[$businessId][$item['id']]=time();
    }
    /** @param array<mixed> $tasks */
    private static function inputTaskIds(array $tasks):void
    {
        if(!array_is_list($tasks)||count($tasks)>100)throw new \InvalidArgumentException('Use the exact current list of business permission task IDs');
        foreach($tasks as $task)if(!is_string($task)||preg_match('/^[A-Za-z0-9_:-]{1,160}$/D',$task)!==1)throw new \InvalidArgumentException('Use task IDs from the current business permission context');
    }
    /** @return list<string> */
    private static function taskIds(mixed $rows):array
    {
        if(!is_array($rows)||!array_is_list($rows))ServiceInput::unexpected();$ids=[];
        foreach($rows as $row){if(!is_array($row)||!is_string($row['task_id']??null))ServiceInput::unexpected();$ids[]=$row['task_id'];}
        return $ids;
    }
    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function peopleProjection(array $data,string $businessId):array
    {
        $business=$data['business']??null;$connection=is_array($business)?($business['business_users_and_invitations']??null):null;
        if(!is_array($business)||($business['id']??null)!==$businessId||!is_array($connection)||!is_array($connection['edges']??null)||!array_is_list($connection['edges']))ServiceInput::unexpected();
        $items=[];
        foreach($connection['edges'] as $edge){
            if(!is_array($edge))ServiceInput::unexpected();$user=$edge['userInfoForSelection']??null;
            if(!is_array($user)||!is_string($user['id']??null)||!is_string($user['__typename']??null))ServiceInput::unexpected();
            $items[]=['id'=>$user['id'],'type'=>$user['__typename'],'name'=>is_string($user['name']??null)?$user['name']:null,
                'email'=>is_string($user['email']??null)?$user['email']:null,'permitted_business_account_task_ids'=>self::taskIds($user['permitted_business_account_tasks']??null),
                'role_request_status'=>is_string($user['role_request_status']??null)?$user['role_request_status']:null,
                'cursor'=>is_string($edge['cursor']??null)?$edge['cursor']:null];
        }
        $pageInfo=$connection['page_info']??null;
        if(!is_array($pageInfo)||!is_bool($pageInfo['has_next_page']??null)||!array_key_exists('end_cursor',$pageInfo)
            ||($pageInfo['end_cursor']!==null&&!is_string($pageInfo['end_cursor']))||($pageInfo['has_next_page']&&($pageInfo['end_cursor']===null||$pageInfo['end_cursor']==='')))ServiceInput::unexpected();
        return ['business_id'=>$businessId,'items'=>$items,'page_info'=>['end_cursor'=>$pageInfo['end_cursor'],'has_next_page'=>$pageInfo['has_next_page']],
            'exhaustive'=>false,'scope'=>'business_portfolio_people'];
    }
}
