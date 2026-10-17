<?php
declare(strict_types=1);
namespace MVO\Signal;

use MVO\Crypto\{Crypto,XEdDsa};
use MVO\Protocol\Jid;

/**
 * Native Signal group sender keys. Authentication, skipped keys and rotation
 * persist atomically; distributions must arrive through authenticated Signal.
 * @phpstan-type State array{id:int,iteration:int,chain:string,public:string,private:?string,unused:array<int,string>}
 */
final class SenderKeyProtocol
{
    private const MAX_UNUSED = 2000;
    private const MAX_FUTURE = 20000;
    private const MAX_STATES = 5;

    public function __construct(private readonly SignalStore $store) {}

    /** Obtain the current sending chain without advancing it. */
    public function createDistribution(string $group, string $sender): string
    {
        $key = self::address($group, $sender);
        return $this->store->transaction(function () use ($key):string {
            $states = $this->load($key);
            if ($states === []) { $states[] = self::fresh(); $this->save($key, $states); }
            $state = $states[0];
            if ($state['private'] === null) throw new \LogicException('Sender private signing key is missing');
            return SenderKeyCodec::distribution($state['id'], $state['iteration'], $state['chain'], $state['public']);
        });
    }

    /** Rotate after group membership/device removal, retaining delayed old messages. */
    public function rotate(string $group, string $sender): void
    {
        $key = self::address($group, $sender);
        $this->store->transaction(function () use ($key):void {
            $states = $this->load($key);
            $fresh = self::fresh();
            while (in_array($fresh['id'], array_column($states, 'id'), true)) $fresh = self::fresh();
            $this->save($key, array_slice([$fresh,...$states], 0, self::MAX_STATES));
        });
    }

    /** Call only after authenticating the envelope with the sender's direct Signal session. */
    public function processDistribution(string $group, string $sender, string $bytes): void
    {
        $key = self::address($group, $sender);
        $parsed = SenderKeyCodec::readDistribution($bytes);
        $this->store->transaction(function () use ($key, $parsed):void {
            $states = $this->load($key);
            foreach ($states as $index=>$state) {
                if ($state['id'] !== $parsed['id']) continue;
                if (!hash_equals($state['public'], $parsed['public'])) throw new \UnexpectedValueException('Sender signing identity changed for the same key ID');
                // A repeated distribution must never reset replay protection or
                // discard skipped keys. Authenticated newer snapshots can jump ahead.
                if ($parsed['iteration'] <= $state['iteration']) return;
                if ($state['private'] !== null) throw new \UnexpectedValueException('Cannot replace a local sending chain');
                if ($parsed['iteration']-$state['iteration'] <= self::MAX_FUTURE) {
                    // Repeated distributions may overtake queued group messages.
                    // Retain their skipped keys and check chain continuity.
                    [$advanced,$lastSeed] = self::select($state, $parsed['iteration']-1);
                    $advanced['unused'][$parsed['iteration']-1] = $lastSeed;
                    if (count($advanced['unused'])>self::MAX_UNUSED) $advanced['unused'] = array_slice($advanced['unused'], -self::MAX_UNUSED, null, true);
                    if (!hash_equals($advanced['chain'], $parsed['chain'])) throw new \UnexpectedValueException('Inconsistent sender key distribution chain');
                    $state = $advanced;
                }
                $state['chain'] = $parsed['chain']; $state['iteration'] = $parsed['iteration'];
                $states[$index] = $state;
                $this->save($key, $states);
                return;
            }
            $state = [...$parsed, 'private'=>null, 'unused'=>[]];
            $this->save($key, array_slice([$state,...$states], 0, self::MAX_STATES));
        });
    }

    public function encrypt(string $group, string $sender, string $plaintext): string
    {
        $key = self::address($group, $sender);
        return $this->store->transaction(function () use ($key, $plaintext):string {
            $states = $this->load($key);
            if ($states === [] || $states[0]['private'] === null) throw new \LogicException('Create a sender key distribution before encryption');
            $state = $states[0];
            if ($state['iteration'] >= 0xffffffff) throw new \OverflowException('Sender key iteration exhausted; rotate sender key');
            $derived = self::derive($state['chain']);
            $ciphertext = openssl_encrypt($plaintext, 'aes-256-cbc', substr($derived['seed'], 16, 32), OPENSSL_RAW_DATA, substr($derived['seed'], 0, 16));
            if ($ciphertext === false) throw new \RuntimeException('Sender key encryption failed');
            $body = SenderKeyCodec::message($state['id'], $state['iteration'], $ciphertext);
            $result = $body.XEdDsa::sign($state['private'], $body);
            $state['chain'] = $derived['next']; ++$state['iteration'];
            $states[0] = $state; $this->save($key, $states);
            return $result;
        });
    }

