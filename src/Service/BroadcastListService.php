<?php
declare(strict_types=1);
namespace MVO\Service;
use MVO\AppState\AppStateService;

/** WhatsApp Business list definitions synchronize through authenticated app-state patches. */
final class BroadcastListService
{
    /** @var \Closure():AppStateService */
    private \Closure $appState;
    /** @var \Closure(string,string|array<string,mixed>,list<string>,array<string,mixed>):array<string,mixed> */
    private \Closure $publisher;
    /** @param callable():AppStateService $appState
     * @param callable(string,string|array<string,mixed>,list<string>,array<string,mixed>):array<string,mixed> $publisher */
    public function __construct(callable $appState,callable $publisher) { $this->appState=\Closure::fromCallable($appState);$this->publisher=\Closure::fromCallable($publisher); }
    /** @param array<string,mixed> $input */
    public function setList(array $input): void { ($this->appState)()->setBroadcastList($input); }
    public function removeList(string $id): void { ($this->appState)()->removeBroadcastList($id); }
    /** @param string|array<string,mixed> $content
     * @param list<string> $recipients
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function send(string $listJid,string|array $content,array $recipients,array $options=[]): array
    {
        ServiceTransport::jid($listJid,'broadcast'); if($listJid==='status@broadcast') throw new \InvalidArgumentException('Use status service for status broadcasts');
        return ($this->publisher)($listJid,$content,$recipients,$options);
    }
}
