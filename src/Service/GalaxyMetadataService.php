<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Protocol\Jid;

/**
 * Read-only metadata for a remotely registered Galaxy form. WaClient uses the HTTP catalog.
 *
 * Reference: WAWebGalaxyFlowsDrawerGetFlowDataQuery.graphql (public Web capture 2026-10-15),
 * document 32671434985780838; WAWebGraphQLPersistedQueries maps its client ID to
 * 24989855014035777 (also present in the public Web capture of 2026-10-22).
 * These pinned IDs do not establish current server availability or permission to manage a form.
 */
final class GalaxyMetadataService
{
    private const DOCUMENT_ID = '32671434985780838';
    private const CLIENT_DOCUMENT_ID = '24989855014035777';

    /** MexService remains an explicit legacy integration; WaClient never falls back to it. */
    public function __construct(private readonly MexService|BusinessFormCatalogHttpTransport $mex) {}

    /**
     * Return the original GraphQL data, including metadata, signatures and unknown fields.
     *
     * Pass the business's PN or LID user JID and its real remote form ID. This read does not
     * create a form, resolve a LID to a phone number, fetch arbitrary URLs or load inline JSON.
     * A null result remains null; IQ/GraphQL refusals propagate as ServiceException.
     * Public-key signatures are returned as received, not declared verified by this method.
     *
     * @return array<string,mixed>|null
     */
    public function getFlowData(string $businessJid, string $flowId, float $timeout = 32.0): ?array
    {
        $jid = Jid::parse($businessJid);
        if ($jid->device !== 0 || !in_array($jid->server, ['s.whatsapp.net', 'lid'], true) || !ctype_digit($jid->user)) {
            throw new \InvalidArgumentException('Galaxy metadata requires a bare business PN or LID JID');
        }
        if ($flowId === '' || !ctype_digit($flowId) || strlen($flowId) > 64) {
            throw new \InvalidArgumentException('flow_id must be a numeric string of at most 64 digits');
        }
        if (!is_finite($timeout) || $timeout <= 0) {
            throw new \InvalidArgumentException('Galaxy metadata timeout must be finite and positive');
        }

        if ($this->mex instanceof BusinessFormCatalogHttpTransport) return $this->mex->getFlowData($businessJid, $flowId, $timeout);

        return $this->mex->dispatch(
            self::DOCUMENT_ID,
            self::CLIENT_DOCUMENT_ID,
            ['request' => ['extensions' => ['biz_jid' => $businessJid, 'flow_id' => $flowId]]],
            'galaxy.getFlowData',
            $timeout,
        );
    }
}
