<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\Protocol\{PageInboxContextReader,PageInboxDocument};

/** Synthetic ServerJS/Relay and LS rows only. Never derived from private account contents. */
final class PageInboxBootstrapSnapshotFixture
{
    public const URL='https://business.facebook.com/latest/inbox/all?asset_id=30003';

    /** @return array<string,mixed> */
    public static function fixture():array
    {
        $view=['props'=>['localScopes'=>[['id'=>'30003','type'=>'page']]]];
        $root=['initialRouteInfo'=>['route'=>['canonicalRouteName'=>'comet.bizweb.BusinessCometBizSuiteInboxAllMessagesRoute',
            'params'=>['asset_id'=>'30003'],'rootView'=>$view,'hostableView'=>$view]],'expectedPreloaders'=>[
            ['queryName'=>'BusinessCometBizSuiteInboxRootQuery','queryID'=>'123456','actorID'=>'30003','variables'=>['page_id'=>null,'business_assets'=>[['business_account_id'=>'40004','business_asset_id'=>'30003']]]],
            ['queryName'=>'BusinessCometContextCardInboxContainerQuery','actorID'=>'30003','variables'=>['shouldSkipPageQuery'=>false]],
            self::preloader(205,'70007'),self::preloader(2,'80008'),
        ]];
        $calls=[];
        foreach(['50005'=>['60006','Uno',1792500001000],'50006'=>['60007','Dos',1792500002000]]as $id=>$data){
            [$contact,$name,$timestamp]=$data;$id=(string)$id;
            $thread=array_fill(0,90,[9]);$thread[0]=[19,(string)$timestamp];$thread[1]=[19,'1792400000000'];$thread[2]='Synthetic snippet';
            $thread[3]=$name;$thread[7]=[19,$id];$thread[9]=[19,'1'];$thread[10]='inbox';$thread[44]=false;$thread[66]=[19,'205'];$thread[89]=[19,'2'];
            $calls[]=[5,'LSDeleteThenInsertThread',...$thread];
            $calls[]=[5,'LSAddParticipantIdToGroupThread',[19,$id],[19,'30003']];
            $calls[]=[5,'LSAddParticipantIdToGroupThread',[19,$id],[19,$contact]];
            $calls[]=[5,'LSSetBusinessThreadInfo',[19,$id],[19,'9'.$id]];
            $message=array_fill(0,82,[9]);$message[0]='Synthetic message '.$id;$message[3]=[19,$id];$message[5]=[19,(string)$timestamp];
            $message[8]='mid.synthetic.'.$id;$message[9]='700000'.$id;$message[10]=[19,$contact];$message[12]=false;$message[17]=false;
            $calls[]=[5,'LSUpsertMessage',...$message];
        }
        return ['define'=>[
            ['CurrentUserInitialData',[],['USER_ID'=>'10001','ACCOUNT_ID'=>'10001'],0],
            ['RelayAPIConfigDefaults',[],['graphURI'=>'/api/graphql/','useXController'=>true,'actorID'=>'10001'],0],
            ['DTSGInitialData',[],['token'=>'SYNTHETIC_PRIVATE_DTSG'],0],
        ],'require'=>[
            ['HasteSupportData','handle',null,[['gkxData'=>['22417'=>['result'=>true]],'qexData'=>['1407'=>['r'=>true]]]]],
            ['CometPlatformRootClient@synthetic','initialize',['BusinessCometRootConfig'],[$root]],
            self::response(205,self::batch(205,'70007',$calls)),self::response(2,self::batch(2,'80008',[[5,'LSSetRegionHint','synthetic-region']])),
        ],'__bbox'=>['result'=>['data'=>['biz_inbox_config'=>['page_id'=>'30003','profile_plus_id_for_delegate_page'=>'20002','is_messenger_enabled'=>true]]]]];
    }

    /** @return array<string,mixed> */
    public static function preloader(int $database,string $epoch):array
    {
        return ['queryName'=>'LSBizInboxGraphQLLightspeedRequestQuery','queryID'=>'123456','actorID'=>null,'preloaderID'=>'synthetic-preloader-'.$database,
            'variables'=>['pageAssetId'=>'30003','igdAssetId'=>null,'waAssetId'=>null,'requestType'=>1,'requestId'=>0,'deviceId'=>'00000000-0000-4000-8000-000000000001',
                'requestPayload'=>json_encode(['database'=>$database,'epoch_id'=>$epoch,'last_applied_cursor'=>null,'sync_params'=>'synthetic','version'=>'123456'],JSON_THROW_ON_ERROR)]];
    }

    /** @param list<array<mixed>> $calls @return array<string,mixed> */
    public static function batch(int $database,string $epoch,array $calls):array
    {
        return ['step'=>[1,[4,0,1,[5,'LSExecuteFirstBlockForSyncTransactionV4',[19,(string)$database],[19,$epoch],[9],'synthetic-next-cursor',[19,'9'],[19,'1'],true,[19,'0'],false,[19,'1']]],
            [23,[2,0],[1,...$calls]],[5,'LSExecuteFinallyBlockForSyncTransaction',false,[19,(string)$database],[19,$epoch]]]];
    }

    /** @param array<string,mixed> $batch @return array<mixed> */
    public static function response(int $database,array $batch):array
    {
        return ['RelayPrefetchedStreamCache@synthetic','next',null,['synthetic-preloader-'.$database,['__bbox'=>['result'=>['data'=>['viewer'=>[
            'unified_inbox_lightspeed_web_request'=>['payload'=>json_encode($batch,JSON_THROW_ON_ERROR),'dependencies'=>[],'experiments'=>[],'target'=>null],
        ]]]]]]];
    }

    /** @param array<string,mixed>|null $fixture */
    public static function document(?array $fixture=null):PageInboxDocument
    {
        $html='<script>throw new Error("must never execute")</script><script type="application/json">'.json_encode($fixture??self::fixture(),JSON_THROW_ON_ERROR).'</script>';
        $context=(new PageInboxContextReader())->read($html,'10001','30003',self::URL);
        return new PageInboxDocument($context,$html,self::URL);
    }
}
