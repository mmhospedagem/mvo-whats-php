<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\PageAdministrationDocuments;
use MVO\Facebook\Catalog\PageManagementDocuments;
use MVO\Facebook\Protocol\WebGraphQL;
use MVO\Facebook\Storage\CommandLedger;
use MVO\Messenger\Auth\WebSession;
use MVO\Messenger\Transport\HttpResponse;

/** Synthetic named responses, not recorded private account traffic. */
final class PageAdministrationNativeSession implements WebSession
{
    /** @var list<array{method:string,url:string,form:array<string,string>}> */ public array $requests=[];
    /** @var array<string,array<string,mixed>> */ public array $responses=[];
    /** @var array<string,bool> */ public array $fail=[];
    public function accountId():string{return '10001';}
    public function privateRequestFields():array{return ['__user'=>'10001','fb_dtsg'=>'SYNTHETIC_CSRF'];}
    public function request(string $method,string $url,array $form=[]):HttpResponse
    {
        $this->requests[]=['method'=>$method,'url'=>$url,'form'=>$form];$name=$form['fb_api_req_friendly_name']??'';
        if($this->fail[$name]??false)throw new \RuntimeException('SYNTHETIC_TRANSPORT_ERROR');
        return new HttpResponse(200,json_encode(['data'=>$this->responses[$name]??[]],JSON_THROW_ON_ERROR));
    }
    /** @return list<array<string,mixed>> */
    public function variables(string $name):array
    {
        return array_values(array_map(static fn(array $request):array=>json_decode($request['form']['variables'],true,64,JSON_THROW_ON_ERROR),
            array_filter($this->requests,static fn(array $r):bool=>($r['form']['fb_api_req_friendly_name']??null)===$name)));
    }
}

final class PageAdministrationNativeFixture
{
    public readonly PageAdministrationNativeSession $session;
    public ?WebGraphQL $graph;
    public ?CommandLedger $ledger;
    private string $directory;
    public function __construct()
    {
        $this->directory=sys_get_temp_dir().'/mvo-native-administration-'.bin2hex(random_bytes(8));mkdir($this->directory,0700);
        $this->session=new PageAdministrationNativeSession();$this->ledger=new CommandLedger($this->directory.'/commands.sqlite');
        $registry=PageManagementDocuments::registry();foreach(PageAdministrationDocuments::documents()as $document)$registry->add($document);
        $this->graph=new WebGraphQL($this->session,$registry,$this->ledger);
        $this->session->responses['BizKitSettingsPageRolesContextProviderQuery']=['page'=>['id'=>'20002','if_viewer_can_manage_admins'=>['id'=>'20002']]];
        $this->session->responses['BizKitSettingsPageRolesReauthPasswordDialogMutation']=['page_pres_perm_management_reauth'=>[
            'reauth_is_successful'=>true,'auth_token'=>'SYNTHETIC_SECRET_AUTH_TOKEN']];
    }
    public function close():void
    {
        $this->graph=null;$this->ledger?->close();$this->ledger=null;gc_collect_cycles();
        foreach(glob($this->directory.'/commands.sqlite*')?:[]as $file)unlink($file);rmdir($this->directory);
    }
}
