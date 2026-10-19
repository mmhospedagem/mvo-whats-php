<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Service\ModerationService;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageAdministrationNativeFixture.php';

final class ModerationServiceTest extends TestCase
{
    public function testPageBanAndUnbanUseDistinctObservedInputsAndPageActor():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new ModerationService($f->graph);
            $f->session->responses['BizKitContextCardBlockWithProductLogicMutation']=['user_ban_biz_inbox'=>['user'=>['id'=>'30003']]];
            $result=$service->banUser('20002','30003','ban-key');self::assertSame('ban_response_received',$result['status']);
            self::assertFalse($result['current_state_confirmed']);self::assertSame($result,$service->banUser('20002','30003','ban-key'));
            self::assertCount(1,$f->session->requests);self::assertSame('20002',$f->session->requests[0]['form']['av']);
            self::assertSame('https://business.facebook.com/api/graphql/',$f->session->requests[0]['url']);
            self::assertSame(['user_id'=>'30003','blocksource'=>'META_BUSINESS_SUITE_WEB'],$f->session->variables('BizKitContextCardBlockWithProductLogicMutation')[0]['input']);
            $f->session->responses['BizKitContextCardUnblockMutation']=['user_unblock'=>['user'=>['id'=>'30003']]];
            $service->unbanUser('20002','30003','unban-key');
            self::assertSame(['blockee'=>'30003'],$f->session->variables('BizKitContextCardUnblockMutation')[0]['input']);
        }finally{unset($service);$f->close();}
    }

    public function testMessagingBlockConfirmsAnExplicitStateWithoutPretendingItBansThePerson():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new ModerationService($f->graph);$name='BizKitContextCardMessengerBlockMutation';
            $f->session->responses[$name]=['messenger_block'=>['messaging_actor'=>['id'=>'30003','is_message_blocked_by_viewer'=>true]]];
            $result=$service->setMessagesBlocked('20002','30003',true,'messages-block');self::assertTrue($result['messages_blocked']);
            self::assertSame('page_messaging',$result['scope']);self::assertArrayNotHasKey('banned',$result);
            self::assertSame(['request_id'=>'bizweb-mbt-srx-20002-30003','target_id'=>'30003','should_be_blocked'=>true],$f->session->variables($name)[0]['input']);
            $f->session->responses[$name]=['messenger_block'=>['messaging_actor'=>['id'=>'30003','is_message_blocked_by_viewer'=>false]]];
            self::assertFalse($service->setMessagesBlocked('20002','30003',false,'messages-unblock')['messages_blocked']);
        }finally{unset($service);$f->close();}
    }

    public function testMismatchedUserOrMessagingStateDoesNotConfirmTheMutation():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new ModerationService($f->graph);
            $f->session->responses['BizKitContextCardBlockWithProductLogicMutation']=['user_ban_biz_inbox'=>['user'=>['id'=>'99999']]];
            try{$service->banUser('20002','30003','mismatched-ban');self::fail('Other target');}
            catch(FacebookException $e){self::assertSame('facebook_response_shape_unknown',$e->reason);self::assertNotNull($e->commandId);}
            $f->session->responses['BizKitContextCardMessengerBlockMutation']=['messenger_block'=>['messaging_actor'=>['id'=>'30003','is_message_blocked_by_viewer'=>false]]];
            try{$service->setMessagesBlocked('20002','30003',true,'mismatched-state');self::fail('Different block state');}
            catch(FacebookException $e){self::assertSame('facebook_moderation_unconfirmed',$e->reason);}
        }finally{unset($service);$f->close();}
    }

    public function testUncertainBanIsNeverRetriedAndAvailabilityDoesNotClaimReportSubmission():void
    {
        $f=new PageAdministrationNativeFixture();
        try{
            $service=new ModerationService($f->graph);$name='BizKitContextCardBlockWithProductLogicMutation';$f->session->fail[$name]=true;
            foreach(['facebook_transport_failed','facebook_command_uncertain']as $reason){
                try{$service->banUser('20002','30003','uncertain-ban');self::fail('Uncertain operation');}
                catch(FacebookException $e){self::assertSame($reason,$e->reason);}
            }
            self::assertCount(1,$f->session->requests);self::assertFalse($service->availability()['operations']['submit_report']);
            self::assertFalse($service->availability()['validated_in_account']);
        }finally{unset($service);$f->close();}
    }
}
