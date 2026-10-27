<?php
declare(strict_types=1);

namespace MVO\Auth;

use MVO\Crypto\Crypto;
use MVO\Crypto\XEdDsa;
use MVO\Protocol\BinaryNode;
use MVO\Protocol\Jid;
use MVO\Protocol\Protobuf;

/** Pair-device/QR, link-code hello/finish and authenticated ADV registration. */
final class PairingFlow
{
    /** @var list<string> */
    private array $qrRefs = [];
    private int $qrIndex = 0;
    private float $qrDeadline = 0;
    /** @var array{code:string,phoneJid:string,ref:string,createdAt:float,ephemeral:array{private:string,public:string},attempts:int,finished:bool}|null */
    private ?array $session = null;
    private bool $pairSuccessInFlight = false;
    /** @var array{code:string,phoneJid:string,createdAt:float}|null */
    private ?array $pendingCode = null;
    private bool $rearmPending = false;
    private bool $rearmInFlight = false;
    private int $operationGeneration = 0;

    /**
     * @param \Closure():array<string,mixed> $getCredentials
     * @param \Closure(array<string,mixed>):void $saveCredentials
     * @param \Closure(BinaryNode):void $sendNode
     * @param \Closure(BinaryNode,float):BinaryNode $query
     * @param \Closure(string,array<string,mixed>):void $emit
     */
    public function __construct(
        private readonly \Closure $getCredentials,
        private readonly \Closure $saveCredentials,
        private readonly \Closure $sendNode,
        private readonly \Closure $query,
        private readonly \Closure $emit,
        private readonly string $platform = '1',
        private readonly string $display = 'Chrome (Windows)',
        private readonly ?\Closure $clock = null,
    ) {}

    public function clear(): void
    {
        ++$this->operationGeneration;
        $this->qrRefs = [];
        $this->qrIndex = 0;
        $this->qrDeadline = 0;
        $this->session = null;
        $this->pendingCode = null;
        $this->rearmPending = false;
    }

    public function tick(float $now): void
    {
        if ($this->qrDeadline > 0 && $now >= $this->qrDeadline) $this->rotateQr($now);
        $active = $this->session ?? $this->pendingCode;
        if ($active !== null && $now - $active['createdAt'] > 180) {
            ++$this->operationGeneration;
            $this->session = null;
            $this->pendingCode = null;
            $this->rearmPending = false;
            ($this->emit)('auth_code_expired', []);
        }
    }

    /** @internal Memory-only input for automatic reconnect; no old ref or private ephemeral key.
     * @return array{code:string,phoneJid:string,createdAt:float}|null */
    public function pendingCodeForReconnect(): ?array
    {
        $active = $this->session ?? $this->pendingCode;
        if ($active === null) return null;
        return ['code'=>$active['code'], 'phoneJid'=>$active['phoneJid'], 'createdAt'=>$active['createdAt']];
    }

    /** @internal Restore only the latest code in the same client lifecycle; never persist this record.
     * @param array{code:string,phoneJid:string,createdAt:float} $code */
    public function restorePendingCode(array $code): void
    {
        $generation=$this->operationGeneration;
        $now=$this->now();
        if ($generation!==$this->operationGeneration || (($this->getCredentials)()['registered'] ?? false)) return;
        $code['code'] = PairingCodeCrypto::normalizeCode($code['code']);
        if (preg_match('/^[1-9][0-9]{6,14}@s\.whatsapp\.net$/D', $code['phoneJid']) !== 1 || !is_finite($code['createdAt'])) throw new \InvalidArgumentException('Invalid pending pairing code');
        if ($code['createdAt'] > $now || $now - $code['createdAt'] > 180) return;
        ++$this->operationGeneration;
        $this->session = null;
        $this->pendingCode = $code;
    }

