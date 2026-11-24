<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

/** A continuation that preserves every account identity already known to it.
 * A first server checkpoint can lack a UID; only independently verified HTTPS
 * browser identity may establish that first binding, through the owning client.
 */
interface BrowserIdentityBoundLoginService extends LoginService
{
    /** Reject a browser identity conflicting with the saved/known attempt UID.
     * Acceptance alone is not authentication: independent HTTPS verification
     * and the session owner's atomic account binding must still succeed.
     */
    public function assertBrowserIdentity(string $accountId):void;
}
