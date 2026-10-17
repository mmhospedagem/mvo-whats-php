<?php
declare(strict_types=1);
namespace MVO\History;

use MVO\Message\ContentDecoder;
use MVO\Protocol\{BinaryData,Jid};
use MVO\Signal\SignalStore;

/** Session-local status projection. It shares the authenticated inbox/outbox database. */
final class StatusStore
{
    public const CHAT='status@broadcast';
    public const LIFETIME=86400;

    /**
     * @param \Closure():int|null $clock */
    public function __construct(private readonly SignalStore $store,private readonly HistoryStore $history,private readonly ?\Closure $clock=null) {}
    /** Responses are indexed by their original key, even when delivered in a direct chat.
     *
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>> */
    public function listInteractions(array $filters=[]):array{return (new ObservedProtocolStore($this->store))->list('status-interactions',$filters);}

    /** Only synchronized/published statuses are available; this does not query a remote status directory.
     *
     * @param array<string,mixed> $filters
     *
     * @return list<array<string,mixed>> */
    public function listStatuses(array $filters=[]):array
    {
        foreach(array_keys($filters) as $field)if(!in_array($field,['scope','author','limit','beforeTimestamp','includeExpired','includeRevoked'],true))throw new \InvalidArgumentException('Unknown status filter: '.$field);
        $scope=$filters['scope']??'all';if(!in_array($scope,['all','own','received'],true))throw new \InvalidArgumentException('Invalid status scope');
        $limit=$filters['limit']??100;self::limit($limit);
        $before=$filters['beforeTimestamp']??null;if($before!==null && (!is_int($before)||$before<0))throw new \InvalidArgumentException('Invalid status timestamp');
        foreach(['includeExpired','includeRevoked'] as $flag)if(isset($filters[$flag])&&!is_bool($filters[$flag]))throw new \InvalidArgumentException('Invalid status flag');
        $author=isset($filters['author'])?self::user($filters['author']):null;$result=[];
        foreach($this->publicationRecords(self::CHAT) as $message) {
            $record=$this->project($message);if($record===null)continue;
            if($scope==='own'&&!$record['fromMe'] || $scope==='received'&&$record['fromMe'])continue;
            if($author!==null&&!$this->sameUser($record['author'],$author))continue;
            if($before!==null&&$record['timestamp']>=$before)continue;
            if(!($filters['includeExpired']??false)&&$record['isExpired'])continue;
            if(!($filters['includeRevoked']??false)&&$record['revoked'])continue;
            $key=self::projectionKey($record);
            if(!isset($result[$key])||self::prefers($record,$result[$key]))$result[$key]=$record;
            if(count($result)>=$limit*2){uasort($result,self::newest(...));$result=array_slice($result,0,$limit,true);}
        }
        uasort($result,self::newest(...));return array_values(array_slice($result,0,$limit,true));
    }

