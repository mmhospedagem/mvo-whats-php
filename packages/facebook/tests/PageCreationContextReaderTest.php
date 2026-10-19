<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\PageCreationContextReader;
use MVO\Facebook\Service\PagesService;
use MVO\Messenger\Auth\BoundWebSession;
use MVO\Messenger\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

final class PageCreationContextReaderTest extends TestCase
{
    private static function identity(string $id='10001'):string
    {
        return '<script type="application/json">'.json_encode(['require'=>[['ScheduledServerJS','handle',null,[['__bbox'=>[
            'define'=>[['CurrentUserInitialData',[],['USER_ID'=>$id],0]],
        ]]]]]],JSON_THROW_ON_ERROR).'</script>';
    }
    private static function legacyGate(bool $gate):string
    {
        return '<script>requireLazy(["HasteSupportData"],function(m){m.handle('.json_encode(['gkxData'=>['20935'=>['result'=>$gate,'hash'=>null]],
            'irrelevant_string'=>'quoted } and \\" brace'],JSON_THROW_ON_ERROR).');});</script>';
    }

    public function testLegacyHasteWrapperIsParsedAsInertJsonIncludingEscapedBraces():void
    {
        $r=(new PageCreationContextReader())->read(self::identity().self::legacyGate(true),'10001');
        self::assertSame(['creation_source'=>'comet','page_referrer'=>'launch_point','off_platform_creator_reachout_id'=>null],$r);
    }

    public function testCometScheduledServerJsonPreservesFalseRatherThanDefaulting():void
    {
        $script='<script type="application/json">'.json_encode(['require'=>[['ScheduledServerJS','handle',null,[['__bbox'=>[
            'require'=>[['HasteSupportData','handle',null,[['gkxData'=>['20935'=>['result'=>false,'hash'=>null]]]]]],
        ]]]]]],JSON_THROW_ON_ERROR).'</script>';
        $r=(new PageCreationContextReader())->read(self::identity().$script,'10001');self::assertSame('www',$r['creation_source']);
    }

    public function testMissingGateIsNotGuessedFromAnArbitraryScriptOrDataObject():void
    {
        $html=self::identity().'<script>window.unrelated={"gkxData":{"20935":{"result":true}}};</script>'
            .'<script type="application/json">{"message":{"gkxData":{"20935":{"result":true}}}}</script>';
        try{(new PageCreationContextReader())->read($html,'10001');self::fail('No authoritative Haste handler');}
        catch(FacebookException $e){self::assertSame('facebook_page_creation_context_unavailable',$e->reason);}
    }

    public function testConflictingGatesAndUnrelatedAccountsAreRejected():void
    {
        foreach([[self::identity().self::legacyGate(true).self::legacyGate(false),'facebook_page_creation_context_unavailable'],
            [self::identity('20002').self::legacyGate(true),'facebook_identity_unconfirmed']]as [$html,$reason]){
            try{(new PageCreationContextReader())->read($html,'10001');self::fail('Conflicting context');}
            catch(FacebookException $e){self::assertSame($reason,$e->reason);}
        }
    }

    public function testReaderDoesNotReuseGateFromAnEarlierResponse():void
    {
        $reader=new PageCreationContextReader();$reader->read(self::identity().self::legacyGate(true),'10001');
        try{$reader->read(self::identity(),'10001');self::fail('Stale gate');}
        catch(FacebookException $e){self::assertSame('facebook_page_creation_context_unavailable',$e->reason);}
    }

    public function testServiceGetsOnlyTheFixedObservedRouteUsingTheExistingOwner():void
    {
        $requests=[];$html=self::identity().self::legacyGate(true);
        $session=new BoundWebSession('10001',static fn():array=>[],static function(string $method,string $url,array $form)use(&$requests,$html):HttpResponse{
            $requests[]=['method'=>$method,'url'=>$url,'form'=>$form];return new HttpResponse(200,$html);
        });
        $r=(new PagesService($session))->creationRequestContext();
        self::assertSame('comet',$r['creation_source']);
        self::assertSame([['method'=>'GET','url'=>'https://www.facebook.com/pages/creation/?ref_type=launch_point','form'=>[]]],$requests);
    }
}
