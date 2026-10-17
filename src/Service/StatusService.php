<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\AppState\AppStateService;
use MVO\History\StatusStore;
use MVO\Media\MediaTransfer;
use MVO\Message\{ContentBuilder,MessageCodec};
use MVO\Protocol\Jid;

/** Status administration on the authenticated session's durable inbox/outbox. */
final class StatusService
{
    /** @var \Closure():AppStateService */
    private \Closure $appState;
    /** @var \Closure(string,string|array<string,mixed>,list<string>,array<string,mixed>):array<string,mixed> */
    private \Closure $publisher;
    /** @var (\Closure():StatusStore)|null */
    private ?\Closure $store;
    /** @var (\Closure(list<array<string,mixed>>,string):void)|null */
    private ?\Closure $receipt;
    /** @var (\Closure(string,string|array<string,mixed>):array<string,mixed>)|null */
    private ?\Closure $directPublisher;
    /** @var (\Closure(array<string,mixed>):string)|null */
    private ?\Closure $download;
    /** @var (\Closure(string|array<string>,string|array<string,mixed>,array<string,mixed>):array<string,mixed>)|null */
    private ?\Closure $groupPublisher=null;
    /** @var (\Closure(string,string):array<string,mixed>)|null */
    private ?\Closure $groupRevoker=null;

    /** Original arguments remain supported for publishing and app-state mutations.
     *
 * @param callable():AppStateService $appState
     *
 * @param callable(string,string|array<string,mixed>,list<string>,array<string,mixed>):array<string,mixed> $publisher
     *
 * @param (callable():StatusStore)|null $store
     *
 * @param (callable(list<array<string,mixed>>,string):void)|null $receipt
     *
 * @param (callable(string,string|array<string,mixed>):array<string,mixed>)|null $directPublisher
     *
 * @param (callable(array<string,mixed>):string)|null $download */
    public function __construct(callable $appState,callable $publisher,?callable $store=null,?callable $receipt=null,?callable $directPublisher=null,?callable $download=null)
    {
        $this->appState=\Closure::fromCallable($appState);$this->publisher=\Closure::fromCallable($publisher);
        $this->store=$store===null?null:\Closure::fromCallable($store);$this->receipt=$receipt===null?null:\Closure::fromCallable($receipt);
        $this->directPublisher=$directPublisher===null?null:\Closure::fromCallable($directPublisher);$this->download=$download===null?null:\Closure::fromCallable($download);
    }
    /** @param callable(string|list<string>,string|array<string,mixed>,array<string,mixed>):array<string,mixed> $publisher
     * @param callable(string,string):array<string,mixed> $revoker */
    public function configureGroupPublishing(callable $publisher,callable $revoker):void{$this->groupPublisher=\Closure::fromCallable($publisher);$this->groupRevoker=\Closure::fromCallable($revoker);}
    /** Explicit group JIDs; no member enumeration into a personal Status audience.
     * @param string|list<string> $groups
     * @param string|array<string,mixed> $content
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendToGroups(string|array $groups,string|array $content,array $options=[]):array
    {
        \MVO\Message\GroupStatusContent::validate($groups,$content,$options);
        return ($this->groupPublisher??throw new \LogicException('Group Status publication is not configured; use WaClient::status'))($groups,$content,$options);
    }
    /** Locally observed/published messages in this group only.
     * @param array<string,mixed> $filters
     * @return list<array<string,mixed>> */
    public function listForGroup(string $group,array $filters=[]):array
    {
        $group=\MVO\Message\GroupStatusContent::normalizeGroups($group)[0];
        self::only($filters,['limit','beforeTimestamp','includeRevoked','author'],'group status filter');
        return $this->history()->listInteractiveStatuses([...$filters,'type'=>'group-status','groupJid'=>$group]);
    }
    /** @return array<string,mixed>|null */
    public function getForGroup(string $group,string $id,?string $author=null):?array
    {
        $group=\MVO\Message\GroupStatusContent::normalizeGroups($group)[0];self::id($id);
        return $this->history()->getGroupStatus($group,$id,$author);
    }
    /** Both the group wrapper and linked-device deletion are returned independently.
     * @return array<string,mixed> */
    public function deleteForGroup(string $group,string $id):array
    {
        $group=\MVO\Message\GroupStatusContent::normalizeGroups($group)[0];self::id($id);
        return ($this->groupRevoker??throw new \LogicException('Group Status revocation is not configured; use WaClient::status'))($group,$id);
    }
    /**
 * @param array<string,mixed> $input */
    public function setPrivacy(array $input):void {$input=self::privacyInput($input);($this->appState)()->setStatusPrivacy($input);}
    public function setUserMuted(string $jid,bool $muted):void {$jid=self::user($jid);($this->appState)()->setUserStatusMute($jid,$muted);}

