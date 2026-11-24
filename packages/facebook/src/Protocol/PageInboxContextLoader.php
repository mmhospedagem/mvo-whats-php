<?php
declare(strict_types=1);

namespace MVO\Facebook\Protocol;

use MVO\Facebook\FacebookException;
use MVO\Messenger\Auth\WebSession;

/** Reads one account-bound native inbox document; never switches identity or follows authentication routes. */
final class PageInboxContextLoader
{
    private const ORIGIN='https://business.facebook.com';

    public function __construct(private readonly WebSession $session) {}

    /** Context stays inside the existing account owner; no cookies or request fields are imported or exported. */
    public function load(string $pageId,?string $selectedItemId=null):PageInboxContext
    {
        return $this->document($pageId,$selectedItemId)->context;
    }

    /** Ephemeral native document for internal snapshot readers only; HTML can contain session-bound secrets. */
    public function document(string $pageId,?string $selectedItemId=null):PageInboxDocument
    {
        if(preg_match('/^[1-9][0-9]{0,24}$/D',$pageId)!==1)throw new \InvalidArgumentException('Preserve the exact numeric Page ID');
        if($selectedItemId!==null&&($selectedItemId===''||strlen($selectedItemId)>4096||preg_match('//u',$selectedItemId)!==1
            ||preg_match('/[\x00-\x1f\x7f]/',$selectedItemId)===1))throw new \InvalidArgumentException('Preserve a bounded nonempty inbox selection ID');
        $query=['asset_id'=>$pageId];if($selectedItemId!==null)$query['selected_item_id']=$selectedItemId;
        $url=self::ORIGIN.'/latest/inbox/all?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
        $seen=[];
        for($redirects=0;;++$redirects){
            $key=self::validate($url,$pageId,$selectedItemId);
            if(isset($seen[$key]))throw new FacebookException('facebook_inbox_redirect_loop','The Page inbox redirected to an already requested document');
            $seen[$key]=true;
            try{$response=$this->session->request('GET',$url);}
            catch(\Throwable){throw new FacebookException('facebook_transport_failed','The Page inbox document request did not complete','not_confirmed',true);}
            if(in_array($response->status,[301,302,303,307,308],true)){
                if($redirects>=3)throw new FacebookException('facebook_inbox_redirect_limit','The Page inbox exceeded the allowed document redirects');
                $location=$response->header('location');
                if(!is_string($location)||$location==='')throw new FacebookException('facebook_inbox_unsafe_redirect','The Page inbox redirect did not provide an allowed document location');
                $url=self::resolve($url,$location);continue;
            }
            if(in_array($response->status,[401,403],true))throw new FacebookException('facebook_authentication_required','The Page inbox requires authentication or account verification');
            if($response->status===429)throw new FacebookException('facebook_rate_limited','Facebook temporarily limited Page inbox document access','not_confirmed',true);
            if($response->status!==200)throw new FacebookException('facebook_http_failed','Facebook did not return a successful Page inbox document','not_confirmed',$response->status>=500);
            $context=(new PageInboxContextReader())->read($response->body,$this->session->accountId(),$pageId,$url);
            return new PageInboxDocument($context,$response->body,$url);
        }
    }

    /** Canonical loop key plus strict scope validation before every request. */
    private static function validate(string $url,string $pageId,?string $selectedItemId):string
    {
        if(strlen($url)>16384||preg_match('/[\x00-\x20\x7f\\\\]/',$url)===1)self::unsafe();
        $parts=parse_url($url);
        if(!is_array($parts)||($parts['scheme']??null)!=='https'||($parts['host']??null)!=='business.facebook.com'
            ||!in_array($parts['path']??null,['/latest/inbox/all','/latest/inbox/all/'],true)
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['port'])||isset($parts['fragment']))self::unsafe();
        $query=self::query($parts['query']??'');
        if(isset($query['asset_id'])&&$query['asset_id']!==$pageId)throw new FacebookException('facebook_inbox_page_mismatch','The Page inbox redirect changed the selected Page asset');
        if(($query['selected_item_id']??null)!==$selectedItemId)throw new FacebookException('facebook_inbox_selection_mismatch','The Page inbox redirect changed the selected inbox item');
        ksort($query,SORT_STRING);
        return self::ORIGIN.$parts['path'].'?'.http_build_query($query,'','&',PHP_QUERY_RFC3986);
    }

    /** Parse exact scalar query keys, refusing duplicate/array-shaped scope aliases.
     * @return array<string,string>
     */
    private static function query(string $query):array
    {
        if($query==='')return [];
        if(str_contains($query,';')||preg_match('/%(?![a-fA-F0-9]{2})/',$query)===1)self::unsafe();
        $values=[];
        foreach(explode('&',$query)as $pair){
            if($pair==='')self::unsafe();
            $parts=explode('=',$pair,2);$name=urldecode($parts[0]);$value=urldecode($parts[1]??'');
            if(preg_match('/^[A-Za-z][A-Za-z0-9_-]{0,127}$/D',$name)!==1||array_key_exists($name,$values)
                ||strlen($value)>4096||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1f\x7f]/',$value)===1)self::unsafe();
            // Nested navigation parameters are not part of a confirmed mailbox document.
            if(in_array(strtolower($name),['next','redirect','redirect_uri','redirect_url','return','return_to','continue','url','u'],true))self::unsafe();
            $values[$name]=$value;
        }
        return $values;
    }

    private static function resolve(string $base,string $location):string
    {
        if(strlen($location)>16384||preg_match('/[\x00-\x20\x7f\\\\]/',$location)===1)self::unsafe();
        $parts=parse_url($location);if(!is_array($parts)||isset($parts['fragment']))self::unsafe();
        if(isset($parts['scheme']))return $location;
        if(isset($parts['host']))return 'https:'.$location;
        if(str_starts_with($location,'/'))return self::ORIGIN.$location;
        $path=parse_url($base,PHP_URL_PATH);if(!is_string($path))self::unsafe();
        if(str_starts_with($location,'?'))return self::ORIGIN.$path.$location;
        $directory=substr($path,0,(int)strrpos($path,'/')+1);
        return self::ORIGIN.$directory.$location;
    }

    private static function unsafe():never { throw new FacebookException('facebook_inbox_unsafe_redirect','The Page inbox redirect left the allowed native mailbox document or its exact scope'); }
    /** @return array<string,string> */
    public function __debugInfo():array { return ['session'=>'[PRIVATE]']; }
}
