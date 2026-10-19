<?php
declare(strict_types=1);
namespace MVO\Facebook\Service;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Catalog\PageManagementDocuments;
use MVO\Facebook\Protocol\PagesBootstrapReader;
use MVO\Facebook\Protocol\PageCreationContextReader;
use MVO\Facebook\Protocol\WebGraphQL;
use MVO\Facebook\Storage\SelectionStore;
use MVO\Messenger\Auth\WebSession;

/** Page discovery uses the authenticated launchpoint, without changing identity. */
final class PagesService
{
    public function __construct(private readonly WebSession $session,private readonly ?SelectionStore $selections=null,private readonly ?WebGraphQL $graph=null){}
    /** @return array{items:list<array<string,mixed>>,page_info:array{end_cursor:null,has_next_page:null},exhaustive:bool} */
    public function list():array
    {
        try{$response=$this->session->request('GET','https://www.facebook.com/pages/?category=your_pages');}
        catch(\Throwable){throw new FacebookException('facebook_transport_failed','The Facebook Pages request did not complete','not_confirmed',true);}
        if(in_array($response->status,[301,302,303,307,308,401],true))throw new FacebookException('facebook_authentication_required','Facebook requires authentication or verification before listing Pages');
        if($response->status===429)throw new FacebookException('facebook_rate_limited','Facebook temporarily limited Page discovery','not_confirmed',true);
        if($response->status!==200)throw new FacebookException('facebook_http_failed','Facebook did not return the Pages launchpoint','not_confirmed',$response->status>=500);
        $result=(new PagesBootstrapReader())->read($response->body,$this->session->accountId());
        $this->selections?->remember('page',$result['items']);
        return $result;
    }

    /** Read the actual creation surface capability as the authenticated person, without creating a Page.
     * A business/Page actor is not silently converted into a personal identity.
     * @return array<string,mixed>
     */
    public function creationContext(float $scale=1):array
    {
        self::scale($scale);$account=$this->session->accountId();
        $data=$this->web()->execute('AdditionalProfilePlusCreationRootQuery',['scale'=>$scale],$account);
        $viewer=$data['viewer']??null;$actor=is_array($viewer)?($viewer['actor']??null):null;
        if(!is_array($viewer)||!is_bool($viewer['can_create_page']??null)||!is_array($actor)
            ||!is_string($actor['__typename']??null)||!is_string($actor['id']??null))ServiceInput::unexpected();
        if($actor['__typename']!=='User'||$actor['id']!==$account)throw new FacebookException('facebook_page_creation_requires_personal_profile','Open the Page creation flow as the authenticated personal account; a Page or business actor cannot substitute for it');
        $suggested=$data['additional_profile_plus_creation_mvp_suggested_categories']??null;
        if($suggested!==null&&(!is_array($suggested)||!array_is_list($suggested)))ServiceInput::unexpected();
        $items=[];
        foreach($suggested??[]as $category){
            if(!is_array($category))ServiceInput::unexpected();
            $id=ServiceInput::scalarId($category['category_id']??null);$name=$category['category_name']??null;
            if($id===null||preg_match('/^[1-9][0-9]{0,24}$/D',$id)!==1||!is_string($name))ServiceInput::unexpected();
            $items[]=['id'=>$id,'name'=>$name];
        }
        return ['account_id'=>$account,'actor_id'=>$actor['id'],'can_create_page'=>$viewer['can_create_page'],
            'suggested_categories'=>$items,'suggested_categories_available'=>$suggested!==null,'creation_route'=>'https://www.facebook.com/pages/creation/'];
    }

    /** Obtain usable mutation context in PHP from the authenticated launchpoint creation route.
     * The same Web session/owner performs this GET; browser cookies/storage are not imported.
     * @return array{creation_source:string,page_referrer:string,off_platform_creator_reachout_id:null}
     */
    public function creationRequestContext():array
    {
        try{$response=$this->session->request('GET',PageCreationContextReader::URL);}
        catch(\Throwable){throw new FacebookException('facebook_transport_failed','The Page creation bootstrap request did not complete','not_confirmed',true);}
        if(in_array($response->status,[301,302,303,307,308,401],true))throw new FacebookException('facebook_authentication_required','Facebook requires the personal account or a security check before loading Page creation');
        if($response->status===429)throw new FacebookException('facebook_rate_limited','Facebook temporarily limited the Page creation context request','not_confirmed',true);
        if($response->status!==200)throw new FacebookException('facebook_http_failed','Facebook did not return the Page creation bootstrap','not_confirmed',$response->status>=500);
        return (new PageCreationContextReader())->read($response->body,$this->session->accountId());
    }

