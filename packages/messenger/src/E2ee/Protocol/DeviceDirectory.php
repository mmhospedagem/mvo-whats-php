<?php
declare(strict_types=1);
namespace MVO\Messenger\E2ee\Protocol;

/** Strict IQ projections. Caller obtains responses from certificate-authenticated Noise, never Web metadata. */
final class DeviceDirectory
{
    /** Keys must already be durable in SignalStore. Match the IQ response before marking them uploaded.
     * @param array<mixed> $pending One-time public keys only: {id:int,public:32 bytes}.
     */
    public static function uploadPrekeys(string $requestId,\MVO\Messenger\E2ee\DeviceKeys $keys,array $pending):BinaryNode
    {
        self::id($requestId);if(!array_is_list($pending)||count($pending)<1||count($pending)>1000)throw new \InvalidArgumentException('Use 1 to 1000 saved Messenger prekeys');$list=[];$seen=[];
        foreach($pending as$key){if(!is_array($key)||!is_int($key['id']??null)||$key['id']<1||$key['id']>0xffffff||isset($seen[$key['id']])||!is_string($key['public']??null)||strlen($key['public'])!==32)throw new \InvalidArgumentException('Use valid distinct saved public prekeys');$seen[$key['id']]=true;$list[]=new BinaryNode('key',[],[new BinaryNode('id',[],substr(pack('N',$key['id']),1)),new BinaryNode('value',[],$key['public'])]);}
        $credentials=$keys->signalCredentials();$signed=$credentials['signedPreKey'];
        return new BinaryNode('iq',['id'=>$requestId,'type'=>'set','to'=>'s.whatsapp.net','xmlns'=>'encrypt'],[new BinaryNode('registration',[],pack('N',$keys->registrationId)),new BinaryNode('type',[],"\x05"),new BinaryNode('identity',[],$keys->identityPublic()),new BinaryNode('list',[],$list),new BinaryNode('skey',[],[new BinaryNode('id',[],substr(pack('N',$signed['id']),1)),new BinaryNode('value',[],$signed['public']),new BinaryNode('signature',[],$signed['signature'])])]);
    }
    /** @param list<string> $users */
    public static function discovery(string $requestId,array $users):BinaryNode
    {
        self::id($requestId);if(!array_is_list($users)||count($users)<1||count($users)>15||count(array_unique($users))!==count($users))throw new \InvalidArgumentException('Query 1 to 15 distinct Messenger users');$children=[];
        foreach($users as$user){if(Address::bare($user)!==$user)throw new \InvalidArgumentException('Discovery requires bare Messenger users');$children[]=new BinaryNode('user',['jid'=>$user]);}
        return new BinaryNode('iq',['id'=>$requestId,'type'=>'get','to'=>'s.whatsapp.net','xmlns'=>'fbid:devices'],[new BinaryNode('users',[],$children)]);
    }
    /** @param list<string> $expectedUsers
     * @return array<string,array{devices:list<string>,dhash:string,remote_identity_trust_verified:bool}>
     */
    public static function devices(BinaryNode $result,string $requestId,array $expectedUsers):array
    {
        self::result($result,$requestId);$users=self::child($result,'users');$out=[];
        foreach(self::children($users,15)as$user){if($user->tag!=='user')throw new \UnexpectedValueException('Unexpected Messenger user projection');$bare=Address::bare($user->attrs['jid']??'');if(!in_array($bare,$expectedUsers,true)||isset($out[$bare]))throw new \UnexpectedValueException('Messenger discovery scope mismatch');$list=self::child($user,'devices');$hash=$list->attrs['dhash']??'';if($hash===''||strlen($hash)>1024)throw new \UnexpectedValueException('Missing Messenger device-list hash');$devices=[];
            foreach(self::children($list,256)as$device){if($device->tag!=='device')continue;$id=$device->attrs['id']??'';if(preg_match('/^(0|[1-9][0-9]{0,4})$/D',$id)!==1||(int)$id>65535)throw new \UnexpectedValueException('Invalid Messenger remote device ID');$address=Address::device(substr($bare,0,-5).':'.$id.'@msgr');if(in_array($address,$devices,true))throw new \UnexpectedValueException('Repeated Messenger device');$devices[]=$address;}
            $out[$bare]=['devices'=>$devices,'dhash'=>$hash,'remote_identity_trust_verified'=>false];
        }
        if(count($out)!==count($expectedUsers))throw new \UnexpectedValueException('Incomplete Messenger device discovery');return $out;
    }
    /** @param list<string> $devices */
    public static function prekeys(string $requestId,array $devices):BinaryNode
    {
        self::id($requestId);if(!array_is_list($devices)||count($devices)<1||count($devices)>256)throw new \InvalidArgumentException('Use a bounded Messenger device list');$children=[];$seen=[];
        foreach($devices as$device){$device=Address::device($device);if(isset($seen[$device]))throw new \InvalidArgumentException('Do not repeat a Messenger device');$seen[$device]=true;$children[]=new BinaryNode('user',['jid'=>$device,'reason'=>'identity']);}
        return new BinaryNode('iq',['id'=>$requestId,'type'=>'get','to'=>'s.whatsapp.net','xmlns'=>'encrypt'],[new BinaryNode('key',[],$children)]);
    }
    /** @param list<string> $expectedDevices
     * @return array<string,array{registrationId:int,identityKey:string,signedPreKey:array{id:int,public:string,signature:string},preKey?:array{id:int,public:string}}>
     */
    public static function bundles(BinaryNode $result,string $requestId,array $expectedDevices):array
    {
        self::result($result,$requestId);$out=[];
        foreach(self::children(self::child($result,'list'),256)as$user){if($user->tag!=='user')throw new \UnexpectedValueException('Invalid Messenger prekey projection');$device=Address::device($user->attrs['jid']??'');if(!in_array($device,$expectedDevices,true)||isset($out[$device]))throw new \UnexpectedValueException('Messenger prekey scope mismatch');$signed=self::child($user,'skey');
            $bundle=['registrationId'=>unpack('N',self::bytes(self::child($user,'registration'),4))[1],'identityKey'=>self::bytes(self::child($user,'identity'),32),
                'signedPreKey'=>['id'=>self::keyId(self::child($signed,'id')),'public'=>self::bytes(self::child($signed,'value'),32),'signature'=>self::bytes(self::child($signed,'signature'),64)]];
            $key=$user->child('key');if($key!==null)$bundle['preKey']=['id'=>self::keyId(self::child($key,'id')),'public'=>self::bytes(self::child($key,'value'),32)];$out[$device]=$bundle;
        }
        if(count($out)!==count($expectedDevices))throw new \UnexpectedValueException('Incomplete Messenger prekey bundles');return $out;
    }
    public static function result(BinaryNode $node,string $requestId):void{self::id($requestId);if($node->tag!=='iq'||($node->attrs['type']??null)!=='result'||($node->attrs['id']??null)!==$requestId||$node->child('error')!==null)throw new \UnexpectedValueException('Unconfirmed Messenger IQ response');}
    private static function id(string $id):void{if($id===''||strlen($id)>128||preg_match('/[\x00-\x20\x7f]/',$id))throw new \InvalidArgumentException('Invalid Messenger request ID');}
    private static function child(BinaryNode $node,string $tag):BinaryNode{$matches=[];foreach(self::children($node,1024)as$child)if($child->tag===$tag)$matches[]=$child;if(count($matches)!==1)throw new \UnexpectedValueException('Missing or ambiguous Messenger IQ child');return $matches[0];}
    /** @return list<BinaryNode> */
    private static function children(BinaryNode $node,int $limit):array{if(!is_array($node->content)||count($node->content)>$limit)throw new \UnexpectedValueException('Invalid Messenger IQ collection');return $node->content;}
    private static function bytes(BinaryNode $node,int $length):string{if(!is_string($node->content)||strlen($node->content)!==$length)throw new \UnexpectedValueException('Invalid Messenger prekey bytes');return $node->content;}
    private static function keyId(BinaryNode $node):int{$id=unpack('N',"\0".self::bytes($node,3))[1];if($id<1)throw new \UnexpectedValueException('Invalid Messenger prekey ID');return $id;}
}
