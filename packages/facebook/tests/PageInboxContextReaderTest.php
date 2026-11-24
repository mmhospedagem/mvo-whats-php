<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\PageInboxContextReader;
use PHPUnit\Framework\TestCase;

final class PageInboxContextReaderTest extends TestCase
{
    private const URL='https://business.facebook.com/latest/inbox/all?asset_id=30003&business_id=40004';

    /** Sanitized native ServerJS/Relay shape; synthetic identities and CSRF values only.
     * @return array<string,mixed>
     */
    private function fixture(bool $assetMode=true,bool $msys=true): array
    {
        $view=['props'=>['localScopes'=>[['id'=>'30003','type'=>'page']]]];
        $root=['initialRouteInfo'=>['route'=>['canonicalRouteName'=>'comet.bizweb.BusinessCometBizSuiteInboxAllMessagesRoute',
            'params'=>['asset_id'=>'30003'],'rootView'=>$view,'hostableView'=>$view]],
            'expectedPreloaders'=>[
                ['queryName'=>'BusinessCometBizSuiteInboxRootQuery','queryID'=>'123456','actorID'=>'30003',
                    'variables'=>['page_id'=>$assetMode?null:'30003','business_assets'=>$assetMode?[['business_account_id'=>'40004','business_asset_id'=>'30003']]:null]],
                ['queryName'=>'BusinessCometContextCardInboxContainerQuery','actorID'=>'30003','variables'=>['shouldSkipPageQuery'=>false]],
            ]];
        return ['define'=>[
            ['CurrentUserInitialData',[],['USER_ID'=>'10001','ACCOUNT_ID'=>'10001'],0],
            ['RelayAPIConfigDefaults',[],['graphURI'=>'/api/graphql/','useXController'=>true,'actorID'=>'10001'],0],
            ['DTSGInitialData',[],['token'=>'SYNTHETIC_PRIVATE_DTSG'],0],
            ['LSD',[],['token'=>'SYNTHETIC_PRIVATE_LSD'],0],
            ['SiteData',[],['client_revision'=>123456,'haste_session'=>'SYNTHETIC_HS','__spin_b'=>'trunk','comet_env'=>15,'pr'=>1.25],0],
            ['SprinkleConfig',[],['param_name'=>'jazoest','version'=>2,'should_randomize'=>false],0],
            ['GetAsyncParamsExtraData',[],['extra_data'=>['__aaid'=>'10001','__bid'=>'40004','unknown_secret'=>'SYNTHETIC_PRIVATE_UNKNOWN']],0],
            ['WebConnectionClassServerGuess',[],['connectionClass'=>'GOOD'],0],
        ],'require'=>[
            ['HasteSupportData','handle',null,[['gkxData'=>['22417'=>['result'=>$assetMode]],'qexData'=>['1407'=>['r'=>$msys,'l'=>'PRIVATE_EXPERIMENT_METADATA']]]]],
            ['CometPlatformRootClient@synthetic','initialize',['BusinessCometRootConfig','RequireDeferredReference'],[$root]],
        ],'__bbox'=>['result'=>['data'=>['biz_inbox_config'=>['page_id'=>'30003','profile_plus_id_for_delegate_page'=>'20002',
            'is_messenger_enabled'=>true,'ig_oauth_nonce'=>'SYNTHETIC_PRIVATE_IG']]]]];
    }

    /** @param array<array-key,mixed> $data */
    private function html(array $data): string { return '<script>throw new Error("must not execute")</script><script type="application/json">'.json_encode($data,JSON_THROW_ON_ERROR).'</script>'; }

    /** Synthetic ScheduledServerJS/AsyncData worker resource, never copied from a private capture.
     * @param array<string,mixed> $worker
     * @return array<string,mixed>
     */
    private function workerResource(array $worker): array
    {
        array_unshift($worker['require'],
            ['SharedOrWebWorkerBootstrapInit@synthetic-worker','startServerJS',[],[]],
            ['WebWorkerInitDeferred@synthetic-worker','initDeferred',[],[]]);
        return ['require'=>[['ScheduledServerJS','handle',[],[['__bbox'=>['require'=>[
            ['AsyncData@synthetic-resource','resolve',[],['synthetic-resource',
                ['data'=>['__bbox'=>['hrp'=>['jsmods'=>$worker],'js_env'=>[]]]]]],
        ]]]]]]];
    }

