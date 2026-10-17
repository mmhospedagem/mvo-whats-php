<?php
declare(strict_types=1);
namespace MVO\Tests;
use PHPUnit\Framework\TestCase;
use MVO\Testing\{FakeMediaClient,FakeMediaServer};

final class FakeMediaTest extends TestCase
{
    public function testTestCertificateIsReusedThenRegeneratedWhenExpired():void
    {
        $directory=sys_get_temp_dir().'/mvo-fake-certificate-'.bin2hex(random_bytes(8));$first=new FakeMediaServer($directory);$second=null;$third=null;
        try {$certificate=file_get_contents($first->caFile());$second=new FakeMediaServer($directory);self::assertSame($certificate,file_get_contents($second->caFile()));
            $key=openssl_pkey_get_private(file_get_contents($directory.'/key.pem'));$options=['config'=>$directory.'/openssl.cnf','digest_alg'=>'sha256'];$csr=openssl_csr_new(['commonName'=>'MVO offline fake localhost'],$key,$options);$expired=openssl_csr_sign($csr,null,$key,0,$options);openssl_x509_export($expired,$pem);file_put_contents($first->caFile(),$pem);
            $third=new FakeMediaServer($directory);$replacement=file_get_contents($third->caFile());self::assertNotSame($pem,$replacement);$parsed=openssl_x509_parse($replacement);self::assertGreaterThan(time()+3600,$parsed['validTo_time_t']);self::assertTrue(openssl_x509_check_private_key($replacement,file_get_contents($directory.'/key.pem')));
            $client=new FakeMediaClient($third->caFile(),'127.0.0.1:1');foreach(['http://localhost/file','https://example.com/file','https://name@localhost/file','https://localhost:8443/file'] as $url){try{$client(['method'=>'GET','url'=>$url,'maxBytes'=>100]);self::fail('Unsafe test media URL accepted');}catch(\InvalidArgumentException $expected){self::assertStringContainsString('explicit localhost',$expected->getMessage());}}
        }finally {$first->close();$second?->close();$third?->close();foreach(new \FilesystemIterator($directory) as $file)unlink($file->getPathname());rmdir($directory);}
    }
}
