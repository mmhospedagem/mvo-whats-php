"""Independent Python cryptography fixture for WAProto addon encryption."""
from pathlib import Path
import json
from cryptography.hazmat.primitives.hashes import SHA256
from cryptography.hazmat.primitives.kdf.hkdf import HKDF
from cryptography.hazmat.primitives.ciphers.aead import AESGCM

root = Path(__file__).resolve().parents[1]
secret = bytes([9]) * 32
iv = bytes([7]) * 12
parent = 'PARENT-123'
author = '551100000000@s.whatsapp.net'
sender = '551188888888@s.whatsapp.net'
kind = 'Poll Vote'
plaintext = b'\x0a\x20' + bytes(range(32))
info = (parent + author + sender + kind).encode()
key = HKDF(algorithm=SHA256(), length=32, salt=None, info=info).derive(secret)
ciphertext = AESGCM(key).encrypt(iv, plaintext, (parent+'\0'+sender).encode())
data = dict(secret=secret.hex(),iv=iv.hex(),parentId=parent,author=author,sender=sender,
            type=kind,plaintext=plaintext.hex(),key=key.hex(),ciphertext=ciphertext.hex())
(root/'tests/fixtures/addon-crypto.json').write_text(json.dumps(data, indent=2)+'\n', encoding='utf8')
print('Generated independent addon-crypto.json fixture')