    /** Only observed wrappers in the authenticated local history; never a remote directory.
     * Group status retains its real group key and does not inherit broadcast lifetime/view state.
     *
     * @param array<string,mixed> $filters
     *
     * @return list<array<string,mixed>> */
    public function listInteractiveStatuses(array $filters=[]):array
    {
        foreach($filters as$field=>$_)if(!in_array($field,['type','groupJid','author','limit','beforeTimestamp','includeExpired','includeRevoked'],true))throw new \InvalidArgumentException('Unknown interactive status filter: '.$field);
        $type=$filters['type']??'all';if(!in_array($type,['all','poll','add-yours','group-status'],true))throw new \InvalidArgumentException('Invalid interactive status type');
        $group=$filters['groupJid']??null;
        if($type==='group-status'&&$group===null)throw new \InvalidArgumentException('Group status queries require an explicit groupJid');
        if($group!==null&&(!is_string($group)||preg_match('/^[0-9]+(?:-[0-9]+)?@g\.us$/D',$group)!==1||!in_array($type,['all','group-status'],true)))throw new \InvalidArgumentException('Invalid group status conversation');
        $base=$filters;unset($base['type'],$base['groupJid']);$limit=$base['limit']??100;self::limit($limit);
        if(isset($base['author']))$base['author']=self::user($base['author']);
        $before=$base['beforeTimestamp']??null;if($before!==null&&(!is_int($before)||$before<0))throw new \InvalidArgumentException('Invalid status timestamp');
        foreach(['includeExpired','includeRevoked']as$flag)if(isset($base[$flag])&&!is_bool($base[$flag]))throw new \InvalidArgumentException('Invalid status flag');
        $result=[];
        if($group===null){
            // Select before applying the limit, so unrelated ordinary statuses cannot hide older interactions.
            foreach($this->publicationRecords(self::CHAT)as$message){
                $record=$this->project($message);if($record===null||!isset($record['statusInteraction']))continue;
                if(!in_array($record['statusInteraction']['type']??null,['poll','add-yours'],true)||($record['statusInteraction']['action']??null)!=='publication')continue;
                if($type!=='all'&&($record['statusInteraction']['type']??null)!==$type)continue;
                if(!$this->matchesInteractiveFilters($record,$base))continue;
                $key=self::projectionKey($record);if(!isset($result[$key])||self::prefers($record,$result[$key]))$result[$key]=$record;
            }
        }else{
            foreach($this->publicationRecords($group)as$message){
                $id=$message['id']??null;$author=$this->author($message);if(!is_string($id)||$id===''||$author===null)continue;
                $unwrapped=ContentDecoder::unwrap(is_array($message['proto']??null)?$message['proto']:[]);
                if(isset($unwrapped['message']['protocolMessage']))continue;
                $decoded=\MVO\Message\AdvancedEvents::normalize($unwrapped['message'],'',[],$unwrapped['wrappers']);
                $interaction=$decoded['statusInteraction']??null;if(!is_array($interaction)||($interaction['type']??null)!=='group-status'||($interaction['action']??null)!=='publication')continue;
                $own=($message['fromMe']??$message['key']['fromMe']??false)===true;
                $message=array_diff_key($message,array_flip(['expiresAt','isExpired','viewed','viewedAt','muted','audience']));
                $record=[...$message,'id'=>$id,'chat'=>$group,'author'=>$author,'fromMe'=>$own,'timestamp'=>self::timestamp($message['timestamp']??null)??0,'key'=>['id'=>$id,'remoteJid'=>$group,'fromMe'=>$own,'participant'=>$author],'revoked'=>($message['revoked']??false)===true,'statusInteraction'=>$interaction,'publicationSupported'=>true,'source'=>'session_history'];
                if(!$this->matchesInteractiveFilters($record,$base))continue;$result[self::projectionKey($record)]=$this->redactRevoked($record);
            }
        }
        uasort($result,self::newest(...));return array_values(array_slice($result,0,$limit));
    }

