<?php
declare(strict_types=1);
namespace MVO\Messenger\Transport;

/** Exact Web upload endpoints and headers, without token/cookie/redirect overrides. */
final class VideoUploadPolicy
{
    /** @param array<string,string> $form
     * @param array<string,string> $headers */
    public static function validate(string $method,string $url,#[\SensitiveParameter] array $form,array $headers,bool $raw=false):void
    {
        $p=parse_url($url);
        if(!is_array($p)||($p['scheme']??null)!=='https'||isset($p['user'])||isset($p['pass'])||isset($p['fragment'])||(isset($p['port'])&&$p['port']!==443))throw new \InvalidArgumentException('Unsupported video upload origin');
        $resumable=($p['host']??null)==='rupload.facebook.com'&&preg_match('~^/fb_video/[a-f0-9]{32}-[0-9]{1,20}-[0-9]{1,20}$~D',$p['path']??'')===1&&!isset($p['query']);
        $formUpload=($p['host']??null)==='vupload-edge.facebook.com'&&in_array($p['path']??'', ['/ajax/video/upload/requests/start/','/ajax/video/upload/requests/receive/'],true)&&preg_match('/^av=[1-9][0-9]{0,24}$/D',$p['query']??'')===1;
        if($raw?(!$resumable||$method!=='POST'||$form!==[]):(!$resumable&&!$formUpload||$resumable&&($method!=='GET'||$form!==[])||$formUpload&&$method!=='POST'))throw new \InvalidArgumentException('Unsupported video upload endpoint or method');
        $allowed=$resumable?['x-entity-name','x-entity-type','x-entity-length','offset','start_offset','end_offset','composer_session_id','id','product_media_id','x-fb-region','x-total-asset-size']:['x_fb_video_waterfall_id'];
        $seen=[];
        foreach($headers as $name=>$value){
            if(!is_string($name)||!is_string($value)||!in_array(strtolower($name),$allowed,true)||isset($seen[strtolower($name)])||strlen($value)>2048||preg_match('/[\x00-\x1f\x7f]/',$value))throw new \InvalidArgumentException('Unsupported video upload header');
            $seen[strtolower($name)]=true;
        }
    }
    /** Bind announced byte positions to the actual selected stream.
     * @param array<string,string> $headers */
    public static function validateRange(UploadFile $file,int $offset,int $length,array $headers):void
    {
        if($offset<0||$length<0||$offset>$file->size||$length>$file->size-$offset)throw new \OutOfBoundsException('The upload range exceeds the selected file');
        $normalized=[];foreach($headers as $name=>$value)$normalized[strtolower($name)]=$value;
        if(($normalized['offset']??null)!==(string)$offset||($normalized['x-entity-length']??null)!==(string)$file->size
            ||isset($normalized['x-total-asset-size'])&&$normalized['x-total-asset-size']!==(string)$file->size
            ||isset($normalized['x-entity-type'])&&$normalized['x-entity-type']!==$file->mediaType)throw new \InvalidArgumentException('The upload headers must describe the selected file and exact byte offset');
    }
}
