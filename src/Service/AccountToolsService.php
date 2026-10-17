<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\AppState\{AppStateProjector,AppStateService};
use MVO\History\HistoryStore;
use MVO\Protocol\Jid;

/** Typed account organization over authenticated AppState; commercial campaign projections are read-only. */
final class AccountToolsService
{
    public function __construct(private readonly AppStateService $appState,private readonly AppStateProjector $projector,private readonly HistoryStore $history) {}

    public static function id(string $id):string{if(preg_match('/^[A-Za-z0-9_.:-]{1,128}$/D',$id)!==1)throw new \InvalidArgumentException('Invalid account record ID');return $id;}
    public static function chat(string $jid,bool $individual=false):string
    {
        $parsed=Jid::recipient($jid)->canonical();
        if($parsed->device!==0||!in_array($parsed->server,$individual?['s.whatsapp.net','lid']:['s.whatsapp.net','lid','g.us'],true)||preg_match($parsed->server==='g.us'?'/^[0-9]+(?:-[0-9]+)?$/D':'/^[0-9]{1,20}$/D',$parsed->user)!==1)throw new \InvalidArgumentException('A bare user or group chat JID is required');return $parsed->bare();
    }
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function agentInput(array $input):array
    {
        self::fields($input,['name','deviceId']);if($input===[])throw new \InvalidArgumentException('Agent update must not be empty');
        if(array_key_exists('name',$input))self::text($input['name'],512,false);
        if(array_key_exists('deviceId',$input)&&(!is_int($input['deviceId'])||$input['deviceId']<0||$input['deviceId']>2147483647))throw new \InvalidArgumentException('Agent deviceId must be a nonnegative int32');return $input;
    }
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function noteInput(array $input):array
    {
        self::fields($input,['chatJid','text']);if($input===[])throw new \InvalidArgumentException('Note update must not be empty');
        if(array_key_exists('chatJid',$input)){if(!is_string($input['chatJid']))throw new \InvalidArgumentException('Note chatJid must be a string');$input['chatJid']=self::chat($input['chatJid']);}
        if(array_key_exists('text',$input))self::text($input['text'],65536,false);return $input;
    }
    /**
     * @param list<string> $ids
     * @return list<string> */
    public static function favoriteIds(array $ids):array
    {
        if(!array_is_list($ids)||count($ids)>1000)throw new \InvalidArgumentException('Favorites require a list of at most 1000 IDs');$out=[];
        foreach($ids as$id){if(!is_string($id)||$id===''||strlen($id)>128||preg_match('//u',$id)!==1||preg_match('/[\x00-\x20\x7f]/',$id))throw new \InvalidArgumentException('Invalid favorite ID');$out[]=$id;}return array_values(array_unique($out));
    }
    /**
     * @return list<array<string,mixed>> */
    public function listAgents(int $limit=100,string $after=''):array{return $this->records('Agent',$limit,$after,static fn(array $r):bool=>($r['isDeleted']??false)!==true);}
    /**
     * @return array<string,mixed>|null */
    public function getAgent(string $id):?array{$r=$this->projector->getAction('Agent',['agentId'=>self::id($id)]);return $r===null||($r['isDeleted']??false)===true?null:self::record('Agent',$r);}
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function saveAgent(string $id,array $input):array
    {
        $id=self::id($id);$input=self::agentInput($input);$current=$this->getAgent($id);$data=array_replace(self::pick($current??[],['name','deviceId']),$input,['isDeleted'=>false]);
        if(!isset($data['name'],$data['deviceId']))throw new \InvalidArgumentException('New agents require name and deviceId');
        $this->appState->mutate('Agent',['agentId'=>$id],$data);return $this->getAgent($id)??throw new \UnexpectedValueException('Agent patch was not projected');
    }
    public function deleteAgent(string $id):void{$current=$this->getAgent($id)??throw new \OutOfBoundsException('Agent was not observed');$this->appState->mutate('Agent',['agentId'=>$id],self::pick($current,['name','deviceId'])+['isDeleted'=>true]);}
    /**
     * @return array<string,mixed>|null */
    public function getAssignment(string $chat):?array{$row=$this->projector->getAction('ChatAssignment',['chatJid'=>self::chat($chat)]);return $row===null?null:self::record('ChatAssignment',$row);}
    /**
     * @return array<string,mixed> */
    public function setAssignment(string $chat,string $agent):array
    {
        $chat=self::chat($chat);$agent=self::id($agent);if($this->getAgent($agent)===null)throw new \OutOfBoundsException('Agent was not observed');
        $this->appState->mutate('ChatAssignment',['chatJid'=>$chat],['deviceAgentId'=>$agent]);return $this->getAssignment($chat)??throw new \UnexpectedValueException('Assignment patch was not projected');
    }
    public function clearAssignment(string $chat):void{$chat=self::chat($chat);if($this->getAssignment($chat)===null)throw new \OutOfBoundsException('Assignment was not observed');$this->appState->mutate('ChatAssignment',['chatJid'=>$chat],null,true);}
    /**
     * @return list<array<string,mixed>> */
    public function listAssignments(int $limit=100,string $after=''):array{return $this->records('ChatAssignment',$limit,$after);}
    /**
     * @return array<string,mixed>|null */
    public function getOpened(string $chat,string $agent):?array{$row=$this->projector->getAction('ChatAssignmentOpenedStatus',['chatJid'=>self::chat($chat),'agentId'=>self::id($agent)]);return $row===null?null:self::record('ChatAssignmentOpenedStatus',$row);}
    /**
     * @return array<string,mixed> */
    public function setOpened(string $chat,string $agent,bool $opened):array
    {
        $chat=self::chat($chat);$agent=self::id($agent);if($this->getAgent($agent)===null)throw new \OutOfBoundsException('Agent was not observed');$this->appState->mutate('ChatAssignmentOpenedStatus',['chatJid'=>$chat,'agentId'=>$agent],['chatOpened'=>$opened]);return $this->getOpened($chat,$agent)??throw new \UnexpectedValueException('Opened patch was not projected');
    }
    /**
     * @return list<array<string,mixed>> */
    public function listNotes(?string $chat=null,int $limit=100,string $after=''):array
    {
        $chat=$chat===null?null:self::chat($chat);return $this->records('NoteEdit',$limit,$after,static fn(array $r):bool=>($r['deleted']??false)!==true&&($chat===null||($r['chatJid']??null)===$chat));
    }
    /**
     * @return array<string,mixed>|null */
    public function getNote(string $id):?array{$row=$this->projector->getAction('NoteEdit',['id'=>self::id($id)]);return $row===null||($row['deleted']??false)===true?null:self::record('NoteEdit',$row);}
    /** Create/update an unstructured note, preserving the original creation timestamp and chat on a text-only update.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function saveNote(string $id,array $input):array
    {
        $id=self::id($id);$input=self::noteInput($input);$current=$this->getNote($id);
        if($current!==null&&!in_array($current['type']??null,[1,'UNSTRUCTURED'],true))throw new \LogicException('Structured notes cannot be overwritten as plain text');
        $data=['type'=>'UNSTRUCTURED','chatJid'=>$input['chatJid']??$current['chatJid']??null,'createdAt'=>$current['createdAt']??(int)floor(microtime(true)*1000),'deleted'=>false,'unstructuredContent'=>$input['text']??$current['unstructuredContent']??null];
        if(!is_string($data['chatJid'])||!is_string($data['unstructuredContent']))throw new \InvalidArgumentException('New notes require chatJid and text');
        $this->appState->mutate('NoteEdit',['id'=>$id],$data);return $this->getNote($id)??throw new \UnexpectedValueException('Note patch was not projected');
    }
    public function deleteNote(string $id):void{$current=$this->getNote($id)??throw new \OutOfBoundsException('Note was not observed');$this->appState->mutate('NoteEdit',['id'=>$id],self::pick($current,['type','chatJid','createdAt','unstructuredContent'])+['deleted'=>true]);}
    /**
     * @return list<string> */
    public function listFavorites():array
    {
        $row=$this->projector->getAction('Favorites')??[];$favorites=$row['favorites']??[];
        if(!is_array($favorites)||!array_is_list($favorites))throw new \UnexpectedValueException('Malformed authenticated favorites');$ids=[];
        foreach($favorites as$favorite){if(!is_array($favorite)||!is_string($favorite['id']??null))throw new \UnexpectedValueException('Malformed authenticated favorite');$ids[]=$favorite['id'];}return self::favoriteIds($ids);
    }
    /** Add/remove preserve siblings and existing order; replacing the whole list is deliberately not exposed.
     *
     * @param list<string> $ids
     * @return list<string> */
    public function addFavorites(array $ids):array{$ids=self::favoriteIds($ids);$this->refreshFavorites();return $this->writeFavorites(array_values(array_unique([...$this->listFavorites(),...$ids])));}
    /**
     * @param list<string> $ids
     * @return list<string> */
    public function removeFavorites(array $ids):array{$ids=self::favoriteIds($ids);$this->refreshFavorites();return $this->writeFavorites(array_values(array_diff($this->listFavorites(),$ids)));}
    private function refreshFavorites():void
    {
        $result=$this->appState->sync(['regular_high']);
        if(($result['collections'][0]['state']??null)!=='success')throw new \RuntimeException('Favorites synchronization was not confirmed; merge was not queued');
    }
    /**
     * @param list<string> $ids
     * @return list<string> */
    private function writeFavorites(array $ids):array{$ids=self::favoriteIds($ids);$this->appState->mutate('Favorites',[],['favorites'=>array_map(static fn(string $id):array=>['id'=>$id],$ids)]);return $this->listFavorites();}
    /**
     * @return list<array<string,mixed>> */
    public function listCampaigns(int $limit=100,string $after=''):array{return $this->records('BusinessBroadcastCampaign',$limit,$after);}
    /**
     * @return array<string,mixed>|null */
    public function getCampaign(string $id):?array{$row=$this->projector->getAction('BusinessBroadcastCampaign',['campaign'=>self::id($id)]);return $row===null?null:self::record('BusinessBroadcastCampaign',$row);}
    /**
     * @return list<array<string,mixed>> */
    public function listCampaignInsights(int $limit=100,string $after=''):array{return $this->records('BusinessBroadcastInsights',$limit,$after);}
    /**
     * @return array<string,mixed>|null */
    public function getCampaignInsights(string $id):?array{$row=$this->projector->getAction('BusinessBroadcastInsights',['campaignId'=>self::id($id)]);return $row===null?null:self::record('BusinessBroadcastInsights',$row);}
    /**
     * @return list<array<string,mixed>> */
    public function listMarketingMessages(int $limit=100,string $after=''):array{return $this->records('MarketingMessage',$limit,$after);}
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    public static function contactInput(array $input):array
    {
        self::fields($input,['fullName','firstName','saveOnPrimaryAddressbook','birthday']);if($input===[])throw new \InvalidArgumentException('Contact update must not be empty');foreach(['fullName','firstName']as$field)if(array_key_exists($field,$input))self::text($input[$field],512,true);
        if(array_key_exists('saveOnPrimaryAddressbook',$input)&&!is_bool($input['saveOnPrimaryAddressbook']))throw new \InvalidArgumentException('saveOnPrimaryAddressbook must be boolean');if(array_key_exists('birthday',$input)){if(!is_string($input['birthday']))throw new \InvalidArgumentException('birthday must be a date');StateFeatureInputs::contactBirthday($input['birthday']);}return $input;
    }
    /** Does not accept user-supplied PN/LID linkage or username assertions.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function saveContact(string $jid,array $input):array
    {
        $jid=self::chat($jid,true);$input=self::contactInput($input);$schema=str_ends_with($jid,'@lid')?'LidContact':'Contact';$current=$this->projector->getAction($schema,['id'=>$jid])??[];
        $allowed=$schema==='Contact'?['fullName','firstName','lidJid','saveOnPrimaryAddressbook','pnJid','username','birthday']:['fullName','firstName','username','birthday'];
        if($schema==='LidContact'&&array_key_exists('saveOnPrimaryAddressbook',$input))throw new \InvalidArgumentException('Saving on the primary address book requires a PN contact');
        $data=array_replace(self::pick($current,$allowed),$input);if(!isset($data['fullName'])&&$current===[])throw new \InvalidArgumentException('A new contact requires fullName');
        $this->appState->mutate($schema,['id'=>$jid],$data);return self::pick($this->projector->getAction($schema,['id'=>$jid])??[],[...$allowed,'id','source','timestamp']);
    }
    /**
     * @return array<string,mixed>|null */
    public function chatState(string $chat):?array{return $this->history->getChat(self::chat($chat));}
    /**
     * @param array<string,mixed> $data
     * @param list<string> $allowed */
    private static function fields(array $data,array $allowed):void{if(array_diff(array_keys($data),$allowed)!==[])throw new \InvalidArgumentException('Unknown account tool field');}
    private static function text(mixed $value,int $max,bool $empty):void{if(!is_string($value)||strlen($value)>$max||(!$empty&&trim($value)==='')||preg_match('//u',$value)!==1||preg_match('/[\x00-\x08\x0b\x0c\x0e-\x1f\x7f]/',$value))throw new \InvalidArgumentException('Invalid account text');}
    /**
     * @param array<string,mixed> $row
     * @param list<string> $fields
     * @return array<string,mixed> */
    private static function pick(array $row,array $fields):array{return array_intersect_key($row,array_flip($fields));}
    /**
     * @param (callable(array<string,mixed>):bool)|null $filter
     * @return list<array<string,mixed>> */
    private function records(string $schema,int $limit,string $after,?callable $filter=null):array
    {
        StateFeatureInputs::page($limit,$after);$result=[];$cursor=$after;
        do{$page=$this->projector->listActions($schema,min(1000,max(100,$limit)),$cursor);foreach($page as$row){$cursor=$row['cursor'];if($filter!==null&&!$filter($row))continue;$result[]=self::record($schema,$row);if(count($result)===$limit)return $result;}}while(count($page)===min(1000,max(100,$limit)));return $result;
    }
    /**
     * @param array<string,mixed> $row
     * @return array<string,mixed> */
    private static function record(string $schema,array $row):array
    {
        $fields=match($schema){'Agent'=>['agentId','name','deviceId','isDeleted'],'ChatAssignment'=>['chatJid','deviceAgentId'],'ChatAssignmentOpenedStatus'=>['chatJid','agentId','chatOpened'],'NoteEdit'=>['id','type','chatJid','createdAt','deleted','unstructuredContent'],'BusinessBroadcastCampaign'=>['campaign','deviceId','adId','name','msgId','broadcastJid','reservedQuota','scheduledTimestamp','createTimestamp','status','bbProStatus','customAudienceFbid'],'BusinessBroadcastInsights'=>['campaignId','recipientCount','deliveredCount','readCount','repliedCount','quickReplyCount'],'MarketingMessage'=>['id','name','message','type','createdAt','lastSentAt','isDeleted','mediaId'],default=>throw new \LogicException('Unsupported account projection')};
        return self::pick($row,[...$fields,'cursor','source','collection','version','timestamp']);
    }
}
