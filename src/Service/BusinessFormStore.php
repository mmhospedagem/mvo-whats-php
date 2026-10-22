<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Protocol\BinaryData;
use MVO\Signal\SignalStore;

/** Session-local saved templates using the observed Android HAS field names; no device catalogue sync. */
final class BusinessFormStore
{
    /** SDK resource bounds, not asserted WhatsApp server limits. */
    public const MAX_FIELDS=20;
    public const MAX_OPTIONS=20;
    public const MAX_TEMPLATES=1000;
    private const NAMESPACE='business-form-templates';
    /** Android Rwh enum names; CUSTOM may repeat, once per custom field. */
    private const FIELD_KEYS=['NAME','PHONE_NUMBER','EMAIL','CPF_CNPJ','ADDRESS','DELIVERY_ADDRESS','CEP','STATE','CITY','NEIGHBORHOOD','STREET_AND_NUMBER','HOUSE_OR_APARTMENT','COMPLEMENT','DEPARTMENT','COUNTRY_REGION','CITIZENSHIP_CARD','ZIP_CODE','ADDRESS_LINE_1','ADDRESS_LINE_2','DELIVERY_NOTE','NEXT','SEND','CONTACT_DETAILS_SCREEN_TITLE','DELIVERY_ADDRESS_SCREEN_TITLE','DELIVERY_ADDRESS_FULL_NAME','CITY_OR_MUNICIPALITY','CUSTOM'];
    private const CUSTOM=['custom_field_names','custom_field_supporting_texts','custom_field_input_types','custom_field_options'];
    private const FIELDS=['form_title','form_description','form_type','message_text','selected_field_list','custom_field_names','custom_field_supporting_texts','custom_field_input_types','custom_field_options','edited_field_keys','edited_field_names','is_ai_form'];
    private \Closure $clock;
    /** @param callable():int|null $clock Unix milliseconds. */
    public function __construct(private readonly SignalStore $store,?callable $clock=null)
    {$this->clock=$clock===null?static fn():int=>(int)floor(microtime(true)*1000):\Closure::fromCallable($clock);}

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function create(array $input):array
    {
        $record=self::normalize($input);
        return $this->store->transaction(function()use($record):array{
            $rows=$this->all();if(count($rows)>=self::MAX_TEMPLATES)throw new \LengthException('The SDK saved-form limit has been reached');
            $record['form_title']=self::uniqueTitle($record['form_title'],$rows);$record['date_time']=$this->nextId($rows);$this->put($record);return self::project($record);
        });
    }
    /** @return array<string,mixed>|null */
    public function get(string $id):?array
    {
        $id=self::id($id);$bytes=$this->store->get(self::NAMESPACE,self::key($id));if($bytes===null)return null;
        $record=$this->decode($bytes);if((string)$record['date_time']!==$id)throw new \UnexpectedValueException('Saved form ID mismatch');return self::project($record);
    }
    /** Chronological pagination; after is a previous row's exact decimal id.
     * @return list<array<string,mixed>> */
    public function list(int $limit=100,string $after=''):array
    {
        if($limit<1||$limit>self::MAX_TEMPLATES)throw new \InvalidArgumentException('Saved form limit must be between 1 and 1000');
        $out=[];foreach($this->store->entries(self::NAMESPACE,$limit,$after===''?'':self::key(self::id($after)))as$bytes)$out[]=self::project($this->decode($bytes));return $out;
    }
    /** Updates only the supplied Android fields, preserving date_time/id.
     * @param array<string,mixed> $changes
     * @return array<string,mixed> */
    public function update(string $id,array $changes):array
    {
        $id=self::id($id);foreach($changes as$field=>$_)if(!in_array($field,self::FIELDS,true))throw new \InvalidArgumentException('Unknown or immutable saved form field: '.$field);
        return $this->store->transaction(function()use($id,$changes):array{
            $existing=$this->get($id)??throw new \OutOfBoundsException('Saved form not found');unset($existing['id'],$existing['date_time']);$record=self::normalize(array_replace($existing,$changes));
            $others=array_values(array_filter($this->all(),static fn(array $row):bool=>(string)$row['date_time']!==$id));
            $record['form_title']=self::uniqueTitle($record['form_title'],$others);$record['date_time']=(int)$id;$this->put($record);return self::project($record);
        });
    }
    /** @return array<string,mixed> */
    public function rename(string $id,string $name):array{return $this->update($id,['form_title'=>$name]);}
    public function delete(string $id):bool
    {
        $id=self::id($id);return $this->store->transaction(function()use($id):bool{if($this->get($id)===null)return false;$this->store->delete(self::NAMESPACE,self::key($id));return true;});
    }
    /** @return array<string,mixed> */
    public function duplicate(string $id,?string $name=null):array
    {
        $record=$this->get($id)??throw new \OutOfBoundsException('Saved form not found');unset($record['id'],$record['date_time']);if($name!==null)$record['form_title']=$name;return $this->create($record);
    }
    /** Local starting definitions, not a catalogue downloaded from WhatsApp.
     * @return array<string,array<string,mixed>> */
    public function templates():array
    {
        $customer=self::normalize(['form_title'=>'Dados do cliente','form_type'=>'CUSTOMER_INFO','selected_field_list'=>['NAME','PHONE_NUMBER','EMAIL']]);
        $offer=self::normalize(['form_title'=>'Inscrição em oferta','form_type'=>'OFFER_SIGNUP','selected_field_list'=>['NAME','PHONE_NUMBER','EMAIL']]);
        return ['customer_info'=>$customer,'offer_signup'=>$offer];
    }
    /** Android-shaped JSON array; this does not write to a phone's wa_props database. */
    public function exportTemplates():string
    {
        $rows=array_map(self::wire(...),$this->all());return json_encode($rows,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
    /** Imports date_time identities atomically. replace replaces this local catalogue only.
     * @return list<array<string,mixed>> */
    public function importTemplates(string $json,bool $replace=false):array
    {
        if(strlen($json)>2097152)throw new \LengthException('Saved form import exceeds the SDK 2 MiB limit');
        try{$rows=json_decode($json,true,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING);}catch(\JsonException $error){throw new \InvalidArgumentException('Saved form import must be a JSON array',0,$error);}
        if(!str_starts_with(ltrim($json),'[')||!is_array($rows)||!array_is_list($rows)||count($rows)>self::MAX_TEMPLATES)throw new \InvalidArgumentException('Saved form import must be a JSON array of at most 1000 templates');
        $normalized=[];$ids=[];
        foreach($rows as$row){
            if(!is_array($row)||(!is_int($row['date_time']??null)&&!is_string($row['date_time']??null)))throw new \InvalidArgumentException('Imported form requires date_time');
            $id=self::id((string)$row['date_time']);if(array_key_exists('id',$row)&&(!is_string($row['id'])||$row['id']!==$id))throw new \InvalidArgumentException('Imported form id must match date_time');
            if(isset($ids[$id]))throw new \InvalidArgumentException('Imported saved form IDs must be unique');$ids[$id]=true;unset($row['id'],$row['date_time']);$record=self::normalize($row);$record['date_time']=(int)$id;$normalized[]=$record;
        }
        return $this->store->transaction(function()use($normalized,$replace):array{
            $existing=$this->all();if(!$replace&&count($existing)+count($normalized)>self::MAX_TEMPLATES)throw new \LengthException('The SDK saved-form limit has been reached');
            $seen=[];foreach($existing as$row)$seen['id:'.$row['date_time']]=true;
            if(!$replace)foreach($normalized as$row)if(isset($seen['id:'.$row['date_time']]))throw new \LogicException('Imported saved form ID already exists');
            $last=$this->store->get('business-form-meta','last-id');$maximum=$last===null?-1:(int)self::id($last);
            foreach([...$existing,...$normalized]as$row)$maximum=max($maximum,$row['date_time']);
            if($replace){foreach($existing as$row)$this->store->delete(self::NAMESPACE,self::key((string)$row['date_time']));$existing=[];}
            $out=[];foreach($normalized as$record){$record['form_title']=self::uniqueTitle($record['form_title'],$existing);$this->put($record);$existing[]=$record;$out[]=self::project($record);}
            if($maximum>=0)$this->store->set('business-form-meta','last-id',(string)$maximum);return $out;
        });
    }
    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    private static function normalize(array $input):array
    {
        foreach($input as$field=>$value){if(!in_array($field,self::FIELDS,true))throw new \InvalidArgumentException('Unknown saved form field: '.$field);if($value===null)throw new \InvalidArgumentException('Saved form fields cannot be null');}
        $out=['form_title'=>self::text($input['form_title']??null,'form_title',256,false),'form_description'=>self::text($input['form_description']??'','form_description',4096),'form_type'=>$input['form_type']??'CUSTOMER_INFO','message_text'=>self::text($input['message_text']??'','message_text',4096)];
        if(!in_array($out['form_type'],['CUSTOMER_INFO','OFFER_SIGNUP'],true))throw new \InvalidArgumentException('Invalid saved form_type');
        $selected=self::strings($input['selected_field_list']??null,'selected_field_list',self::MAX_FIELDS,128,false);if($selected===[])throw new \InvalidArgumentException('Saved form must select at least one field');
        $seen=[];$customCount=0;foreach($selected as$key){if(!in_array($key,self::FIELD_KEYS,true))throw new \InvalidArgumentException('Unknown Android form field: '.$key);if($key==='CUSTOM'){$customCount++;continue;}if(isset($seen[$key]))throw new \InvalidArgumentException('Duplicate predefined form field');$seen[$key]=true;}$out['selected_field_list']=$selected;
        foreach(self::CUSTOM as$field){$value=$input[$field]??[];if(!is_array($value)||!array_is_list($value)||count($value)!==$customCount)throw new \InvalidArgumentException('Parallel custom field arrays must match selected CUSTOM fields');$out[$field]=$value;}
        foreach($out['custom_field_names']as$index=>$name){$out['custom_field_names'][$index]=self::text($name,'custom_field_names',1024,false);$out['custom_field_supporting_texts'][$index]=self::text($out['custom_field_supporting_texts'][$index],'custom_field_supporting_texts',2048);
            $type=$out['custom_field_input_types'][$index];if(!in_array($type,['TEXT_INPUT','TEXT_AREA','DROPDOWN'],true))throw new \InvalidArgumentException('Invalid custom field input type');
            $options=self::strings($out['custom_field_options'][$index],'custom_field_options',self::MAX_OPTIONS,1024,false);
            if(($type==='DROPDOWN'&&$options===[])||($type!=='DROPDOWN'&&$options!==[]))throw new \InvalidArgumentException('Only dropdown fields have nonempty options');
            if(count(array_unique($options))!==count($options))throw new \InvalidArgumentException('Dropdown options must be unique');$out['custom_field_options'][$index]=$options;
        }
        $out['edited_field_keys']=self::strings($input['edited_field_keys']??[],'edited_field_keys',self::MAX_FIELDS,128,false);$out['edited_field_names']=self::strings($input['edited_field_names']??[],'edited_field_names',self::MAX_FIELDS,1024,false);
        if(count($out['edited_field_keys'])!==count($out['edited_field_names'])||count(array_unique($out['edited_field_keys']))!==count($out['edited_field_keys']))throw new \InvalidArgumentException('Edited field keys and names must be unique parallel arrays');
        foreach($out['edited_field_keys']as$key)if($key==='CUSTOM'||!in_array($key,self::FIELD_KEYS,true))throw new \InvalidArgumentException('Invalid edited predefined field key');
        if(isset($input['is_ai_form'])&&!is_bool($input['is_ai_form']))throw new \InvalidArgumentException('is_ai_form must be a boolean');$out['is_ai_form']=$input['is_ai_form']??false;return $out;
    }
    private static function text(mixed $value,string $field,int $limit,bool $empty=true):string
    {
        if(!is_string($value)||strlen($value)>$limit||preg_match('//u',$value)!==1||str_contains($value,"\0")||(!$empty&&trim($value)===''))throw new \InvalidArgumentException('Invalid saved form '.$field);return $value;
    }
    /** @return list<string> */
    private static function strings(mixed $value,string $field,int $count,int $length,bool $empty=true):array
    {
        if(!is_array($value)||!array_is_list($value)||count($value)>$count)throw new \InvalidArgumentException('Invalid saved form '.$field);
        return array_map(static fn(mixed $item):string=>self::text($item,$field,$length,$empty),$value);
    }
    /** @param list<array<string,mixed>> $rows */
    private static function uniqueTitle(string $name,array $rows):string
    {
        $names=array_fill_keys(array_column($rows,'form_title'),true);if(!isset($names[$name]))return $name;
        for($i=1;$i<=self::MAX_TEMPLATES;$i++){$candidate=$name.'('.$i.')';if(!isset($names[$candidate]))return self::text($candidate,'form_title',256,false);}throw new \LengthException('Cannot allocate a unique saved form title');
    }
    /** @param list<array<string,mixed>> $rows */
    private function nextId(array $rows):int
    {
        $clock=($this->clock)();if($clock<0)throw new \InvalidArgumentException('Saved form clock must return positive Unix milliseconds');
        $last=$this->store->get('business-form-meta','last-id');$maximum=$last===null?-1:(int)self::id($last);foreach($rows as$row)$maximum=max($maximum,$row['date_time']);
        if($maximum===PHP_INT_MAX)throw new \OverflowException('Saved form timestamp identity exhausted');$next=max($clock,$maximum+1);$this->store->set('business-form-meta','last-id',(string)$next);return $next;
    }
    /** @return list<array<string,mixed>> */
    private function all():array
    {
        $out=[];foreach($this->store->entries(self::NAMESPACE,self::MAX_TEMPLATES)as$bytes)$out[]=$this->decode($bytes);return $out;
    }
    /** @return array<string,mixed> */
    private function decode(string $bytes):array
    {
        $row=BinaryData::decode($bytes);if(!is_array($row)||!is_int($row['date_time']??null))throw new \UnexpectedValueException('Invalid saved business form');$id=self::id((string)$row['date_time']);unset($row['date_time']);$row=self::normalize($row);$row['date_time']=(int)$id;return $row;
    }
    /** @param array<string,mixed> $row */
    private function put(array $row):void{$this->store->set(self::NAMESPACE,self::key((string)$row['date_time']),BinaryData::encode($row));}
    /** @param array<string,mixed> $row
     * @return array<string,mixed> */
    private static function project(array $row):array{return $row+['id'=>(string)$row['date_time']];}
    /** @param array<string,mixed> $row
     * @return array<string,mixed> */
    private static function wire(array $row):array
    {
        if($row['custom_field_names']===[])foreach(self::CUSTOM as$field)unset($row[$field]);if($row['edited_field_keys']===[])unset($row['edited_field_keys'],$row['edited_field_names']);if(!$row['is_ai_form'])unset($row['is_ai_form']);return $row;
    }
    private static function id(string $id):string
    {
        $maximum=(string)PHP_INT_MAX;if(preg_match('/^(?:0|[1-9][0-9]*)$/D',$id)!==1||strlen($id)>strlen($maximum)||(strlen($id)===strlen($maximum)&&strcmp($id,$maximum)>0))throw new \InvalidArgumentException('Saved form ID must be a nonnegative 64-bit decimal string');return $id;
    }
    private static function key(string $id):string{return str_pad($id,19,'0',STR_PAD_LEFT);}
}
