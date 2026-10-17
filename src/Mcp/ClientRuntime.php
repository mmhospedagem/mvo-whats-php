<?php
declare(strict_types=1);
namespace MVO\Mcp;
use MVO\WaClient;
use MVO\Protocol\SchemaCodec;

/** Explicitly configured PHP clients, reused per session. Each operation comes from this allowlist. */
final class ClientRuntime
{
    private const LEGACY_RESOURCE_SCHEME='zapo://';
    private const METHODS=[
        'client'=>['getState','isConnected','isRegistered','connect','stop','sendMessage','sendText','getMessageStatus','retryMessage','sendReceipt','requestHistorySync'],
        'history'=>['listChats','listContacts','listMessages','getChat','getContact','getMessage'],
        'group'=>['queryGroupMetadata','queryAllGroups','queryGroupInviteInfo','createGroup','setSubject','setDescription','setSetting','setMemberAddMode','setMemberLinkMode','setMemberShareGroupHistoryMode','setEphemeralDuration','addParticipants','removeParticipants','promoteParticipants','demoteParticipants','leaveGroup','queryInviteCode','revokeInvite','joinGroupViaInvite','createCommunity','linkSubGroups','unlinkSubGroups','queryLinkedGroupsParticipants','queryMembershipApprovalRequests','approveMembershipRequests','rejectMembershipRequests','cancelMembershipRequests','joinLinkedGroup','fetchSubGroups','fetchSubgroupSuggestions'],
        'community'=>['queryAllCommunities','queryCommunityMetadata','createCommunity','setSubject','setDescription','getProfilePicture','setProfilePicture','removeProfilePicture','setSetting','setMemberAddMode','setMemberLinkMode','setMemberShareGroupHistoryMode','setEphemeralDuration','addParticipants','removeParticipants','promoteParticipants','demoteParticipants','queryInviteCode','revokeInvite','queryCommunityInviteInfo','joinCommunityViaInvite','leaveCommunity','deactivateCommunity','createSubGroup','linkSubGroups','unlinkSubGroups','fetchSubGroups','querySubGroups','queryLinkedGroupsParticipants','joinLinkedGroup','queryMembershipApprovalRequests','approveMembershipRequests','rejectMembershipRequests','cancelMembershipRequests','transferCommunityOwnership','fetchSubgroupSuggestions','isInternalGroup','submitGroupSuspensionAppeal'],
        'presence'=>['send','sendChatstate','subscribe'],
        'profile'=>['getProfilePicture','getStatus','setStatus','setPushName','getProfiles','getDisappearingMode','setDisappearingMode','getTextStatuses','setTextStatus','getUsernames','resolveUsername','getOwnUsername','setUsername','deleteUsername','getAboutStatus','checkUsernameAvailability','getLidsByPhoneNumbers'],
        'privacy'=>['getPrivacySettings','setPrivacySetting','getDisallowedList','setDisallowedList','getBlocklist','blockUser','unblockUser'],
        'business'=>['getBusinessProfile','editBusinessProfile','getVerifiedName','getVerifiedNames'],
        'newsletter'=>['fetch','fetchByInvite','listSubscribed','searchDirectory','fetchRecommended','fetchSimilar','fetchDirectoryList','fetchDirectoryCategoriesPreview','fetchDehydrated','create','update','follow','unfollow','mute','fetchAdminInfo','fetchAdminCapabilities','fetchFollowers','fetchInsights','fetchReports','fetchPendingInvites','fetchEnforcements','send','editMessage','react','revoke','votePoll','sendViewReceipt','fetchMessages','fetchMessageUpdates','subscribeLiveUpdates'],
        'status'=>['send','sendText','sendImage','sendVideo','sendVoice','revokeStatus','setPrivacy','setUserMuted','listStatuses','getStatus','getAudience','listViewers','getPrivacy','listMuted','isUserMuted','readStatus','replyToStatus','reactToStatus','downloadStatus'],
        'broadcastList'=>['setList','removeList','send'],
        'bot'=>['listBots','getBotProfile','sendPrompt'],
        'chat'=>['setChatArchive','setChatMute','setChatPin','setChatRead','setMessageStar','clearChat','deleteChat','deleteMessageForMe','setContact','editLabel','setChatLabel'],
    ];
    /** @var \Closure(string):WaClient */
    private \Closure $factory;
    /** @var array<string,WaClient> */
    private array $clients=[];
    /** @var list<array<string,mixed>> */
    private array $events=[];
    /** @var list<int> */
    private array $eventSizes=[];
    private int $eventBytes=0;
    private int $cursor=0;
    public readonly ToolRegistry $tools;
    /** @param callable(string):WaClient $factory */
    public function __construct(callable $factory,private readonly string $defaultSession='default',private readonly int $maxSessions=4)
    {
        if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$defaultSession) || $maxSessions<1 || $maxSessions>16) throw new \InvalidArgumentException('Invalid MCP session limits');$this->factory=\Closure::fromCallable($factory);$this->tools=new ToolRegistry();$this->register();
    }
    public function server(): StdioServer { return new StdioServer($this->tools,$this->resources(...),$this->readResource(...)); }
    public function tick(): void { foreach($this->clients as $client) if($client->isRunning()) { try {$client->poll(0.001);}catch(\Throwable $error) {$this->record($client->sessionId,'runtime_error',['class'=>get_class($error)]);} } }
    public function close(): void { foreach($this->clients as $client) $client->stop();$this->clients=[]; }
    private function client(string $session): WaClient
    {
        if(!preg_match('/^[A-Za-z0-9_-]{1,64}$/D',$session)) throw new \InvalidArgumentException('Invalid MCP session id');if(isset($this->clients[$session])) return $this->clients[$session];if(count($this->clients)>=$this->maxSessions) throw new \LengthException('MCP session limit reached');
        $client=($this->factory)($session);if($client->sessionId!==$session) throw new \LogicException('MCP client factory returned the wrong session');
        foreach(['connection','message','message_receipt','message_ack','auth_qr','auth_code','group','presence','chatstate','picture','own_username','newsletter_update','status','bot_chunk','offline_resume','appstate_mutation','appstate_keys','appstate_error','history_sync_chunk','service_error'] as $event) $client->on($event,function(array $payload)use($session,$event):void {$this->record($session,$event,$payload);});return $this->clients[$session]=$client;
    }
    /** @param array<string,mixed> $data */
    private function record(string $session,string $event,array $data): void
    {
        try { $safe=JsonValue::encode($data);if(strlen(json_encode($safe,JSON_THROW_ON_ERROR))>65536) $safe=['omitted'=>'Event exceeded 64 KiB MCP event limit']; }
        catch(\Throwable) { $safe=['omitted'=>'Event exceeded MCP serialization limits']; }
        $record=['cursor'=>++$this->cursor,'session'=>$session,'event'=>$event,'data'=>$safe];$size=strlen(json_encode($record,JSON_THROW_ON_ERROR));$this->events[]=$record;$this->eventSizes[]=$size;$this->eventBytes+=$size;
        while(count($this->events)>200 || $this->eventBytes>1048576) { array_shift($this->events);$this->eventBytes-=array_shift($this->eventSizes) ?? 0; }
    }
    private function register(): void
    {
        $classes=['client'=>WaClient::class,'history'=>\MVO\History\HistoryStore::class,'group'=>\MVO\Service\GroupService::class,'community'=>\MVO\Service\CommunityService::class,'presence'=>\MVO\Service\PresenceService::class,'profile'=>\MVO\Service\ProfileService::class,'privacy'=>\MVO\Service\PrivacyService::class,'business'=>\MVO\Service\BusinessService::class,'newsletter'=>\MVO\Service\NewsletterService::class,'status'=>\MVO\Service\StatusService::class,'broadcastList'=>\MVO\Service\BroadcastListService::class,'bot'=>\MVO\Service\BotService::class,'chat'=>\MVO\AppState\AppStateService::class];
        foreach(self::METHODS as $service=>$methods) foreach($methods as $method) {
            // The explicit allowlist can target multiple supported library versions.
            if(!method_exists($classes[$service],$method)) continue;$reflection=new \ReflectionMethod($classes[$service],$method);if(!$reflection->isPublic()) continue;
            $properties=['session'=>['type'=>'string','minLength'=>1,'maxLength'=>64,'default'=>$this->defaultSession]];$required=[];
            foreach($reflection->getParameters() as $param) { $properties[$param->getName()]=self::schema($param->getType());if(!$param->isOptional()) $required[]=$param->getName();elseif($param->isDefaultValueAvailable()) $properties[$param->getName()]['default']=$param->getDefaultValue(); }
            $readOnly=str_starts_with($method,'get') || str_starts_with($method,'is') || str_starts_with($method,'query') || str_starts_with($method,'list') || str_starts_with($method,'fetch') || $method==='checkUsernameAvailability';
            $name=strtolower($service.'_'.preg_replace('/([a-z0-9])([A-Z])/','$1_$2',$method));$schema=['type'=>'object','properties'=>$properties,'required'=>$required,'additionalProperties'=>false];
            $this->tools->add($name,'Calls PHP '.$service.'.'.$method.' on the selected session.',$schema,function(array $input)use($service,$reflection):mixed {
                $session=$input['session'] ?? $this->defaultSession;$client=$this->client($session);$target=match($service) {'client'=>$client,'history'=>$client->history(),'group'=>$client->group(),'community'=>$client->community(),'presence'=>$client->presence(),'profile'=>$client->profile(),'privacy'=>$client->privacy(),'business'=>$client->business(),'newsletter'=>$client->newsletter(),'status'=>$client->status(),'broadcastList'=>$client->broadcastList(),'bot'=>$client->bot(),'chat'=>$client->chat()};
                $args=[];foreach($reflection->getParameters() as $param) $args[]=array_key_exists($param->getName(),$input) ? JsonValue::decode($input[$param->getName()]) : $param->getDefaultValue();
                return $reflection->invokeArgs($target,$args);
            },$readOnly,str_starts_with($method,'delete') || str_starts_with($method,'remove') || str_starts_with($method,'revoke') || in_array($method,['leaveGroup','leaveCommunity','deactivateCommunity','transferCommunityOwnership','unlinkSubGroups','demoteParticipants','rejectMembershipRequests','cancelMembershipRequests'],true));
        }
        $this->tools->add('events_list','Read a bounded ring of sanitized session events.',['type'=>'object','properties'=>['after'=>['type'=>'integer','minimum'=>0,'default'=>0],'limit'=>['type'=>'integer','minimum'=>1,'maximum'=>200,'default'=>100]],'additionalProperties'=>false],function(array $args):array {return ['cursor'=>$this->cursor,'events'=>array_slice(array_values(array_filter($this->events,static fn(array $e):bool=>$e['cursor']>($args['after'] ?? 0))),0,$args['limit'] ?? 100),'oldestCursor'=>$this->events[0]['cursor'] ?? $this->cursor];},true);
    }
    /** @return array<string,mixed> */
    private static function schema(?\ReflectionType $type): array
    {
        if($type instanceof \ReflectionUnionType) return ['anyOf'=>array_map(self::schema(...),$type->getTypes())];if(!$type instanceof \ReflectionNamedType) return [];
        $schema=match($type->getName()) {'string'=>['type'=>'string','maxLength'=>1048576],'int'=>['type'=>'integer'],'float'=>['type'=>'number'],'bool'=>['type'=>'boolean'],'array'=>['anyOf'=>[['type'=>'object'],['type'=>'array','maxItems'=>10000]]],'null'=>['type'=>'null'],default=>[]};
        return $type->allowsNull() && $type->getName()!=='null' ? ['anyOf'=>[$schema,['type'=>'null']]] : $schema;
    }
    /** @return array<string,array<string,mixed>> */
    public function resources(): array
    {
        return ['mvo://sessions'=>['uri'=>'mvo://sessions','name'=>'Sessions','mimeType'=>'application/json'],'mvo://schema/message'=>['uri'=>'mvo://schema/message','name'=>'WAProto Message field schema','mimeType'=>'application/json'],'mvo://events'=>['uri'=>'mvo://events','name'=>'Recent sanitized events','mimeType'=>'application/json']];
    }
    public function readResource(string $uri): string
    {
        if(str_starts_with($uri,self::LEGACY_RESOURCE_SCHEME))$uri='mvo://'.substr($uri,strlen(self::LEGACY_RESOURCE_SCHEME));
        $value=match($uri) {'mvo://sessions'=>array_map(static fn(WaClient $c):array=>['session'=>$c->sessionId,'state'=>$c->getState(),'registered'=>$c->isRegistered()],$this->clients),'mvo://schema/message'=>['types'=>SchemaCodec::messageTypes(),'fields'=>SchemaCodec::fields('Message')],'mvo://events'=>['cursor'=>$this->cursor,'events'=>$this->events],default=>throw new \InvalidArgumentException('Unknown resource')};return json_encode(JsonValue::encode($value),JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    }
}
