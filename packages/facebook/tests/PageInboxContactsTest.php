<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\{PageInboxContactProjector,PageInboxBootstrapSnapshotReader};
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageInboxBootstrapSnapshotFixture.php';

final class PageInboxContactsTest extends TestCase
{
    private const PHOTO='https://scontent.synthetic.fbcdn.net/avatar.jpg?signature=synthetic';

    /** @return array{name:string,args:list<mixed>} */
    private function verify(string $id,string $name,?string $photo=self::PHOTO,string $level='1'):array
    {
        $args=array_fill(0,23,null);$args[0]=$id;$args[2]=$photo;$args[3]=$name;$args[14]=$level;
        return ['name'=>'LSVerifyContactRowExists','args'=>$args];
    }

    /** @return array{name:string,args:list<mixed>} */
    private function replace(string $id,string $name,string $level):array
    {
        $args=array_fill(0,59,null);$args[0]=$id;$args[2]=self::PHOTO;$args[9]=$name;$args[21]=$level;
        return ['name'=>'LSDeleteThenInsertContact','args'=>$args];
    }

    public function testNativeContactOffsetsAndAuthorityArePreservedWithoutExposingOtherMetadata():void
    {
        $verify=$this->verify('60006','Nome sintético');$verify['args'][20]='PRIVATE_SECONDARY_NAME';$verify['args'][21]='PRIVATE_FIRST_NAME';
        $rows=(new PageInboxContactProjector())->project([$verify,$this->verify('60006','Do not overwrite'),
            $this->replace('60006','Contato confirmado','20'),$this->replace('60006','Lower authority','10'),$this->verify('60006','Still do not overwrite')]);
        self::assertSame(['id'=>'60006','name'=>'Contato confirmado','photo_url'=>self::PHOTO],$rows['60006']);
        self::assertStringNotContainsString('PRIVATE_',json_encode($rows,JSON_THROW_ON_ERROR));
        self::assertSame([],(new PageInboxContactProjector())->project([]));
    }

    public function testPhotoUrlsAreLiteralBoundedHttpsMetaUrlsAndDoNotBecomeInventedGraphUrls():void
    {
        foreach(['javascript:alert(1)','http://scontent.fbcdn.net/avatar.jpg','https://fbcdn.net.evil.invalid/avatar.jpg',
            'https://user:secret@scontent.fbcdn.net/avatar.jpg','https://scontent.fbcdn.net:444/avatar.jpg','https://scontent.fbcdn.net:443/avatar.jpg',
            'https://www.facebook.com/avatar.jpg','https://scontent.fbcdn.net/avatar.jpg#fragment',
            'https://scontent.fbcdn.net/avatar.jpg?x=%ZZ','https://scontent.fbcdn.net/avatar.jpg?x=%2',
            'https://scontent.fbcdn.net/avatar\\.jpg',
            "https://scontent.fbcdn.net/avatar.jpg\r\n"]as $url){
            self::assertNull(PageInboxContactProjector::photoUrl($url));
            self::assertSame(self::PHOTO,PageInboxContactProjector::photoUrl($url,self::PHOTO));
        }
        self::assertSame(self::PHOTO,PageInboxContactProjector::photoUrl(self::PHOTO));
        self::assertSame('https://lookaside.fbsbx.com/avatar.jpg?x=%2F',PageInboxContactProjector::photoUrl('https://lookaside.fbsbx.com/avatar.jpg?x=%2F'));
        self::assertNull(PageInboxContactProjector::photoUrl('60006'));
    }

    public function testMissingAndUnsafeContactDataNeverProduceAnUnrelatedDisplayIdentity():void
    {
        $rows=(new PageInboxContactProjector())->project([$this->verify('60006',' ',null),$this->verify('60007',"bad\0name",'javascript:alert(1)')]);
        self::assertNull($rows['60006']['name']);self::assertNull($rows['60006']['photo_url']);
        self::assertNull($rows['60007']['name']);self::assertNull($rows['60007']['photo_url']);
        $bad=$this->verify('60006','Synthetic');$bad['args'][14]='unknown';
        try{(new PageInboxContactProjector())->project([$this->verify('60007','Earlier row'),$bad]);self::fail('Malformed contact rows must abort the projection');}
        catch(FacebookException $error){self::assertSame('facebook_inbox_snapshot_unconfirmed',$error->reason);}
    }

