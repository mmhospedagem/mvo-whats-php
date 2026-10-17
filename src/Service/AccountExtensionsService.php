<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\AppState\{AppStateProjector,AppStateService};
use MVO\History\HistoryStore;
use MVO\Message\{BotTransport,ContentDecoder};
use MVO\Protocol\{BinaryData,Jid,SchemaCodec};
use MVO\Signal\SignalStore;

/** Typed conveniences over verified, authenticated account records. Never enables a subscription. */
final class AccountExtensionsService
{
    private const PREFERENCES=[
        'disableLinkPreviews'=>['DisableLinkPreviews','isPreviewsDisabled'],
        'relayAllCalls'=>['VoipRelayAllCalls','isEnabled'],
        'locale'=>['LocaleSetting','locale'],
        'twentyFourHourFormat'=>['TimeFormat','isTwentyFourHourFormatEnabled'],
        'unarchiveChats'=>['UnarchiveChatsSetting','unarchiveChats'],
    ];
    public function __construct(private readonly AppStateService $appState,private readonly AppStateProjector $projector,private readonly HistoryStore $history){}

    /** Missing values mean unknown, not false.
     * @return array<string,mixed> */
    public function preferences():array
    {
        $items=[];foreach(self::PREFERENCES as$name=>[$schema,$field]){$row=$this->projector->getAction($schema);$items[]=['name'=>$name,'observed'=>$row!==null,'value'=>$row[$field]??null,'timestamp'=>$row['timestamp']??null,'source'=>$row['source']??null];}
        return ['items'=>$items,'globalSettings'=>$this->history->observedGlobalSettings(),'source'=>'authenticated_app_state'];
    }
    /** Ingestion hook for HistorySync.globalSettings after authenticated decoding. Secrets are never persisted here.
     * @param array<string,mixed> $settings */
    public static function observeGlobalSettings(SignalStore $store,array $settings):void
    {
        unset($settings['avatarUserSettings'],$settings['chatLockSettings']);$settings=self::safeProto('GlobalSettings',$settings);
        $store->set('account-observed-settings','global',BinaryData::encode(['source'=>'authenticated_history','observedAt'=>time(),'settings'=>$settings]));
    }
    /** @return array<string,mixed>|null */
    public static function readGlobalSettings(SignalStore $store):?array
    {
        $bytes=$store->get('account-observed-settings','global');if($bytes===null)return null;$row=BinaryData::decode($bytes);if(!is_array($row)||!is_array($row['settings']??null))return null;
        unset($row['settings']['avatarUserSettings'],$row['settings']['chatLockSettings']);return ['source'=>'authenticated_history','observedAt'=>$row['observedAt']??null,'settings'=>self::safeProto('GlobalSettings',$row['settings'])];
    }
    /** @param array<string,mixed> $value
     * @return array<string,mixed> */
    private static function safeProto(string $name,array $value,int $depth=0):array
    {
        if($depth>5)return [];$out=[];foreach(SchemaCodec::fields($name)as$field=>$spec){if(in_array($field,['avatarUserSettings','chatLockSettings','secretCode','password'],true)||!array_key_exists($field,$value))continue;$v=$value[$field];if(is_array($v)&&in_array($spec['type'],SchemaCodec::messageTypes(),true)&&!$spec['repeated'])$out[$field]=self::safeProto($spec['type'],$v,$depth+1);elseif(is_string($v)&&strlen($v)<=4096&&preg_match('//u',$v)===1)$out[$field]=$v;elseif(is_bool($v)||is_int($v))$out[$field]=$v;}return $out;
    }
    /** @return array<string,mixed> */
    public function setPreference(string $name,mixed $value):array
    {
        self::validatePreference($name,$value);[$schema,$field]=self::PREFERENCES[$name];$this->appState->mutate($schema,[],[$field=>$value]);
        $row=$this->projector->getAction($schema)??throw new \UnexpectedValueException('Preference was not acknowledged');
        return ['name'=>$name,'value'=>$row[$field]??null,'timestamp'=>$row['timestamp']??null,'source'=>'authenticated_app_state'];
    }
    public static function validatePreference(string $name,mixed $value):void
    {
        if(!isset(self::PREFERENCES[$name]))throw new \InvalidArgumentException('Unknown synchronized preference');
        if($name==='locale'){if(!is_string($value)||preg_match('/^[a-z]{2,3}(?:[_-][A-Za-z0-9]{2,8}){0,2}$/D',$value)!==1)throw new \InvalidArgumentException('Invalid locale');}
        elseif(!is_bool($value))throw new \InvalidArgumentException('Preference must be boolean');
    }
    /** @return list<array<string,mixed>> */
    public function platformPreferences(int $limit=100,string $after=''):array
    {
        $out=[];foreach($this->projector->listActions('SettingsSync',$limit,$after)as$row){unset($row['_raw']);$out[]=$row;}return $out;
    }
    /** Verified SettingsSync has an explicit platform and chat index; it never falls back to an invented account index.
     * @return array<string,mixed> */
    public function setPlatformPreference(string $platform,string $setting,string $chat,mixed $value):array
    {
        [$field,$value]=self::platformInput($platform,$setting,$chat,$value);$chat=AccountToolsService::chat($chat);
        $args=['settingPlatform'=>$platform,'settingKey'=>$setting,'chatJid'=>$chat];$this->appState->mutate('SettingsSync',$args,[$field=>$value]);
        $row=$this->projector->getAction('SettingsSync',$args)??throw new \UnexpectedValueException('Platform preference was not acknowledged');unset($row['_raw']);return $row;
    }
    /** @return array{string,mixed} */
    public static function platformInput(string $platform,string $setting,string $chat,mixed $value):array
    {
        $spec=\MVO\AppState\AppStateSchema::all()['SettingsSync'];$platforms=$spec['indexParts'][1]['enumValues'];$settings=$spec['indexParts'][2]['enumValues'];
        if(!isset($platforms[$platform])||$platform==='PLATFORM_UNKNOWN'||!isset($settings[$setting])||$setting==='SETTING_KEY_UNKNOWN')throw new \InvalidArgumentException('Unknown platform or synchronized setting');
        AccountToolsService::chat($chat);$number=$settings[$setting];$field=null;$type=null;
        foreach(SchemaCodec::fields('SyncActionValue.SettingsSyncAction')as$name=>$definition)if($definition['id']===$number){$field=$name;$type=$definition['type'];break;}
        if($field===null)throw new \LogicException('Setting does not have a verified value field');
        if($type==='bool'&&!is_bool($value)||$type==='int32'&&(!is_int($value)||$value<0||$value>2147483647))throw new \InvalidArgumentException('Invalid synchronized setting value');
        if($type==='string')self::text($value,512,false);
        SchemaCodec::encode('SyncActionValue.SettingsSyncAction',[$field=>$value]);return [$field,$value];
    }
    /** @return list<array<string,mixed>> */
    public function aiThreads(int $limit=100,string $after=''):array
    {
        StateFeatureInputs::page($limit,$after);$threads=[];
        foreach($this->history->iterateMessages()as$message){$proto=$message['proto']??null;if(!is_array($proto))continue;
            foreach(ContentDecoder::unwrap($proto)['context']['threadId']??[]as$thread){$key=$thread['threadKey']??null;if(!is_array($key)||!in_array($thread['threadType']??null,[2,'AI_THREAD'],true))continue;
                try{$chat=BotTransport::fbid($key['remoteJid']??'');$id=self::threadId($key['id']??'');}catch(\InvalidArgumentException){continue;}
                $cursor=hash('sha256',$chat."\0".$id);$threads[$cursor]=['cursor'=>$cursor,'chatJid'=>$chat,'threadId'=>$id,'source'=>'authenticated_message','observed'=>true];
            }
        }
        foreach(['AiThreadRename','AiThreadPin','AiThreadDelete']as$schema){$cursor='';do{$rows=$this->projector->listActions($schema,1000,$cursor);foreach($rows as$row){$cursor=$row['cursor'];try{$chat=BotTransport::fbid($row['chatJid']??'');$id=self::threadId($row['id']??'');}catch(\InvalidArgumentException){continue;}$hash=hash('sha256',$chat."\0".$id);$threads[$hash]??=['cursor'=>$hash,'chatJid'=>$chat,'threadId'=>$id,'source'=>'authenticated_app_state','observed'=>true];$threads[$hash][match($schema){'AiThreadRename'=>'title','AiThreadPin'=>'pinned',default=>'deleted'}]=match($schema){'AiThreadRename'=>$row['newTitle']??null,'AiThreadPin'=>$row['pinned']??null,default=>true};$threads[$hash]['timestamp']=$row['timestamp']??null;}}while(count($rows)===1000);}
        ksort($threads,SORT_STRING);$out=[];foreach($threads as$cursor=>$row)if(strcmp($cursor,$after)>0){$out[]=$row;if(count($out)===$limit)break;}return $out;
    }
    /** @return array<string,mixed> */
    public function mutateAiThread(string $chat,string $id,string $action,mixed $value=null):array
    {
        $chat=BotTransport::fbid($chat);$id=self::threadId($id);if(!in_array($action,['rename','pin','delete'],true))throw new \InvalidArgumentException('Unknown AI thread action');
        if($action==='rename')self::text($value,512,false);if($action==='pin'&&!is_bool($value))throw new \InvalidArgumentException('pinned must be boolean');
        $found=null;$after='';do{$rows=$this->aiThreads(1000,$after);foreach($rows as$row){$after=$row['cursor'];if($row['chatJid']===$chat&&$row['threadId']===$id)$found=$row;}}while(count($rows)===1000);
        if($found===null)throw new \OutOfBoundsException('AI thread was not observed');if(($found['deleted']??false)===true)throw new \LogicException('AI thread was already deleted');
        $schema=match($action){'rename'=>'AiThreadRename','pin'=>'AiThreadPin',default=>'AiThreadDelete'};$data=match($action){'rename'=>['newTitle'=>$value],'pin'=>['pinned'=>$value],default=>[]};
        $this->appState->mutate($schema,['chatJid'=>$chat,'id'=>$id],$data);
        return ['chatJid'=>$chat,'threadId'=>$id,'action'=>$action,'value'=>$value,'source'=>'authenticated_app_state','acknowledged'=>true];
    }
    public static function threadId(string $id):string{self::text($id,256,false);if(preg_match('/[\x00-\x20\x7f]/',$id))throw new \InvalidArgumentException('Invalid thread ID');return $id;}
    /** Uses only an authenticated sticker descriptor, never media keys supplied by an HTTP caller.
     * @return array<string,mixed> */
    public function setFavoriteSticker(string $id,bool $favorite):array
    {
        self::stickerId($id);$filehash=base64_encode(hex2bin($id));$descriptor=$this->projector->getAction('FavoriteSticker',['filehash'=>$filehash]);
        if($descriptor===null)foreach($this->history->iterateMessages()as$message){$proto=$message['proto']??null;if(!is_array($proto))continue;$sticker=ContentDecoder::unwrap($proto)['message']['stickerMessage']??null;if(is_array($sticker)&&($sticker['fileSha256']??null)===hex2bin($id)){$descriptor=$sticker;break;}}
        if($descriptor===null)throw new \OutOfBoundsException('Sticker descriptor was not observed');
        $fields=array_keys(SchemaCodec::fields('SyncActionValue.StickerAction'));$data=array_intersect_key($descriptor,array_flip($fields));$data['isFavorite']=$favorite;
        if($favorite&&(!is_string($data['mediaKey']??null)||strlen($data['mediaKey'])!==32||!is_string($data['fileEncSha256']??null)||strlen($data['fileEncSha256'])!==32||(!isset($data['url'])&&!isset($data['directPath']))))throw new \LogicException('Observed sticker does not contain an authenticated media descriptor');
        $this->appState->mutate('FavoriteSticker',['filehash'=>$filehash],$data);return ['stickerId'=>$id,'favorite'=>$favorite,'source'=>'authenticated_app_state','acknowledged'=>true];
    }
    /** @return array<string,mixed> */
    public function removeRecentSticker(string $id):array
    {
        self::stickerId($id);$observed=null;$after='';do{$rows=$this->history->observed()->list('stickers',['collection'=>'recent','limit'=>1000,'after'=>$after]);foreach($rows as$row){$after=$row['id'];if($row['id']===$id)$observed=$row;}}while(count($rows)===1000);
        if($observed===null)throw new \OutOfBoundsException('Recent sticker was not observed');$timestamp=$observed['lastStickerSentTs']??null;if(!is_int($timestamp)||$timestamp<0)throw new \LogicException('Recent sticker timestamp was not observed');
        $this->appState->mutate('RemoveRecentSticker',['filehash'=>base64_encode(hex2bin($id))],['lastStickerSentTs'=>$timestamp]);return ['stickerId'=>$id,'removed'=>true,'source'=>'authenticated_app_state','acknowledged'=>true];
    }
    public static function stickerId(string $id):void{if(preg_match('/^[a-f0-9]{64}$/D',$id)!==1)throw new \InvalidArgumentException('Use an observed lowercase SHA-256 sticker ID');}
    /** Account-side observations do not prove server eligibility or alter paid features.
     * @return array<string,mixed> */
    public function nativeDiagnostics():array
    {
        $items=[];foreach(['PrimaryFeature','SubscriptionsSyncV2','PrimaryVersion','DeviceCapabilities','DeviceCapabilitiesDevice','DeviceCapabilitiesV2']as$schema){$rows=[];$after='';do{$page=$this->projector->listActions($schema,1000,$after);foreach($page as$row){$after=$row['cursor'];unset($row['_raw']);$rows[]=$row;}}while(count($page)===1000);$items[]=['name'=>$schema,'state'=>$rows===[]?'unknown':'observed','records'=>$rows];}
        return ['items'=>$items,'source'=>'authenticated_app_state','observedAt'=>time(),'canEnablePaidFeatures'=>false,'serverEligibilityConfirmed'=>false];
    }
    private static function text(mixed $value,int $max,bool $empty):void{if(!is_string($value)||strlen($value)>$max||(!$empty&&trim($value)==='')||preg_match('//u',$value)!==1||preg_match('/[\x00-\x1f\x7f]/',$value))throw new \InvalidArgumentException('Invalid text');}
}
