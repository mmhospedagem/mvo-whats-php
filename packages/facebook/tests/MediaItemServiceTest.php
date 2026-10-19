<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\MediaDocuments;
use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\{DocumentRegistry,WebGraphQL};
use MVO\Facebook\Service\MediaService;
use MVO\Facebook\Storage\CommandLedger;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageServiceFixture.php';

/** Synthetic photo/video caption/deletion contracts, never a deletion in a real account. */
final class MediaItemServiceTest extends TestCase
{
    private string $directory;
    private ?CommandLedger $ledger=null;
    private ?MediaService $service=null;
    private PageFakeSession $owner;
    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-media-item-'.bin2hex(random_bytes(6));mkdir($this->directory,0700);$this->owner=new PageFakeSession();
        $this->ledger=new CommandLedger($this->directory.'/commands.sqlite');$this->service=new MediaService($this->owner,$this->ledger,new WebGraphQL($this->owner,new DocumentRegistry(MediaDocuments::documents()),$this->ledger));
    }
    protected function tearDown():void{$this->service=null;$this->ledger?->close();$this->ledger=null;gc_collect_cycles();foreach(glob($this->directory.'/*')?:[] as $file)unlink($file);rmdir($this->directory);}
    /** @return iterable<string,array{string,string}> */
    public static function operations():iterable{foreach(['photo','video'] as $type)foreach(['edit','delete'] as $op)yield $type.' '.$op=>[$type,$op];}
    #[DataProvider('operations')]
    public function testExactAssetAndActorAreProjectedAndNeverAutomaticallyRepeated(string $type,string $operation):void
    {
        $this->owner->data=self::response($type,$operation);$r=$this->invoke($type,$operation);self::assertSame($r,$this->invoke($type,$operation));self::assertCount(1,$this->owner->requests);
        self::assertSame('20002',$this->owner->requests[0]['form']['av']);self::assertSame('30003',$r['media_id']);self::assertSame('30003',$this->owner->variables()['input'][$type.'_id']);
        self::assertStringNotContainsString('PRIVATE',json_encode($r,JSON_THROW_ON_ERROR));if($operation==='delete')self::assertSame('asset',$r['scope']);
    }
    #[DataProvider('operations')]
    public function testAnotherAssetOrMissingConfirmationNeverConfirmsTheCommand(string $type,string $operation):void
    {
        $this->owner->data=self::response($type,$operation,false);
        foreach(['facebook_response_shape_unknown','facebook_command_uncertain'] as $reason){try{$this->invoke($type,$operation);self::fail('Wrong asset confirmed');}catch(FacebookException $e){self::assertSame($reason,$e->reason);}}
        self::assertCount(1,$this->owner->requests);
    }
    #[DataProvider('operations')]
    public function testNetworkFailureCannotAutomaticallyRepeatCaptionOrDeletion(string $type,string $operation):void
    {
        $this->owner->fail=true;foreach(['facebook_transport_failed','facebook_command_uncertain'] as $reason){try{$this->invoke($type,$operation);self::fail('Failure confirmed');}catch(FacebookException $e){self::assertSame($reason,$e->reason);self::assertSame('unknown',$e->outcome);}}
        self::assertCount(1,$this->owner->requests);
    }
    public function testDeletingAnAssetRequiresItsActualCurrentProfileCoverFlags():void
    {
        try{$this->service->deleteMedia('20002','photo','30003','missing',[],self::variables('CometMediaViewerPhotoDeleteActionMutation'));self::fail('Missing flags accepted');}catch(FacebookException $e){self::assertSame('facebook_operation_context_required',$e->reason);}
        self::assertSame([],$this->owner->requests);
    }
    private function invoke(string $type,string $op):array
    {
        $name=$op==='edit'?'CometMediaViewer'.ucfirst($type).'ActionsEditMessageMutation':'CometMediaViewer'.ucfirst($type).'DeleteActionMutation';
        return $op==='edit'?$this->service->editCaption('20002',$type,'30003','New caption','edit-'.$type,self::variables($name)):
            $this->service->deleteMedia('20002',$type,'30003','delete-'.$type,$type==='photo'?['isCoverPhoto'=>false,'isProfilePic'=>false]:['isProfileVideo'=>false],self::variables($name));
    }
    private static function variables(string $name):array{$v=['scale'=>1];foreach(MediaDocuments::providers($name) as $p)$v[$p]=str_contains($p,'AutoTranslationType')?'ORIGINAL':false;return $v;}
    private static function response(string $type,string $op,bool $valid=true):array{return $op==='edit'?[$type.'_edit_message'=>[$type=>['id'=>$valid?'30003':'99999','message'=>['text'=>'New caption'],'private'=>'PRIVATE']]]:[$type.'_delete'=>['deleted_'.$type.'_id'=>$valid?'30003':'99999','private'=>'PRIVATE']];}
}
