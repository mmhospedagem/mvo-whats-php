<?php
declare(strict_types=1);

namespace MVO\Facebook\Service;

use MVO\Facebook\Catalog\PageManagementDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\WebGraphQL;

/** Business Suite Web metrics. Distribution totals are never relabelled as organic-only. */
final class InsightsService
{
    public function __construct(private readonly WebGraphQL $graph) {}
    /** Observed metric pairs; support for each Page/date range is determined by Facebook.
     * @return array<string,array{event:string,metric:string}>
     */
    public function metrics():array{return PageManagementDocuments::insightMetrics();}

    /** @return array<string,mixed> */
    public function singleValue(string $pageId,string $metric,string $since,string $until,string $callerId):array
    {
        $arg=$this->argument($pageId,$metric,$since,$until);
        $arg['compare_time_range']=null;$arg['delta_formats']=['DELTA_RAW_VALUE','DELTA_PERCENTAGE','RAW_PREVIOUS_VALUE'];
        $data=$this->graph->execute('useBizWebInsightsSingleValueQuery',['arg'=>$arg,'callerID'=>self::caller($callerId),'shouldQueryDefinition'=>false],$pageId,'https://business.facebook.com');
        $result=self::result($data,'TofuSingleValueMetricsQueryResult');
        if(!array_key_exists('value',$result))ServiceInput::unexpected();
        $value=self::number($result['value']);$delta=$result['delta']??null;
        if($delta!==null&&!is_array($delta))ServiceInput::unexpected();
        return ['page_id'=>$pageId,'metric'=>$metric,'value'=>$value,'time_range'=>$arg['time_range'],
            'delta'=>$delta===null?null:['value'=>self::number($delta['value']??null),'percentage'=>self::number($delta['percentage']??null),
                'compared_time_range_text'=>is_string($delta['compared_time_range_text']??null)?$delta['compared_time_range_text']:null],
            'distribution_scope'=>'not_separated','source'=>'useBizWebInsightsSingleValueQuery'];
    }

    /** Daily raw server timestamps, without fabricating missing dates or interpreting missing values as zero.
     * @return array<string,mixed>
     */
    public function timeSeries(string $pageId,string $metric,string $since,string $until,string $callerId):array
    {
        $arg=$this->argument($pageId,$metric,$since,$until);$arg['compare_time_range']=null;$arg['time_granularity']='DAILY';
        $data=$this->graph->execute('useBizWebInsightsPrivateSingleLineChartWithoutBreakdownQuery',['arg'=>$arg,'callerID'=>self::caller($callerId)],$pageId,'https://business.facebook.com');
        $result=self::result($data,'TofuTimeSeriesWithoutBreakdownMetricsQueryResult');
        if(!is_array($result['points']??null)||!array_is_list($result['points'])||count($result['points'])>10000)ServiceInput::unexpected();
        $points=[];
        foreach($result['points'] as $point){
            if(!is_array($point)||!array_key_exists('start_time',$point)||!array_key_exists('value',$point))ServiceInput::unexpected();
            $timestamp=$point['start_time'];
            if(!is_int($timestamp)&&!(is_string($timestamp)&&preg_match('/^[0-9]{1,20}$/D',$timestamp)===1))ServiceInput::unexpected();
            $points[]=['start_time'=>$timestamp,'value'=>self::number($point['value'])];
        }
        return ['page_id'=>$pageId,'metric'=>$metric,'time_range'=>$arg['time_range'],'granularity'=>'DAILY','points'=>$points,
            'distribution_scope'=>'not_separated','source'=>'useBizWebInsightsPrivateSingleLineChartWithoutBreakdownQuery'];
    }

