<?php
declare(strict_types=1);
namespace MVO\Facebook\Protocol;

use MVO\Facebook\FacebookException;

/** Reads the Pages launchpoint's inert Relay results, never its scripts. */
final class PagesBootstrapReader
{
    private int $nodes=0;
    private ?string $identity=null;
    /** @var array<string,array<string,mixed>> */ private array $pages=[];
    private bool $collectionSeen=false;

    /** The modern profile ID and delegate_page_id are kept separately.
     * A result without pagination evidence is explicitly non-exhaustive.
     * @return array{items:list<array<string,mixed>>,page_info:array{end_cursor:null,has_next_page:null},exhaustive:bool}
     */
    public function read(#[\SensitiveParameter] string $html,string $expectedAccount):array
    {
        if(strlen($html)>16777216)throw new FacebookException('facebook_response_too_large','The Pages document exceeds the parser limit');
        $this->nodes=0;$this->identity=null;$this->pages=[];$this->collectionSeen=false;
        preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~is',$html,$matches);
        foreach($matches[1] as $script){
            $script=trim($script);if($script===''||!in_array($script[0],['{','['],true))continue;
            try{$value=json_decode($script,true,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException){continue;}
            $this->walk($value,0);
        }
        if($this->identity===null||$this->identity!==$expectedAccount)throw new FacebookException('facebook_identity_unconfirmed','The Pages document did not confirm the connected account');
        if(!$this->collectionSeen)throw new FacebookException('facebook_pages_unavailable','Facebook did not provide a recognized Pages collection');
        return ['items'=>array_values($this->pages),'page_info'=>['end_cursor'=>null,'has_next_page'=>null],'exhaustive'=>false];
    }
    private function walk(mixed $value,int $depth):void
    {
        if(++$this->nodes>200000||$depth>100)throw new FacebookException('facebook_document_limit','The Pages document exceeds the parser nesting limit');
        if(!is_array($value))return;
        foreach($value['define']??[] as $definition){
            if(!is_array($definition)||($definition[0]??null)!=='CurrentUserInitialData')continue;
            $id=$definition[2]['USER_ID']??null;if(is_int($id))$id=(string)$id;
            if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,24}$/D',$id))continue;
            if($this->identity!==null&&$this->identity!==$id)throw new FacebookException('facebook_identity_unconfirmed','The Pages document contains conflicting account identities');
            $this->identity=$id;
        }
        // Only server Relay result data is a source of selectable resources.
        $viewer=$value['__bbox']['result']['data']['viewer']??null;
        if(is_array($viewer)){
            foreach(['admined_pages','adminedPages'] as $field){
                $rows=$viewer[$field]['nodes']??null;
                if(is_array($rows)&&array_is_list($rows)){$this->collectionSeen=true;foreach($rows as $row)if(is_array($row))$this->page($row,'classic');}
            }
            $rows=$viewer['actor']['profile_switcher_eligible_profiles']['nodes']??null;
            if(is_array($rows)&&array_is_list($rows)){
                foreach($rows as $row){
                    $profile=is_array($row)?($row['profile']??null):null;
                    if(!is_array($profile)||(($profile['is_profile_plus']??false)!==true&&($profile['can_manage_classic_page_in_pages_tab']??false)!==true))continue;
                    $this->collectionSeen=true;$this->page($profile,($profile['is_profile_plus']??false)===true?'profile_plus':'classic');
                }
            }
        }
        foreach($value as $child)if(is_array($child))$this->walk($child,$depth+1);
    }
    /** @param array<string,mixed> $row */
    private function page(array $row,string $type):void
    {
        $id=$row['id']??null;if(is_int($id))$id=(string)$id;
        if(!is_string($id)||!preg_match('/^[1-9][0-9]{0,24}$/D',$id)||!is_string($row['name']??null)||trim($row['name'])==='')return;
        $delegate=$row['delegate_page_id']??null;if(is_int($delegate))$delegate=(string)$delegate;
        if($delegate!==null&&(!is_string($delegate)||!preg_match('/^[1-9][0-9]{0,24}$/D',$delegate)))$delegate=null;
        $this->pages[$id]=['id'=>$id,'name'=>$row['name'],'type'=>$type,'actor_id'=>$id,'delegate_page_id'=>$delegate];
    }
}