    /** Exact group-local lookup; ordinary group messages and protocol revocations are not publications.
     *
     * @return array<string,mixed>|null */
    public function getGroupStatus(string $group,string $id,?string $author=null):?array
    {
        $group=\MVO\Message\GroupStatusContent::normalizeGroups($group)[0];self::id($id);$author=$author===null?null:self::user($author);
        $matches=[];
        foreach($this->publicationRecords($group)as$candidate){
            if(($candidate['id']??null)!==$id)continue;$candidateAuthor=$this->author($candidate);
            if($candidateAuthor===null||($author!==null&&!$this->sameUser($candidateAuthor,$author)))continue;
            $body=ContentDecoder::unwrap(is_array($candidate['proto']??null)?$candidate['proto']:[]);
            if(isset($body['message']['protocolMessage'])||array_intersect($body['wrappers'],['groupStatusMessage','groupStatusMessageV2'])===[])continue;
            $key=(($candidate['fromMe']??false)?'own':'received')."\0".$candidateAuthor;
            $existing=$matches[$key]??null;
            if($existing===null||($candidate['revoked']??false)===true||isset($candidate['participant']))$matches[$key]=$candidate;
        }
        if($author===null){$own=array_values(array_filter($matches,static fn(array $row):bool=>($row['fromMe']??false)===true));if(count($own)===1)$matches=$own;}
        if(count($matches)>1)throw new \InvalidArgumentException('Group Status ID is ambiguous; provide its author');
        $message=array_values($matches)[0]??null;
        if($message===null)return null;
        $actual=$this->author($message);if($actual===null)return null;
        $unwrapped=ContentDecoder::unwrap(is_array($message['proto']??null)?$message['proto']:[]);
        if(isset($unwrapped['message']['protocolMessage']))return null;
        $decoded=\MVO\Message\AdvancedEvents::normalize($unwrapped['message'],'',[],$unwrapped['wrappers']);$interaction=$decoded['statusInteraction']??null;
        if(!is_array($interaction)||($interaction['type']??null)!=='group-status'||($interaction['action']??null)!=='publication')return null;
        $own=($message['fromMe']??$message['key']['fromMe']??false)===true;
        $message=array_diff_key($message,array_flip(['expiresAt','isExpired','viewed','viewedAt','muted','audience']));
        return $this->redactRevoked([...$message,'id'=>$id,'chat'=>$group,'author'=>$actual,'fromMe'=>$own,'timestamp'=>self::timestamp($message['timestamp']??null)??0,'key'=>['id'=>$id,'remoteJid'=>$group,'fromMe'=>$own,'participant'=>$actual],'revoked'=>($message['revoked']??false)===true,'statusInteraction'=>$interaction,'publicationSupported'=>true,'source'=>'session_history']);
    }
    /** Called only after the authenticated server ACK of our group-wrapper revocation. */
    public function markGroupStatusRevoked(string $group,string $id):void
    {
        $group=\MVO\Message\GroupStatusContent::normalizeGroups($group)[0];self::id($id);
        foreach($this->history->iterateStoredMessages($group)as$message)if(($message['id']??null)===$id&&($message['fromMe']??false)===true)$this->history->upsertMessage([...$message,'revoked'=>true]);
    }

    /**
     * @param array<string,mixed> $record
     *
     * @param array<string,mixed> $filters */
    private function matchesInteractiveFilters(array $record,array $filters):bool
    {
        $before=$filters['beforeTimestamp']??null;if($before!==null&&(!is_int($before)||$before<0))throw new \InvalidArgumentException('Invalid status timestamp');
        foreach(['includeExpired','includeRevoked']as$flag)if(isset($filters[$flag])&&!is_bool($filters[$flag]))throw new \InvalidArgumentException('Invalid status flag');
        $author=isset($filters['author'])?self::user($filters['author']):null;
        return ($author===null||$this->sameUser($record['author'],$author))&&($before===null||$record['timestamp']<$before)&&(($filters['includeExpired']??false)||!($record['isExpired']??false))&&(($filters['includeRevoked']??false)||!($record['revoked']??false));
    }

    /** Omitting author prefers a local publication; ambiguous received IDs require the author.
     *
     * @return array<string,mixed>|null */
    public function getStatus(string $id,?string $author=null,bool $includeExpired=false,bool $includeRevoked=false):?array
    {
        self::id($id);$author=$author===null?null:self::user($author);$matches=[];$audience=$this->getAudience($id);
        foreach($this->publicationRecords(self::CHAT) as $message) {
            if(($message['id']??null)!==$id)continue;$record=$this->project($message);if($record===null)continue;
            if($author!==null&&!$this->sameUser($record['author'],$author))continue;
            if(!$includeExpired&&$record['isExpired'] || !$includeRevoked&&$record['revoked'])continue;
            $key=self::projectionKey($record);if(!isset($matches[$key])||self::prefers($record,$matches[$key]))$matches[$key]=$record;
        }
        if($author!==null){
            $exact=array_values(array_filter($matches,static fn(array $row):bool=>$row['author']===$author));
            if(count($exact)===1)return $exact[0];
        }
        if($author===null) {
            $own=array_values(array_filter($matches,static fn(array $row):bool=>$row['fromMe']));
            foreach($own as $record)if(($audience['author']??null)===$record['author'])return $record;
            if(count($own)===1)return $own[0];
        }
        if(count($matches)>1)throw new \InvalidArgumentException('Status ID is ambiguous; provide its author');
        return array_values($matches)[0]??null;
    }

    /** The audience is a durable snapshot of the actual locally submitted fanout, not current privacy.
     *
     * @return array<string,mixed>|null */
    public function getAudience(string $id):?array {self::id($id);return $this->read('status-audiences',$id);}

