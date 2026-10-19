<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Catalog\PageManagementDocuments;
use MVO\Facebook\Protocol\WebGraphQL;
use MVO\Facebook\Service\PagesService;
use MVO\Messenger\Auth\WebSession;
use MVO\Messenger\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageManagementFixture.php';

final class PageCreationServiceTest extends TestCase
{
    /** Explicit synthetic branch context; not values discovered from a real account. */
    private const CONTEXT=['creation_source'=>'comet','page_referrer'=>'SYNTHETIC_ROUTE_REFERRER'];
    /** @return array<string,mixed> */
    private static function created():array
    {
        return ['viewer'=>['can_create_page'=>true,'actor'=>['id'=>'10001','__typename'=>'User']],
            'additional_profile_plus_create'=>['additional_profile'=>['id'=>'20002'],'page'=>['id'=>'30003'],'name_error'=>null,'error_message'=>null]];
    }

    public function testCreationContextRequiresThePersonalOwnerAndProjectsNoFriends():void
    {
        $f=new PageManagementFixture(['viewer'=>['can_create_page'=>true,'actor'=>['id'=>'10001','__typename'=>'User','name'=>'Private name','friends'=>['edges'=>[['node'=>['id'=>'90009']]]]]],
            'additional_profile_plus_creation_mvp_suggested_categories'=>[['category_id'=>'40004','category_name'=>'Categoria fictícia']]]);
        try{
            $service=new PagesService($f->session,null,$f->graph);$r=$service->creationContext();
            self::assertTrue($r['can_create_page']);self::assertSame('10001',$r['actor_id']);self::assertSame('40004',$r['suggested_categories'][0]['id']);
            self::assertArrayNotHasKey('friends',$r);self::assertArrayNotHasKey('name',$r);
            self::assertSame('28981446454853803',$f->session->requests[0]['form']['doc_id']);self::assertSame('10001',$f->session->requests[0]['form']['av']);
        }finally{unset($service);$f->close();}
    }

    public function testBusinessActorIsNotReportedAsThePersonalCreationContext():void
    {
        $f=new PageManagementFixture(['viewer'=>['can_create_page'=>true,'actor'=>['id'=>'20002','__typename'=>'Page']]]);
        try{
            $service=new PagesService($f->session,null,$f->graph);
            try{$service->creationContext();self::fail('Page actor');}
            catch(FacebookException $e){self::assertSame('facebook_page_creation_requires_personal_profile',$e->reason);}
        }finally{unset($service);$f->close();}
    }

    public function testCategoryLookupUsesOnlyTheObservedSearchInputAndALocalLimit():void
    {
        $f=new PageManagementFixture(['page_creation_category_typeahead_search'=>['results'=>['nodes'=>[
            ['category_id'=>'40004','category_name'=>'Categoria um'],['category_id'=>'50005','category_name'=>'Categoria dois']]]]]);
        try{
            $service=new PagesService($f->session,null,$f->graph);$r=$service->categories('Categoria',1);
            self::assertCount(1,$r['items']);self::assertSame(2,$r['observed_count']);self::assertTrue($r['truncated_locally']);self::assertFalse($r['exhaustive']);
            self::assertSame(['params'=>['search_string'=>'Categoria']],$f->session->variables());
        }finally{unset($service);$f->close();}
    }

    public function testCreationPreservesBothReturnedIdsAndIdempotencyWithoutCompletingSetup():void
    {
        $f=new PageManagementFixture(self::created());
        try{
            $service=new PagesService($f->session,null,$f->graph);$r=$service->create('Página Fictícia',['40004'],'create-key',self::CONTEXT,'Descrição de teste.');
            self::assertSame('20002',$r['profile_id']);self::assertSame('30003',$r['page_id']);self::assertSame('20002',$r['actor_id']);self::assertSame('30003',$r['delegate_page_id']);
            self::assertSame('page_created',$r['status']);self::assertFalse($r['setup_complete']);
            self::assertSame(['bio'=>'Descrição de teste.','categories'=>['40004'],'creation_source'=>'comet','name'=>'Página Fictícia',
                'off_platform_creator_reachout_id'=>null,'page_referrer'=>'SYNTHETIC_ROUTE_REFERRER'],$f->session->variables(1)['input']);
            self::assertSame('10001',$f->session->requests[1]['form']['av']);self::assertSame('23863457623296585',$f->session->requests[1]['form']['doc_id']);
            self::assertSame(['AdditionalProfilePlusCreationRootQuery','AdditionalProfilePlusCreationMutation'],array_map(static fn(array $request):string=>$request['form']['fb_api_req_friendly_name'],$f->session->requests));
            self::assertSame('1',$f->session->requests[0]['form']['__req']);self::assertSame('2',$f->session->requests[1]['form']['__req']);
            // A confirmed replay needs neither another capability read nor a still-allowed actor.
            $f->session->fail=true;
            self::assertSame($r,$service->create('Página Fictícia',['40004'],'create-key',self::CONTEXT,'Descrição de teste.'));self::assertCount(2,$f->session->requests);
            try{$service->create('Nome Diferente',['40004'],'create-key',self::CONTEXT,'Descrição de teste.');self::fail('Conflicting creation');}
            catch(FacebookException $e){self::assertSame('facebook_idempotency_conflict',$e->reason);}
        }finally{unset($service);$f->close();}
    }

