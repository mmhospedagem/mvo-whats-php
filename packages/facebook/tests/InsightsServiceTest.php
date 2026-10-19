<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Service\InsightsService;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageManagementFixture.php';

final class InsightsServiceTest extends TestCase
{
    public function testMetricUsesObservedPairAndDoesNotCallTheTotalOrganic():void
    {
        $f=new PageManagementFixture(['tofu_metrics_query'=>['__typename'=>'TofuSingleValueMetricsQueryResult','value'=>42,'delta'=>null]]);
        try{
            $service=new InsightsService($f->graph);$r=$service->singleValue('20002','Views','2026-10-01','2026-10-17','BIZWEB_PROFILE_PLUS_INSIGHTS');
            self::assertSame(42,$r['value']);self::assertSame('not_separated',$r['distribution_scope']);
            $arg=$f->session->variables()['arg'];self::assertSame('VIEW',$arg['event']);self::assertSame('COUNT',$arg['tofu_metric']);
            self::assertSame('20002',$arg['id']);self::assertSame('20002',$f->session->requests[0]['form']['av']);
            self::assertSame('https://business.facebook.com/api/graphql/',$f->session->requests[0]['url']);
        }finally{unset($service);$f->close();}
    }

    public function testSeriesKeepsNullAndMissingDaysWithoutInventingZeroes():void
    {
        $f=new PageManagementFixture(['tofu_metrics_query'=>['__typename'=>'TofuTimeSeriesWithoutBreakdownMetricsQueryResult','points'=>[['start_time'=>1790812800,'value'=>null],['start_time'=>1790985600,'value'=>5]]]]);
        try{
            $service=new InsightsService($f->graph);$r=$service->timeSeries('20002','Reach','2026-10-01','2026-10-03','BIZWEB_PROFILE_PLUS_INSIGHTS');
            self::assertCount(2,$r['points']);self::assertNull($r['points'][0]['value']);self::assertSame('DAILY',$f->session->variables()['arg']['time_granularity']);
        }finally{unset($service);$f->close();}
    }

    public function testPaidOrganicBreakdownPreservesExactServerNames():void
    {
        $f=new PageManagementFixture(['tofu_metrics_query'=>['__typename'=>'TofuSingleValueWithBreakdownMetricsQueryResult','bucket_values'=>[['bucket_names'=>['organic'],'bucket_value'=>50],['bucket_names'=>['paid'],'bucket_value'=>12]]]]);
        try{
            $service=new InsightsService($f->graph);$r=$service->breakdown('20002','Reach','PAID_ORGANIC','2026-10-01','2026-10-03','BIZWEB_PROFILE_PLUS_INSIGHTS');
            self::assertSame(['organic'],$r['buckets'][0]['names']);self::assertSame(['PAID_ORGANIC'],$f->session->variables()['arg']['breakdowns']);
            self::assertSame('OVERALL',$f->session->variables()['arg']['time_granularity']);
        }finally{unset($service);$f->close();}
    }

    public function testUpstreamErrorNeverBecomesAnEmptySuccessfulReport():void
    {
        $f=new PageManagementFixture(['tofu_metrics_query'=>['__typename'=>'TofuErrorQueryResult','code'=>'FORBIDDEN']]);
        try{
            $service=new InsightsService($f->graph);
            try{$service->singleValue('20002','Views','2026-10-01','2026-10-17','CURRENT_CALLER');self::fail('Expected upstream metric error');}
            catch(FacebookException $e){self::assertSame('facebook_insight_unavailable',$e->reason);}
        }finally{unset($service);$f->close();}
    }

    public function testInvalidDateAndInventedMetricFailBeforeIO():void
    {
        $f=new PageManagementFixture();
        try{
            $service=new InsightsService($f->graph);
            foreach([['Views','2026-02-30'],['Unknown','2026-10-01']]as [$metric,$date]){
                try{$service->singleValue('20002',$metric,$date,'2026-10-17','CURRENT_CALLER');self::fail('Invalid input');}
                catch(\InvalidArgumentException){self::assertCount(0,$f->session->requests);}
            }
        }finally{unset($service);$f->close();}
    }
}
