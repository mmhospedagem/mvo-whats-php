<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

/** Private native-application authorization, distinct from a consumer API key.
 * No Meta application credentials or attestation keys are bundled in the SDK.
 */
interface AndroidLoginProfile
{
    public function origin():string;
    public function passwordVersion():int;
    public function keyFetchFlow():string;

    /** Adds the application's verified authorization to an already built request.
     * Implementations must not change the account, device or credential fields.
     * @param array<string,string> $form
     * @return array{form:array<string,string>,headers:array<string,string>}
     */
    public function authorize(string $method,string $path,#[\SensitiveParameter] array $form):array;
}
