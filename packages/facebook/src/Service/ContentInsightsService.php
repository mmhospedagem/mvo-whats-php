<?php
declare(strict_types=1);
namespace MVO\Facebook\Service;

use MVO\Facebook\Catalog\ContentInsightsDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\{ContentInsightsContextReader,WebGraphQL};
use MVO\Messenger\Auth\WebSession;

/** Bounded Business Suite content reports; missing renderers are never zeroes. */
final class ContentInsightsService
{
    private const QUERY='BizWebUnifiedTableCursorPaginator_data_refetchable';
    private const COLUMNS='BizWebUnifiedTableCustomColumnModalQuery';
    public function __construct(private readonly WebGraphQL $graph,private readonly ?WebSession $session=null){}

    /** Read current Relay flags from an authenticated document, without executing its scripts.
     * @return array<string,bool> */
    public function context(string $pageId,?string $businessId=null):array
    {
        ServiceInput::actor($pageId);if($businessId!==null)ServiceInput::actor($businessId);
        if($this->session===null||$this->session->accountId()!==$this->graph->accountId())throw new FacebookException('facebook_session_required','Use the same owner Web session to load the content context');
        $query=['asset_id'=>$pageId];if($businessId!==null)$query['business_id']=$businessId;
        try{$response=$this->session->request('GET','https://business.facebook.com/latest/insights/content?'.http_build_query($query,'','&',PHP_QUERY_RFC3986));}
        catch(\Throwable){throw new FacebookException('facebook_transport_failed','The content-table bootstrap request did not complete','not_confirmed',true);}
        if($response->status!==200)throw new FacebookException('facebook_content_context_unavailable','Facebook did not return an authenticated content-table document');
        return (new ContentInsightsContextReader())->read($response->body,$this->graph->accountId());
    }

    /** @param array<string,bool> $providers
     * @return array<string,mixed> */
    public function columns(string $pageId,array $providers):array
    {
        ServiceInput::actor($pageId);
        $values=ContentInsightsDocuments::arguments(self::COLUMNS,[...$this->providers($providers,self::COLUMNS),'callerID'=>'BIZWEB_INSIGHTS_ORGANIC_CONTENT','ids'=>[$pageId]]);
        $data=$this->graph->execute(self::COLUMNS,$values,$pageId,'https://business.facebook.com');
        $table=$data['tofu_unified_table']['column']??null;
        if(!is_array($table)||!is_array($table['all_available_columns']??null)||!array_is_list($table['all_available_columns']))ServiceInput::unexpected();
        $columns=[];
        foreach($table['all_available_columns'] as $column){
            if(!is_array($column)||!is_string($column['key']??null))ServiceInput::unexpected();
            $columns[]=ServiceInput::select($column,['key','category','category_label','header','is_fixed','is_sortable','width','default_sorting_direction']);
        }
        return ['page_id'=>$pageId,'columns'=>$columns,'default_visible_keys'=>$table['default_visible_keys']??null,'source'=>self::COLUMNS];
    }

    /** @param array<string,bool> $providers
     * @param array<string,mixed> $options count (1..100), search, sort_key, sort_direction, visible_columns.
     * @return array<string,mixed> */
    public function listContent(string $pageId,string $since,string $until,array $providers,array $options=[]):array
    {
        $variables=$this->variables($pageId,$since,$until,$providers,$options);
        return $this->page($pageId,$since,$until,$options,$variables);
    }

    /** A continuation belongs to the same owner, Page, dates, flags and query options.
     * @param array<string,mixed> $continuation
     * @param array<string,bool> $providers
     * @return array<string,mixed> */
    public function next(string $pageId,array $continuation,array $providers):array
    {
        ServiceInput::keys($continuation,['version','account_id','page_id','since','until','options','cursor','scope_hash']);
        if(($continuation['version']??null)!==1||($continuation['account_id']??null)!==$this->graph->accountId()||($continuation['page_id']??null)!==$pageId||!is_string($continuation['since']??null)||!is_string($continuation['until']??null)||!is_array($continuation['options']??null)||!is_string($continuation['cursor']??null)||!is_string($continuation['scope_hash']??null))throw new \InvalidArgumentException('Use the continuation returned for this owner and Page');
        $variables=$this->variables($pageId,$continuation['since'],$continuation['until'],$providers,$continuation['options']);
        if(!hash_equals($this->scope($variables),$continuation['scope_hash']))throw new \InvalidArgumentException('The content-table context changed; start a new report');
        $variables['cursor']=ServiceInput::opaque($continuation['cursor'],'content cursor',16384);
        return $this->page($pageId,$continuation['since'],$continuation['until'],$continuation['options'],$variables);
    }

