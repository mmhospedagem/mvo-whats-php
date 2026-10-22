<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Service\{BusinessFormsService,BusinessFormStore,ServiceException};
use MVO\Signal\SignalStore;
use PHPUnit\Framework\TestCase;

final class BusinessFormsServiceTest extends TestCase
{
    private SignalStore $store;
    private BusinessFormStore $catalog;
    private int $publications=0;
    private array $sent=[];

    protected function setUp():void{$this->store=new SignalStore(':memory:');$this->catalog=new BusinessFormStore($this->store,static fn():int=>1792670400000);}
    protected function tearDown():void{$this->store->close();}
    private function service(?callable $publisher=null,?callable $sender=null,?callable $generator=null):BusinessFormsService
    {
        return new BusinessFormsService($this->catalog,$this->store,$publisher??function(array $form,string $country):array{
            $this->publications++;self::assertSame('BR',$country);
            return ['flowId'=>'9007199254740993','wellVersion'=>'1','flowName'=>$form['form_title'],'category'=>'CUSTOMER_INFO'];
        },$sender??function(string $to,array $message):array{$this->sent[]=[$to,$message];return ['id'=>$message['messageId']??'MESSAGE','status'=>'server_ack'];},$generator);
    }
    private function form():array{return $this->catalog->create(['form_title'=>'Dados do cliente','form_type'=>'CUSTOMER_INFO','message_text'=>'Preencha seus dados.','selected_field_list'=>['NAME']]);}

