<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Service\BusinessFormStore;
use MVO\Signal\SignalStore;
use MVO\Store\{KeyValueStore,SqliteStore};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BusinessFormStoreTest extends TestCase
{
    private SignalStore $store;
    private int $clock=1792666800000;
    protected function setUp():void{$this->store=new SignalStore(':memory:');}
    protected function tearDown():void{$this->store->close();}
    private function service(?SignalStore $store=null):BusinessFormStore{return new BusinessFormStore($store??$this->store,fn():int=>$this->clock);}
    private static function input(array $changes=[]):array{return $changes+['form_title'=>'Cadastro MVO','form_description'=>'Dados para atendimento','form_type'=>'CUSTOMER_INFO','message_text'=>'Preencha seus dados.','selected_field_list'=>['NAME','PHONE_NUMBER','EMAIL']];}
    private static function custom():array{return self::input(['selected_field_list'=>['NAME','CUSTOM','CUSTOM','CUSTOM'],'custom_field_names'=>['Assunto','Detalhes','Plano'],'custom_field_supporting_texts'=>['Informe o assunto','','Selecione um plano'],'custom_field_input_types'=>['TEXT_INPUT','TEXT_AREA','DROPDOWN'],'custom_field_options'=>[[],[],['Básico','Avançado']],'edited_field_keys'=>['NAME'],'edited_field_names'=>['Nome completo'],'is_ai_form'=>true]);}

    public function testCreatePreservesAndroidShapeWithDecimalIdAlias():void
    {
        $row=$this->service()->create(self::custom());self::assertSame('1792666800000',$row['id']);self::assertSame(1792666800000,$row['date_time']);self::assertSame('Cadastro MVO',$row['form_title']);self::assertSame(['NAME','CUSTOM','CUSTOM','CUSTOM'],$row['selected_field_list']);self::assertSame(['TEXT_INPUT','TEXT_AREA','DROPDOWN'],$row['custom_field_input_types']);self::assertTrue($row['is_ai_form']);self::assertSame($row,$this->service()->get($row['id']));
    }
    public function testRepeatedAndBackwardClockAllocateDistinctPersistentMonotonicIds():void
    {
        $first=$this->service()->create(self::input());$second=$this->service()->create(self::input());$this->clock--;$third=$this->service()->create(self::input());
        self::assertSame(['1792666800000','1792666800001','1792666800002'],[$first['id'],$second['id'],$third['id']]);$this->service()->delete($third['id']);$fourth=$this->service()->create(self::input());self::assertSame('1792666800003',$fourth['id']);
    }
    public function testNameCollisionsUseAndroidSuffixAndLowestUnusedNumber():void
    {
        $forms=$this->service();$first=$forms->create(self::input());$second=$forms->duplicate($first['id']);$third=$forms->duplicate($first['id']);
        self::assertSame('Cadastro MVO(1)',$second['form_title']);self::assertSame('Cadastro MVO(2)',$third['form_title']);$forms->delete($second['id']);
        $copy=$forms->duplicate($first['id']);self::assertSame('Cadastro MVO(1)',$copy['form_title']);$renamed=$forms->rename($copy['id'],'Outro nome');self::assertSame('Outro nome',$renamed['form_title']);
        self::assertSame('Cadastro MVO(1)',$forms->rename($copy['id'],'Cadastro MVO')['form_title']);self::assertSame('Cadastro MVO',$forms->rename($first['id'],'Cadastro MVO')['form_title']);
    }
    public function testUpdateIsPartialAndPreservesIdentityAndUnchangedFields():void
    {
        $forms=$this->service();$before=$forms->create(self::custom());$after=$forms->update($before['id'],['message_text'=>'Mensagem nova','is_ai_form'=>false]);
        self::assertSame($before['id'],$after['id']);self::assertSame($before['date_time'],$after['date_time']);self::assertSame($before['custom_field_options'],$after['custom_field_options']);self::assertSame('Mensagem nova',$after['message_text']);self::assertFalse($after['is_ai_form']);self::assertSame($after,$forms->update($before['id'],[]));
        try{$forms->update($before['id'],['date_time'=>1]);self::fail('Immutable ID changed');}catch(\InvalidArgumentException){}self::assertSame($after,$forms->get($before['id']));
    }
    public function testDeleteAndMissingRecordsHaveExplicitOutcomes():void
    {
        $forms=$this->service();self::assertNull($forms->get('123'));self::assertFalse($forms->delete('123'));$row=$forms->create(self::input());self::assertTrue($forms->delete($row['id']));self::assertFalse($forms->delete($row['id']));
        foreach(['update','duplicate','rename']as$method){try{match($method){'update'=>$forms->update('123',[]),'duplicate'=>$forms->duplicate('123'),'rename'=>$forms->rename('123','Missing')};self::fail('Missing record was created');}catch(\OutOfBoundsException){}}
    }
    public function testChronologicalPaginationUsesExactLastId():void
    {
        $forms=$this->service();$rows=[];for($i=0;$i<5;$i++)$rows[]=$forms->create(self::input(['form_title'=>'Form '.$i]));
        self::assertSame(array_slice($rows,0,2),$forms->list(2));self::assertSame(array_slice($rows,2,2),$forms->list(2,$rows[1]['id']));self::assertSame([],$forms->list(2,$rows[4]['id']));
        foreach([0,1001]as$limit){try{$forms->list($limit);self::fail();}catch(\InvalidArgumentException){}}
    }
    public function testTwoLocalStartingTemplatesAreDefinitionsRatherThanStoredOrRemoteIds():void
    {
        $forms=$this->service();$templates=$forms->templates();self::assertSame(['customer_info','offer_signup'],array_keys($templates));self::assertSame('CUSTOMER_INFO',$templates['customer_info']['form_type']);self::assertSame('OFFER_SIGNUP',$templates['offer_signup']['form_type']);
        foreach($templates as$template){self::assertSame(['NAME','PHONE_NUMBER','EMAIL'],$template['selected_field_list']);self::assertArrayNotHasKey('id',$template);self::assertArrayNotHasKey('date_time',$template);self::assertIsString($forms->create($template)['id']);}self::assertCount(2,$forms->list());
    }
    public function testExportRoundTripUsesObservedAndroidArrayWithoutSdkIdAlias():void
    {
        $forms=$this->service();$first=$forms->create(self::custom());$second=$forms->create(self::input(['form_title'=>'Outro']));$json=$forms->exportTemplates();$wire=json_decode($json,true,512,JSON_THROW_ON_ERROR);
        self::assertSame($first['date_time'],$wire[0]['date_time']);self::assertArrayNotHasKey('id',$wire[0]);self::assertArrayNotHasKey('custom_field_names',$wire[1]);self::assertArrayNotHasKey('is_ai_form',$wire[1]);self::assertSame(['Nome completo'],$wire[0]['edited_field_names']);
        $other=new SignalStore(':memory:');try{$restored=$this->service($other)->importTemplates($json);self::assertSame([$first,$second],$restored);self::assertSame($json,$this->service($other)->exportTemplates());}finally{$other->close();}
    }
    public function testImportAcceptsTimestampStringsAndMatchingSdkAlias():void
    {
        $forms=$this->service();$row=self::input()+['date_time'=>'42','id'=>'42'];$imported=$forms->importTemplates(json_encode([$row],JSON_THROW_ON_ERROR));self::assertSame('42',$imported[0]['id']);self::assertSame(42,$imported[0]['date_time']);
    }
    public function testImportDuplicateOrExistingIdsNeverPartiallyMutateCatalogue():void
    {
        $forms=$this->service();$before=$forms->create(self::input());$jsonBefore=$forms->exportTemplates();
        foreach([
            [self::input()+['date_time'=>42],self::input()+['date_time'=>'42']],
            [self::input()+['date_time'=>42],self::input()+['date_time'=>$before['date_time']]],
            [self::input()+['date_time'=>42],self::input(['is_ai_form'=>1])+['date_time'=>43]],
            [self::input()+['date_time'=>42,'id'=>'43']],
        ]as$rows){try{$forms->importTemplates(json_encode($rows,JSON_THROW_ON_ERROR));self::fail('Invalid import accepted');}catch(\InvalidArgumentException|\LogicException){}self::assertSame($jsonBefore,$forms->exportTemplates());}
    }
    public function testReplaceOnlyAffectsLocalCatalogueAndKeepsFutureIdsMonotonic():void
    {
        $forms=$this->service();$old=$forms->create(self::input());$replacement=self::input(['form_title'=>'Importado'])+['date_time'=>9000000000000];$forms->importTemplates(json_encode([$replacement],JSON_THROW_ON_ERROR),true);
        self::assertNull($forms->get($old['id']));self::assertCount(1,$forms->list());self::assertSame('9000000000001',$forms->create(self::input())['id']);$forms->importTemplates('[]',true);self::assertSame([],$forms->list());self::assertSame('9000000000002',$forms->create(self::input())['id']);
    }
    public function testImportedTimestampHighWaterSurvivesImmediateReplacement():void
    {
        $forms=$this->service();$forms->importTemplates(json_encode([self::input()+['date_time'=>9000000000000]],JSON_THROW_ON_ERROR));$forms->importTemplates('[]',true);self::assertSame('9000000000001',$forms->create(self::input())['id']);
    }
    public function testImportTransactionRollsBackDeletesAndWritesWhenBackendFails():void
    {
        $backend=new BusinessFormFailingBackend(new SqliteStore(':memory:','owner'));$store=new SignalStore(':memory:','owner',$backend);
        try{$forms=$this->service($store);$forms->create(self::input());$before=$forms->exportTemplates();$backend->failOnSet=2;$rows=[self::input()+['date_time'=>10],self::input(['form_title'=>'Another'])+['date_time'=>11]];
            try{$forms->importTemplates(json_encode($rows,JSON_THROW_ON_ERROR),true);self::fail('Backend failure ignored');}catch(\RuntimeException){}self::assertSame($before,$forms->exportTemplates());
        }finally{$store->close();}
    }
    public function testSqliteReopeningAndDifferentSessionIsolation():void
    {
        $path=tempnam(sys_get_temp_dir(),'mvo-business-form-');self::assertIsString($path);
        try{$owner=new SignalStore($path,'owner');$created=$this->service($owner)->create(self::custom());$owner->close();$owner=new SignalStore($path,'owner');self::assertSame($created,$this->service($owner)->get($created['id']));$owner->close();
            $other=new SignalStore($path,'other');self::assertNull($this->service($other)->get($created['id']));self::assertSame([],$this->service($other)->list());$other->close();
        }finally{foreach([$path,$path.'-wal',$path.'-shm']as$file)if(is_file($file))unlink($file);}
    }
    #[DataProvider('invalidDefinitions')]
    public function testInvalidDefinitionCannotCreateOrMutate(array $changes):void
    {
        $forms=$this->service();try{$forms->create(array_replace(self::input(),$changes));self::fail('Invalid saved definition accepted');}catch(\InvalidArgumentException){}self::assertSame([],$forms->list());
    }
    public static function invalidDefinitions():iterable
    {
        yield 'blank title'=>[['form_title'=>'  ']];yield 'invalid utf8'=>[['form_title'=>"\xff"]];yield 'nul'=>[['message_text'=>"a\0b"]];yield 'unknown field'=>[['endpoint'=>'https://example.com']];yield 'wrong type'=>[['form_type'=>'FLOW']];yield 'boolean int'=>[['is_ai_form'=>1]];yield 'boolean string'=>[['is_ai_form'=>'false']];yield 'null'=>[['form_description'=>null]];yield 'no fields'=>[['selected_field_list'=>[]]];yield 'invented field'=>[['selected_field_list'=>['FULLNAME']]];yield 'duplicated field'=>[['selected_field_list'=>['NAME','NAME']]];yield 'scalar list'=>[['selected_field_list'=>'NAME']];yield 'map list'=>[['selected_field_list'=>['name'=>'NAME']]];
        yield 'missing custom arrays'=>[['selected_field_list'=>['CUSTOM']]];yield 'extra custom arrays'=>[['custom_field_names'=>['Name']]];yield 'mismatched edited arrays'=>[['edited_field_keys'=>['NAME']]];yield 'unknown edited key'=>[['edited_field_keys'=>['FULLNAME'],'edited_field_names'=>['Name']]];
        $custom=self::custom();yield 'unknown custom type'=>[array_replace($custom,['custom_field_input_types'=>['INPUT','TEXT_AREA','DROPDOWN']])];yield 'empty dropdown'=>[array_replace($custom,['custom_field_options'=>[[],[],[]]])];yield 'text with options'=>[array_replace($custom,['custom_field_options'=>[['A'],[],['B']]])];yield 'duplicated options'=>[array_replace($custom,['custom_field_options'=>[[],[],['A','A']]])];yield 'blank custom name'=>[array_replace($custom,['custom_field_names'=>['','Description','Plan']])];yield 'too many fields'=>[['selected_field_list'=>array_fill(0,BusinessFormStore::MAX_FIELDS+1,'CUSTOM')]];
    }
    #[DataProvider('invalidIds')]
    public function testInvalidIdIsRejected(string $id):void{$this->expectException(\InvalidArgumentException::class);$this->service()->get($id);}
    public static function invalidIds():iterable{foreach(['','-1','01','1.0','abc','9223372036854775808']as$id)yield [$id];}
    public function testInvalidImportRootAndSizeLeaveDataUnchanged():void
    {
        $forms=$this->service();foreach(['{}','null','invalid','[{}]',str_repeat(' ',2097153)]as$json){try{$forms->importTemplates($json);self::fail();}catch(\InvalidArgumentException|\LengthException){}self::assertSame([],$forms->list());}
    }
    public function testAllObservedAndroidFieldNamesCanBeStoredWithoutInventingVisibilityMapping():void
    {
        $fields=['CPF_CNPJ','ADDRESS','DELIVERY_ADDRESS','CEP','STATE','CITY','NEIGHBORHOOD','STREET_AND_NUMBER','HOUSE_OR_APARTMENT','COMPLEMENT','DEPARTMENT','COUNTRY_REGION','CITIZENSHIP_CARD','ZIP_CODE','ADDRESS_LINE_1','ADDRESS_LINE_2','DELIVERY_NOTE'];
        self::assertSame($fields,$this->service()->create(self::input(['selected_field_list'=>$fields]))['selected_field_list']);
    }
}

/** Backend-independent atomicity contract: fail the second write inside a real SQLite transaction. */
final class BusinessFormFailingBackend implements KeyValueStore
{
    public int $failOnSet=0;
    public function __construct(private readonly KeyValueStore $inner){}
    public function get(string $namespace,string $key):?string{return $this->inner->get($namespace,$key);}
    public function set(string $namespace,string $key,string $value):void{if($this->failOnSet>0&&--$this->failOnSet===0)throw new \RuntimeException('Simulated backend write failure');$this->inner->set($namespace,$key,$value);}
    public function delete(string $namespace,string $key):void{$this->inner->delete($namespace,$key);}
    public function entries(string $namespace,int $limit=1000,string $after=''):array{return $this->inner->entries($namespace,$limit,$after);}
    public function transaction(callable $operation):mixed{return $this->inner->transaction(fn():mixed=>$operation($this));}
    public function close():void{$this->inner->close();}
}