    /** Called inside the same transaction that stores the ratchets and exact ciphertext.
     *
     * @param list<string> $recipients */
    public function recordAudience(string $id,string $author,array $recipients,string $setting,int $timestamp):void
    {
        self::id($id);$author=self::user($author);if(!in_array($setting,['contacts','allowlist','denylist','close_friends'],true))throw new \InvalidArgumentException('Invalid status setting');
        $users=[];foreach($recipients as $recipient)$users[]=self::user($recipient);$users=array_values(array_unique($users));sort($users,SORT_STRING);
        $this->store->set('status-audiences',$id,BinaryData::encode(['id'=>$id,'author'=>$author,'recipients'=>$users,'statusSetting'=>$setting,'timestamp'=>$timestamp,'source'=>'local_outbox']));
    }

    /** Read/play receipts are admitted only by MessageService's eligible-device authorization.
     * Delivery ACKs do not mean a status was viewed.
     *
     * @return list<array{jid:string,timestamp:int,status:string}> */
    public function listViewers(string $id):array
    {
        self::id($id);if($this->getAudience($id)===null)return [];
        $bytes=$this->store->get('message-outbox',$id);if($bytes===null)return [];
        $record=json_decode($bytes,true,32,JSON_THROW_ON_ERROR);$viewers=[];
        if(!is_array($record)||($record['metadata']['to']??null)!==self::CHAT)return [];
        foreach($record['metadata']['receipts']??[] as $device=>$receipt) {
            if(!is_array($receipt)||!in_array($receipt['status']??null,['read','played'],true))continue;
            try{$jid=self::user($device);$jid=self::user($this->store->get('message-pn-to-lid',$jid)??$jid);}catch(\InvalidArgumentException){continue;}
            $timestamp=self::timestamp($receipt['timestamp']??null);if($timestamp===null)continue;
            if(!isset($viewers[$jid])||$timestamp<$viewers[$jid]['timestamp'])$viewers[$jid]=['jid'=>$jid,'timestamp'=>$timestamp,'status'=>$receipt['status']];
        }
        $result=array_values($viewers);usort($result,static fn(array $a,array $b):int=>($a['timestamp']<=>$b['timestamp'])?:strcmp($a['jid'],$b['jid']));return $result;
    }

    /** Authenticated app-state projection; null means it has not been synchronized.
     *
     * @return array<string,mixed>|null */
    public function getPrivacy():?array {return $this->read('appstate-settings','StatusPrivacy');}

    /**
     * @return list<array<string,mixed>> */
    public function listMuted(int $limit=100,string $after=''):array
    {
        self::limit($limit);if($after!=='')$after=self::user($after);$result=[];
        foreach($this->history->iterateContacts() as $contact) {
            if(($contact['statusMuted']??null)!==true||strcmp($contact['jid'],$after)<=0)continue;
            $result[]=$contact;if(count($result)>=$limit)break;
        }
        return $result;
    }

    /** Null distinguishes an unknown synchronized setting from explicitly unmuted. */
    public function isUserMuted(string $jid):?bool {foreach($this->aliases(self::user($jid)) as $alias){$value=$this->history->getContact($alias)['statusMuted']??null;if(is_bool($value))return $value;}return null;}

    /** After enqueueing a durable read receipt, record the local view state. */
    public function markViewed(string $id,string $author,?int $timestamp=null):void
    {
        self::id($id);$author=self::user($author);$timestamp??=$this->now();if($timestamp<0)throw new \InvalidArgumentException('Invalid view timestamp');
        $key=self::key($id,$author);$old=$this->read('status-views',$key);
        $this->store->set('status-views',$key,BinaryData::encode(['id'=>$id,'author'=>$author,'viewedAt'=>$old['viewedAt']??$timestamp]));
    }

    /** Server revocation is distinct from local history deletion. Call after authenticated REVOKE or ACK. */
    public function markRevoked(string $id,string $author,?int $timestamp=null):void
    {
        self::id($id);$author=self::user($author);$timestamp??=$this->now();if($timestamp<0)throw new \InvalidArgumentException('Invalid revoke timestamp');
        $this->store->set('status-revoked',self::key($id,$author),BinaryData::encode(['id'=>$id,'author'=>$author,'revokedAt'=>$timestamp]));
    }