    public function testUncertainCreationIsNotRepeated():void
    {
        $f=new PageManagementFixture(self::created());
        try{
            $session=new class($f->session) implements WebSession{
                public function __construct(private readonly PageFakeSession $delegate){}
                public function accountId():string{return $this->delegate->accountId();}
                public function privateRequestFields():array{return $this->delegate->privateRequestFields();}
                public function request(string $method,string $url,array $form=[]):HttpResponse{
                    if(($form['fb_api_req_friendly_name']??null)==='AdditionalProfilePlusCreationMutation')$this->delegate->fail=true;
                    return $this->delegate->request($method,$url,$form);
                }
            };
            $graph=new WebGraphQL($session,PageManagementDocuments::registry(),$f->ledger);
            $service=new PagesService($session,null,$graph);
            foreach(['facebook_transport_failed','facebook_command_uncertain']as $reason){
                try{$service->create('Página Fictícia',['40004'],'uncertain-create',self::CONTEXT);self::fail('Uncertain creation');}
                catch(FacebookException $e){self::assertSame($reason,$e->reason);self::assertNotNull($e->commandId);}
            }
            self::assertCount(2,$f->session->requests);
        }finally{unset($service,$graph,$session);$f->close();}
    }

    public function testANameRejectionIsNotTreatedAsPageCreation():void
    {
        $f=new PageManagementFixture([...self::created(),'additional_profile_plus_create'=>['additional_profile'=>null,'page'=>null,'name_error'=>'Synthetic rejection','error_message'=>null]]);
        try{
            $service=new PagesService($f->session,null,$f->graph);
            try{$service->create('Página Fictícia',['40004'],'rejected-create',self::CONTEXT);self::fail('Rejected name');}
            catch(FacebookException $e){self::assertSame('facebook_page_creation_name_rejected',$e->reason);self::assertNotNull($e->commandId);}
            try{$service->create('Página Fictícia',['40004'],'rejected-create',self::CONTEXT);self::fail('Automatic resend');}
            catch(FacebookException $e){self::assertSame('facebook_command_uncertain',$e->reason);}
            self::assertCount(2,$f->session->requests);
        }finally{unset($service);$f->close();}
    }

    public function testBioLimitUsesHtmlUtf16UnitsAndRejectsBeforeAnyRequest():void
    {
        $f=new PageManagementFixture(self::created());
        try{
            $service=new PagesService($f->session,null,$f->graph);
            try{$service->create('Página Fictícia',['40004'],'too-long-bio',self::CONTEXT,str_repeat('😀',128));self::fail('256 UTF-16 units');}
            catch(\InvalidArgumentException){self::assertCount(0,$f->session->requests);}
            $r=$service->create('Página Fictícia',['40004'],'valid-bio',self::CONTEXT,str_repeat('😀',127).'a');
            self::assertSame('page_created',$r['status']);self::assertCount(2,$f->session->requests);
        }finally{unset($service);$f->close();}
    }

    public function testInvalidCategoryAndUnknownSourceDoNotPerformIo():void
    {
        $f=new PageManagementFixture();
        try{
            $service=new PagesService($f->session,null,$f->graph);
            foreach([[],['40004','40004'],['40004','50005','60006','70007'],['invented-category']]as $ids){
                try{$service->create('Página Fictícia',$ids,'invalid-create',self::CONTEXT);self::fail('Invalid categories');}
                catch(\InvalidArgumentException){self::assertCount(0,$f->session->requests);}
            }
            try{$service->create('Página Fictícia',['40004'],'invalid-source',['creation_source'=>'invented','page_referrer'=>'route']);self::fail('Invented branch');}
            catch(\InvalidArgumentException){self::assertCount(0,$f->session->requests);}
        }finally{unset($service);$f->close();}
    }

