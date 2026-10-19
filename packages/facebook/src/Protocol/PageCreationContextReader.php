<?php
declare(strict_types=1);

namespace MVO\Facebook\Protocol;

use MVO\Facebook\FacebookException;

/** Read only inert ServerJS/HasteSupportData JSON from the fixed creation route. No JavaScript is run. */
final class PageCreationContextReader
{
    public const URL='https://www.facebook.com/pages/creation/?ref_type=launch_point';
    private ?string $account=null;
    private ?bool $creationGate=null;
    private int $visited=0;

    /** HasteSupportData.handle forwards gkxData to gkx.add; gate 20935 selects comet/www.
     * The referrer comes from the exact launchpoint link requested by this SDK, not an invented default.
     * @return array{creation_source:string,page_referrer:string,off_platform_creator_reachout_id:null}
     */
    public function read(#[\SensitiveParameter] string $html,string $expectedAccount):array
    {
        if(preg_match('/^[1-9][0-9]{0,24}$/D',$expectedAccount)!==1)throw new \InvalidArgumentException('Use the exact authenticated account ID');
        if(strlen($html)>16777216)throw new FacebookException('facebook_response_too_large','The creation bootstrap exceeds the parser limit');
        $this->account=null;$this->creationGate=null;$this->visited=0;
        preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~is',$html,$scripts);
        foreach($scripts[1]as $script){
            $script=trim($script);if($script==='')continue;
            if(in_array($script[0],['{','['],true)){
                try{$value=json_decode($script,true,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
                catch(\JsonException){continue;}
                $this->walk($value,0);continue;
            }
            // The shipped legacy wrapper is accepted only at the start of a script and for these handlers.
            if(preg_match('~^requireLazy\(\["(HasteSupportData|ServerJS)"\],function\(([A-Za-z_$][A-Za-z0-9_$]*)\)\{\2\.handle\(~D',$script,$match)!==1)continue;
            $json=self::objectLiteral($script,strlen($match[0]));
            if($json===null)continue;
            try{$value=json_decode($json,true,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}
            catch(\JsonException){continue;}
            if($match[1]==='HasteSupportData')$this->gate($value);else $this->walk($value,0);
        }
        if($this->account!==$expectedAccount)throw new FacebookException('facebook_identity_unconfirmed','The creation bootstrap did not confirm the connected account');
        if($this->creationGate===null)throw new FacebookException('facebook_page_creation_context_unavailable','The authenticated creation bootstrap did not provide gate 20935; no creation_source fallback is supplied');
        return ['creation_source'=>$this->creationGate?'comet':'www','page_referrer'=>'launch_point','off_platform_creator_reachout_id'=>null];
    }

    private function walk(mixed $value,int $depth):void
    {
        if(++$this->visited>200000||$depth>100)throw new FacebookException('facebook_document_limit','The creation bootstrap exceeds the parser nesting limit');
        if(!is_array($value))return;
        $definitions=$value['define']??null;
        foreach(is_array($definitions)?$definitions:[]as $definition){
            if(!is_array($definition)||($definition[0]??null)!=='CurrentUserInitialData')continue;
            $account=$definition[2]['USER_ID']??null;
            if(is_int($account)&&$account>0)$account=(string)$account;
            if(!is_string($account)||preg_match('/^[1-9][0-9]{0,24}$/D',$account)!==1)throw new FacebookException('facebook_identity_unconfirmed','The creation bootstrap does not contain an authenticated personal account');
            if($this->account!==null&&$this->account!==$account)throw new FacebookException('facebook_identity_unconfirmed','The creation bootstrap contains conflicting account identities');
            $this->account=$account;
        }
        $requires=$value['require']??null;
        foreach(is_array($requires)?$requires:[]as $require){
            if(is_array($require)&&($require[0]??null)==='HasteSupportData'&&($require[1]??null)==='handle')$this->gate($require[3][0]??null);
        }
        foreach($value as $child)if(is_array($child))$this->walk($child,$depth+1);
    }

    private function gate(mixed $value):void
    {
        $data=is_array($value)?($value['gkxData']??null):null;
        $gate=is_array($data)?($data['20935']??null):null;
        if($gate===null)return;
        if(!is_array($gate)||!is_bool($gate['result']??null))throw new FacebookException('facebook_page_creation_context_unavailable','The creation source gate did not have the observed boolean format');
        if($this->creationGate!==null&&$this->creationGate!==$gate['result'])throw new FacebookException('facebook_page_creation_context_unavailable','The creation bootstrap contains conflicting creation source gates');
        $this->creationGate=$gate['result'];
    }

    /** Delimit a JSON object while respecting quoted braces/escaped quotes. Never interpret a JS expression. */
    private static function objectLiteral(string $source,int $offset):?string
    {
        while(isset($source[$offset])&&ctype_space($source[$offset]))$offset++;
        if(($source[$offset]??null)!=='{')return null;
        $start=$offset;$depth=0;$quoted=false;$escaped=false;$length=strlen($source);
        for(;$offset<$length;$offset++){
            $char=$source[$offset];
            if($quoted){
                if($escaped){$escaped=false;continue;}
                if($char==='\\'){$escaped=true;continue;}
                if($char==='"')$quoted=false;
                continue;
            }
            if($char==='"'){$quoted=true;continue;}
            if($char==='{')$depth++;
            if($char==='}'&&--$depth===0)return substr($source,$start,$offset-$start+1);
        }
        return null;
    }
}