    /** Collect a bounded paginated report. Pagination completion does not establish complete organic metrics.
     * @param array<string,bool> $providers
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function report(string $pageId,string $since,string $until,array $providers,array $options=[],int $maxPages=20):array
    {
        if($maxPages<1||$maxPages>100)throw new \InvalidArgumentException('The SDK report limit is one to 100 pages');
        $page=$this->listContent($pageId,$since,$until,$providers,$options);$items=[];$seen=[];$cursors=[];$changed=false;$pages=0;$complete=true;
        do{
            $pages++;
            foreach($page['items'] as $item){
                $id=$item['row_id'];$digest=hash('sha256',json_encode($item,JSON_THROW_ON_ERROR));
                if(isset($seen[$id])){$changed=$changed||$seen[$id]!==$digest;continue;}
                $seen[$id]=$digest;$items[]=$item;$complete=$complete&&$item['metrics_complete'];
            }
            $continuation=$page['continuation'];
            if($continuation===null||$pages===$maxPages)break;
            $cursor=$continuation['cursor'];
            if(isset($cursors[$cursor]))throw new FacebookException('facebook_content_cursor_cycle','Facebook repeated a content-table cursor; no complete report is claimed');
            $cursors[$cursor]=true;$page=$this->next($pageId,$continuation,$providers);
        }while(true);
        return ['page_id'=>$pageId,'since'=>$since,'until'=>$until,'items'=>$items,'pages'=>$pages,'pagination_complete'=>$continuation===null,
            'metrics_complete'=>$complete,'complete_organic_reporting'=>false,'snapshot_consistent'=>$changed?false:null,'duplicate_rows_changed'=>$changed,'continuation'=>$continuation,
            'distribution_scope'=>'not_separated','source'=>self::QUERY,'export_scope'=>'local_normalized_report'];
    }

    /** @param array<string,bool> $providers
     * @return array<string,bool> */
    private function providers(array $providers,string $document=self::QUERY):array
    {
        ServiceInput::keys($providers,[ContentInsightsContextReader::ASSOCIATED_ADS,ContentInsightsContextReader::AM_PREVIEW]);
        $selected=[];
        foreach(ContentInsightsDocuments::providers($document) as $key){
            if(!is_bool($providers[$key]??null))throw new \InvalidArgumentException('Supply the current content-table provider flags required by this document');
            $selected[$key]=$providers[$key];
        }
        return $selected;
    }
    /** @param array<string,bool> $providers
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    private function variables(string $pageId,string $since,string $until,array $providers,array $options):array
    {
        ServiceInput::actor($pageId);self::date($since);self::date($until);
        if($until<$since)throw new \InvalidArgumentException('The report ends before it starts');
        ServiceInput::keys($options,['count','search','sort_key','sort_direction','visible_columns']);
        $count=$options['count']??10;if(!is_int($count)||$count<1||$count>100)throw new \InvalidArgumentException('Use one to 100 rows per content page');
        $search=$options['search']??'';if(!is_string($search)||strlen($search)>1000||!preg_match('//u',$search)||str_contains($search,"\0"))throw new \InvalidArgumentException('Invalid content search');
        $sort=$options['sort_key']??null;$columns=ContentInsightsDocuments::columnFields();
        if($sort!==null&&(!is_string($sort)||!isset($columns[$sort])))throw new \InvalidArgumentException('Use an observed content sort key');
        $direction=$options['sort_direction']??'DESC';if(!in_array($direction,['ASC','DESC'],true))throw new \InvalidArgumentException('Use ASC or DESC');
        $visible=$options['visible_columns']??null;
        if($visible!==null){
            if(!is_array($visible)||!array_is_list($visible)||$visible===[]||count($visible)>count($columns))throw new \InvalidArgumentException('Use a nonempty list of observed content columns');
            foreach($visible as $key)if(!is_string($key)||!isset($columns[$key]))throw new \InvalidArgumentException('Unknown content column');
            if(count(array_unique($visible))!==count($visible))throw new \InvalidArgumentException('Content columns cannot repeat');
        }
        return ContentInsightsDocuments::arguments(self::QUERY,[...$this->providers($providers),'callerID'=>'BIZWEB_INSIGHTS_ORGANIC_CONTENT','count'=>$count,'cursor'=>null,
            'filters'=>[],'ids'=>[$pageId],'initialFilters'=>[['param'=>'UNIFIED_TABLE_FEATURE','values'=>['LOAD_ONLY_VISIBLE_COLUMNS_IN_VIEWPORT']]],
            'searchString'=>$search,'skip'=>false,'sortDirection'=>$direction,'sortKey'=>$sort,
            'timeRange'=>['type'=>'CUSTOM','start_iso_date'=>$since,'end_iso_date'=>$until],'visibleColumnKeys'=>$visible]);
    }
    /** @param array<string,mixed> $variables */
    private function scope(array $variables):string{$variables['cursor']=null;return hash('sha256',json_encode($variables,JSON_THROW_ON_ERROR));}
    private static function date(string $value):void
    {
        $date=\DateTimeImmutable::createFromFormat('!Y-m-d',$value,new \DateTimeZone('UTC'));
        if($date===false||$date->format('Y-m-d')!==$value)throw new \InvalidArgumentException('Use an actual YYYY-MM-DD content date');
    }
    /** @param array<string,mixed> $options
     * @param array<string,mixed> $variables
     * @return array<string,mixed> */
    private function page(string $pageId,string $since,string $until,array $options,array $variables):array
    {
        $data=$this->graph->execute(self::QUERY,$variables,$pageId,'https://business.facebook.com');
        $content=$data['tofu_unified_table']['content']??null;
        if(!is_array($content)||!is_array($content['edges']??null)||!array_is_list($content['edges'])||count($content['edges'])>100||!is_array($content['page_info']??null)||!is_bool($content['page_info']['has_next_page']??null))ServiceInput::unexpected();
        $items=[];foreach($content['edges'] as $edge){if(!is_array($edge)||!is_array($edge['node']??null))ServiceInput::unexpected();$items[]=$this->row($edge['node'],$variables['visibleColumnKeys']);}
        $continuation=null;
        if($content['page_info']['has_next_page']){
            $cursor=$content['page_info']['end_cursor']??null;if(!is_string($cursor)||$cursor===''||$cursor===$variables['cursor'])ServiceInput::unexpected();
            $continuation=['version'=>1,'account_id'=>$this->graph->accountId(),'page_id'=>$pageId,'since'=>$since,'until'=>$until,'options'=>$options,
                'cursor'=>ServiceInput::opaque($cursor,'content cursor',16384),'scope_hash'=>$this->scope($variables)];
        }
        return ['page_id'=>$pageId,'since'=>$since,'until'=>$until,'items'=>$items,'has_next_page'=>$continuation!==null,'continuation'=>$continuation,'source'=>self::QUERY];
    }
    /** @param array<string,mixed> $row
     * @param list<string>|null $visible
     * @return array<string,mixed> */
    private function row(array $row,?array $visible):array
    {
        $id=ServiceInput::scalarId($row['row_id']??null);if($id===null)ServiceInput::unexpected();
        $header=$row['header']??null;$title=null;$entity=null;$headerType=is_array($header)?($header['__typename']??null):null;
        if($headerType==='TofuUnifiedTableOrganicContentRowHeader'){
            $entity=is_array($header['entity']??null)?ServiceInput::select($header['entity'],['id','entity_type','entity_info']):null;
            $title=is_array($entity)&&is_array($entity['entity_info']??null)&&is_string($entity['entity_info']['title']??null)?$entity['entity_info']['title']:null;
        }
        $fields=is_array($row['fields']??null)?$row['fields']:[];$cells=[];$complete=true;
        foreach(ContentInsightsDocuments::columnFields() as $column=>$field){
            if($visible!==null&&!in_array($column,$visible,true))continue;
            if($visible===null&&!array_key_exists($field,$fields))continue;
            $cell=$fields[$field]??null;$renderer=is_array($cell)&&is_array($cell['renderer']??null)?$cell['renderer']:null;
            $type=is_array($renderer)&&is_string($renderer['__typename']??null)?$renderer['__typename']:null;
            $value=null;$status='unavailable';$extra=[];
            if($type==='TofuUnifiedTableSingleValueMetricKPICell'&&is_array($renderer['result']??null)&&array_key_exists('value',$renderer['result'])){
                $value=$renderer['result']['value'];
                if($value!==null&&(!is_int($value)&&!is_float($value)||is_float($value)&&!is_finite($value)))ServiceInput::unexpected();
                $status=$value===null?'unavailable':'available';
                $extra['query_context']=is_array($renderer['result']['query_context']??null)?ServiceInput::select($renderer['result']['query_context'],['event','metric']):null;
            }elseif($type==='TofuUnifiedTableDateTimeCell'&&array_key_exists('date_time',$renderer)){
                $value=$renderer['date_time'];if($value!==null&&!is_int($value)&&!is_string($value))ServiceInput::unexpected();
                $status=$value===null?'unavailable':'available';$extra['is_scheduled']=is_bool($renderer['is_scheduled']??null)?$renderer['is_scheduled']:null;
            }elseif($type==='TofuUnifiedTableNoopCellDoNotRenderThis')$status='not_rendered';
            $complete=$complete&&in_array($status,['available','not_rendered'],true);
            $cells[$column]=['value'=>$value,'status'=>$status,'renderer'=>$type,'distribution_scope'=>'not_separated',...$extra];
        }
        return ['row_id'=>$id,'title'=>$title,'header_type'=>$headerType,'entity'=>$entity,'cells'=>$cells,'metrics_complete'=>$cells!==[]&&$complete];
    }

    /** @return array<string,mixed> */
    public function availability():array
    {
        return ['validated_in_account'=>false,'operations'=>['columns'=>true,'pagination'=>true,'bounded_report'=>true,
            'local_csv'=>true,'local_json'=>true,'paid_breakdown_renderer'=>false,'ads_liquidity_renderer'=>false,'complete_organic_reporting'=>false],
            'limit'=>'Only observed metric/date renderers are normalized; unsupported cells stay unavailable. Exports are local, and pagination does not prove an atomic snapshot or complete organic-only metrics.'];
    }
}
