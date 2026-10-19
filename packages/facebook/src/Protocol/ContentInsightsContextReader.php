<?php
declare(strict_types=1);
namespace MVO\Facebook\Protocol;

use MVO\Facebook\FacebookException;

/** Current authenticated provider flags from inert ServerJS/Haste JSON, never executed JavaScript. */
final class ContentInsightsContextReader
{
    public const ASSOCIATED_ADS='__relay_internal__pv__shouldFetchAssociatedAdsInforelayprovider';
    public const AM_PREVIEW='__relay_internal__pv__shouldFetchAMPostPreviewrelayprovider';
    private ?string $account=null;
    /** @var array<string,bool> */ private array $flags=[];
    private int $visited=0;
    /** @return array<string,bool> */
    public function read(#[\SensitiveParameter] string $html,string $expectedAccount):array
    {
        if(!preg_match('/^[1-9][0-9]{0,24}$/D',$expectedAccount))throw new \InvalidArgumentException('Use the connected account ID');
        if(strlen($html)>16777216)throw new FacebookException('facebook_document_limit','The content-table bootstrap exceeds the parser bound');
        $this->account=null;$this->flags=[];$this->visited=0;
        preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~is',$html,$scripts);
        foreach($scripts[1] as $script){
            $script=trim($script);if($script===''||!in_array($script[0],['{','['],true))continue;
            try{$value=json_decode($script,true,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){continue;}
            $this->walk($value,0);
        }
        if($this->account!==$expectedAccount)throw new FacebookException('facebook_identity_unconfirmed','The content-table document did not confirm the original account owner');
        if(count($this->flags)!==2)throw new FacebookException('facebook_content_context_unavailable','The current document did not provide both content-table Relay flags; no fallback values are supplied');
        return $this->flags;
    }
    private function walk(mixed $value,int $depth):void
    {
        if(++$this->visited>200000||$depth>100)throw new FacebookException('facebook_document_limit','The content-table bootstrap exceeds its nesting bound');
        if(!is_array($value))return;
        foreach(is_array($value['define']??null)?$value['define']:[] as $definition){
            if(!is_array($definition)||($definition[0]??null)!=='CurrentUserInitialData')continue;
            $id=$definition[2]['USER_ID']??null;if(is_int($id)&&$id>0)$id=(string)$id;
            if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,24}$/D',$id)||$this->account!==null&&$this->account!==$id)throw new FacebookException('facebook_identity_unconfirmed','The content-table document contains an invalid or conflicting account identity');
            $this->account=$id;
        }
        foreach(is_array($value['require']??null)?$value['require']:[] as $call)if(is_array($call)&&($call[0]??null)==='HasteSupportData'&&($call[1]??null)==='handle')$this->support($call[3][0]??null);
        foreach($value as $child)if(is_array($child))$this->walk($child,$depth+1);
    }
    private function support(mixed $data):void
    {
        if(!is_array($data))return;
        // The public provider modules read justknobx._("6003") and gkx("1513").
        foreach([[self::ASSOCIATED_ADS,'justknobxData','6003','r'],[self::AM_PREVIEW,'gkxData','1513','result']] as [$key,$section,$id,$field]){
            $group=$data[$section]??null;
            if(!is_array($group)||!array_key_exists($id,$group))continue;
            $entry=$group[$id];$value=is_array($entry)?($entry[$field]??null):null;
            if(!is_bool($value)||array_key_exists($key,$this->flags)&&$this->flags[$key]!==$value)throw new FacebookException('facebook_content_context_unavailable','The content-table flags have an unsupported or conflicting format');
            $this->flags[$key]=$value;
        }
    }
}
