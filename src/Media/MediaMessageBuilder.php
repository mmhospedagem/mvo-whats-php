<?php
declare(strict_types=1);

namespace MVO\Media;

/** Builds real WAProto media messages after authenticated CDN uploads. */
final class MediaMessageBuilder
{
    public function __construct(private readonly MediaTransfer $transfer,private readonly bool $newsletter=false) {}

    /**
     * @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    public function build(array $content): array
    {
        $type = $content['type'] ?? '';
        $nativeLottie=$type==='lottie-sticker'||($type==='sticker'&&($content['isLottie']??false)===true);
        if($nativeLottie){
            if(isset($content['mimetype'])&&$content['mimetype']!=='application/was')throw new \InvalidArgumentException('Native Lottie stickers require application/was');
            $type='sticker';$content['type']='sticker';$content['isLottie']=true;$content['mimetype']='application/was';
        }
        if ($type === 'audio-sticker') {
            $sticker = $content['preparedSticker'] ?? null;
            $audio = $content['preparedAudio'] ?? null;
            if ($sticker === null) {
                $options = is_array($content['stickerOptions'] ?? null) ? $content['stickerOptions'] : [];
                $sticker = $this->build([...$options, 'type'=>'sticker', 'media'=>$content['media'] ?? null])['stickerMessage'];
            }
            if ($audio === null) {
                $options = is_array($content['audioOptions'] ?? null) ? $content['audioOptions'] : [];
                $audio = $this->build([...$options, 'type'=>'audio', 'media'=>$content['audio'] ?? $content['audioMedia'] ?? null, 'ptt'=>false])['audioMessage'];
            }
            return \MVO\Message\ModernContent::build([...$content, 'preparedSticker'=>$sticker, 'preparedAudio'=>$audio], '', [], (int)floor(microtime(true)*1000)) ?? throw new \LogicException('Missing audio sticker builder');
        }
        if ($type === 'sticker-pack') return $this->stickerPack($content);
        if (!is_string($type) || !in_array($type,['image','video','gif','ptv','audio','ptt','document','sticker'],true)) throw new \InvalidArgumentException('Unsupported media message type');
        $source = $content['media'] ?? $content['file'] ?? null;
        $plain = MediaSource::read($source,$this->transfer->maxBytes());
        if ($plain === '') throw new \InvalidArgumentException('Cannot upload empty media');
        $processor=$this->transfer->processor(); $processorFields=[];
        if ($processor!==null&&!$nativeLottie) {
            $result=$processor->process($plain,$type,($content['normalizeVoice'] ?? false)===true);
            $plain=$result['bytes']; $processorFields=$result['fields'];
        } elseif ($content['normalizeVoice'] ?? false) throw new \LogicException('Configure MediaProcessor to normalize voice notes');
        $fileName = $content['fileName'] ?? MediaSource::fileName($source);
        if ($fileName !== null && (!is_string($fileName) || str_contains($fileName,"\0"))) throw new \InvalidArgumentException('Invalid media fileName');
        $mime = $content['mimetype'] ?? MediaMetadata::mimetype($plain,$type,$fileName);
        if (!is_string($mime) || !preg_match('~^[a-zA-Z0-9!#$&^_.+-]+/[a-zA-Z0-9!#$&^_.+-]+(?:;[^\r\n]*)?$~',$mime)) throw new \InvalidArgumentException('Invalid media mimetype');
        $processed = $processorFields+MediaMetadata::probe($plain,$type);
        $fieldsByType = [
            'image'=>['caption','height','width','jpegThumbnail','viewOnce','accessibilityLabel','contextInfo','imageSourceType','interactiveAnnotations','annotations'],
            'video'=>['caption','height','width','seconds','jpegThumbnail','gifPlayback','gifAttribution','viewOnce','accessibilityLabel','contextInfo','interactiveAnnotations','annotations'],
            'audio'=>['seconds','ptt','waveform','backgroundArgb','viewOnce','accessibilityLabel','contextInfo'],
            'document'=>['caption','title','fileName','pageCount','jpegThumbnail','thumbnailHeight','thumbnailWidth','contactVcard','accessibilityLabel','contextInfo'],
            'sticker'=>['height','width','pngThumbnail','isAnimated','firstFrameLength','firstFrameSidecar','stickerSentTs','isAvatar','isAiSticker','isLottie','accessibilityLabel','premium','emojis','contextInfo'],
        ];
        $baseType = match($type) {'gif','ptv'=>'video','ptt'=>'audio',default=>$type};
        $fields = [];
        foreach ($fieldsByType[$baseType] as $field) {
            if (isset($content[$field])) $fields[$field] = $content[$field];
            elseif (isset($processed[$field])) $fields[$field] = $processed[$field];
        }
        foreach (['width','height','seconds','pageCount','firstFrameLength'] as $field) {
            if (isset($fields[$field]) && (!is_int($fields[$field]) || $fields[$field] < 0 || $fields[$field] > 0xffffffff)) throw new \InvalidArgumentException('Invalid media '.$field);
        }
        foreach (['jpegThumbnail','pngThumbnail','waveform','firstFrameSidecar'] as $field) {
            if (isset($fields[$field]) && (!is_string($fields[$field]) || strlen($fields[$field]) > 1048576)) throw new \InvalidArgumentException('Invalid media '.$field);
        }
        if ($type === 'gif') $fields['gifPlayback'] = true;
        if ($type === 'ptt') $fields['ptt'] = true;
        if (($fields['gifPlayback'] ?? false) && str_starts_with($plain,'GIF8')) throw new \InvalidArgumentException('WhatsApp GIF messages require MP4 video; convert the GIF file to MP4 first');
        if ($baseType === 'document') {
            $fields['fileName'] ??= $fileName ?? 'file';
            $fields['title'] ??= $fields['fileName'];
        }
        if ($baseType === 'sticker') {
            $fields['isAnimated'] ??= isset($fields['firstFrameLength']);
            $fields['stickerSentTs'] ??= (int) floor(microtime(true)*1000);
        }
        $uploadType = match(true) {
            $baseType === 'video' && (bool)($fields['gifPlayback'] ?? false) => 'gif',
            $baseType === 'audio' && (bool)($fields['ptt'] ?? false) => 'ptt',
            default => $type,
        };
        $options = ['sidecar'=>in_array($baseType,['video','audio'],true)];
        if (isset($fields['firstFrameLength'])) {
            if ($fields['firstFrameLength'] <= 0 || $fields['firstFrameLength'] > strlen($plain)) throw new \InvalidArgumentException('Invalid firstFrameLength');
            $options['firstFrameLength'] = $fields['firstFrameLength'];
        }
        $uploaded = $this->newsletter ? $this->transfer->uploadNewsletter($plain,$uploadType) : $this->transfer->upload($plain,$uploadType,$options);
        $fields = array_replace($fields,$uploaded,['mimetype'=>$mime]);
        if ($baseType !== 'video') unset($fields['metadataUrl']);
        if ($type === 'ptv') unset($fields['metadataUrl']);
        $key = $type === 'ptv' ? 'ptvMessage' : $baseType.'Message';
        return [$key=>$fields];
    }

    /**
     * @param array<string,mixed> $content
     * @return array<string,mixed>
     */
    private function stickerPack(array $content): array
    {
        foreach (['name','publisher'] as $field) {
            if (!isset($content[$field]) || !is_string($content[$field]) || trim($content[$field]) === '') throw new \InvalidArgumentException('Sticker pack requires '.$field);
        }
        if (!isset($content['stickers']) || !is_array($content['stickers']) || $content['stickers'] === []) throw new \InvalidArgumentException('Sticker pack requires at least one sticker');
        if (!isset($content['trayIcon']) || !is_array($content['trayIcon'])) throw new \InvalidArgumentException('Sticker pack requires trayIcon');
        $cover='';
        if (!$this->newsletter) {
            if (!isset($content['coverThumbnail'])) throw new \InvalidArgumentException('Sticker pack requires coverThumbnail');
            $cover = MediaSource::read($content['coverThumbnail'],$this->transfer->maxBytes());
            if ($cover === '') throw new \InvalidArgumentException('Sticker pack coverThumbnail is empty');
        }
        $entries = $stickers = [];
        $remaining = $this->transfer->maxBytes();
        foreach ([...array_values($content['stickers']),$content['trayIcon']] as $index => $entry) {
            if (!is_array($entry) || !isset($entry['fileName']) || !is_string($entry['fileName'])) throw new \InvalidArgumentException('Sticker pack entry requires fileName');
            $bytes = MediaSource::read($entry['media'] ?? null,$remaining);
            $remaining -= strlen($bytes);
            if ($remaining <= 0) throw new \LengthException('Sticker pack ZIP exceeds maxBytes');
            $entries[] = ['fileName'=>$entry['fileName'],'bytes'=>$bytes];
            if ($index < count($content['stickers'])) {
                $sticker = ['fileName'=>$entry['fileName'],'emojis'=>$entry['emojis'] ?? [],'mimetype'=>$entry['mimetype'] ?? 'image/webp'];
                foreach (['isAnimated','isLottie','accessibilityLabel','premium'] as $field) if (isset($entry[$field])) $sticker[$field] = $entry[$field];
                $stickers[] = $sticker;
            }
        }
        $zip = StickerPackZip::create($entries,$this->transfer->maxBytes());
        if ($this->newsletter) {
            $bundle=$this->transfer->uploadNewsletter($zip,'sticker-pack'); unset($bundle['url']);
            $fields=$bundle+['stickerPackId'=>$content['stickerPackId'] ?? bin2hex(random_bytes(16)),'name'=>$content['name'],'publisher'=>$content['publisher'],'stickers'=>$stickers,'trayIconFileName'=>$content['trayIcon']['fileName'],'stickerPackSize'=>$bundle['fileLength'],'stickerPackOrigin'=>2];
            foreach (['caption','packDescription','contextInfo'] as $field) if (isset($content[$field])) $fields[$field]=$content[$field];
            return ['stickerPackMessage'=>$fields];
        }
        $key = random_bytes(32);
        $bundle = $this->transfer->upload($zip,'sticker-pack',['mediaKey'=>$key]);
        $thumbnail = $this->transfer->upload($cover,'thumbnail-sticker-pack',['mediaKey'=>$key]);
        unset($bundle['url']); // StickerPackMessage has no URL field.
        $fields = $bundle + [
            'stickerPackId'=>$content['stickerPackId'] ?? bin2hex(random_bytes(16)),
            'name'=>$content['name'],'publisher'=>$content['publisher'],'stickers'=>$stickers,
            'trayIconFileName'=>$content['trayIcon']['fileName'],'stickerPackSize'=>$bundle['fileLength'],'stickerPackOrigin'=>2,
            'thumbnailDirectPath'=>$thumbnail['directPath'],'thumbnailSha256'=>$thumbnail['fileSha256'],'thumbnailEncSha256'=>$thumbnail['fileEncSha256'],
            'thumbnailWidth'=>$content['thumbnailWidth'] ?? 252,'thumbnailHeight'=>$content['thumbnailHeight'] ?? 252,
            'imageDataHash'=>base64_encode($thumbnail['fileSha256']),
        ];
        foreach (['caption','packDescription','contextInfo'] as $field) if (isset($content[$field])) $fields[$field] = $content[$field];
        return ['stickerPackMessage'=>$fields];
    }
}