    /** Category lookup uses only the observed search_string input; the result limit is local.
     * @return array<string,mixed>
     */
    public function categories(string $search,int $limit=10):array
    {
        $search=trim($search);
        if($search===''||preg_match('//u',$search)!==1||str_contains($search,"\0")||self::utf16Length($search)>256)throw new \InvalidArgumentException('Use a nonempty category search within 256 UTF-16 units');
        if($limit<1||$limit>100)throw new \InvalidArgumentException('Use a local category result limit between 1 and 100');
        $data=$this->web()->execute('usePagesCometAdminEditingCategoryDataSourceQuery',['params'=>['search_string'=>$search]],$this->session->accountId());
        $nodes=$data['page_creation_category_typeahead_search']['results']['nodes']??null;
        if(!is_array($nodes)||!array_is_list($nodes)||count($nodes)>10000)ServiceInput::unexpected();
        $items=[];
        foreach($nodes as $node){
            if($node===null)continue;
            if(!is_array($node))ServiceInput::unexpected();
            $id=ServiceInput::scalarId($node['category_id']??null);$name=$node['category_name']??null;
            if($id===null||preg_match('/^[1-9][0-9]{0,24}$/D',$id)!==1||!is_string($name))ServiceInput::unexpected();
            $items[]=['id'=>$id,'name'=>$name];
        }
        return ['items'=>array_slice($items,0,$limit),'observed_count'=>count($items),'local_limit'=>$limit,
            'truncated_locally'=>count($items)>$limit,'exhaustive'=>false,'page_info'=>['end_cursor'=>null,'has_next_page'=>null]];
    }

    /** Create the first Page/profile pair only; the subsequent setup wizard is not auto-completed.
     * A new attempt verifies the current personal actor and can_create_page before mutation I/O.
     * Read creationContext() separately to present the current capability/terms in the application.
     * Context values come from the current route and gkx(20935) branch, not a fabricated provider default.
     * @param list<string> $categoryIds Exact category IDs returned by categories() or creationContext().
     * @param array{creation_source:string,page_referrer:string,off_platform_creator_reachout_id?:string|null} $context
     * @return array{profile_id:string,page_id:string,actor_id:string,delegate_page_id:string,status:string,setup_complete:bool}
     */
    public function create(string $name,array $categoryIds,string $idempotencyKey,array $context,string $bio=''):array
    {
        $name=trim($name);ServiceInput::key($idempotencyKey);
        if(preg_match('//u',$name)!==1||str_contains($name,"\0")||self::utf16Length($name)<2||self::utf16Length($name)>75)throw new \InvalidArgumentException('Use a UTF-8 Page name of 2 to 75 UTF-16 units');
        if(preg_match('//u',$bio)!==1||str_contains($bio,"\0")||self::utf16Length($bio)>255)throw new \InvalidArgumentException('Use a Page bio of at most 255 UTF-16 units');
        if(!array_is_list($categoryIds)||count($categoryIds)<1||count($categoryIds)>3)throw new \InvalidArgumentException('Use 1 to 3 distinct category IDs from the creation selector');
        $selected=[];
        foreach($categoryIds as $categoryId)$selected[]=self::categoryId($categoryId);
        if(count(array_unique($selected))!==count($selected))throw new \InvalidArgumentException('Use distinct category IDs from the creation selector');
        ServiceInput::keys($context,['creation_source','page_referrer','off_platform_creator_reachout_id']);
        $source=ServiceInput::requiredString($context,'creation_source');
        if(!in_array($source,['comet','www'],true))throw new \InvalidArgumentException('Use the current Web creation_source branch: comet or www');
        $referrer=ServiceInput::requiredString($context,'page_referrer');$reachout=$context['off_platform_creator_reachout_id']??null;
        if($reachout!==null){if(!is_string($reachout))throw new \InvalidArgumentException('Preserve the optional creator reachout ID from the current route');ServiceInput::opaque($reachout,'creator reachout ID',256);}
        $account=$this->session->accountId();
        return $this->web()->execute('AdditionalProfilePlusCreationMutation',['input'=>['bio'=>$bio,'categories'=>$selected,
            'creation_source'=>$source,'name'=>$name,'off_platform_creator_reachout_id'=>$reachout,'page_referrer'=>$referrer]],
            $account,'https://www.facebook.com',$idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array{profile_id:string,page_id:string,actor_id:string,delegate_page_id:string,status:string,setup_complete:bool}
             */
            static function(array $data):array{
                $result=$data['additional_profile_plus_create']??null;
                if(!is_array($result)||!array_key_exists('name_error',$result)||!array_key_exists('error_message',$result))ServiceInput::unexpected(true);
                if($result['name_error']!==null&&$result['name_error']!=='')throw new FacebookException('facebook_page_creation_name_rejected','Facebook rejected the requested Page name; review its naming requirements','not_confirmed');
                if($result['error_message']!==null&&$result['error_message']!=='')throw new FacebookException('facebook_page_creation_rejected','Facebook rejected Page creation or requires an additional account check','not_confirmed');
                $profile=ServiceInput::scalarId($result['additional_profile']['id']??null);$page=ServiceInput::scalarId($result['page']['id']??null);
                if($profile===null||$page===null||preg_match('/^[1-9][0-9]{0,24}$/D',$profile)!==1||preg_match('/^[1-9][0-9]{0,24}$/D',$page)!==1)ServiceInput::unexpected(true);
                return ['profile_id'=>$profile,'page_id'=>$page,'actor_id'=>$profile,'delegate_page_id'=>$page,'status'=>'page_created','setup_complete'=>false];
            },function():void{
                $capability=$this->creationContext();
                if($capability['can_create_page']!==true)throw new FacebookException('facebook_page_creation_unavailable','Facebook did not allow the authenticated person to create a Page in the current creation context');
            });
    }

