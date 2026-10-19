<?php
declare(strict_types=1);
namespace MVO\Facebook;

use MVO\Facebook\Catalog\{AdsDocuments,PageDocuments};
use MVO\Facebook\Monitoring\SnapshotMonitor;
use MVO\Facebook\Protocol\{DocumentRegistry,WebGraphQL};
use MVO\Facebook\Service\{AdsService,CommentsService,InboxService,PagesService,PostsService,ReactionsService};
use MVO\Facebook\Storage\{CommandLedger,SelectionStore};
use MVO\Messenger\Auth\WebSession;

/** Optional Facebook services; the supplied session retains its original owner. */
final class FacebookClient
{
    private readonly DocumentRegistry $documents;
    private readonly WebGraphQL $graph;
    private readonly SelectionStore $selections;
    private readonly CommandLedger $commands;
    private readonly string $directory;
    private bool $closed=false;

    public function __construct(private readonly WebSession $session,string $privateStorageDirectory,?DocumentRegistry $documents=null)
    {
        $account=$session->accountId();if(!preg_match('/^[1-9][0-9]{0,24}$/D',$account))throw new \InvalidArgumentException('Expected the authenticated Facebook account ID');
        $this->directory=rtrim($privateStorageDirectory,'/\\').DIRECTORY_SEPARATOR.$account;
        if(!is_dir($this->directory)&&!mkdir($this->directory,0700,true)&&!is_dir($this->directory))throw new \RuntimeException('Unable to create private Facebook service storage');
        $this->documents=$documents??new DocumentRegistry([...PageDocuments::documents(),...AdsDocuments::documents()]);
        $this->commands=new CommandLedger($this->directory.'/commands.sqlite');
        $this->selections=new SelectionStore($this->directory.'/selections.sqlite',$account);
        $this->graph=new WebGraphQL($session,$this->documents,$this->commands);
    }
    public function accountId():string{return $this->session->accountId();}
    public function pages():PagesService{$this->requireOpen();return new PagesService($this->session,$this->selections);}
    public function posts():PostsService{$this->requireOpen();return new PostsService($this->graph);}
    public function comments():CommentsService{$this->requireOpen();return new CommentsService($this->graph);}
    public function reactions():ReactionsService{$this->requireOpen();return new ReactionsService($this->graph);}
    public function inbox():InboxService{$this->requireOpen();return new InboxService($this->graph);}
    public function ads():AdsService{$this->requireOpen();return new AdsService($this->graph);}
    /** @return array<string,mixed> */
    public function listPages():array{return $this->pages()->list();}
    /** @return array<string,mixed> */
    public function listAdAccounts():array
    {
        $result=$this->ads()->accounts();
        if(!is_array($result['items']??null)||!array_is_list($result['items']))throw new FacebookException('facebook_invalid_response','The ad-account query did not return a normalized collection');
        $this->selections->remember('ad_account',$result['items']);return $result;
    }
    /** Local preference only; it does not change cookies or remotely switch profiles. */
    public function selectPage(string $id):void{$this->selections->select('page',$id);}
    public function selectAdAccount(string $id):void{$this->selections->select('ad_account',$id);}
    /** @return array{account_id:string,page:?array<string,mixed>,ad_account:?array<string,mixed>} */
    public function selectedContext():array{return ['account_id'=>$this->accountId(),'page'=>$this->selections->selected('page'),'ad_account'=>$this->selections->selected('ad_account')];}
    public function monitor():SnapshotMonitor{$this->requireOpen();return new SnapshotMonitor($this->directory.'/monitor.sqlite');}
    /** @return array<string,mixed>|null */
    public function command(string $id):?array{return $this->commands->get($id);}
    /** Observed protocol documents, not a claim of account permission or live success.
     * @return list<array{name:string,kind:string,source_sha256:string}>
     */
    public function documentInventory():array{return $this->documents->inventory();}
    /** Release only this service storage; the Messenger session retains its own lifecycle. */
    public function close():void{$this->commands->close();$this->selections->close();$this->closed=true;}
    private function requireOpen():void{if($this->closed)throw new \LogicException('This Facebook client is closed; create a new facade for the active Messenger session');}
    public function __debugInfo():array{return ['account_id'=>$this->accountId(),'documents'=>count($this->documents->inventory()),'session'=>'[PRIVATE]'];}
}
