<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\PagesBootstrapReader;
use MVO\Facebook\Service\PagesService;
use MVO\Messenger\Auth\WebSession;
use MVO\Messenger\Transport\HttpResponse;
use PHPUnit\Framework\TestCase;

final class PagesBootstrapReaderTest extends TestCase
{
    private function html(array $data,string $account='10001'):string
    {
        return '<script>'.json_encode(['define'=>[['CurrentUserInitialData',[],['USER_ID'=>$account],0]],'__bbox'=>['result'=>['data'=>$data]]],JSON_THROW_ON_ERROR).'</script>';
    }
    public function testDiscoveryKeepsProfileAndDelegateIdsSeparateAndSkipsUnknownPersonalProfiles():void
    {
        $html=$this->html(['viewer'=>['admined_pages'=>['nodes'=>[['id'=>'20002','name'=>'Synthetic classic Page']]],'actor'=>['profile_switcher_eligible_profiles'=>['nodes'=>[
            ['profile'=>['id'=>'30003','name'=>'Synthetic modern Page','is_profile_plus'=>true,'delegate_page_id'=>'40004']],
            ['profile'=>['id'=>'50005','name'=>'Synthetic personal profile']],
        ]]]]]);
        $result=(new PagesBootstrapReader())->read($html,'10001');self::assertCount(2,$result['items']);
        self::assertSame('30003',$result['items'][1]['actor_id']);self::assertSame('40004',$result['items'][1]['delegate_page_id']);
        self::assertFalse($result['exhaustive']);self::assertNull($result['page_info']['has_next_page']);
    }
    public function testLoginHtmlAndWrongIdentityCannotProduceEmptySuccess():void
    {
        foreach(['<html>login</html>',$this->html(['viewer'=>['admined_pages'=>['nodes'=>[]]]],'90009')] as $html){
            try{(new PagesBootstrapReader())->read($html,'10001');self::fail('Expected account verification failure');}
            catch(FacebookException $error){self::assertSame('facebook_identity_unconfirmed',$error->reason);}
        }
    }
    public function testHtmlScriptsAndUnrelatedJsonAreNeverExecutedOrAcceptedAsPages():void
    {
        $html='<script>throw new Error("DO NOT EXECUTE");</script>'.$this->html(['unrelated'=>['admined_pages'=>['nodes'=>[['id'=>'20002','name'=>'Not a Relay viewer']]]]]);
        $this->expectException(FacebookException::class);(new PagesBootstrapReader())->read($html,'10001');
    }
    public function testConfirmedEmptyPageCollectionIsDifferentFromAnUnknownFormat():void
    {
        $result=(new PagesBootstrapReader())->read($this->html(['viewer'=>['admined_pages'=>['nodes'=>[]]]]),'10001');
        self::assertSame([],$result['items']);self::assertFalse($result['exhaustive']);
    }
    public function testTransportFailuresAreTypedAndDoNotExposeThePrivateCause():void
    {
        $session=new class implements WebSession{
            public int $requests=0;
            public function accountId():string{return '10001';}
            public function privateRequestFields():array{return [];}
            public function request(string $method,string $url,array $form=[]):HttpResponse{
                $this->requests++;throw new \RuntimeException('SYNTHETIC_PRIVATE_CAUSE');
            }
        };
        try{(new PagesService($session))->list();self::fail('Expected a typed transport failure');}
        catch(FacebookException $error){
            self::assertSame('facebook_transport_failed',$error->reason);
            self::assertSame('not_confirmed',$error->outcome);self::assertTrue($error->retryable);
            self::assertStringNotContainsString('SYNTHETIC_PRIVATE',$error->getMessage());
        }
        self::assertSame(1,$session->requests);
    }
}
