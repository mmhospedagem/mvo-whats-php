<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\SchemaCodec;

/** Current protocol conveniences. Media references must already be uploaded. */
final class ModernContent
{
    /**
     * @param array<string,mixed> $content
     *
     * @param array<string,mixed> $context
     *
     * @return array<string,mixed>|null */
    public static function build(array $content,string $to,array $context,int $now):?array
    {
        $type=$content['type']??null;
        if(in_array($type,['chat-theme','reset-chat-theme'],true)&&str_contains($to,'@')&&!str_ends_with($to,'@s.whatsapp.net')&&!str_ends_with($to,'@lid'))throw new \InvalidArgumentException('Chat themes require an individual conversation');
        $message=match($type){
            'provider-notice'=>ProviderNoticeContent::build($content),
            'music'=>self::music($content),
            'audio-sticker'=>self::audioSticker($content),
            'request-location','location-request'=>self::locationRequest($content),
            'cancel-location-request','location-cancel'=>['requestLocationUpdateMessage'=>[
                'key'=>self::target($content['target']??null,$to),'updateType'=>'CANCEL',
                'senderTimestampMs'=>$content['senderTimestampMs']??$now]],
            'chat-theme'=>['protocolMessage'=>['type'=>'CHAT_THEME_SETTING',
                'chatThemeSetting'=>self::theme(self::object($content['theme']??null,'theme'),$now)]],
            'reset-chat-theme'=>['protocolMessage'=>['type'=>'CHAT_THEME_SETTING',
                'chatThemeSetting'=>self::theme(['settingTimestampMs'=>$content['settingTimestampMs']??$now,'clearTheme'=>true],$now)]],
            'status-question'=>self::question($content),
            'status-answer'=>['statusQuestionAnswerMessage'=>['key'=>self::target($content['target']??null,$to),
                'text'=>self::text($content['text']??null,'answer text')]],
            'status-share'=>self::share($content),
            default=>null,
        };
        if($message!==null)SchemaCodec::encode('Message',$message);
        return $message;
    }

    /** Only overrides established by the public Web stanza encoder.
     * @param array<string,mixed> $message
     * @return array{attrs:array<string,string>,encAttrs:array<string,string>,metaAttrs:array<string,string>} */
    public static function attributes(array $message):array
    {
        $message=ContentDecoder::unwrap($message)['message'];
        if(isset($message['requestLocationMessage']))return ['attrs'=>['type'=>'media'],'encAttrs'=>['mediatype'=>'request_location'],'metaAttrs'=>[]];
        if(isset($message['musicMessage']))return ['attrs'=>['type'=>'media'],'encAttrs'=>['mediatype'=>'music'],'metaAttrs'=>[]];
        return ['attrs'=>[],'encAttrs'=>[],'metaAttrs'=>[]];
    }

    /**
     * The current Android UI handles send_location; field 136 is parsed but has no row renderer.
     * @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    private static function locationRequest(array $content):array
    {
        $format=$content['format']??'interactive';
        if(!in_array($format,['interactive','protocol','text'],true))throw new \InvalidArgumentException('Location request format must be interactive, protocol or text');
        if($format==='protocol'){
            if(array_key_exists('text',$content))throw new \InvalidArgumentException('Protocol location requests do not carry text');
            return ['requestLocationMessage'=>[]];
        }
        $text=self::text($content['text']??'Por favor, compartilhe sua localização nesta conversa.','location request text');
        if($format==='text')return ['extendedTextMessage'=>['text'=>$text]];
        return ['interactiveMessage'=>[
            'header'=>['hasMediaAttachment'=>false],
            'body'=>['text'=>$text],
            'nativeFlowMessage'=>['buttons'=>[['name'=>'send_location','buttonParamsJson'=>'{}']],'messageVersion'=>1],
        ]];
    }

    /** Native music metadata identifies existing content; it does not fetch or license a track.
     *
     * @param array<string,mixed> $content
     *
     * @return array<string,mixed> */
    private static function music(array $content):array
    {
        self::validateMusicCard($content);
        $body=['embeddedMusic'=>self::embeddedMusic(self::object($content['embeddedMusic']??null,'embeddedMusic'))];
        foreach(['songUri','artworkUri']as$field)if(isset($content[$field]))$body[$field]=self::text($content[$field],$field);
        $styles=['UNKNOWN','VINYL','MESSAGE_BUBBLE','LYRICS'];$style=self::enum($content['style']??'VINYL',$styles,'music style');
        $body['style']=is_int($style)?$style:array_search($style,$styles,true);
        return ['musicMessage'=>$body];
    }

