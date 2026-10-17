<?php
declare(strict_types=1);
namespace MVO\Message;

/** JSON-safe projections of authenticated rich bot replies, never a recursive string scraper. */
final class BotResponseContent
{
    private const MAX_BYTES = 1048576;
    private const MAX_BLOCKS = 512;
    private const MAX_PROJECTED_BYTES = 2097152;

    /** The unified layout and text primitives mirror the public Web reader.
     * Unknown primitives remain explicit; download credentials and raw JSON are not projected.
     *
     * @param array<string,mixed> $message
     *
     * @return array{text:?string,responseMode:string,revisionTimestampMs:?int,richResponse:array{format:string,status:string,blocks:list<array<string,mixed>>,truncated:bool}}|null */
    public static function decode(array $message): ?array
    {
        $unwrapped=ContentDecoder::unwrap($message);$message=$unwrapped['message'];
        // Streaming bot edits carry a complete replacement snapshot inside ProtocolMessage.
        // Do not recurse through quoted messages or arbitrary nested text fields.
        $protocol = $message['protocolMessage'] ?? null;
        if (is_array($protocol) && in_array($protocol['type'] ?? null,[14,'MESSAGE_EDIT'],true) && is_array($protocol['editedMessage'] ?? null)) $message = ContentDecoder::unwrap($protocol['editedMessage'])['message'];
        $rich = $message['richResponseMessage'] ?? null;
        if (!is_array($rich)) return null;
        $truncated = false; $invalid = false; $unsupported = false; $blocks = []; $format = 'legacy';$projectedBytes=0;
        $bytes = $rich['unifiedResponse']['data'] ?? null;
        if (is_string($bytes)) {
            $format = 'unified';
            if (strlen($bytes) > self::MAX_BYTES) { $truncated = true; $invalid = true; }
            else {
                try { $unified = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR); }
                catch (\JsonException) { $unified = null; }
                if (!is_array($unified) || array_is_list($unified) || !is_array($unified['sections'] ?? null) || !array_is_list($unified['sections'])) $invalid = true;
                else {
                    foreach ($unified['sections'] as $section) {
                        if (!is_array($section) || !is_array($section['view_model'] ?? null)) { $unsupported = true; continue; }
                        $view = $section['view_model'];
                        $primitives = match ($view['__typename'] ?? null) {
                            'GenAISingleLayoutViewModel' => isset($view['primitive']) ? [$view['primitive']] : [],
                            'GenAIGridLayoutViewModel', 'GenAIHScrollLayoutViewModel' => is_array($view['primitives'] ?? null) && array_is_list($view['primitives']) ? $view['primitives'] : [],
                            default => [],
                        };
                        if ($primitives === []) $unsupported = true;
                        foreach ($primitives as $primitive) {
                            if (count($blocks) >= self::MAX_BLOCKS) { $truncated = true; break 2; }
                            if (!is_array($primitive)) { $unsupported = true; continue; }
                            $block = self::unifiedBlock($primitive, $truncated);
                            if ($block === null) { $unsupported = true; continue; }
                            if(!self::fits($block,$projectedBytes)){$truncated=true;break 2;}
                            if ($block['type'] === 'unsupported') $unsupported = true;
                            $blocks[] = $block;
                        }
                    }
                }
            }
        }
        // Some versions include both formats. Prefer the unified response to avoid duplicating the answer.
        if ($blocks === [] && is_array($rich['submessages'] ?? null)) {
            foreach ($rich['submessages'] as $submessage) {
                if (count($blocks) >= self::MAX_BLOCKS) { $truncated = true; break; }
                if (!is_array($submessage)) { $unsupported = true; continue; }
                $block = self::legacyBlock($submessage, $truncated);
                if(!self::fits($block,$projectedBytes)){$truncated=true;break;}
                if ($block['type'] === 'unsupported') $unsupported = true;
                $blocks[] = $block;
            }
        }
        $text = [];
        foreach ($blocks as $block) if (is_string($block['text'] ?? null) && $block['text'] !== '' && !in_array($block['type'],['image','progress','decoration'],true)) $text[] = $block['text'];
        $joined = implode("\n", $text); $joined = self::string($joined, $truncated);
        $known=false;foreach($blocks as$block)if(self::knownContent($block)){$known=true;break;}
        $status = $invalid ? ($blocks === [] ? 'invalid' : 'partial') : ($unsupported ? ($known ? 'partial' : 'unsupported') : 'parsed');
        $revision = is_array($protocol) && is_int($protocol['timestampMs'] ?? null) && $protocol['timestampMs'] > 0 ? $protocol['timestampMs'] : null;
        $result=['text'=>$joined === '' ? null : $joined, 'responseMode'=>'replace','revisionTimestampMs'=>$revision, 'richResponse'=>['format'=>$format,'status'=>$status,'blocks'=>$blocks,'truncated'=>$truncated]];
        $sources=ModernEvents::sources($message['messageContextInfo']['botMetadata']['richResponseSourcesMetadata']??$unwrapped['context']['botMetadata']['richResponseSourcesMetadata']??null);
        if($sources!==[]){$allowed=[];foreach($sources as$source){if(!self::fits($source,$projectedBytes)){$result['richResponse']['truncated']=true;break;}$allowed[]=$source;}$result['richResponse']['sources']=$allowed;}
        return $result;
    }

    /**
     * @param array<string,mixed> $primitive
     *
     * @return array<string,mixed>|null */
    private static function unifiedBlock(array $primitive, bool &$truncated): ?array
    {
        $wire = $primitive['__typename'] ?? null;
        if (!is_string($wire)) return null;
        $wire = self::string($wire, $truncated);
        switch ($wire) {
            case 'GenAIMarkdownTextUXPrimitive':
            case 'FOATextPrimitive':
                if (!is_string($primitive['text'] ?? null)) return ['type'=>'unsupported','wireType'=>$wire];
                $text = preg_replace('/\{\{([^\s\}]+)\}\}([\s\S]*?)\{\{\/\1\}\}/', '$2', self::string($primitive['text'], $truncated));
                return ['type'=>'text','wireType'=>$wire,'text'=>$text ?? ''];
            case 'GenAIMetadataTextPrimitive':
                return is_string($primitive['text'] ?? null) ? ['type'=>'text','wireType'=>$wire,'text'=>self::string($primitive['text'],$truncated)] : ['type'=>'unsupported','wireType'=>$wire];
            case 'GenAICodeUXPrimitive':
                $parts = [];
                foreach (self::items($primitive['code_blocks'] ?? null,$truncated) as $part) if (is_array($part) && is_string($part['content'] ?? null)) $parts[] = self::string($part['content'],$truncated);
                return ['type'=>'code','wireType'=>$wire,'text'=>self::string(implode('', $parts),$truncated)];
            case 'GenATableUXPrimitive':
                $rows = [];
                foreach (self::items($primitive['rows'] ?? null,$truncated) as $row) if (is_array($row)) $rows[] = self::strings($row['cells'] ?? null,$truncated);
                return ['type'=>'table','wireType'=>$wire,'rows'=>$rows,'text'=>self::tableText($rows,$truncated)];
            case 'GenAILatexUXPrimitive':
                $latex = $primitive['item']['latex_expression'] ?? $primitive['latex_expression'] ?? null;
                return is_string($latex) ? ['type'=>'latex','wireType'=>$wire,'text'=>self::string($latex,$truncated)] : ['type'=>'unsupported','wireType'=>$wire];
            case 'GenAIBotProgressStatusPrimitive':
            case 'GenAIBotThinkingStatusPrimitive':
                return is_string($primitive['title'] ?? null) ? ['type'=>'progress','wireType'=>$wire,'text'=>self::string($primitive['title'],$truncated)] : ['type'=>'unsupported','wireType'=>$wire];
            case 'GenAIProductItemCardPrimitive':
                return self::card($primitive,'product',$wire,$truncated);
            case 'GenAIImagePrimitive':
            case 'GenAIImaginePrimitive':
                return self::card($primitive,'image',$wire,$truncated);
            case 'GenAIReelPrimitive':
                return self::card($primitive,'reel',$wire,$truncated);
            case 'GenAISearchResultPrimitive':
                return self::card($primitive,'search-result',$wire,$truncated);
            case 'GenAIMuseConnectorActionCardPrimitive':
                // Describe observed actions; never execute a remote connector or credential request.
                return self::card($primitive,'action',$wire,$truncated);
            case 'GenAIDividerPrimitive':
            case 'GenAISpacerPrimitive':
                return ['type'=>'decoration','wireType'=>$wire];
            default:
                return ['type'=>'unsupported','wireType'=>$wire];
        }
    }

    /**
     * @param array<string,mixed> $part
     *
     * @return array<string,mixed> */
    private static function legacyBlock(array $part, bool &$truncated): array
    {
        $type = $part['messageType'] ?? null;
        if(in_array($type,[1,'AI_RICH_RESPONSE_GRID_IMAGE'],true)&&is_array($part['gridImageMetadata']??null)){
            $m=$part['gridImageMetadata'];$images=[];foreach(self::items($m['imageUrls']??null,$truncated)as$image)if(is_array($image)&&($item=self::image($image))!==[])$images[]=$item;
            return ['type'=>'image-grid','images'=>$images,'preview'=>is_array($m['gridImageUrl']??null)?self::image($m['gridImageUrl']):null];
        }
        if(in_array($type,[3,'AI_RICH_RESPONSE_INLINE_IMAGE'],true)&&is_array($part['imageMetadata']??null)){
            $m=$part['imageMetadata'];return ['type'=>'image','image'=>is_array($m['imageUrl']??null)?self::image($m['imageUrl']):[],
                'text'=>\MVO\History\ProtocolValues::text($m['imageText']??null),'url'=>\MVO\History\ProtocolValues::url($m['tapLinkUrl']??null),'alignment'=>is_int($m['alignment']??null)?$m['alignment']:null];
        }
        if(in_array($type,[6,'AI_RICH_RESPONSE_DYNAMIC'],true)&&is_array($part['dynamicMetadata']??null)){
            $m=$part['dynamicMetadata'];return ['type'=>'dynamic','url'=>\MVO\History\ProtocolValues::url($m['url']??null),'mediaType'=>$m['type']??null,'loopCount'=>is_int($m['loopCount']??null)?$m['loopCount']:null];
        }
        if(in_array($type,[7,'AI_RICH_RESPONSE_MAP'],true)&&is_array($part['mapMetadata']??null)){
            $m=$part['mapMetadata'];$map=['type'=>'map','annotations'=>[]];
            foreach(['centerLatitude','centerLongitude','latitudeDelta','longitudeDelta']as$field)if(is_int($m[$field]??null)||is_float($m[$field]??null))if(is_finite((float)$m[$field]))$map[$field]=$m[$field];
            foreach(self::items($m['annotations']??null,$truncated)as$a){if(!is_array($a))continue;$item=[];foreach(['annotationNumber','latitude','longitude']as$field)if((is_int($a[$field]??null)||is_float($a[$field]??null))&&is_finite((float)$a[$field]))$item[$field]=$a[$field];foreach(['title','body']as$field)if(is_string($a[$field]??null))$item[$field]=self::string($a[$field],$truncated);$map['annotations'][]=$item;}
            if(is_bool($m['showInfoList']??null))$map['showInfoList']=$m['showInfoList'];return $map;
        }
        if(in_array($type,[9,'AI_RICH_RESPONSE_CONTENT_ITEMS'],true)&&is_array($part['contentItemsMetadata']??null)){
            $items=[];foreach(self::items($part['contentItemsMetadata']['itemsMetadata']??null,$truncated)as$item){$r=is_array($item)?($item['reelItem']??null):null;if(!is_array($r))continue;$row=['type'=>'reel','title'=>\MVO\History\ProtocolValues::text($r['title']??null)];foreach(['profileIconUrl','thumbnailUrl','videoUrl']as$field){$url=\MVO\History\ProtocolValues::url($r[$field]??null);if($url!==null)$row[$field]=$url;}$items[]=$row;}
            return ['type'=>'content-items','items'=>$items,'layout'=>$part['contentItemsMetadata']['contentType']??null];
        }
        if (in_array($type,[2,'AI_RICH_RESPONSE_TEXT'],true) && is_string($part['messageText'] ?? null)) return ['type'=>'text','text'=>self::string($part['messageText'],$truncated)];
        if (in_array($type,[5,'AI_RICH_RESPONSE_CODE'],true) && is_array($part['codeMetadata'] ?? null)) {
            $metadata = $part['codeMetadata']; $parts = [];
            foreach (self::items($metadata['codeBlocks'] ?? null,$truncated) as $block) if (is_array($block) && is_string($block['codeContent'] ?? null)) $parts[] = self::string($block['codeContent'],$truncated);
            $out = ['type'=>'code','text'=>self::string(implode('',$parts),$truncated)];
            if (is_string($metadata['codeLanguage'] ?? null)) $out['language'] = self::string($metadata['codeLanguage'],$truncated);
            return $out;
        }
        if (in_array($type,[4,'AI_RICH_RESPONSE_TABLE'],true) && is_array($part['tableMetadata'] ?? null)) {
            $metadata = $part['tableMetadata']; $rows = [];
            foreach (self::items($metadata['rows'] ?? null,$truncated) as $row) if (is_array($row)) $rows[] = self::strings($row['items'] ?? null,$truncated);
            $out = ['type'=>'table','rows'=>$rows,'text'=>self::tableText($rows,$truncated)];
            if (is_string($metadata['title'] ?? null)) $out['title'] = self::string($metadata['title'],$truncated);
            return $out;
        }
        if (in_array($type,[8,'AI_RICH_RESPONSE_LATEX'],true) && is_string($part['latexMetadata']['text'] ?? null)) return ['type'=>'latex','text'=>self::string($part['latexMetadata']['text'],$truncated)];
        return ['type'=>'unsupported','wireType'=>is_int($type) || is_string($type) ? $type : null];
    }
    /**
     * @param array<string,mixed> $image
     * @return array<string,mixed> */
    private static function image(array $image):array
    {
        $out=[];foreach(['imagePreviewUrl','imageHighResUrl','sourceUrl']as$field){$url=\MVO\History\ProtocolValues::url($image[$field]??null);if($url!==null)$out[$field]=$url;}return $out;
    }
    /** @param array<string,mixed> $block */
    private static function fits(array $block,int &$bytes):bool
    {
        $size=strlen(json_encode($block,JSON_THROW_ON_ERROR|JSON_INVALID_UTF8_SUBSTITUTE|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES));if($size>self::MAX_PROJECTED_BYTES-$bytes)return false;$bytes+=$size;return true;
    }
    /** @param array<string,mixed> $block */
    private static function knownContent(array $block):bool
    {
        if(in_array($block['type']??null,['unsupported','progress','decoration'],true))return false;
        if(isset($block['centerLatitude'],$block['centerLongitude']))return true;
        foreach(['text','title','url','image','images','preview','annotations','rows','items','image_url','thumbnail_url','video_url','source_url','action_url']as$field)if((is_string($block[$field]??null)&&$block[$field]!=='')||(is_array($block[$field]??null)&&$block[$field]!==[]))return true;
        return false;
    }
    /** Deliberate flat allowlist, never recursively scrape arbitrary JSON or secrets.
     *
     * @param array<string,mixed> $primitive
     * @return array<string,mixed> */
    private static function card(array $primitive,string $type,string $wire,bool &$truncated):array
    {
        $out=['type'=>$type,'wireType'=>$wire];foreach(['title','description','text','subtitle','label','product_id','currency','price']as$field)if(is_string($primitive[$field]??null))$out[$field]=self::string($primitive[$field],$truncated);
        if(isset($out['title'])&&!isset($out['text']))$out['text']=$out['title'];
        foreach(['url','image_url','thumbnail_url','video_url','source_url','action_url']as$field){$url=\MVO\History\ProtocolValues::url($primitive[$field]??null);if($url!==null)$out[$field]=$url;}
        return count($out)>2?$out:['type'=>'unsupported','wireType'=>$wire];
    }

    /**
     * @return list<mixed> */
    private static function items(mixed $value, bool &$truncated): array
    {
        if (!is_array($value) || !array_is_list($value)) return [];
        if (count($value) > self::MAX_BLOCKS) { $truncated = true; return array_slice($value,0,self::MAX_BLOCKS); }
        return $value;
    }
    /**
     * @return list<string> */
    private static function strings(mixed $value, bool &$truncated): array
    {
        $out = [];
        foreach (self::items($value,$truncated) as $item) if (is_string($item)) $out[] = self::string($item,$truncated);
        return $out;
    }
    /**
     * @param list<list<string>> $rows */
    private static function tableText(array $rows, bool &$truncated): string
    {
        return self::string(implode("\n",array_map(static fn(array $row):string=>implode(' | ',$row),$rows)),$truncated);
    }
    private static function string(string $value, bool &$truncated): string
    {
        if (preg_match('//u',$value) !== 1) { $truncated = true; return ''; }
        if (strlen($value) <= self::MAX_BYTES) return $value;
        $truncated = true; $value = substr($value,0,self::MAX_BYTES);
        while ($value !== '' && preg_match('//u',$value) !== 1) $value = substr($value,0,-1);
        return $value;
    }
}
