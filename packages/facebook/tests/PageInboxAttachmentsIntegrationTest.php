<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\PageInboxBootstrapSnapshotReader;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageInboxBootstrapSnapshotFixture.php';

final class PageInboxAttachmentsIntegrationTest extends TestCase
{
    /** Synthetic LSInsertBlobAttachment wire row with independently chosen fixture IDs.
     * @return array<mixed>
     */
    private function blob(string $thread='50005',string $message='mid.synthetic.50005',string $filename='imagem-sintetica.jpg',string $type='2'):array
    {
        $args=array_fill(0,70,[9]);$args[0]=$filename;$args[1]=[19,'1024'];
        $args[3]='https://scontent.synthetic.fbcdn.net/image.jpg?native=synthetic';$args[6]='image/jpeg';
        $args[8]='https://scontent.synthetic.fbcdn.net/preview.jpg';$args[11]='image/jpeg';
        $args[14]=[19,'640'];$args[15]=[19,'480'];$args[27]=[19,$thread];$args[28]=[19,'0'];
        $args[29]=[19,$type];$args[32]=$message;$args[34]=[19,'90001'];$args[48]=[19,'80'];
        return [5,'LSInsertBlobAttachment',...$args];
    }

    /** @param list<array<mixed>> $calls @return array<string,mixed> */
    private function fixture(array $calls):array
    {
        $f=PageInboxBootstrapSnapshotFixture::fixture();
        $payload=&$f['require'][2][3][1]['__bbox']['result']['data']['viewer']['unified_inbox_lightspeed_web_request']['payload'];
        $batch=json_decode($payload,true,flags:JSON_THROW_ON_ERROR);array_push($batch['step'][2][2],...$calls);
        $payload=json_encode($batch,JSON_THROW_ON_ERROR);
        return $f;
    }

    public function testMediaRowsAreCorrelatedToTheExactConfirmedPageMessageBeforeLocalSelection():void
    {
        $reader=new PageInboxBootstrapSnapshotReader();$doc=PageInboxBootstrapSnapshotFixture::document($this->fixture([$this->blob()]));
        $snapshot=$reader->read($doc);$messages=array_column($snapshot['messages'],null,'message_id');
        self::assertSame([],$messages['mid.synthetic.50006']['attachments']);
        $items=$messages['mid.synthetic.50005']['attachments'];self::assertCount(1,$items);
        self::assertSame('90001',$items[0]['id']);self::assertSame('image',$items[0]['type']);
        self::assertSame('https://scontent.synthetic.fbcdn.net/image.jpg?native=synthetic',$items[0]['url']);
        self::assertSame('imagem-sintetica.jpg',$items[0]['filename']);self::assertSame(1024,$items[0]['size_bytes']);
        self::assertSame(['partial'=>false,'unsupported_procedures'=>[]],$snapshot['attachment_coverage']);
        $other=$reader->read($doc,'50006');self::assertCount(1,$other['messages']);self::assertSame([],$other['messages'][0]['attachments']);
        self::assertStringNotContainsString('imagem-sintetica.jpg',json_encode($other['messages'],JSON_THROW_ON_ERROR));
    }

    public function testForeignTupleUnrelatedAttachmentOrUnmappedAttachmentItemNeverMovesOntoAnotherMessage():void
    {
        $calls=[$this->blob('50006','mid.synthetic.50005','FOREIGN_TUPLE_PRIVATE.jpg'),
            $this->blob('90009','mid.foreign','UNRELATED_PRIVATE.jpg'),[5,'LSInsertAttachmentItem','unknown native shape']];
        $snapshot=(new PageInboxBootstrapSnapshotReader())->read(PageInboxBootstrapSnapshotFixture::document($this->fixture($calls)));
        foreach($snapshot['messages']as $message)self::assertSame([],$message['attachments']);
        self::assertSame(['partial'=>true,'unsupported_procedures'=>['LSInsertAttachmentItem']],$snapshot['attachment_coverage']);
        self::assertStringNotContainsString('PRIVATE',json_encode($snapshot,JSON_THROW_ON_ERROR));
    }

    public function testInvalidAttachmentOrLaterUnsupportedAstAbortsAllSnapshotOutput():void
    {
        foreach(['invalid_attachment','invalid_ast']as $case){
            $blob=$this->blob();$calls=[$blob];
            if($case==='invalid_attachment')$calls[0][50]='not-an-authority';else $calls[]=[105,'unsupported'];
            try{(new PageInboxBootstrapSnapshotReader())->read(PageInboxBootstrapSnapshotFixture::document($this->fixture($calls)));self::fail('A malformed batch must not return partially enriched output');}
            catch(FacebookException $error){self::assertContains($error->reason,['facebook_inbox_attachment_unconfirmed','facebook_inbox_snapshot_unsupported']);}
        }
    }
}
