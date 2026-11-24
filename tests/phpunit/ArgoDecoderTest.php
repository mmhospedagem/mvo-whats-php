<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Protocol\{ArgoDecoder,MexArgoWireTypes};
final class ArgoDecoderTest extends TestCase {
    public function testCapturedWhatsAppGraphQlErrorFixture():void {
        $bytes=base64_decode('BK4CVGhlIHNjaGVtYSBzcGVjaWZpZXMgdGhlIGZpZWxkIGlzIG5vbi1udWxsLCBidXQgYSBudWxsIHZhbHVlIHdhcyByZXR1cm5lZCBieSB0aGUgYmFja2VuZGNvZGVudWxsX3ZhbHVlQmFkIFJlcXVlc3RlcnJvcl9jb2RlaXNfcmV0cnlhYmxlc2V2ZXJpdHlDUklUSUNBTAgAAKAGNAEEsAEDAgAEAggIFBYDAgAEBhQMGAAQCBAD',true);
        $result=ArgoDecoder::decode($bytes); self::assertNull($result['data']); self::assertCount(2,$result['errors']);
        self::assertSame('null_value',$result['errors'][0]['extensions']['code']); self::assertSame('Bad Request',$result['errors'][1]['message']); self::assertSame('CRITICAL',$result['errors'][1]['extensions']['severity']);
        self::assertSame([0],$result['errors'][0]['path']); self::assertSame([0],$result['errors'][1]['path']); self::assertSame(400,$result['errors'][1]['extensions']['error_code']);
        $wire=['type'=>'RECORD','fields'=>[
            'data'=>['type'=>'NULLABLE','of'=>['type'=>'RECORD','fields'=>[
                'xwa2_ent_get_certificates'=>['type'=>'NULLABLE','of'=>['type'=>'DESC']],
            ]]],
            'errors'=>['type'=>'ARRAY','of'=>['type'=>'ERROR'],'optional'=>true],
            'extensions'=>['type'=>'EXTENSIONS','optional'=>true],
        ]];
        $typed=ArgoDecoder::decode($bytes,$wire);
        self::assertNull($typed['data']);
        self::assertSame([['xwa2_ent_get_certificates'],['xwa2_ent_get_certificates']],array_column($typed['errors'],'path'));
        self::assertSame(400,$typed['errors'][1]['extensions']['error_code']);
    }
    public function testInlineObjectsScalarsAndStringBackreferences():void {
        // flags inline+self-describing; {name:'PHP',again:'PHP',value:1.5,raw:0xff}
        $bytes="\x06\x04\x08\x08name\x08\x06PHP\x0aagain\x08\x09\x0avalue\x0e".pack('e',1.5)."\x06raw\x0a\x02\xff\x00";
        self::assertSame(['data'=>['name'=>'PHP','again'=>'PHP','value'=>1.5,'raw'=>"\xff"],'errors'=>[]],ArgoDecoder::decode($bytes));
    }
    public function testMalformedLengthsAndInvalidBackreferencesFail():void {
        foreach (["\x04\xae\x02a","\x06\x08\x07","\x06\x03","\x06\x08\x02\xff"] as $bytes) {
            try { ArgoDecoder::decode($bytes); self::fail('Accepted malformed Argo'); } catch (\UnexpectedValueException|\LengthException) { self::addToAssertionCount(1); }
        }
    }
    public function testTypedErrorLocationsPathAndExtensionsUseSharedIntegerBlock():void {
        // Self describing header, String block, Int block, then core:
        // data=null; one error(message='bad', location=[line2,column3],path=[4,5],extensions={code:403}).
        $strings='badcode';$ints=self::label(2).self::label(3).self::label(4).self::label(5).self::label(403);
        $core=self::label(-1).self::label(1).self::label(3).self::label(1).self::label(2).self::label(0).self::label(2).self::label(1).self::label(4).self::label(6).self::label(-2);
        $bytes="\x04".self::label(strlen($strings)).$strings.self::label(strlen($ints)).$ints.self::label(strlen($core)).$core;
        $decoded=ArgoDecoder::decode($bytes); self::assertSame(['message'=>'bad','path'=>[4,5],'extensions'=>['code'=>403],'location'=>[['line'=>2,'column'=>3]]],$decoded['errors'][0]);
    }
    public function testAbsentPathDoesNotConsumeIntegerExtensions():void {
        $strings='badcode';$ints=self::label(401);$core=self::label(-1).self::label(1).self::label(3).self::label(-2).self::label(-2).self::label(0).self::label(2).self::label(1).self::label(4).self::label(6).self::label(-2);
        $bytes="\x04".self::label(strlen($strings)).$strings.self::label(strlen($ints)).$ints.self::label(strlen($core)).$core;
        $decoded=ArgoDecoder::decode($bytes);self::assertNull($decoded['errors'][0]['path']);self::assertSame(401,$decoded['errors'][0]['extensions']['code']);
    }
    public function testEmptyObjectConsumesItsImplicitEmptyStringSegment():void {
        $core=self::label(2).self::label(0).self::label(0).self::label(-2);
        $bytes="\x04".self::label(0).self::label(strlen($core)).$core;
        self::assertSame(['data'=>[],'errors'=>[]],ArgoDecoder::decode($bytes));
    }
    public function testMessageCappingKeepsIntegerAndEnumBlocksSeparate():void
    {
        $core=implode('',array_map(self::label(...),[0,0,3,3,-4,1,1,1,-2,-2]));
        $segments=[self::label(100).self::label(17),'nowend','A','B','C',$core];
        $bytes="\x04".implode('',array_map(static fn(string $v):string=>self::label(strlen($v)).$v,$segments));
        self::assertSame(['data'=>['xwa2_message_capping_info'=>[
            'total_quota'=>100,'used_quota'=>17,'cycle_start_timestamp'=>'now','cycle_end_timestamp'=>'end',
            'server_sent_timestamp'=>'now','ote_status'=>'A','mv_status'=>'B','capping_status'=>'C',
        ]],'errors'=>[]],ArgoDecoder::decode($bytes,MexArgoWireTypes::get('FetchNewChatMessageCappingInfo')));
    }

    public function testModernHeaderAndMalformedTypedValues():void
    {
        self::assertSame(['data'=>['ok'=>true],'errors'=>[]],ArgoDecoder::decode(base64_decode('AgRvawgEAgQC',true)));
        $wire=MexArgoWireTypes::get('FetchReachoutTimelock');
        $bytes=base64_decode('BBQxNzg4MTMyNzYzDkRFRkFVTFQMAAIUDgMD',true);
        $badBoolean=substr_replace($bytes,"\x04",-5,1);
        foreach ([$badBoolean,substr($bytes,0,-1),$bytes."\x00"] as $invalid) {
            try {
                ArgoDecoder::decode($invalid,$wire);
                self::fail('Accepted malformed schema-driven Argo');
            } catch (\UnexpectedValueException|\LengthException) {
                self::addToAssertionCount(1);
            }
        }
    }
    private static function label(int $n):string { $v=$n>=0 ? $n*2 : -$n*2-1;$out='';do {$b=$v & 127;$v=intdiv($v,128);$out.=chr($b|($v>0 ? 128 : 0));}while($v>0);return $out; }
}
