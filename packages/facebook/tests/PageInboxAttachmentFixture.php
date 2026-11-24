<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

/** Synthetic native procedure rows; no real Page, message, filename, cookie or signed media URL. */
final class PageInboxAttachmentFixture
{
    /** @param list<array{name:string,args:list<mixed>}> $attachments
     * @return list<array{name:string,args:list<mixed>}>
     */
    public static function calls(array $attachments=[]):array
    {
        $message=array_fill(0,24,null);$message[0]=null;$message[3]='40004';$message[5]='1790000000000';$message[8]='mid.fixture.1';$message[10]='50005';
        return [
            ['name'=>'LSAddParticipantIdToGroupThread','args'=>['40004','30003']],
            ['name'=>'LSAddParticipantIdToGroupThread','args'=>['40004','50005']],
            ['name'=>'LSSetBusinessThreadInfo','args'=>['40004','80008']],
            ['name'=>'LSUpsertMessage','args'=>$message],...$attachments,
        ];
    }
    /** @return list<array<string,mixed>> */
    public static function messages():array{return [['id'=>'mid.fixture.1','message_id'=>'mid.fixture.1','thread_id'=>'40004','sender_id'=>'50005','text'=>null]];}

    /** Fields mirror the shipped LSInsertBlobAttachment writer, not inferred positions.
     * @param array<int,mixed> $overrides
     * @return array{name:string,args:list<mixed>}
     */
    public static function blob(int $type=2,array $overrides=[]):array
    {
        $args=array_fill(0,49,null);
        foreach([0=>'foto-sintetica.jpg',1=>'12345',2=>true,3=>'https://scontent.xx.fbcdn.net/v/t1/synthetic.jpg',5=>'3000',6=>'image/jpeg',
            8=>'https://scontent.xx.fbcdn.net/v/t1/synthetic-preview.jpg',10=>'3000',11=>'image/jpeg',14=>640,15=>480,22=>1234,
            27=>'40004',28=>0,29=>$type,31=>'1790000000000',32=>'mid.fixture.1',34=>'90009',48=>80]as $i=>$value)$args[$i]=$value;
        foreach($overrides as $i=>$value)$args[$i]=$value;
        return ['name'=>'LSInsertBlobAttachment','args'=>$args];
    }

    /** @param array<int,mixed> $overrides
     * @return array{name:string,args:list<mixed>}
     */
    public static function sticker(array $overrides=[]):array
    {
        $args=['40004','1790000000000','mid.fixture.1','70007','90009',1,'figurinha-sintetica.webp',0,null,true,false];
        foreach($overrides as $i=>$value)$args[$i]=$value;
        return ['name'=>'LSInsertOptimisticAttachment','args'=>$args];
    }

    /** Independent field map from the shipped LSInsertXmaAttachment writer.
     * @param array<int,mixed> $overrides
     * @return array{name:string,args:list<mixed>}
     */
    public static function xma(array $overrides=[]):array
    {
        $args=array_fill(0,133,null);
        foreach([2=>'0',3=>true,25=>'40004',26=>0,27=>7,29=>'1790000000000',30=>'mid.fixture.1',32=>'xma.synthetic.attachment-opaque',132=>80]as $i=>$value)$args[$i]=$value;
        foreach($overrides as $i=>$value)$args[$i]=$value;
        return ['name'=>'LSInsertXmaAttachment','args'=>$args];
    }
}
