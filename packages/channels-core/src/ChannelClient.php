<?php
declare(strict_types=1);

namespace MVO\Channels;

/** Minimal application contract. Identifiers and payloads remain native to each channel. */
interface ChannelClient
{
    public function channel(): string;

    public function provider(): string;

    public function capabilities(): Capabilities;

    /** Public, locally known account information; never credentials.
     * @return array<string, mixed>
     */
    public function accountInfo(): array;

    /** Native payloads are preserved. Do not publish credential-bearing authentication events.
     * @param callable(array<string, mixed>): void $handler
     */
    public function on(string $event, callable $handler): static;

    /** An operation result is not proof of delivery or display.
     * @return array<string, mixed>
     */
    public function sendText(string $to, string $text): array;
}
