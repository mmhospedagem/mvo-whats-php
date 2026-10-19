<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Service\PagesService;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageManagementFixture.php';

final class PageManagementServiceTest extends TestCase
{
    /** @return array{end_point:string,entry_point:string} */
    private static function context():array{return ['end_point'=>'PAGE_SURFACE','entry_point'=>'PAGE_SURFACE_ABOUT_CARD'];}

    public function testFieldUpdatePreservesPageScopeAndConfirmedReplay():void
    {
        $f=new PageManagementFixture(['page_edit_blurb'=>['blurb'=>'Nova descrição','error'=>null]]);
        try{
            $service=new PagesService($f->session,null,$f->graph);
            $first=$service->updateField('20002','blurb','Nova descrição','description-key',self::context());
            self::assertSame($first,$service->updateField('20002','blurb','Nova descrição','description-key',self::context()));
            self::assertCount(1,$f->session->requests);self::assertSame('20002',$f->session->requests[0]['form']['av']);
            self::assertSame(['page_id'=>'20002','end_point'=>'PAGE_SURFACE','entry_point'=>'PAGE_SURFACE_ABOUT_CARD','blurb'=>'Nova descrição'],$f->session->variables()['input']);
            self::assertSame('field_change_confirmed',$first['status']);
            try{$service->updateField('20002','blurb','Outro','description-key',self::context());self::fail('Different content');}
            catch(FacebookException $e){self::assertSame('facebook_idempotency_conflict',$e->reason);}
            self::assertCount(1,$f->session->requests);
        }finally{unset($service);$f->close();}
    }

    public function testUnsafeActorAndUnknownContextAreRejectedBeforeIO():void
    {
        $f=new PageManagementFixture();
        try{
            $service=new PagesService($f->session,null,$f->graph);
            foreach([['actor'=>'30003','context'=>self::context()],['actor'=>null,'context'=>['end_point'=>'INVENTED','entry_point'=>'PAGE_SURFACE_ABOUT_CARD']]]as $case){
                try{$service->updateField('20002','name','Nome','key',$case['context'],$case['actor']);self::fail('Expected precondition');}
                catch(\InvalidArgumentException){self::assertCount(0,$f->session->requests);}
            }
        }finally{unset($service);$f->close();}
    }

    public function testUncertainFieldUpdateDoesNotResend():void
    {
        $f=new PageManagementFixture([],true);
        try{
            $service=new PagesService($f->session,null,$f->graph);
            foreach(['facebook_transport_failed','facebook_command_uncertain']as $reason){
                try{$service->updateField('20002','email','demo@example.test','email-key',self::context());self::fail('Uncertain');}
                catch(FacebookException $e){self::assertSame($reason,$e->reason);self::assertNotNull($e->commandId);}
            }
            self::assertCount(1,$f->session->requests);
        }finally{unset($service);$f->close();}
    }

    public function testNameErrorIsNotReportedAsSuccessfulApproval():void
    {
        $f=new PageManagementFixture(['page_edit_name'=>['page_name'=>'Original','error'=>'Not allowed']]);
        try{
            $service=new PagesService($f->session,null,$f->graph);
            try{$service->updateField('20002','name','New','name-key',self::context());self::fail('Rejected name');}
            catch(FacebookException $e){self::assertSame('facebook_page_field_rejected',$e->reason);self::assertNotNull($e->commandId);}
        }finally{unset($service);$f->close();}
    }

    public function testManagedPaginationPreservesProfileAndDelegateIDs():void
    {
        $f=new PageManagementFixture(['viewer'=>['actor'=>['additional_profiles_with_biz_tools'=>['edges'=>[['node'=>['id'=>'20002','delegate_page_id'=>'30003','name'=>'Demo']]],
            'page_info'=>['end_cursor'=>'EXACT+/==','has_next_page'=>true]]]]]);
        try{
            $service=new PagesService($f->session,null,$f->graph);$result=$service->managed();
            self::assertSame('20002',$result['items'][0]['actor_id']);self::assertSame('30003',$result['items'][0]['delegate_page_id']);self::assertFalse($result['exhaustive']);
            $service->next($result['page_info']['end_cursor']);self::assertSame('EXACT+/==',$f->session->variables(1)['cursor']);
        }finally{unset($service);$f->close();}
    }

    public function testDeleteMustConfirmTheExactPageAndOnlyRequestsDeletion():void
    {
        $f=new PageManagementFixture(['page_delete'=>['page'=>['id'=>'20002']]]);
        try{
            $service=new PagesService($f->session,null,$f->graph);$r=$service->delete('20002','delete-key');
            self::assertSame('deletion_requested',$r['status']);self::assertFalse($r['permanent_deletion_confirmed']);
            self::assertSame(['page_id'=>'20002'],$f->session->variables()['input']);
        }finally{unset($service);$f->close();}
    }

    public function testFieldReadDoesNotLeakUnknownRendererFields():void
    {
        $f=new PageManagementFixture(['page'=>['id'=>'20002','comet_page_admin_editing_field'=>['comet_page_editing_field_renderer'=>[
            '__typename'=>'PageAdminEditingEmailFieldRenderer','email_field_value'=>'demo@example.test','private'=>'NOT_PUBLIC']]]]);
        try{
            $service=new PagesService($f->session,null,$f->graph);$r=$service->field('20002','email',self::context());
            self::assertSame('demo@example.test',$r['value']);self::assertArrayNotHasKey('private',$r);
        }finally{unset($service);$f->close();}
    }
}