    /** Modern managed profiles; each returned actor ID is kept separate from its delegate Page ID.
     * @return array<string,mixed>
     */
    public function managed(float $scale=1):array
    {
        self::scale($scale);
        $data=$this->web()->execute('PagesCometLaunchpointUnifiedQueryPagesListRedesignedQuery',['scale'=>$scale]);
        return $this->managedProjection($data);
    }

    /** Follow only the cursor returned by managed()/next(); this is one page, not complete inventory.
     * @return array<string,mixed>
     */
    public function next(string $cursor,int $count=20,float $scale=1):array
    {
        ServiceInput::opaque($cursor,'managed Page cursor');self::scale($scale);
        if($count<1||$count>100)throw new \InvalidArgumentException('Use a Page collection count between 1 and 100');
        $data=$this->web()->execute('PagesCometLaunchPointUnifiedQueryPagesListRedesignedUpdatedPagesSectionQuery',['cursor'=>$cursor,'count'=>$count,'scale'=>$scale]);
        return $this->managedProjection($data);
    }

    /** Minimal name/ID established by the Page deletion-info query; no deletion is performed.
     * @return array{id:string,name:string}|null
     */
    public function get(string $pageId,?string $actorId=null):?array
    {
        $actorId=$this->actor($pageId,$actorId);
        $data=$this->web()->execute('PagesCometPagesYouManageDeletePageDialogQuery',['pageID'=>$pageId],$actorId);
        if(!array_key_exists('page',$data))ServiceInput::unexpected();
        if($data['page']===null)return null;
        $page=$data['page'];
        if(!is_array($page)||($page['id']??null)!==$pageId||!is_string($page['name']??null))ServiceInput::unexpected();
        return ['id'=>$pageId,'name'=>$page['name']];
    }

    /** Current inline field values. Context must be copied from the current editor, not invented.
     * @param array{end_point:string,entry_point:string} $context
     * @return array<string,mixed>
     */
    public function field(string $pageId,string $field,array $context,?string $actorId=null,float $scale=1):array
    {
        $actorId=$this->actor($pageId,$actorId);self::scale($scale);$context=self::context($context);
        $mapping=self::fields();
        if(!isset($mapping[$field]))throw new \InvalidArgumentException('The inline editor supports name, blurb, email, phone and website');
        $data=$this->web()->execute('PagesCometAdminEditingInlineFieldQuery',['pageID'=>$pageId,'field'=>$field,
            'entryPoint'=>$context['entry_point'],'endPoint'=>$context['end_point'],'scale'=>$scale],$actorId);
        $page=$data['page']??null;
        if(!is_array($page)||($page['id']??null)!==$pageId)ServiceInput::unexpected();
        $renderer=$page['comet_page_admin_editing_field']['comet_page_editing_field_renderer']??null;
        if(!is_array($renderer)||!is_string($renderer['__typename']??null))ServiceInput::unexpected();
        $valueKey=$field.'_field_value';
        if(!array_key_exists($valueKey,$renderer))throw new FacebookException('facebook_page_editor_value_unavailable','The Web editor did not provide the established field value');
        $value=$renderer[$valueKey];
        if($field==='phone'){
            if($value!==null&&(!is_array($value)||(!is_string($value['number']??null)&&($value['number']??null)!==null)
                ||(!is_string($value['region_code']??null)&&($value['region_code']??null)!==null)))ServiceInput::unexpected();
            $value=is_array($value)?['number'=>$value['number']??null,'region_code'=>$value['region_code']??null]:null;
        }elseif($value!==null&&!is_string($value))ServiceInput::unexpected();
        return ['page_id'=>$pageId,'field'=>$field,'value'=>$value,'renderer_type'=>$renderer['__typename']];
    }

