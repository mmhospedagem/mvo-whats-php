<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\WaClient;
use PHPUnit\Framework\TestCase;

final class WaClientBusinessAgentTest extends TestCase
{
    private string $directory;
    private WaClient $client;

    protected function setUp():void
    {
        $this->directory=sys_get_temp_dir().'/mvo-agent-facade-'.bin2hex(random_bytes(8));
        $this->client=new WaClient('fixture',$this->directory,automaticMaintenance:false);
        $credentials=CredentialStore::fresh();
        $credentials['registered']=true;
        $credentials['meJid']='5511000000000:5@s.whatsapp.net';
        foreach (['state'=>'connected','running'=>true,'credentials'=>$credentials] as $name=>$value)
            (new \ReflectionProperty(WaClient::class,$name))->setValue($this->client,$value);
    }
    protected function tearDown():void
    {
        $this->client->disconnect();unset($this->client);gc_collect_cycles();
        $root=realpath($this->directory);
        if ($root===false) return;
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root,\FilesystemIterator::SKIP_DOTS),\RecursiveIteratorIterator::CHILD_FIRST) as $file) {
            self::assertStringStartsWith(str_replace('\\','/',$root).'/',str_replace('\\','/',$file->getPathname()));
            if ($file->isDir() && !$file->isLink()) rmdir($file->getPathname());
            else unlink($file->getPathname());
        }
        rmdir($root);
    }
    public function testGuardWithdrawalBlocksAuthenticationOnAnExistingFacade():void
    {
        $agent=$this->client->businessAgent();
        $this->client->setBusinessAgentGuard(static function():void{throw new \LogicException('Authorization withdrawn');});
        try { $agent->run('status'); self::fail('An expired authorization reached Business HTTP'); }
        catch (\LogicException $error) { self::assertSame('Authorization withdrawn',$error->getMessage()); }
        $auth=(new \ReflectionProperty(WaClient::class,'businessFormsAuthentication'))->getValue($this->client);
        self::assertSame('idle',$auth->status()['state']);
    }
    public function testDisconnectExpiresTheFacadeAndDropsTheBusinessToken():void
    {
        $agent=$this->client->businessAgent();
        $auth=(new \ReflectionProperty(WaClient::class,'businessFormsAuthentication'))->getValue($this->client);
        (new \ReflectionProperty($auth,'accessToken'))->setValue($auth,'test-only-secret');
        $this->client->disconnect();
        self::assertNull((new \ReflectionProperty($auth,'accessToken'))->getValue($auth));
        try { $agent->run('status'); self::fail('An old facade attempted a new request'); }
        catch (\LogicException $error) { self::assertStringContainsString('expired or disconnected',$error->getMessage()); }
    }
}
