<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Message\{MessageCodec,MessageService};
use MVO\Protocol\{BinaryNode,Jid};
use MVO\Service\{RecipientResolver,RecipientResolutionException,Services,ServiceException};
use MVO\Signal\{SignalProtocol,SignalStore};
use MVO\Transport\NodeSession;
use MVO\WaClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UsernameRecipientTest extends TestCase
{
    private array $queries=[];
    private array $responses=[];
    private array $clients=[];
    private array $stores=[];
    private array $directories=[];

    protected function tearDown():void
    {
        foreach ($this->clients as $client) $client->disconnect();
        foreach ($this->stores as $store) $store->close();
        $this->clients=[]; $this->stores=[]; gc_collect_cycles();
        foreach ($this->directories as $directory) {
            $files=new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($directory);
        }
    }

    private function resolver():RecipientResolver
    {
        $services=new Services(function(BinaryNode $node):BinaryNode {
            $this->queries[]=$node;
            $response=array_shift($this->responses);
            if ($response instanceof \Throwable) throw $response;
            return $response ?? new BinaryNode('iq',['type'=>'result']);
        },static function(BinaryNode $node):void {},static fn():array=>[]);
        return new RecipientResolver($services->profile());
    }

    private static function lookup(array $users):BinaryNode
    {
        return new BinaryNode('iq',['type'=>'result'],[new BinaryNode('usync',[],[new BinaryNode('list',[],$users)])]);
    }

    private static function user(string $jid='8@lid',array $contact=['type'=>'in','username'=>'cliente.exemplo']):BinaryNode
    {
        return new BinaryNode('user',['jid'=>$jid],[new BinaryNode('contact',$contact)]);
    }

    public static function validRecipients():iterable
    {
        yield ['cliente.exemplo','cliente.exemplo',true];
        yield [' @cliente.exemplo ','cliente.exemplo',true];
        yield ['@cliente.exemplo:1234','cliente.exemplo:1234',true];
        yield ['+55 (11) 90000-0001','5511900000001@s.whatsapp.net',false];
        yield ['120363000-123','120363000-123@g.us',false];
        yield ['8@lid','8@lid',false];
        yield ['8:7@lid','8:7@lid',false];
        yield ['8:99@hosted.lid','8:99@lid',false];
        yield ['5584:99@hosted','5584:99@s.whatsapp.net',false];
        yield ['120363000@g.us','120363000@g.us',false];
        yield ['123@newsletter','123@newsletter',false];
        yield ['status@broadcast','status@broadcast',false];
    }

    #[DataProvider('validRecipients')]
    public function testPureRecipientValidationDoesNotInventUsernameJids(string $input,string $expected,bool $username):void
    {
        self::assertSame($expected,RecipientResolver::normalize($input));
        self::assertSame($username,RecipientResolver::isUsername($input));
        if ($username) {
            self::assertStringNotContainsString('@',$expected);
            try { Jid::recipient($input); self::fail('Pure JID parser must not resolve usernames'); }
            catch (\InvalidArgumentException) { self::assertTrue(true); }
        } else {
            self::assertSame($expected,$this->resolver()->resolve($input)); self::assertCount(0,$this->queries);
        }
    }

    public static function invalidRecipients():iterable
    {
        foreach (['','@@cliente.exemplo','maik..venancio','cliente.exemplo:123','cliente.exemplo:12345','cliente.exemplo:1234:5678','cliente.exemplo:abcd','www.maik','someone.com','somewhatsapp','maik venancio','.maik','maik.','ab',str_repeat('a',36),'màik','maik@example.com','maik@evil.invalid'] as $input) yield [$input];
    }

    #[DataProvider('invalidRecipients')]
    public function testInvalidInputNeverTriggersLookup(string $input):void
    {
        try { $this->resolver()->resolve($input); self::fail('Accepted invalid recipient'); }
        catch (\InvalidArgumentException $error) { self::assertStringNotContainsString('1234:5678',$error->getMessage()); }
        self::assertCount(0,$this->queries);
    }

    public function testUsernameAndOptionalPinUseActualContactUsyncAndFreshLookup():void
    {
        $this->responses=[self::lookup([self::user()]),self::lookup([self::user('9@lid')])];
        $resolver=$this->resolver();
        self::assertSame('8@lid',$resolver->resolve('@cliente.exemplo:1234'));
        self::assertSame('9@lid',$resolver->resolve('cliente.exemplo:1234'));
        self::assertCount(2,$this->queries,'Do not retain mappings when a username changes owner');
        foreach ($this->queries as $node) {
            self::assertSame('usync',$node->attrs['xmlns']);
            $sync=$node->child('usync');
            self::assertSame(['addressing_mode'=>'lid'],$sync->child('query')->child('contact')->attrs);
            self::assertSame(['username'=>'cliente.exemplo','pin'=>'1234'],$sync->child('list')->child('user')->child('contact')->attrs);
            self::assertSame([],$sync->child('list')->child('user')->attrs);
        }
    }

    public static function unsuccessfulLookups():iterable
    {
        yield 'no user'=>[[],'not-found'];
        yield 'unregistered'=>[[self::user('8@lid',['type'=>'out'])],'not-found'];
        yield 'contact error'=>[[new BinaryNode('user',[],[new BinaryNode('contact',[],[new BinaryNode('error',['code'=>'404'])])])],'not-found'];
        yield 'needs key'=>[[new BinaryNode('user',[],[new BinaryNode('contact',['type'=>'in','username'=>'cliente.exemplo'])])],'key-required'];
        yield 'unknown server'=>[[self::user('8@evil.invalid')],'invalid-result'];
        yield 'synthetic alpha jid'=>[[self::user('cliente.exemplo@lid')],'invalid-result'];
        yield 'device'=>[[self::user('8:7@lid')],'invalid-result'];
        yield 'group'=>[[self::user('8@g.us')],'invalid-result'];
        yield 'mismatched echo'=>[[self::user('8@lid',['type'=>'in','username'=>'someone.else'])],'invalid-result'];
        yield 'unknown contact state'=>[[self::user('8@lid',['type'=>'unknown'])],'invalid-result'];
        yield 'missing contact'=>[[new BinaryNode('user',['jid'=>'8@lid'])],'invalid-result'];
        yield 'user error'=>[[new BinaryNode('user',['jid'=>'8@lid'],[new BinaryNode('contact',['type'=>'in']),new BinaryNode('error',['code'=>'403'])])],'invalid-result'];
        yield 'ambiguous'=>[[self::user(),self::user('9@lid')],'invalid-result'];
    }

    #[DataProvider('unsuccessfulLookups')]
    public function testLookupFailuresRemainTypedAndExcludeContactKey(array $users,string $status):void
    {
        $this->responses=[self::lookup($users)];
        try { $this->resolver()->resolve('cliente.exemplo:4321'); self::fail('Accepted unavailable username'); }
        catch (RecipientResolutionException $error) {
            self::assertSame($status,$error->status);
            self::assertStringNotContainsString('4321',$error->getMessage());
            self::assertStringNotContainsString('cliente.exemplo',$error->getMessage());
        }
        self::assertCount(1,$this->queries);
    }

    public function testServerMayOmitUsernameEchoAndMayCanonicalizeCaseOrHostedServer():void
    {
        $this->responses=[self::lookup([self::user('8@hosted.lid',['type'=>'in'])]),self::lookup([self::user('5584@s.whatsapp.net',['type'=>'in','username'=>'CLIENTE.EXEMPLO'])])];
        $resolver=$this->resolver();
        self::assertSame('8@lid',$resolver->resolve('cliente.exemplo'));
        self::assertSame('5584@s.whatsapp.net',$resolver->resolve('cliente.exemplo'));
    }

    public function testTransportRefusalNeverFallsBackToFabricatedJid():void
    {
        $this->responses=[new BinaryNode('iq',['type'=>'error'],[new BinaryNode('error',['code'=>'403'])])];
        try { $this->resolver()->resolve('cliente.exemplo'); self::fail('Accepted rejected lookup'); }
        catch (ServiceException $error) { self::assertSame('403',$error->serverCode); }
        self::assertCount(1,$this->queries);
    }

    private function directory():string
    {
        $directory=sys_get_temp_dir().'/mvo-username-'.bin2hex(random_bytes(8)); mkdir($directory,0700);
        $this->directories[]=$directory; return $directory;
    }

    private function connected(UsernameNodeSession $session):WaClient
    {
        $directory=$this->directory(); $credentials=CredentialStore::fresh();
        $credentials['registered']=true; $credentials['meJid']='5511000000000:5@s.whatsapp.net';
        $credentials['meLid']='700:5@lid'; $credentials['serverHasPreKeys']=true;
        $credentials['signedIdentity']=['details'=>'fixture','accountSignatureKey'=>random_bytes(32),'accountSignature'=>random_bytes(64),'deviceSignature'=>random_bytes(64)];
        $store=new CredentialStore($directory); $store->save($credentials); $store->close();
        $client=new WaClient(authDirectory:$directory,sessionFactory:fn():NodeSession=>$session,automaticMaintenance:false);
        $this->clients[]=$client; $client->connect(); $session->incoming[]=new BinaryNode('success',['lid'=>'700:5@lid']); $client->poll(0);
        self::assertTrue($client->isConnected()); return $client;
    }

    public function testNativeClientAndMessageServiceBothEncryptToTheResolvedAccountWithoutDuplicateLookup():void
    {
        $session=new UsernameNodeSession(); $session->usernameUsers=[self::user('800@lid')];
        $peerCredentials=CredentialStore::fresh(); $peerStore=new SignalStore($this->directory().'/peer.sqlite'); $this->stores[]=$peerStore;
        $peerSignal=new SignalProtocol($peerStore,$peerCredentials); $key=$peerStore->generatePreKeys(1)[0]; $signed=$peerCredentials['signedPreKey'];
        $session->bundle=new BinaryNode('user',['jid'=>'800@lid'],[
            new BinaryNode('registration',[],pack('N',$peerCredentials['registrationId'])),new BinaryNode('identity',[],$peerCredentials['identityKeyPair']['public']),
            new BinaryNode('skey',[],[new BinaryNode('id',[],substr(pack('N',$signed['id']),1)),new BinaryNode('value',[],$signed['public']),new BinaryNode('signature',[],$signed['signature'])]),
            new BinaryNode('key',[],[new BinaryNode('id',[],substr(pack('N',$key['id']),1)),new BinaryNode('value',[],$key['public'])]),
        ]);
        $texts=[];
        $session->onMessage=static function(BinaryNode $node) use($peerSignal,&$texts):void {
            self::assertSame('800@lid',$node->attrs['to']);
            $targets=$node->child('participants')->content;
            self::assertCount(1,$targets); self::assertSame('800@lid',$targets[0]->attrs['jid']);
            $enc=$targets[0]->child('enc');
            $texts[]=MessageCodec::decode(MessageCodec::unpad($peerSignal->decrypt('700:5@lid',$enc->attrs['type'],$enc->content)))['text'];
        };
        $client=$this->connected($session);
        self::assertSame('800@lid',$client->sendText('@cliente.exemplo','Primeira')['to']);
        self::assertSame(1,$session->usernameLookups);
        self::assertSame('800@lid',$client->message()->sendMessage('cliente.exemplo:1234','Segunda')['to']);
        self::assertSame(2,$session->usernameLookups);
        self::assertSame(['Primeira','Segunda'],$texts);
        self::assertSame('8:7@lid',$client->resolveRecipient('8:7@lid')); self::assertSame(2,$session->usernameLookups);
    }

    public function testNativeLookupFailureCannotPublishOrDeleteSession():void
    {
        $session=new UsernameNodeSession(); $client=$this->connected($session);
        foreach ([$client->sendMessage(...),$client->message()->sendMessage(...)] as $send) {
            try { $send('cliente.exemplo:4321','Não enviar'); self::fail('Sent to missing username'); }
            catch (RecipientResolutionException $error) { self::assertSame('not-found',$error->status); self::assertStringNotContainsString('4321',$error->getMessage()); }
        }
        self::assertSame(0,$session->published); self::assertTrue($client->isRegistered()); self::assertTrue($client->isConnected());
        self::assertSame(2,$session->usernameLookups);
    }

    public static function invalidResolverJids():iterable
    {
        foreach (['cliente.exemplo','cliente.exemplo@lid','8@g.us','8:7@lid','8@evil.invalid'] as $jid) yield [$jid];
    }

    #[DataProvider('invalidResolverJids')]
    public function testCustomMessageResolverCannotRouteUsernameToInvalidAccount(string $resolved):void
    {
        $credentials=CredentialStore::fresh(); $credentials['registered']=true; $credentials['meJid']='5511000000000:5@s.whatsapp.net';
        $store=new SignalStore($this->directory().'/sender.sqlite'); $this->stores[]=$store;
        $signal=new SignalProtocol($store,$credentials); $lookups=0; $queries=0;
        $service=new MessageService($signal,$store,static fn():array=>$credentials,static function(array $credentials):void {},
            static function(BinaryNode $node) use(&$queries):BinaryNode { ++$queries; return new BinaryNode('iq',['type'=>'result']); },
            static function(BinaryNode $node):void { self::fail('Unexpected message publication'); },static function(string $event,array $payload):void {},
            usernameResolver:static function(string $input) use($resolved,&$lookups):string { ++$lookups; return $resolved; });
        try { $service->sendText('cliente.exemplo','Não enviar'); self::fail('Accepted malformed account from resolver'); }
        catch (RecipientResolutionException $error) { self::assertSame('invalid-result',$error->status); }
        self::assertSame(1,$lookups); self::assertSame(0,$queries);
    }
}

