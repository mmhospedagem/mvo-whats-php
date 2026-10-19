<?php
declare(strict_types=1);

namespace MVO\Messenger\Transport;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\DgwCodec;

/**
 * DGW v5 stream multiplexer over an already configured binary WebSocket.
 *
 * Configure the Socket URL, Cookie, Origin and x-dgw-* query before construction.
 * connect() proves the socket handshake only. Stream ACKs prove transport
 * acceptance only: application task results and message receipts remain separate.
 * No account registration, automatic replay or authentication fallback occurs here.
 */
final class DgwConnection
{
    /** @var array<int, array<string, mixed>> */
    private array $streams = [];
    /** @var list<array{stream_id:int, payload:array<string, mixed>}> */
    private array $events = [];
    private int $nextStream = 0;
    private float $lastPing = 0.0;
    private float $lastPong = 0.0;
    private bool $active = false;

    public function __construct(
        private readonly Socket $socket,
        private readonly int $maxPayloadBytes = 8388608,
        private readonly int $maxStreams = 64,
        private readonly int $maxQueuedEvents = 1024,
    ) {
        if ($maxPayloadBytes < 64 || $maxPayloadBytes > 0xffffff || $maxStreams < 1 || $maxStreams > 65536 || $maxQueuedEvents < 1) {
            throw new \InvalidArgumentException('Limites de conexão DGW inválidos.');
        }
    }

    public function connect(): void
    {
        if ($this->isConnected()) {
            return;
        }
        $this->streams = [];
        $this->events = [];
        $this->nextStream = 0;
        $this->socket->connect();
        if (!$this->socket->isConnected()) {
            throw new MessengerException('dgw_disconnected', 'O transporte DGW não confirmou a conexão.');
        }
        $this->active = true;
        $this->lastPing = $this->lastPong = self::now();
    }

    /**
     * Waits for stream establishment, data ACK and a response with matching request_id.
     * The returned object is the LS response, not a message-delivery receipt.
     * @param array<string, mixed> $request
     * @return array<string, mixed>
     */
    public function request(array $request, float $timeoutSeconds = 10.0): array
    {
        $requestId = self::requestId($request);
        if (($request['type'] ?? null) === 4) {
            throw new MessengerException('dgw_invalid_request', 'Uma solicitação sem resposta não pode ser usada em request().');
        }
        $deadline = $this->deadline($timeoutSeconds);
        $stream = $this->createStream($request, false);
        try {
            $this->waitFor($stream, $deadline, true);
            $response = $this->streams[$stream]['response'];
            if (!is_array($response) || self::requestId($response) !== $requestId) {
                throw new MessengerException('dgw_request_mismatch', 'A resposta DGW não corresponde à solicitação.');
            }
            return $response;
        } finally {
            $this->closeStream($stream);
        }
    }

    /**
     * Opens a persistent LS database sync stream. Data received while waiting for
     * its transport ACK is queued for receive(); callers maintain LS cursors.
     * @param array<string, mixed> $initialRequest
     */
    public function subscribe(array $initialRequest, float $timeoutSeconds = 10.0): int
    {
        self::requestId($initialRequest);
        $deadline = $this->deadline($timeoutSeconds);
        $stream = $this->createStream($initialRequest, true);
        try {
            $this->waitFor($stream, $deadline, false);
            return $stream;
        } catch (\Throwable $error) {
            $this->closeStream($stream);
            throw $error;
        }
    }

    /** @param array<string, mixed> $request */
    public function sendOnStream(int $streamId, array $request, float $timeoutSeconds = 10.0): void
    {
        self::requestId($request);
        $deadline = $this->deadline($timeoutSeconds);
        $this->requireConnected();
        if (!isset($this->streams[$streamId]) || !$this->streams[$streamId]['persistent'] || $this->streams[$streamId]['closed']) {
            throw new MessengerException('dgw_stream_closed', 'O stream DGW não está aberto.');
        }
        $ackId = $this->streams[$streamId]['next_ack'];
        $this->streams[$streamId]['next_ack'] = ($ackId + 1) & 0x7fff;
        $this->streams[$streamId]['awaiting_ack'] = $ackId;
        $this->streams[$streamId]['acked'] = false;
        $this->sendFrames([[
            'type' => DgwCodec::DATA, 'stream_id' => $streamId,
            'ack_id' => $ackId, 'requires_ack' => true, 'payload' => $this->encodeRequest($request),
        ]]);
        try {
            $this->waitFor($streamId, $deadline, false);
        } catch (\Throwable $error) {
            $this->closeStream($streamId);
            throw $error;
        }
    }