    /** Explicit recipients are required; privacy never triggers an implicit contact-directory broadcast.
     *
 * @param string|array<string,mixed> $content
 * @param list<string> $recipients
 * @param array<string,mixed> $options
     *
 * @return array<string,mixed> Server ACK or uncertain-send result, not proof of views/delivery. */
    public function send(string|array $content,array $recipients,?string $statusSetting=null,array $options=[]):array
    {
        $content=self::prepareContent($content);$recipients=self::normalizeRecipients($recipients);$options=self::distributionOptions($statusSetting,$options);
        return ($this->publisher)(StatusStore::CHAT,$content,$recipients,$options);
    }
    /**
 * @param list<string> $recipients
 * @param array<string,mixed> $style
 * @param array<string,mixed> $options
 * @return array<string,mixed> */
    public function sendText(string $text,array $recipients,array $style=[],?string $statusSetting=null,array $options=[]):array
    {
        self::only($style,['font','backgroundColor','textColor'],'text style');return $this->send(['type'=>'text','text'=>$text,...$style],$recipients,$statusSetting,$options);
    }
    /**
 * @param string|array<string,mixed> $media
 * @param list<string> $recipients
 * @param array<string,mixed> $options
 * @return array<string,mixed> */
    public function sendImage(string|array $media,array $recipients,array $options=[],?string $statusSetting=null):array {self::only($options,['caption','mimetype','width','height','jpegThumbnail','accessibilityLabel'],'image options');return $this->send(['type'=>'image','media'=>$media,...$options],$recipients,$statusSetting);}
    /**
 * @param string|array<string,mixed> $media
 * @param list<string> $recipients
 * @param array<string,mixed> $options
 * @return array<string,mixed> */
    public function sendVideo(string|array $media,array $recipients,array $options=[],?string $statusSetting=null):array {self::only($options,['caption','mimetype','width','height','seconds','jpegThumbnail','accessibilityLabel','gifPlayback'],'video options');return $this->send(['type'=>'video','media'=>$media,...$options],$recipients,$statusSetting);}
    /**
 * @param string|array<string,mixed> $media
 * @param list<string> $recipients
 * @param array<string,mixed> $options
 * @return array<string,mixed> */
    public function sendVoice(string|array $media,array $recipients,array $options=[],?string $statusSetting=null):array {self::only($options,['mimetype','seconds','waveform','backgroundColor','accessibilityLabel','normalizeVoice'],'voice options');return $this->send(['type'=>'voice','media'=>$media,...$options],$recipients,$statusSetting);}

    /** @param list<string> $recipients
     * @param array<string,mixed> $preview
     * @return array<string,mixed> */
    public function sendLink(string $text, string $url, array $recipients, array $preview=[], ?string $statusSetting=null): array
    {
        $style=$preview['style'] ?? 'AUTO'; unset($preview['style']);
        return $this->send(['type'=>'text', 'text'=>$text, 'linkPreview'=>['matchedText'=>$url, ...$preview], 'linkPreviewStyle'=>$style], $recipients, $statusSetting);
    }
    /** A text status with music metadata, or image/video content with a musicAnnotation.
     * @param array<string,mixed> $content
     * @param list<string> $recipients
     * @return array<string,mixed> */
    public function sendMusic(array $content, array $recipients, ?string $statusSetting=null): array { return $this->send($content, $recipients, $statusSetting); }
    /** @param list<string> $recipients
     * @return array<string,mixed> */
    public function sendQuestion(string $text, array $recipients, ?string $statusSetting=null): array { return $this->send(['type'=>'status-question', 'text'=>$text], $recipients, $statusSetting); }
    /** @param list<string> $recipients
     * @return array<string,mixed> */
    public function reshare(string $id, string $author, array $recipients, ?string $statusSetting=null): array
    {
        $status=$this->history()->getStatus($id,$author) ?? throw new \InvalidArgumentException('Active status was not observed in this session');
        $message=\MVO\Message\ContentDecoder::unwrap($status['proto'])['message'];
        return $this->send(['type'=>'status-share', 'message'=>$message, 'source'=>'INTERNAL_RESHARE'], $recipients, $statusSetting);
    }

