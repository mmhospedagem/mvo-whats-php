<?php
declare(strict_types=1);
namespace MVO\History;

use MVO\Protocol\BinaryData;
use MVO\Signal\SignalStore;

/** Session-scoped channel snapshots and an index over the existing durable message history. */
final class ChannelStore
{
    public function __construct(private readonly SignalStore $store,private readonly HistoryStore $history) {}

    public static function jid(string $jid):string
    {
        if(!preg_match('/^[0-9]+@newsletter$/D',$jid))throw new \InvalidArgumentException('Expected a channel JID');
        return $jid;
    }

    /** Raw fields are retained; missing server fields are never filled with guessed defaults.
     * @param array<string,mixed> $data */
    public function recordUpdate(string $jid,string $action,array $data,?int $timestamp=null):void
    {
        $jid=self::jid($jid);$record=$this->getChannel($jid)??['jid'=>$jid,'metadata'=>[],'updateTimes'=>[]];
        $times=$record['updateTimes'];$metadata=$record['metadata'];
        $fresh=static fn(string $domain):bool=>$timestamp===null||!isset($times[$domain])||$timestamp>=$times[$domain];
        $mark=static function(string $domain)use(&$times,$timestamp):void {if($timestamp!==null)$times[$domain]=max($times[$domain]??0,$timestamp);};
        if(in_array($action,['join','metadata','owner_metadata','admin_metadata'],true)){
            $metadata=self::mergeStamped($metadata,$action==='join'?$data:array_intersect_key($data,array_flip(['id','thread_metadata','admin_settings'])),$times,$timestamp);
            $mark('metadata');
        }
        if(in_array($action,['join','leave'],true)&&$fresh('following')){$record['following']=$action==='join';$mark('following');}
        if($action==='state'&&is_array($data['state']??null)&&$fresh('state')){$metadata['state']=$data['state'];$mark('state');}
        if($action==='user_setting'&&is_array($data['setting']??null)){
            $type=$data['setting']['type']??null;$value=$data['setting']['value']??null;
            if(is_string($type)&&is_string($value)&&$fresh('setting:'.$type)){$record['userSettings'][$type]=$value;$mark('setting:'.$type);}
        }
        if(in_array($action,['admin_promote','admin_demote'],true)){
            $user=$data['user']['id']??null;$role=$data['user_new_role']??null;
            if(is_string($user)&&is_string($role)&&$fresh('role:'.$user)){$record['observedAdminRoles'][$user]=$role;$mark('role:'.$user);}
        }
        $record['metadata']=$metadata;$record['updateTimes']=$times;
        $record['lastObservedAction']=$action;$record['lastObservedData']=$data;
        $record['observedAt']=time();
        $this->store->set('newsletter-channels',$jid,BinaryData::encode($record));
    }

    /** @return array<string,mixed>|null */
    public function getChannel(string $jid):?array
    {
        $bytes=$this->store->get('newsletter-channels',self::jid($jid));
        if($bytes===null)return null;$record=BinaryData::decode($bytes);
        if(!is_array($record)||($record['jid']??null)!==$jid||!is_array($record['metadata']??null)||!is_array($record['updateTimes']??null))throw new \UnexpectedValueException('Invalid persisted channel snapshot');
        return $record;
    }

    /** Local observations only, ordered by JID; $after is the previous page's last JID.
     * @return list<array<string,mixed>> */
    public function listChannels(int $limit=100,string $after=''):array
    {
        self::limit($limit);if($after!=='')self::jid($after);$rows=[];
        foreach($this->store->entries('newsletter-channels',$limit,$after)as$jid=>$bytes){$row=$this->getChannel((string)$jid);if($row!==null)$rows[]=$row;}
        return $rows;
    }

    /** @param array<string,mixed> $record */
    public function indexMessage(array $record):void
    {
        $jid=self::jid($record['chat']);$server=$record['serverId']??null;
        if(($record['isChannelQuestionResponse']??false)===true)return;
        $this->observeScheduling($record);
        if((is_int($server)&&$server>0)||(is_string($server)&&preg_match('/^[1-9][0-9]{0,17}$/D',$server)))
            $this->store->set((($record['isChannelStatus']??false)?'newsletter-status-message-index:':'newsletter-message-index:').$jid,(string)$server,$record['id']);
    }

    /** Observed native metadata only. Keys used to reveal encrypted content never leave storage.
     * @param array<string,mixed> $record */
    private function observeScheduling(array $record):void
    {
        $jid=self::jid($record['chat']);$id=$record['id'];
        $unwrapped=\MVO\Message\ContentDecoder::unwrap(is_array($record['proto']??null)?$record['proto']:[]);
        $wrapper=in_array('newsletterScheduledMessage',$unwrapped['wrappers'],true);
        $metadata=$record['webMessageInfo']['scheduledMessageMetadata']??null;
        $oldBytes=$this->store->get('newsletter-observed-schedules:'.$jid,$id);
        if(!$wrapper&&!is_array($metadata)&&$oldBytes===null)return;
        $old=$oldBytes===null?[]:BinaryData::decode($oldBytes);if(!is_array($old))throw new \UnexpectedValueException('Invalid observed schedule');
        $time=$metadata['scheduledTime']??$old['scheduledTime']??null;
        if(!is_int($time)&&!is_string($time))$time=null;
        $row=['id'=>$id,'channelJid'=>$jid,'engine'=>'whatsapp_observed','state'=>'observed',
            'newsletterWrapper'=>$wrapper||($old['newsletterWrapper']??false),
            'metadataPresent'=>is_array($metadata)||($old['metadataPresent']??false),
            'scheduledTime'=>$time,'revoked'=>($record['revoked']??false)===true,'observedAt'=>time()];
        $this->store->set('newsletter-observed-schedules:'.$jid,$id,BinaryData::encode($row));
    }