    public function testLocalCrudNeverInvokesRemotePublisher():void
    {
        $service=$this->service();$form=$service->create(['form_title'=>'Dados','form_type'=>'CUSTOMER_INFO','message_text'=>'Preencha','selected_field_list'=>['NAME']]);
        self::assertSame($form,$service->get($form['id']));self::assertCount(1,$service->list());
        self::assertSame('Novo nome',$service->rename($form['id'],'Novo nome')['form_title']);
        $copy=$service->duplicate($form['id']);self::assertNotSame($form['id'],$copy['id']);
        self::assertTrue($service->delete($copy['id']));self::assertSame(0,$this->publications);
        self::assertArrayHasKey('customer_info',$service->templates());self::assertJson($service->exportTemplates());
    }
    public function testConfirmedPublicationIsIdempotentAndBindsExactSnapshot():void
    {
        $form=$this->form();$service=$this->service();$publication=$service->publish($form['id'],'BR','PUBLISH-1');
        self::assertSame('published',$publication['state']);self::assertSame($form,$publication['form']);
        self::assertSame('9007199254740993',$publication['publication']['flowId']);
        self::assertSame($publication,$service->publish($form['id'],'BR','PUBLISH-1'));self::assertSame(1,$this->publications);
        self::assertSame($publication,$service->lastPublication($form['id']));
        $service->rename($form['id'],'Edited later');
        try{$service->publish($form['id'],'BR','PUBLISH-1');self::fail('Changed snapshot reused request ID');}catch(\LogicException){}
        self::assertSame(1,$this->publications);
    }
    public function testUnknownRemoteOutcomePersistsAndCannotPublishAgainImplicitly():void
    {
        $form=$this->form();$calls=0;$service=$this->service(function()use(&$calls):array{$calls++;throw new \RuntimeException('connection ended');});
        try{$service->publish($form['id'],'BR','UNCERTAIN');self::fail('Lost connection was accepted');}catch(\RuntimeException $error){self::assertSame('connection ended',$error->getMessage());}
        $record=$service->publicationStatus('UNCERTAIN');self::assertSame('unknown',$record['state']);
        self::assertSame($record,$this->service()->publish($form['id'],'BR','UNCERTAIN'));
        try{$this->service()->publish($form['id'],'BR','NEW-ID');self::fail('Unknown publication repeated');}catch(\LogicException){}
        self::assertSame(1,$calls);self::assertSame(0,$this->publications);
        self::assertSame('abandoned',$service->abandonPublication('UNCERTAIN')['state']);
        self::assertSame('published',$this->service()->publish($form['id'],'BR','EXPLICIT-NEW-ATTEMPT')['state']);
    }
    public function testExplicitRefusalAndMalformedSuccessAreNotPublished():void
    {
        $form=$this->form();$service=$this->service(static fn():array=>throw new ServiceException('forms.publish','403','Not permitted'));
        try{$service->publish($form['id'],'BR','DENIED');self::fail('Refusal accepted');}catch(ServiceException $error){self::assertSame('403',$error->serverCode);}
        self::assertSame('rejected',$service->publicationStatus('DENIED')['state']);
        $service=$this->service(static fn():array=>['flowId'=>'123']);
        try{$service->publish($form['id'],'BR','MALFORMED');self::fail('Missing version accepted');}catch(\UnexpectedValueException){}
        self::assertSame('unknown',$service->publicationStatus('MALFORMED')['state']);self::assertCount(0,$this->sent);
    }
    public function testPublicationSurvivesSendFailureAndDoesNotRequireTemplateToRemainSaved():void
    {
        $form=$this->form();$service=$this->service(sender:static fn():array=>throw new \RuntimeException('message outcome unknown'));
        try{$service->send($form['id'],'5511999999999','BR',requestId:'PUBLISH-BEFORE-SEND');self::fail('Message failure swallowed');}
        catch(\RuntimeException $error){self::assertSame('message outcome unknown',$error->getMessage());}
        self::assertSame('published',$service->publicationStatus('PUBLISH-BEFORE-SEND')['state']);self::assertSame(1,$this->publications);
        $service->delete($form['id']);$result=$this->service()->sendPublication('PUBLISH-BEFORE-SEND','123456@lid');
        self::assertSame('server_ack',$result['message']['status']);self::assertSame('123456@lid',$this->sent[0][0]);
        self::assertSame($form,$this->sent[0][1]['form']);self::assertSame(1,$this->publications);
    }
    public function testInvalidRecipientCountryAndOptionsDoNotCreateRemoteResource():void
    {
        $form=$this->form();$service=$this->service();
        foreach([
            static fn()=>$service->publish($form['id'],'br'),
            static fn()=>$service->publish($form['id'],'BR','invalid/request'),
            static fn()=>$service->send($form['id'],'not@@a-jid','BR'),
            static fn()=>$service->send($form['id'],'123@lid','BR',['flow_id'=>'invented']),
            static fn()=>$service->send($form['id'],'123@lid','BR',['cta'=>null]),
            static fn()=>$service->send($form['id'],'123@lid','BR',['cta'=>str_repeat('X',31)]),
            static fn()=>$service->send($form['id'],'123@lid','BR',['viewOnce'=>'true']),
        ]as$call){try{$call();self::fail('Invalid input accepted');}catch(\InvalidArgumentException){}}
        self::assertSame(0,$this->publications);self::assertNull($service->lastPublication($form['id']));
    }
    public function testRepeatingSendReturnsAcknowledgedResultWithoutPublishingOrSendingAgain():void
    {
        $form=$this->form();$service=$this->service();$first=$service->send($form['id'],'123@lid','BR',requestId:'SEND-ONCE');
        self::assertSame($first,$service->send($form['id'],'123@lid','BR',requestId:'SEND-ONCE'));
        self::assertSame(1,$this->publications);self::assertCount(1,$this->sent);
        self::assertSame($this->sent[0][1]['messageId'],$service->sendStatus('SEND-ONCE')['messageId']);
        try{$service->send($form['id'],'456@lid','BR',requestId:'SEND-ONCE');self::fail('Send ID rebound to another recipient');}catch(\LogicException){}
        self::assertCount(1,$this->sent);
    }
    public function testUnknownSendKeepsMessageIdAndWillNotSendAgain():void
    {
        $form=$this->form();$calls=0;$messageId=null;$service=$this->service(sender:static function(string $to,array $content)use(&$calls,&$messageId):array{$calls++;$messageId=$content['messageId'];throw new \RuntimeException('Lost message ACK');});
        try{$service->send($form['id'],'123@lid','BR',requestId:'UNKNOWN-SEND');self::fail('Unknown send accepted');}catch(\RuntimeException $error){self::assertSame('Lost message ACK',$error->getMessage());}
        $attempt=$service->sendStatus('UNKNOWN-SEND');self::assertSame('unknown',$attempt['state']);self::assertSame($messageId,$attempt['messageId']);
        try{$service->send($form['id'],'123@lid','BR',requestId:'UNKNOWN-SEND');self::fail('Unknown message resent');}catch(\LogicException){}
        self::assertSame(1,$calls);self::assertSame(1,$this->publications);
    }
    public function testUnsupportedStoredFieldFailsBeforePublication():void
    {
        $form=$this->catalog->create(['form_title'=>'Unsupported field','form_type'=>'CUSTOMER_INFO','message_text'=>'Fill','selected_field_list'=>['ADDRESS']]);
        try{$this->service()->publish($form['id'],'BR');self::fail('Unsupported field published');}catch(\InvalidArgumentException){}
        self::assertSame(0,$this->publications);
    }
    public function testGenerationPreservesRejectionAndDoesNotCreateTemplateOrFlow():void
    {
        $rejection=['can_convert_to_form'=>false,'rejection_type'=>'not_supported','rejection_reason'=>'Review required','flagged_fields'=>['example']];
        $service=$this->service(generator:static function(string $prompt)use($rejection):array{self::assertSame('Generate customer data',$prompt);return $rejection;});
        self::assertSame($rejection,$service->generate('Generate customer data'));self::assertSame([],$service->list());self::assertSame(0,$this->publications);
        try{$service->generate("\0");self::fail('Invalid prompt accepted');}catch(\InvalidArgumentException){}
    }
    public function testPendingReservationAfterCrashIsNotRepeated():void
    {
        $form=$this->form();$service=$this->service();
        $record=['requestId'=>'RESERVED','formId'=>$form['id'],'state'=>'prepared','fingerprint'=>\MVO\Service\PublicationPolicy::fingerprint(['form'=>$form,'countryCode'=>'BR']),'countryCode'=>'BR','form'=>$form,'createdAt'=>1,'updatedAt'=>1];
        $this->store->set('business-form-publications','RESERVED',\MVO\Protocol\BinaryData::encode($record));$this->store->set('business-form-last-publication',$form['id'],'RESERVED');
        self::assertSame('prepared',$service->publish($form['id'],'BR','RESERVED')['state']);self::assertSame(0,$this->publications);
        try{$service->sendPublication('RESERVED','123@lid');self::fail('Unconfirmed form sent');}catch(\LogicException){}
        self::assertSame([],$this->sent);
    }
}