    /** Register a pending code only from the outer loop, after the new pair-device callback returns. */
    public function drainPending(): void
    {
        if (!$this->rearmPending || $this->pendingCode === null || $this->rearmInFlight) return;
        $this->tick($this->now());
        if ($this->pendingCode === null) return;
        $previous = $this->pendingCode;
        $generation = ++$this->operationGeneration;
        $this->rearmPending = false;
        $this->rearmInFlight = true;
        try {
            // The new QR already carries its ADV secret. Re-registering a code
            // rotates only the ephemeral exchange, without another push or TTL extension.
            $hello = PairingCodeCrypto::createHello($previous['code']);
            $session = $this->sendHello($previous['phoneJid'], $hello, false, $previous['createdAt']);
            if ($generation !== $this->operationGeneration) return;
            $this->tick($this->now());
            if ($generation !== $this->operationGeneration) return;
            $this->session = $session;
            $this->pendingCode = null;
        } catch (\Throwable $error) {
            if ($generation !== $this->operationGeneration) return;
            $this->session = null;
            $this->pendingCode = null;
            ($this->emit)('auth_code_refresh', ['forceManual'=>true, 'reason'=>'code_reregistration_failed']);
            return;
        } finally {
            $this->rearmInFlight = false;
        }
        if ($generation === $this->operationGeneration) ($this->emit)('auth_code_progress', ['stage'=>'code_registered_after_reconnect']);
    }

    public function requestCode(string $phoneNumber, ?string $customCode = null): string
    {
        if (($this->getCredentials)()['registered'] ?? false) throw new \LogicException('Device is already registered');
        $phone = trim($phoneNumber);
        if (!preg_match('/^\+?[0-9 ()-]+$/D', $phone)) throw new \InvalidArgumentException('Use a phone number with country code');
        $digits = preg_replace('/[^0-9]/', '', $phone);
        if ($digits === null || !preg_match('/^[1-9][0-9]{6,14}$/D', $digits)) throw new \InvalidArgumentException('Phone number must have 7-15 digits including country code');
        $hello = PairingCodeCrypto::createHello($customCode);
        $generation = ++$this->operationGeneration;
        $this->session = null;
        $this->pendingCode = null;
        $this->rearmPending = false;
        $this->rotateAdvSecret();
        $phoneJid = $digits . '@s.whatsapp.net';
        $session = $this->sendHello($phoneJid, $hello, true, $this->now());
        if ($generation !== $this->operationGeneration) throw new \MVO\Transport\SessionRestartRequired('Pairing code request was superseded');
        $this->session = $session;
        // The fresh ADV secret invalidates any previously displayed QR.
        if ($this->qrIndex > 0) $this->emitQr($this->qrRefs[$this->qrIndex - 1], $this->now());
        if ($generation !== $this->operationGeneration) throw new \MVO\Transport\SessionRestartRequired('Pairing code request was superseded');
        ($this->emit)('auth_code', ['code' => $hello['pairingCode']]);
        return $hello['pairingCode'];
    }

    /** @param array{pairingCode:string,companionEphemeralKeyPair:array{private:string,public:string},wrappedCompanionEphemeralPub:string} $hello
     * @return array{code:string,phoneJid:string,ref:string,createdAt:float,ephemeral:array{private:string,public:string},attempts:int,finished:bool} */
    private function sendHello(string $phoneJid, array $hello, bool $push, float $createdAt): array
    {
        $credentials = ($this->getCredentials)();
        $response = ($this->query)(new BinaryNode('iq', ['to' => 's.whatsapp.net', 'type' => 'set', 'xmlns' => 'md'], [
            new BinaryNode('link_code_companion_reg', ['jid' => $phoneJid, 'stage' => 'companion_hello', 'should_show_push_notification' => $push ? 'true' : 'false'], [
                new BinaryNode('link_code_pairing_wrapped_companion_ephemeral_pub', [], $hello['wrappedCompanionEphemeralPub']),
                new BinaryNode('companion_server_auth_key_pub', [], $credentials['noiseKeyPair']['public']),
                new BinaryNode('companion_platform_id', [], $this->platform),
                new BinaryNode('companion_platform_display', [], $this->display),
                new BinaryNode('link_code_pairing_nonce', [], ''),
            ]),
        ]), 30.0);
        self::assertResult($response, 'companion_hello');
        $ref = self::content($response->child('link_code_companion_reg')?->child('link_code_pairing_ref'), 'companion hello pairing ref');
        return [
            'code' => $hello['pairingCode'], 'phoneJid' => $phoneJid, 'ref' => $ref,
            'createdAt' => $createdAt, 'ephemeral' => $hello['companionEphemeralKeyPair'],
            'attempts' => 0, 'finished' => false,
        ];
    }