    /** These observations do not imply that native channel scheduling can be created by this library.
     * @return list<array<string,mixed>> */
    public function listObservedSchedules(string $jid,int $limit=50,string $after=''):array
    {
        self::jid($jid);self::limit($limit);if(strlen($after)>128||preg_match('/[\x00-\x20\x7f]/',$after))throw new \InvalidArgumentException('Invalid observed schedule cursor');
        $rows=[];foreach($this->store->entries('newsletter-observed-schedules:'.$jid,$limit,$after)as$id=>$bytes){
            $row=BinaryData::decode($bytes);if(!is_array($row)||($row['channelJid']??null)!==$jid||($row['id']??null)!==(string)$id)throw new \UnexpectedValueException('Invalid observed channel schedule');
            $rows[]=$row;
        }
        return $rows;
    }

    /** @return array<string,mixed>|null */
    public function findByServerId(string $jid,int $serverId,bool $isChannelStatus=false):?array
    {
        self::jid($jid);if($serverId<1)throw new \InvalidArgumentException('Channel server ID must be positive');
        $id=$this->store->get(($isChannelStatus?'newsletter-status-message-index:':'newsletter-message-index:').$jid,(string)$serverId);
        if($id!==null){$record=$this->history->getMessage($jid,$id);if($record!==null&&($record['isChannelStatus']??false)===$isChannelStatus&&($record['isChannelQuestionResponse']??false)!==true&&(string)($record['serverId']??'')===(string)$serverId)return $record;}
        // Existing installations can populate the index lazily without a destructive migration.
        foreach($this->history->iterateMessages($jid)as$record)if(($record['isChannelStatus']??false)===$isChannelStatus&&($record['isChannelQuestionResponse']??false)!==true&&(string)($record['serverId']??'')===(string)$serverId){$this->indexMessage($record);return $record;}
        return null;
    }

    /** @return array<string,mixed>|null */
    public function getMessage(string $jid,string $id,?int $serverId=null):?array
    {
        self::jid($jid);if($id===''||strlen($id)>128||preg_match('/[\x00-\x20\x7f]/',$id))throw new \InvalidArgumentException('Invalid channel message ID');
        $record=$this->history->getMessage($jid,$id);
        if(($record['isChannelStatus']??false)===true)$record=null;
        if($record===null)$record=$this->history->getMessage($jid,'response:'.$id);
        if($serverId!==null){if($serverId<1)throw new \InvalidArgumentException('Channel server ID must be positive');if($record!==null&&(string)($record['serverId']??'')!==(string)$serverId)throw new \InvalidArgumentException('Channel message ID and server ID refer to different posts');$record??=$this->findByServerId($jid,$serverId);}
        if($record===null&&preg_match('/^server:([1-9][0-9]{0,17})$/D',$id,$m))$record=$this->findByServerId($jid,(int)$m[1]);
        return $record;
    }

    /** Newest first; timestamp cursor is exclusive. Includes known revocation tombstones.
     * @return list<array<string,mixed>> */
    public function listMessages(string $jid,int $limit=50,?int $beforeTimestamp=null):array
    {
        self::jid($jid);self::limit($limit);if($beforeTimestamp!==null&&$beforeTimestamp<0)throw new \InvalidArgumentException('Invalid channel timestamp cursor');
        $records=[];
        foreach($this->history->iterateMessages($jid)as$record){
            if(($record['isChannelStatus']??false)===true||($beforeTimestamp!==null&&($record['timestamp']??0)>=$beforeTimestamp))continue;
            $records[]=$record;
            if(count($records)>=$limit*2){usort($records,self::newest(...));$records=array_slice($records,0,$limit);}
        }
        usort($records,self::newest(...));return array_slice($records,0,$limit);
    }

    /** @param array<string,mixed> $old
     * @param array<string,mixed> $patch
     * @param array<string,int> $times
     * @return array<string,mixed> */
    private static function mergeStamped(array $old,array $patch,array &$times,?int $timestamp,string $prefix='metadata'):array
    {
        foreach($patch as$key=>$value){
            $path=$prefix.'.'.$key;$latest=0;
            foreach($times as$domain=>$time)if(str_starts_with($domain,'field:')){$field=substr($domain,6);if($field===$path||str_starts_with($path,$field.'.'))$latest=max($latest,$time);}
            if($timestamp!==null&&$timestamp<$latest)continue;
            if(is_array($value)&&$value!==[]&&!array_is_list($value))$old[$key]=self::mergeStamped(is_array($old[$key]??null)&&!array_is_list($old[$key])?$old[$key]:[],$value,$times,$timestamp,$path);
            else {
                foreach($times as$domain=>$time)if(str_starts_with($domain,'field:'.$path.'.'))$latest=max($latest,$time);
                if($timestamp!==null&&$timestamp<$latest)continue;
                $old[$key]=$value;if($timestamp!==null)$times['field:'.$path]=$timestamp;
            }
        }
        return $old;
    }
    private static function limit(int $limit):void {if($limit<1||$limit>1000)throw new \InvalidArgumentException('Channel limit must be 1..1000');}
    /** @param array<string,mixed> $a
     * @param array<string,mixed> $b */
    private static function newest(array $a,array $b):int {return ($b['timestamp']??0)<=>($a['timestamp']??0)?:strcmp($a['id'],$b['id']);}
}