    /** Project a plaintext already authenticated by Signal or authenticated history sync.
     * A sender can only revoke that sender's status, even if its target key names another author.
     *
     * @param array<string,mixed> $event
     *
     * @return array<string,mixed>|null */
    public function recordEvent(array $event):?array
    {
        if(($event['chat']??null)!==self::CHAT)return null;
        $author=$this->author($event);if($author===null)return null;
        $inner=ContentDecoder::unwrap(is_array($event['proto']??null)?$event['proto']:[])['message'];$protocol=$inner['protocolMessage']??null;
        if(is_array($protocol)&&in_array($protocol['type']??null,[0,'REVOKE'],true)) {
            $target=$protocol['key']??null;if(!is_array($target)||!is_string($target['id']??null)||$target['id']==='')return null;
            if(isset($target['remoteJid'])&&$target['remoteJid']!==self::CHAT)return null;
            if(isset($target['participant'])) {try{if(!$this->sameUser(self::user($target['participant']),$author))return null;}catch(\InvalidArgumentException){return null;}}
            $timestamp=self::timestamp($event['timestamp']??null)??$this->now();$this->markRevoked($target['id'],$author,$timestamp);
            return ['action'=>'revoked','id'=>$target['id'],'author'=>$author,'chat'=>self::CHAT,'timestamp'=>$timestamp,'source'=>'session_history'];
        }
        $record=$this->project($event);return $record===null?null:[...$record,'action'=>$record['fromMe']?'published':'received'];
    }

    /**
     * @param array<string,mixed> $message
     *
     * @return array<string,mixed>|null */
    private function project(array $message):?array
    {
        $id=$message['id']??null;if(!is_string($id)||$id==='')return null;$author=$this->author($message);if($author===null)return null;
        $inner=ContentDecoder::unwrap(is_array($message['proto']??null)?$message['proto']:[])['message'];
        if(isset($inner['protocolMessage'])||isset($inner['reactionMessage'])||isset($inner['senderKeyDistributionMessage'])&&count($inner)===1||in_array($message['type']??null,['protocol','reaction','internal'],true))return null;
        $timestamp=self::timestamp($message['timestamp']??null)??0;$own=($message['fromMe']??$message['key']['fromMe']??false)===true;
        // A phone sync can echo our publication using PN rather than its original LID.
        // Bind only authenticated own aliases to the durable publication identity and lifetime.
        if($own) {
            $audience=$this->getAudience($id);
            if(is_string($audience['author']??null)&&$this->sameUser($author,$audience['author'])){$author=$audience['author'];$timestamp=self::timestamp($audience['timestamp']??null)??$timestamp;}
            elseif(($lid=$this->store->get('message-pn-to-lid',$author))!==null)$author=self::user($lid);
        }
        $expires=$timestamp+self::LIFETIME;
        $revoke=null;$view=null;foreach($this->aliases($author) as $alias){$revoke??=$this->read('status-revoked',self::key($id,$alias));$view??=$this->read('status-views',self::key($id,$alias));}
        $modern=\MVO\Message\ModernEvents::normalize(is_array($message['proto']??null)?$message['proto']:[]);
        $modern=array_intersect_key($modern,['statusContent'=>true,'association'=>true]);
        $unwrapped=ContentDecoder::unwrap(is_array($message['proto']??null)?$message['proto']:[]);
        $decoded=\MVO\Message\AdvancedEvents::normalize($unwrapped['message'],'',[],$unwrapped['wrappers']);
        if(is_array($decoded['statusInteraction']??null))$modern+=['statusInteraction'=>$decoded['statusInteraction'],'publicationSupported'=>false];
        return $this->redactRevoked([...$message,...$modern,'id'=>$id,'chat'=>self::CHAT,'author'=>$author,'fromMe'=>$own,'timestamp'=>$timestamp,
            'key'=>['id'=>$id,'remoteJid'=>self::CHAT,'fromMe'=>$own,'participant'=>$author],
            'expiresAt'=>$expires,'isExpired'=>$expires<=$this->now(),'revoked'=>($message['revoked']??false)===true||$revoke!==null,'revokedAt'=>$revoke['revokedAt']??null,
            'viewed'=>$view!==null,'viewedAt'=>$view['viewedAt']??null,'muted'=>$own?false:$this->isUserMuted($author),'source'=>'session_history']);
    }

