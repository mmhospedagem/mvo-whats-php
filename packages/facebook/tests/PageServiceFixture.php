<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\Catalog\PageDocuments;
use MVO\Facebook\Protocol\WebGraphQL;
use MVO\Facebook\Storage\CommandLedger;
use MVO\Messenger\Auth\WebSession;
use MVO\Messenger\Transport\HttpResponse;

final class PageFakeSession implements WebSession
{
    /** @var list<array{method:string,url:string,form:array<string,string>}> */
    public array $requests = [];
    /** @param array<string,mixed> $data */
    public function __construct(public array $data = [], public bool $fail = false) {}
    public function accountId(): string { return '10001'; }
    public function privateRequestFields(): array { return ['__user' => '10001', 'fb_dtsg' => 'SYNTHETIC_PRIVATE']; }
    public function request(string $method, string $url, array $form = []): HttpResponse
    {
        $this->requests[] = ['method' => $method, 'url' => $url, 'form' => $form];
        if ($this->fail) throw new \RuntimeException('SYNTHETIC_PRIVATE_TRANSPORT');
        return new HttpResponse(200, json_encode(['data' => $this->data], JSON_THROW_ON_ERROR));
    }
    /** @return array<string,mixed> */
    public function variables(int $index = 0): array
    {
        return json_decode($this->requests[$index]['form']['variables'], true, 64, JSON_THROW_ON_ERROR);
    }
}

final class PageServiceFixture
{
    public readonly PageFakeSession $session;
    public ?WebGraphQL $graph;
    private ?CommandLedger $ledger;
    private string $directory;
    /** @param array<string,mixed> $data */
    public function __construct(array $data = [], bool $fail = false)
    {
        $this->directory = sys_get_temp_dir().'/mvo-facebook-services-'.bin2hex(random_bytes(8));
        mkdir($this->directory, 0700);
        $this->session = new PageFakeSession($data, $fail);
        $this->ledger = new CommandLedger($this->directory.'/commands.sqlite');
        $this->graph = new WebGraphQL($this->session, PageDocuments::registry(), $this->ledger);
    }
    /** Synthetic explicit context only; these are NOT discovered account gate values.
     * @return array<string,mixed>
     */
    public static function providers(string $name): array
    {
        $values = [];
        foreach (PageDocuments::providers($name) as $provider) $values[$provider] = str_contains($provider, 'AutoTranslationType') ? 'ORIGINAL' : false;
        return $values;
    }
    /** @return array<string,mixed> */
    public static function composer(): array
    {
        return ['composer_entry_point' => 'SYNTHETIC_ENTRY', 'composer_source_surface' => 'SYNTHETIC_SURFACE',
            'composer_type' => 'profile', 'logging' => ['composer_session_id' => 'SYNTHETIC_SESSION'],
            'audience' => ['privacy' => ['base_state' => 'EVERYONE']], 'tracking' => []];
    }
    public function close(): void
    {
        $this->ledger?->close();
        $this->graph = null;
        $this->ledger = null;
        gc_collect_cycles();
        foreach (glob($this->directory.'/commands.sqlite*') ?: [] as $file) unlink($file);
        rmdir($this->directory);
    }
}
