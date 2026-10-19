<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee\Protocol;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\BootstrapConfig;

/** MAWAppVersion in the pinned first-party public artifact: 302.0.SiteData.client_revision. */
final class BlueWebVersion
{
    public const ARTIFACT_SHA256='2723f035edbfb9672564da69844951ae8eec172181af86fe7e21c002b34906aa';

    /** The revision comes from the current private bootstrap, never a saved example or LS version.
     * @return array{302,0,int}
     */
    public static function fromBootstrap(BootstrapConfig $bootstrap):array
    {
        $site=$bootstrap->module('SiteData');$revision=is_array($site)?($site['client_revision']??null):null;
        if(!(is_int($revision)||(is_string($revision)&&preg_match('/^[1-9][0-9]{0,9}$/D',$revision)===1))
            ||(int)$revision<1||(int)$revision>0xffffffff)throw new MessengerException('messenger_blue_web_version_unavailable','The current bootstrap does not contain the SiteData revision required by the observed MAWAppVersion profile');
        return [302,0,(int)$revision];
    }
}
