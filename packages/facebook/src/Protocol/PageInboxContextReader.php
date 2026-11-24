<?php
declare(strict_types=1);

namespace MVO\Facebook\Protocol;

use MVO\Facebook\FacebookException;

/** Parses inert ServerJS/Relay Business Suite JSON. No JavaScript is executed. */
final class PageInboxContextReader
{
    /** @var array<string,array<string,mixed>> */ private array $definitions=[];
    /** @var list<array<string,mixed>> */ private array $roots=[];
    /** @var list<array<string,mixed>> */ private array $configs=[];
    private ?bool $removePageDependency=null;
    private ?bool $loadIgdOnMsys=null;
    private int $visited=0;

    /** The URL is the actual final response URL, not a user-supplied arbitrary endpoint.
     * Context is ephemeral and must never be exposed outside the account owner.
     */
    public function read(#[\SensitiveParameter] string $html,string $expectedAccount,string $expectedPage,string $documentUrl): PageInboxContext
    {
        self::id($expectedAccount); self::id($expectedPage);
        $url=parse_url($documentUrl);
        if(!is_array($url)||($url['scheme']??null)!=='https'||($url['host']??null)!=='business.facebook.com'
            ||!in_array($url['path']??null,['/latest/inbox/all','/latest/inbox/all/'],true)
            ||isset($url['user'])||isset($url['pass'])||isset($url['port'])||isset($url['fragment']))throw new \InvalidArgumentException('Use the final HTTPS Business Suite inbox document URL');
        if(strlen($html)>16777216)throw new FacebookException('facebook_response_too_large','The Page inbox bootstrap exceeds the parser limit');
        $this->definitions=[];$this->roots=[];$this->configs=[];$this->removePageDependency=null;$this->loadIgdOnMsys=null;$this->visited=0;
        preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~is',$html,$scripts);
        foreach($scripts[1]as $script){
            $script=trim($script);if($script===''||!in_array($script[0],['{','['],true))continue;
            try{$data=json_decode($script,true,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){continue;}
            $this->walk($data,0);
        }
        $identity=$this->definitions['CurrentUserInitialData']['USER_ID']??null;
        if(self::scalarId($identity)!==$expectedAccount)throw new FacebookException('facebook_identity_unconfirmed','The Page inbox bootstrap did not confirm the connected account');
        $identityModule=$this->definitions['CurrentUserInitialData'];
        if(array_key_exists('ACCOUNT_ID',$identityModule)&&self::scalarId($identityModule['ACCOUNT_ID'])!==$expectedAccount)throw new FacebookException('facebook_identity_unconfirmed','The Page inbox account identity does not match the connected owner');
        $relay=$this->definitions['RelayAPIConfigDefaults']??[];
        if(($relay['graphURI']??null)!=='/api/graphql/'||($relay['useXController']??null)!==true)self::unavailable('The Page inbox did not provide the established same-origin Web endpoint');
        // Relay defaults identify the account; the Page actor comes from its exact preloader.
        if(self::scalarId($relay['actorID']??null)!==$expectedAccount)self::unavailable('The default Web environment does not match the authenticated account');
        if(count($this->roots)!==1||count($this->configs)!==1)self::unavailable('The Page inbox bootstrap did not provide one unambiguous route and mailbox config');
        $root=$this->roots[0];$route=$root['initialRouteInfo']['route']??null;
        if(!is_array($route)||($route['canonicalRouteName']??null)!=='comet.bizweb.BusinessCometBizSuiteInboxAllMessagesRoute'
            ||self::scalarId($route['params']['asset_id']??null)!==$expectedPage)self::unavailable('The returned Business Suite route is not scoped to the selected Page');
        foreach(['rootView','hostableView']as $view){
            $scopes=$route[$view]['props']['localScopes']??null;
            if(!is_array($scopes)||!array_is_list($scopes)||count($scopes)!==1||($scopes[0]['type']??null)!=='page'
                ||self::scalarId($scopes[0]['id']??null)!==$expectedPage)self::unavailable('The native route did not confirm one selected Page asset');
        }
        $config=$this->configs[0];
        if(self::scalarId($config['page_id']??null)!==$expectedPage)self::unavailable('The native mailbox config belongs to a different Page');
        $profile=$config['profile_plus_id_for_delegate_page']??null;
        if($profile!==null&&self::scalarId($profile)===null)self::unavailable('The native mailbox config did not provide a valid Page profile actor');
        $profile=self::scalarId($profile);
        if(($config['is_messenger_enabled']??null)!==true)throw new FacebookException('facebook_inbox_unavailable','The selected Page did not enable Messenger in its native inbox config');
        if($this->removePageDependency===null||$this->loadIgdOnMsys===null)self::unavailable('The native inbox did not provide its query gates');
        $preloaders=$root['expectedPreloaders']??null;
        if(!is_array($preloaders)||!array_is_list($preloaders))self::unavailable('The native inbox did not provide its expected preloaders');
        $main=null;$contextCard=null;
        foreach($preloaders as $preloader){
            if(!is_array($preloader))continue;
            $name=$preloader['queryName']??null;
            if($name==='BusinessCometBizSuiteInboxRootQuery'){
                if($main!==null)self::unavailable('The Page inbox has ambiguous root preloaders');$main=$preloader;
            }
            if($name==='BusinessCometContextCardInboxContainerQuery'){
                if($contextCard!==null)self::unavailable('The Page inbox has ambiguous context-card preloaders');$contextCard=$preloader;
            }
        }
        if(!is_array($main)||self::scalarId($main['actorID']??null)!==$expectedPage||!is_array($main['variables']??null))self::unavailable('The native root preloader did not confirm the Page actor');
        if(!is_array($contextCard)||self::scalarId($contextCard['actorID']??null)!==$expectedPage
            ||!is_bool($contextCard['variables']['shouldSkipPageQuery']??null))self::unavailable('The native context-card preloader did not confirm its query mode');
        $vars=$main['variables'];$assets=$vars['business_assets']??null;
        if($this->removePageDependency){
            if(!array_key_exists('page_id',$vars)||$vars['page_id']!==null||!is_array($assets)||!array_is_list($assets)||count($assets)!==1
                ||!is_array($assets[0])||self::scalarId($assets[0]['business_asset_id']??null)!==$expectedPage
                ||self::scalarId($assets[0]['business_account_id']??null)===null)self::unavailable('The native inbox did not confirm the selected business asset');
            $assets=[['business_account_id'=>(string)$assets[0]['business_account_id'],'business_asset_id'=>$expectedPage]];
        }else{
            if(self::scalarId($vars['page_id']??null)!==$expectedPage||$assets!==null)self::unavailable('The native inbox did not confirm the Page-based query mode');
            $assets=[];
        }
        $query=['page_id'=>$this->removePageDependency?null:$expectedPage,
            'businessAssets'=>$assets,'businessAsset'=>$this->removePageDependency?$assets[0]:null,
            'isDependencyOfRemovePageDependencyEnabledTool'=>$this->removePageDependency,
            'getShouldUnifyConfigQueries'=>!$this->loadIgdOnMsys,'shouldUnifyContextCardQueries'=>!$this->loadIgdOnMsys,
            'shouldSkipPageQuery'=>$contextCard['variables']['shouldSkipPageQuery']];
        return new PageInboxContext('https://business.facebook.com',$expectedPage,$expectedPage,$this->fields($expectedAccount),$query,$profile);
    }

    private function walk(mixed $value,int $depth): void
    {
        if(++$this->visited>250000||$depth>100)throw new FacebookException('facebook_document_limit','The Page inbox bootstrap exceeds the parser nesting limit');
        if(!is_array($value))return;
        $definitions=$value['define']??null;
        foreach(is_array($definitions)?$definitions:[]as $row){
            if(!is_array($row)||!is_string($row[0]??null)||!in_array($row[0],['CurrentUserInitialData','RelayAPIConfigDefaults','DTSGInitialData','DTSGInitData','LSD','SiteData','SprinkleConfig','GetAsyncParamsExtraData','WebConnectionClassServerGuess'],true)||!is_array($row[2]??null))continue;
            $name=$row[0];$data=$row[2];
            if(isset($this->definitions[$name])&&$this->definitions[$name]!==$data)self::unavailable('The native inbox contains conflicting bootstrap modules');
            $this->definitions[$name]=$data;
        }
        $requires=$value['require']??null;
        foreach(is_array($requires)?$requires:[]as $row){
            if(!is_array($row))continue;
            if(($row[0]??null)==='HasteSupportData'&&($row[1]??null)==='handle')$this->gates($row[3][0]??null);
            if(is_string($row[0]??null)&&str_starts_with($row[0],'CometPlatformRootClient@')&&($row[1]??null)==='initialize'
                &&is_array($row[2]??null)&&in_array('BusinessCometRootConfig',$row[2],true)&&is_array($row[3][0]??null))$this->roots[]=$row[3][0];
        }
        $data=$value['__bbox']['result']['data']??null;
        if(is_array($data)&&is_array($data['biz_inbox_config']??null))$this->configs[]=$data['biz_inbox_config'];
        foreach($value as $key=>$child){
            if(!is_array($child))continue;
            if($key==='require')foreach($child as $index=>$row){
                // AsyncData may carry a separate worker's ServerJS environment.
                // Its definitions, identities, gates and roots do not belong to
                // this document. Keep all other AsyncData/Relay data traversable.
                if(self::isEmbeddedWorkerResource($row))unset($child[$index][3][1]['data']['__bbox']['hrp']['jsmods']);
            }
            $this->walk($child,$depth+1);
        }
    }

    private static function isEmbeddedWorkerResource(mixed $row): bool
    {
        if(!is_array($row)||!array_is_list($row)||count($row)!==4
            ||!self::moduleReference($row[0]??null,'AsyncData')||($row[1]??null)!=='resolve'
            ||($row[2]??null)!==[]||!is_array($row[3]??null)||!array_is_list($row[3])||count($row[3])!==2
            ||!is_string($row[3][0])||!is_array($row[3][1]))return false;
        $jsmods=$row[3][1]['data']['__bbox']['hrp']['jsmods']??null;
        if(!is_array($jsmods)||!is_array($jsmods['define']??null)||!is_array($jsmods['require']??null)
            ||!array_is_list($jsmods['require']))return false;
        $bootstrap=false;$deferred=false;
        foreach($jsmods['require']as $marker){
            if(!is_array($marker)||!array_is_list($marker))continue;
            if(self::moduleReference($marker[0]??null,'SharedOrWebWorkerBootstrapInit')&&($marker[1]??null)==='startServerJS')$bootstrap=true;
            if(self::moduleReference($marker[0]??null,'WebWorkerInitDeferred')&&($marker[1]??null)==='initDeferred')$deferred=true;
        }
        return $bootstrap&&$deferred;
    }

    private static function moduleReference(mixed $value,string $module): bool
    {
        return is_string($value)&&preg_match('/^'.preg_quote($module,'/').'@[A-Za-z0-9_.-]+$/D',$value)===1;
    }

    private function gates(mixed $value): void
    {
        if(!is_array($value))return;
        $gate=$value['gkxData']['22417']??null;
        if($gate!==null){
            if(!is_array($gate)||!is_bool($gate['result']??null))self::unavailable('The Page dependency gate has an unknown format');
            if($this->removePageDependency!==null&&$this->removePageDependency!==$gate['result'])self::unavailable('The inbox dependency gate is inconsistent');
            $this->removePageDependency=$gate['result'];
        }
        $experiment=$value['qexData']['1407']??null;
        if($experiment!==null){
            if(!is_array($experiment)||!is_bool($experiment['r']??null))self::unavailable('The inbox transport experiment has an unknown format');
            if($this->loadIgdOnMsys!==null&&$this->loadIgdOnMsys!==$experiment['r'])self::unavailable('The inbox transport experiment is inconsistent');
            $this->loadIgdOnMsys=$experiment['r'];
        }
    }

    /** @return array<string,string> */
    private function fields(string $account): array
    {
        $fields=['__user'=>$account,'__a'=>'1'];
        $dtsg=$this->definitions['DTSGInitialData']['token']??$this->definitions['DTSGInitData']['token']??null;
        $lsd=$this->definitions['LSD']['token']??null;
        foreach(['fb_dtsg'=>$dtsg,'lsd'=>$lsd]as $key=>$token){
            if($token===null)continue;
            if(!is_string($token)||$token===''||strlen($token)>65536||str_contains($token,"\0"))self::unavailable('The native inbox did not provide valid private request fields');
            $fields[$key]=$token;
        }
        if(!isset($fields['fb_dtsg'])&&!isset($fields['lsd']))self::unavailable('The native inbox did not provide current CSRF request fields');
        $sprinkle=$this->definitions['SprinkleConfig']??null;
        if($sprinkle!==null){
            // getAsyncParams/DTSGUtils: the actual configured field, version and
            // token-derived sum, with LSD used only when DTSG is unavailable.
            if(($sprinkle['param_name']??null)!=='jazoest'||!is_bool($sprinkle['should_randomize']??null)
                ||(!is_int($sprinkle['version']??null)&&!is_string($sprinkle['version']??null)))self::unavailable('The native inbox has an unsupported Sprinkle configuration');
            $token=$fields['fb_dtsg']??$fields['lsd'];
            if(preg_match('/[^\x20-\x7e]/',$token)===1)self::unavailable('The native inbox token alphabet is not supported by the Sprinkle encoder');
            $sum=0;foreach(str_split($token)as $character)$sum+=ord($character);
            $fields['jazoest']=($sprinkle['should_randomize']?'':(string)$sprinkle['version']).$sum;
        }
        $extra=$this->definitions['GetAsyncParamsExtraData']['extra_data']??[];
        if(!is_array($extra))self::unavailable('The native inbox has malformed extra request fields');
        // These two native business-scoping fields were observed in the
        // Business Suite bootstrap. Do not forward arbitrary unknown payloads.
        foreach(['__aaid','__bid']as $name){
            if(!array_key_exists($name,$extra))continue;
            if(!is_string($extra[$name])||strlen($extra[$name])>256||str_contains($extra[$name],"\0"))self::unavailable('The native inbox has malformed business request fields');
            $fields[$name]=$extra[$name];
        }
        $site=$this->definitions['SiteData']??[];
        foreach(['__spin_r'=>'__spin_r','__spin_b'=>'__spin_b','__spin_t'=>'__spin_t','client_revision'=>'__rev','haste_session'=>'__hs','hsi'=>'__hsi','comet_env'=>'__comet_req']as $source=>$target){
            $value=$site[$source]??null;
            if($value===null)continue;
            if(!is_int($value)&&!is_string($value))self::unavailable('The native inbox has malformed request metadata');
            $fields[$target]=(string)$value;
        }
        if(isset($site['pr'])){
            $density=$site['pr'];
            if((!is_int($density)&&!is_float($density))||!is_finite((float)$density)||$density<=0||$density>16)self::unavailable('The native inbox has malformed rendering request metadata');
            $fields['dpr']=(string)$density;
        }
        $connection=$this->definitions['WebConnectionClassServerGuess']['connectionClass']??null;
        if($connection!==null){
            if(!is_string($connection)||strlen($connection)>128||str_contains($connection,"\0"))self::unavailable('The native inbox has malformed connection request metadata');
            $fields['__ccg']=$connection;
        }
        $fields['__crn']='comet.bizweb.BusinessCometBizSuiteInboxAllMessagesRoute';
        return $fields;
    }

    private static function scalarId(mixed $value): ?string
    {
        if(is_int($value)&&$value>0)$value=(string)$value;
        return is_string($value)&&preg_match('/^[1-9][0-9]{0,24}$/D',$value)===1?$value:null;
    }
    private static function id(string $value): void { if(self::scalarId($value)===null)throw new \InvalidArgumentException('Preserve exact numeric account and Page IDs as strings'); }
    private static function unavailable(string $message): never { throw new FacebookException('facebook_inbox_context_unconfirmed',$message); }
}