    public function decrypt(string $group, string $sender, string $ciphertext): string
    {
        $key = self::address($group, $sender);
        $parsed = SenderKeyCodec::readMessage($ciphertext);
        return $this->store->transaction(function () use ($key, $parsed):string {
            $states = $this->load($key); $index = null;
            foreach ($states as $candidate=>$state) if ($state['id'] === $parsed['id']) { $index = $candidate; break; }
            if ($index === null) throw new \UnexpectedValueException('Sender key not found for this group/sender/key ID');
            $state = $states[$index];
            if (!XEdDsa::verify(SignalState::rawPublicKey($state['public']), $parsed['body'], $parsed['signature'])) throw new \UnexpectedValueException('Invalid sender key signature');
            [$updated,$seed] = self::select($state, $parsed['iteration']);
            $plaintext = openssl_decrypt($parsed['ciphertext'], 'aes-256-cbc', substr($seed, 16, 32), OPENSSL_RAW_DATA, substr($seed, 0, 16));
            if ($plaintext === false) throw new \UnexpectedValueException('Invalid sender key ciphertext padding');
            $states[$index] = $updated; $this->save($key, $states);
            return $plaintext;
        });
    }

    /** @return array{next:string,seed:string} */
    public static function derive(string $chain): array
    {
        Crypto::length($chain, 32);
        return ['next'=>Crypto::hmac($chain, "\x02"), 'seed'=>Crypto::hkdf(Crypto::hmac($chain, "\x01"), 50, 'WhisperGroup')];
    }

    /**
     * @param State $state
     * @return array{State,string}
     */
    private static function select(array $state, int $iteration): array
    {
        if ($iteration >= 0xffffffff) throw new \UnexpectedValueException('Sender key iteration exhausted');
        $delta = $iteration-$state['iteration'];
        if ($delta > self::MAX_FUTURE) throw new \UnexpectedValueException('Sender key message is too far in future');
        if ($delta < 0) {
            $seed = $state['unused'][$iteration] ?? null;
            if ($seed === null) throw new \UnexpectedValueException('Sender key message iteration is stale or replayed');
            unset($state['unused'][$iteration]);
            return [$state,$seed];
        }
        // Bound skipped-key memory while allowing an offline group sender's gap.
        $firstRetained = max($state['iteration'], $iteration-self::MAX_UNUSED);
        while ($state['iteration'] < $iteration) {
            $derived = self::derive($state['chain']);
            $seed = $derived['seed']; $current = $state['iteration'];
            $state['chain'] = $derived['next']; ++$state['iteration'];
            if ($current >= $firstRetained) $state['unused'][$current] = $seed;
        }
        $derived = self::derive($state['chain']);
        $seed = $derived['seed']; $state['chain'] = $derived['next']; ++$state['iteration'];
        if (count($state['unused']) > self::MAX_UNUSED) $state['unused'] = array_slice($state['unused'], -self::MAX_UNUSED, null, true);
        return [$state,$seed];
    }

    /** @return State */
    private static function fresh(): array
    {
        $pair = SignalState::keyPair();
        return ['id'=>random_int(1, 0x7fffffff), 'iteration'=>0, 'chain'=>random_bytes(32),
            'public'=>$pair['public'], 'private'=>$pair['private'], 'unused'=>[]];
    }

    /** @return list<State> */
    private function load(string $key): array
    {
        $bytes = $this->store->get('sender-key', $key);
        if ($bytes === null) return [];
        $states = SignalState::unpack(json_decode($bytes, true, 32, JSON_THROW_ON_ERROR));
        if (!is_array($states) || !array_is_list($states) || count($states)>self::MAX_STATES) throw new \UnexpectedValueException('Invalid stored sender key state');
        foreach ($states as $state) {
            if (!is_array($state) || !is_int($state['id'] ?? null) || !is_int($state['iteration'] ?? null) ||
                $state['id'] < 0 || $state['id']>0xffffffff || $state['iteration']<0 || $state['iteration']>0xffffffff ||
                !is_string($state['chain'] ?? null) || strlen($state['chain']) !== 32 || !is_string($state['public'] ?? null) ||
                !array_key_exists('private', $state) || ($state['private'] !== null && (!is_string($state['private']) || strlen($state['private']) !== 32)) ||
                !is_array($state['unused'] ?? null) || count($state['unused'])>self::MAX_UNUSED) throw new \UnexpectedValueException('Invalid stored sender key');
            SignalState::publicKey($state['public']);
            foreach ($state['unused'] as $iteration=>$seed) if (!is_int($iteration) || $iteration<0 || !is_string($seed) || strlen($seed)!==50) throw new \UnexpectedValueException('Invalid skipped sender message key');
        }
        /** @var list<State> $states */
        return $states;
    }

    /** @param list<State> $states */
    private function save(string $key, array $states): void
    {
        $this->store->set('sender-key', $key, json_encode(SignalState::pack($states), JSON_THROW_ON_ERROR));
    }

    private static function address(string $group, string $sender): string
    {
        $groupAddress = Jid::parse($group)->canonical(); $senderAddress = Jid::parse($sender)->canonical();
        if (!in_array($groupAddress->server,['g.us','broadcast'],true) || $groupAddress->device !== 0) throw new \InvalidArgumentException('Sender keys require a group or broadcast JID');
        if (!in_array($senderAddress->server, ['s.whatsapp.net','lid'], true) || $senderAddress->device>255) throw new \InvalidArgumentException('Sender keys require a direct-device sender');
        return hash('sha256', $groupAddress->bare()."\0".(string)$senderAddress);
    }
}