    public function testFreshNegativeCapabilityBlocksTheMutationAndPersistsNotExecuted():void
    {
        $f=new PageManagementFixture([...self::created(),'viewer'=>['can_create_page'=>false,'actor'=>['id'=>'10001','__typename'=>'User']]]);
        try{
            $service=new PagesService($f->session,null,$f->graph);$commandId=null;
            try{$service->create('Página Fictícia',['40004'],'negative-create',self::CONTEXT);self::fail('Creation unavailable');}
            catch(FacebookException $error){self::assertSame('facebook_page_creation_unavailable',$error->reason);self::assertSame('not_executed',$error->outcome);self::assertNotNull($error->commandId);$commandId=$error->commandId;}
            $command=$f->ledger->get($commandId);self::assertSame('not_confirmed',$command['state']);
            self::assertSame(['execution_started'=>false,'outcome'=>'not_executed','preflight_failure'=>'facebook_page_creation_unavailable'],$command['result']);
            self::assertCount(1,$f->session->requests);self::assertSame('AdditionalProfilePlusCreationRootQuery',$f->session->requests[0]['form']['fb_api_req_friendly_name']);
            // Even a now-positive response cannot turn this failed attempt into an implicit retry.
            $f->session->data=self::created();
            try{$service->create('Página Fictícia',['40004'],'negative-create',self::CONTEXT);self::fail('Failed attempt replayed');}
            catch(FacebookException $error){self::assertSame('facebook_command_not_executed',$error->reason);self::assertSame('not_executed',$error->outcome);self::assertSame($commandId,$error->commandId);}
            self::assertCount(1,$f->session->requests);
        }finally{unset($service);$f->close();}
    }

    public function testBusinessActorOrOtherPersonalActorCannotReachTheCreationMutation():void
    {
        foreach(['Page','Business','User']as $actorType){
            $f=new PageManagementFixture([...self::created(),'viewer'=>['can_create_page'=>true,'actor'=>['id'=>'20002','__typename'=>$actorType]]]);
            try{
                $service=new PagesService($f->session,null,$f->graph);
                try{$service->create('Página Fictícia',['40004'],'actor-create',self::CONTEXT);self::fail('Wrong creation actor');}
                catch(FacebookException $error){self::assertSame('facebook_page_creation_requires_personal_profile',$error->reason);self::assertSame('not_executed',$error->outcome);self::assertNotNull($error->commandId);self::assertFalse($f->ledger->get($error->commandId)['result']['execution_started']);}
                self::assertCount(1,$f->session->requests);self::assertSame('AdditionalProfilePlusCreationRootQuery',$f->session->requests[0]['form']['fb_api_req_friendly_name']);
            }finally{unset($service);$f->close();}
        }
    }

    public function testFailedReadOnlyPreflightDoesNotClaimMutationIoStarted():void
    {
        $f=new PageManagementFixture([],true);
        try{
            $service=new PagesService($f->session,null,$f->graph);$commandId=null;
            try{$service->create('Página Fictícia',['40004'],'preflight-timeout',self::CONTEXT);self::fail('Failed preflight');}
            catch(FacebookException $error){self::assertSame('facebook_transport_failed',$error->reason);self::assertSame('not_executed',$error->outcome);self::assertNotNull($error->commandId);self::assertFalse($f->ledger->get($error->commandId)['result']['execution_started']);$commandId=$error->commandId;}
            $f->session->fail=false;$f->session->data=self::created();
            try{$service->create('Página Fictícia',['40004'],'preflight-timeout',self::CONTEXT);self::fail('Failed preflight replayed');}
            catch(FacebookException $error){self::assertSame('facebook_command_not_executed',$error->reason);self::assertSame('not_executed',$error->outcome);self::assertSame($commandId,$error->commandId);}
            self::assertCount(1,$f->session->requests);self::assertSame('AdditionalProfilePlusCreationRootQuery',$f->session->requests[0]['form']['fb_api_req_friendly_name']);
        }finally{unset($service);$f->close();}
    }
}