    public function testEmbeddedWorkerCannotReplaceDocumentFieldsIdentityPageRootsOrGatesInEitherOrder(): void
    {
        $document=$this->fixture();$document['define'][4][2]['__spin_t']=100;
        $document['define'][4][2]['ef_page']='SYNTHETIC_DOCUMENT';
        $worker=$this->fixture(false,false);
        $worker['define'][0][2]=['USER_ID'=>'90009','ACCOUNT_ID'=>'90009'];
        $worker['define'][1][2]['actorID']='90009';
        $worker['define'][2][2]['token']='SYNTHETIC_WORKER_DTSG';
        $worker['define'][3][2]['token']='SYNTHETIC_WORKER_LSD';
        $worker['define'][4][2]['__spin_t']=200;$worker['define'][4][2]['ef_page']=null;
        $worker['define'][]=['DTSGInitData',[],['token'=>'SYNTHETIC_WORKER_OLD_DTSG','async_get_token'=>'SYNTHETIC_WORKER_ASYNC'],0];
        $worker['__bbox']['result']['data']['biz_inbox_config']['page_id']='80008';
        $worker['require'][1][3][0]['initialRouteInfo']['route']['params']['asset_id']='80008';
        $worker['require'][1][3][0]['expectedPreloaders'][0]['actorID']='80008';
        $baseline=(new PageInboxContextReader())->read($this->html($document),'10001','30003',self::URL);
        $resource=$this->html($this->workerResource($worker));$native=$this->html($document);
        foreach([$native.$resource,$resource.$native]as $html){
            $context=(new PageInboxContextReader())->read($html,'10001','30003',self::URL);
            self::assertSame($baseline->privateRequestFields(),$context->privateRequestFields());
            self::assertSame($baseline->queryVariables,$context->queryVariables);
            self::assertSame('30003',$context->pageId);self::assertSame('20002',$context->profileActorId);
            self::assertSame('100',$context->privateRequestFields()['__spin_t']);
        }
    }

    public function testDocumentConflictsStillFailWithAValidWorkerResource(): void
    {
        $document=$this->fixture();$conflict=['define'=>[['LSD',[],['token'=>'SYNTHETIC_CONFLICTING_DOCUMENT_LSD'],0]]];
        $resource=$this->html($this->workerResource($document));
        foreach([$this->html($document).$resource.$this->html($conflict),$resource.$this->html($conflict).$this->html($document)]as $html){
            try{(new PageInboxContextReader())->read($html,'10001','30003',self::URL);self::fail('A worker cannot hide conflicting document definitions');}
            catch(FacebookException $error){self::assertSame('facebook_inbox_context_unconfirmed',$error->reason);self::assertSame('The native inbox contains conflicting bootstrap modules',$error->getMessage());}
        }
    }

    public function testDocumentAsyncDataMailboxConfigAndWorkerSiblingDataRemainTraversable(): void
    {
        $document=$this->fixture();$config=$document['__bbox'];unset($document['__bbox']);
        $worker=$this->workerResource($this->fixture());
        // A real mailbox config is also delivered by AsyncData; do not skip the
        // resolve row, the entire resource, or every AsyncData argument.
        $document['require'][]=['AsyncData@synthetic-config','resolve',[],['synthetic-config',['__bbox'=>$config]]];
        $context=(new PageInboxContextReader())->read($this->html($document).$this->html($worker),'10001','30003',self::URL);
        self::assertSame('30003',$context->pageId);
        unset($document['require'][2]);
        $worker['require'][0][3][0]['__bbox']['require'][0][3][1]['data']['__bbox']['result']=$config['result'];
        $context=(new PageInboxContextReader())->read($this->html($document).$this->html($worker),'10001','30003',self::URL);
        self::assertSame('30003',$context->pageId);self::assertSame('20002',$context->profileActorId);
    }

    public function testWorkerCannotSupplyMissingDocumentProofEvenWhenItsOwnProofMatchesExpectedIds(): void
    {
        $document=$this->fixture();$resource=$this->html($this->workerResource($document));$cases=[[]];
        foreach([['define',0],['define',1],['require',1],['require',0]]as [$key,$index]){$x=$document;unset($x[$key][$index]);$cases[]=$x;}
        $x=$document;unset($x['define'][2],$x['define'][3]);$cases[]=$x;
        $x=$document;unset($x['__bbox']);$cases[]=$x;
        foreach($cases as $case)foreach([true,false]as $workerFirst){
            $native=$this->html($case);$html=$workerFirst?$resource.$native:$native.$resource;
            try{(new PageInboxContextReader())->read($html,'10001','30003',self::URL);self::fail('Worker proof must never fill document proof');}
            catch(FacebookException $error){self::assertContains($error->reason,['facebook_identity_unconfirmed','facebook_inbox_context_unconfirmed']);}
        }
    }