final class UsernameNodeSession implements NodeSession
{
    public array $incoming=[];
    public array $usernameUsers=[];
    public int $usernameLookups=0;
    public int $published=0;
    public ?BinaryNode $bundle=null;
    public ?\Closure $onMessage=null;
    public function start(string $staticPrivate,string $staticPublic,string $clientPayload):string { return str_repeat('s',32); }
    public function sendNode(BinaryNode $node):void
    {
        if ($node->tag==='message') {
            ++$this->published; if ($this->onMessage!==null) ($this->onMessage)($node);
            $this->incoming[]=new BinaryNode('ack',['class'=>'message','id'=>$node->attrs['id']]); return;
        }
        if ($node->tag!=='iq' || !in_array($node->attrs['type'] ?? '',['get','set'],true)) return;
        $children=[];
        if (($node->attrs['xmlns'] ?? '')==='usync') {
            $sync=$node->child('usync');
            if ($sync?->child('list')?->child('user')?->child('contact')?->attrs['username'] ?? false) {
                ++$this->usernameLookups; $users=$this->usernameUsers;
            } else {
                $users=[];
                foreach ($sync?->child('list')?->content ?? [] as $user) {
                    $id=($user->attrs['jid'] ?? '')==='700@lid' ? '5' : '0';
                    $users[]=new BinaryNode('user',$user->attrs,[new BinaryNode('devices',[],[new BinaryNode('device-list',[],[new BinaryNode('device',['id'=>$id])])])]);
                }
            }
            $children=[new BinaryNode('usync',[],[new BinaryNode('list',[],$users)])];
        } elseif (($node->attrs['xmlns'] ?? '')==='encrypt' && $node->child('key')!==null && $this->bundle!==null) {
            $children=[new BinaryNode('list',[],[$this->bundle])];
        }
        $this->incoming[]=new BinaryNode('iq',['type'=>'result','id'=>$node->attrs['id']],$children);
    }
    public function receiveNode(float $timeoutSeconds=1):?BinaryNode { return array_shift($this->incoming); }
    public function close():void {}
}