    /** Revoke only a known local publication using its original distribution snapshot.
     * Explicit recipients must match that snapshot, preventing a deletion from widening the audience.
     *
 * @param list<string> $recipients
 * @param array<string,mixed> $options
 * @return array<string,mixed> */
    public function revokeStatus(string $messageId,array $recipients=[],?string $statusSetting=null,array $options=[]):array
    {
        self::id($messageId);$options=self::distributionOptions($statusSetting,$options);$store=$this->history();
        $record=$store->getStatus($messageId);$audience=$store->getAudience($messageId);
        if($record===null||($record['fromMe']??false)!==true||$audience===null||($audience['author']??null)!==($record['author']??null))throw new \LogicException('Only a known active status published by this session can be revoked');
        $original=self::normalizeRecipients($audience['recipients']??[]);sort($original,SORT_STRING);
        if($recipients!==[]) {$explicit=self::normalizeRecipients($recipients);sort($explicit,SORT_STRING);if($explicit!==$original)throw new \InvalidArgumentException('Status deletion recipients must match the original audience');}
        $setting=$audience['statusSetting']??null;if(!is_string($setting))throw new \UnexpectedValueException('Missing original status distribution setting');
        if(isset($options['statusSetting'])&&$options['statusSetting']!==$setting)throw new \InvalidArgumentException('Status deletion must use its original distribution setting');$options['statusSetting']=$setting;
        return ($this->publisher)(StatusStore::CHAT,['type'=>'raw','message'=>['protocolMessage'=>['type'=>'REVOKE','key'=>['remoteJid'=>StatusStore::CHAT,'fromMe'=>true,'id'=>$messageId,'participant'=>$record['author']]]]],$original,$options);
    }
    /** Protocol observations are not a promise that a given account/client can publish these variants.
     * @return array<string,mixed> */
    public static function interactiveCapabilities():array
    {
        $common=['receive'=>true,'localQuery'=>true,'publish'=>false,'clientCompatibility'=>'not_guaranteed'];
        return ['source'=>'sdk_protocol_evidence','features'=>[
            'poll'=>$common+['reason'=>'Status poll reception is identified; an authenticated publication and vote-routing producer was not established.'],
            'add-yours'=>$common+['reason'=>'Status wrappers and authenticated Add Yours response notifications are observed; prompt publication was not established.'],
            'group-status'=>array_replace($common,['publish'=>true,'contentTypes'=>['text','image','video','audio','voice'],'maxGroups'=>50,'transport'=>'group_sender_key','wrapper'=>'groupStatusMessageV2','customAudienceDisplay'=>true,'personalizedRecipients'=>false,'reason'=>'Explicit group publication uses current membership and Group Status V2. Client rollout and server permissions still apply.']),
        ]];
    }
    /** @param array<string,mixed> $filters
     * @return list<array<string,mixed>> */
    public function listInteractiveStatuses(array $filters=[]):array{return $this->history()->listInteractiveStatuses($filters);}

