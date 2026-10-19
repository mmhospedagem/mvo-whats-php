# Third-party notices — MVO Messenger PHP

Original PHP code and integration work by MVO are covered by [LICENSE](LICENSE).
This package does not include the WhatsApp protocol, WAProto definitions, native
codecs or documentation fonts from the source
[MVO Whats PHP repository](https://github.com/mmhospedagem/mvo-whats-php).

## Adapted WebSocket transport

`src/Transport/WebSocket.php` adapts the project's MIT-licensed WhatsApp
WebSocket transport. The applicable original notice is retained below. The
adaptation has connector-specific origins/headers and does not load the
WhatsApp client or its credentials.

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

## Protocol references

DGW, Lightspeed, bootstrap and browser-password formats are implemented in PHP
from observed protocol contracts. `DgwCodec` records a layout comparison with
`mautrix/meta` revision `1b3bd5d`, `pkg/messagix/dgw/frames.go`; that reference
does not bundle its Go source or runtime. Public Facebook JavaScript used for
format inspection is not redistributed in this package. Facebook and Messenger
names and related rights remain with their respective holders. This library is
an independent experimental implementation, without an affiliation claim.

## PHP and development dependencies

PHP extensions and Composer development packages retain their respective
licenses. Their binaries/source distributions are not bundled here, and
`vendor/` is excluded from this source package.
