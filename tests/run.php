<?php
declare(strict_types=1);
require __DIR__.'/../autoload.php';
use MVO\Crypto\Crypto;
use MVO\Media\MediaCrypto;
use MVO\Protocol\{BinaryCodec,BinaryNode,Jid,Protobuf};
use MVO\Transport\{FrameCodec,NoiseChannel,NoiseHandshake};
use MVO\Store\SqliteStore;
$passed = 0;
function eq(mixed $a, mixed $b): void { if (is_object($a) && is_object($b) ? $a != $b : $a !== $b) throw new RuntimeException('Values differ: '.var_export($a,true).' / '.var_export($b,true)); }
function fails(callable $f, string $class = Throwable::class): void {
    try { $f(); } catch (Throwable $e) { if ($e instanceof $class) return; throw $e; }
    throw new RuntimeException('Expected exception: '.$class);
}
function test(string $name, callable $fn): void {
    global $passed;
    try { $fn(); $passed++; echo "PASS $name\n"; }
    catch (Throwable $e) { fwrite(STDERR,"FAIL $name: {$e->getMessage()}\n{$e->getTraceAsString()}\n"); exit(1); }
}
test('RFC 5869 HKDF test case 1', function () {
    eq(bin2hex(Crypto::hkdf(str_repeat("\x0b",22),42,hex2bin('f0f1f2f3f4f5f6f7f8f9'),hex2bin('000102030405060708090a0b0c'))),'3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf34007208d5b887185865');
});
test('NIST AES-256-GCM empty plaintext vector',function () {
    eq(bin2hex(Crypto::encryptGcm(str_repeat("\0",32),str_repeat("\0",12),'')),'530f8afbc74536b9a963b4f1c4cb738b');
});
test('GCM authentication and input sizes',function () {
    $key = random_bytes(32); $n = Crypto::nonce(42); $c = Crypto::encryptGcm($key,$n,'payload','aad');
    eq(Crypto::decryptGcm($key,$n,$c,'aad'),'payload');
    fails(fn()=>Crypto::decryptGcm($key,$n,$c,'different'));
    fails(fn()=>Crypto::encryptGcm('bad',$n,''));
    fails(fn()=>Crypto::nonce(0x100000000));
    eq(bin2hex(Crypto::nonce(0x01020304)),'000000000000000001020304');
});
test('RFC 7748 X25519 known public key',function () {
    $private = hex2bin('77076d0a7318a57d3c16c17251b26645df4c2f87ebc0992ab177fba51db92c2a');
    eq(bin2hex(sodium_crypto_scalarmult_base($private)),'8520f0098930a754748b7ddcb43ef75a0dbf3a0d26381af4eba4a98eaa9b4e6a');
    $a = Crypto::x25519KeyPair(); $b = Crypto::x25519KeyPair();
    eq(Crypto::x25519($a['private'],$b['public']),Crypto::x25519($b['private'],$a['public']));
    fails(fn()=>Crypto::x25519($a['private'],str_repeat("\0",32)));
});
test('JIDs, LID, hosted, group and malformed input',function () {
    eq((string)Jid::recipient('+55 (11) 99999-0000'),'5511999990000@s.whatsapp.net');
    eq((string)Jid::recipient('123-456'),'123-456@g.us');
    eq(Jid::parse('123:4@lid')->device,4);
    eq((string)Jid::parse('123:4@hosted.lid')->canonical(),'123:4@lid');
    foreach (['','a@@b','a:x@lid','a@','@lid','a:999999999999999999999@lid'] as $bad) fails(fn()=>Jid::parse($bad));
});
test('WAP fixed wire vector and nested nodes',function () {
    $c = new BinaryCodec();
    eq(bin2hex($c->encode(new BinaryNode('iq',['type'=>'get']))),'f803190429');
    $n = new BinaryNode('message',['to'=>'123:4@lid','id'=>'12345'],[new BinaryNode('enc',['type'=>'msg'],"\0\xffhello")]);
    eq($c->decode($c->encode($n)),$n);
});
test('All 1259 WAP tokens decode to original spelling',function () {
    $tables = json_decode(file_get_contents(__DIR__.'/../resources/tokens.json'),true,512,JSON_THROW_ON_ERROR); $codec = new BinaryCodec();
    foreach ($tables['single'] as $i=>$word) eq($codec->decode("\xf8\x01".chr($i+1))->tag,$word);
    foreach ($tables['dictionaries'] as $d=>$words) foreach ($words as $i=>$word) eq($codec->decode("\xf8\x01".chr(236+$d).chr($i))->tag,$word);
});
test('WAP packed/JID/binary/list boundaries',function () {
    $c = new BinaryCodec();
    foreach (['12345','12-34.','ABCDEF','custom','123:255@hosted','123:256@lid','123@s.whatsapp.net'] as $v) {
        $n = new BinaryNode('test',['x'=>$v]); eq($c->decode($c->encode($n)),$n);
    }
    foreach ([0,1,255,256,1048575,1048576] as $size) {
        $n = new BinaryNode('data',[],str_repeat('z',$size)); eq($c->decode($c->encode($n)),$n);
    }
    $n = new BinaryNode('list',[],array_fill(0,256,new BinaryNode('item'))); eq($c->decode($c->encode($n)),$n);
});
test('WAP stanza compression and rejection paths',function () {
    $c = new BinaryCodec(); $n = new BinaryNode('iq'); $raw = $c->encode($n);
    eq($c->decode("\2".gzcompress($raw),true),$n);
    eq($c->decode("\2".gzdeflate($raw),true),$n);
    foreach (['',substr($raw,0,-1),$raw.'junk',"\xf8\x01\xff\x81\x1e"] as $bad) fails(fn()=>$c->decode($bad));
    fails(fn()=>$c->decode("\4".$raw,true));
    fails(fn()=>(new BinaryCodec(10))->decode("\2".gzcompress(str_repeat('x',100)),true));
    $n = new BinaryNode('a',[],[new BinaryNode('b')]); fails(fn()=>(new BinaryCodec(100,1))->encode($n));
    fails(fn()=>$c->decode("\xf8\1".str_repeat("\xfa",100)."\0"));
});
test('24-bit frames fragmentation, coalescing and limits',function () {
    $enc = new FrameCodec(); $dec = new FrameCodec(); $wire = $enc->encode('abc').$enc->encode('').$enc->encode('xyz'); $out = [];
    foreach (str_split($wire) as $b) $out = array_merge($out,$dec->push($b));
    eq($out,['abc','','xyz']); $dec->finish();
    eq((new FrameCodec())->push($wire),$out);
    $intro = new FrameCodec(FrameCodec::HEADER); eq(substr($intro->encode('a'),0,4),FrameCodec::HEADER); eq($intro->encode('b'),"\0\0\1b");
    fails(fn()=>(new FrameCodec('',2))->push("\0\0\3"));
    $dec->push("\0"); fails(fn()=>$dec->finish());
});
test('Noise handshake transcript, channel direction and replay rejection',function () {
    $a = new NoiseHandshake(); $b = new NoiseHandshake();
    foreach (['ephemeral','static'] as $s) { $a->authenticate($s); $b->authenticate($s); $a->mixKey($s); $b->mixKey($s); eq($b->decrypt($a->encrypt('hello')),'hello'); }
    $ca = $a->finish(); $cb = $b->finish(false); fails(fn()=>$a->finish());
    eq($cb->decrypt($ca->encrypt('one')),'one'); eq($ca->decrypt($cb->encrypt('two')),'two');
    $cipher = $ca->encrypt('three'); eq($cb->decrypt($cipher),'three'); fails(fn()=>$cb->decrypt($cipher)); fails(fn()=>$cb->encrypt('after failure'));
});
test('Media types, hashes, MAC tampering and empty payload',function () {
    foreach (['image','video','audio','document','sticker','ptt','ptv','history','md-app-state','group-history','sticker-pack','thumbnail-link'] as $type) foreach (['',"\0hello\xff"] as $plain) {
        $e = MediaCrypto::encrypt($plain,$type);
        eq(MediaCrypto::decrypt($e['body'],$type,$e['mediaKey'],$e['fileSha256'],$e['fileEncSha256']),$plain);
        $bad = $e['body']; $bad[0] = chr(ord($bad[0])^1); fails(fn()=>MediaCrypto::decrypt($bad,$type,$e['mediaKey']));
        fails(fn()=>MediaCrypto::decrypt($e['body'],$type,$e['mediaKey'],str_repeat('x',32)));
    }
    fails(fn()=>MediaCrypto::deriveKeys('unsupported',random_bytes(32)));
});
test('Media compatibility against independent Python cryptography vector',function () {
    $v = json_decode(file_get_contents(__DIR__.'/media-vector.json'),true,512,JSON_THROW_ON_ERROR);
    $key = hex2bin($v['key']); $plain = hex2bin($v['plain']); $body = hex2bin($v['body']);
    eq(MediaCrypto::encrypt($plain,'image',$key)['body'],$body);
    eq(MediaCrypto::decrypt($body,'image',$key),$plain);
});
test('Protobuf fixed wire vectors, repetition and invalid fields',function () {
    $f = [['number'=>1,'wire'=>0,'value'=>150],['number'=>2,'wire'=>2,'value'=>'testing'],['number'=>1,'wire'=>0,'value'=>0]];
    eq(bin2hex(Protobuf::encode($f)),'089601120774657374696e670800'); eq(Protobuf::decode(Protobuf::encode($f)),$f);
    foreach (['00','08','1203ff','0b','0880808080808080808080'] as $bad) fails(fn()=>Protobuf::decode(hex2bin($bad)));
    eq(Protobuf::decode(Protobuf::encode([['number'=>1,'wire'=>0,'value'=>PHP_INT_MAX]]))[0]['value'],PHP_INT_MAX);
});
test('SQLite persistence, binary values, session isolation and rollback',function () {
    $file = tempnam(sys_get_temp_dir(),'mvo');
    $a = $b = $reopened = null;
    try {
        $a = new SqliteStore($file,'a'); $b = new SqliteStore($file,'b');
        $a->set('auth','key',"\0\xff"); eq($a->get('auth','key'),"\0\xff"); eq($b->get('auth','key'),null);
        $reopened = new SqliteStore($file,'a');
        eq($reopened->get('auth','key'),"\0\xff"); $reopened->close();
        fails(fn()=>$a->transaction(function ($s) { $s->set('auth','key','changed'); throw new RuntimeException('rollback'); }));
        eq($a->get('auth','key'),"\0\xff");
        $a->transaction(fn($s)=>$s->set('auth','key','committed')); eq($a->get('auth','key'),'committed');
        $a->delete('auth','key'); eq($a->get('auth','key'),null);
    } finally {
        $a?->close(); $b?->close(); $reopened?->close();
        unset($a,$b,$reopened); gc_collect_cycles();
        foreach ([$file,$file.'-wal',$file.'-shm'] as $path) if (is_file($path)) unlink($path);
    }
});
echo "\n$passed tests passed. No live WhatsApp pairing was tested.\n";
