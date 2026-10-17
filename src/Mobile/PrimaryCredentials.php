<?php
declare(strict_types=1);
namespace MVO\Mobile;

use MVO\Crypto\{Crypto,XEdDsa};
use MVO\Protocol\Jid;
use MVO\Signal\SignalState;

/** Validates primary credentials; it cannot turn a linked Web device into the primary. */
final class PrimaryCredentials
{
    /** Converts the reference's decoded binary credential object; base64/JSON decoding belongs to the trusted importer.
     * @param array<string,mixed> $source
     * @return array<string,mixed> */
    public static function fromReference(array $source):array
    {
        $registration=$source['registrationInfo']??null;$signed=$source['signedPreKey']??null;
        if(!is_array($registration) || !is_array($signed))throw new \InvalidArgumentException('Primary import requires registrationInfo and signedPreKey');
        $pair=static function(mixed $input):array {
            if(!is_array($input) || !is_string($input['privKey']??null) || !is_string($input['pubKey']??null))throw new \InvalidArgumentException('Imported keypair must contain binary privKey/pubKey');
            return ['private'=>$input['privKey'],'public'=>SignalState::rawPublicKey($input['pubKey'])];
        };
        $credentials=[...$source,'noiseKeyPair'=>$pair($source['noiseKeyPair']??null),'identityKeyPair'=>$pair($registration['identityKeyPair']??null),
            'registrationId'=>$registration['registrationId']??null,'signedPreKey'=>['id'=>$signed['keyId']??null,...$pair($signed['keyPair']??null),'signature'=>$signed['signature']??null],
            'registered'=>true,'loginCounter'=>$source['loginCounter']??0,'serverHasPreKeys'=>$source['serverHasPreKeys']??false];
        unset($credentials['registrationInfo']);self::validate($credentials);return $credentials;
    }
    /** @param array<string,mixed> $credentials
     * @param array<string,mixed>|null $deviceInfo */
    public static function validate(array $credentials,?array $deviceInfo=null):void
    {
        if(($credentials['registered']??false)!==true || !is_string($credentials['meJid']??null))throw new \InvalidArgumentException('Mobile transport requires registered primary credentials');
        $jid=Jid::parse($credentials['meJid'])->canonical();
        if($jid->device!==0 || $jid->server!=='s.whatsapp.net' || !preg_match('/^[1-9][0-9]{6,14}$/D',$jid->user))throw new \InvalidArgumentException('Mobile primary requires the numeric account JID with device zero');
        foreach(['noiseKeyPair','identityKeyPair','signedPreKey'] as $field) {
            $pair=$credentials[$field]??null;
            if(!is_array($pair) || !is_string($pair['private']??null) || !is_string($pair['public']??null))throw new \InvalidArgumentException('Mobile credentials missing '.$field);
            Crypto::length($pair['private'],32);Crypto::length($pair['public'],32);
            if(!hash_equals(sodium_crypto_scalarmult_base($pair['private']),$pair['public']))throw new \InvalidArgumentException('Mobile credentials have an inconsistent '.$field);
        }
        if(!is_string($credentials['advSecretKey']??null) || strlen($credentials['advSecretKey'])!==32)throw new \InvalidArgumentException('Mobile credentials require the ADV secret');
        $signed=$credentials['signedPreKey'];
        if(!is_int($signed['id']??null) || $signed['id']<1 || $signed['id']>0xffffff)throw new \InvalidArgumentException('Mobile signed prekey ID invalid');
        if(!is_string($signed['signature']??null) || !XEdDsa::verify($credentials['identityKeyPair']['public'],"\x05".$signed['public'],$signed['signature']))throw new \InvalidArgumentException('Mobile signed prekey authentication failed');
        if(!is_int($credentials['registrationId']??null) || $credentials['registrationId']<1 || $credentials['registrationId']>16380)throw new \InvalidArgumentException('Mobile credentials have an invalid registration ID');
        if($deviceInfo===null)return;
        foreach(['manufacturer','device','osVersion','osBuildNumber','appVersion'] as $field)if(!is_string($deviceInfo[$field]??null) || $deviceInfo[$field]==='')throw new \InvalidArgumentException('Mobile deviceInfo missing '.$field);
        if(!in_array($deviceInfo['os']??'android',['android','ios'],true))throw new \InvalidArgumentException('Mobile OS must be android or ios');
        if(!preg_match('/^[0-9]+(?:\.[0-9]+){3}$/D',$deviceInfo['appVersion']))throw new \InvalidArgumentException('Mobile version must contain four numeric components');
        foreach(explode('.',$deviceInfo['appVersion']) as $component)if(filter_var($component,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>0xffffffff]])===false)throw new \InvalidArgumentException('Mobile version component invalid');
        if(isset($deviceInfo['distributionChannel']) && !in_array($deviceInfo['distributionChannel'],['appstore','website','testflight','internal'],true))throw new \InvalidArgumentException('Unknown iOS distribution channel');
    }
}
