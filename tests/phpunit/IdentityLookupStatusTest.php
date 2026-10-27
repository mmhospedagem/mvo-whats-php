<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Protocol\BinaryNode;
use MVO\Service\{IdentityService,MexService,ProfileService,ServiceException,ServiceTransport};
use MVO\Signal\SignalStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class IdentityLookupStatusTest extends TestCase
{
    private const PHONE='5511000000001@s.whatsapp.net';
    private SignalStore $store;
    private int $queries=0;

    protected function setUp():void { $this->store=new SignalStore(':memory:'); }
    protected function tearDown():void { $this->store->close(); }

    private static function response(array $users=[],array $protocols=[]):BinaryNode
    {
        return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('usync',[],[
            new BinaryNode('result',[],$protocols),new BinaryNode('list',[],$users),
        ])]);
    }

    private function transport(BinaryNode|callable $response):ServiceTransport
    {
        $transport=new ServiceTransport(function(BinaryNode $request)use($response):BinaryNode{
            $this->queries++;return $response instanceof BinaryNode?$response:$response($request);
        },static function():void{},static fn():array=>[]);
        $transport->configureIdentities(new IdentityService($this->store));
        return $transport;
    }

    private function profile(BinaryNode|callable $response):ProfileService
    {
        $transport=$this->transport($response);return new ProfileService($transport,new MexService($transport));
    }

    public static function outcomes():iterable
    {
        $contact=static fn(string $type):BinaryNode=>new BinaryNode('contact',['type'=>$type]);
        $lid=new BinaryNode('lid',['val'=>'123456@lid']);
        $lidError=new BinaryNode('lid',[],[new BinaryNode('error',['code'=>'404','text'=>'not-found'])]);
        $user=static fn(array $children,array $attrs=['jid'=>self::PHONE]):BinaryNode=>new BinaryNode('user',$attrs,$children);
        yield 'in'=>[[$user([$contact('in'),$lid])],'in',true,false,'123456@lid',null];
        yield 'in without LID'=>[[$user([$contact('in')])],'in',true,false,null,null];
        yield 'out cannot retain LID'=>[[$user([$contact('out'),$lid])],'out',false,false,null,null];
        yield 'invalid cannot retain LID'=>[[$user([$contact('invalid'),$lid])],'invalid',false,true,null,null];
        yield 'contact failure cannot retain mapping'=>[[$user([new BinaryNode('contact',[],[new BinaryNode('error',['code'=>'500','text'=>'lookup-failed'])]),$lid])],'error',null,false,null,['code'=>'500','text'=>'lookup-failed']];
        yield 'LID error with no contact verdict'=>[[$user([$lidError])],'error',null,false,null,['code'=>'404','text'=>'not-found']];
        yield 'LID error with confirmed contact'=>[[$user([$contact('in'),$lidError])],'in',true,false,null,['code'=>'404','text'=>'not-found']];
        yield 'out verdict overrides LID error'=>[[$user([$contact('out'),$lidError])],'out',false,false,null,null];
        yield 'invalid verdict overrides LID error'=>[[$user([$contact('invalid'),$lidError])],'invalid',false,true,null,null];
        yield 'missing response'=>[[],'unanswered',null,false,null,null];
        yield 'user without lookup verdict'=>[[$user([])],'unanswered',null,false,null,null];
        yield 'unknown contact type'=>[[$user([$contact('future-type')])],'unanswered',null,false,null,null];
        yield 'LID mapping alone does not invent contact registration'=>[[$user([$lid])],'unanswered',null,false,'123456@lid',null];
        yield 'user error'=>[[$user([new BinaryNode('error',['code'=>'503','text'=>'unavailable'])])],'error',null,false,null,['code'=>'503','text'=>'unavailable']];
        foreach(['in','out','invalid','unknown']as$type){
            $echo=new BinaryNode('contact',['type'=>$type],'+5511000000001');
            yield 'missing jid with '.$type=>[[$user([$echo],[])],$type==='unknown'?'unanswered':$type,match($type){'in'=>true,'out','invalid'=>false,default=>null},$type==='invalid',null,null];
        }
        yield 'legacy invalid jid with echoed contact'=>[[$user([new BinaryNode('contact',['type'=>'invalid'],'+5511000000001')],['jid'=>'undefined'])],'invalid',false,true,null,null];
    }

    #[DataProvider('outcomes')]
    public function testEachCorrelatedPhoneHasAnOutcome(array $users,string $status,?bool $exists,bool $invalid,?string $lid,?array $error):void
    {
        $row=$this->profile(self::response($users))->getLidsByPhoneNumbers([self::PHONE])[0];
        self::assertSame(self::PHONE,$row['queriedJid']);self::assertSame(self::PHONE,$row['phoneJid']);
        self::assertSame($status,$row['status']);self::assertSame($exists,$row['exists']);self::assertSame($invalid,$row['invalid']);
        self::assertSame($lid,$row['lidJid']);self::assertSame($error,$row['error']??null);self::assertSame(1,$this->queries);
        self::assertSame($lid,(new IdentityService($this->store))->resolveLid(self::PHONE));
    }

    public function testProtocolErrorsAreReturnedPerUnansweredPhoneWithoutDiscardingSuccessfulRows():void
    {
        $own='5511000000002@s.whatsapp.net';$empty='5511000000003@s.whatsapp.net';$out='5511000000004@s.whatsapp.net';$missing='5511000000005@s.whatsapp.net';
        $response=self::response([
            new BinaryNode('user',['jid'=>self::PHONE],[new BinaryNode('contact',['type'=>'in']),new BinaryNode('lid',['val'=>'123456@lid'])]),
            new BinaryNode('user',['jid'=>$own],[new BinaryNode('contact',[],[new BinaryNode('error',['code'=>'500','text'=>'lookup-failed'])])]),
            new BinaryNode('user',['jid'=>$empty],[new BinaryNode('lid',[],[new BinaryNode('error')])]),
            new BinaryNode('user',['jid'=>$out],[new BinaryNode('contact',['type'=>'out'])]),
        ],[
            new BinaryNode('contact',[],[new BinaryNode('error',['code'=>'429','text'=>'rate-overlimit','backoff'=>'30'])]),
            new BinaryNode('lid',[],[new BinaryNode('error',['code'=>'503','backoff'=>'5'])]),
        ]);
        $rows=$this->profile($response)->getLidsByPhoneNumbers([self::PHONE,$own,$empty,$out,$missing]);
        self::assertSame(['in','error','error','out','unanswered'],array_column($rows,'status'));
        self::assertSame([true,null,null,false,null],array_column($rows,'exists'));
        self::assertArrayNotHasKey('error',$rows[0]);self::assertSame(['code'=>'500','text'=>'lookup-failed'],$rows[1]['error']);
        $protocol=['code'=>'429','text'=>'rate-overlimit','backoffSeconds'=>30];
        self::assertSame($protocol,$rows[2]['error']);self::assertArrayNotHasKey('error',$rows[3]);self::assertSame($protocol,$rows[4]['error']);
        self::assertSame('123456@lid',(new IdentityService($this->store))->resolveLid(self::PHONE));
    }

    public function testProtocolLidFailureIsUsedWhenTheContactProtocolHasNoError():void
    {
        $row=$this->profile(self::response([], [new BinaryNode('contact'),new BinaryNode('lid',[],[new BinaryNode('error',['code'=>'503','backoff'=>'5'])])]))->getLidsByPhoneNumbers([self::PHONE])[0];
        self::assertSame('unanswered',$row['status']);self::assertNull($row['exists']);self::assertSame(['code'=>'503','backoffSeconds'=>5],$row['error']);
    }

    public static function backoffs():iterable
    {
        foreach(['-1','+30',' 30','x','1.5','99999999999999999999999999']as$value)yield $value=>[$value,null];
        yield 'zero'=>['0',0];yield 'positive'=>['30',30];yield 'leading zeroes'=>['00030',30];
    }

    #[DataProvider('backoffs')]
    public function testBackoffMustBeANonnegativeRepresentableInteger(string $raw,?int $seconds):void
    {
        $row=$this->profile(self::response([], [new BinaryNode('contact',[],[new BinaryNode('error',['code'=>'429','backoff'=>$raw])])]))->getLidsByPhoneNumbers([self::PHONE])[0];
        self::assertSame('unanswered',$row['status']);self::assertSame($seconds,$row['error']['backoffSeconds']??null);self::assertSame(1,$this->queries);
    }

    public function testMissingJidNeedsAMatchingDecodableEchoAndNeverMeansInvalidByItself():void
    {
        $rows=$this->profile(self::response([
            new BinaryNode('user',[],[new BinaryNode('contact',['type'=>'invalid'])]),
            new BinaryNode('user',[],[new BinaryNode('contact',['type'=>'invalid'],'+5511000000099')]),
            new BinaryNode('user',['jid'=>self::PHONE],[new BinaryNode('contact',['type'=>'in'],'malformed')]),
        ]))->getLidsByPhoneNumbers([self::PHONE]);
        self::assertSame('unanswered',$rows[0]['status']);self::assertFalse($rows[0]['invalid']);self::assertNull($rows[0]['exists']);
    }

    public function testConflictingRowsStayUnansweredEvenIfALaterRowRepeatsAnEarlierResult():void
    {
        $user=static fn(string $lid):BinaryNode=>new BinaryNode('user',['jid'=>self::PHONE],[new BinaryNode('contact',['type'=>'in']),new BinaryNode('lid',['val'=>$lid])]);
        $row=$this->profile(self::response([$user('123456@lid'),$user('654321@lid'),$user('123456@lid')]))->getLidsByPhoneNumbers([self::PHONE])[0];
        self::assertSame('unanswered',$row['status']);self::assertNull($row['exists']);self::assertNull($row['lidJid']);
        self::assertNull((new IdentityService($this->store))->resolveLid(self::PHONE));
    }

    public function testLocalInvalidInputsKeepTheirStatusWhenValidInputsAreUnanswered():void
    {
        $rows=$this->profile(self::response())->getLidsByPhoneNumbers(['bad',self::PHONE,self::PHONE]);
        self::assertSame(['invalid','unanswered','unanswered'],array_column($rows,'status'));self::assertSame([false,null,null],array_column($rows,'exists'));
        self::assertSame($rows[1],$rows[2]);self::assertSame(1,$this->queries);
    }

    public function testGeneralUsyncStillThrowsOnProtocolFailure():void
    {
        $transport=$this->transport(self::response([], [new BinaryNode('status',[],[new BinaryNode('error',['code'=>'403'])])]));
        try{$transport->usync([new BinaryNode('status')],[new BinaryNode('user',['jid'=>'123456@lid'])]);self::fail('Protocol refusal was hidden');}
        catch(ServiceException $error){self::assertSame('usync.status',$error->operation);self::assertSame('403',$error->serverCode);}
    }

    public function testSingleUserRoutingStillThrowsOnProtocolFailure():void
    {
        $transport=$this->transport(self::response([], [new BinaryNode('contact',[],[new BinaryNode('error',['code'=>'429'])])]));
        try{$transport->userPair(self::PHONE);self::fail('Protocol refusal must not become a routing fallback');}
        catch(ServiceException $error){self::assertSame('usync.contact',$error->operation);self::assertSame('429',$error->serverCode);self::assertSame(1,$this->queries);}
    }

    public function testIqRefusalStillThrowsAndDoesNotInventPerPhoneSuccess():void
    {
        $profile=$this->profile(new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'503'])]));
        try{$profile->getLidsByPhoneNumbers([self::PHONE]);self::fail('IQ refusal was hidden');}
        catch(ServiceException $error){self::assertSame('503',$error->serverCode);self::assertSame(1,$this->queries);}
    }

    public function testMalformedEnvelopeDoesNotLookLikeAValidEmptyLookup():void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->profile(new BinaryNode('iq',['type'=>'result']))->getLidsByPhoneNumbers([self::PHONE]);
    }

    public function testProtocolErrorIsScopedToTheChunkThatReportedIt():void
    {
        $numbers=[];for($i=0;$i<201;$i++)$numbers[]=(string)(5511000000000+$i);
        $profile=$this->profile(function(BinaryNode $request):BinaryNode{
            $users=$request->child('usync')->child('list')->content;
            if(count($users)===200)return self::response([], [new BinaryNode('contact',[],[new BinaryNode('error',['code'=>'429','backoff'=>'30'])])]);
            return self::response([new BinaryNode('user',['jid'=>$users[0]->attrs['jid']],[new BinaryNode('contact',['type'=>'in'])])]);
        });
        $rows=$profile->getLidsByPhoneNumbers($numbers);self::assertSame(2,$this->queries);self::assertCount(201,$rows);
        foreach(array_slice($rows,0,200)as$row){self::assertSame('unanswered',$row['status']);self::assertSame(30,$row['error']['backoffSeconds']);}
        self::assertSame('in',$rows[200]['status']);self::assertArrayNotHasKey('error',$rows[200]);
    }
}