    /** @return array{stream_id:int, payload:array<string, mixed>}|null */
    public function receive(float $timeoutSeconds = 0.5): ?array
    {
        $deadline = $this->deadline($timeoutSeconds, true);
        $this->requireConnected();
        if ($this->events !== []) {
            return array_shift($this->events);
        }
        do {
            $this->pump(max(0.0, min(0.5, $deadline - self::now())));
            if ($this->events !== []) {
                return array_shift($this->events);
            }
        } while (self::now() < $deadline);
        return null;
    }

    public function closeStream(int $streamId): void
    {
        if (!isset($this->streams[$streamId])) {
            return;
        }
        $closed = $this->streams[$streamId]['closed'];
        unset($this->streams[$streamId]);
        if (!$closed && $this->isConnected()) {
            $this->sendFrames([['type' => DgwCodec::END, 'stream_id' => $streamId]]);
        }
    }

    public function isConnected(): bool
    {
        return $this->active && $this->socket->isConnected();
    }

    public function close(): void
    {
        $this->active = false;
        $this->streams = [];
        $this->events = [];
        $this->socket->close();
    }

    /** @param array<string, mixed> $request */
    private function createStream(array $request, bool $persistent): int
    {
        $this->requireConnected();
        $payload = $this->encodeRequest($request);
        if (count($this->streams) >= $this->maxStreams) {
            throw new MessengerException('dgw_limit', 'Limite de streams DGW atingido.');
        }
        do {
            $id = $this->nextStream;
            $this->nextStream = ($this->nextStream + 1) & 0xffff;
        } while (isset($this->streams[$id]));
        $this->streams[$id] = [
            'persistent' => $persistent, 'established' => false, 'acked' => false,
            'awaiting_ack' => 0, 'next_ack' => 1, 'closed' => false,
            'error' => null, 'response' => null,
        ];
        try {
            $this->sendFrames([
                ['type' => DgwCodec::ESTABLISH, 'stream_id' => $id, 'parameters' => new \stdClass()],
                ['type' => DgwCodec::DATA, 'stream_id' => $id, 'ack_id' => 0, 'requires_ack' => true, 'payload' => $payload],
            ]);
        } catch (\Throwable $error) {
            unset($this->streams[$id]);
            throw $error;
        }
        return $id;
    }

    private function waitFor(int $streamId, float $deadline, bool $needResponse): void
    {
        while (true) {
            $this->requireConnected();
            $state = $this->streams[$streamId] ?? null;
            if ($state === null) {
                throw new MessengerException('dgw_stream_closed', 'O stream DGW foi encerrado.');
            }
            if ($state['error'] instanceof MessengerException) {
                throw $state['error'];
            }
            if ($state['established'] && $state['acked'] && (!$needResponse || $state['response'] !== null)) {
                return;
            }
            if ($state['closed']) {
                throw new MessengerException('dgw_stream_closed', 'O servidor encerrou o stream antes de confirmar a operação.');
            }
            $remaining = $deadline - self::now();
            if ($remaining <= 0) {
                throw new MessengerException('dgw_timeout', 'A confirmação DGW expirou; o resultado da operação pode ser incerto.');
            }
            $this->pump(min(0.5, $remaining));
        }
    }

