<?php
declare(strict_types=1);
namespace MVO\Facebook\Protocol;

use MVO\Facebook\FacebookException;

/** Bounded JSON/NDJSON parser. It never evaluates an anti-JSON prefix or scripts. */
final class GraphQLDecoder
{
    /** @return array<string,mixed> */
    public function decode(#[\SensitiveParameter] string $body):array
    {
        if(strlen($body)>16777216)throw new FacebookException('facebook_response_too_large','Facebook returned more data than the configured limit');
        $body=trim($body);if(str_starts_with($body,'for (;;);'))$body=ltrim(substr($body,9));elseif(str_starts_with($body,'for(;;);'))$body=ltrim(substr($body,8));
        try{$whole=json_decode($body,true,128,JSON_THROW_ON_ERROR);$lines=is_array($whole)?[$body]:[];}
        catch(\JsonException){$lines=preg_split('/\r?\n/',$body);}
        if($lines===false||$lines===[]||count($lines)>2048)throw new FacebookException('facebook_invalid_response','Facebook returned an unsupported response stream');
        $envelopes=[];
        foreach($lines as $line){
            if(trim($line)==='')continue;
            try{$value=json_decode($line,true,128,JSON_THROW_ON_ERROR);}catch(\JsonException){throw new FacebookException('facebook_invalid_response','Facebook did not return a valid Web JSON response');}
            if(!is_array($value)||$value===[])throw new FacebookException('facebook_invalid_response','Facebook returned an unsupported JSON envelope');
            foreach(array_is_list($value)?$value:[$value] as $envelope){
                if(!is_array($envelope)||array_is_list($envelope))throw new FacebookException('facebook_invalid_response','Facebook returned an unsupported JSON envelope');
                $envelopes[]=$envelope;
                if(count($envelopes)>2048)throw new FacebookException('facebook_response_too_large','Facebook returned too many response envelopes');
            }
        }
        $result=[];$seen=false;$pending=[];$needsFinal=false;$finalSeen=false;
        foreach($envelopes as $envelope){
            if(isset($envelope['extensions'])&&!is_array($envelope['extensions']))throw new FacebookException('facebook_invalid_response','Facebook returned invalid response extensions');
            if(isset($envelope['error'])||!empty($envelope['errors']))throw new FacebookException('facebook_operation_rejected','Facebook refused this Web operation; check the account permissions and current document',isset($envelope['data'])?'partial':'not_confirmed');
            if(isset($envelope['incremental'])||($envelope['extensions']['is_normalized']??false)===true)throw new FacebookException('facebook_incremental_response_unsupported','Facebook returned an unsupported incremental representation','partial');
            if(array_key_exists('is_final',$envelope['extensions']??[])){
                if(!is_bool($envelope['extensions']['is_final']))throw new FacebookException('facebook_invalid_response','Facebook returned an invalid finalization marker','partial');
                $finalSeen=$envelope['extensions']['is_final'];if(!$finalSeen)$needsFinal=true;
            }
            if(isset($envelope['path'])||isset($envelope['label'])){
                $path=$envelope['path']??null;$label=$envelope['label']??null;
                if(!is_array($path)||!array_is_list($path)||count($path)>64||!is_string($label)||strlen($label)>512||!is_array($envelope['data']??null))throw new FacebookException('facebook_invalid_patch','Facebook returned an invalid incremental patch','partial');
                foreach($path as $part)if((!is_string($part)&&!is_int($part))||(is_int($part)&&($part<0||$part>10000))||(is_string($part)&&($part===''||strlen($part)>256)))throw new FacebookException('facebook_invalid_patch','Facebook returned an invalid patch path','partial');
                $stream=!str_contains($label,'$defer$');
                if($stream&&!str_contains($label,'$stream$')&&!str_contains($label,'$stream_connection$'))throw new FacebookException('facebook_incremental_response_unsupported','The incremental patch kind is not supported','partial');
                $pending[]=['path'=>$path,'data'=>$envelope['data'],'stream'=>$stream];
            }else{
                if(!array_key_exists('data',$envelope))continue;
                // Relay's final sentinel carries no new data. It cannot confirm
                // a response without an earlier application result.
                if($envelope['data']===null&&($envelope['extensions']['is_final']??false)===true)continue;
                if(!is_array($envelope['data']))throw new FacebookException('facebook_invalid_response','Facebook did not return object data');
                foreach($envelope['data'] as $key=>$value){
                    if(array_key_exists($key,$result)&&$result[$key]!==$value)throw new FacebookException('facebook_ambiguous_response','Facebook returned conflicting data for this document','partial');
                    $result[$key]=$value;
                }
                $seen=true;
            }
            do{
                $progress=false;
                foreach($pending as $index=>$patch)if($seen&&$this->patch($result,$patch['path'],$patch['data'],$patch['stream'])){unset($pending[$index]);$progress=true;}
            }while($progress&&$pending!==[]);
        }
        if(!$seen)throw new FacebookException('facebook_unconfirmed_response','Facebook did not confirm an application result','not_confirmed');
        if($pending!==[]||($needsFinal&&!$finalSeen))throw new FacebookException('facebook_incomplete_response','Facebook did not finish the incremental response','partial');
        return $result;
    }
    /** @param array<string,mixed> $root
     * @param list<string|int> $path
     * @param array<mixed> $data
     */
    private function patch(array &$root,array $path,array $data,bool $stream):bool
    {
        $target=&$root;
        if($stream){
            if($path===[]||!is_int($path[array_key_last($path)]))throw new FacebookException('facebook_invalid_patch','The stream patch does not identify an item index','partial');
            $itemIndex=array_pop($path);
        }
        foreach($path as $part){
            if(!is_array($target)||!array_key_exists($part,$target)||$target[$part]===null)return false;
            $target=&$target[$part];
        }
        if(!is_array($target))throw new FacebookException('facebook_invalid_patch','The patch target is not a collection or object','partial');
        if($stream){
            if(!array_is_list($target))throw new FacebookException('facebook_invalid_patch','The stream patch target is not a list','partial');
            if($itemIndex>count($target))return false;
            if($itemIndex===count($target))$target[]=$data;
            elseif($target[$itemIndex]===null)$target[$itemIndex]=$data;
            elseif(is_array($target[$itemIndex]))$target[$itemIndex]=$this->merge($target[$itemIndex],$data);
            else throw new FacebookException('facebook_invalid_patch','The stream patch conflicts with the original item','partial');
        }else $target=$this->merge($target,$data);
        return true;
    }
    /** @param array<mixed> $original
     * @param array<mixed> $patch
     * @return array<mixed>
     */
    private function merge(array $original,array $patch):array
    {
        foreach($patch as $key=>$value){
            if(!array_key_exists($key,$original)||$original[$key]===null){$original[$key]=$value;continue;}
            if($original[$key]===$value)continue;
            if(is_array($original[$key])&&is_array($value)&&!array_is_list($original[$key])&&!array_is_list($value)){$original[$key]=$this->merge($original[$key],$value);continue;}
            throw new FacebookException('facebook_conflicting_patch','Facebook returned conflicting values in an incremental result','partial');
        }
        return $original;
    }
}