    public function handleNode(BinaryNode $node): bool
    {
        if ($node->tag === 'iq' && ($node->attrs['type'] ?? '') === 'set') {
            if (($pair = $node->child('pair-device')) !== null) {
                $refs = [];
                foreach (is_array($pair->content) ? $pair->content : [] as $ref) {
                    if ($ref->tag === 'ref') $refs[] = self::content($ref, 'QR ref');
                }
                if ($refs === []) throw new \UnexpectedValueException('pair-device has no QR refs');
                $pendingCode = $this->pendingCodeForReconnect();
                ++$this->operationGeneration;
                $this->session = null;
                $this->pendingCode = $pendingCode;
                $this->rearmPending = $pendingCode !== null;
                $this->rotateAdvSecret();
                ($this->sendNode)(self::iqResponse($node));
                $this->qrRefs = $refs;
                $this->qrIndex = 0;
                $this->rotateQr($this->now());
                return true;
            }
            if (($pair = $node->child('pair-success')) !== null) {
                if ($this->pairSuccessInFlight || (($this->getCredentials)()['meJid'] ?? null) !== null) return true;
                $this->pairSuccessInFlight = true;
                try {
                    $this->completePairSuccess($node, $pair);
                } finally {
                    $this->pairSuccessInFlight = false;
                }
                return true;
            }
        }
        if ($node->tag !== 'notification') return false;
        if ($node->child('companion_reg_refresh') !== null || $node->child('pair-device-rotate-qr') !== null) {
            $this->rotateAdvSecret();
            $this->ack($node, 'companion_reg_refresh');
            if ($this->qrIndex > 0) $this->emitQr($this->qrRefs[$this->qrIndex - 1], $this->now());
            return true;
        }
        $link = $node->child('link_code_companion_reg');
        if ($link === null) return false;
        $this->ack($node);
        if (($link->attrs['stage'] ?? '') === 'refresh_code' && $this->session !== null) {
            $ref = self::content($link->child('link_code_pairing_ref'), 'refresh_code ref');
            if (hash_equals($this->session['ref'], $ref)) {
                ($this->emit)('auth_code_refresh', ['forceManual' => ($link->attrs['force_manual_refresh'] ?? '') === 'true']);
            }
        } elseif (($link->attrs['stage'] ?? '') === 'primary_hello') {
            $this->handlePrimaryHello($link);
        }
        return true;
    }

    private function handlePrimaryHello(BinaryNode $link): void
    {
        if ($this->session === null) return;
        $ref = self::content($link->child('link_code_pairing_ref'), 'primary_hello ref');
        if (!hash_equals($this->session['ref'], $ref)) return;
        if ($this->now() - $this->session['createdAt'] > 180) {
            $this->session = null;
            throw new \UnexpectedValueException('Pairing code has expired');
        }
        if (++$this->session['attempts'] > 3) throw new \UnexpectedValueException('Pairing code exceeded three primary hello attempts');
        $attempt = $this->session['attempts'];
        ($this->emit)('auth_code_progress', ['stage' => 'primary_hello_received', 'attempt' => $attempt]);
        // Progress listeners may stop pairing; never dereference a cleared/replaced flow.
        if ($this->session === null || !hash_equals($this->session['ref'], $ref)) return;
        $credentials = ($this->getCredentials)();
        if ($this->session['finished']) {
            $credentials = $this->rotateAdvSecret();
            $this->session['finished'] = false;
        }
        $finish = PairingCodeCrypto::completeFinish(
            $this->session['code'],
            self::content($link->child('link_code_pairing_wrapped_primary_ephemeral_pub'), 'primary wrapped key'),
            self::content($link->child('primary_identity_pub'), 'primary identity key'),
            $this->session['ephemeral']['private'],
            $credentials['identityKeyPair'],
        );
        $credentials['advSecretKey'] = $finish['advSecret'];
        ($this->saveCredentials)($credentials);
        ($this->emit)('auth_code_progress', ['stage' => 'companion_finish_sent', 'attempt' => $attempt]);
        if ($this->session === null || !hash_equals($this->session['ref'], $ref)) return;
        $result = ($this->query)(new BinaryNode('iq', ['to' => 's.whatsapp.net', 'type' => 'set', 'xmlns' => 'md'], [
            new BinaryNode('link_code_companion_reg', ['jid' => $this->session['phoneJid'], 'stage' => 'companion_finish'], [
                new BinaryNode('link_code_pairing_wrapped_key_bundle', [], $finish['wrappedKeyBundle']),
                new BinaryNode('companion_identity_public', [], $finish['companionIdentityPublic']),
                new BinaryNode('link_code_pairing_ref', [], $ref),
            ]),
        ]), 30.0);
        self::assertResult($result, 'companion_finish');
        // A reentrant query can process pair-success, which clears the session.
        if ($this->session !== null && hash_equals($this->session['ref'], $ref)) $this->session['finished'] = true;
        ($this->emit)('auth_code_progress', ['stage' => 'companion_finish_accepted', 'attempt' => $attempt]);
    }

