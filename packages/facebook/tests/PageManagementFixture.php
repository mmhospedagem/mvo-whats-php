<?php
declare(strict_types=1);
namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\PageManagementDocuments;
use MVO\Facebook\Protocol\WebGraphQL;
use MVO\Facebook\Storage\CommandLedger;

require_once __DIR__.'/PageServiceFixture.php';

/** Synthetic payloads only; no credentials or connected account are read. */
final class PageManagementFixture
{
    public readonly PageFakeSession $session;
    public ?CommandLedger $ledger;
    public ?WebGraphQL $graph;
    private string $directory;
    /** @param array<string,mixed> $data */
    public function __construct(array $data=[],bool $fail=false)
    {
        $this->directory=sys_get_temp_dir().'/mvo-page-management-'.bin2hex(random_bytes(8));mkdir($this->directory,0700);
        $this->session=new PageFakeSession($data,$fail);
        $this->ledger=new CommandLedger($this->directory.'/commands.sqlite');
        $this->graph=new WebGraphQL($this->session,PageManagementDocuments::registry(),$this->ledger);
    }
    public function close():void
    {
        $this->graph=null;$this->ledger?->close();$this->ledger=null;gc_collect_cycles();
        foreach(glob($this->directory.'/commands.sqlite*')?:[] as $path)unlink($path);
        rmdir($this->directory);
    }
}