    /** One independently idempotent field change, rather than a falsely atomic multi-field update.
     * The name mutation submits a change request; it does not prove final approval of the name.
     * @param array{end_point:string,entry_point:string} $context
     * @return array<string,mixed>
     */
    public function updateField(string $pageId,string $field,string $value,string $idempotencyKey,array $context,?string $actorId=null):array
    {
        $actorId=$this->actor($pageId,$actorId);$context=self::context($context);ServiceInput::key($idempotencyKey);
        $mapping=self::fields();
        if(!isset($mapping[$field]))throw new \InvalidArgumentException('The supported Page fields are name, blurb, email, phone and website');
        if(strlen($value)>60000||preg_match('//u',$value)!==1||str_contains($value,"\0"))throw new \InvalidArgumentException('Use a UTF-8 Page field value within the SDK limit');
        if($field==='name'&&trim($value)==='')throw new \InvalidArgumentException('A Page name must not be empty');
        if($field==='email'&&$value!==''&&filter_var($value,FILTER_VALIDATE_EMAIL)===false)throw new \InvalidArgumentException('Use a valid Page email address or an empty value to clear it');
        if($field==='website'&&$value!==''){
            $parts=parse_url($value);
            if(!is_array($parts)||!in_array($parts['scheme']??null,['http','https'],true)||!is_string($parts['host']??null)||isset($parts['user'])||isset($parts['pass']))throw new \InvalidArgumentException('Use an HTTP(S) Page website URL without embedded credentials');
        }
        [$document,$inputField,$responseField,$valueField]=$mapping[$field];
        $arguments=PageManagementDocuments::arguments($document,['input'=>['page_id'=>$pageId,'end_point'=>$context['end_point'],
            'entry_point'=>$context['entry_point'],$inputField=>$value]]);
        return $this->web()->execute($document,$arguments,$actorId,'https://www.facebook.com',$idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array<string,mixed>
             */
            static function(array $data)use($pageId,$field,$responseField,$valueField):array{
                $result=$data[$responseField]??null;
                if(!is_array($result)||!array_key_exists('error',$result)||!array_key_exists($valueField,$result))ServiceInput::unexpected(true);
                if($result['error']!==null&&$result['error']!==false&&$result['error']!=='')throw new FacebookException('facebook_page_field_rejected','Facebook rejected this Page field change; inspect the current editor before another command','not_confirmed');
                if($result[$valueField]!==null&&!is_string($result[$valueField]))ServiceInput::unexpected(true);
                return ['page_id'=>$pageId,'field'=>$field,'value'=>$result[$valueField],
                    'status'=>$field==='name'?'name_change_response_received':'field_change_confirmed','name_approval_confirmed'=>$field==='name'?false:null];
            });
    }

    /** Requests deletion according to the Web operation; the Page is not promised to disappear immediately.
     * @return array{page_id:string,status:string,permanent_deletion_confirmed:bool}
     */
    public function delete(string $pageId,string $idempotencyKey,?string $actorId=null):array
    {
        $actorId=$this->actor($pageId,$actorId);ServiceInput::key($idempotencyKey);
        return $this->web()->execute('usePagesCometDeletePageMutation',['input'=>['page_id'=>$pageId]],$actorId,'https://www.facebook.com',$idempotencyKey,
            /** @param array<string,mixed> $data
             * @return array{page_id:string,status:string,permanent_deletion_confirmed:bool}
             */
            static function(array $data)use($pageId):array{
                if(($data['page_delete']['page']['id']??null)!==$pageId)ServiceInput::unexpected(true);
                return ['page_id'=>$pageId,'status'=>'deletion_requested','permanent_deletion_confirmed'=>false];
            });
    }

    /** Contract coverage only; account capabilities and live success are not inferred from the catalog.
     * @return array<string,mixed>
     */
    public function availability():array
    {
        return ['validated_in_account'=>false,'operations'=>['list'=>true,'managed'=>true,'next'=>true,'get'=>true,'field'=>true,
            'update_fields'=>array_keys(self::fields()),'delete'=>true,'create'=>true,'creation_context'=>true,'creation_request_context'=>true,'categories'=>true],
            'limits'=>['create'=>'Personal-account creation flow, 1 to 3 native categories, current route/source context; post-creation setup is not auto-completed',
                'update'=>'Only the five observed inline fields; no arbitrary settings or atomic multi-field CRUD',
                'delete'=>'Deletion requested; permanent deletion and grace period remain server decisions']];
    }

