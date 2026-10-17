<?php
declare(strict_types=1);
namespace MVO\Mobile;

use MVO\Protocol\{Jid,SchemaCodec};

final class HistoryBootstrap
{
    /** @param array<string,mixed> $options
     * @param callable(string):array<string,mixed>|null $upload
     * @return array{message:array<string,mixed>,payloadBytes:int} */
    public static function build(array $options=[],?callable $upload=null):array
    {
        $mappings=[];
        foreach($options['phoneNumberToLidMappings']??[] as $mapping) {
            if(!is_array($mapping) || !is_string($mapping['pnJid']??null) || !is_string($mapping['lidJid']??null))throw new \InvalidArgumentException('History mapping requires PN and LID');
            $pn=Jid::recipient($mapping['pnJid'])->canonical();$lid=Jid::recipient($mapping['lidJid'])->canonical();
            if($pn->server!=='s.whatsapp.net' || $lid->server!=='lid')throw new \InvalidArgumentException('History mapping has wrong addressing servers');
            $mappings[]=['pnJid'=>$pn->bare(),'lidJid'=>$lid->bare()];
        }
        $history=['syncType'=>'INITIAL_BOOTSTRAP','chunkOrder'=>0,'progress'=>100,'conversations'=>$options['conversations']??[],'pushnames'=>$options['pushnames']??[],'phoneNumberToLidMappings'=>$mappings];
        if(isset($options['companionMetaNonce']))$history['companionMetaNonce']=$options['companionMetaNonce'];
        $plain=SchemaCodec::encode('HistorySync',$history);$compressed=gzcompress($plain,1);
        if($compressed===false)throw new \RuntimeException('History bootstrap compression failed');
        $notification=['syncType'=>'INITIAL_BOOTSTRAP','chunkOrder'=>0,'progress'=>100];
        if(strlen($compressed)<=32768)$notification['initialHistBootstrapInlinePayload']=$compressed;
        else {
            if($upload===null)throw new \LengthException('History bootstrap larger than 32 KiB requires an encrypted media uploader');
            $media=$upload($compressed);
            foreach(['mediaKey','fileSha256','fileEncSha256'] as $field)if(!is_string($media[$field]??null) || strlen($media[$field])!==32)throw new \UnexpectedValueException('History upload returned invalid '.$field);
            if(!is_string($media['directPath']??null) || $media['directPath']==='')throw new \UnexpectedValueException('History upload missing directPath');
            $notification+=[...array_intersect_key($media,array_flip(['mediaKey','fileSha256','fileEncSha256','directPath'])),'fileLength'=>strlen($compressed)];
        }
        return ['message'=>['protocolMessage'=>['type'=>'HISTORY_SYNC_NOTIFICATION','historySyncNotification'=>$notification]],'payloadBytes'=>strlen($compressed)];
    }
}
