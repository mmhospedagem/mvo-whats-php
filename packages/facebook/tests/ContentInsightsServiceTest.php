<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\ContentInsightsDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\{ContentInsightsContextReader,WebGraphQL};
use MVO\Facebook\Service\{ContentInsightsService,ContentReportExporter};
use MVO\Messenger\Auth\WebSession;
use MVO\Messenger\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

/** Independent synthetic wire shapes from the public Relay operations, never real account data. */
final class ContentInsightsServiceTest extends TestCase
{
    private const TABLE='BizWebUnifiedTableCursorPaginator_data_refetchable';
    private const COLUMNS='BizWebUnifiedTableCustomColumnModalQuery';

    public function testCatalogMatchesBothObservedRelayArgumentsAndDistinctProviderSets():void
    {
        $registry=ContentInsightsDocuments::registry();$table=$registry->get(self::TABLE);$columns=$registry->get(self::COLUMNS);
        self::assertSame('39052890231021381',$table->id);self::assertSame('28513279061615805',$columns->id);
        self::assertSame(['callerID','ids',ContentInsightsContextReader::ASSOCIATED_ADS],$columns->variables);
        self::assertSame([ContentInsightsContextReader::ASSOCIATED_ADS],ContentInsightsDocuments::providers(self::COLUMNS));
        self::assertSame([ContentInsightsContextReader::ASSOCIATED_ADS,ContentInsightsContextReader::AM_PREVIEW],ContentInsightsDocuments::providers(self::TABLE));
        self::assertSame(10,$table->defaults['count']);self::assertTrue($table->defaults['skip']);
        self::assertSame('comments',ContentInsightsDocuments::columnFields()['COMMENTS']);self::assertSame('views',ContentInsightsDocuments::columnFields()['VIEWS']);
        foreach([$table,$columns]as $document){self::assertSame('query',$document->kind);self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D',$document->sourceSha256);}
    }

    public function testColumnsUsesTheNestedColumnConfigAndOnlyItsOwnRelayProvider():void
    {
        $session=new ContentInsightsTestSession();$session->responses[]=['tofu_unified_table'=>['__typename'=>'BizWebUnifiedTable','column'=>[
            'default_visible_keys'=>['VIEWS'],'all_available_columns'=>[['__typename'=>'BizWebUnifiedTableColumn','key'=>'VIEWS','category'=>'SYNTHETIC_CATEGORY',
                'header'=>['renderer'=>['__typename'=>'TofuUnifiedTableTextCell']],'is_fixed'=>false,'is_sortable'=>true,'width'=>120,'unrequested'=>'OMIT']]]]];
        $service=self::service($session);$result=$service->columns('20002',self::providers());
        self::assertSame(['VIEWS'],$result['default_visible_keys']);self::assertSame('VIEWS',$result['columns'][0]['key']);self::assertArrayNotHasKey('unrequested',$result['columns'][0]);
        $variables=$session->variables();self::assertSame(['20002'],$variables['ids']);self::assertSame('BIZWEB_INSIGHTS_ORGANIC_CONTENT',$variables['callerID']);
        self::assertFalse($variables[ContentInsightsContextReader::ASSOCIATED_ADS]);self::assertArrayNotHasKey(ContentInsightsContextReader::AM_PREVIEW,$variables);
        self::assertSame('20002',$session->requests[0]['form']['av']);self::assertSame('https://business.facebook.com/api/graphql/',$session->requests[0]['url']);
        $session->responses[]=['tofu_unified_table'=>['column'=>['default_visible_keys'=>[],'all_available_columns'=>[]]]];
        self::assertSame([],$service->columns('20002',[ContentInsightsContextReader::ASSOCIATED_ADS=>true])['columns']);
    }

    public function testRowsPreserveZeroMissingErrorAndUnknownRenderersWithTheActualHeaderType():void
    {
        $session=new ContentInsightsTestSession();$row=self::row();$row['fields']['comments']=['renderer'=>['__typename'=>'TofuUnifiedTableSingleValueMetricKPICell','result'=>['value'=>null]]];
        $row['fields']['reach']=['renderer'=>['__typename'=>'TofuUnifiedTableErrorCell']];
        $row['fields']['reactions']=['renderer'=>['__typename'=>'TofuUnifiedTableSingleValueMetricWithPaidBreakdownCell','result'=>['value'=>999]]];
        $row['fields']['date']=['renderer'=>['__typename'=>'TofuUnifiedTableDateTimeCell','date_time'=>'2026-10-19T14:30:00Z','is_scheduled'=>false]];
        $session->responses[]=self::table([$row]);$service=self::service($session);
        $r=$service->listContent('20002','2026-10-01','2026-10-19',self::providers(),['visible_columns'=>['VIEWS','COMMENTS','REACH','REACTIONS','DATE']]);$item=$r['items'][0];
        self::assertSame('Título fictício',$item['title']);self::assertSame('TofuUnifiedTableOrganicContentRowHeader',$item['header_type']);
        self::assertSame(0,$item['cells']['VIEWS']['value']);self::assertSame('available',$item['cells']['VIEWS']['status']);
        foreach(['COMMENTS','REACH','REACTIONS']as $column){self::assertNull($item['cells'][$column]['value']);self::assertSame('unavailable',$item['cells'][$column]['status']);}
        self::assertSame('2026-10-19T14:30:00Z',$item['cells']['DATE']['value']);self::assertFalse($item['cells']['DATE']['is_scheduled']);
        self::assertSame('not_separated',$item['cells']['VIEWS']['distribution_scope']);self::assertFalse($item['metrics_complete']);self::assertFalse($r['has_next_page']);
        $variables=$session->variables();self::assertFalse($variables['skip']);self::assertSame(['type'=>'CUSTOM','start_iso_date'=>'2026-10-01','end_iso_date'=>'2026-10-19'],$variables['timeRange']);
    }

    public function testRequestedMissingCellStaysUnavailableAndNumericStringsAreNotInventedNumbers():void
    {
        $session=new ContentInsightsTestSession();$session->responses[]=self::table([self::row()]);$service=self::service($session);
        $result=$service->listContent('20002','2026-10-01','2026-10-19',self::providers(),['visible_columns'=>['VIEWS','COMMENTS']]);
        self::assertNull($result['items'][0]['cells']['COMMENTS']['value']);self::assertFalse($result['items'][0]['metrics_complete']);
        $session->responses[]=self::table([self::row('ROW_2','0')]);
        try{$service->listContent('20002','2026-10-01','2026-10-19',self::providers());self::fail('A metric string must not silently become zero');}
        catch(FacebookException $e){self::assertSame('facebook_response_shape_unknown',$e->reason);}
    }

    public function testInvalidDatesOptionsAndProviderValuesFailBeforeIo():void
    {
        $session=new ContentInsightsTestSession();$service=self::service($session);
        $cases=[['2026-02-30','2026-10-19',self::providers(),[]],['2026-10-19','2026-10-01',self::providers(),[]],
            ['2026-10-01','2026-10-19',[],[]],['2026-10-01','2026-10-19',self::providers(),['count'=>101]],
            ['2026-10-01','2026-10-19',self::providers(),['visible_columns'=>['VIEWS','VIEWS']]],
            ['2026-10-01','2026-10-19',self::providers(),['sort_key'=>'INVENTED_METRIC']]];
        foreach($cases as [$since,$until,$providers,$options]){
            try{$service->listContent('20002',$since,$until,$providers,$options);self::fail('Invalid content context');}catch(\InvalidArgumentException){self::assertCount(0,$session->requests);}
        }
    }

    public function testContinuationBindsAccountPageDatesFlagsAndOptionsBeforeUsingItsCursor():void
    {
        $session=new ContentInsightsTestSession();$session->responses[]=self::table([self::row()],true,'CURSOR_A');$service=self::service($session);
        $first=$service->listContent('20002','2026-10-01','2026-10-19',self::providers(),['count'=>5,'visible_columns'=>['VIEWS']]);$continuation=$first['continuation'];
        foreach(['account_id'=>'99999','page_id'=>'99999','since'=>'2026-09-01','options'=>['count'=>6,'visible_columns'=>['VIEWS']]]as $key=>$value){
            $changed=[...$continuation,$key=>$value];
            try{$service->next('20002',$changed,self::providers());self::fail('Modified report scope');}catch(\InvalidArgumentException){self::assertCount(1,$session->requests);}
        }
        $providers=self::providers();$providers[ContentInsightsContextReader::AM_PREVIEW]=false;
        try{$service->next('20002',$continuation,$providers);self::fail('Changed flags');}catch(\InvalidArgumentException){self::assertCount(1,$session->requests);}
        $session->responses[]=self::table([self::row('ROW_2',4)]);$next=$service->next('20002',$continuation,self::providers());
        self::assertSame('CURSOR_A',$session->variables(1)['cursor']);self::assertSame(5,$session->variables(1)['count']);self::assertNull($next['continuation']);
    }

    public function testReportDeduplicatesRowsButDoesNotClaimAnAtomicSnapshotOrOrganicOnlyTotals():void
    {
        $session=new ContentInsightsTestSession();$session->responses[] = self::table([self::row()],true,'CURSOR_A');
        $session->responses[]=self::table([self::row(),self::row('ROW_2',3)]);$service=self::service($session);
        $result=$service->report('20002','2026-10-01','2026-10-19',self::providers(),['visible_columns'=>['VIEWS']]);
        self::assertCount(2,$result['items']);self::assertSame(2,$result['pages']);self::assertTrue($result['pagination_complete']);
        self::assertNull($result['snapshot_consistent']);self::assertFalse($result['duplicate_rows_changed']);self::assertFalse($result['complete_organic_reporting']);self::assertTrue($result['metrics_complete']);
        self::assertSame('local_normalized_report',$result['export_scope']);self::assertSame('not_separated',$result['distribution_scope']);
    }

    public function testChangedDuplicateIsFlaggedWhileTheFirstObservedRowIsPreserved():void
    {
        $session=new ContentInsightsTestSession();$session->responses[]=self::table([self::row()],true,'CURSOR_A');$session->responses[]=self::table([self::row('ROW_1',12)]);
        $result=self::service($session)->report('20002','2026-10-01','2026-10-19',self::providers(),['visible_columns'=>['VIEWS']]);
        self::assertCount(1,$result['items']);self::assertSame(0,$result['items'][0]['cells']['VIEWS']['value']);self::assertTrue($result['duplicate_rows_changed']);self::assertFalse($result['snapshot_consistent']);
    }

    public function testBoundedReportKeepsItsContinuationAndRejectsARepeatedCursorCycle():void
    {
        $session=new ContentInsightsTestSession();$session->responses[]=self::table([self::row()],true,'CURSOR_A');
        $r=self::service($session)->report('20002','2026-10-01','2026-10-19',self::providers(),[],1);
        self::assertFalse($r['pagination_complete']);self::assertSame('CURSOR_A',$r['continuation']['cursor']);self::assertCount(1,$session->requests);
        $session=new ContentInsightsTestSession();$session->responses=[self::table([self::row()],true,'CURSOR_A'),self::table([self::row('ROW_2')],true,'CURSOR_B'),self::table([self::row('ROW_3')],true,'CURSOR_A')];
        try{self::service($session)->report('20002','2026-10-01','2026-10-19',self::providers());self::fail('Cursor cycle');}
        catch(FacebookException $e){self::assertSame('facebook_content_cursor_cycle',$e->reason);self::assertCount(3,$session->requests);}
    }

    public function testContextReaderPreservesFalseAndRejectsConflictsOwnerMismatchAndStaleFlags():void
    {
        $reader=new ContentInsightsContextReader();self::assertSame(self::providers(),$reader->read(self::bootstrap(),'10001'));
        foreach([self::bootstrap('99999'),self::bootstrap('10001',true,true).self::bootstrap('10001',false,true),self::bootstrap('10001',false,null)]as $html){
            try{$reader->read($html,'10001');self::fail('Invalid or conflicting bootstrap');}catch(FacebookException){self::addToAssertionCount(1);}
        }
        $fake='<script>window.unrelated='.json_encode(['define'=>[['CurrentUserInitialData',[],['USER_ID'=>'10001']]],'gkxData'=>['1513'=>['result'=>true]]],JSON_THROW_ON_ERROR).'</script>';
        try{$reader->read($fake,'10001');self::fail('Arbitrary JavaScript is not bootstrap evidence');}catch(FacebookException $e){self::assertSame('facebook_identity_unconfirmed',$e->reason);}
    }

    public function testContextUsesOnlyTheFixedBusinessRouteAndSameOwnerSessionWithSanitizedFailures():void
    {
        $session=new ContentInsightsTestSession();$session->html=self::bootstrap();$service=self::service($session);
        self::assertSame(self::providers(),$service->context('20002','40004'));
        self::assertSame(['method'=>'GET','url'=>'https://business.facebook.com/latest/insights/content?asset_id=20002&business_id=40004','form'=>[]],$session->requests[0]);
        $session->fail=true;
        try{$service->context('20002');self::fail('Transport failed');}
        catch(FacebookException $e){self::assertSame('facebook_transport_failed',$e->reason);self::assertStringNotContainsString('SYNTHETIC_PRIVATE_TOKEN',$e->getMessage());}
        $different=new ContentInsightsTestSession();$different->account='99999';
        $service=new ContentInsightsService(new WebGraphQL($session,ContentInsightsDocuments::registry()),$different);
        try{$service->context('20002');self::fail('Different session owner');}catch(FacebookException $e){self::assertSame('facebook_session_required',$e->reason);self::assertCount(0,$different->requests);}
    }

    public function testJsonAndCsvExportPreserveZeroUnavailableStatusAndEscapeFormulaTitles():void
    {
        $session=new ContentInsightsTestSession();$row=self::row();$row['header']['entity']['entity_info']['title']='=HYPERLINK("https://example.test")';
        $session->responses[]=self::table([$row]);$report=self::service($session)->report('20002','2026-10-01','2026-10-19',self::providers(),['visible_columns'=>['VIEWS','COMMENTS']]);
        $json=json_decode(ContentReportExporter::json($report),true,64,JSON_THROW_ON_ERROR);self::assertSame(0,$json['items'][0]['cells']['VIEWS']['value']);self::assertNull($json['items'][0]['cells']['COMMENTS']['value']);
        $csv=ContentReportExporter::csv($report);$stream=fopen('php://temp','w+');fwrite($stream,$csv);rewind($stream);
        $header=fgetcsv($stream,0,',','"','');$records=[];
        while(($record=fgetcsv($stream,0,',','"',''))!==false)$records[$record[5]]=$record;
        fclose($stream);$zero=$records['VIEWS'];$missing=$records['COMMENTS'];
        self::assertSame('page_id',$header[0]);self::assertSame('0',$zero[6]);self::assertSame('available',$zero[7]);self::assertStringStartsWith("'=",$zero[4]);
        self::assertSame('',$missing[6]);self::assertSame('unavailable',$missing[7]);self::assertSame('not_separated',$missing[8]);
        self::assertFalse(self::service($session)->availability()['validated_in_account']);self::assertFalse(self::service($session)->availability()['operations']['complete_organic_reporting']);
    }

    /** @return array<string,bool> */
    private static function providers():array{return [ContentInsightsContextReader::ASSOCIATED_ADS=>false,ContentInsightsContextReader::AM_PREVIEW=>true];}
    private static function service(ContentInsightsTestSession $session):ContentInsightsService{return new ContentInsightsService(new WebGraphQL($session,ContentInsightsDocuments::registry()),$session);}
    /** @return array<string,mixed> */
    private static function row(string $id='ROW_1',int|string|null $value=0):array
    {
        return ['row_id'=>$id,'id'=>'OPAQUE_'.$id,'header'=>['__typename'=>'TofuUnifiedTableOrganicContentRowHeader','entity'=>[
            'id'=>'ENTITY_'.$id,'entity_type'=>'FB_POST','entity_info'=>['title'=>'Título fictício','id'=>'POST_'.$id]]],
            'fields'=>['views'=>['renderer'=>['__typename'=>'TofuUnifiedTableSingleValueMetricKPICell','result'=>['value'=>$value,'query_context'=>['event'=>'VIEW','metric'=>'COUNT']]]]]];
    }
    /** @param list<array<string,mixed>> $rows
     * @return array<string,mixed> */
    private static function table(array $rows,bool $next=false,?string $cursor=null):array
    {
        return ['tofu_unified_table'=>['__typename'=>'BizWebUnifiedTable','content'=>['edges'=>array_map(static fn(array $row):array=>['node'=>$row],$rows),
            'page_info'=>['has_next_page'=>$next,'end_cursor'=>$cursor]]]];
    }
    private static function bootstrap(string $account='10001',bool $associated=false,?bool $preview=true):string
    {
        $support=['justknobxData'=>['6003'=>['r'=>$associated]]];if($preview!==null)$support['gkxData']=['1513'=>['result'=>$preview]];
        return '<script type="application/json">'.json_encode(['require'=>[['ScheduledServerJS','handle',null,[['__bbox'=>[
            'define'=>[['CurrentUserInitialData',[],['USER_ID'=>$account],1]],'require'=>[['HasteSupportData','handle',null,[$support]]]]]]]]],JSON_THROW_ON_ERROR).'</script>';
    }
}

final class ContentInsightsTestSession implements WebSession
{
    public string $account='10001';public string $html='';public bool $fail=false;
    /** @var list<array<string,mixed>> */public array $responses=[];
    /** @var list<array{method:string,url:string,form:array<string,string>}> */public array $requests=[];
    public function accountId():string{return $this->account;}
    public function privateRequestFields():array{return ['__user'=>$this->account,'fb_dtsg'=>'SYNTHETIC_CSRF'];}
    public function request(string $method,string $url,array $form=[]):HttpResponse
    {
        $this->requests[]=['method'=>$method,'url'=>$url,'form'=>$form];if($this->fail)throw new \RuntimeException('SYNTHETIC_PRIVATE_TOKEN');
        if($method==='GET')return new HttpResponse(200,$this->html);
        return new HttpResponse(200,json_encode(['data'=>array_shift($this->responses)??[]],JSON_THROW_ON_ERROR));
    }
    /** @return array<string,mixed> */
    public function variables(int $index=0):array{return json_decode($this->requests[$index]['form']['variables'],true,64,JSON_THROW_ON_ERROR);}
}