    private function web():WebGraphQL{return $this->graph??throw new FacebookException('facebook_web_transport_required','Construct this service with the shared Facebook WebGraphQL runtime');}
    private function actor(string $pageId,?string $actorId):string
    {
        ServiceInput::actor($pageId);$actorId??=$pageId;ServiceInput::actor($actorId);
        if($actorId!==$pageId&&$actorId!==$this->session->accountId())throw new \InvalidArgumentException('The actor must be the target Page or the authenticated account');
        return $actorId;
    }
    private static function scale(float $value):void{if(!is_finite($value)||$value<=0||$value>8)throw new \InvalidArgumentException('Use a finite Page rendering scale between 0 and 8');}
    /** Mirrors JavaScript/HTML maxLength units without requiring ext-mbstring. Input is already valid UTF-8. */
    private static function utf16Length(string $value):int
    {
        return preg_match_all('/./us',$value)+preg_match_all('/[\x{10000}-\x{10FFFF}]/u',$value);
    }
    /** Validate an individual untrusted array member without coercing a JSON number into a category ID. */
    private static function categoryId(mixed $value):string
    {
        if(!is_string($value)||preg_match('/^[1-9][0-9]{0,24}$/D',$value)!==1)throw new \InvalidArgumentException('Preserve numeric category IDs as strings');
        return $value;
    }
    /** @param array<string,mixed> $context
     * @return array{end_point:string,entry_point:string}
     */
    private static function context(array $context):array
    {
        ServiceInput::keys($context,['end_point','entry_point']);
        $end=ServiceInput::requiredString($context,'end_point');$entry=ServiceInput::requiredString($context,'entry_point');
        PageManagementDocuments::editContext($end,$entry);return ['end_point'=>$end,'entry_point'=>$entry];
    }
    /** @return array<string,array{string,string,string,string}> */
    private static function fields():array
    {
        return ['name'=>['PagesCometAdminEditingPageNameMutation','page_name','page_edit_name','page_name'],
            'blurb'=>['PagesCometEditPageBlurbMutation','blurb','page_edit_blurb','blurb'],
            'email'=>['PagesCometEditPageEmailMutation','email','page_edit_email','email'],
            'phone'=>['PagesCometEditPagePhoneMutation','phone','page_edit_phone','phone'],
            'website'=>['PagesCometEditPageWebsiteMutation','url','page_edit_website','url']];
    }
    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function managedProjection(array $data):array
    {
        $connection=$data['viewer']['actor']['additional_profiles_with_biz_tools']??null;
        if(!is_array($connection)||!is_array($connection['edges']??null)||!array_is_list($connection['edges']))ServiceInput::unexpected();
        $items=[];
        foreach($connection['edges'] as $edge){
            $node=is_array($edge)?($edge['node']??null):null;
            if($node===null)continue;
            if(!is_array($node)||!is_string($node['id']??null)||!is_string($node['name']??null))ServiceInput::unexpected();
            ServiceInput::actor($node['id']);$delegate=$node['delegate_page_id']??null;
            if($delegate!==null&&(!is_string($delegate)||preg_match('/^[1-9][0-9]{0,24}$/D',$delegate)!==1))ServiceInput::unexpected();
            $items[]=['id'=>$node['id'],'actor_id'=>$node['id'],'name'=>$node['name'],'type'=>'profile_plus','delegate_page_id'=>$delegate,
                'viewer_has_advertising_permission'=>is_bool($node['viewer_has_advertising_permission']??null)?$node['viewer_has_advertising_permission']:null,
                'availability'=>is_string($node['availability']??null)?$node['availability']:null];
        }
        $pageInfo=$connection['page_info']??null;
        if(!is_array($pageInfo)||!array_key_exists('end_cursor',$pageInfo)||!is_bool($pageInfo['has_next_page']??null)
            ||($pageInfo['end_cursor']!==null&&!is_string($pageInfo['end_cursor']))||($pageInfo['has_next_page']&&($pageInfo['end_cursor']===null||$pageInfo['end_cursor']==='')))ServiceInput::unexpected();
        $this->selections?->remember('page',$items);
        return ['items'=>$items,'page_info'=>['end_cursor'=>$pageInfo['end_cursor'],'has_next_page'=>$pageInfo['has_next_page']],
            'exhaustive'=>false,'collection'=>'additional_profiles_with_biz_tools'];
    }
}
