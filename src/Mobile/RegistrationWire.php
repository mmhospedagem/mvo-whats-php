<?php
declare(strict_types=1);
namespace MVO\Mobile;

use MVO\Crypto\Crypto;

/** Android registration parameter encoding. Secrets are consumed only by the HTTPS transport. */
final class RegistrationWire
{
    /** Public X25519 server key extracted from the supported Android registration artifact. */
    public const SERVER_PUBLIC_KEY_HEX='8e8c0f74c3ebc5d7a6865c6c3c843856b06121cce8ea774d22fb6f122512302d';
    /** @param array<string,mixed> $credentials
     * @param array<string,mixed> $record
     * @param array<string,mixed> $input
     * @return array<string,string> */
    public static function parameters(array $credentials,array $record,array $input):array
    {
        $info=$record['deviceInfo'];if(($info['os']??'android')!=='android')throw new \InvalidArgumentException('Mobile registration currently supports Android');
        $fields=['cc'=>$record['cc'],'in'=>$record['number'],'lg'=>$info['localeLanguageIso6391']??'en','lc'=>$info['localeCountryIso31661Alpha2']??'US','fdid'=>$info['phoneId'],'method'=>$record['method'],
            'expid'=>self::base64url($record['experimentId']),'access_session_id'=>self::base64url($record['accessSessionId']),'id'=>$record['id'],'backup_token'=>$record['backupToken'],
            'authkey'=>self::base64url($credentials['noiseKeyPair']['public']),'e_ident'=>self::base64url($credentials['identityKeyPair']['public']),
            'e_keytype'=>self::base64url("\x05"),'e_regid'=>self::base64url(pack('N',$credentials['registrationId'])),
            'e_skey_id'=>self::base64url(substr(pack('N',$credentials['signedPreKey']['id']),1)),'e_skey_val'=>self::base64url($credentials['signedPreKey']['public']),'e_skey_sig'=>self::base64url($credentials['signedPreKey']['signature'])];
        if(($record['lastOperation']??'code')==='code'){
            $fields['token']=RegistrationProfile::token($record['number'],$info);
            foreach(['clicked_education_link','manage_call_permission','call_log_permission'] as$key)$fields[$key]='false';
        }
        if(($record['lastOperation']??null)==='security')unset($fields['method']);
        return $fields;
    }
    public static function base64url(string $bytes):string {return rtrim(strtr(base64_encode($bytes),'+/','-_'),'=');}
    /** A new ephemeral key is required for each request because the official envelope uses a zero nonce.
     * @param array<string,string> $parameters */
    public static function seal(array $parameters,?string $ephemeralPrivate=null,?string $serverPublic=null):string
    {
        $ephemeralPrivate??=random_bytes(32);$serverPublic??=hex2bin(self::SERVER_PUBLIC_KEY_HEX);Crypto::length($ephemeralPrivate,32);Crypto::length($serverPublic,32);
        $public=sodium_crypto_scalarmult_base($ephemeralPrivate);$shared=sodium_crypto_scalarmult($ephemeralPrivate,$serverPublic);
        return self::base64url($public.Crypto::encryptGcm($shared,str_repeat("\0",12),self::form($parameters)));
    }
    /** id and backup_token are raw binary values; encode exactly once.
     * @param array<string,string> $parameters */
    public static function form(array $parameters):string
    {
        $out=[];foreach($parameters as$key=>$value){if(!is_string($key) || !is_string($value))throw new \InvalidArgumentException('Invalid registration form parameter');$out[]=rawurlencode($key).'='.rawurlencode($value);}return implode('&',$out);
    }
}