    /** PAID_ORGANIC preserves Facebook's exact bucket labels; unsupported breakdowns produce an upstream error.
     * @return array<string,mixed>
     */
    public function breakdown(string $pageId,string $metric,string $breakdown,string $since,string $until,string $callerId):array
    {
        if(!in_array($breakdown,PageManagementDocuments::insightBreakdowns(),true))throw new \InvalidArgumentException('Use a breakdown exposed by the observed Business Suite metric enum');
        $arg=$this->argument($pageId,$metric,$since,$until);$arg['breakdowns']=[$breakdown];$arg['time_granularity']='OVERALL';
        $data=$this->graph->execute('useBizWebInsightsRankingChartQueryQuery',['arg'=>$arg,'callerID'=>self::caller($callerId)],$pageId,'https://business.facebook.com');
        $result=self::result($data,'TofuSingleValueWithBreakdownMetricsQueryResult');
        if(!is_array($result['bucket_values']??null)||!array_is_list($result['bucket_values'])||count($result['bucket_values'])>10000)ServiceInput::unexpected();
        $buckets=[];
        foreach($result['bucket_values'] as $bucket){
            if(!is_array($bucket)||!is_array($bucket['bucket_names']??null)||!array_is_list($bucket['bucket_names'])||!array_key_exists('bucket_value',$bucket))ServiceInput::unexpected();
            foreach($bucket['bucket_names'] as $name)if(!is_string($name))ServiceInput::unexpected();
            $buckets[]=['names'=>$bucket['bucket_names'],'value'=>self::number($bucket['bucket_value'])];
        }
        return ['page_id'=>$pageId,'metric'=>$metric,'breakdown'=>$breakdown,'time_range'=>$arg['time_range'],'buckets'=>$buckets,
            'distribution_scope'=>$breakdown==='PAID_ORGANIC'?'server_paid_organic_buckets':'not_separated','source'=>'useBizWebInsightsRankingChartQueryQuery'];
    }

    /** @return array<string,mixed> */
    public function availability():array
    {
        return ['validated_in_account'=>false,'operations'=>['single_value'=>true,'time_series'=>true,'breakdown'=>true,
            'organic_content_table'=>true,'complete_organic_reporting'=>false,'exports'=>false,'local_content_exports'=>true],
            'metrics'=>array_keys($this->metrics()),'breakdowns'=>PageManagementDocuments::insightBreakdowns(),
            'content_service'=>'contentInsights','limit'=>'Bounded observed Web metrics; totals can include paid traffic. The separate content service paginates known cells and exports locally; complete organic metrics and Meta exports remain unavailable'];
    }

    /** @return array<string,mixed> */
    private function argument(string $pageId,string $metric,string $since,string $until):array
    {
        ServiceInput::actor($pageId);$pair=$this->metrics()[$metric]??null;
        if($pair===null)throw new \InvalidArgumentException('Use an observed metric name from metrics()');
        $start=self::date($since);$end=self::date($until);
        if($end<$start)throw new \InvalidArgumentException('The insight date range ends before it starts');
        return ['id'=>$pageId,'event'=>$pair['event'],'tofu_metric'=>$pair['metric'],'breakdowns'=>[],
            'time_range'=>['type'=>'CUSTOM','start_iso_date'=>$since,'end_iso_date'=>$until]];
    }
    private static function date(string $value):\DateTimeImmutable
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));$errors=\DateTimeImmutable::getLastErrors();
        if($date===false||$date->format('Y-m-d')!==$value||($errors!==false&&($errors['warning_count']!==0||$errors['error_count']!==0)))throw new \InvalidArgumentException('Use an actual YYYY-MM-DD insight date');
        return $date;
    }
    private static function caller(string $id):string{return ServiceInput::opaque($id,'current Business Suite caller ID',160);}
    /** @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private static function result(array $data,string $type):array
    {
        $result=$data['tofu_metrics_query']??null;
        if(!is_array($result)||!is_string($result['__typename']??null))ServiceInput::unexpected();
        if($result['__typename']==='TofuErrorQueryResult')throw new FacebookException('facebook_insight_unavailable','Facebook returned a metric availability or permission error; this is not a zero-valued metric','not_confirmed');
        if($result['__typename']!==$type)ServiceInput::unexpected();
        return $result;
    }
    private static function number(mixed $value):int|float|null
    {
        if($value===null)return null;
        if(!is_int($value)&&!is_float($value))ServiceInput::unexpected();
        if(is_float($value)&&!is_finite($value))ServiceInput::unexpected();
        return $value;
    }
}