    public function testIncompleteOrMalformedWorkerBoundaryIsNotIgnored(): void
    {
        $document=$this->fixture();$worker=$document;$worker['define'][3][2]['token']='SYNTHETIC_CONFLICTING_WORKER_LSD';
        $valid=$this->workerResource($worker);$cases=[];
        $x=$valid;$x['require'][0][3][0]['__bbox']['require'][0][0]='AsyncData';$cases[]=$x;
        $x=$valid;$x['require'][0][3][0]['__bbox']['require'][0][1]='resolveOther';$cases[]=$x;
        $x=$valid;$x['require'][0][3][0]['__bbox']['require'][0][2]=['unconfirmed-dependency'];$cases[]=$x;
        foreach([0,1]as $marker){
            $x=$valid;$x['require'][0][3][0]['__bbox']['require'][0][3][1]['data']['__bbox']['hrp']['jsmods']['require'][$marker][1]='wrongMethod';$cases[]=$x;
            $x=$valid;$x['require'][0][3][0]['__bbox']['require'][0][3][1]['data']['__bbox']['hrp']['jsmods']['require'][$marker][0].='@wrong';$cases[]=$x;
        }
        $x=$valid;$payload=&$x['require'][0][3][0]['__bbox']['require'][0][3][1]['data']['__bbox'];$payload['other_resource']=$payload['hrp'];unset($payload['hrp']);unset($payload);$cases[]=$x;
        $x=$valid;$x['unrelated']=$x['require'][0][3][0]['__bbox']['require'];unset($x['require']);$cases[]=$x;
        foreach($cases as $case){
            try{(new PageInboxContextReader())->read($this->html($document).$this->html($case),'10001','30003',self::URL);self::fail('An unproven marker must not suppress a conflict');}
            catch(FacebookException $error){self::assertSame('facebook_inbox_context_unconfirmed',$error->reason);self::assertSame('The native inbox contains conflicting bootstrap modules',$error->getMessage());}
        }
    }

    public function testContextUsesNativePagePreloaderInsteadOfPersonalRelayDefault(): void
    {
        $context=(new PageInboxContextReader())->read($this->html($this->fixture()),'10001','30003',self::URL);
        self::assertSame('https://business.facebook.com',$context->origin);
        self::assertSame('30003',$context->actorId);self::assertSame('30003',$context->pageId);
        self::assertSame('20002',$context->profileActorId);
        self::assertSame(['page_id'=>null,'businessAssets'=>[['business_account_id'=>'40004','business_asset_id'=>'30003']],
            'businessAsset'=>['business_account_id'=>'40004','business_asset_id'=>'30003'],
            'isDependencyOfRemovePageDependencyEnabledTool'=>true,'getShouldUnifyConfigQueries'=>false,
            'shouldUnifyContextCardQueries'=>false,'shouldSkipPageQuery'=>false],$context->queryVariables);
        $fields=$context->privateRequestFields();self::assertSame('10001',$fields['__user']);
        self::assertSame('SYNTHETIC_PRIVATE_DTSG',$fields['fb_dtsg']);self::assertSame('SYNTHETIC_PRIVATE_LSD',$fields['lsd']);
        self::assertSame('123456',$fields['__rev']);self::assertSame('15',$fields['__comet_req']);
        self::assertSame('2'.array_sum(array_map(ord(...),str_split('SYNTHETIC_PRIVATE_DTSG'))),$fields['jazoest']);
        self::assertSame('10001',$fields['__aaid']);self::assertSame('40004',$fields['__bid']);
        self::assertSame('1.25',$fields['dpr']);self::assertSame('GOOD',$fields['__ccg']);
        self::assertArrayNotHasKey('unknown_secret',$fields);
        $debug=print_r($context,true);self::assertStringNotContainsString('SYNTHETIC_PRIVATE',$debug);self::assertStringNotContainsString('PRIVATE_EXPERIMENT_METADATA',$debug);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE',json_encode($context,JSON_THROW_ON_ERROR));
        try{serialize($context);self::fail('Private context must not be serialized');}catch(\LogicException){}
    }

    public function testPageBasedModeComesFromObservedGatesAndNativeVariables(): void
    {
        $context=(new PageInboxContextReader())->read($this->html($this->fixture(false,false)),'10001','30003',self::URL);
        self::assertSame('30003',$context->queryVariables['page_id']);
        self::assertSame([],$context->queryVariables['businessAssets']);self::assertNull($context->queryVariables['businessAsset']);
        self::assertFalse($context->queryVariables['isDependencyOfRemovePageDependencyEnabledTool']);
        self::assertTrue($context->queryVariables['getShouldUnifyConfigQueries']);self::assertTrue($context->queryVariables['shouldUnifyContextCardQueries']);
    }

