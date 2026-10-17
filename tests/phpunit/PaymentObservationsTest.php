<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\History\HistoryStore;
use MVO\Message\{BotResponseContent,ModernEvents};
use MVO\Protocol\SchemaCodec;
use MVO\Service\HistoryFeaturesService;
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class PaymentObservationsTest extends TestCase
{
    private SignalStore $store;
    private HistoryStore $history;
    private const CHAT='120363000000000000@g.us';
    protected function setUp():void{$this->store=new SignalStore(':memory:');$this->history=new HistoryStore($this->store);}
    protected function tearDown():void{$this->store->close();}
    private function record(string $id,string $sender,array $proto,int $timestamp=10,string $chat=self::CHAT):void
    {$this->history->recordEvent(['id'=>$id,'chat'=>$chat,'from'=>$sender,'authenticatedSender'=>$sender,'timestamp'=>$timestamp,'proto'=>$proto]);}
    private static function split(string $id='SPLIT'):array{return ['splitPaymentMessage'=>['splitId'=>$id,'requesterJid'=>'200@s.whatsapp.net','totalAmount'=>['value'=>'9223372036854775807','offset'=>100,'currencyCode'=>'BRL'],'description'=>'Equipe','participants'=>[['jid'=>'300@s.whatsapp.net','amount'=>['value'=>'9007199254740993','offset'=>100,'currencyCode'=>'BRL'],'status'=>0]],'contextInfo'=>['ephemeralSharedSecret'=>'PRIVATE']]];}
    public function testMoneyPreservesInt64AndOffsetWithoutFloatOrPrivateFields():void
    {
        $proto=self::split();$decoded=SchemaCodec::decode('Message',SchemaCodec::encode('Message',$proto));$payment=ModernEvents::normalize($decoded)['payment'];self::assertSame('9223372036854775807',$payment['totalAmount']['value']);self::assertSame('9007199254740993',$payment['participants'][0]['amount']['value']);self::assertSame(100,$payment['totalAmount']['offset']);self::assertFalse($payment['paymentVerified']);self::assertArrayNotHasKey('contextInfo',$payment);
        foreach([1.5,-1,'1e10','0001','9223372036854775808',null]as$value)self::assertNull(ModernEvents::money(['value'=>$value,'offset'=>100,'currencyCode'=>'BRL']));
        self::assertNull(ModernEvents::money(['value'=>'1','offset'=>1.0,'currencyCode'=>'BRL']));self::assertNull(ModernEvents::money(['value'=>'1','offset'=>100,'currencyCode'=>'invalid']));self::assertSame('0',ModernEvents::money(['value'=>0,'offset'=>0,'currencyCode'=>'BRL'])['value']);
    }
    public function testSplitCreationRejectsForgedRequesterAndParticipantUpdateDoesNotMeanPaid():void
    {
        $this->record('FORGED','999@s.whatsapp.net',self::split());self::assertSame([],$this->history->observed()->list('payments'));
        $this->record('CREATE','200@s.whatsapp.net',self::split());$row=$this->history->observed()->list('payments')[0];self::assertSame('200@s.whatsapp.net',$row['author']);
        $update=['splitPaymentUpdateMessage'=>['splitId'=>'SPLIT','participantJid'=>'300@s.whatsapp.net']];
        $this->record('SPOOF','999@s.whatsapp.net',$update,11);$this->record('OTHERCHAT','300@s.whatsapp.net',$update,11,'120363000000000001@g.us');self::assertArrayNotHasKey('updateCount',$this->history->observed()->getPayment($row['id']));
        $this->record('UPDATE','300@s.whatsapp.net',$update,12);$current=$this->history->observed()->getPayment($row['id']);self::assertSame(1,$current['updateCount']);self::assertSame('UPDATE',$current['lastUpdateMessageId']);self::assertSame('pending',$current['participants'][0]['reportedStatus']);self::assertFalse($current['paymentVerified']);
        $this->record('UPDATE','300@s.whatsapp.net',$update,12);$this->record('STALE','300@s.whatsapp.net',$update,9);self::assertSame(1,$this->history->observed()->getPayment($row['id'])['updateCount']);
    }
    public function testMissingAmbiguousOrNonParticipantReferencesCannotChangeSplit():void
    {
        $update=['splitPaymentUpdateMessage'=>['splitId'=>'SPLIT','participantJid'=>'300@s.whatsapp.net']];$this->record('NOORIGINAL','300@s.whatsapp.net',$update);self::assertSame([],$this->history->observed()->list('payments'));
        $this->record('CREATE','200@s.whatsapp.net',self::split());$other=self::split();$other['splitPaymentMessage']['requesterJid']='400@s.whatsapp.net';$this->record('COLLISION','400@s.whatsapp.net',$other);
        $this->record('AMBIGUOUS','300@s.whatsapp.net',$update,11);foreach($this->history->observed()->list('payments')as$row)self::assertArrayNotHasKey('updateCount',$row);
        $update['splitPaymentUpdateMessage']['participantJid']='999@s.whatsapp.net';$this->record('OUTSIDER','999@s.whatsapp.net',$update,12);foreach($this->history->observed()->list('payments')as$row)self::assertArrayNotHasKey('updateCount',$row);
    }
    public function testPnLidAliasesDoNotDuplicateCreationAndRequesterCannotRewriteAmounts():void
    {
        $this->record('ORIGINAL','200@s.whatsapp.net',self::split());$original=$this->history->observed()->list('payments')[0];$this->store->set('message-pn-to-lid','200@s.whatsapp.net','900@lid');$this->store->set('message-lid-to-pn','900@lid','200@s.whatsapp.net');
        $modified=self::split();$modified['splitPaymentMessage']['requesterJid']='900@lid';$modified['splitPaymentMessage']['totalAmount']['value']='1';$this->record('ECHO','900@lid',$modified,11);$rows=$this->history->observed()->list('payments');self::assertCount(1,$rows);self::assertSame($original['id'],$rows[0]['id']);self::assertSame('9223372036854775807',$rows[0]['totalAmount']['value']);
    }
    public function testPaymentReminderEnforcesOriginalPartiesAndOmitsBankAddress():void
    {
        $body=['reminderId'=>'R','instanceId'=>'I','description'=>'Recorrente','frequency'=>3,'status'=>1,'payeeVpa'=>'PRIVATE-BANK-ADDRESS','payeeJid'=>'200@s.whatsapp.net','payerJid'=>'300@s.whatsapp.net','amount'=>['value'=>'4500','offset'=>100,'currencyCode'=>'BRL']];
        $this->record('CREATE','200@s.whatsapp.net',['paymentReminderMessage'=>$body]);$row=$this->history->observed()->list('payments')[0];self::assertSame('monthly',$row['frequency']);self::assertStringNotContainsString('PRIVATE',json_encode($row));self::assertArrayNotHasKey('payeeVpa',$row);
        $body['status']=5;$forged=$body;$forged['payerJid']='999@s.whatsapp.net';$this->record('FORGEDPAID','999@s.whatsapp.net',['paymentReminderMessage'=>$forged],11);self::assertSame('active',$this->history->observed()->getPayment($row['id'])['reportedStatus']);
        $this->record('REPORTED','300@s.whatsapp.net',['paymentReminderMessage'=>$body],12);$current=$this->history->observed()->getPayment($row['id']);self::assertSame('paid',$current['reportedStatus']);self::assertFalse($current['paymentVerified']);self::assertTrue($current['observedOnly']);
        $body['status']=3;$this->record('STOP','300@s.whatsapp.net',['paymentReminderMessage'=>$body],13);self::assertSame('stopped_by_receiver',$this->history->observed()->getPayment($row['id'])['reportedStatus']);
    }
    public function testMalformedReferencesCannotCollideWithValidPaymentIds():void
    {
        $bad=self::split();$bad['splitPaymentMessage']['splitId']="SPLIT\0";$this->record('BAD','200@s.whatsapp.net',$bad);self::assertSame([],$this->history->observed()->list('payments'));
        $bad=self::split();$bad['splitPaymentMessage']['participants'][]=$bad['splitPaymentMessage']['participants'][0];$this->record('DUP','200@s.whatsapp.net',$bad);self::assertSame([],$this->history->observed()->list('payments'));
    }
    public function testPaymentRetentionIsBoundedAndCapabilitiesDoNotAdvertiseNativeMutation():void
    {
        $this->store->transaction(function():void{for($i=0;$i<2001;++$i)$this->record('R'.$i,'200@s.whatsapp.net',['paymentReminderMessage'=>['reminderId'=>'R'.$i,'status'=>1,'payeeJid'=>'200@s.whatsapp.net']]);});
        $first=$this->history->observed()->list('payments',['limit'=>1000]);$second=$this->history->observed()->list('payments',['limit'=>1000,'after'=>$first[array_key_last($first)]['id']]);self::assertSame(2000,count($first)+count($second));
        $f=new HistoryFeaturesService($this->store,$this->history,static fn():array=>['meJid'=>'100:5@s.whatsapp.net']);$cap=$f->capabilities();foreach(['statusPoll','statusAddYours','nativeScheduling','payments','botReminder','instantImage']as$name){self::assertArrayHasKey($name,$cap['experimental']);self::assertFalse($cap['experimental'][$name]['nativePublish']);}self::assertFalse($cap['experimental']['payments']['executePayment']);self::assertFalse($cap['experimental']['payments']['paymentVerified']);self::assertTrue($cap['experimental']['nativeScheduling']['localScheduler']);
    }
    public function testMixedVisualAndFutureBlocksArePartialWhileProgressIsNotReadableContent():void
    {
        $result=BotResponseContent::decode(['richResponseMessage'=>['submessages'=>[['messageType'=>3,'imageMetadata'=>['imageUrl'=>['imageHighResUrl'=>'https://example.com/image.jpg']]],['messageType'=>999]]]]);self::assertSame('partial',$result['richResponse']['status']);self::assertNull($result['text']);
        $bytes=json_encode(['sections'=>[['view_model'=>['__typename'=>'GenAISingleLayoutViewModel','primitive'=>['__typename'=>'GenAIBotThinkingStatusPrimitive','title'=>'Thinking']]],['view_model'=>['__typename'=>'GenAISingleLayoutViewModel','primitive'=>['__typename'=>'FuturePrimitive']]]]]);
        $result=BotResponseContent::decode(['richResponseMessage'=>['unifiedResponse'=>['data'=>$bytes]]]);self::assertSame('unsupported',$result['richResponse']['status']);
    }
}
