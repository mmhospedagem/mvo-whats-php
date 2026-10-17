from pathlib import Path
import json
from cryptography.hazmat.primitives.kdf.hkdf import HKDF
from cryptography.hazmat.primitives import hashes,hmac,padding
from cryptography.hazmat.primitives.ciphers import Cipher,algorithms,modes
key=bytes(range(32)); plain=b'PHP native WhatsApp media\x00\xff'
k=HKDF(algorithm=hashes.SHA256(),length=112,salt=None,info=b'WhatsApp Image Keys').derive(key)
p=padding.PKCS7(128).padder(); padded=p.update(plain)+p.finalize()
e=Cipher(algorithms.AES(k[16:48]),modes.CBC(k[:16])).encryptor(); cipher=e.update(padded)+e.finalize()
h=hmac.HMAC(k[48:80],hashes.SHA256()); h.update(k[:16]+cipher); body=cipher+h.finalize()[:10]
Path('tests/media-vector.json').write_text(json.dumps({'key':key.hex(),'plain':plain.hex(),'body':body.hex(),'source':'Independent Python cryptography HKDF/AES-CBC/HMAC implementation'},indent=2))