    /** Validate a standalone card without network access. Catalogue availability and client gates remain remote.
     * This intentionally does not tighten EmbeddedMusic annotations on Status.
     * @param array<string,mixed> $content */
    public static function validateMusicCard(array $content):void
    {
        $music=self::embeddedMusic(self::object($content['embeddedMusic']??null,'embeddedMusic'));
        foreach(['musicContentMediaId','songId','author','title']as$field)self::text($music[$field]??null,'embeddedMusic.'.$field);
        foreach(['musicContentMediaId','songId']as$field){
            $id=$music[$field];
            if(preg_match('/[\s<>]/u',$id)===1||in_array(strtoupper($id),['TRACK_ID','SONG_ID','MUSIC_CONTENT_MEDIA_ID','STRING'],true))
                throw new \InvalidArgumentException('embeddedMusic.'.$field.' requires a catalogue reference, not a placeholder');
        }
        self::musicUri($content['songUri']??null,'songUri');
        if(array_key_exists('artworkUri',$content))self::musicUri($content['artworkUri'],'artworkUri');
        if(!in_array($content['style']??'VINYL',['VINYL',1],true))
            throw new \InvalidArgumentException('Standalone music cards currently require VINYL style');
    }

    private static function musicUri(mixed $value,string $label):void
    {
        $value=self::text($value,$label);$parts=parse_url($value);$host=strtolower($parts['host']??'');
        $allowed=str_ends_with($host,'.fbcdn.net')||str_ends_with($host,'.whatsapp.net');
        if(filter_var($value,FILTER_VALIDATE_URL)===false||!is_array($parts)||strtolower($parts['scheme']??'')!=='https'
            ||!$allowed||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])
            ||isset($parts['port'])&&$parts['port']!==443||preg_match('/\s/u',$value)===1)
            throw new \InvalidArgumentException($label.' requires an HTTPS catalogue URL on a fbcdn.net or whatsapp.net subdomain without credentials or a fragment');
    }

    /**
     * @param array<string,mixed> $music
     * @return array<string,mixed> */
    public static function embeddedMusic(array $music):array
    {
        self::only($music,array_keys(SchemaCodec::fields('EmbeddedMusic')),'embeddedMusic');
        self::text($music['songId']??null,'music songId');
        foreach(['author','title','artistAttribution']as$field)if(isset($music[$field]))self::text($music[$field],$field);
        foreach(['artworkSha256','artworkEncSha256','artworkMediaKey']as$field)if(isset($music[$field]))self::bytes32($music[$field],$field);
        foreach(['musicSongStartTimeInMs','derivedContentStartTimeInMs','overlapDurationInMs']as$field)if(isset($music[$field]))self::nonnegative($music[$field],$field);
        SchemaCodec::encode('EmbeddedMusic',$music);return $music;
    }

    /**
     * @param array<string,mixed> $content
     * @return array<string,mixed> */
    private static function audioSticker(array $content):array
    {
        $sticker=self::object($content['preparedSticker']??$content['prepared']??null,'preparedSticker');
        $audio=self::object($content['preparedAudio']??null,'preparedAudio');
        self::prepared($sticker,'sticker');self::prepared($audio,'audio');
        if(isset($sticker['audioMessage']))throw new \InvalidArgumentException('Audio sticker already contains audioMessage');
        if(($audio['ptt']??false)!==false)throw new \InvalidArgumentException('Audio sticker requires audio, not a voice note');
        if((string)$audio['fileLength']==='0')throw new \InvalidArgumentException('Audio sticker audio file must not be empty');
        if(!str_starts_with(strtolower($audio['mimetype']),'audio/'))throw new \InvalidArgumentException('Audio sticker requires an audio mimetype');
        if(strtolower($sticker['mimetype'])!==(empty($sticker['isLottie'])?'image/webp':'application/was'))throw new \InvalidArgumentException('Sticker mimetype must be image/webp or application/was for Lottie');
        $sticker['audioMessage']=$audio;
        return ['stickerMessage'=>$sticker];
    }

    /** Validate encrypted media metadata before any transport action.
     *
     * @param array<string,mixed> $media */
    private static function prepared(array $media,string $kind):void
    {
        self::text($media['directPath']??null,$kind.' directPath');
        foreach(['fileSha256','fileEncSha256','mediaKey']as$field)self::bytes32($media[$field]??null,$kind.' '.$field);
        self::nonnegative($media['fileLength']??null,$kind.' fileLength');
        self::text($media['mimetype']??null,$kind.' mimetype');
        SchemaCodec::encode($kind==='sticker'?'Message.StickerMessage':'Message.AudioMessage',$media);
    }

    /**
     * @param array<string,mixed> $theme
     * @return array<string,mixed> */
    public static function theme(array $theme,int $now):array
    {
        self::only($theme,['settingTimestampMs','clearTheme','colorSchemeId','wallpaper','defaultWallpaper','solidColor','stockImage','customImage','animatedWallpaper'],'theme');
        $body=$theme;$body['settingTimestampMs']??=$now;
        self::nonnegative($body['settingTimestampMs'],'settingTimestampMs');
        if(isset($body['colorSchemeId']))self::text($body['colorSchemeId'],'colorSchemeId');
        if(isset($body['wallpaper'])){
            $wallpaper=self::object($body['wallpaper'],'wallpaper');unset($body['wallpaper']);
            $field=match($wallpaper['type']??null){'default'=>'defaultWallpaper','solid-color'=>'solidColor','stock-image'=>'stockImage','custom-image'=>'customImage','animated'=>'animatedWallpaper',default=>throw new \InvalidArgumentException('Unknown wallpaper type')};
            unset($wallpaper['type']);$body[$field]=$wallpaper;
        }
        $wallpapers=array_intersect_key($body,array_flip(['defaultWallpaper','solidColor','stockImage','customImage','animatedWallpaper']));
        if(count($wallpapers)>1)throw new \InvalidArgumentException('Choose exactly one wallpaper');
        if(($body['clearTheme']??false)===true&&(isset($body['colorSchemeId'])||$wallpapers!==[]))throw new \InvalidArgumentException('clearTheme cannot include a wallpaper or colorSchemeId');
        if(($body['clearTheme']??false)!==true&&!isset($body['colorSchemeId'])&&$wallpapers===[])throw new \InvalidArgumentException('Theme requires colorSchemeId or wallpaper');
        foreach($wallpapers as$field=>$value){
            $value=self::object($value,$field);
            if(isset($value['dimLevel'])&&((!is_int($value['dimLevel'])&&!is_float($value['dimLevel']))||!is_finite((float)$value['dimLevel'])))throw new \InvalidArgumentException('dimLevel must be finite');
            if($field==='stockImage')self::text($value['stockImageId']??null,'stockImageId');
            if($field==='animatedWallpaper')self::text($value['animatedWallpaperId']??null,'animatedWallpaperId');
            if($field==='customImage'){
                self::text($value['directPath']??null,'wallpaper directPath');
                foreach(['mediaKey','fileEncSha256','fileSha256']as$key)self::bytes32($value[$key]??null,'wallpaper '.$key);
            }
            if($field==='solidColor'&&!isset($value['backgroundLightArgb'])&&!isset($value['backgroundDarkArgb']))throw new \InvalidArgumentException('Solid wallpaper requires an ARGB background color');
        }
        SchemaCodec::encode('Message.ChatThemeSetting',$body);return $body;
    }

    /** Add status metadata without replacing the uploaded media or its encryption keys.
     *
     * @param array<string,mixed> $message
     * @param array<string,mixed> $options
     *
     * @return array<string,mixed> */
    public static function decorateStatus(array $message,array $options):array
    {
        if(isset($message['conversation'])){$message['extendedTextMessage']=['text'=>$message['conversation']];unset($message['conversation']);}
        $field=null;
        foreach(['extendedTextMessage','imageMessage','videoMessage','audioMessage']as$candidate)if(isset($message[$candidate])){$field=$candidate;break;}
        if($field===null)throw new \InvalidArgumentException('Status metadata requires text, image, video or audio content');
        if(isset($options['linkPreview'])){
            if($field!=='extendedTextMessage')throw new \InvalidArgumentException('Link preview requires a text status');
            $preview=self::object($options['linkPreview'],'linkPreview');
            self::only($preview,['matchedText','title','description','jpegThumbnail','thumbnailDirectPath','thumbnailSha256','thumbnailEncSha256','mediaKey','mediaKeyTimestamp','thumbnailHeight','thumbnailWidth','previewType','linkPreviewMetadata','musicMetadata'],'status linkPreview');
            $preview['matchedText']=self::uri($preview['matchedText']??null,'matchedText');
            $message[$field]=array_replace($message[$field],$preview);
        }
        if(isset($options['linkPreviewStyle'])){
            if($field!=='extendedTextMessage'||empty($message[$field]['matchedText']))throw new \InvalidArgumentException('Link style requires a status link preview');
            $message['statusLinkPreviewMetadata']=['style'=>self::enum($options['linkPreviewStyle'],['AUTO','COMPACT','FULL','IMMERSIVE'],'status link style')];
        }
        if(isset($options['musicMetadata'])){
            $music=self::embeddedMusic(self::object($options['musicMetadata'],'musicMetadata'));
            if($field!=='extendedTextMessage')throw new \InvalidArgumentException('musicMetadata requires text; use musicAnnotation for image/video statuses');
            $message[$field]['musicMetadata']=$music;
        }
        if(isset($options['musicAnnotation'])){
            if(!in_array($field,['imageMessage','videoMessage'],true))throw new \InvalidArgumentException('Music annotation requires image or video status');
            $annotation=self::object($options['musicAnnotation'],'musicAnnotation');
            self::only($annotation,['embeddedMusic','polygonVertices','shouldSkipConfirmation'],'musicAnnotation');
            $music=self::embeddedMusic(self::object($annotation['embeddedMusic']??null,'musicAnnotation embeddedMusic'));unset($annotation['embeddedMusic']);
            $annotation['embeddedContent']=['embeddedMusic'=>$music];$annotation['embeddedAction']=true;
            SchemaCodec::encode('InteractiveAnnotation',$annotation);$message[$field]['annotations'][]=$annotation;
        }
        if(isset($options['statusAttributions'])){
            $attributions=$options['statusAttributions'];
            if(!is_array($attributions)||!array_is_list($attributions))throw new \InvalidArgumentException('statusAttributions must be a list');
            foreach($attributions as$attribution)SchemaCodec::encode('StatusAttribution',self::object($attribution,'statusAttribution'));
            $message[$field]['contextInfo']['statusAttributions']=$attributions;
            foreach($attributions as$attribution)if(in_array($attribution['type']??null,[1,'RESHARE'],true))$message[$field]['contextInfo']['statusAttributionType']='RESHARED_FROM_POST';
        }
        if(isset($options['association'])){
            $association=self::object($options['association'],'association');
            self::target($association['parentMessageKey']??null,'status@broadcast');
            SchemaCodec::encode('MessageAssociation',$association);$message['messageContextInfo']['messageAssociation']=$association;
        }
        SchemaCodec::encode('Message',$message);return $message;
    }

    /**
     * @param array<string,mixed> $content
     * @return array<string,mixed> */
    private static function question(array $content):array
    {
        $body=['text'=>self::text($content['text']??null,'question text'),'contextInfo'=>['isQuestion'=>true]];
        return ['questionMessage'=>['message'=>['extendedTextMessage'=>$body]]];
    }

    /** Sharing preserves the supplied original content and adds observable attribution.
     *
     * @param array<string,mixed> $content
     * @return array<string,mixed> */
    private static function share(array $content):array
    {
        $message=self::object($content['message']??null,'shared status message');
        $source=self::enum($content['source']??'INTERNAL_RESHARE',['UNKNOWN','INTERNAL_RESHARE','MENTION_RESHARE','CHANNEL_RESHARE','FORWARD'],'reshare source');
        $reshare=['source'=>$source];if(isset($content['metadata']))$reshare['metadata']=self::object($content['metadata'],'reshare metadata');
        return self::decorateStatus($message,['statusAttributions'=>[['type'=>'RESHARE','statusReshare'=>$reshare]]]);
    }

    /**
     * @return array<string,mixed> */
    private static function target(mixed $value,string $to):array
    {
        $key=self::object($value,'target');self::text($key['id']??null,'target id');
        $key['remoteJid']??=$to;$key['fromMe']??=false;SchemaCodec::encode('MessageKey',$key);return $key;
    }
    private static function uri(mixed $value,string $label):string
    {
        $value=self::text($value,$label);$parts=parse_url($value);
        if(!is_array($parts)||!in_array(strtolower($parts['scheme']??''),['https','http'],true)||empty($parts['host'])||isset($parts['user'])||isset($parts['pass']))throw new \InvalidArgumentException($label.' requires an HTTP(S) URL without credentials');return $value;
    }
    private static function text(mixed $value,string $label):string
    {
        if(!is_string($value)||trim($value)===''||preg_match('//u',$value)!==1)throw new \InvalidArgumentException($label.' requires non-empty UTF-8 text');return $value;
    }
    private static function bytes32(mixed $value,string $label):void
    {if(!is_string($value)||strlen($value)!==32)throw new \InvalidArgumentException($label.' requires 32 bytes');}
    private static function nonnegative(mixed $value,string $label):void
    {if((!is_int($value)&&!is_string($value))||preg_match('/^(?:0|[1-9][0-9]*)$/D',(string)$value)!==1)throw new \InvalidArgumentException($label.' requires a nonnegative integer');}
    /**
     * @param list<string> $values */
    private static function enum(mixed $value,array $values,string $label):int|string
    {if(is_string($value)&&in_array($value,$values,true)||is_int($value)&&$value>=0&&$value<count($values))return $value;throw new \InvalidArgumentException('Unknown '.$label);}
    /**
     * @return array<string,mixed> */
    private static function object(mixed $value,string $label):array
    {if(!is_array($value)||($value!==[]&&array_is_list($value)))throw new \InvalidArgumentException($label.' requires an object');return $value;}
    /**
     * @param array<string,mixed> $value
     * @param list<string> $allowed */
    private static function only(array $value,array $allowed,string $label):void
    {foreach($value as$key=>$ignored)if(!in_array($key,$allowed,true))throw new \InvalidArgumentException('Unknown '.$label.' field '.$key);}
}