    private function pump(float $timeout): void
    {
        $now = self::now();
        if ($now - $this->lastPong >= 30.0) {
            $this->fail('dgw_pong_timeout', 'O servidor DGW deixou de confirmar a conexão.');
        }
        if ($now - $this->lastPing >= 10.0) {
            $this->sendFrames([['type' => DgwCodec::PING]]);
            $this->lastPing = $now;
        }
        try {
            $message = $this->socket->receive($timeout);
            if ($message === null) {
                $this->requireConnected();
                return;
            }
            foreach (DgwCodec::decode($message, $this->maxPayloadBytes) as $frame) {
                $this->handleFrame($frame);
            }
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** @param array<string, mixed> $frame */
    private function handleFrame(array $frame): void
    {
        $type = $frame['type'];
        if ($type === DgwCodec::PING || $type === DgwCodec::PONG) {
            $this->lastPong = self::now();
            if ($type === DgwCodec::PING) {
                $this->sendFrames([['type' => DgwCodec::PONG]]);
            }
            return;
        }
        if ($type === DgwCodec::DEAUTH) {
            $this->fail('dgw_unauthorized', 'O servidor DGW invalidou a autenticação.');
        }
        if ($type === DgwCodec::DRAIN) {
            $this->fail('dgw_draining', 'O servidor DGW encerrou esta conexão; reconecte e retome os cursores.');
        }
        $id = $frame['stream_id'];
        if (!isset($this->streams[$id])) {
            // A late response after a timed-out request must not be attributed
            // to another command. Stream IDs are not reused until wraparound.
            if (($type === DgwCodec::DATA || $type === DgwCodec::EXTENDED_DATA) && $frame['requires_ack']) {
                $this->sendFrames([['type' => DgwCodec::ACK, 'stream_id' => $id, 'ack_id' => $frame['ack_id']]]);
            }
            return;
        }
        if ($type === DgwCodec::ESTABLISH) {
            $params = $frame['parameters'];
            $code = is_array($params) ? ($params['code'] ?? null) : $params;
            if ($code !== 200) {
                $this->streams[$id]['error'] = new MessengerException('dgw_stream_rejected', 'O servidor recusou a abertura do stream DGW.');
            } else {
                $this->streams[$id]['established'] = true;
            }
        } elseif ($type === DgwCodec::ACK) {
            if ($frame['ack_id'] === $this->streams[$id]['awaiting_ack']) {
                $this->streams[$id]['acked'] = true;
            }
        } elseif ($type === DgwCodec::END || $type === DgwCodec::END_REASON) {
            $this->streams[$id]['closed'] = true;
            if ($type === DgwCodec::END_REASON && !in_array($frame['reason'], [0, 2], true)) {
                $this->streams[$id]['error'] = new MessengerException('dgw_stream_error', 'O servidor encerrou o stream DGW com erro.');
            }
            if ($this->streams[$id]['persistent']) {
                $this->queueEvent(['stream_id' => $id, 'payload' => [
                    'event' => 'stream_closed', 'reason' => $frame['reason'] ?? 0,
                ]]);
            }
        } elseif ($type === DgwCodec::DATA || $type === DgwCodec::EXTENDED_DATA) {
            if ($type === DgwCodec::EXTENDED_DATA && $frame['content_type'] !== 0) {
                $this->fail('dgw_content_type', 'O servidor usou um conteúdo DGW não implementado.');
            }
            $payload = $this->decodeResponse($frame['payload']);
            if ($frame['requires_ack']) {
                $this->sendFrames([['type' => DgwCodec::ACK, 'stream_id' => $id, 'ack_id' => $frame['ack_id']]]);
            }
            if ($this->streams[$id]['persistent']) {
                $this->queueEvent(['stream_id' => $id, 'payload' => $payload]);
            } elseif ($this->streams[$id]['response'] !== null) {
                $this->fail('dgw_duplicate_response', 'O servidor retornou mais de uma resposta para o mesmo stream DGW.');
            } else {
                $this->streams[$id]['response'] = $payload;
            }
        }
    }

    /** @param array{stream_id:int,payload:array<string,mixed>} $event */
    private function queueEvent(array $event): void
    {
        if (count($this->events) >= $this->maxQueuedEvents) {
            $this->fail('dgw_event_overflow', 'A fila de eventos DGW atingiu o limite; retome pelos cursores persistidos.');
        }
        $this->events[] = $event;
    }

    /** @param list<array<string, mixed>> $frames */
    private function sendFrames(array $frames): void
    {
        $message = '';
        foreach ($frames as $frame) {
            $message .= DgwCodec::encode($frame);
        }
        if (strlen($message) > $this->maxPayloadBytes) {
            throw new MessengerException('dgw_limit', 'A mensagem DGW excede o limite configurado.');
        }
        try {
            $this->socket->send($message);
        } catch (\Throwable $error) {
            $this->close();
            throw $error;
        }
    }

    /** @param array<string, mixed> $request */
    private function encodeRequest(array $request): string
    {
        try {
            return json_encode($request, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (\JsonException) {
            throw new MessengerException('dgw_invalid_request', 'A solicitação DGW contém JSON inválido.');
        }
    }

    /** @return array<string, mixed> */
    private function decodeResponse(string $payload): array
    {
        try {
            $response = json_decode($payload, true, 128, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new MessengerException('dgw_invalid_response', 'A resposta DGW contém JSON inválido.');
        }
        if (!is_array($response) || !str_starts_with(ltrim($payload), '{')) {
            throw new MessengerException('dgw_invalid_response', 'A resposta DGW precisa ser um objeto JSON.');
        }
        return $response;
    }

    /** @param array<string, mixed> $request */
    private static function requestId(array $request): string
    {
        $id = $request['request_id'] ?? null;
        if ((is_int($id) && $id >= 0) || (is_string($id) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $id) === 1)) {
            return (string) $id;
        }
        throw new MessengerException('dgw_invalid_request', 'request_id DGW precisa ser um inteiro decimal não negativo.');
    }

    private function deadline(float $seconds, bool $allowZero = false): float
    {
        if (!is_finite($seconds) || ($allowZero ? $seconds < 0 : $seconds <= 0) || $seconds > 300) {
            throw new \InvalidArgumentException('Timeout DGW inválido.');
        }
        return self::now() + $seconds;
    }

    private function requireConnected(): void
    {
        if (!$this->isConnected()) {
            throw new MessengerException('dgw_disconnected', 'A conexão DGW está encerrada.');
        }
    }

    private function fail(string $reason, string $message): never
    {
        $this->close();
        throw new MessengerException($reason, $message);
    }

    private static function now(): float
    {
        return hrtime(true) / 1_000_000_000;
    }
}
