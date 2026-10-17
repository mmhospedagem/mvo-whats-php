<?php
declare(strict_types=1);

namespace MVO\Tests;

use MVO\AppState\{AppStateCrypto, AppStateProjector, AppStateSchema, AppStateService};
use MVO\History\HistoryStore;
use MVO\Protocol\{BinaryNode, SchemaCodec};
use MVO\Service\BusinessToolsService;
use MVO\Signal\SignalStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BusinessToolsTest extends TestCase
{
    private SignalStore $store;
    private SignalStore $peerStore;
    private HistoryStore $history;
    private AppStateProjector $projector;
    private AppStateService $appState;
    private AppStateService $peer;
    private BusinessToolsService $tools;
    private array $wireActions = [];
    private int $queryCount = 0;
    private bool $rejectUpload = false;
    private ?\Closure $beforeAck = null;

    protected function setUp(): void
    {
        $this->store = new SignalStore(':memory:');
        $this->peerStore = new SignalStore(':memory:');
        $this->history = new HistoryStore($this->store);
        $this->projector = new AppStateProjector($this->store,$this->history);
        $this->peer = new AppStateService($this->peerStore, static fn()=>throw new \LogicException('Peer must not query'));
        $this->appState = new AppStateService($this->store, function(BinaryNode $request):BinaryNode {
            ++$this->queryCount;
            self::assertSame('w:sync:app:state',$request->attrs['xmlns']);
            $collection = $request->child('sync')->child('collection');
            self::assertSame('regular',$collection->attrs['name']);
            $patchNode = $collection->child('patch');
            if ($patchNode !== null) {
                if ($this->beforeAck !== null) ($this->beforeAck)();
                if ($this->rejectUpload) return new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'403'])]);
                $patch=SchemaCodec::decode('SyncdPatch',$patchNode->content);
                $patch['version']=['version'=>$this->peer->collectionState('regular')['version']+1];
                // A separate local peer verifies the encrypted mutation, snapshot MAC and patch MAC.
                foreach ($this->peer->applyPatch('regular',$patch) as $mutation) $this->wireActions[]=AppStateSchema::parse($mutation);
            }
            return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('sync',[],[new BinaryNode('collection',['name'=>'regular','version'=>(string)$this->peer->collectionState('regular')['version']])])]);
        });
        $this->appState->setProjector($this->projector);
        foreach ([$this->appState,$this->peer] as $service) {
            $service->importKeys([['keyId'=>'KEY1','keyData'=>str_repeat('k',32),'timestamp'=>1]]);
            $service->applySnapshot('regular',['version'=>['version'=>0],'records'=>[],'keyId'=>['id'=>'KEY1'],'mac'=>AppStateCrypto::snapshotMac(str_repeat('k',32),str_repeat("\0",128),0,'regular')]);
        }
        $this->tools = new BusinessToolsService($this->appState,$this->projector,$this->history);
    }

    protected function tearDown(): void { $this->store->close();$this->peerStore->close(); }

    private function seed(string $schema,array $args,array $data): void { $this->projector->apply(AppStateSchema::mutation($schema,$args,$data,123)); }
    private function lastAction(): array { return $this->wireActions[array_key_last($this->wireActions)]; }

    public function testLabelCrudUsesAuthenticatedPatchesAndWaitsForAck(): void
    {
        $this->beforeAck=function():void { self::assertNull($this->tools->getLabel('1'));self::assertCount(1,$this->appState->pendingMutations()); };
        $label=$this->tools->createLabel('Cliente',3);
        self::assertSame('1',$label['id']);
        self::assertSame('Cliente',$label['name']);
        self::assertSame(3,$label['color']);
        self::assertSame('LabelEdit',$this->lastAction()['schema']);
        self::assertSame('["label_edit","1"]',$this->lastAction()['_raw']['index']);
        self::assertSame(3,$this->lastAction()['version']);
        self::assertSame([],$this->appState->pendingMutations());
        self::assertArrayNotHasKey('_raw',$label);
        $this->beforeAck=null;
        $updated=$this->tools->updateLabel('1',['color'=>19,'name'=>'Cliente VIP']);
        self::assertSame(19,$updated['color']);
        self::assertSame('Cliente VIP',$updated['name']);
        self::assertSame('1',$this->tools->listLabels()[0]['id']);
        $this->tools->deleteLabel('1');
        self::assertNull($this->tools->getLabel('1'));
        self::assertTrue($this->lastAction()['deleted']);
        self::assertSame([],$this->tools->listLabels());
    }

    public function testLabelAssociationsUseExactChatAndMessageIndices(): void
    {
        $this->tools->createLabel('Cliente',1,'12');
        $this->history->upsertMessage(['chat'=>'55@s.whatsapp.net','id'=>'MSG1','timestamp'=>100,'text'=>'Recebida']);
        $this->tools->setChatLabel('55:2@s.whatsapp.net','12',true);
        self::assertSame('["label_jid","12","55@s.whatsapp.net"]',$this->lastAction()['_raw']['index']);
        self::assertSame(['12'],$this->tools->getChatLabels('55'));
        $this->tools->setMessageLabel('55','MSG1','12',true);
        self::assertSame('["label_message","12","55@s.whatsapp.net","MSG1","0","0"]',$this->lastAction()['_raw']['index']);
        self::assertSame(3,$this->lastAction()['version']);
        self::assertSame(['12'],$this->tools->getMessageLabels('55','MSG1'));
        self::assertSame('Recebida',$this->history->getMessage('55@s.whatsapp.net','MSG1')['text']);
        $this->tools->setMessageLabel('55','MSG1','12',false);
        self::assertSame([],$this->tools->getMessageLabels('55','MSG1'));
        $this->tools->setChatLabel('55','12',false);
        self::assertSame([],$this->tools->getChatLabels('55'));
    }

    public function testDeletingLabelRemovesAllMessageAssociationsBeyondThousandRecords(): void
    {
        $this->tools->createLabel('Remover',1,'12');
        $this->tools->createLabel('Manter',2,'13');
        for($i=0;$i<1005;++$i) $this->history->upsertMessage(['chat'=>'55@s.whatsapp.net','id'=>'M'.sprintf('%04d',$i),'timestamp'=>100,'labelIds'=>['12','13']]);
        $this->tools->setChatLabel('55','12',true);
        $this->tools->deleteLabel('12');
        self::assertSame([],$this->tools->getChatLabels('55'));
        $count=0;
        foreach($this->history->iterateMessages() as $message) { ++$count;self::assertSame(['13'],$message['labelIds']); }
        self::assertSame(1005,$count);
        self::assertNotNull($this->tools->getLabel('13'));
    }

    public function testQuickRepliesCreateUpdateAndDeletionUseActualProtoFields(): void
    {
        $reply=$this->tools->saveQuickReply('1720000001',['shortcut'=>'/preco','message'=>'Nosso plano custa R$ 29,99','keywords'=>['plano','preco'],'count'=>2]);
        self::assertSame('1720000001',$reply['id']);
        self::assertSame(['plano','preco'],$reply['keywords']);
        self::assertSame('QuickReply',$this->lastAction()['schema']);
        self::assertSame(2,$this->lastAction()['version']);
        self::assertSame('["quick_reply","1720000001"]',$this->lastAction()['_raw']['index']);
        self::assertFalse($this->lastAction()['deleted']);
        $updated=$this->tools->saveQuickReply('1720000001',['message'=>'Preço atualizado']);
        self::assertSame('/preco',$updated['shortcut']);
        self::assertSame('Preço atualizado',$updated['message']);
        self::assertCount(1,$this->tools->listQuickReplies());
        $this->tools->deleteQuickReply('1720000001');
        self::assertNull($this->tools->getQuickReply('1720000001'));
        self::assertSame([],$this->tools->listQuickReplies());
        self::assertTrue($this->lastAction()['deleted']);
    }

    public function testQuickReplyPaginationScansAllHashedActionsAndRemainsCompatibleWithOldStores(): void
    {
        for($i=0;$i<1005;++$i) $this->seed('LabelEdit',['id'=>(string)(1000+$i)],['name'=>'Label '.$i,'color'=>1]);
        foreach(['1720000001','1720000002','1720000003'] as $id) $this->seed('QuickReply',['id'=>$id],['shortcut'=>'/'.$id,'message'=>'Texto','deleted'=>false]);
        $this->seed('QuickReply',['id'=>'1720000004'],['shortcut'=>'/excluida','message'=>'Não listar','deleted'=>true]);
        $first=$this->tools->listQuickReplies(2);
        self::assertSame(['1720000001','1720000002'],array_column($first,'id'));
        self::assertSame(['1720000003'],array_column($this->tools->listQuickReplies(2,'1720000002'),'id'));
        self::assertSame(0,$this->queryCount,'Local read must not cause a remote query');
        self::assertSame('/1720000001',$this->tools->getQuickReply('1720000001')['shortcut']);
    }

    public function testBroadcastDefinitionsSynchronizeLidsAndDeleteWithRemoveOperation(): void
    {
        $input=['id'=>'1720000001@broadcast','listName'=>'Clientes','participants'=>[['lidJid'=>'12:2@lid','pnJid'=>'5511999999999@s.whatsapp.net']],'labelIds'=>['1','12']];
        $list=$this->tools->saveBroadcastList($input);
        self::assertSame('Clientes',$list['listName']);
        self::assertSame('12@lid',$list['participants'][0]['lidJid']);
        self::assertSame('BusinessBroadcastList',$this->lastAction()['schema']);
        self::assertSame(1,$this->lastAction()['version']);
        self::assertSame(['1','12'],$this->lastAction()['labelIds']);
        self::assertCount(1,$this->tools->listBroadcastLists());
        $this->tools->deleteBroadcastList('1720000001@broadcast');
        self::assertSame('remove',$this->lastAction()['operation']);
        self::assertSame([],$this->tools->listBroadcastLists());
    }

    public function testRefusedAckPreservesQueueAndDoesNotProjectSuccessfulMutation(): void
    {
        $this->rejectUpload=true;
        $before=$this->appState->collectionState('regular');
        try { $this->tools->createLabel('Não confirmado',1);self::fail('Refused mutation returned success'); }
        catch(\RuntimeException $e) { self::assertNotSame('',$e->getMessage()); }
        self::assertNull($this->tools->getLabel('1'));
        self::assertCount(1,$this->appState->pendingMutations());
        self::assertSame($before,$this->appState->collectionState('regular'));
        self::assertSame([],$this->wireActions);
        $this->rejectUpload=false;
        // A new automatically allocated label must not overwrite a still-pending ID.
        $next=$this->tools->createLabel('Novo cliente',2);
        self::assertSame('2',$next['id']);
        self::assertSame('Não confirmado',$this->tools->getLabel('1')['name']);
        self::assertSame([],$this->appState->pendingMutations());
    }

    public function testMessageLabelRefusalLeavesPreviouslyReceivedMessageIntact(): void
    {
        $this->tools->createLabel('Cliente',1,'12');
        $this->history->upsertMessage(['chat'=>'55@s.whatsapp.net','id'=>'MSG1','timestamp'=>100,'text'=>'Original']);
        $before=$this->history->getMessage('55@s.whatsapp.net','MSG1');
        $this->rejectUpload=true;
        try { $this->tools->setMessageLabel('55','MSG1','12',true);self::fail('Refused association returned success'); }
        catch(\RuntimeException) { self::assertSame($before,$this->history->getMessage('55@s.whatsapp.net','MSG1')); }
        self::assertCount(1,$this->appState->pendingMutations());
    }

    public function testLabelAutomaticIdFindsMaximumAcrossPagesAndNeverExceedsInt32(): void
    {
        for($i=1;$i<=1005;++$i) $this->seed('LabelEdit',['id'=>(string)$i],['name'=>'Label '.$i,'color'=>1]);
        self::assertSame('1006',$this->tools->createLabel('Último',19)['id']);
        $this->seed('LabelEdit',['id'=>'2147483647'],['name'=>'Limite','color'=>1]);
        $before=$this->queryCount;
        try { $this->tools->createLabel('Sem ID disponível',1);self::fail('Overflow accepted'); }
        catch(\OverflowException) { self::assertSame($before,$this->queryCount); }
    }

    public static function invalidInputs(): iterable
    {
        yield 'label color'=>['createLabel',['Nome',20]];
        yield 'label empty name'=>['createLabel',['',1]];
        yield 'label zero id'=>['createLabel',['Nome',1,'0']];
        yield 'label overflow'=>['createLabel',['Nome',1,'2147483648']];
        yield 'label nonnumeric'=>['createLabel',['Nome',1,'uuid']];
        yield 'reply nonnumeric id'=>['saveQuickReply',['uuid',['shortcut'=>'/x','message'=>'Texto']]];
        yield 'reply missing text'=>['saveQuickReply',['1720000001',['shortcut'=>'/x']]];
        yield 'reply invalid keywords'=>['saveQuickReply',['1720000001',['shortcut'=>'/x','message'=>'Texto','keywords'=>[123]]]];
        yield 'reply negative count'=>['saveQuickReply',['1720000001',['shortcut'=>'/x','message'=>'Texto','count'=>-1]]];
        yield 'reply unknown field'=>['saveQuickReply',['1720000001',['shortcut'=>'/x','message'=>'Texto','password'=>'secret']]];
        yield 'broadcast phone as lid'=>['saveBroadcastList',[['id'=>'list','listName'=>'Clientes','participants'=>[['lidJid'=>'55@s.whatsapp.net']]]]];
        yield 'broadcast empty participants'=>['saveBroadcastList',[['id'=>'list','listName'=>'Clientes','participants'=>[]]]];
        yield 'broadcast unknown participant'=>['saveBroadcastList',[['id'=>'list','listName'=>'Clientes','participants'=>[['lidJid'=>'12@lid','password'=>'secret']]]]];
        yield 'pagination unbounded'=>['listQuickReplies',[1001]];
    }

    #[DataProvider('invalidInputs')]
    public function testInvalidBusinessToolsNeverEnqueueOrQuery(string $method,array $args): void
    {
        try { $this->tools->$method(...$args);self::fail('Invalid input accepted'); }
        catch(\InvalidArgumentException $e) { self::assertNotSame('',$e->getMessage()); }
        self::assertSame(0,$this->queryCount);
        self::assertSame([],$this->appState->pendingMutations());
    }

    public function testRemoteSnapshotRemovalCleansMessageAndChatLabels(): void
    {
        $this->seed('LabelEdit',['id'=>'12'],['name'=>'Cliente','color'=>1]);
        $this->seed('LabelJid',['labelId'=>'12','chatJid'=>'55'],['labeled'=>true]);
        $this->seed('LabelMessage',['labelId'=>'12','chatJid'=>'55','messageId'=>'MSG1'],['labeled'=>true]);
        self::assertSame(['12'],$this->tools->getMessageLabels('55','MSG1'));
        $this->projector->replaceSnapshot('regular',[]);
        self::assertSame([],$this->tools->getChatLabels('55'));
        self::assertSame([],$this->tools->getMessageLabels('55','MSG1'));
        self::assertNull($this->tools->getLabel('12'));
    }
}