    public function testSnapshotEnrichesOnlyConfirmedPageParticipantsAndPreservesExplicitThreadIdentity():void
    {
        $f=PageInboxBootstrapSnapshotFixture::fixture();
        $payload=&$f['require'][2][3][1]['__bbox']['result']['data']['viewer']['unified_inbox_lightspeed_web_request']['payload'];
        $batch=json_decode($payload,true,flags:JSON_THROW_ON_ERROR);$calls=&$batch['step'][2][2];
        foreach($calls as &$call){
            if(is_array($call)&&($call[1]??null)==='LSDeleteThenInsertThread'&&($call[9]??null)===[19,'50005'])$call[5]='';
            if(is_array($call)&&($call[1]??null)==='LSDeleteThenInsertThread'&&($call[9]??null)===[19,'50006'])$call[6]='https://scontent.synthetic.fbcdn.net/group.jpg';
        }
        unset($call);
        $calls[]=[5,'LSAddParticipantIdToGroupThread',[19,'50006'],[19,'60008']];
        foreach([$this->verify('60006','Cliente sintético A'),$this->verify('60007','Cliente sintético B'),$this->verify('60008','Outro participante'),$this->verify('30003','Página sintética'),$this->verify('90009','UNRELATED_PRIVATE_CONTACT')]as $row){
            $calls[]=[5,$row['name'],...$row['args']];
        }
        $payload=json_encode($batch,JSON_THROW_ON_ERROR);unset($payload,$calls);
        $reader=new PageInboxBootstrapSnapshotReader();$snapshot=$reader->read(PageInboxBootstrapSnapshotFixture::document($f));
        $threads=array_column($snapshot['threads'],null,'id');$messages=array_column($snapshot['messages'],null,'thread_id');
        self::assertSame('Cliente sintético A',$threads['50005']['name']);self::assertSame(self::PHOTO,$threads['50005']['photo_url']);
        self::assertSame([
            ['id'=>'30003','name'=>'Página sintética','photo_url'=>self::PHOTO,'is_page'=>true],
            ['id'=>'60006','name'=>'Cliente sintético A','photo_url'=>self::PHOTO,'is_page'=>false],
        ],$threads['50005']['participants']);
        self::assertSame('Dos',$threads['50006']['name']);self::assertSame('https://scontent.synthetic.fbcdn.net/group.jpg',$threads['50006']['photo_url']);
        self::assertNull($threads['50006']['thread_key']['other_user_id']);
        self::assertSame('Cliente sintético A',$messages['50005']['sender_name']);self::assertSame(self::PHOTO,$messages['50005']['sender_photo_url']);
        self::assertStringNotContainsString('UNRELATED_PRIVATE_CONTACT',json_encode($snapshot,JSON_THROW_ON_ERROR));
        $plain=$reader->read(PageInboxBootstrapSnapshotFixture::document());
        self::assertNull($plain['messages'][0]['sender_name']);self::assertNull($plain['messages'][0]['sender_photo_url']);
    }

    public function testContactsFromAnUnconfirmedOtherPageBatchCannotEnrichAnyRows():void
    {
        $f=PageInboxBootstrapSnapshotFixture::fixture();
        $f['require'][1][3][0]['expectedPreloaders'][3]['variables']['pageAssetId']='90009';
        $contact=$this->verify('60006','Must not cross Page scope');
        $f['require'][3]=PageInboxBootstrapSnapshotFixture::response(2,PageInboxBootstrapSnapshotFixture::batch(2,'80008',[[5,$contact['name'],...$contact['args']]]));
        $this->expectException(FacebookException::class);
        (new PageInboxBootstrapSnapshotReader())->read(PageInboxBootstrapSnapshotFixture::document($f));
    }

    public function testGroupAndUnknownThreadTypesDoNotAcquireTheOnlyRemainingParticipantsIdentity():void
    {
        foreach(['2','5','999']as $type){
            $f=PageInboxBootstrapSnapshotFixture::fixture();
            $payload=&$f['require'][2][3][1]['__bbox']['result']['data']['viewer']['unified_inbox_lightspeed_web_request']['payload'];
            $batch=json_decode($payload,true,flags:JSON_THROW_ON_ERROR);$calls=&$batch['step'][2][2];
            foreach($calls as &$call){
                if(is_array($call)&&($call[1]??null)==='LSDeleteThenInsertThread'&&($call[9]??null)===[19,'50005']){
                    $call[5]=[9];$call[11]=[19,$type];
                }
            }
            unset($call);$contact=$this->verify('60006','Only remaining participant');$calls[]=[5,$contact['name'],...$contact['args']];
            $payload=json_encode($batch,JSON_THROW_ON_ERROR);unset($payload,$calls);
            $snapshot=(new PageInboxBootstrapSnapshotReader())->read(PageInboxBootstrapSnapshotFixture::document($f));
            $threads=array_column($snapshot['threads'],null,'id');
            self::assertNull($threads['50005']['name']);self::assertNull($threads['50005']['photo_url']);
            $others=array_values(array_filter($threads['50005']['participants'],static fn(array $p):bool=>!$p['is_page']));
            self::assertSame([['id'=>'60006','name'=>'Only remaining participant','photo_url'=>self::PHOTO,'is_page'=>false]],$others);
        }
    }
}
