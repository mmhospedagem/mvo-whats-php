<?php
declare(strict_types=1);
namespace MVO\Console;

use MVO\Auth\QrCode;

/** Local terminal view; redraws only on QR rotation, updates countdown in place. */
final class PairingDisplay
{
    /** @var \Closure(string):void */
    private \Closure $write;
    private bool $active = false;
    private float $deadline = 0;
    private int $remaining = -1;
    private int $rotation = 0;
    private string $connectionState = 'pairing';

    /** @param callable(string):void $write */
    public function __construct(callable $write,private readonly bool $ansi = true) { $this->write=\Closure::fromCallable($write); }
    public function isActive(): bool { return $this->active; }
    public function showQr(string $payload,int $expiresIn,?float $now = null): void {
        if ($expiresIn<1) throw new \InvalidArgumentException('Invalid QR lifetime');
        $qr = QrCode::terminal($payload,$this->ansi);
        if (!$this->active && $this->ansi) ($this->write)("\033[?1049h\033[?25l");
        $this->active = true; $this->rotation++;
        $this->deadline = ($now ?? microtime(true))+$expiresIn;
        $this->remaining = $expiresIn;
        ($this->write)(($this->ansi ? "\033[2J\033[H" : '')."WhatsApp - escaneie o QR Code abaixo\n\n".$qr);
        ($this->write)($this->status()."\nWhatsApp > Aparelhos conectados > Conectar um aparelho\nO QR atualiza automaticamente. Ctrl+C encerra.\n");
    }
    public function tick(?float $now = null): void {
        if (!$this->active) return;
        $remaining=max(0,(int)ceil($this->deadline-($now ?? microtime(true))));
        if ($remaining===0) { $this->expired(); return; }
        if ($remaining!==$this->remaining) { $this->remaining=$remaining; $this->updateStatus(); }
    }
    public function state(string $state): void {
        $this->connectionState=$state;
        if ($this->active && !in_array($state,['connected','disconnected','reconnecting','stopped','logged_out'],true)) { $this->updateStatus(); return; }
        $this->close();
        ($this->write)('['.date('H:i:s').'] '.$state."\n");
    }
    public function paired(string $jid): void {
        $this->close();
        ($this->write)('Vinculo verificado: '.$jid.". Aguardando login autenticado...\n");
    }
    public function expired(): void {
        if (!$this->active) return;
        $this->close();
        ($this->write)("QR expirou. Aguardando a proxima referencia do servidor.\n");
    }
    private function status(): string { return 'QR #'.$this->rotation.' | '.$this->remaining.'s | '.$this->connectionState; }
    private function updateStatus(): void {
        // Footer has three newline-terminated lines. Relative movement remains correct after scrolling.
        if ($this->ansi) ($this->write)("\033[3A\r\033[2K".$this->status()."\033[3B\r");
    }
    public function close(): void {
        if ($this->active && $this->ansi) ($this->write)("\033[0m\033[?25h\033[?1049l");
        $this->active=false; $this->deadline=0;
    }
    public function __destruct() { $this->close(); }
}