    private function completePairSuccess(BinaryNode $iq, BinaryNode $pair): void
    {
        $codeRef = $this->session['ref'] ?? null;
        if ($codeRef !== null) {
            ($this->emit)('auth_code_progress', ['stage' => 'pair_success_received']);
            if ($this->session === null || !hash_equals($this->session['ref'], $codeRef)) return;
        }
        $credentials = ($this->getCredentials)();
        try {
            $bytes = self::content($pair->child('device-identity'), 'device identity');
            if (strlen($bytes) > 500) throw new \LengthException('Device identity exceeds 500 bytes');
            $device = $pair->child('device');
            $platform = $pair->child('platform');
            $jid = $device?->attrs['jid'] ?? '';
            $platformName = $platform?->attrs['name'] ?? '';
            if ($jid === '' || $platformName === '') throw new \UnexpectedValueException('pair-success is missing device/platform');
            Jid::parse($jid);
            $wrapped = Protobuf::decode($bytes, 500, 32);
            $identityBytes = self::bytesField($wrapped, 1);
            $hmac = self::bytesField($wrapped, 2);
            $accountType = self::intField($wrapped, 3);
            if (!in_array($accountType, [0, 1], true)) throw new \UnexpectedValueException('Unsupported ADV account type');
            $expected = Crypto::hmac($credentials['advSecretKey'], ($accountType === 1 ? "\x06\x05" : '') . $identityBytes);
            if (!hash_equals($expected, $hmac)) throw new \UnexpectedValueException('pair-success HMAC validation failed');
            $identity = Protobuf::decode($identityBytes, 500, 32);
            $details = self::bytesField($identity, 1);
            $accountKey = self::bytesField($identity, 2);
            $accountSignature = self::bytesField($identity, 3);
            $deviceIdentity = Protobuf::decode($details, 500, 32);
            $deviceType = self::intField($deviceIdentity, 5);
            if (!in_array($deviceType, [0, 1], true)) throw new \UnexpectedValueException('Unsupported ADV device type');
            $rawAccountKey = strlen($accountKey) === 33 && $accountKey[0] === "\x05" ? substr($accountKey, 1) : $accountKey;
            Crypto::length($rawAccountKey, 32);
            Crypto::length($accountSignature, 64);
            $local = $credentials['identityKeyPair'];
            $accountPrefix = $deviceType === 1 ? "\x06\x05" : "\x06\0";
            if (!XEdDsa::verify($rawAccountKey, $accountPrefix . $details . $local['public'], $accountSignature)) {
                throw new \UnexpectedValueException('pair-success account signature validation failed');
            }
            $deviceSignature = XEdDsa::sign($local['private'], "\x06\x01" . $details . $local['public'] . $accountKey);
            $signedIdentity = ['details' => $details, 'accountSignatureKey' => $accountKey, 'accountSignature' => $accountSignature, 'deviceSignature' => $deviceSignature];
            $response = Protobuf::encode([
                ['number' => 1, 'wire' => 2, 'value' => $details],
                ['number' => 3, 'wire' => 2, 'value' => $accountSignature],
                ['number' => 4, 'wire' => 2, 'value' => $deviceSignature],
            ]);
            $keyIndex = self::intField($deviceIdentity, 3);
        } catch (\Throwable $error) {
            try {
                ($this->sendNode)(self::iqResponse($iq, 'error', [new BinaryNode('error', ['code' => '401', 'text' => 'not-authorized'])]));
            } catch (\Throwable) {
                // Preserve the validation error when the connection has already closed.
            }
            throw $error;
        }
        if ($codeRef !== null) {
            ($this->emit)('auth_code_progress', ['stage' => 'pair_success_validated']);
            if ($this->session === null || !hash_equals($this->session['ref'], $codeRef)) return;
        }
        $credentials['signedIdentity'] = $signedIdentity;
        $credentials['registered'] = true;
        $credentials['meJid'] = $jid;
        $credentials['meLid'] = $device->attrs['lid'] ?? null;
        $credentials['platform'] = $platformName;
        $credentials['loginCounter'] = 0;
        ($this->saveCredentials)($credentials);
        $this->clear();
        ($this->sendNode)(self::iqResponse($iq, 'result', [
            new BinaryNode('pair-device-sign', [], [new BinaryNode('device-identity', ['key-index' => (string) $keyIndex], $response)]),
        ]));
        ($this->emit)('auth_paired', ['credentials' => $credentials]);
    }