    public function testMissingOrConflictingProofCannotFallBackToThePersonalActorOrInventAssets(): void
    {
        $cases=[];$f=$this->fixture();
        $x=$f;$x['define'][0][2]['USER_ID']='90009';$cases[]=$x;
        $x=$f;$x['define'][0][2]['ACCOUNT_ID']='90009';$cases[]=$x;
        $x=$f;$x['define'][1][2]['graphURI']='https://example.invalid/api/graphql/';$cases[]=$x;
        $x=$f;$x['define'][1][2]['actorID']='90009';$cases[]=$x;
        $x=$f;$x['require'][1][3][0]['expectedPreloaders'][0]['actorID']='10001';$cases[]=$x;
        $x=$f;$x['require'][1][3][0]['expectedPreloaders'][0]['queryName']='UnknownQuery';$cases[]=$x;
        $x=$f;$x['require'][1][3][0]['expectedPreloaders'][0]['variables']['business_assets'][0]['business_asset_id']='90009';$cases[]=$x;
        $x=$f;$x['require'][1][3][0]['expectedPreloaders'][0]['variables']['business_assets'][]=['business_account_id'=>'40004','business_asset_id'=>'50005'];$cases[]=$x;
        $x=$f;$x['require'][1][3][0]['initialRouteInfo']['route']['rootView']['props']['localScopes'][0]['type']='user';$cases[]=$x;
        $x=$f;$x['require'][1][3][0]['initialRouteInfo']['route']['params']['asset_id']='90009';$cases[]=$x;
        $x=$f;$x['__bbox']['result']['data']['biz_inbox_config']['page_id']='90009';$cases[]=$x;
        $x=$f;$x['__bbox']['result']['data']['biz_inbox_config']['profile_plus_id_for_delegate_page']='not-an-id';$cases[]=$x;
        $x=$f;unset($x['require'][0][3][0]['gkxData']);$cases[]=$x;
        $x=$f;unset($x['require'][0][3][0]['qexData']);$cases[]=$x;
        $x=$f;$x['require'][0][3][0]['qexData']['1407']['r']='true';$cases[]=$x;
        $x=$f;$x['require'][0][3][0]['gkxData']['22417']['result']=false;$cases[]=$x;
        $x=$f;unset($x['require'][1][3][0]['expectedPreloaders'][1]['variables']['shouldSkipPageQuery']);$cases[]=$x;
        $x=$f;$x['require'][1][0]='UnrelatedRootClient';$cases[]=$x;
        $x=$f;unset($x['__bbox']);$x['unrelated']=['biz_inbox_config'=>['page_id'=>'30003']];$cases[]=$x;
        $x=$f;unset($x['define'][2],$x['define'][3]);$cases[]=$x;
        $x=$f;$x['define'][]=['RelayAPIConfigDefaults',[],['graphURI'=>'/api/graphql/','useXController'=>true,'actorID'=>'90009'],0];$cases[]=$x;
        $x=$f;$x['require'][]=['HasteSupportData','handle',null,[['gkxData'=>['22417'=>['result'=>false]]]]];$cases[]=$x;
        $reader=new PageInboxContextReader();
        foreach($cases as $case){
            try{$reader->read($this->html($case),'10001','30003',self::URL);self::fail('An unconfirmed context must not become a usable request');}
            catch(FacebookException $error){self::assertContains($error->reason,['facebook_identity_unconfirmed','facebook_inbox_context_unconfirmed']);}
        }
        // A failed parse must not poison or supply missing state to the next document.
        self::assertSame('30003',$reader->read($this->html($f),'10001','30003',self::URL)->actorId);
    }

    public function testSprinkleUsesLsdOnlyIfDtsgIsUnavailableAndAllowsAbsentProfileWithoutInventingOne(): void
    {
        $f=$this->fixture();unset($f['define'][2],$f['__bbox']['result']['data']['biz_inbox_config']['profile_plus_id_for_delegate_page']);
        $f['define'][5][2]['should_randomize']=true;
        $context=(new PageInboxContextReader())->read($this->html($f),'10001','30003',self::URL);
        self::assertNull($context->profileActorId);
        self::assertSame((string)array_sum(array_map(ord(...),str_split('SYNTHETIC_PRIVATE_LSD'))),$context->privateRequestFields()['jazoest']);
    }

    public function testDocumentUrlCannotSelectAnUnrelatedOriginOrEmbedCredentials(): void
    {
        $rejected=0;
        foreach(['https://www.facebook.com/latest/inbox/all','https://business.facebook.com.evil.invalid/latest/inbox/all',
            'https://business.facebook.com/latest/ads','https://user:secret@business.facebook.com/latest/inbox/all']as $url){
            try{(new PageInboxContextReader())->read($this->html($this->fixture()),'10001','30003',$url);self::fail('Invalid origin accepted');}
            catch(\InvalidArgumentException){++$rejected;}
        }
        self::assertSame(4,$rejected);
    }
}
