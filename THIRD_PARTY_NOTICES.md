# Avisos de terceiros — MVO PHP

## Atualização das definições Protobuf

`resources/WAProto.proto` e seu descriptor gerado incorporam definições extraídas
pelo WPPConnect Team, copyright (c) 2022, sob Apache License 2.0. O snapshot de
origem é o commit `ab2a7ce8147d39d6a0ca42cfb59565565699d771` de
[wppconnect-team/wa-proto](https://github.com/wppconnect-team/wa-proto).
A licença acompanha o pacote em
[resources/licenses/wa-proto-Apache-2.0.txt](resources/licenses/wa-proto-Apache-2.0.txt).

As adaptações MVO conferem os módulos públicos atuais, preservam declarações
históricas e o campo `Message#117`, atualizam a identificação do build e geram
o descriptor para PHP. Os avisos das declarações anteriores continuam abaixo;
essa atualização não altera suas licenças. A proveniência, hashes e limites da
comparação constam em `resources/WAProto.metadata.json`.

Este arquivo reúne os avisos que acompanham as partes adaptadas, os dados e os
componentes de terceiros utilizados pelo projeto. As contribuições e a integração
MVO estão identificadas em [LICENSE](LICENSE). A apresentação da marca MVO não
altera os direitos dos titulares indicados abaixo.

## Código e dados do protocolo

As partes adaptadas em `src/` e `resources/`, incluindo tabelas, schemas,
sinalização/mídia e catálogos de configuração, e os fixtures de protocolo em
`tests/fixtures/`, conservam o seguinte aviso MIT original:

MIT License

Copyright (c) 2026 vinikjkkj <contact@vinicius.email>

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

## Compatibilidade das mensagens interativas

As partes adaptadas de `src/Message/InteractiveStanza.php`, dos envelopes de
contexto para os aparelhos da conta e do comportamento associado conservam o
seguinte aviso original:

MIT License

Copyright (c) 2025 Rajeh Taher/WhiskeySockets

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

## Leitor e decodificador de estruturas

As partes adaptadas de `src/Protocol/ArgoReader.php` e
`src/Protocol/ArgoDecoder.php` conservam o seguinte aviso original:

MIT License

Copyright (c) 2026 vinicius (https://github.com/vinikjkkj)

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

## Registro de telemetria

`src/Telemetry/wam-registry.json` foi adaptado de um registro publicado com a
declaração de licença MIT e autor vinikjkkj, versão
`2.3000.1041713829-1ec0d3b`. A distribuição consultada não incluía arquivo LICENSE
separado; esta atribuição e a declaração de licença vêm dos metadados publicados.
Os termos MIT acima são preservados junto a esses dados.

## Dependências instaladas pelo Composer

Os pacotes instalados pelo Composer mantêm seus arquivos de licença em `vendor/`.
O pacote desta biblioteca não incorpora essa pasta; os termos de cada dependência
acompanham a instalação correspondente.

## Optional native WebRTC dependencies

`tools/setup-voip.ps1` builds the C bridge against these pinned upstream sources:

- [libdatachannel v0.24.6](https://github.com/paullouisageneau/libdatachannel/tree/v0.24.6), revision `6b1e2e620f1e37f0eafeee702eaea0043cb305fd`, Mozilla Public License 2.0.
- [Mbed TLS 3.6.7](https://github.com/Mbed-TLS/mbedtls/tree/mbedtls-3.6.7), revision `068ff080b369adfac81509f9b57b2afabaf82dc5`, offered by upstream under Apache-2.0 OR GPL-2.0-or-later.
- The libdatachannel checkout's pinned libjuice (MPL 2.0), plog (MIT, Copyright (c) 2022 Sergey Podobry), usrsctp (BSD-style, Copyright (c) 2015 Randall Stewart and Michael Tuexen) and nlohmann/json (MIT, Copyright (c) 2013-2025 Niels Lohmann) dependencies.

The installer retains their full licenses in `.dev/native/bin/licenses`, alongside a build manifest recording source revisions and binary hashes. The build tools Zig, CMake and Ninja are optional development tools, not JavaScript runtime dependencies.

## Optional native Opus and MLow codecs

`tools/setup-codecs.ps1` installs the MSYS2 UCRT64 Opus 1.6.1 package, SHA-256 `7b8f176f9cc0431b52006861c0a2e083d73d4b47a73ab749755b5379ff673252`. Its full `COPYING` notice is retained in `.dev/native/ucrt64/share/licenses/opus/COPYING`.

The same script builds [edgardmessias/opus_mlow v1.0.1](https://github.com/edgardmessias/opus_mlow/releases/tag/v1.0.1), source archive SHA-256 `2b730f9ccbc13b02b9360e889079ef2ca831cbf181fa8cb2dae634f532f76ca0`. Its `COPYING` is retained in `.dev/native/codecs/MLow-COPYING.txt`.

API validation reference: MIT, Copyright (c) 2026 openclaw. The installed codec retains its own COPYING notice.

Both Opus packages include these redistribution conditions, reproduced from their `COPYING` notices:

Copyright 2001-2023 Xiph.Org, Skype Limited, Octasic,
Jean-Marc Valin, Timothy B. Terriberry, CSIRO, Gregory Maxwell,
Mark Borgerding, Erik de Castro Lopo, Mozilla, Amazon.
The opus_mlow fork's notice lists the corresponding 2001-2011 attribution.

Redistribution and use in source and binary forms, with or without
modification, are permitted provided that the following conditions
are met:

- Redistributions of source code must retain the above copyright
  notice, this list of conditions and the following disclaimer.
- Redistributions in binary form must reproduce the above copyright
  notice, this list of conditions and the following disclaimer in the
  documentation and/or other materials provided with the distribution.
- Neither the name of Internet Society, IETF or IETF Trust, nor the
  names of specific contributors, may be used to endorse or promote
  products derived from this software without specific prior written
  permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
``AS IS'' AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER
OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL,
EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO,
PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR
PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF
LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING
NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.

The original notices also preserve links to the Opus patent declarations:
[Xiph.Org](https://datatracker.ietf.org/ipr/1524/),
[Microsoft](https://datatracker.ietf.org/ipr/1914/), and
[Broadcom](https://datatracker.ietf.org/ipr/1526/).

## Optional native Companion post-filter

`tools/setup-companion.ps1` builds a separate native backend from
[libmlow-wasm revision 0f50232f5b3667da3c3d8208cdd5ef4c8d48f390](https://github.com/vinikjkkj/libmlow-wasm/tree/0f50232f5b3667da3c3d8208cdd5ef4c8d48f390),
source archive SHA-256 `3668aa86290e74e47d4384f79c829b0d4f033f346551c8c24dcc2b257e0c59a3`.
Its native Companion code and the four ABI adapters in `native/companion_bridge.c`
are licensed under the following MIT notice. The installer also preserves the full
source `LICENSE`, `THIRD_PARTY_NOTICES.md` and the separately licensed opus_mlow
`COPYING` in `.dev/native/codecs/licenses`, alongside `companion-build.json`.
The decoder hook patch is applied to a separate opus_mlow 1.0.1 build; the default
codec is not replaced. No neural model weights are included or relicensed.

MIT License

Copyright (c) 2026 openclaw

Permission is hereby granted, free of charge, to any person obtaining a copy
of this software and associated documentation files (the "Software"), to deal
in the Software without restriction, including without limitation the rights
to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
copies of the Software, and to permit persons to whom the Software is
furnished to do so, subject to the following conditions:

The above copyright notice and this permission notice shall be included in all
copies or substantial portions of the Software.

THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
SOFTWARE.

## Optional FFmpeg tools

`tools/setup-av.ps1` installs FFmpeg/ffplay/ffprobe 9.0.2 Essentials from [Gyan](https://www.gyan.dev/ffmpeg/builds/), a Windows binary distributor listed on the [official FFmpeg download page](https://ffmpeg.org/download.html). The archive SHA-256 is `60f467265b1e312373dbcd92200c2618a74850f98d3d078e94296bb3fa2047ba`.

That executable distribution is GPLv3 and includes additional codec/library notices. Its complete `LICENSE`, `README.txt`, documentation and presets remain inside `.dev/native/ffmpeg/ffmpeg-9.0.2-essentials_build`; the installer also writes `av-install.json`. These optional executables run as separate native processes. Neither installing them nor loading the PHP library starts hardware capture, playback or a WhatsApp call.

## Manrope documentation fonts

The offline documentation includes unmodified Manrope TTF files in `docs/assets/fonts/` at weights 400, 600 and 800, distributed by the public [MVO site](https://mvo.host/assets/addons/mvo-site/homepage-next.css?v=20260912-1). Their original family names, copyright and license metadata are retained.

Copyright 2019 The Manrope Project Authors (https://github.com/sharanda/manrope), as embedded in the distributed TTF files. The official Google Fonts copy of OFL.txt also retains its supplied Copyright 2018 notice for the Manrope Project Authors (https://github.com/googlefonts/manrope).

These font files are licensed under the SIL Open Font License, Version 1.1. The complete license is included in [docs/assets/fonts/OFL.txt](docs/assets/fonts/OFL.txt), obtained from the [official Google Fonts Manrope directory](https://raw.githubusercontent.com/google/fonts/16c2976c68c3137c09c69c8e9d3deada699d157e/ofl/manrope/OFL.txt). The fonts remain under OFL-1.1; this notice does not relicense them under the PHP library's MIT license. The download URLs, immutable license revision, file sizes and SHA-256 hashes are recorded in `docs/assets/fonts/PROVENANCE.txt`.