    /** Classify from the immutable native wrapper, merge current state, then redact before returning.
     * @return \Generator<int,array<string,mixed>> */
    private function publicationRecords(string $chat):\Generator
    {
        foreach($this->history->iterateStoredMessages($chat)as$message){$current=$this->history->messageObservations()->project($message);if(($current['revoked']??false)===true)$message['revoked']=true;yield $message;}
    }
    /** Revoked publication metadata does not disclose the original body or private descriptors.
     * @param array<string,mixed> $record
     * @return array<string,mixed> */
    private function redactRevoked(array $record):array
    {
        if(($record['revoked']??false)!==true)return $record;
        return array_intersect_key($record,array_flip(['id','chat','author','fromMe','timestamp','key','revoked','revokedAt','revokedAtMs','expiresAt','isExpired','viewed','viewedAt','muted','audience','statusInteraction','publicationSupported','source','status']))+['type'=>'revoked','text'=>null,'proto'=>[],'data'=>[]];
    }

    /**
     * @param array<string,mixed> $message */
    private function author(array $message):?string
    {
        $raw=$message['participant']??$message['key']['participant']??$message['from']??null;
        if(!is_string($raw))return null;try{return self::user($raw);}catch(\InvalidArgumentException){return null;}
    }
    /**
     * @return array<string,mixed>|null */
    private function read(string $namespace,string $key):?array {$bytes=$this->store->get($namespace,$key);if($bytes===null)return null;$value=BinaryData::decode($bytes);if(!is_array($value))throw new \UnexpectedValueException('Invalid status record');return $value;}
    private function now():int {return $this->clock===null?time():($this->clock)();}
    /**
     * @return list<string> */
    private function aliases(string $jid):array {$result=[$jid];foreach(['message-pn-to-lid','message-lid-to-pn'] as $namespace)if(($alias=$this->store->get($namespace,$jid))!==null)$result[]=self::user($alias);return array_values(array_unique($result));}
    private function sameUser(string $a,string $b):bool {return array_intersect($this->aliases($a),$this->aliases($b))!==[];}
    private static function user(mixed $raw):string {if(!is_string($raw)||$raw==='')throw new \InvalidArgumentException('Invalid status author');$jid=Jid::recipient($raw)->canonical();if(!in_array($jid->server,['s.whatsapp.net','lid'],true)||!ctype_digit($jid->user))throw new \InvalidArgumentException('Status authors must be user accounts');return $jid->bare();}
    private static function id(string $id):void {if($id===''||strlen($id)>256||preg_match('/[\x00-\x20]/',$id))throw new \InvalidArgumentException('Invalid status ID');}
    private static function key(string $id,string $author):string{return hash('sha256',$author."\0".$id);}
    /**
     * @param array<string,mixed> $record */
    private static function projectionKey(array $record):string{return ($record['fromMe']?'own':'received')."\0".$record['author']."\0".$record['id'];}
    /** Prefer the locally persisted publication over an echoed history-sync row.
     *
     * @param array<string,mixed> $candidate
     *
     * @param array<string,mixed> $existing */
    private static function prefers(array $candidate,array $existing):bool
    {
        if($candidate['fromMe']&&($candidate['history']??false)!==($existing['history']??false))return ($candidate['history']??false)!==true;
        return ($candidate['timestamp']??0)>($existing['timestamp']??0);
    }
    private static function timestamp(mixed $value):?int {if(is_string($value)&&ctype_digit($value)&&strlen($value)<=10)$value=(int)$value;return is_int($value)&&$value>=0?$value:null;}
    private static function limit(mixed $limit):void {if(!is_int($limit)||$limit<1||$limit>1000)throw new \InvalidArgumentException('Page limit must be 1..1000');}
    /**
     * @param array<string,mixed> $a
     *
     * @param array<string,mixed> $b */
    private static function newest(array $a,array $b):int {return ($b['timestamp']<=>$a['timestamp'])?:strcmp($a['author']."\0".$a['id'],$b['author']."\0".$b['id']);}
}
