<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\MediaDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\{DocumentRegistry,WebGraphQL};
use MVO\Facebook\Service\AlbumService;
use MVO\Facebook\Storage\CommandLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageServiceFixture.php';

/** Synthetic native Web contracts only: no album or asset is changed on Facebook. */
final class AlbumServiceTest extends TestCase
{
    private string $directory;
    private ?CommandLedger $ledger=null;
    private ?AlbumService $service=null;
    private PageFakeSession $session;
    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-album-'.bin2hex(random_bytes(6));mkdir($this->directory,0700);
        $this->session=new PageFakeSession();$this->ledger=new CommandLedger($this->directory.'/commands.sqlite');
        $this->service=new AlbumService(new WebGraphQL($this->session,new DocumentRegistry(MediaDocuments::documents()),$this->ledger));
    }
    protected function tearDown():void
    {
        $this->service=null;$this->ledger?->close();$this->ledger=null;gc_collect_cycles();
        foreach(glob($this->directory.'/*')?:[] as $file)unlink($file);rmdir($this->directory);
    }
    /** @return iterable<string,array{string}> */
    public static function commands():iterable{foreach(['create','edit','delete','add','cover','reorder'] as $name)yield $name=>[$name];}
    #[DataProvider('commands')]
    public function testTheObservedActorOperationAndProjectedResultAreDurableWithoutRepeating(string $command):void
    {
        $this->session->data=self::response($command);$first=$this->invoke($command);self::assertSame($first,$this->invoke($command));self::assertCount(1,$this->session->requests);
        self::assertSame('20002',$this->session->requests[0]['form']['av']);self::assertSame('10001',$this->session->requests[0]['form']['__user']);
        self::assertStringNotContainsString('SYNTHETIC_UNPROJECTED_PRIVATE',json_encode($first,JSON_THROW_ON_ERROR));
        self::assertSame('30003',$first['album_id']);
        $input=$this->session->variables()['input'];self::assertArrayNotHasKey('actor_id',$input);
        if($command==='create'){self::assertSame('SELF',$input['privacy']['base_state']);self::assertSame('COMPOSER_UUID',$input['client_mutation_id']);self::assertFalse($first['media_added']);}
        if($command==='add'){self::assertSame([['photo'=>['id'=>'40004','message'=>['text'=>'Caption']]],['video'=>['id'=>'50005']]],$input['attachments']);self::assertSame(['to_id'=>'30003'],$input['audience']);}
    }
    #[DataProvider('commands')]
    public function testAResultWithoutTheExpectedIdentityOrAssetEchoCannotConfirmOrRepeat(string $command):void
    {
        $this->session->data=self::response($command,false);
        foreach(['facebook_response_shape_unknown','facebook_command_uncertain'] as $reason){
            try{$this->invoke($command);self::fail('An unconfirmed album command was accepted');}catch(FacebookException $error){self::assertSame($reason,$error->reason);self::assertNotNull($error->commandId);}
        }
        self::assertCount(1,$this->session->requests);
    }
    #[DataProvider('commands')]
    public function testTransportTimeoutDoesNotRepeatARealMutation(string $command):void
    {
        $this->session->fail=true;
        foreach(['facebook_transport_failed','facebook_command_uncertain'] as $reason){try{$this->invoke($command);self::fail('Expected uncertainty');}catch(FacebookException $error){self::assertSame($reason,$error->reason);self::assertSame('unknown',$error->outcome);}}
        self::assertCount(1,$this->session->requests);
    }
    public function testItemsPreserveOpaqueCursorAndOnlyProjectKnownFields():void
    {
        $this->session->data=['node'=>['id'=>'30003','grid_media'=>self::page()]];
        $result=$this->service->items('20002','30003','OPAQUE+/==',8,2);
        self::assertSame('OPAQUE+/==',$this->session->variables()['cursor']);self::assertSame('40004',$result['items'][0]['id']);
        self::assertFalse($result['page_info']['has_next_page']);self::assertSame('Photo',$result['items'][0]['type']);
        self::assertSame('34407011978913359',$this->session->requests[0]['form']['doc_id']);
    }
    public function testProviderValuesCannotBeInventedOrDefaultedBeforeAlbumMutation():void
    {
        try{$this->service->delete('20002','30003','missing-provider',['scale'=>1]);self::fail('Missing providers were accepted');}catch(FacebookException $error){self::assertSame('facebook_provider_context_required',$error->reason);}
        self::assertSame([],$this->session->requests);
    }
    public function testInvalidDuplicateAssetsAndContributorsFailBeforeIO():void
    {
        try{$this->service->reorder('20002','30003',['40004','40004'],'duplicate',self::variables('useCometAlbumEditOrderMutation'));self::fail('Duplicates accepted');}catch(\InvalidArgumentException){}
        try{AlbumService::attachments([['type'=>'photo','id'=>'40004','url'=>'https://example.test/private']]);self::fail('Extra fields accepted');}catch(\InvalidArgumentException){}
        self::assertSame([],$this->session->requests);
    }
    private function invoke(string $command):array
    {
        return match($command){
            'create'=>$this->service->create('20002','Album title','Description',['allow'=>[],'base_state'=>'SELF','deny'=>[],'tag_expansion_state'=>'UNSPECIFIED'],'COMPOSER_UUID','create',self::variables('useCometPhotoAlbumCreateMutation')),
            'edit'=>$this->service->edit('20002','30003',['title'=>'Album title','description'=>'Description'],'edit',self::variables('CometAlbumEditAlbumMutation')),
            'delete'=>$this->service->delete('20002','30003','delete',self::variables('CometAlbumDeleteAlbumMutation')),
            'add'=>$this->service->addMedia('20002','30003',[['type'=>'photo','id'=>'40004','caption'=>'Caption'],['type'=>'video','id'=>'50005']],'COMPOSER_UUID','add',self::variables('useCometPhotoAlbumStoryCreateMutation')),
            'cover'=>$this->service->setCover('20002','30003','40004','cover',self::variables('useCometPhotoAlbumCoverUpdateMutation')),
            'reorder'=>$this->service->reorder('20002','30003',['40004','50005'],'reorder',self::variables('useCometAlbumEditOrderMutation')),
            default=>throw new \LogicException('Unknown fixture')};
    }
    private static function variables(string $name):array{$v=['scale'=>1];foreach(MediaDocuments::providers($name) as $provider)$v[$provider]=str_contains($provider,'AutoTranslationType')?'ORIGINAL':false;return $v;}
    private static function response(string $command,bool $valid=true):array
    {
        $a=['id'=>$valid?'30003':'99999','title'=>['text'=>'Album title'],'message'=>['text'=>'Description'],'private'=>'SYNTHETIC_UNPROJECTED_PRIVATE'];
        return match($command){
            'create'=>['album_create'=>['album'=>['id'=>$valid?'30003':null,'private'=>'SYNTHETIC_UNPROJECTED_PRIVATE']]],
            'edit'=>['album_edit'=>['album'=>$a]],'delete'=>['album_delete'=>['deleted_album_id'=>$valid?'30003':'99999']],
            'add'=>['story_create'=>['story'=>['profileAlbumView'=>$a+['grid_media'=>self::page()]]]],
            'cover'=>['album_edit'=>['album'=>$a+['album_cover_focused_image'=>['image'=>['id'=>'40004']]]]],
            'reorder'=>['album_reorder'=>['editAlbumView'=>$a+['media'=>self::page()]]],default=>[]};
    }
    private static function page():array{return ['edges'=>[['node'=>['id'=>'40004','__typename'=>'Photo','private'=>'SYNTHETIC_UNPROJECTED_PRIVATE']],['node'=>['id'=>'50005','__typename'=>'Video']]],'page_info'=>['end_cursor'=>null,'has_next_page'=>false]];}
}
