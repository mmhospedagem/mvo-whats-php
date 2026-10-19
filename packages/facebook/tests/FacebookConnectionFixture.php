<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Messenger\Auth\CookieJar;
use MVO\Messenger\Protocol\DgwCodec;
use MVO\Messenger\Transport\{HttpClient,HttpResponse,Socket,WebLoginResponse,WebLoginTransport};
use PHPUnit\Framework\TestCase;

/** Synthetic peers only: native PHP codecs and login parsers remain under test. */
final class FacebookConnectionFixture
{
    public const ACCOUNT='12345678901234567';
    public const PAGE='20000000000000002';
    public const PASSWORD='SYNTHETIC_FACEBOOK_PRIVATE_PASSWORD';
    public const SESSION='SYNTHETIC_FACEBOOK_PRIVATE_XS';
    public const TOKEN='SYNTHETIC_FACEBOOK_PRIVATE_DTSG';
    public const CODE='012345';
    public const CHALLENGE='https://www.facebook.com/two_factor/?context=SYNTHETIC_PRIVATE_CHALLENGE';

    /** @return list<array<string,mixed>> */
    public static function cookies():array
    {
        return [['name'=>'c_user','value'=>self::ACCOUNT,'domain'=>'.facebook.com'],
            ['name'=>'xs','value'=>self::SESSION,'domain'=>'.facebook.com'],
            ['name'=>'datr','value'=>'SYNTHETIC_FACEBOOK_PRIVATE_DEVICE','domain'=>'.facebook.com']];
    }

    public static function identity():string
    {
        return '<script type="application/json">'.json_encode(['define'=>[['CurrentUserInitialData',[],['USER_ID'=>self::ACCOUNT],0]]],JSON_THROW_ON_ERROR).'</script>';
    }

    public static function loginPage():string
    {
        $publicKey=bin2hex(sodium_crypto_box_publickey(sodium_crypto_box_seed_keypair(str_repeat("\x07",32))));
        return '<form id="login_form" method="post" action="/login/password/"><input name="email"><input name="pass" type="password"></form>'
            .'<script type="application/json">'.json_encode(['require'=>[['MWLoginEncryptPasswordOnSubmit','init',[],[['keyId'=>7,'publicKey'=>$publicKey]]]]],JSON_THROW_ON_ERROR).'</script>';
    }

    public static function challengePage(string $mode):string
    {
        if($mode==='otp')return '<form method="post" action="/two_factor/confirm/"><input type="hidden" name="nh" value="SYNTHETIC_PRIVATE_NONCE"><input name="approvals_code" maxlength="6"></form>';
        if($mode!=='captcha')throw new \LogicException('Unknown synthetic login mode');
        return '<script type="application/json">'.json_encode(['payload'=>json_encode(['data'=>['xfb_two_factor_login_default_method'=>['method'=>['method'=>'RECAPTCHA']]]],JSON_THROW_ON_ERROR)],JSON_THROW_ON_ERROR).'</script>';
    }
}

final class FacebookConnectionLoginTransport implements WebLoginTransport
{
    /** @var list<array{method:string,url:string}> */ public array $requests=[];
    /** @var list<array<string,mixed>> */ private array $jar=[];
    public int $passwordPosts=0;
    public int $otpPosts=0;

    public function __construct(private readonly string $mode='direct'){}

    public function request(string $method,string $url,array $form=[]):WebLoginResponse
    {
        $this->requests[]=['method'=>$method,'url'=>$url];
        if($method==='GET'&&$url==='https://www.messenger.com/'){
            TestCase::assertSame([],$form);
            return new WebLoginResponse(200,FacebookConnectionFixture::loginPage());
        }
        if($method==='POST'&&$url==='https://www.messenger.com/login/password/'){
            ++$this->passwordPosts;TestCase::assertSame(1,$this->passwordPosts,'A password must not be reposted');
            TestCase::assertSame('person@example.invalid',$form['email']);
            TestCase::assertStringStartsWith('#PWD_BROWSER:5:',$form['pass']);
            TestCase::assertStringNotContainsString(FacebookConnectionFixture::PASSWORD,json_encode($form,JSON_THROW_ON_ERROR));
            if($this->mode!=='direct'){
                $this->jar=[['name'=>'datr','value'=>'SYNTHETIC_FACEBOOK_PENDING_DEVICE','domain'=>'.facebook.com']];
                return new WebLoginResponse(302,'',['location'=>[FacebookConnectionFixture::CHALLENGE]]);
            }
            $this->jar=FacebookConnectionFixture::cookies();
            return new WebLoginResponse(200,FacebookConnectionFixture::identity());
        }
        if($method==='GET'&&$url===FacebookConnectionFixture::CHALLENGE)return new WebLoginResponse(200,FacebookConnectionFixture::challengePage($this->mode));
        if($method==='POST'&&$url==='https://www.facebook.com/two_factor/confirm/'&&$this->mode==='otp'){
            ++$this->otpPosts;TestCase::assertSame(1,$this->otpPosts,'An OTP must not be reposted');
            TestCase::assertSame(FacebookConnectionFixture::CODE,$form['approvals_code']);
            TestCase::assertSame('SYNTHETIC_PRIVATE_NONCE',$form['nh']);
            $this->jar=FacebookConnectionFixture::cookies();
            return new WebLoginResponse(302,'',['location'=>['https://www.facebook.com/messages/']]);
        }
        if($method==='GET'&&$url==='https://www.facebook.com/messages/'&&$this->jar===FacebookConnectionFixture::cookies())return new WebLoginResponse(200,FacebookConnectionFixture::identity());
        throw new \LogicException('Unexpected synthetic authentication request');
    }
    public function cookies():array{return $this->jar;}
    public function clear():void{$this->jar=[];}
}

