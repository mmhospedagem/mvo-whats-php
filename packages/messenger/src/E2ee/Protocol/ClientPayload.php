<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee\Protocol;
use MVO\Messenger\E2ee\{CryptoAuthToken,DeviceKeys,ProtoWire};

/** Version is supplied from a verified current profile; never hardcodes reference appVersion 301.0.2. */
final class ClientPayload
{
    /** @param array<mixed> $appVersion Current BLUE_WEB Messenger version, validated as 3 to 5 integers. */
    public static function login(DeviceKeys $keys,int $registeredDeviceId,CryptoAuthToken $token,array $appVersion,string $browserAgent):string
    {
        if($registeredDeviceId<1||$registeredDeviceId>65535)throw new \InvalidArgumentException('Use the server-confirmed Messenger device ID');
        if(!array_is_list($appVersion)||count($appVersion)<3||count($appVersion)>5)throw new \InvalidArgumentException('Supply the verified current Messenger BLUE_WEB version');
        $version='';foreach($appVersion as$i=>$part){if(!is_int($part)||$part<0||$part>0xffffffff)throw new \InvalidArgumentException('Invalid Messenger appVersion');$version.=ProtoWire::integer($i+1,$part);}
        if($appVersion[0]===0||$browserAgent===''||strlen($browserAgent)>2048||preg_match('/[\x00-\x1f\x7f]/',$browserAgent))throw new \InvalidArgumentException('Invalid current Messenger user agent');
        $id=$keys->accountId;if(strlen($id)>strlen((string)PHP_INT_MAX)||(strlen($id)===strlen((string)PHP_INT_MAX)&&strcmp($id,(string)PHP_INT_MAX)>0))throw new \OverflowException('Messenger account exceeds supported integer encoding');
        $cat=$token->privateForm($id,$keys->uuid);
        return ProtoWire::integer(1,(int)$id).ProtoWire::integer(3,0).ProtoWire::bytes(5,ProtoWire::integer(1,32).ProtoWire::bytes(2,$version))
            .ProtoWire::integer(12,1).ProtoWire::integer(13,1).ProtoWire::integer(18,$registeredDeviceId).ProtoWire::integer(20,1)
            .ProtoWire::bytes(21,$cat['fb_cat']).ProtoWire::bytes(22,$browserAgent).ProtoWire::integer(33,1);
    }
}
