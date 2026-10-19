<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee\Protocol;

/** Internal protocol integration seam. No production ICDC verifier is shipped yet.
 * Implementations must verify the authentic signed ICDC roster, identity membership,
 * signer trust/sequence and exact device-to-identity binding, or throw. Merely seeing
 * dhash, an HTTP/Noise result or a consumer-supplied boolean is not verification.
 * Synthetic implementations belong only in tests. This is not a public permission flag.
 */
interface RosterVerifier
{
    /** @param array<string,array{devices:list<string>,dhash:string,remote_identity_trust_verified:bool}> $rosters
     * @param array<string,array{registrationId:int,identityKey:string,signedPreKey:array{id:int,public:string,signature:string},preKey?:array{id:int,public:string}}> $bundles
     */
    public function verify(BinaryNode $directoryResult,array $rosters,array $bundles):void;
}
