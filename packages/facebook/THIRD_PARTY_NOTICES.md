# Third-party notices — MVO Facebook PHP

Original PHP code and integration work by MVO are covered by [LICENSE](LICENSE).
This package does not include the WhatsApp runtime, WAProto definitions, native
codecs, documentation fonts or other separately licensed assets from the source
[MVO Whats PHP repository](https://github.com/mmhospedagem/mvo-whats-php).

## Public protocol references and catalog provenance

The files in `resources/` record persisted Web document identifiers, variable
contracts, public source URLs and SHA-256 hashes observed in Facebook's publicly
served JavaScript. The source URLs and hashes are provenance references, not
bundled copies of those JavaScript files or a grant to redistribute them. The
PHP implementation and synthetic tests do not redistribute Facebook bundles.

Incremental response handling was also checked against the public
[Relay OperationExecutor](https://github.com/facebook/relay/blob/main/packages/relay-runtime/store/OperationExecutor.js).
Relay source code is not bundled in this package. Facebook, Messenger and Relay
names and related rights remain with their respective holders. This library is
an independent experimental implementation, without an affiliation claim.

## Composer dependencies

`mmhospedagem/mvo-messenger-php` is installed separately by Composer. Its MIT
license and applicable adapted-transport notices accompany that package. Other
development dependencies retain the licenses supplied in their distributions.
This source package does not bundle `vendor/`.