    private function rotateQr(float $now): void
    {
        if ($this->qrIndex >= count($this->qrRefs)) {
            $this->qrDeadline = 0;
            $this->qrRefs = [];
            $this->qrIndex = 0;
            ($this->emit)('auth_qr_expired', []);
            return;
        }
        $this->emitQr($this->qrRefs[$this->qrIndex++], $now);
    }

    private function emitQr(string $ref, float $now): void
    {
        $credentials = ($this->getCredentials)();
        $ttl = count($this->qrRefs) - $this->qrIndex === 5 ? 60 : 20;
        $qr = implode(',', [$ref, base64_encode($credentials['noiseKeyPair']['public']), base64_encode($credentials['identityKeyPair']['public']), base64_encode($credentials['advSecretKey']), $this->platform]);
        $this->qrDeadline = $now + $ttl;
        ($this->emit)('auth_qr', ['qr' => $qr, 'expiresIn' => $ttl]);
    }

    /** @return array<string,mixed> */
    private function rotateAdvSecret(): array
    {
        $credentials = ($this->getCredentials)();
        $credentials['advSecretKey'] = random_bytes(32);
        ($this->saveCredentials)($credentials);
        return $credentials;
    }

    private function ack(BinaryNode $node, ?string $type = null): void
    {
        $attrs = ['to' => $node->attrs['from'] ?? 's.whatsapp.net', 'class' => 'notification', 'type' => $type ?? $node->attrs['type'] ?? 'notification'];
        if (isset($node->attrs['id'])) $attrs['id'] = $node->attrs['id'];
        ($this->sendNode)(new BinaryNode('ack', $attrs));
    }

    private function now(): float { return $this->clock === null ? microtime(true) : ($this->clock)(); }

    /** @param list<BinaryNode>|null $content */
    private static function iqResponse(BinaryNode $request, string $type = 'result', ?array $content = null): BinaryNode
    {
        $attrs = ['to' => $request->attrs['from'] ?? 's.whatsapp.net', 'type' => $type];
        if (isset($request->attrs['id'])) $attrs['id'] = $request->attrs['id'];
        return new BinaryNode('iq', $attrs, $content);
    }

    private static function assertResult(BinaryNode $node, string $stage): void
    {
        if ($node->tag !== 'iq' || ($node->attrs['type'] ?? '') !== 'result') throw new \UnexpectedValueException($stage . ' was rejected by WhatsApp');
    }

    private static function content(?BinaryNode $node, string $name): string
    {
        if ($node === null || !is_string($node->content) || $node->content === '') throw new \UnexpectedValueException('Missing ' . $name);
        return $node->content;
    }

    /** @param list<array{number:int,wire:int,value:int|string}> $fields */
    private static function bytesField(array $fields, int $number): string
    {
        $value = null;
        foreach ($fields as $field) {
            if ($field['number'] !== $number) continue;
            if ($value !== null || $field['wire'] !== 2 || !is_string($field['value'])) throw new \UnexpectedValueException('Invalid ADV bytes field ' . $number);
            $value = $field['value'];
        }
        if ($value === null || $value === '') throw new \UnexpectedValueException('Missing ADV bytes field ' . $number);
        return $value;
    }

    /** @param list<array{number:int,wire:int,value:int|string}> $fields */
    private static function intField(array $fields, int $number): int
    {
        $value = null;
        foreach ($fields as $field) {
            if ($field['number'] !== $number) continue;
            if ($value !== null || $field['wire'] !== 0 || !is_int($field['value'])) throw new \UnexpectedValueException('Invalid ADV integer field ' . $number);
            $value = $field['value'];
        }
        return $value ?? 0;
    }
}
