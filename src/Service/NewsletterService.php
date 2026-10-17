<?php
declare(strict_types=1);
namespace MVO\Service;
use MVO\Message\{ContentBuilder,ContentDecoder};
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\History\ChannelStore;

/** Channels are public plaintext messages, never sent through direct-chat Signal encryption. */
final class NewsletterService
{
    /** @var (\Closure(array<string,mixed>):array<string,mixed>)|null */
    private ?\Closure $prepareMedia;
    /** @var (\Closure(BinaryNode,string):array<array-key,array<string,mixed>>)|null */
    private ?\Closure $ingestMessages;
    /** @var (\Closure():ChannelStore)|null */
    private ?\Closure $channelStore;
    /** @var (\Closure(array<string,mixed>):string)|null */
    private ?\Closure $downloadMedia;
    /** @var (\Closure(BinaryNode,BinaryNode):void)|null */
    private ?\Closure $recordPublication;
    /** @param callable(array<string,mixed>):array<string,mixed>|null $prepareMedia
     * @param (callable(BinaryNode,string):array<array-key,array<string,mixed>>)|null $ingestMessages
     * @param (callable():ChannelStore)|null $channelStore
     * @param (callable(array<string,mixed>):string)|null $downloadMedia
     * @param (callable(BinaryNode,BinaryNode):void)|null $recordPublication */
    public function __construct(private readonly ServiceTransport $transport,private readonly MexService $mex,?callable $prepareMedia=null,?callable $ingestMessages=null,?callable $channelStore=null,?callable $downloadMedia=null,?callable $recordPublication=null)
    {
        $this->prepareMedia=$prepareMedia===null?null:\Closure::fromCallable($prepareMedia);
        $this->ingestMessages=$ingestMessages===null?null:\Closure::fromCallable($ingestMessages);$this->channelStore=$channelStore===null?null:\Closure::fromCallable($channelStore);
        $this->downloadMedia=$downloadMedia===null?null:\Closure::fromCallable($downloadMedia);$this->recordPublication=$recordPublication===null?null:\Closure::fromCallable($recordPublication);
    }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function fetch(string $jid,array $options=[]): array { return $this->metadata(NewsletterInput::jid($jid),'JID',$options); }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function fetchByInvite(string $invite,array $options=[]): array { return $this->metadata(NewsletterInput::invite($invite),'INVITE',$options); }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    private function metadata(string $key,string $type,array $options): array
    {
        NewsletterInput::keys($options,['viewRole','fetchViewerMetadata','fetchFullImage','fetchCreationTime','fetchWamoSub']);
        if(array_key_exists('viewRole',$options)) NewsletterInput::role($options['viewRole']);
        foreach(['fetchViewerMetadata','fetchFullImage','fetchCreationTime','fetchWamoSub'] as $field) if(array_key_exists($field,$options)) NewsletterInput::boolean($options[$field],$field);
        $d=$this->mex->run('FetchNewsletter',['input'=>['key'=>$key,'type'=>$type,'view_role'=>$options['viewRole'] ?? 'SUBSCRIBER'],'fetch_viewer_metadata'=>$options['fetchViewerMetadata'] ?? true,'fetch_full_image'=>$options['fetchFullImage'] ?? $type !== 'INVITE','fetch_creation_time'=>$options['fetchCreationTime'] ?? true,'fetch_wamo_sub'=>$options['fetchWamoSub'] ?? false,'fetch_status_metadata'=>false,'fetch_pinned_messages'=>true]);
        return self::requiredObject($d,'xwa2_newsletter');
    }
    /**
     * @return list<array<string,mixed>> */
    public function listSubscribed(bool $fetchWamoSub=false): array
    {
        $d=$this->mex->run('FetchAllNewslettersMetadata',['fetch_wamo_sub'=>$fetchWamoSub,'fetch_status_metadata'=>false]);
        $rows=$d['xwa2_newsletter_subscribed'] ?? null;
        if(!is_array($rows) || !array_is_list($rows)) throw new \UnexpectedValueException('Subscribed channels response missing');
        foreach($rows as $row) if(!is_array($row)) throw new \UnexpectedValueException('Invalid subscribed channel metadata');
        return $rows;
    }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>|null */
    public function searchDirectory(array $options=[]): ?array { NewsletterInput::keys($options,['searchText','categories','limit','startCursor']);$input=['search_text'=>NewsletterInput::text($options['searchText'] ?? '','searchText',4096),'categories'=>NewsletterInput::strings($options['categories'] ?? [],'categories'),'limit'=>NewsletterInput::integer($options['limit'] ?? 100,'limit')]; if (array_key_exists('startCursor',$options)) $input['start_cursor']=NewsletterInput::cursor($options['startCursor']); return $this->mex->run('FetchNewsletterDirectorySearchResults',['input'=>$input,'fetch_status_metadata'=>false]); }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>|null */
    public function fetchRecommended(array $options=[]): ?array { NewsletterInput::keys($options,['limit','countryCodes']);return $this->mex->run('FetchRecommendedNewsletters',['input'=>['limit'=>NewsletterInput::integer($options['limit'] ?? 25,'limit'),'country_codes'=>NewsletterInput::countries($options['countryCodes'] ?? [])],'fetch_status_metadata'=>false]); }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>|null */
    public function fetchSimilar(string $jid,array $options=[]): ?array { NewsletterInput::keys($options,['limit','countryCodes']);return $this->mex->run('FetchSimilarNewsletters',['input'=>['newsletter_id'=>NewsletterInput::jid($jid),'limit'=>NewsletterInput::integer($options['limit'] ?? 10,'limit'),'country_codes'=>NewsletterInput::countries($options['countryCodes'] ?? [])],'fetch_status_metadata'=>false]); }
    /**
     * @param array<string,mixed> $options
     * @return array<string,mixed>|null */
    public function fetchDirectoryList(array $options): ?array { NewsletterInput::keys($options,['view','countryCodes','categories','limit','startCursor']);$input=['view'=>NewsletterInput::enum($options['view'] ?? null,'view',['RECOMMENDED','NEW','POPULAR','FEATURED','TRENDING']),'filters'=>['country_codes'=>NewsletterInput::countries($options['countryCodes'] ?? []),'categories'=>NewsletterInput::strings($options['categories'] ?? [],'categories')],'limit'=>NewsletterInput::integer($options['limit'] ?? 25,'limit')]; if(array_key_exists('startCursor',$options)) $input['start_cursor']=NewsletterInput::cursor($options['startCursor']); return $this->mex->run('FetchNewsletterDirectoryList',['input'=>$input,'fetch_status_metadata'=>false]); }
    /**
     * @param list<string> $categories
     * @return array<string,mixed>|null */
    public function fetchDirectoryCategoriesPreview(array $categories,?string $country=null,int $limit=10): ?array { $i=['categories'=>NewsletterInput::strings($categories,'categories',1000,false),'per_category_limit'=>NewsletterInput::integer($limit,'limit')]+($country === null ? [] : ['country_code'=>NewsletterInput::countries([$country])[0]]); return $this->mex->run('FetchNewsletterDirectoryCategoriesPreview',['input'=>$i,'fetch_status_metadata'=>false]); }
    /**
     * @param list<string> $domains
     * @return array<string,mixed>|null */
    public function fetchIsDomainPreviewable(array $domains): ?array { $domains=NewsletterInput::strings($domains,'domains',1000,false);foreach($domains as $domain) if(filter_var($domain,FILTER_VALIDATE_DOMAIN,FILTER_FLAG_HOSTNAME)===false || !str_contains($domain,'.')) throw new \InvalidArgumentException('Invalid channel preview domain');return $this->mex->run('FetchNewsletterIsDomainPreviewable',['url_domains'=>$domains]); }
    /**
     * @return array<string,mixed>|null */
    public function fetchDehydrated(string $key,bool $fetchWamoSub=false,string $viewRole='SUBSCRIBER'): ?array { $isJid=str_ends_with($key,'@newsletter');return $this->mex->run('FetchNewsletterDehydrated',['input'=>['key'=>$isJid?NewsletterInput::jid($key):NewsletterInput::invite($key),'type'=>$isJid ? 'JID' : 'INVITE','view_role'=>NewsletterInput::role($viewRole)],'fetch_wamo_sub'=>$fetchWamoSub,'fetch_pinned_messages'=>true]); }
    /**
     * @return array<string,mixed> */
    public function create(string $name,?string $description=null,?string $jpegPicture=null): array
    {
        $i=['name'=>NewsletterInput::text($name,'name',65536,false)]+($description === null ? [] : ['description'=>NewsletterInput::text($description,'description')])+($jpegPicture === null ? [] : ['picture'=>base64_encode(NewsletterInput::picture($jpegPicture))]);
        return self::requiredObject($this->mex->run('CreateNewsletter',['input'=>$i]),'xwa2_newsletter_create');
    }
    /**
     * @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function update(string $jid,array $input): array
    {
        NewsletterInput::jid($jid);NewsletterInput::keys($input,['name','description','picture','reactionCodesSetting'],['picture']);$u=[];
        foreach(['name','description'] as $key) if(array_key_exists($key,$input)) $u[$key]=NewsletterInput::text($input[$key],$key,65536,$key!=='name');
        if(array_key_exists('picture',$input)) $u['picture']=$input['picture'] === null ? '' : base64_encode(NewsletterInput::picture($input['picture']));
        if(array_key_exists('reactionCodesSetting',$input)) $u['settings']=['reaction_codes'=>['value'=>NewsletterInput::enum($input['reactionCodesSetting'],'reactionCodesSetting',['ALL','BASIC','NONE','BLOCKLIST'])]];
        if($u===[]) throw new \InvalidArgumentException('Empty newsletter update');
        return self::requiredObject($this->mex->run('UpdateNewsletter',['newsletter_id'=>$jid,'updates'=>$u]),'xwa2_newsletter_update');
    }
    /** @return array<string,mixed> */
    public function setName(string $jid,string $name): array { return $this->update($jid,['name'=>$name]); }
    /** @return array<string,mixed> */
    public function setDescription(string $jid,string $description): array { return $this->update($jid,['description'=>$description]); }
    /** @return array<string,mixed> */
    public function setPicture(string $jid,string $jpegPicture): array { return $this->update($jid,['picture'=>$jpegPicture]); }
    /** @return array<string,mixed> */
    public function removePicture(string $jid): array { return $this->update($jid,['picture'=>null]); }
    /** @return array<string,mixed> */
    public function setReactionCodes(string $jid,string $setting): array { return $this->update($jid,['reactionCodesSetting'=>$setting]); }
    public function delete(string $jid): void { self::requiredObject($this->admin('DeleteNewsletter',$jid),'xwa2_newsletter_delete_v2'); }
    public function follow(string $jid): void { self::requiredObject($this->admin('JoinNewsletter',$jid),'xwa2_newsletter_join_v2'); }
    public function unfollow(string $jid): void { self::requiredObject($this->admin('LeaveNewsletter',$jid),'xwa2_newsletter_leave_v2'); }
    public function mute(string $jid,bool $muted,bool $adminActivity=false): void { self::requiredObject($this->mex->run('UpdateNewsletterUserSetting',['input'=>['newsletter_id'=>NewsletterInput::jid($jid),'type'=>$adminActivity ? 'MUTE_ADMIN_ACTIVITY' : 'MUTE_FOLLOWER_ACTIVITY','value'=>$muted ? 'ON' : 'OFF']]),'xwa2_newsletter_update_user_setting'); }
    /**
     * @return array<string,mixed>|null */
    public function fetchAdminInfo(string $jid): ?array { return $this->admin('FetchNewsletterAdminInfo',$jid); }
    /**
     * @return array<string,mixed>|null */
    public function fetchAdminCapabilities(string $jid): ?array { return $this->admin('FetchNewsletterAdminCapabilities',$jid); }
    /**
     * @return array<string,mixed>|null */
    public function fetchFollowers(string $jid,int $count=50): ?array { return $this->mex->run('FetchNewsletterFollowers',['input'=>['newsletter_id'=>NewsletterInput::jid($jid),'count'=>NewsletterInput::integer($count,'count')]]); }
    /**
     * @param list<array<string,mixed>> $metrics
     * @return array<string,mixed>|null */
    public function fetchInsights(string $jid,array $metrics): ?array { if($metrics===[] || !array_is_list($metrics) || count($metrics)>1000) throw new \InvalidArgumentException('Metrics must be a nonempty list');foreach($metrics as $metric) if(!is_array($metric) || $metric===[] || array_is_list($metric)) throw new \InvalidArgumentException('Each channel metric must be an input object');return $this->mex->run('FetchNewsletterInsights',['input'=>['newsletter_id'=>NewsletterInput::jid($jid),'metrics'=>$metrics]]); }
    /**
     * @return array<string,mixed>|null */
    public function fetchReports(): ?array { return $this->mex->run('FetchNewsletterReports'); }
    /**
     * @return array<string,mixed>|null */
    public function fetchPendingInvites(string $jid): ?array { return $this->admin('FetchNewsletterPendingInvites',$jid); }
    /**
     * @return array<string,mixed>|null */
    public function fetchEnforcements(string $jid): ?array { return $this->admin('FetchNewsletterEnforcements',$jid); }
    /**
     * @return array<string,mixed>|null */
    public function fetchPollVoters(string $jid,int $serverId,string $voteHash,int $limit=50): ?array { self::serverId($serverId);NewsletterInput::text($voteHash,'voteHash',4096,false);return $this->mex->run('FetchNewsletterPollVoters',['input'=>['newsletter_id'=>NewsletterInput::jid($jid),'server_id'=>(string)$serverId,'vote_hash'=>$voteHash,'limit'=>NewsletterInput::integer($limit,'limit')]]); }
    /**
     * @return array<string,mixed>|null */
    public function fetchMessageReactionSenders(string $jid,int $serverId): ?array { self::serverId($serverId);return $this->mex->run('FetchNewsletterMessageReactionSenderList',['input'=>['id'=>NewsletterInput::jid($jid),'server_id'=>(string)$serverId]]); }
    public function changeOwner(string $jid,string $user): void { $this->admin('ChangeNewsletterOwner',$jid,['user_id'=>ServiceTransport::user($user)]); }
    public function demoteAdmin(string $jid,string $user): void { $this->admin('DemoteNewsletterAdmin',$jid,['user_id'=>ServiceTransport::user($user)]); }
    /**
     * @return array<string,mixed>|null */
    public function createAdminInvite(string $jid,string $user): ?array
    {
        NewsletterInput::jid($jid);$result=$this->admin('CreateNewsletterAdminInvite',$jid,['user_id'=>$this->adminInviteLid($user)]);
        $invite=self::requiredObject($result,'xwa2_newsletter_admin_invite_create');
        $expires=$invite['invite_expiration_time']??null;if((!is_string($expires)&&!is_int($expires))||!ctype_digit((string)$expires))throw new \UnexpectedValueException('Channel admin invitation expiration missing');
        return $result;
    }
    public function acceptAdminInvite(string $jid): void { $this->admin('AcceptNewsletterAdminInvite',$jid); }
    public function revokeAdminInvite(string $jid,string $user): void { NewsletterInput::jid($jid);$this->admin('RevokeNewsletterAdminInvite',$jid,['user_id'=>$this->adminInviteLid($user)]); }
    private function adminInviteLid(string $user):string
    {
        $pair=$this->transport->userPair($user);$lid=$pair['lid'];if(!is_string($lid)||!str_ends_with($lid,'@lid'))throw new \UnexpectedValueException('Channel admin invite requires a resolved LID');return $lid;
    }
    /**
     * @param list<string> $notices
     * @return array<string,mixed> */
    public function queryTosState(array $notices): array
    {
        $r=$this->tos('get',$notices); $t=$r->child('tos') ?? throw new \UnexpectedValueException('TOS envelope missing'); $n=[]; foreach(ServiceTransport::children($t,'notice') as $v) if(isset($v->attrs['id'])) $n[]=['id'=>$v->attrs['id'],'accepted'=>match($v->attrs['state'] ?? null){'true'=>true,'false'=>false,default=>null}]; return ['refreshSeconds'=>isset($t->attrs['refresh']) && ctype_digit($t->attrs['refresh'])?(int)$t->attrs['refresh']:null,'notices'=>$n];
    }
    /**
     * @param list<string> $notices */
    public function acceptTos(array $notices): void { $this->tos('set',$notices); }
    /**
     * @param list<string> $notices */
    private function tos(string $type,array $notices): BinaryNode { $notices=NewsletterInput::strings($notices,'notice IDs',1000,false); $n=[]; foreach(array_unique($notices) as $id) $n[]=new BinaryNode('notice',['id'=>$id]); return $this->transport->iq('newsletter.tos',$type,'s.whatsapp.net','tos',[new BinaryNode('request',$type==='set' ? ['type'=>'session_update'] : [],$n)]); }
    /**
     * @param array<string,mixed> $variables
     * @return array<string,mixed>|null */
    private function admin(string $op,string $jid,array $variables=[]): ?array { return $this->mex->run($op,['newsletter_id'=>NewsletterInput::jid($jid)]+$variables); }
    /** Pin and unpin use channel server IDs, not client stanza IDs.
     * @param list<int|string> $serverIds
     * @return array<string,mixed>|null */
    public function pinMessages(string $jid,array $serverIds): ?array { return $this->admin('NewsletterPinMessages',$jid,['input'=>['message_ids'=>self::pinIds($serverIds)]]); }
    /** @param list<int|string> $serverIds
     * @return array<string,mixed>|null */
    public function unpinMessages(string $jid,array $serverIds): ?array { return $this->admin('NewsletterUnpinMessages',$jid,['input'=>['message_ids'=>self::pinIds($serverIds)]]); }
    /** @return array<string,mixed>|null */
    public function addPaidPartnershipLabel(string $jid,int $serverId,string $messageType='MESSAGE'): ?array { return $this->label('NewsletterAddPaidPartnershipLabel',$jid,$serverId,$messageType); }
    /** @return array<string,mixed>|null */
    public function addAiContentLabel(string $jid,int $serverId,string $messageType='MESSAGE'): ?array { return $this->label('NewsletterLabelAiContent',$jid,$serverId,$messageType); }
    /** @return array<string,mixed>|null */
    private function label(string $operation,string $jid,int $serverId,string $messageType): ?array
    {
        self::serverId($serverId);return $this->admin($operation,$jid,['server_id'=>(string)$serverId,'message_type'=>NewsletterInput::enum($messageType,'messageType',['MESSAGE','STATUS'])]);
    }
    /** @param list<int|string> $serverIds
     * @return list<string> */
    private static function pinIds(array $serverIds): array
    {
        if(!array_is_list($serverIds) || $serverIds===[] || count($serverIds)>1000) throw new \InvalidArgumentException('Channel server IDs required');$ids=[];
        foreach($serverIds as $id) { if(!is_int($id) && (!is_string($id) || !preg_match('/^[1-9][0-9]*$/D',$id))) throw new \InvalidArgumentException('Invalid channel server ID');$integer=filter_var($id,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);if($integer===false) throw new \InvalidArgumentException('Invalid channel server ID');$ids[]=(string)$integer; }
        return array_values(array_unique($ids));
    }
    /** @param array<string,mixed>|null $data
     * @return array<string,mixed> */
    private static function requiredObject(?array $data,string $key): array
    {
        $object=$data[$key] ?? null;if(!is_array($object)) throw new \UnexpectedValueException('Channel response missing: '.$key);
        if(!is_string($object['id']??null)||$object['id']==='')throw new \UnexpectedValueException('Channel response identity missing: '.$key);
        $state=$object['state']['type'] ?? null;
        if(in_array($state,['DELETED','NON_EXISTING','SUSPENDED'],true)) throw new ServiceException('newsletter.'.$key,$state==='SUSPENDED'?'423':'404','channel state: '.$state);
        return $object;
    }
    /** Resolve a channel invite link or JID without changing subscription.
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function resolveChannel(string $jidOrInvite,array $options=[]): array { return str_ends_with($jidOrInvite,'@newsletter')?$this->fetch($jidOrInvite,$options):$this->fetchByInvite($jidOrInvite,$options); }
    public function inviteCode(string $jid): ?string { return self::normalizeMetadata($this->fetch($jid))['inviteCode']; }
    public function inviteUrl(string $jid): ?string { return self::normalizeMetadata($this->fetch($jid))['inviteUrl']; }
    /** Share a real channel as a follower invitation, without subscribing the recipient or inviting an admin.
     *
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function followerInviteContent(string $jid,array $options=[]):array
    {
        NewsletterInput::jid($jid);NewsletterInput::keys($options,['caption','jpegThumbnail']);
        $caption=NewsletterInput::text($options['caption']??'','caption',4096);
        $thumbnail=$options['jpegThumbnail']??null;
        if($thumbnail!==null&&(!is_string($thumbnail)||strlen($thumbnail)>1048576))throw new \InvalidArgumentException('Invalid channel invitation thumbnail');
        $metadata=self::normalizeMetadata($this->fetch($jid));
        if($metadata['jid']!==$jid||!is_string($metadata['name'])||trim($metadata['name'])==='')throw new \UnexpectedValueException('Channel invitation requires matching authenticated channel metadata');
        $body=['newsletterJid'=>$metadata['jid'],'newsletterName'=>$metadata['name'],'caption'=>$caption];
        if($thumbnail!==null)$body['jpegThumbnail']=$thumbnail;
        return ['type'=>'newsletter-follower-invite','data'=>$body];
    }
    /** Adds stable accessors without dropping native metadata or manufacturing missing values.
     * @param array<string,mixed> $metadata
     * @return array{jid:?string,name:?string,description:?string,subscribers:int|string|null,createdAt:int|string|null,picture:?array<string,mixed>,preview:?array<string,mixed>,inviteCode:?string,inviteUrl:?string,viewer:?array<string,mixed>,settings:?array<string,mixed>,raw:array<string,mixed>} */
    public static function normalizeMetadata(array $metadata): array
    {
        $thread=is_array($metadata['thread_metadata'] ?? null)?$metadata['thread_metadata']:[];$viewer=is_array($metadata['viewer_metadata'] ?? null)?$metadata['viewer_metadata']:null;
        $jid=is_string($metadata['id'] ?? null)?$metadata['id']:null;if($jid!==null && ctype_digit($jid)) $jid.='@newsletter';
        if($jid!==null && !preg_match('/^[0-9]+@newsletter$/D',$jid)) $jid=null;
        $name=is_array($thread['name'] ?? null)?($thread['name']['text'] ?? null):($thread['name'] ?? null);$description=is_array($thread['description'] ?? null)?($thread['description']['text'] ?? null):($thread['description'] ?? null);
        $invite=is_string($thread['invite'] ?? null)?$thread['invite']:null;$subscribers=$thread['subscribers_count'] ?? null;$created=$thread['creation_time'] ?? null;
        return ['jid'=>$jid,'name'=>is_string($name)?$name:null,'description'=>is_string($description)?$description:null,'subscribers'=>is_int($subscribers)||is_string($subscribers)?$subscribers:null,'createdAt'=>is_int($created)||is_string($created)?$created:null,'picture'=>is_array($thread['picture'] ?? null)?$thread['picture']:null,'preview'=>is_array($thread['preview'] ?? null)?$thread['preview']:null,'inviteCode'=>$invite,'inviteUrl'=>$invite===null?null:'https://whatsapp.com/channel/'.rawurlencode($invite),'viewer'=>$viewer,'settings'=>is_array($thread['settings'] ?? null)?$thread['settings']:null,'raw'=>$metadata];
    }
    /** Public text/poll/pre-uploaded plaintext media.
     * @param string|array<string,mixed> $content
     * @return array<string,mixed> */
    public function send(string $jid,string|array $content,?string $id=null): array { return $this->publish($jid,$content,$id ?? self::id(),false); }
    /** Photo polls use the public channel producer, including associated plaintext image children.
     * @param array<string,mixed> $content
     * @return array<string,mixed> */
    public function sendImagePoll(string $jid,array $content,?string $id=null):array
    {
        NewsletterInput::jid($jid);
        $sender=new \MVO\Message\ImagePollSender(
            function(string $channel,array $message,array $options):array{
                $messageId=$options['id'];self::messageId($messageId);
                $child=isset($message['pollCreationOptionImageMessage']);
                if($child){
                    $association=$message['messageContextInfo']['messageAssociation']??null;
                    if(!is_array($association)||($association['associationType']??null)!=='MEDIA_POLL'||($association['parentMessageKey']['remoteJid']??null)!==$channel)
                        throw new \InvalidArgumentException('Photo poll image has no matching channel parent');
                    $plain=new BinaryNode('plaintext',['mediatype'=>'image'],SchemaCodec::encode('Message',$message));
                    $meta=new BinaryNode('meta',['message_association_type'=>'media_poll']);$type='media';
                }else{
                    $plain=new BinaryNode('plaintext',[],SchemaCodec::encode('Message',$message));
                    $meta=new BinaryNode('meta',['polltype'=>'creation','contenttype'=>'image']);$type='poll';
                }
                return $this->publishStanza(new BinaryNode('message',['to'=>$channel,'id'=>$messageId,'type'=>$type],[$plain,$meta]));
            },
            $this->prepareMedia??throw new \LogicException('Newsletter media uploader is not configured'),
        );
        return $sender->send($jid,$content,$id===null?[]:['id'=>$id]);
    }
    /**
     * @param string|array<string,mixed> $content
     * @return array<string,mixed> */
    public function editMessage(string $jid,string $parentId,string|array $content): array { return $this->publish($jid,$content,$parentId,true); }
    /** Publish a question as a channel text or media post.
     * @param string|array<string,mixed> $content
     * @return array<string,mixed> */
    public function sendQuestion(string $jid,string|array $content,?string $id=null): array { return $this->publish($jid,$content,$id ?? self::id(),false,['questiontype'=>'question'],['isQuestion'=>true]); }
    /** Respond to a question using a new response client ID; the envelope references the parent.
     * @return array<string,mixed> */
    public function respondQuestion(string $jid,int $questionServerId,string $text,?string $id=null): array
    {
        NewsletterInput::jid($jid);self::serverId($questionServerId);NewsletterInput::text($text,'question response',65536,false);$id=$id ?? self::id();self::messageId($id);
        $proto=['questionResponseMessage'=>['key'=>['remoteJid'=>$jid,'fromMe'=>true,'id'=>$id],'text'=>$text]];
        return $this->publishStanza(new BinaryNode('message',['to'=>$jid,'id'=>$id,'server_id'=>(string)$questionServerId,'type'=>'text'],[new BinaryNode('plaintext',[],SchemaCodec::encode('Message',$proto)),new BinaryNode('meta',['questiontype'=>'response'])]));
    }
    /** Reshare a response as an administrator, preserving the question/response IDs.
     * @param string|array<string,mixed> $content
     * @param array<string,mixed> $quotedQuestion Original WAProto Message of the question.
     * @param array<string,mixed> $quotedResponse Original WAProto Message of the response.
     * @return array<string,mixed> */
    public function reshareQuestionResponse(string $jid,int $questionServerId,string $responseServerId,string|array $content,array $quotedQuestion,array $quotedResponse,?string $id=null): array
    {
        NewsletterInput::jid($jid);NewsletterInput::integer($questionServerId,'questionServerId',1,2147483647);NewsletterInput::text($responseServerId,'responseServerId',128,false);
        $question=ContentDecoder::unwrap($quotedQuestion)['message'];$response=ContentDecoder::unwrap($quotedResponse)['message'];
        if($question===[] || isset($question['questionResponseMessage'])) throw new \InvalidArgumentException('The quoted question must contain its original post');
        $body=$response['questionResponseMessage'] ?? null;if(!is_array($body) || !is_array($body['key'] ?? null) || ($body['key']['remoteJid'] ?? null)!==$jid) throw new \InvalidArgumentException('The quoted response must contain a channel response key');
        self::messageId(NewsletterInput::text($body['key']['id'] ?? null,'response key ID',128,false));NewsletterInput::text($body['text'] ?? null,'quoted response text',65536,false);
        if(array_key_exists('fromMe',$body['key'])) NewsletterInput::boolean($body['key']['fromMe'],'response fromMe');
        // Validate complete quoted protobufs before any media upload or message send.
        SchemaCodec::encode('Message',$quotedQuestion);SchemaCodec::encode('Message',$quotedResponse);
        return $this->publish($jid,$content,$id ?? self::id(),false,['questiontype'=>'reply','parent_server_id'=>(string)$questionServerId,'response_server_id'=>$responseServerId],['questionReplyQuotedMessage'=>['serverQuestionId'=>$questionServerId,'quotedQuestion'=>$quotedQuestion,'quotedResponse'=>$quotedResponse]]);
    }
    /** Publish an explicit, non-encrypted result snapshot. Counts come from the caller.
     * @param list<array{optionName:string,optionVoteCount:int}> $pollVotes
     * @return array<string,mixed> */
    public function sendPollResultSnapshot(string $jid,string $name,array $pollVotes,?string $id=null,bool $version3=false): array
    {
        NewsletterInput::text($name,'poll name',65536,false);if(!array_is_list($pollVotes) || $pollVotes===[] || count($pollVotes)>12) throw new \InvalidArgumentException('Poll snapshot requires 1..12 options');
        $votes=[];foreach($pollVotes as $vote) { if(!is_array($vote)) throw new \InvalidArgumentException('Invalid poll snapshot option');NewsletterInput::keys($vote,['optionName','optionVoteCount']);$votes[]=['optionName'=>NewsletterInput::text($vote['optionName'] ?? null,'optionName',65536,false),'optionVoteCount'=>NewsletterInput::integer($vote['optionVoteCount'] ?? null,'optionVoteCount',0,PHP_INT_MAX)]; }
        return $this->publish($jid,['type'=>'raw','message'=>[$version3?'pollResultSnapshotMessageV3':'pollResultSnapshotMessage'=>['name'=>$name,'pollVotes'=>$votes]]],$id ?? self::id(),false);
    }
    /**
     * @param string|array<string,mixed> $content
     * @param array<string,string> $metadata
     * @param array<string,mixed> $channelContext
     * @return array<string,mixed> */
    private function publish(string $jid,string|array $content,string $id,bool $edit,array $metadata=[],array $channelContext=[]): array
    {
        NewsletterInput::jid($jid); self::messageId($id);
        if(is_array($content) && (($content['viewOnce'] ?? false)!==false || (isset($content['ephemeralExpiration']) && $content['ephemeralExpiration']!==0))) throw new \InvalidArgumentException('Channel posts do not support disappearing or view-once content');
        if (is_array($content) && in_array($content['type'] ?? '',['image','video','gif','ptv','audio','ptt','document','sticker','sticker-pack'],true)) {
            $content=['type'=>'raw','message'=>($this->prepareMedia ?? throw new \LogicException('Newsletter media uploader is not configured'))($content)];
        }
        $built=ContentBuilder::build($content,$jid,['outgoingId'=>$id]); $proto=ContentDecoder::unwrap($built['proto'])['message'];
        if(isset($proto['conversation'])) { $proto['extendedTextMessage']=['text'=>$proto['conversation']]; unset($proto['conversation']); }
        $supported=['extendedTextMessage','imageMessage','videoMessage','ptvMessage','audioMessage','documentMessage','stickerMessage','stickerPackMessage','contactMessage','pollCreationMessage','pollCreationMessageV2','pollCreationMessageV3','pollCreationMessageV5','pollCreationMessageV6','pollResultSnapshotMessage','pollResultSnapshotMessageV3','messageContextInfo'];
        foreach(array_keys($proto) as $field) if(!in_array($field,$supported,true)) throw new \InvalidArgumentException('Unsupported channel payload: '.$field);
        $type='text';$nodes=[];$media=null;$payloads=isset($proto['extendedTextMessage'])?1:0;
        foreach(['imageMessage'=>'image','videoMessage'=>'video','ptvMessage'=>'ptv','audioMessage'=>'audio','documentMessage'=>'document','stickerMessage'=>'sticker','stickerPackMessage'=>'sticker_pack'] as $field=>$mediaType) if(isset($proto[$field])) { ++$payloads;$media=$field==='audioMessage' && !empty($proto[$field]['ptt'])?'ptt':($field==='videoMessage' && !empty($proto[$field]['gifPlayback'])?'gif':$mediaType); if(!empty($proto[$field]['mediaKey'])) throw new \InvalidArgumentException('Newsletter media must be uploaded plaintext; encrypted direct media cannot be reused'); if(empty($proto[$field]['url']) && empty($proto[$field]['directPath'])) throw new \InvalidArgumentException('Newsletter requires an uploaded media descriptor'); $type='media'; }
        if(isset($proto['contactMessage'])) {++$payloads;$media='vcard';$type='media';}
        foreach(['pollCreationMessage','pollCreationMessageV2','pollCreationMessageV3','pollCreationMessageV5','pollCreationMessageV6'] as $field) if(isset($proto[$field])) { ++$payloads;if($edit) throw new \InvalidArgumentException('Newsletter polls cannot be edited'); $type='poll';$nodes[]=new BinaryNode('meta',['polltype'=>'creation']); }
        foreach(['pollResultSnapshotMessage','pollResultSnapshotMessageV3'] as $field) if(isset($proto[$field])) {++$payloads;if($edit) throw new \InvalidArgumentException('Channel poll snapshots cannot be edited');$type='poll';$nodes[]=new BinaryNode('meta',['polltype'=>'result_snapshot']);}
        if($type==='text' && !isset($proto['extendedTextMessage'])) throw new \InvalidArgumentException('Unsupported newsletter content');
        if($payloads!==1) throw new \InvalidArgumentException('A channel post must contain exactly one content payload');
        if($metadata!==[]) { if($type==='poll') throw new \InvalidArgumentException('Channel questions require text or media content');$nodes[]=new BinaryNode('meta',$metadata); }
        // Channel posts do not carry direct-chat message-secret or DeviceSent wrappers.
        unset($proto['messageContextInfo']);
        if($channelContext!==[]) { foreach($proto as $field=>&$payload) if(is_array($payload)) $payload['contextInfo']=array_replace(is_array($payload['contextInfo'] ?? null)?$payload['contextInfo']:[],$channelContext);unset($payload);$proto=[isset($channelContext['isQuestion'])?'questionMessage':'questionReplyMessage'=>['message'=>$proto]]; }
        array_unshift($nodes,new BinaryNode('plaintext',$media===null ? [] : ['mediatype'=>$media],SchemaCodec::encode('Message',$proto)));
        $attrs=['to'=>$jid,'id'=>$id,'type'=>$type]+($edit ? ['edit'=>'3'] : []); return $this->publishStanza(new BinaryNode('message',$attrs,$nodes));
    }
    /** Server IDs and timestamps are exposed only when supplied by the server ACK.
     * @return array<string,mixed> */
    private function publishStanza(BinaryNode $node): array
    {
        $result=$this->transport->publishNode($node);$ack=$result['ack'];
        foreach(['server_id'=>'serverId','t'=>'timestampSeconds'] as $attribute=>$field) { $value=$ack->attrs[$attribute] ?? null;if($value!==null && preg_match('/^[0-9]+$/D',$value)) { $integer=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>$attribute==='server_id'?1:0]]);if($integer!==false) $result[$field]=$integer; } }
        if(is_string($ack->attrs['response_server_id']??null))$result['responseServerId']=$ack->attrs['response_server_id'];
        if($this->recordPublication!==null)($this->recordPublication)($node,$ack);
        return $result;
    }
    /** Transport accepted, not server acknowledgement.
     * @return array{stanzaId:string,status:string} */
    public function react(string $jid,int $serverId,string $emoji,bool $revoke=false): array
    {
        self::serverId($serverId);if(!$revoke) NewsletterInput::text($emoji,'reaction',256,false);$id=self::id();$this->transport->send(new BinaryNode('message',['to'=>NewsletterInput::jid($jid),'id'=>$id,'server_id'=>(string)$serverId,'type'=>'reaction']+($revoke ? ['edit'=>'7'] : []),[new BinaryNode('reaction',$revoke ? [] : ['code'=>$emoji])]));return ['stanzaId'=>$id,'status'=>'sent'];
    }
    /** @return array{stanzaId:string,status:string} */
    public function revokeReaction(string $jid,int $serverId): array { return $this->react($jid,$serverId,'',true); }
    /**
     * @return array<string,mixed> */
    public function revoke(string $jid,string $messageId): array { self::messageId($messageId); return $this->publishStanza(new BinaryNode('message',['to'=>NewsletterInput::jid($jid),'id'=>$messageId,'type'=>'text','edit'=>'8'],[new BinaryNode('plaintext')])); }
    /**
     * @param list<string> $votes Native SHA256 option hashes.
     * @return array{stanzaId:string,status:string} */
    public function votePoll(string $jid,int $serverId,array $votes,?string $contentType=null): array
    {
        self::serverId($serverId);if(!array_is_list($votes) || count($votes)>1000) throw new \InvalidArgumentException('Channel votes must be a list');$nodes=[]; foreach($votes as $v) { if(!is_string($v) || strlen($v)!==32) throw new \InvalidArgumentException('Option vote must contain a SHA256 hash');$nodes[]=new BinaryNode('vote',[],$v); }if($contentType!==null) NewsletterInput::text($contentType,'contentType',256,false);$id=self::id();$this->transport->send(new BinaryNode('message',['to'=>NewsletterInput::jid($jid),'id'=>$id,'server_id'=>(string)$serverId,'type'=>'poll'],[new BinaryNode('votes',[],$nodes),new BinaryNode('meta',['polltype'=>'vote']+($contentType === null ? [] : ['contenttype'=>$contentType]))]));return ['stanzaId'=>$id,'status'=>'sent'];
    }
    /**
     * @param list<int> $serverIds
     * @return array{stanzaId:string,status:string} */
    public function sendViewReceipt(string $jid,array $serverIds): array { if($serverIds===[] || !array_is_list($serverIds) || count($serverIds)>1000) throw new \InvalidArgumentException('View ids must be a nonempty list');$n=[];foreach($serverIds as $sid) { NewsletterInput::integer($sid,'serverId',1,PHP_INT_MAX);$n[]=new BinaryNode('item',['server_id'=>(string)$sid]); }$id=self::id();$this->transport->send(new BinaryNode('receipt',['to'=>NewsletterInput::jid($jid),'id'=>$id,'type'=>'view'],[new BinaryNode('list',[],$n)]));return ['stanzaId'=>$id,'status'=>'sent']; }
    public function fetchMessages(string $jid,int $count=50,?int $before=null,?int $after=null,?string $viewRole=null): BinaryNode
    {
        self::count($count);$a=['type'=>'jid','jid'=>NewsletterInput::jid($jid),'count'=>(string)$count]+($viewRole === null ? [] : ['view_role'=>NewsletterInput::role($viewRole)])+self::range($before,$after);return $this->transport->iq('newsletter.fetchMessages','get','s.whatsapp.net','newsletter',[new BinaryNode('messages',$a)]);
    }
    public function fetchMessageUpdates(string $jid,int $count=50,?int $since=null,?int $before=null,?int $after=null): BinaryNode
    {
        self::count($count);if($before===null && $after===null) throw new \InvalidArgumentException('Before/after required');if($since!==null) NewsletterInput::integer($since,'since',0,PHP_INT_MAX);$a=['count'=>(string)$count]+self::range($before,$after)+($since === null ? [] : ['since'=>(string)$since]);return $this->transport->iq('newsletter.fetchMessageUpdates','get',NewsletterInput::jid($jid),'newsletter',[new BinaryNode('message_updates',$a)]);
    }
    /** Channel status history has its own public IQ and a maximum page size of 100. */
    public function fetchStatuses(string $jid,int $count=50,?int $before=null,?int $after=null,?string $viewRole=null): BinaryNode
    {
        NewsletterInput::integer($count,'count',1,100);$attrs=['type'=>'jid','jid'=>NewsletterInput::jid($jid),'count'=>(string)$count]+($viewRole===null?[]:['view_role'=>NewsletterInput::role($viewRole)])+self::range($before,$after);
        return $this->transport->iq('newsletter.fetchStatuses','get','s.whatsapp.net','newsletter',[new BinaryNode('statuses',$attrs)]);
    }
    /** Fetch and project native posts, updates and errors. No implicit view receipt is sent.
     * @return array<array-key,array<string,mixed>> */
    public function fetchMessagesDecoded(string $jid,int $count=50,?int $before=null,?int $after=null,?string $viewRole=null):array
    {return $this->decodeResponse($this->fetchMessages($jid,$count,$before,$after,$viewRole),$jid);}
    /** @return array<array-key,array<string,mixed>> */
    public function fetchMessageUpdatesDecoded(string $jid,int $count=50,?int $since=null,?int $before=null,?int $after=null):array
    {return $this->decodeResponse($this->fetchMessageUpdates($jid,$count,$since,$before,$after),$jid);}
    /** Channel statuses have a separate local namespace from ordinary posts.
     * @return array<array-key,array<string,mixed>> */
    public function fetchStatusesDecoded(string $jid,int $count=50,?int $before=null,?int $after=null,?string $viewRole=null):array
    {return $this->decodeResponse($this->fetchStatuses($jid,$count,$before,$after,$viewRole),$jid);}
    /** @return array<array-key,array<string,mixed>> */
    private function decodeResponse(BinaryNode $node,string $jid):array
    {return $this->ingestMessages===null?IncomingEvents::parseNewsletterResponse($node,$jid):($this->ingestMessages)($node,$jid);}
    /** Local session history only; newest first, exclusive timestamp cursor.
     * @return list<array<string,mixed>> */
    public function listLocalMessages(string $jid,int $limit=50,?int $beforeTimestamp=null):array
    {NewsletterInput::jid($jid);return $this->local()->listMessages($jid,$limit,$beforeTimestamp);}
    /** Native scheduling metadata observed in this session; no remote creation or discovery is inferred.
     * @return list<array<string,mixed>> */
    public function listObservedSchedules(string $jid,int $limit=50,string $after=''):array
    {return $this->local()->listObservedSchedules($jid,$limit,$after);}
    /** @return array<string,mixed>|null */
    public function getLocalMessage(string $jid,string $id,?int $serverId=null):?array
    {NewsletterInput::jid($jid);return $this->local()->getMessage($jid,$id,$serverId);}
    /** Download a known, active channel post; URLs and descriptors cannot be supplied by callers. */
    public function downloadMessageMedia(string $jid,string $id,?int $serverId=null):string
    {
        $record=$this->getLocalMessage($jid,$id,$serverId);
        if($record===null||($record['revoked']??false)===true)throw new \OutOfBoundsException('Channel post not found or revoked');
        $proto=ContentDecoder::unwrap($record['proto']??[])['message'];
        $payload=\MVO\Media\MediaTransfer::resolveNewsletterPayload($proto);
        if($payload===null)throw new \InvalidArgumentException('Channel post contains no downloadable media');
        return ($this->downloadMedia??throw new \LogicException('Channel media downloader is not configured'))($payload);
    }
    private function local():ChannelStore {return ($this->channelStore??throw new \LogicException('Channel history is not configured'))();}
    /**
     * @return array{durationSeconds:int} */
    public function subscribeLiveUpdates(string $jid): array { $n=$this->transport->iq('newsletter.subscribeLiveUpdates','set',NewsletterInput::jid($jid),'newsletter',[new BinaryNode('live_updates')])->child('live_updates');$duration=$n?->attrs['duration'] ?? '';if(!ctype_digit($duration)) throw new \UnexpectedValueException('Invalid live-update subscription duration');$d=(int)$duration;if($d<30 || $d>600) throw new \UnexpectedValueException('Invalid live-update subscription duration');return ['durationSeconds'=>$d]; }
    /**
     * @return array<string,string> */
    private static function range(?int $before,?int $after): array { if($before!==null && $after!==null) throw new \InvalidArgumentException('Use either before or after, never both');if($before!==null) { self::serverId($before);return ['before'=>(string)$before]; }if($after!==null) { self::serverId($after);return ['after'=>(string)$after]; }return []; }
    private static function count(int $count): void { if($count<1 || $count>1000) throw new \InvalidArgumentException('Count must be 1..1000'); }
    private static function serverId(int $id): void { if($id<1) throw new \InvalidArgumentException('Server id must be positive'); }
    private static function messageId(string $id): void { if(!preg_match('/^[A-Za-z0-9_-]{1,128}$/D',$id)) throw new \InvalidArgumentException('Invalid message id'); }
    private static function id(): string { return '3EB0'.strtoupper(bin2hex(random_bytes(10))); }
}