    /** Local synchronized statuses only.
 * @param array<string,mixed> $filters
 * @return list<array<string,mixed>> */
    public function listStatuses(array $filters=[]):array {return $this->history()->listStatuses($filters);}
    /**
 * @return array<string,mixed>|null */
    public function getStatus(string $id,?string $author=null,bool $includeExpired=false,bool $includeRevoked=false):?array {return $this->history()->getStatus($id,$author,$includeExpired,$includeRevoked);}
    /**
 * @return array<string,mixed>|null */
    public function getAudience(string $id):?array {return $this->history()->getAudience($id);}
    /** Observed authenticated read/play receipts only.
 * @return list<array{jid:string,timestamp:int,status:string}> */
    public function listViewers(string $id):array {return $this->history()->listViewers($id);}
    /** Null means no authenticated privacy setting has been synchronized.
 * @return array<string,mixed>|null */
    public function getPrivacy():?array {return $this->history()->getPrivacy();}
    /**
 * @return list<array<string,mixed>> */
    public function listMuted(int $limit=100,string $after=''):array {return $this->history()->listMuted($limit,$after);}
    public function isUserMuted(string $jid):?bool {return $this->history()->isUserMuted(self::user($jid));}
    /** Queue a durable read receipt for a known received status; queueing is not a server ACK.
     *
 * @return array<string,mixed> */
    public function readStatus(string $id,string $author):array
    {
        $record=$this->received($id,$author);($this->receipt??throw new \LogicException('Status receipts are not configured'))([$record['key']],'read');
        return ['id'=>$id,'author'=>$record['author'],'status'=>'receipt_queued'];
    }
    /** Send privately to the author, quoting the actual status@broadcast key.
 * @return array<string,mixed> */
    public function replyToStatus(string $id,string $author,string $text):array
    {
        MessageCodec::text($text);$record=$this->received($id,$author);
        return ($this->directPublisher??throw new \LogicException('Status replies are not configured'))($record['author'],['type'=>'text','text'=>$text,'quote'=>['key'=>$record['key'],'proto'=>$record['proto']??[]]]);
    }
    /** Empty emoji removes the reaction. Its target remains the broadcast key.
 * @return array<string,mixed> */
    public function reactToStatus(string $id,string $author,string $emoji):array
    {
        if(strlen($emoji)>128||preg_match('//u',$emoji)!==1||preg_match('/[\x00-\x1f\x7f]/',$emoji))throw new \InvalidArgumentException('Invalid status reaction');$record=$this->received($id,$author);
        return ($this->directPublisher??throw new \LogicException('Status reactions are not configured'))($record['author'],['type'=>'raw','message'=>['reactionMessage'=>['key'=>$record['key'],'text'=>$emoji,'senderTimestampMs'=>(int)floor(microtime(true)*1000)]]]);
    }
    /** Returns authenticated plaintext bytes from a known active status. No arbitrary URL/key/output path. */
    public function downloadStatus(string $id,?string $author=null):string
    {
        $record=$this->history()->getStatus($id,$author);if($record===null)throw new \LogicException('Status is not available in this session');
        if(MediaTransfer::resolvePayload($record['proto']??[])===null)throw new \InvalidArgumentException('Status does not contain downloadable media');
        return ($this->download??throw new \LogicException('Status media download is not configured'))($record);
    }
    /** Pure validation before media processing, USync or encryption. Raw WAProto accepts only status media/text.
     *
 * @param string|array<string,mixed> $content
 * @return array<string,mixed> */
    public static function prepareContent(string|array $content):array
    {
        if(is_string($content))$content=['type'=>'text','text'=>$content];$type=$content['type']??'text';
        $metadata=array_intersect_key($content,array_flip(['linkPreview','linkPreviewStyle','musicMetadata','musicAnnotation','statusAttributions','association']));
        if($metadata!==[]) {
            $base=self::prepareContent(array_diff_key($content,$metadata));
            if(($base['type']??null)==='raw')return ['type'=>'raw', 'message'=>\MVO\Message\ModernContent::decorateStatus($base['message'],$metadata)];
            // Validate the decorator before the upload, using only a temporary body of the same media type.
            $field=match($base['type']){'image'=>'imageMessage','video'=>'videoMessage',default=>'audioMessage'};
            \MVO\Message\ModernContent::decorateStatus([$field=>[]],$metadata);
            return [...$base, 'statusMetadata'=>$metadata];
        }
        if(in_array($type,['status-question','status-share'],true)) {
            $message=ContentBuilder::build($content,StatusStore::CHAT)['proto'];
            return self::prepareContent(['type'=>'raw','message'=>$message]);
        }
        if($type==='raw') {
            self::only($content,['type','message'],'status content');$message=$content['message']??null;
            if(!is_array($message))throw new \InvalidArgumentException('Raw status requires text, image, video or audio');
            $unwrapped=\MVO\Message\ContentDecoder::unwrap($message);
            if(array_diff($unwrapped['wrappers'],['questionMessage'])!==[])throw new \InvalidArgumentException('Unsupported status wrapper');
            $body=array_diff_key($unwrapped['message'],['messageContextInfo'=>true,'statusLinkPreviewMetadata'=>true]);
            if(count($body)!==1||!in_array(array_key_first($body),['extendedTextMessage','imageMessage','videoMessage','audioMessage'],true))throw new \InvalidArgumentException('Raw status requires text, image, video or audio');
            ContentBuilder::build($content,StatusStore::CHAT);return $content;
        }
        if($type==='text') {
            self::only($content,['type','text','font','backgroundColor','textColor'],'status text');$text=$content['text']??null;if(!is_string($text))throw new \InvalidArgumentException('Status text is required');MessageCodec::text($text);$body=['text'=>$text];
            if(array_key_exists('font',$content))$body['font']=self::font($content['font']);foreach(['backgroundColor'=>'backgroundArgb','textColor'=>'textArgb'] as $from=>$to)if(array_key_exists($from,$content))$body[$to]=self::color($content[$from]);
            return ['type'=>'raw','message'=>['extendedTextMessage'=>$body]];
        }
        if(!in_array($type,['image','video','voice','ptt'],true))throw new \InvalidArgumentException('Status supports text, image, video and voice');
        $allowed=match($type){'image'=>['caption','mimetype','width','height','jpegThumbnail','accessibilityLabel'],'video'=>['caption','mimetype','width','height','seconds','jpegThumbnail','accessibilityLabel','gifPlayback'],default=>['mimetype','seconds','waveform','backgroundColor','backgroundArgb','accessibilityLabel','normalizeVoice']};
        self::only($content,['type','media',...$allowed],'status media');$media=$content['media']??null;if((!is_string($media)&&!is_array($media))||$media===''||$media===[])throw new \InvalidArgumentException('Status media source is required');
        foreach(['caption','accessibilityLabel'] as $field)if(isset($content[$field])&&(!is_string($content[$field])||strlen($content[$field])>65536||preg_match('//u',$content[$field])!==1))throw new \InvalidArgumentException('Invalid status '.$field);
        if(isset($content['mimetype'])&&(!is_string($content['mimetype'])||!preg_match('~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+(?:;[^\r\n]*)?$~',$content['mimetype'])))throw new \InvalidArgumentException('Invalid status mimetype');
        foreach(['width','height','seconds'] as $field)if(isset($content[$field])&&(!is_int($content[$field])||$content[$field]<0||$content[$field]>0xffffffff))throw new \InvalidArgumentException('Invalid status '.$field);
        foreach(['jpegThumbnail','waveform'] as $field)if(isset($content[$field])&&(!is_string($content[$field])||strlen($content[$field])>1048576))throw new \InvalidArgumentException('Invalid status '.$field);
        foreach(['normalizeVoice','gifPlayback'] as $field)if(isset($content[$field])&&!is_bool($content[$field]))throw new \InvalidArgumentException($field.' must be boolean');
        if(array_key_exists('backgroundColor',$content)){$content['backgroundArgb']=self::color($content['backgroundColor']);unset($content['backgroundColor']);}elseif(array_key_exists('backgroundArgb',$content))$content['backgroundArgb']=self::color($content['backgroundArgb']);
        if($type==='voice')$content['type']='ptt';return $content;
    }
    /**
 * @param list<string> $recipients
 * @return list<string> */
    public static function normalizeRecipients(array $recipients):array
    {
        if($recipients===[]||!array_is_list($recipients)||count($recipients)>10000)throw new \InvalidArgumentException('Status requires an explicit audience of 1 to 10000 users');$normalized=[];
        foreach($recipients as $recipient){if(!is_string($recipient))throw new \InvalidArgumentException('Invalid status recipient');$normalized[]=self::user($recipient);}return array_values(array_unique($normalized));
    }
    /**
 * @param array<string,mixed> $input
 * @return array<string,mixed> */
    private static function privacyInput(array $input):array
    {
        self::only($input,['mode','userJids','shareToFB','shareToIG','shareToFb','shareToIg'],'status privacy');$mode=$input['mode']??null;
        if(is_string($mode))$mode=match(strtolower($mode)){'allowlist'=>'ALLOW_LIST','denylist','contacts_except'=>'DENY_LIST',default=>strtoupper($mode)};
        if((!is_int($mode)||$mode<0||$mode>4)&&(!is_string($mode)||!in_array($mode,['ALLOW_LIST','DENY_LIST','CONTACTS','CLOSE_FRIENDS','CUSTOM_LIST'],true)))throw new \InvalidArgumentException('Invalid status privacy mode');
        $users=$input['userJids']??[];if(!is_array($users)||!array_is_list($users)||count($users)>10000)throw new \InvalidArgumentException('Invalid status privacy users');$normalized=[];
        foreach($users as $user){if(!is_string($user))throw new \InvalidArgumentException('Invalid status privacy user');$normalized[]=self::user($user);}
        foreach(['shareToFB','shareToIG','shareToFb','shareToIg'] as $field)if(array_key_exists($field,$input)&&!is_bool($input[$field]))throw new \InvalidArgumentException('Invalid status privacy '.$field);
        $input['mode']=$mode;$input['userJids']=array_values(array_unique($normalized));return $input;
    }
    private function history():StatusStore {return ($this->store??throw new \LogicException('Status history is not configured; use WaClient::status'))();}
    /**
 * @return array<string,mixed> */
    private function received(string $id,string $author):array
    {
        self::id($id);$author=self::user($author);$record=$this->history()->getStatus($id,$author);if($record===null||($record['fromMe']??true)!==false)throw new \LogicException('A known active received status and its author are required');return $record;
    }
    private static function user(string $input):string
    {
        $jid=Jid::recipient($input)->canonical();if(!in_array($jid->server,['s.whatsapp.net','lid'],true)||$jid->device!==0||!ctype_digit($jid->user))throw new \InvalidArgumentException('Status recipients must be phone numbers or bare user JIDs');return $jid->bare();
    }
    /**
 * @param array<string,mixed> $options
 * @return array<string,mixed> */
    private static function distributionOptions(?string $setting,array $options):array
    {
        self::only($options,['id','statusSetting'],'status distribution');if(isset($options['id'])){if(!is_string($options['id']))throw new \InvalidArgumentException('Invalid status ID');self::id($options['id']);}
        if($setting!==null&&isset($options['statusSetting'])&&$options['statusSetting']!==$setting)throw new \InvalidArgumentException('Conflicting status distribution settings');$setting??=$options['statusSetting']??null;
        if($setting!==null&&!in_array($setting,['contacts','allowlist','denylist','close_friends'],true))throw new \InvalidArgumentException('Invalid status distribution setting');if($setting!==null)$options['statusSetting']=$setting;return $options;
    }
    private static function font(mixed $font):int
    {
        $fonts=['SYSTEM'=>0,'SYSTEM_TEXT'=>1,'FB_SCRIPT'=>2,'SYSTEM_BOLD'=>6,'MORNINGBREEZE_REGULAR'=>7,'CALISTOGA_REGULAR'=>8,'EXO2_EXTRABOLD'=>9,'COURIERPRIME_BOLD'=>10];if(is_string($font))$font=$fonts[$font]??null;
        if(!is_int($font)||!in_array($font,array_values($fonts),true))throw new \InvalidArgumentException('Invalid status font');return $font;
    }
    private static function color(mixed $color):int
    {
        if(is_string($color)&&preg_match('/^#(?:[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/D',$color))$color=(int)hexdec((strlen($color)===7?'FF':'').substr($color,1));
        if(!is_int($color)||$color<0||$color>0xffffffff)throw new \InvalidArgumentException('Status color must be uint32 ARGB, #RRGGBB or #AARRGGBB');return $color;
    }
    private static function id(string $id):void {if($id===''||strlen($id)>256||preg_match('/[\x00-\x20]/',$id))throw new \InvalidArgumentException('Invalid status ID');}
    /**
 * @param array<string,mixed> $input
 * @param list<string> $allowed */
    private static function only(array $input,array $allowed,string $kind):void {foreach(array_keys($input) as $field)if(!in_array($field,$allowed,true))throw new \InvalidArgumentException('Unknown '.$kind.' field: '.$field);}
}
