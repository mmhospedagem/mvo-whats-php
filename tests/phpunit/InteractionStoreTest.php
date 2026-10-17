<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\{HistoryStore,InteractionStore};
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class InteractionStoreTest extends TestCase
{
    private SignalStore $store;
    private HistoryStore $history;
    private InteractionStore $interactions;
    private const CHAT='123@g.us';
    private const AUTHOR='100@lid';
    private const VOTER='200@lid';
    protected function setUp():void
    {
        $this->store=new SignalStore(':memory:');$this->history=new HistoryStore($this->store);$this->interactions=new InteractionStore($this->store,$this->history);
        $this->parent('POLL',['pollCreationMessageV6'=>['name'=>'Preferência','options'=>[['optionName'=>'A'],['optionName'=>'B']],'selectableOptionsCount'=>1]]);
        $this->parent('EVENT',['eventMessage'=>['name'=>'Encontro','startTime'=>100,'extraGuestsAllowed'=>true]]);
    }
    protected function tearDown():void {$this->store->close();}
    private function parent(string $id,array $proto,array $extra=[]):void {$this->history->upsertMessage(['chat'=>self::CHAT,'id'=>$id,'from'=>self::AUTHOR,'participant'=>self::AUTHOR,'proto'=>$proto]+$extra);}
    private function vote(string $id,array $options,int $timestamp,string $sender=self::VOTER):array
    {
        return ['id'=>$id,'chat'=>self::CHAT,'authenticatedSender'=>$sender,'type'=>'poll-vote','target'=>['id'=>'POLL','remoteJid'=>self::CHAT,'participant'=>self::AUTHOR],'encrypted'=>false,'data'=>['selectedOptions'=>array_map(static fn(string $name):string=>hash('sha256',$name,true),$options)],'proto'=>['pollUpdateMessage'=>['senderTimestampMs'=>$timestamp]]];
    }
    public function testVoteChangesWithdrawalAndReplayKeepOnlyLatestAuthenticatedSelection():void
    {
        $first=$this->vote('V1',['A'],1000);$this->interactions->ingest($first);$this->interactions->ingest($first);
        self::assertSame([1,0],array_column($this->interactions->pollResults(self::CHAT,'POLL')['options'],'votes'));
        $this->interactions->ingest($this->vote('V2',['B'],2000));$this->interactions->ingest($this->vote('OLD',['A'],1500));
        $result=$this->interactions->pollResults(self::CHAT,'POLL');self::assertSame([0,1],array_column($result['options'],'votes'));self::assertSame(1,$result['totalVoters']);self::assertSame(hash('sha256','B'),$result['voters'][0]['selectedOptionHashes'][0]);
        $this->interactions->ingest($this->vote('CLEAR',[],3000));$result=$this->interactions->pollResults(self::CHAT,'POLL');self::assertSame(0,$result['totalVoters']);self::assertSame([0,0],array_column($result['options'],'votes'));
    }
    public function testMissingAuthenticationFailedDecryptAndCrossConversationReferencesAreIgnored():void
    {
        $event=$this->vote('BAD',['A'],1000);unset($event['authenticatedSender']);$this->interactions->ingest($event);
        $event=$this->vote('BAD',['A'],1000);$event['decryptError']='addon_authentication_failed';$this->interactions->ingest($event);
        $event=$this->vote('BAD',['A'],1000);$event['encrypted']=true;$this->interactions->ingest($event);
        $event=$this->vote('BAD',['A'],1000);$event['target']['remoteJid']='999@g.us';$this->interactions->ingest($event);
        $event=$this->vote('BAD',['A'],1000);$event['target']['participant']='999@lid';$this->interactions->ingest($event);
        $event=$this->vote('BAD',['A'],1000);$event['data']['selectedOptions']=['not-a-sha256'];$this->interactions->ingest($event);
        self::assertSame(0,$this->interactions->pollResults(self::CHAT,'POLL')['totalVoters']);
    }
    public function testAnonymityRemovesIdentitiesFromAllReturnedLevels():void
    {
        $this->parent('POLL',['pollCreationMessageV6'=>['name'=>'Anônima','hideParticipantName'=>true,'options'=>[['optionName'=>'A'],['optionName'=>'B']]]]);
        $this->interactions->ingest($this->vote('V1',['A'],1000));$result=$this->interactions->pollResults(self::CHAT,'POLL');
        self::assertTrue($result['anonymous']);self::assertSame(1,$result['totalVoters']);self::assertArrayNotHasKey('voters',$result);self::assertArrayNotHasKey('voters',$result['options'][0]);self::assertStringNotContainsString(self::VOTER,json_encode($result,JSON_THROW_ON_ERROR));
    }
    public function testPnLidAliasesDoNotDoubleCountAndDoNotRegressNewerSelections():void
    {
        $phone='5511999999999@s.whatsapp.net';$this->interactions->ingest($this->vote('PN',['A'],1000,$phone));
        $this->store->set('message-pn-to-lid',$phone,self::VOTER);$this->store->set('message-lid-to-pn',self::VOTER,$phone);
        $this->interactions->ingest($this->vote('LID',['B'],2000,self::VOTER));$this->interactions->ingest($this->vote('STALE',['A'],1500,$phone));
        $result=$this->interactions->pollResults(self::CHAT,'POLL');self::assertSame(1,$result['totalVoters']);self::assertSame([0,1],array_column($result['options'],'votes'));self::assertSame(self::VOTER,$result['voters'][0]['jid']);
    }
    public function testQueriesRequireAnObservedParentOfTheCorrectKindAndAuthor():void
    {
        foreach([[self::CHAT,'MISSING',null],[self::CHAT,'EVENT',null],['999@g.us','POLL',null],[self::CHAT,'POLL','999@lid']]as[$chat,$id,$author]){
            try{$this->interactions->pollResults($chat,$id,$author);self::fail('Expected isolated parent lookup.');}catch(\OutOfBoundsException $expected){self::assertStringContainsString('not observed',$expected->getMessage());}
        }
    }
    public function testEventRsvpChangesAreNormalizedWithoutCountingOldResponsesTwice():void
    {
        $event=['id'=>'R1','chat'=>self::CHAT,'authenticatedSender'=>self::VOTER,'type'=>'event-response','encrypted'=>false,'target'=>['id'=>'EVENT','participant'=>self::AUTHOR],'data'=>['response'=>'GOING','timestampMs'=>1000,'extraGuestCount'=>2]];
        $this->interactions->ingest($event);$this->interactions->ingest($event);
        $event['id']='R2';$event['data']=['response'=>3,'timestampMs'=>2000,'extraGuestCount'=>0];$this->interactions->ingest($event);
        $event['id']='OLD';$event['data']=['response'=>2,'timestampMs'=>1500];$this->interactions->ingest($event);
        $result=$this->interactions->eventResponses(self::CHAT,'EVENT');self::assertSame(['going'=>0,'not_going'=>0,'maybe'=>1],$result['counts']);self::assertSame(1,$result['totalResponses']);self::assertSame('maybe',$result['responses'][0]['response']);self::assertSame(0,$result['responses'][0]['extraGuestCount']);
    }
    public function testOwnParentWithoutParticipantStorageStillMatchesItsAuthenticatedAuthor():void
    {
        $chat='5511999999999@s.whatsapp.net';$this->history->upsertMessage(['chat'=>$chat,'id'=>'OWN','fromMe'=>true,'from'=>self::AUTHOR,'proto'=>['pollCreationMessageV3'=>['name'=>'Própria','options'=>[['optionName'=>'A']]]]]);
        $event=$this->vote('V',['A'],1000);$event['chat']=$chat;$event['target']=['id'=>'OWN','remoteJid'=>$chat,'participant'=>self::AUTHOR];$this->interactions->ingest($event);
        self::assertSame(1,$this->interactions->pollResults($chat,'OWN',self::AUTHOR)['totalVoters']);
    }
    public function testUnknownOptionHashesAreReportedAsUnresolvedInsteadOfInventingOptions():void
    {
        $this->interactions->ingest($this->vote('NEW',['Later added'],1000));$result=$this->interactions->pollResults(self::CHAT,'POLL');
        self::assertSame(1,$result['unresolvedSelections']);self::assertSame(1,$result['totalSelections']);self::assertCount(2,$result['options']);
    }

    public function testIncomingDirectTargetMayNameOnlyTheAuthenticatedLocalRecipient():void
    {
        $this->history->upsertMessage(['chat'=>self::VOTER,'id'=>'DIRECT','fromMe'=>true,'from'=>self::AUTHOR,'proto'=>['pollCreationMessageV3'=>['name'=>'Direta','options'=>[['optionName'=>'A']]]]]);
        $event=$this->vote('DIRECT_VOTE',['A'],1000);$event['chat']=self::VOTER;$event['fromMe']=false;
        $event['target']=['id'=>'DIRECT','remoteJid'=>self::AUTHOR,'participant'=>self::AUTHOR];$event['authenticatedRecipientJid']='5511000000000@s.whatsapp.net';$event['authenticatedRecipientAlt']=self::AUTHOR;
        $this->interactions->ingest($event);self::assertSame(1,$this->interactions->pollResults(self::VOTER,'DIRECT')['totalVoters']);
        $event['id']='FORGED';$event['target']['remoteJid']='999@lid';$event['proto']['pollUpdateMessage']['senderTimestampMs']=2000;$event['data']['selectedOptions']=[];$this->interactions->ingest($event);
        self::assertSame(1,$this->interactions->pollResults(self::VOTER,'DIRECT')['totalVoters']);
        $event['target']['remoteJid']=self::AUTHOR;unset($event['authenticatedRecipientJid'],$event['authenticatedRecipientAlt']);$this->interactions->ingest($event);self::assertSame(1,$this->interactions->pollResults(self::VOTER,'DIRECT')['totalVoters']);
        $event['authenticatedRecipientAlt']=self::AUTHOR;$event['fromMe']=true;$this->interactions->ingest($event);self::assertSame(1,$this->interactions->pollResults(self::VOTER,'DIRECT')['totalVoters']);
    }

    public function testAuthenticatedAddedOptionsResolveVotesWithoutChangingOriginalAuditMessage():void
    {
        $proto=['pollCreationMessageV6'=>['name'=>'Opções','allowAddOption'=>true,'options'=>[['optionName'=>'A'],['optionName'=>'B']]]];$this->parent('POLL',$proto);
        $event=['id'=>'ADD','chat'=>self::CHAT,'authenticatedSender'=>self::VOTER,'type'=>'poll-add-option','encrypted'=>false,'timestamp'=>1,'target'=>['id'=>'POLL','participant'=>self::AUTHOR],'data'=>['pollAddOptionMessage'=>['pollCreationMessageKey'=>['id'=>'POLL','remoteJid'=>self::CHAT],'addOption'=>['optionName'=>'C']]]];
        $this->interactions->ingest($event);$this->interactions->ingest($event);$this->interactions->ingest($this->vote('V',['C'],2000));
        $result=$this->interactions->pollResults(self::CHAT,'POLL');self::assertSame(['A','B','C'],array_column($result['options'],'name'));self::assertSame([0,0,1],array_column($result['options'],'votes'));self::assertSame(0,$result['unresolvedSelections']);
        self::assertSame($proto,$this->history->getMessage(self::CHAT,'POLL',self::AUTHOR)['proto']);
        $event['id']='DUP';$event['data']['pollAddOptionMessage']['addOption']=['optionName'=>'C','optionHash'=>'another-hash'];$this->interactions->ingest($event);self::assertCount(3,$this->interactions->pollResults(self::CHAT,'POLL')['options']);
    }

    public function testLockedPollsAndMismatchedAddedOptionTargetsDoNotGainOptions():void
    {
        $event=['id'=>'ADD','chat'=>self::CHAT,'authenticatedSender'=>self::VOTER,'type'=>'poll-add-option','timestamp'=>1,'target'=>['id'=>'POLL','participant'=>self::AUTHOR],'data'=>['pollAddOptionMessage'=>['addOption'=>['optionName'=>'C']]]];
        $this->interactions->ingest($event);self::assertCount(2,$this->interactions->pollResults(self::CHAT,'POLL')['options']);
        $this->parent('POLL',['pollCreationMessageV6'=>['name'=>'Poll','allowAddOption'=>true,'options'=>[['optionName'=>'A','optionHash'=>'existing']]]]);
        $event['data']['pollAddOptionMessage']['pollCreationMessageKey']=['id'=>'POLL','remoteJid'=>'999@g.us'];$this->interactions->ingest($event);self::assertCount(1,$this->interactions->pollResults(self::CHAT,'POLL')['options']);
        $event['data']['pollAddOptionMessage']['pollCreationMessageKey']=['id'=>'POLL','remoteJid'=>self::CHAT];$event['data']['pollAddOptionMessage']['addOption']['optionHash']='existing';$this->interactions->ingest($event);self::assertCount(1,$this->interactions->pollResults(self::CHAT,'POLL')['options']);
    }

    public function testPollEditsRequireOriginalAuthenticatedAuthorAndPreservePrivacyAndOptions():void
    {
        $this->parent('POLL',['pollCreationMessageV6'=>['name'=>'Original','hideParticipantName'=>true,'allowAddOption'=>false,'options'=>[['optionName'=>'A'],['optionName'=>'B']]]]);
        $event=['id'=>'EDIT','chat'=>self::CHAT,'authenticatedSender'=>self::VOTER,'type'=>'poll-edit','target'=>['id'=>'POLL','participant'=>self::AUTHOR],'data'=>['protocolMessage'=>['type'=>14,'key'=>['id'=>'POLL','remoteJid'=>self::CHAT],'timestampMs'=>2000,'editedMessage'=>['pollCreationMessageV6'=>['name'=>'Novo título','hideParticipantName'=>false,'allowAddOption'=>true,'options'=>[['optionName'=>'Forged']]]]]]];
        $this->interactions->ingest($event);self::assertSame('Original',$this->interactions->pollResults(self::CHAT,'POLL')['name']);
        $event['authenticatedSender']=self::AUTHOR;$this->interactions->ingest($event);$result=$this->interactions->pollResults(self::CHAT,'POLL');self::assertSame('Novo título',$result['name']);self::assertTrue($result['anonymous']);self::assertSame(['A','B'],array_column($result['options'],'name'));
        $event['id']='OLD';$event['data']['protocolMessage']['timestampMs']=1000;$event['data']['protocolMessage']['editedMessage']['pollCreationMessageV6']['name']='Regressão';$this->interactions->ingest($event);self::assertSame('Novo título',$this->interactions->pollResults(self::CHAT,'POLL')['name']);
        self::assertFalse($this->interactions->parentContent(self::CHAT,'POLL','poll')['allowAddOption']);
    }

    public function testEventProjectionAppliesOnlyAuthenticatedAuthorEditsAndPreservesAudit():void
    {
        $original=$this->history->getMessage(self::CHAT,'EVENT',self::AUTHOR)['proto'];
        $event=['id'=>'EDIT','chat'=>self::CHAT,'authenticatedSender'=>self::VOTER,'type'=>'event-edit','target'=>['id'=>'EVENT','participant'=>self::AUTHOR],'data'=>['protocolMessage'=>['type'=>'MESSAGE_EDIT','timestampMs'=>2000,'key'=>['id'=>'EVENT','remoteJid'=>self::CHAT],'editedMessage'=>['eventMessage'=>['name'=>'Atualizado','startTime'=>500,'extraGuestsAllowed'=>false]]]]];
        $this->interactions->ingest($event);self::assertSame('Encontro',$this->interactions->eventResponses(self::CHAT,'EVENT')['name']);
        $event['authenticatedSender']=self::AUTHOR;$this->interactions->ingest($event);self::assertSame('Atualizado',$this->interactions->eventResponses(self::CHAT,'EVENT')['name']);
        $content=$this->interactions->parentContent(self::CHAT,'EVENT','event');self::assertSame(500,$content['startTime']);self::assertFalse($content['extraGuestsAllowed']);
        self::assertSame($original,$this->history->getMessage(self::CHAT,'EVENT',self::AUTHOR)['proto']);
        $event['id']='BAD_REMOTE';$event['data']['protocolMessage']['timestampMs']=3000;$event['data']['protocolMessage']['key']['remoteJid']='999@g.us';$event['data']['protocolMessage']['editedMessage']['eventMessage']['name']='Wrong';$this->interactions->ingest($event);self::assertSame('Atualizado',$this->interactions->eventResponses(self::CHAT,'EVENT')['name']);
    }

    public function testVotesAndParentProjectionsSurviveClosingAndReopeningSqlite():void
    {
        $path=sys_get_temp_dir().'/mvo-interactions-restart-'.bin2hex(random_bytes(8)).'.sqlite';$store=new SignalStore($path);$history=new HistoryStore($store);$interactions=new InteractionStore($store,$history);
        try{
            $history->upsertMessage(['chat'=>self::CHAT,'id'=>'POLL','from'=>self::AUTHOR,'participant'=>self::AUTHOR,'proto'=>['pollCreationMessageV3'=>['name'=>'Antes','options'=>[['optionName'=>'A']]]]]);
            $interactions->ingest($this->vote('V',['A'],1000));
            $interactions->ingest(['id'=>'EDIT','chat'=>self::CHAT,'authenticatedSender'=>self::AUTHOR,'type'=>'poll-edit','target'=>['id'=>'POLL'],'data'=>['protocolMessage'=>['type'=>14,'timestampMs'=>2000,'editedMessage'=>['pollCreationMessageV3'=>['name'=>'Depois']]]]]);
            $store->close();unset($store,$history,$interactions);$store=new SignalStore($path);$history=new HistoryStore($store);$interactions=new InteractionStore($store,$history);
            $result=$interactions->pollResults(self::CHAT,'POLL');self::assertSame('Depois',$result['name']);self::assertSame(1,$result['totalVoters']);self::assertSame(1,$result['options'][0]['votes']);
        }finally{$store->close();unset($store,$history,$interactions);gc_collect_cycles();foreach([$path,$path.'-wal',$path.'-shm']as$file)if(is_file($file))unlink($file);}
    }
}
