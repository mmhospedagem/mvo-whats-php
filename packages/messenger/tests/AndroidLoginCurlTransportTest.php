<?php
declare(strict_types=1);

namespace MVO\Messenger\Tests;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\AndroidLoginCurlTransport;
use MVO\Messenger\Transport\AndroidLoginTransport;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AndroidLoginCurlTransportTest extends TestCase
{
    /** @return iterable<string,array{string}> */
    public static function unsafeUrls(): iterable
    {
        foreach ([
            'http://graph.facebook.com/auth/login', 'https://example.test/auth/login',
            'https://graph.facebook.com.example.test/auth/login', 'https://graph.facebook.com./auth/login',
            'https://graph.facebook.com:443/auth/login', 'https://graph.facebook.com:8443/auth/login',
            'https://graph.facebook.com/auth/login?access_token=SYNTHETIC_SECRET',
            'https://graph.facebook.com/auth/login#SYNTHETIC_SECRET',
            'https://user:SYNTHETIC_SECRET@graph.facebook.com/auth/login',
            'https://graph.facebook.com/other', 'https://graph.facebook.com/auth/login/',
            'https://graph.facebook.com/auth/../auth/login', 'https://graph.facebook.com/auth%2flogin',
            'https://graph.facebook.com//auth/login', 'https://graph.facebook.com\\@example.test/auth/login',
            "https://graph.facebook.com/auth/login\r\nX-Secret: SYNTHETIC_SECRET",
            "https://graph.facebook.com/auth/login\0", 'file:///etc/passwd',
            'https://graph.facebook.com/check_approved_machine?u=123&m=SYNTHETIC_SECRET',
            'https://b-graph.facebook.com/check_approved_machine?access_token=SYNTHETIC_SECRET',
            'https://graph.facebook.com/check_approved_machine#SYNTHETIC_SECRET',
            'https://graph.facebook.com/check_approved_machine/',
            'http://graph.facebook.com/check_approved_machine',
            'https://graph.facebook.com.example.test/check_approved_machine',
            'http://graph.facebook.com/123/twofacsms',
            'https://example.test/123/twofacsms',
            'https://graph.facebook.com.example.test/123/twofacsms',
            'https://graph.facebook.com./123/twofacsms',
            'https://graph.facebook.com:443/123/twofacsms',
            'https://user:secret@graph.facebook.com/123/twofacsms',
            'https://graph.facebook.com/0/twofacsms',
            'https://graph.facebook.com/0123/twofacsms',
            'https://graph.facebook.com/12345678901234567890123456/twofacsms',
            'https://graph.facebook.com/-123/twofacsms',
            'https://graph.facebook.com/+123/twofacsms',
            'https://graph.facebook.com/1e3/twofacsms',
            'https://graph.facebook.com/１２３/twofacsms',
            'https://graph.facebook.com/%31%32%33/twofacsms',
            'https://graph.facebook.com//123/twofacsms',
            'https://graph.facebook.com/123/../123/twofacsms',
            'https://graph.facebook.com/123/twofacsms/',
            'https://graph.facebook.com/123/twofacsms?first_factor=SYNTHETIC_SECRET',
            'https://graph.facebook.com/123/twofacsms#SYNTHETIC_SECRET',
            'https://b-graph.facebook.com/123/twofacsms?method=GET',
            "https://graph.facebook.com/123/twofacsms\n",
            "https://graph.facebook.com/123/twofacsms\0",
            'https://graph.facebook.com/me?access_token=SYNTHETIC_SECRET',
            'https://b-graph.facebook.com/me?fields=id',
            'https://graph.facebook.com/me#SYNTHETIC_SECRET',
            'https://graph.facebook.com/me/',
            'http://graph.facebook.com/me',
            'https://graph.facebook.com.example.test/me',
            'https://graph.facebook.com:443/me',
            'https://user:SYNTHETIC_SECRET@graph.facebook.com/me',
            'https://graph.facebook.com/%6de',
        ] as $index => $url) yield 'target-' . $index => [$url];
    }

    #[DataProvider('unsafeUrls')]
    public function testRejectsUnsafeTargetsBeforeAnyNetwork(string $url): void
    {
        $transport = new AndroidLoginCurlTransport(0.001);
        try {
            $transport->request('POST', $url, ['password' => 'SYNTHETIC_SECRET']);
            self::fail('Unsafe target admitted');
        } catch (MessengerException $error) {
            self::assertSame('login_unsafe_redirect', $error->reason);
            self::assertStringNotContainsString('SYNTHETIC_SECRET', $error->getMessage());
            self::assertStringNotContainsString($url, $error->getMessage());
        }
    }

    public function testOnlyCanonicalNativeRoutesAreAdmitted(): void
    {
        self::assertInstanceOf(AndroidLoginTransport::class, new AndroidLoginCurlTransport());
        foreach (['graph.facebook.com', 'b-graph.facebook.com'] as $host) {
            AndroidLoginCurlTransport::validateUrl('https://' . $host . '/pwd_key_fetch');
            AndroidLoginCurlTransport::validateUrl('https://' . $host . '/auth/login');
            AndroidLoginCurlTransport::validateUrl('https://' . $host . '/check_approved_machine');
            AndroidLoginCurlTransport::validateUrl('https://' . $host . '/1/twofacsms');
            AndroidLoginCurlTransport::validateUrl('https://' . $host . '/1234567890123456789012345/twofacsms');
            AndroidLoginCurlTransport::validateUrl('https://' . $host . '/me');
            self::addToAssertionCount(6);
        }
    }

    /** @return iterable<string,array{string,string,array<mixed>,array<mixed>}> */
    public static function invalidRequests(): iterable
    {
        $key = 'https://graph.facebook.com/pwd_key_fetch';
        $login = 'https://graph.facebook.com/auth/login';
        yield 'GET-key' => ['GET', $key, [], []];
        yield 'GET-bgraph-key' => ['GET', 'https://b-graph.facebook.com/pwd_key_fetch', [], []];
        yield 'GET-login' => ['GET', $login, [], []];
        yield 'DELETE' => ['DELETE', $login, [], []];
        yield 'lowercase' => ['post', $login, [], []];
        yield 'method-injection' => ["POST\r\nX-Test: x", $login, [], []];
        yield 'numeric-form-key' => ['POST', $key, ['0' => 'test'], []];
        yield 'nested-form' => ['POST', $key, ['flow' => ['test']], []];
        yield 'integer-form' => ['POST', $login, ['flag' => 1], []];
        yield 'empty-form-key' => ['POST', $login, ['' => 'test'], []];
        yield 'control-form-key' => ['POST', $login, ["field\r\n" => 'test'], []];
        yield 'form-size' => ['POST', $login, ['password' => str_repeat('X', 1048577)], []];
        yield 'encoded-form-size' => ['POST', $login, ['password' => str_repeat('%', 800000)], []];
        yield 'forbidden-host-header' => ['POST', $login, [], ['Host' => 'example.test']];
        yield 'forbidden-cookie' => ['POST', $login, [], ['Cookie' => 'synthetic=secret']];
        yield 'forbidden-accept-override' => ['POST', $login, [], ['Accept' => '*/*']];
        yield 'injected-header-name' => ['POST', $login, [], ["Authorization\r\n" => 'secret']];
        yield 'injected-header-value' => ['POST', $login, [], ['Authorization' => "secret\r\nHost: example.test"]];
        yield 'control-header-value' => ['POST', $login, [], ['Authorization' => "secret\0"]];
        yield 'oversized-header' => ['POST', $login, [], ['Authorization' => str_repeat('X', 8193)]];
        yield 'duplicate-header' => ['POST', $login, [], ['Authorization' => 'one', 'AUTHORIZATION' => 'two']];
        yield 'header-array' => ['POST', $login, [], ['Authorization' => ['one']]];
        yield 'POST-approval' => ['POST', 'https://graph.facebook.com/check_approved_machine', [], []];
        yield 'POST-bgraph-approval' => ['POST', 'https://b-graph.facebook.com/check_approved_machine', [], []];
        yield 'PUT-approval' => ['PUT', 'https://graph.facebook.com/check_approved_machine', [], []];
        yield 'lowercase-approval' => ['get', 'https://graph.facebook.com/check_approved_machine', [], []];
        yield 'GET-approval-nested-form' => ['GET', 'https://graph.facebook.com/check_approved_machine', ['m' => ['secret']], []];
        yield 'GET-approval-cookie' => ['GET', 'https://graph.facebook.com/check_approved_machine', [], ['Cookie' => 'synthetic=secret']];
        yield 'GET-approval-override' => ['GET', 'https://graph.facebook.com/check_approved_machine', ['method' => 'POST'], []];
        yield 'GET-approval-uppercase-override' => ['GET', 'https://graph.facebook.com/check_approved_machine', ['METHOD' => 'POST'], []];
        yield 'GET-sms' => ['GET', 'https://graph.facebook.com/123/twofacsms', [], []];
        yield 'GET-bgraph-sms' => ['GET', 'https://b-graph.facebook.com/123/twofacsms', [], []];
        yield 'PUT-sms' => ['PUT', 'https://graph.facebook.com/123/twofacsms', [], []];
        yield 'lowercase-sms' => ['post', 'https://graph.facebook.com/123/twofacsms', [], []];
        yield 'POST-sms-override' => ['POST', 'https://graph.facebook.com/123/twofacsms', ['method' => 'GET'], []];
        yield 'POST-sms-uppercase-override' => ['POST', 'https://graph.facebook.com/123/twofacsms', ['METHOD' => 'GET'], []];
        yield 'POST-sms-cookie' => ['POST', 'https://graph.facebook.com/123/twofacsms', [], ['Cookie' => 'synthetic=secret']];
        yield 'POST-native-identity' => ['POST', 'https://graph.facebook.com/me', [], []];
        yield 'POST-bgraph-native-identity' => ['POST', 'https://b-graph.facebook.com/me', [], []];
        yield 'PUT-native-identity' => ['PUT', 'https://graph.facebook.com/me', [], []];
        yield 'GET-native-identity-method-override' => ['GET', 'https://graph.facebook.com/me', ['method' => 'POST'], []];
        yield 'GET-native-identity-uppercase-method-override' => ['GET', 'https://graph.facebook.com/me', ['METHOD' => 'GET'], []];
        yield 'GET-native-identity-token-query' => ['GET', 'https://graph.facebook.com/me', ['access_token' => 'SYNTHETIC_SECRET'], []];
        yield 'GET-native-identity-fields-query' => ['GET', 'https://graph.facebook.com/me', ['fields' => 'id'], []];
        yield 'GET-native-identity-cookie' => ['GET', 'https://graph.facebook.com/me', [], ['Cookie' => 'synthetic=secret']];
    }

    /** @param array<mixed> $form
     * @param array<mixed> $headers */
    #[DataProvider('invalidRequests')]
    public function testRejectsInvalidMethodsFormsAndHeadersBeforeIO(string $method, string $url, array $form, array $headers): void
    {
        $this->expectException(\InvalidArgumentException::class);
        (new AndroidLoginCurlTransport(0.001))->request($method, $url, $form, $headers);
    }

    /** @return iterable<array{float,int,int}> */
    public static function invalidLimits(): iterable
    {
        yield [0.0, 1, 1]; yield [-1.0, 1, 1]; yield [INF, 1, 1]; yield [NAN, 1, 1];
        yield [120.01, 1, 1]; yield [1.0, 0, 1]; yield [1.0, 2097153, 1];
        yield [1.0, 1, 0]; yield [1.0, 1, 65537];
    }

    #[DataProvider('invalidLimits')]
    public function testHardLimitsCannotBeRaised(float $timeout, int $body, int $headers): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new AndroidLoginCurlTransport($timeout, $body, $headers);
    }

    public function testHTTPSPasswordKeyFetchUsesPostWithGetFormMethod(): void
    {
        [$result, $received] = $this->lab('ok', ['method' => 'POST', 'url' => 'https://b-graph.facebook.com/pwd_key_fetch',
            'form' => ['method' => 'GET', 'flow' => 'test + &', 'version' => '2'], 'headers' => ['aUtHoRiZaTiOn' => 'OAuth SYNTHETIC', 'X-FB-Friendly-Name' => 'SyntheticLogin']]);
        self::assertTrue($result['ok'], json_encode([$result, $received]));
        self::assertSame(200, $result['responses'][0]['status']);
        self::assertSame('first', $result['responses'][0]['first_case']);
        self::assertSame('POST /pwd_key_fetch HTTP/1.1', $received[0]['request']);
        self::assertSame('method=GET&flow=test%20%2B%20%26&version=2', $received[0]['body']);
        self::assertSame('application/json', $received[0]['headers']['accept']);
        self::assertSame('MVO-Messenger-PHP/0.1', $received[0]['headers']['user-agent']);
        self::assertSame('OAuth SYNTHETIC', $received[0]['headers']['authorization']);
        self::assertSame('SyntheticLogin', $received[0]['headers']['x-fb-friendly-name']);
    }

    public function testHTTPSPostUsesRfc3986WithoutReusingCookies(): void
    {
        [$result, $received] = $this->lab('ok', ['method' => 'POST', 'url' => 'https://graph.facebook.com/auth/login', 'count' => 2,
            'form' => ['password' => 'SYNTHETIC + &', 'email' => 'test@example.test'],
            'headers' => ['User-Agent' => 'Synthetic-Client/1.0', 'Accept-Language' => 'pt-BR']]);
        self::assertTrue($result['ok']); self::assertCount(2, $received);
        foreach ($received as $request) {
            self::assertSame('POST /auth/login HTTP/1.1', $request['request']);
            self::assertSame('password=SYNTHETIC%20%2B%20%26&email=test%40example.test', $request['body']);
            self::assertSame('application/x-www-form-urlencoded', $request['headers']['content-type']);
            self::assertSame('Synthetic-Client/1.0', $request['headers']['user-agent']);
            self::assertArrayNotHasKey('cookie', $request['headers']);
        }
    }

    /** @return iterable<array{string}> */
    public static function approvalOrigins(): iterable
    {
        yield ['graph.facebook.com'];yield ['b-graph.facebook.com'];
    }

    #[DataProvider('approvalOrigins')]
    public function testHTTPSApprovalUsesActualGetWithInternalRfc3986QueryAndNoBodyOrCookies(string $host): void
    {
        [$result,$received]=$this->lab('ok',['method'=>'GET','url'=>'https://'.$host.'/check_approved_machine','count'=>2,
            'form'=>['u'=>'123456789','m'=>'MACHINE + / &=?'],'headers'=>['Authorization'=>'OAuth SYNTHETIC']]);
        self::assertTrue($result['ok']);self::assertCount(2,$received);
        foreach($received as$request){
            self::assertSame('GET /check_approved_machine?u=123456789&m=MACHINE%20%2B%20%2F%20%26%3D%3F HTTP/1.1',$request['request']);
            self::assertSame('',$request['body']);self::assertArrayNotHasKey('content-length',$request['headers']);
            self::assertArrayNotHasKey('cookie',$request['headers']);
            self::assertSame('OAuth SYNTHETIC',$request['headers']['authorization']);
        }
    }

    public function testApprovalGetDoesNotFollowRedirectOrRetry(): void
    {
        [$result,$received]=$this->lab('redirect',['method'=>'GET','url'=>'https://graph.facebook.com/check_approved_machine','form'=>['u'=>'123','m'=>'SYNTHETIC_MACHINE']]);
        self::assertTrue($result['ok']);self::assertCount(1,$received);self::assertSame(302,$result['responses'][0]['status']);
    }

    #[DataProvider('approvalOrigins')]
    public function testHTTPSSmsUsesCanonicalPostWithPrivateFirstFactorAndNoCookieReuse(string $host): void
    {
        [$result,$received]=$this->lab('ok',['method'=>'POST','url'=>'https://'.$host.'/1234567890123456789012345/twofacsms','count'=>2,
            'form'=>['first_factor'=>'SYNTHETIC + / &=?','access_token'=>'123|SYNTHETIC_CLIENT'],
            'headers'=>['X-FB-Friendly-Name'=>'login_approval_resend_code']]);
        self::assertTrue($result['ok']);self::assertCount(2,$received);
        foreach($received as$request){
            self::assertSame('POST /1234567890123456789012345/twofacsms HTTP/1.1',$request['request']);
            self::assertSame('first_factor=SYNTHETIC%20%2B%20%2F%20%26%3D%3F&access_token=123%7CSYNTHETIC_CLIENT',$request['body']);
            self::assertSame('application/x-www-form-urlencoded',$request['headers']['content-type']);
            self::assertSame('MVO-Messenger-PHP/0.1',$request['headers']['user-agent']);
            self::assertSame('login_approval_resend_code',$request['headers']['x-fb-friendly-name']);
            self::assertArrayNotHasKey('cookie',$request['headers']);
        }
    }

    public function testSmsPostDoesNotFollowRedirectOrRetry(): void
    {
        [$result,$received]=$this->lab('redirect',['method'=>'POST','url'=>'https://graph.facebook.com/123/twofacsms',
            'form'=>['first_factor'=>'SYNTHETIC_FIRST_FACTOR']]);
        self::assertTrue($result['ok']);self::assertCount(1,$received);self::assertSame(302,$result['responses'][0]['status']);
    }

    #[DataProvider('approvalOrigins')]
    public function testHTTPSNativeIdentityUsesQueryFreeGetWithPrivateUserAuthorization(string $host): void
    {
        [$result,$received]=$this->lab('ok',['method'=>'GET','url'=>'https://'.$host.'/me','count'=>2,
            'headers'=>['Authorization'=>'OAuth SYNTHETIC_USER_TOKEN','X-FB-Friendly-Name'=>'getMeUser']]);
        self::assertTrue($result['ok']);self::assertCount(2,$received);
        foreach($received as$request){
            self::assertSame('GET /me HTTP/1.1',$request['request']);
            self::assertSame('',$request['body']);self::assertArrayNotHasKey('content-length',$request['headers']);
            self::assertSame('OAuth SYNTHETIC_USER_TOKEN',$request['headers']['authorization']);
            self::assertSame('getMeUser',$request['headers']['x-fb-friendly-name']);
            self::assertArrayNotHasKey('cookie',$request['headers']);
        }
    }

    public function testNativeIdentityGetDoesNotFollowRedirectOrRetry(): void
    {
        [$result,$received]=$this->lab('redirect',['method'=>'GET','url'=>'https://graph.facebook.com/me',
            'headers'=>['Authorization'=>'OAuth SYNTHETIC_USER_TOKEN','X-FB-Friendly-Name'=>'getMeUser']]);
        self::assertTrue($result['ok']);self::assertCount(1,$received);self::assertSame(302,$result['responses'][0]['status']);
    }

    public function testDoesNotFollowRedirectOrRetry(): void
    {
        [$result, $received] = $this->lab('redirect', ['method' => 'POST', 'url' => 'https://graph.facebook.com/auth/login']);
        self::assertTrue($result['ok']); self::assertCount(1, $received);
        self::assertSame(302, $result['responses'][0]['status']);
        self::assertSame('https://untrusted.example.test/steal', $result['responses'][0]['location']);
    }

    /** @return iterable<array{string,array<string,int>}> */
    public static function oversizedResponses(): iterable
    {
        yield ['body', ['max_body' => 1024]];
        yield ['compressed', ['max_body' => 1024]];
        yield ['headers', ['max_headers' => 1024]];
    }

    /** @param array<string,int> $limits */
    #[DataProvider('oversizedResponses')]
    public function testBoundsDecodedBodyAndHeaders(string $mode, array $limits): void
    {
        [$result] = $this->lab($mode, ['method' => 'POST', 'url' => 'https://graph.facebook.com/auth/login'] + $limits);
        self::assertFalse($result['ok']);
        self::assertSame('login_response_too_large', $result['reason']);
        self::assertStringNotContainsString(str_repeat('X', 30), $result['message']);
    }

    public function testReturnsHttpErrorPrivatelyForLoginParser(): void
    {
        [$result] = $this->lab('http-error', ['method' => 'POST', 'url' => 'https://graph.facebook.com/auth/login']);
        self::assertTrue($result['ok']);
        self::assertSame(401, $result['responses'][0]['status']);
        self::assertSame('{"error":"SYNTHETIC_PRIVATE_BODY"}', $result['responses'][0]['body']);
    }

    public function testRejectsTrustedCertificateForWrongHostname(): void
    {
        [$result, $received] = $this->lab('ok', ['method' => 'POST', 'url' => 'https://graph.facebook.com/auth/login'], 'wrong.example.test');
        self::assertFalse($result['ok']); self::assertSame('login_transport_failed', $result['reason']);
        self::assertSame([['tls_failed' => true]], $received);
        self::assertStringNotContainsString('graph.facebook.com', $result['message']);
    }

    public function testRejectsUntrustedCertificateForCorrectHostname(): void
    {
        [$result, $received] = $this->lab('ok', ['method' => 'POST', 'url' => 'https://graph.facebook.com/auth/login'], trusted: false);
        self::assertFalse($result['ok']); self::assertSame('login_transport_failed', $result['reason']);
        self::assertSame([['tls_failed' => true]], $received);
    }

    public function testExceptionsAndDebugOutputDoNotExposeRequestSecrets(): void
    {
        $previous = ini_set('zend.exception_ignore_args', '0');
        $transport = new AndroidLoginCurlTransport();
        try {
            $transport->request('POST', 'https://graph.facebook.com/auth/login', ['password' => 'SYNTHETIC_SECRET'], ['Authorization' => "SYNTHETIC_SECRET\r\nHost: example.test"]);
            self::fail('Invalid private request admitted');
        } catch (\InvalidArgumentException $error) {
            self::assertStringNotContainsString('SYNTHETIC_SECRET', (string) $error);
            self::assertStringNotContainsString('SYNTHETIC_SECRET', print_r($transport, true));
        } finally {
            ini_set('zend.exception_ignore_args', (string) $previous);
        }
    }

    /** @param array<string,mixed> $input
     * @return array{array<string,mixed>,list<array<string,mixed>>} */
    private function lab(string $mode, array $input, string $hostname = 'graph.facebook.com', bool $trusted = true): array
    {
        if (!function_exists('proc_open') || !extension_loaded('openssl') || !extension_loaded('curl')) self::markTestSkipped('Local HTTPS fixtures require proc_open, OpenSSL and cURL.');
        $directory = sys_get_temp_dir() . '/mvo-android-http-' . bin2hex(random_bytes(8));
        mkdir($directory, 0700);
        $server = null; $serverPipes = [];
        try {
            $config = "[req]\ndistinguished_name=dn\n[dn]\n[v3]\nsubjectAltName=DNS:$hostname" . ($hostname === 'graph.facebook.com' ? ',DNS:b-graph.facebook.com' : '') . "\nbasicConstraints=critical,CA:TRUE\nkeyUsage=critical,digitalSignature,keyEncipherment,keyCertSign\nextendedKeyUsage=serverAuth\n";
            file_put_contents($directory . '/openssl.cnf', $config);
            $options = ['config' => $directory . '/openssl.cnf', 'digest_alg' => 'sha256', 'private_key_bits' => 2048, 'x509_extensions' => 'v3'];
            $key = openssl_pkey_new($options); self::assertNotFalse($key);
            $csr = openssl_csr_new(['commonName' => $hostname], $key, $options); self::assertNotFalse($csr);
            $cert = openssl_csr_sign($csr, null, $key, 1, $options); self::assertNotFalse($cert);
            self::assertTrue(openssl_x509_export($cert, $certificate));
            self::assertTrue(openssl_pkey_export($key, $privateKey, null, $options));
            file_put_contents($directory . '/certificate.pem', $certificate);
            file_put_contents($directory . '/key.pem', $privateKey);
            $caFile = $directory . '/certificate.pem';
            if (!$trusted) {
                $untrustedKey = openssl_pkey_new($options); self::assertNotFalse($untrustedKey);
                $untrustedCsr = openssl_csr_new(['commonName' => 'Unrelated synthetic root'], $untrustedKey, $options); self::assertNotFalse($untrustedCsr);
                $untrustedCert = openssl_csr_sign($untrustedCsr, null, $untrustedKey, 1, $options); self::assertNotFalse($untrustedCert);
                self::assertTrue(openssl_x509_export($untrustedCert, $untrustedPem));
                $caFile = $directory . '/untrusted-root.pem';
                file_put_contents($caFile, $untrustedPem);
            }
            file_put_contents($directory . '/input.json', json_encode($input, JSON_THROW_ON_ERROR));
            $server = proc_open([PHP_BINARY, __DIR__ . '/fixtures/android-login-https-server.php', $mode, $directory, (string) ($input['count'] ?? 1)],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $serverPipes, options: ['bypass_shell' => true]);
            self::assertIsResource($server); fclose($serverPipes[0]);
            $deadline = microtime(true) + 5;
            while (!is_file($directory . '/ready.json') && microtime(true) < $deadline) usleep(10000);
            self::assertFileExists($directory . '/ready.json');
            $ready = json_decode(file_get_contents($directory . '/ready.json'), true, 8, JSON_THROW_ON_ERROR);
            $environment = getenv();
            self::assertIsArray($environment);
            foreach (['HTTPS_PROXY', 'https_proxy', 'HTTP_PROXY', 'http_proxy', 'ALL_PROXY', 'all_proxy'] as $name) $environment[$name] = 'http://' . $ready['address'];
            $environment['NO_PROXY'] = ''; $environment['no_proxy'] = '';
            $process = proc_open([PHP_BINARY, '-d', 'curl.cainfo=' . $caFile, __DIR__ . '/fixtures/android-login-https-client.php', $directory . '/input.json'],
                [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, env_vars: $environment, options: ['bypass_shell' => true]);
            self::assertIsResource($process); fclose($pipes[0]);
            $stdout = stream_get_contents($pipes[1]); $stderr = stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]);
            self::assertSame(0, proc_close($process), $stderr);
            $result = json_decode($stdout, true, 16, JSON_THROW_ON_ERROR);
            $serverError = stream_get_contents($serverPipes[2]);
            fclose($serverPipes[1]); fclose($serverPipes[2]); $serverPipes = [];
            self::assertSame(0, proc_close($server), $serverError); $server = null;
            $received = json_decode(file_get_contents($directory . '/received.json'), true, 16, JSON_THROW_ON_ERROR);
            return [$result, $received];
        } finally {
            foreach ($serverPipes as $pipe) if (is_resource($pipe)) fclose($pipe);
            if (is_resource($server)) { proc_terminate($server); proc_close($server); }
            foreach (glob($directory . '/*') ?: [] as $path) if (is_file($path)) unlink($path);
            rmdir($directory);
        }
    }
}
