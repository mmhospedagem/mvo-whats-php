<?php
declare(strict_types=1);
namespace MVO\Service;
use MVO\Protocol\BinaryNode;
use MVO\AppState\AppStateService;

/** Lazy service facade. It reuses the client's existing session, query routing and publisher. */
final class Services
{
    private ServiceTransport $transport;
    private MexService $mex;
    private ?PresenceService $presence=null;
    private ?GroupService $group=null;
    private ?CommunityService $community=null;
    private ?ProfileService $profile=null;
    private ?PrivacyService $privacy=null;
    private ?BusinessService $business=null;
    private ?CatalogService $catalog=null;
    private ?NewsletterService $newsletter=null;
    private ?EmailService $email=null;
    private ?StatusService $status=null;
    /** @var (\Closure(string|list<string>,string|array<string,mixed>,array<string,mixed>):array<string,mixed>)|null */
    private ?\Closure $groupStatusPublisher=null;
    /** @var (\Closure(string,string):array<string,mixed>)|null */
    private ?\Closure $groupStatusRevoker=null;
    private ?BroadcastListService $broadcast=null;
    private ?BotService $bot=null;
    /** @var \Closure():AppStateService|null */
    private ?\Closure $appState;
    /** @var \Closure(string,string|array<string,mixed>,list<string>,array<string,mixed>):array<string,mixed>|null */
    private ?\Closure $broadcastPublisher;
    /** @var \Closure(string):void|null */
    private ?\Closure $applyOwnPushName;
    /** @var \Closure(string,string|array<string,mixed>,array<string,mixed>):array<string,mixed>|null */
    private ?\Closure $sendBotPrompt;
    /** @var (\Closure(array<string,mixed>):array<string,mixed>)|null */
    private ?\Closure $prepareNewsletter;
    /** @var (\Closure(string|array<string,mixed>):string)|null */
    private ?\Closure $uploadProductImage;
    /** @var (\Closure(mixed):array<string,mixed>)|null */
    private ?\Closure $uploadBusinessCover;
    /** @var (\Closure():\MVO\History\StatusStore)|null */
    private ?\Closure $statusStore;
    /** @var (\Closure(list<array<string,mixed>>,string):void)|null */
    private ?\Closure $statusReceipt;
    /** @var (\Closure(array<string,mixed>):string)|null */
    private ?\Closure $statusDownload;
    /** @var (\Closure(BinaryNode,string):array<array-key,array<string,mixed>>)|null */
    private ?\Closure $ingestChannelMessages;
    /** @var (\Closure():\MVO\History\ChannelStore)|null */
    private ?\Closure $channelStore;
    /** @var (\Closure(array<string,mixed>):string)|null */
    private ?\Closure $downloadChannelMedia;
    /** @var (\Closure(BinaryNode,BinaryNode):void)|null */
    private ?\Closure $recordChannelPublication;
    /** @param callable(BinaryNode,float):BinaryNode $query
     * @param callable(BinaryNode):void $send
     * @param callable():array<string,mixed> $credentials
     * @param callable(string,string|array<string,mixed>):array<string,mixed>|null $sendMessage
     * @param callable():AppStateService|null $appState
     * @param callable(string,string|array<string,mixed>,list<string>,array<string,mixed>):array<string,mixed>|null $sendBroadcast
     * @param callable(string):void|null $applyOwnPushName
     * @param callable(string,string|array<string,mixed>,array<string,mixed>):array<string,mixed>|null $sendBotPrompt
     * @param callable(array<string,mixed>):array<string,mixed>|null $prepareNewsletter
     * @param callable(string|array<string,mixed>):string|null $uploadProductImage
     * @param callable(mixed):array<string,mixed>|null $uploadBusinessCover
     * @param (callable():\MVO\History\StatusStore)|null $statusStore
     * @param (callable(list<array<string,mixed>>,string):void)|null $statusReceipt
     * @param (callable(array<string,mixed>):string)|null $statusDownload
     * @param (callable(BinaryNode,string):array<array-key,array<string,mixed>>)|null $ingestChannelMessages
     * @param (callable():\MVO\History\ChannelStore)|null $channelStore
     * @param (callable(array<string,mixed>):string)|null $downloadChannelMedia
     * @param (callable(BinaryNode,BinaryNode):void)|null $recordChannelPublication */
    public function __construct(callable $query,callable $send,callable $credentials,?callable $sendMessage=null,?callable $appState=null,?callable $sendBroadcast=null,?callable $applyOwnPushName=null,?callable $sendBotPrompt=null,?callable $prepareNewsletter=null,?callable $uploadProductImage=null,?callable $uploadBusinessCover=null,?callable $statusStore=null,?callable $statusReceipt=null,?callable $statusDownload=null,?callable $ingestChannelMessages=null,?callable $channelStore=null,?callable $downloadChannelMedia=null,?callable $recordChannelPublication=null)
    {
        $this->transport=new ServiceTransport($query,$send,$credentials,$sendMessage);$this->mex=new MexService($this->transport);
        $this->appState=$appState === null ? null : \Closure::fromCallable($appState);$this->broadcastPublisher=$sendBroadcast === null ? null : \Closure::fromCallable($sendBroadcast);
        $this->applyOwnPushName=$applyOwnPushName===null ? null : \Closure::fromCallable($applyOwnPushName);
        $this->sendBotPrompt=$sendBotPrompt===null ? null : \Closure::fromCallable($sendBotPrompt);
        $this->prepareNewsletter=$prepareNewsletter===null ? null : \Closure::fromCallable($prepareNewsletter);
        $this->uploadProductImage=$uploadProductImage===null?null:\Closure::fromCallable($uploadProductImage);
        $this->uploadBusinessCover=$uploadBusinessCover===null?null:\Closure::fromCallable($uploadBusinessCover);
        $this->statusStore=$statusStore===null?null:\Closure::fromCallable($statusStore);$this->statusReceipt=$statusReceipt===null?null:\Closure::fromCallable($statusReceipt);$this->statusDownload=$statusDownload===null?null:\Closure::fromCallable($statusDownload);
        $this->ingestChannelMessages=$ingestChannelMessages===null?null:\Closure::fromCallable($ingestChannelMessages);$this->channelStore=$channelStore===null?null:\Closure::fromCallable($channelStore);$this->downloadChannelMedia=$downloadChannelMedia===null?null:\Closure::fromCallable($downloadChannelMedia);
        $this->recordChannelPublication=$recordChannelPublication===null?null:\Closure::fromCallable($recordChannelPublication);
    }
    public function presence(): PresenceService { return $this->presence ??= new PresenceService($this->transport); }
    public function configurePrivacyTokens(TrustedContactTokenService $service):void {$this->transport->configurePrivacyTokens($service);}
    public function configureIdentities(IdentityService $service):void {$this->transport->configureIdentities($service);}
    public function group(): GroupService { return $this->group ??= new GroupService($this->transport,$this->mex); }
    public function community(): CommunityService { return $this->community ??= new CommunityService($this->group(),$this->profile()); }
    public function profile(): ProfileService { return $this->profile ??= new ProfileService($this->transport,$this->mex,$this->appState,$this->applyOwnPushName); }
    public function privacy(): PrivacyService { return $this->privacy ??= new PrivacyService($this->transport); }
    public function business(): BusinessService { return $this->business ??= new BusinessService($this->transport,$this->catalog(),$this->uploadBusinessCover); }
    public function catalog(): CatalogService { return $this->catalog ??= new CatalogService($this->transport,$this->uploadProductImage); }
    public function newsletter(): NewsletterService { return $this->newsletter ??= new NewsletterService($this->transport,$this->mex,$this->prepareNewsletter,$this->ingestChannelMessages,$this->channelStore,$this->downloadChannelMedia,$this->recordChannelPublication); }
    public function channels():NewsletterService {return $this->newsletter();}
    public function email(): EmailService { return $this->email ??= new EmailService($this->transport); }
    public function bot(): BotService { return $this->bot ??= new BotService($this->transport,$this->sendBotPrompt); }
    public function mex(): MexService { return $this->mex; }
    /** @param callable(string|list<string>,string|array<string,mixed>,array<string,mixed>):array<string,mixed> $publisher
     * @param callable(string,string):array<string,mixed> $revoker */
    public function configureGroupStatusPublishing(callable $publisher,callable $revoker):void
    {
        $this->groupStatusPublisher=\Closure::fromCallable($publisher);$this->groupStatusRevoker=\Closure::fromCallable($revoker);
        if($this->status!==null)$this->status->configureGroupPublishing($publisher,$revoker);
    }
    public function status(): StatusService {
        if($this->status===null){
            $this->status=new StatusService($this->appState ?? throw new \LogicException('App-state is not configured'),$this->broadcastPublisher ?? throw new \LogicException('Broadcast publisher is not configured'),$this->statusStore,$this->statusReceipt,$this->transport->publish(...),$this->statusDownload);
            if($this->groupStatusPublisher!==null&&$this->groupStatusRevoker!==null)$this->status->configureGroupPublishing($this->groupStatusPublisher,$this->groupStatusRevoker);
        }
        return $this->status;
    }
    public function broadcastList(): BroadcastListService { return $this->broadcast ??= new BroadcastListService($this->appState ?? throw new \LogicException('App-state is not configured'),$this->broadcastPublisher ?? throw new \LogicException('Broadcast publisher is not configured')); }
}
