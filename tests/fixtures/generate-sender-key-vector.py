"""Independent Python/OpenSSL Signal sender-key vector; test tooling only.

Uses RFC 8032 Ed25519 signing and the Edwards -> Montgomery map, including
Signal's embedded public sign bit. No PHP or Node implementation is imported.
"""
from pathlib import Path
import hashlib
import hmac
import json
from cryptography.hazmat.primitives.asymmetric.ed25519 import Ed25519PrivateKey
from cryptography.hazmat.primitives.ciphers import Cipher, algorithms, modes
from cryptography.hazmat.primitives.padding import PKCS7


def mac(key, message):
    return hmac.new(key, message, hashlib.sha256).digest()


def hkdf(ikm, length=50):
    prk = mac(b"\0" * 32, ikm)
    block = out = b""
    for counter in range(1, (length + 31) // 32 + 1):
        block = mac(prk, block + b"WhisperGroup" + bytes([counter]))
        out += block
    return out[:length]


def varint(n):
    result = b""
    while n >= 128:
        result += bytes([(n & 127) | 128])
        n >>= 7
    return result + bytes([n])


def blob(field, value):
    return varint(field * 8 + 2) + varint(len(value)) + value


def uint(field, value):
    return varint(field * 8) + varint(value)


chain = initial = bytes(range(32))
seed0 = hkdf(mac(chain, b"\1"))
next0 = mac(chain, b"\2")
for iteration in range(4):
    seed = hkdf(mac(chain, b"\1"))
    chain = mac(chain, b"\2")
plaintext = b"\x0a\x12Independent group!\0\xff"
padder = PKCS7(128).padder()
padded = padder.update(plaintext) + padder.finalize()
encryptor = Cipher(algorithms.AES(seed[16:48]), modes.CBC(seed[:16])).encryptor()
ciphertext = encryptor.update(padded) + encryptor.finalize()
body = b"\x33" + uint(1, 714) + uint(2, 3) + blob(3, ciphertext)
key = Ed25519PrivateKey.from_private_bytes(bytes(range(32, 64)))
ed_public = key.public_key().public_bytes_raw()
y = int.from_bytes(ed_public, "little") & ((1 << 255) - 1)
p = (1 << 255) - 19
u = ((1 + y) * pow(1 - y, -1, p)) % p
public = b"\x05" + u.to_bytes(32, "little")
signature = bytearray(key.sign(body))
signature[-1] |= ed_public[-1] & 128
distribution = b"\x33" + uint(1, 714) + uint(2, 0) + blob(3, initial) + blob(4, public)
result = {
    "provenance": "Independent Python cryptography/OpenSSL Ed25519 + Edwards/Montgomery map and hashlib/hmac; test-only fixture",
    "chain": initial.hex(), "seed0": seed0.hex(), "next0": next0.hex(),
    "distribution": distribution.hex(), "message": (body + signature).hex(),
    "plaintext": plaintext.hex(),
}
Path(__file__).with_name("sender-key-vector.json").write_text(json.dumps(result, indent=2) + "\n", encoding="utf-8")
