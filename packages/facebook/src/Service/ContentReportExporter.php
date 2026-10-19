<?php
declare(strict_types=1);
namespace MVO\Facebook\Service;

/** Local exports of the normalized SDK report, not Meta's asynchronous export API. */
final class ContentReportExporter
{
    /** @param array<string,mixed> $report */
    public static function json(array $report):string
    {
        self::validate($report);return json_encode($report,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRETTY_PRINT)."\n";
    }
    /** Each metric keeps its availability and distribution scope. Untrusted formulas are escaped.
     * @param array<string,mixed> $report */
    public static function csv(array $report):string
    {
        self::validate($report);$stream=fopen('php://temp','w+');if($stream===false)throw new \RuntimeException('Unable to create the local CSV buffer');
        try{
            fputcsv($stream,['page_id','since','until','row_id','title','column','value','status','distribution_scope','renderer'],',','"','');
            foreach($report['items'] as $row){
                if(!is_array($row)||!is_array($row['cells']??null))throw new \InvalidArgumentException('Use a normalized content report');
                foreach($row['cells'] as $column=>$cell){
                    if(!is_array($cell))throw new \InvalidArgumentException('Use normalized content cells');
                    $values=[$report['page_id'],$report['since'],$report['until'],$row['row_id']??null,$row['title']??null,$column,$cell['value']??null,$cell['status']??null,$cell['distribution_scope']??null,$cell['renderer']??null];
                    fputcsv($stream,array_map(self::scalar(...),$values),',','"','');
                }
            }
            rewind($stream);$csv=stream_get_contents($stream);if($csv===false)throw new \RuntimeException('Unable to read the local CSV buffer');return $csv;
        }finally{fclose($stream);}
    }
    private static function scalar(mixed $value):string
    {
        if($value===null)return '';
        if(is_int($value)||is_float($value))return (string)$value;
        if(!is_string($value))throw new \InvalidArgumentException('Content CSV values must be scalar');
        return preg_match('/^[\s\x00-\x1f]*[=+\-@\t\r]/u',$value)===1?"'".$value:$value;
    }
    /** @param array<string,mixed> $report */
    private static function validate(array $report):void
    {
        foreach(['page_id','since','until'] as $key)if(!is_string($report[$key]??null))throw new \InvalidArgumentException('Use the normalized content report returned by the SDK');
        if(($report['export_scope']??null)!=='local_normalized_report'||!is_array($report['items']??null)||!array_is_list($report['items']))throw new \InvalidArgumentException('Use the normalized content report returned by the SDK');
    }
}