final class FacebookConnectionHttp implements HttpClient
{
    /** @var list<string> */ public array $urls=[];
    public bool $expired=false;

    public function request(string $method,string $url,CookieJar $cookies,array $form=[]):HttpResponse
    {
        TestCase::assertSame('GET',$method);TestCase::assertSame([],$form);
        TestCase::assertSame(FacebookConnectionFixture::ACCOUNT,$cookies->accountId());
        TestCase::assertStringContainsString('xs='.FacebookConnectionFixture::SESSION,$cookies->headerFor($url));
        $this->urls[]=$url;
        if($this->expired)return new HttpResponse(302,'SYNTHETIC_PRIVATE_EXPIRED_BODY',['location'=>['https://www.facebook.com/login/']]);
        if($url==='https://www.facebook.com/pages/?category=your_pages'){
            $data=['define'=>[['CurrentUserInitialData',[],['USER_ID'=>FacebookConnectionFixture::ACCOUNT],0]],
                '__bbox'=>['result'=>['data'=>['viewer'=>['admined_pages'=>['nodes'=>[['id'=>FacebookConnectionFixture::PAGE,'name'=>'Synthetic test page']]]]]]]];
            return new HttpResponse(200,'<script type="application/json">'.json_encode($data,JSON_THROW_ON_ERROR).'</script>');
        }
        TestCase::assertSame('https://www.facebook.com/messages/',$url);
        $modules=['CurrentUserInitialData'=>['USER_ID'=>FacebookConnectionFixture::ACCOUNT,'APP_ID'=>'987123','NAME'=>'Synthetic test account'],
            'DGWWebConfig'=>['appId'=>'456789','appVersion'=>'0'],'MqttWebDeviceID'=>['clientID'=>'synthetic-device'],
            'DTSGInitialData'=>['token'=>FacebookConnectionFixture::TOKEN],
            'LSVersion'=>'987654321','LSPlatformMessengerSyncParams'=>['mailbox'=>'{"group":"mailbox"}']];
        $definitions=[];foreach($modules as $name=>$value)$definitions[]=[$name,[],$value,0];
        return new HttpResponse(200,'<script type="application/json">'.json_encode(['require'=>[['ScheduledServerJS','handle',null,[['__bbox'=>['define'=>$definitions]]]]]],JSON_THROW_ON_ERROR).'</script>');
    }
}

/** The production DGW codec decodes requests and encodes synthetic sync responses. */
final class FacebookConnectionPeer implements Socket
{
    private bool $active=false;
    /** @var list<string> */ private array $incoming=[];
    /** @var list<array<string,mixed>> */ public array $syncRequests=[];

    public function connect():void{$this->active=true;}
    public function close():void{$this->active=false;$this->incoming=[];}
    public function isConnected():bool{return $this->active;}
    public function receive(float $timeoutSeconds=.5):?string{return array_shift($this->incoming);}
    public function send(string $payload):void
    {
        foreach(DgwCodec::decode($payload) as $frame){
            $stream=$frame['stream_id']??0;
            if($frame['type']===DgwCodec::ESTABLISH){$this->incoming[]=DgwCodec::encode(['type'=>DgwCodec::ESTABLISH,'stream_id'=>$stream,'parameters'=>200]);continue;}
            if($frame['type']!==DgwCodec::DATA)continue;
            $this->incoming[]=DgwCodec::encode(['type'=>DgwCodec::ACK,'stream_id'=>$stream,'ack_id'=>$frame['ack_id']]);
            $outer=json_decode($frame['payload'],true,64,JSON_THROW_ON_ERROR);
            TestCase::assertContains($outer['type'],[1,2],'A connection test must not issue message tasks');
            TestCase::assertSame('987123',$outer['app_id']);
            $request=json_decode($outer['payload'],true,64,JSON_THROW_ON_ERROR);$this->syncRequests[]=$request;
            $database=$request['database'];$epoch=(string)$request['epoch_id'];$cursor=$request['last_applied_cursor'];
            $calls=[[5,'LSExecuteFirstBlockForSyncTransactionV4',$database,$epoch,$cursor===null?[9]:$cursor,'synthetic-cursor-'.$database,0,1,false,0,false,1],
                [5,'LSExecuteFinallyBlockForSyncTransaction',true,$database,$epoch]];
            $response=['request_id'=>$outer['request_id'],'payload'=>json_encode(['step'=>[1,...$calls]],JSON_THROW_ON_ERROR),'sp'=>[]];
            $this->incoming[]=DgwCodec::encode(['type'=>DgwCodec::DATA,'stream_id'=>$stream,'ack_id'=>0,'requires_ack'=>true,'payload'=>json_encode($response,JSON_THROW_ON_ERROR)]);
        }
    }
}
