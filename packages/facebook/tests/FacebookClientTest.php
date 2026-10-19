<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookClient;
use MVO\Facebook\Service\{AlbumService,ModerationService,ContentInsightsService};
use MVO\Messenger\Auth\WebSession;
use MVO\Messenger\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

final class FacebookClientTest extends TestCase
{
    public function testTheLibraryDiscoversAndRestoresSelectionsWithoutSwitchingTheAuthenticatedAccount():void
    {
        $root=sys_get_temp_dir().'/mvo-facebook-client-'.bin2hex(random_bytes(8));mkdir($root,0700);
        try{
            $session=new class implements WebSession{
                public int $reads=0;
                public function accountId():string{return '10001';}
                public function privateRequestFields():array{return ['__user'=>'10001','fb_dtsg'=>'SYNTHETIC_PRIVATE'];}
                public function request(string $method,string $url,array $form=[]):HttpResponse{
                    $this->reads++;
                    if($method==='GET'){
                        TestCase::assertSame('https://www.facebook.com/pages/?category=your_pages',$url);
                        $body=['define'=>[['CurrentUserInitialData',[],['USER_ID'=>'10001'],0]],'__bbox'=>['result'=>['data'=>['viewer'=>['admined_pages'=>['nodes'=>[['id'=>'20002','name'=>'Synthetic Page']]]]]]]];
                        return new HttpResponse(200,'<script>'.json_encode($body,JSON_THROW_ON_ERROR).'</script>');
                    }
                    TestCase::assertSame('useFetchAdAccountsForL4ContextQuery',$form['fb_api_req_friendly_name']);
                    TestCase::assertSame('10001',$form['__user']);TestCase::assertSame('10001',$form['av']);
                    TestCase::assertTrue(json_decode($form['variables'],true)['skipLwiDefaultAdAccount']);
                    return new HttpResponse(200,'{"data":{"maiba_ad_accounts":{"edges":[{"node":{"legacy_account_id":"30003","name":null,"currency":"BRL","account_status":1}}]}}}');
                }
            };
            $client=new FacebookClient($session,$root);self::assertNull($client->selectedContext()['page']);
            self::assertInstanceOf(AlbumService::class,$client->albums());
            self::assertInstanceOf(ModerationService::class,$client->moderation());
            self::assertInstanceOf(ContentInsightsService::class,$client->contentInsights());
            self::assertSame(0,$session->reads,'Acquiring the new services must share the owner without implicit I/O');
            self::assertCount(1,$client->listPages()['items']);$client->selectPage('20002');
            $accounts=$client->listAdAccounts();self::assertFalse($accounts['exhaustive']);$client->selectAdAccount('30003');
            self::assertSame('10001',$client->accountId());self::assertSame('20002',$client->selectedContext()['page']['id']);
            self::assertSame('30003',$client->selectedContext()['ad_account']['id']);self::assertSame(1,$client->selectedContext()['ad_account']['status']);
            $documents=array_column($client->documentInventory(),'name');
            self::assertContains('PageCometLaunchpointLeftNavMenuRootQuery',$documents);self::assertContains('useFetchAdAccountsForL4ContextQuery',$documents);self::assertSame(2,$session->reads);
            foreach(['ProfileCometLegacyAlbumGridViewPaginationQuery','CometAlbumDeleteAlbumMutation','BizKitContextCardMessengerBlockMutation',
                'BizKitSettingsPageRolesMutationUserBasedRoleMutation','BizWebUnifiedTableCursorPaginator_data_refetchable','BizWebUnifiedTableCustomColumnModalQuery']as $name){
                self::assertContains($name,$documents,'The facade must register each service contract without custom consumer wiring');
            }
            $restored=new FacebookClient($session,$root);self::assertSame('20002',$restored->selectedContext()['page']['id']);self::assertSame(2,$session->reads);
            $client->close();
            foreach(['listPages','listAdAccounts','posts','comments','reactions','inbox','monitor','media','albums','moderation','contentInsights','administration','insights'] as $method){
                try{$client->$method();self::fail('A closed facade accepted a new service or query');}
                catch(\LogicException $error){self::assertStringContainsString('closed',$error->getMessage());}
            }
            self::assertSame(2,$session->reads);
        }finally{
            if(isset($client))$client->close();if(isset($restored))$restored->close();
            unset($client,$restored,$session);gc_collect_cycles();
            $it=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
            foreach($it as $entry){if($entry->isDir())rmdir($entry->getPathname());else unlink($entry->getPathname());}rmdir($root);
        }
    }
}
