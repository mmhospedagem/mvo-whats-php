<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee;

/** The fetched account roster is an authenticated snapshot, not a remote-device trust decision. */
final class IdentityRoster implements \JsonSerializable
{
    /** @param list<string> $identities Raw Curve25519 public keys in server order. */
    public function __construct(public readonly int $sequence, private readonly array $identities)
    {
        if ($sequence < 0 || $sequence > 2147483646 || !array_is_list($identities) || count($identities) > 255) throw new \UnexpectedValueException('Invalid Messenger identity roster');
        $seen = [];
        foreach ($identities as $identity) {
            if (strlen($identity) !== 32 || $identity === str_repeat("\0", 32) || isset($seen[bin2hex($identity)])) throw new \UnexpectedValueException('Invalid or repeated Messenger identity');
            $seen[bin2hex($identity)] = true;
        }
    }

    /** @param array<string,mixed> $response */
    public static function fromResponse(array $response): self
    {
        if (($response['status'] ?? null) !== 200 || !is_int($response['icdc_seq'] ?? null)
            || !is_array($response['device_identities'] ?? null) || !array_is_list($response['device_identities'])) throw new \UnexpectedValueException('Messenger did not confirm an identity roster');
        $identities = [];
        foreach ($response['device_identities'] as $encoded) {
            if (!is_string($encoded) || !preg_match('/^[A-Za-z0-9_-]{43}$/D', $encoded)) throw new \UnexpectedValueException('Invalid Messenger identity encoding');
            $identity = base64_decode(strtr($encoded, '-_', '+/') . '=', true);
            if ($identity === false || rtrim(strtr(base64_encode($identity), '+/', '-_'), '=') !== $encoded) throw new \UnexpectedValueException('Noncanonical Messenger identity encoding');
            $identities[] = $identity;
        }
        return new self($response['icdc_seq'], $identities);
    }

    /** Snapshot identity includes server order and sequence, unlike the protocol ihash. */
    public function snapshotHash(): string { return hash('sha256', pack('N', $this->sequence) . implode('', $this->identities)); }

    /** @return array{sequence:int,signing_index:int,details:string,identities_hash:string} */
    public function append(string $ownIdentity, int $timestamp): array
    {
        if (strlen($ownIdentity) !== 32 || $timestamp < 1) throw new \InvalidArgumentException('Invalid Messenger ICDC input');
        $identities = $this->identities; $index = array_search($ownIdentity, $identities, true); $sequence = $this->sequence;
        if ($index === false) { $index = count($identities); $identities[] = $ownIdentity; ++$sequence; }
        $details = ProtoWire::integer(1, $sequence) . ProtoWire::integer(2, $timestamp);
        foreach ($identities as $identity) $details .= ProtoWire::bytes(3, $identity);
        $details .= ProtoWire::integer(4, $index);
        sort($identities, SORT_STRING);
        return ['sequence' => $sequence, 'signing_index' => $index, 'details' => $details, 'identities_hash' => substr(hash('sha256', implode('', $identities), true), 0, 10)];
    }

    /** @return array<string,mixed> */
    public function jsonSerialize(): array
    {
        return ['sequence' => $this->sequence, 'devices' => count($this->identities), 'snapshot_hash' => $this->snapshotHash(),
            'identity_fingerprints' => array_map(static fn(string $identity): string => hash('sha256', $identity), $this->identities),
            'remote_device_trust_verified' => false];
    }
    /** @return array<string,mixed> */
    public function __debugInfo(): array { return $this->jsonSerialize(); }
}
