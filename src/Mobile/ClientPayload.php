<?php
declare(strict_types=1);
namespace MVO\Mobile;

use MVO\Protocol\{Jid,SchemaCodec};

/** Login payload for an already registered mobile primary, never an SMS/registration implementation. */
final class ClientPayload
{
    /** @param array<string,mixed> $credentials
     * @param array<string,mixed> $deviceInfo
     * @param array<string,mixed> $options */
    public static function login(array $credentials,array $deviceInfo,array $options=[]):string
    {
        PrimaryCredentials::validate($credentials,$deviceInfo);
        $jid=Jid::parse($credentials['meJid']);$ios=($deviceInfo['os']??'android')==='ios';$business=($deviceInfo['business']??false)===true;
        $version=[];$names=['primary','secondary','tertiary','quaternary'];
        foreach(explode('.',$deviceInfo['appVersion']) as $i=>$part)$version[$names[$i]]=(int)$part;
        $agent=['platform'=>$ios?($business?'SMB_IOS':'IOS'):($business?'SMB_ANDROID':'ANDROID'),'releaseChannel'=>'RELEASE','appVersion'=>$version,
            'osVersion'=>$deviceInfo['osVersion'],'manufacturer'=>$deviceInfo['manufacturer'],'device'=>$deviceInfo['device'],'osBuildNumber'=>$deviceInfo['osBuildNumber'],
            'phoneId'=>$deviceInfo['phoneId']??self::uuid(),'localeLanguageIso6391'=>$deviceInfo['localeLanguageIso6391']??'en','localeCountryIso31661Alpha2'=>$deviceInfo['localeCountryIso31661Alpha2']??'US','deviceType'=>'PHONE'];
        if(isset($deviceInfo['deviceModelType']))$agent['deviceModelType']=$deviceInfo['deviceModelType'];
        if($ios)$agent['distributionChannel']=strtoupper($deviceInfo['distributionChannel']??'appstore');
        else{ $agent['mcc']=$deviceInfo['mcc']??'000';$agent['mnc']=$deviceInfo['mnc']??'000';if(isset($deviceInfo['deviceBoard']))$agent['deviceBoard']=$deviceInfo['deviceBoard']; }
        $payload=['username'=>$jid->user,'device'=>0,'passive'=>($options['passive']??false)===true,'pull'=>($options['pull']??true)===true,'product'=>'WHATSAPP',
            'connectType'=>$options['connectType']??'CELLULAR_UNKNOWN','connectReason'=>$options['connectReason']??'USER_ACTIVATED','userAgent'=>$agent,'lidDbMigrated'=>($credentials['lidDbMigrated']??false)===true];
        foreach(['pushName','yearClass','memClass'] as $field)if(isset($options[$field]) || isset($credentials[$field]))$payload[$field]=$options[$field]??$credentials[$field];
        return SchemaCodec::encode('ClientPayload',$payload);
    }
    private static function uuid():string { $b=random_bytes(16);$b[6]=chr((ord($b[6])&15)|64);$b[8]=chr((ord($b[8])&63)|128);$h=bin2hex($b);return substr($h,0,8).'-'.substr($h,8,4).'-'.substr($h,12,4).'-'.substr($h,16,4).'-'.substr($h,20); }
}
