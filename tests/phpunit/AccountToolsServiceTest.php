<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\AppState\{AppStateCrypto,AppStateProjector,AppStateSchema,AppStateService};
use MVO\History\HistoryStore;
use MVO\Protocol\{BinaryNode,SchemaCodec};
use MVO\Service\AccountToolsService;
use MVO\Signal\SignalStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AccountToolsServiceTest extends TestCase
{
    private SignalStore $store;
    private SignalStore $peerStore;
    private AppStateProjector $projector;
    private AppStateService $app;
    private AppStateService $peer;
    private AccountToolsService $tools;
    private array $actions=[];
    private int $queries=0;
    private bool $reject=false;
    private ?\Closure $beforeAck=null;
    private ?\Closure $onFetch=null;
    protected function setUp():void
    {
        $this->store=new SignalStore(':memory:');$this->peerStore=new SignalStore(':memory:');$history=new HistoryStore($this->store);$this->projector=new AppStateProjector($this->store,$history);
        $this->peer=new AppStateService($this->peerStore,static fn():never=>throw new \LogicException('No peer query'));
        $this->app=new AppStateService($this->store,function(BinaryNode $request):BinaryNode{
            ++$this->queries;$responses=[];
            foreach($request->child('sync')->content as$collection){$name=$collection->attrs['name'];$patch=$collection->child('patch');
                if($patch!==null){if($this->beforeAck!==null)($this->beforeAck)();if($this->reject)return new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'403'])]);$decoded=SchemaCodec::decode('SyncdPatch',$patch->content);$decoded['version']=['version'=>$this->peer->collectionState($name)['version']+1];foreach($this->peer->applyPatch($name,$decoded)as$mutation)$this->actions[]=AppStateSchema::parse($mutation);}
                elseif($this->onFetch!==null)($this->onFetch)($name);
                $responses[]=new BinaryNode('collection',['name'=>$name,'version'=>(string)$this->peer->collectionState($name)['version']]);
            }
            return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('sync',[],$responses)]);
        });$this->app->setProjector($this->projector);
        foreach([$this->app,$this->peer]as$service){$service->importKeys([['keyId'=>'KEY1','keyData'=>str_repeat('k',32),'timestamp'=>1]]);foreach(AppStateService::COLLECTIONS as$name)$service->applySnapshot($name,['version'=>['version'=>0],'records'=>[],'keyId'=>['id'=>'KEY1'],'mac'=>AppStateCrypto::snapshotMac(str_repeat('k',32),str_repeat("\0",128),0,$name)]);}
        $this->tools=new AccountToolsService($this->app,$this->projector,$history);
    }
    protected function tearDown():void{$this->store->close();$this->peerStore->close();}
    private function seed(string $schema,array $args,array $data):void{$this->projector->apply(AppStateSchema::mutation($schema,$args,$data,123));}
    private function last():array{return $this->actions[array_key_last($this->actions)];}

    public function testAgentAssignmentAndOpenedUseExactAuthenticatedIndicesAndWaitForAck():void
    {
        $this->beforeAck=function():void{self::assertNull($this->tools->getAgent('a1'));};
        $agent=$this->tools->saveAgent('a1',['name'=>'Suporte','deviceId'=>2]);self::assertSame('Suporte',$agent['name']);self::assertSame('Agent',$this->last()['schema']);self::assertSame('["deviceAgent","a1"]',$this->last()['_raw']['index']);self::assertSame(7,$this->last()['version']);self::assertArrayNotHasKey('_raw',$agent);$this->beforeAck=null;
        $agent=$this->tools->saveAgent('a1',['name'=>'Financeiro']);self::assertSame(2,$agent['deviceId']);
        $assignment=$this->tools->setAssignment('5511999999999','a1');self::assertSame('a1',$assignment['deviceAgentId']);self::assertSame('["agentChatAssignment","5511999999999@s.whatsapp.net"]',$this->last()['_raw']['index']);
        self::assertTrue($this->tools->setOpened('5511999999999','a1',true)['chatOpened']);self::assertSame('["agentChatAssignmentOpenedStatus","5511999999999@s.whatsapp.net","a1"]',$this->last()['_raw']['index']);self::assertFalse($this->tools->setOpened('5511999999999','a1',false)['chatOpened']);
        $this->tools->clearAssignment('5511999999999');self::assertSame('remove',$this->last()['operation']);self::assertNull($this->tools->getAssignment('5511999999999'));
        $this->tools->deleteAgent('a1');self::assertNull($this->tools->getAgent('a1'));self::assertSame([],$this->tools->listAgents());self::assertTrue($this->last()['isDeleted']);
    }
    public function testNoteUpdatePreservesChatAndCreationAndDeleteHidesOnlyThatNote():void
    {
        $note=$this->tools->saveNote('n1',['chatJid'=>'5511999999999','text'=>'Primeira nota']);$created=$note['createdAt'];self::assertSame('NoteEdit',$this->last()['schema']);self::assertSame('regular_low',$this->last()['collection']);self::assertSame('["note_edit","n1"]',$this->last()['_raw']['index']);
        $next=$this->tools->saveNote('n1',['text'=>'Atualização']);self::assertSame($created,$next['createdAt']);self::assertSame($note['chatJid'],$next['chatJid']);self::assertSame('Atualização',$next['unstructuredContent']);
        $this->tools->saveNote('n2',['chatJid'=>'5511888888888','text'=>'Outra conversa']);self::assertCount(1,$this->tools->listNotes($note['chatJid']));
        $this->tools->deleteNote('n1');self::assertSame($created,$this->last()['createdAt']);self::assertTrue($this->last()['deleted']);self::assertNull($this->tools->getNote('n1'));self::assertCount(1,$this->tools->listNotes());
    }
    public function testFavoritesFetchBeforeMergingAndPreserveUnrelatedOrder():void
    {
        $this->seed('Favorites',[],['favorites'=>[['id'=>'one'],['id'=>'two']]]);$fetched=false;
        $this->onFetch=function(string $name)use(&$fetched):void{if($name==='regular_high'&&!$fetched){$fetched=true;$this->seed('Favorites',[],['favorites'=>[['id'=>'one'],['id'=>'two'],['id'=>'remote-new']]]);}};
        self::assertSame(['one','two','remote-new','three'],$this->tools->addFavorites(['two','three']));self::assertTrue($fetched);self::assertSame('["favorites"]',$this->last()['_raw']['index']);self::assertSame(1,$this->last()['version']);self::assertSame(['one','remote-new','three'],$this->tools->removeFavorites(['two','missing']));
    }
    public function testReadOnlyCommercialProjectionsNeverMutateOrFillMissingMetrics():void
    {
        $this->seed('BusinessBroadcastCampaign',['campaign'=>'c1'],['name'=>'Campanha observada','reservedQuota'=>100]);$this->seed('BusinessBroadcastInsights',['campaignId'=>'c1'],['recipientCount'=>8,'readCount'=>3]);$this->seed('MarketingMessage',['id'=>'m1'],['name'=>'Modelo','message'=>'Olá','isDeleted'=>false]);
        self::assertSame('Campanha observada',$this->tools->getCampaign('c1')['name']);$stats=$this->tools->getCampaignInsights('c1');self::assertSame(8,$stats['recipientCount']);self::assertArrayNotHasKey('deliveredCount',$stats);self::assertArrayNotHasKey('_raw',$stats);self::assertCount(1,$this->tools->listCampaigns());self::assertCount(1,$this->tools->listCampaignInsights());self::assertCount(1,$this->tools->listMarketingMessages());self::assertSame(0,$this->queries);self::assertSame([],$this->app->pendingMutations());
    }
    public function testContactPartialEditPreservesOnlyAuthenticatedLinkage():void
    {
        $this->seed('Contact',['id'=>'5511999999999@s.whatsapp.net'],['fullName'=>'Nome antigo','firstName'=>'Nome','lidJid'=>'123456@lid','username'=>'cliente.mvo']);
        $record=$this->tools->saveContact('5511999999999',['firstName'=>'Novo','birthday'=>'1990-05-20']);self::assertSame('Nome antigo',$record['fullName']);self::assertSame('123456@lid',$record['lidJid']);self::assertSame('cliente.mvo',$record['username']);self::assertSame('1990-05-20',$record['birthday']);self::assertSame('critical_unblock_low',$this->last()['collection']);
        $lid=$this->tools->saveContact('777@lid',['fullName'=>'Nome LID']);self::assertSame('Nome LID',$lid['fullName']);self::assertSame('LidContact',$this->last()['schema']);self::assertArrayNotHasKey('pnJid',$lid);
    }
    public function testRefusedAckDoesNotProjectSuccess():void
    {
        $this->reject=true;try{$this->tools->saveAgent('a1',['name'=>'Não confirmado','deviceId'=>1]);self::fail('Expected ACK rejection');}catch(\RuntimeException){self::assertNull($this->tools->getAgent('a1'));self::assertCount(1,$this->app->pendingMutations());self::assertSame([],$this->actions);}
    }
    public function testStructuredNotesAndUnobservedAssignmentsAreNotFabricated():void
    {
        $this->seed('NoteEdit',['id'=>'structured'],['type'=>'STRUCTURED','chatJid'=>'5511999999999@s.whatsapp.net','createdAt'=>123,'deleted'=>false]);
        try{$this->tools->saveNote('structured',['text'=>'Overwrite']);self::fail('Expected structured guard');}catch(\LogicException){self::assertSame(0,$this->queries);}
        $this->expectException(\OutOfBoundsException::class);$this->tools->setAssignment('5511999999999','missing');
    }
    public function testPaginationFiltersDeletedAndForeignChatBeforeApplyingLimit():void
    {
        for($i=0;$i<120;++$i)$this->seed('Agent',['agentId'=>'deleted-'.$i],['name'=>'Excluído','deviceId'=>1,'isDeleted'=>true]);foreach(['a1','a2','a3']as$id)$this->seed('Agent',['agentId'=>$id],['name'=>$id,'deviceId'=>1,'isDeleted'=>false]);
        $first=$this->tools->listAgents(2);self::assertCount(2,$first);$next=$this->tools->listAgents(2,$first[1]['cursor']);self::assertCount(1,$next);self::assertCount(3,array_unique(array_column([...$first,...$next],'agentId')));
    }
    public static function invalid():iterable
    {
        yield'name null'=>['agent',['name'=>null]];yield'negative device'=>['agent',['deviceId'=>-1]];yield'unknown agent field'=>['agent',['isDeleted'=>false]];yield'blank note'=>['note',['text'=>' ']];yield'fake note chat'=>['note',['chatJid'=>'nobody@example.com','text'=>'x']];yield'contact assertion'=>['contact',['pnJid'=>'55@s.whatsapp.net']];yield'null primary'=>['contact',['saveOnPrimaryAddressbook'=>null]];yield'bad birthday'=>['contact',['birthday'=>'2025-02-30']];yield'null birthday'=>['contact',['birthday'=>null]];yield'bad favorite'=>['favorites',["x\n"]];yield'invalid utf8'=>['favorites',["\xff"]];
    }
    #[DataProvider('invalid')]
    public function testInvalidInputsHaveNoWireOrQueueEffect(string $type,array $input):void
    {
        try{match($type){'agent'=>$this->tools->saveAgent('a1',$input),'note'=>$this->tools->saveNote('n1',$input),'contact'=>$this->tools->saveContact('5511999999999',$input),'favorites'=>$this->tools->addFavorites($input)};self::fail('Expected validation');}catch(\InvalidArgumentException){self::assertSame(0,$this->queries);self::assertSame([],$this->app->pendingMutations());}
    }
}
