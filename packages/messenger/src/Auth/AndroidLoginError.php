<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

use MVO\Messenger\MessengerException;

/** Native API errors, kept separate from HTTP status and public diagnostics. */
final class AndroidLoginError
{
    /** @param array<string,mixed> $context */
    private function __construct(public readonly int $code, private readonly array $context) {}

    public static function fromJson(#[\SensitiveParameter] string $json): ?self
    {
        if (strlen($json)>2097152) throw self::invalid();
        try { $root=json_decode($json,true,32,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); }
        catch (\JsonException) { throw self::invalid(); }
        if (!is_array($root)||array_is_list($root)) throw self::invalid();
        if (array_key_exists('error',$root)) {
            $error=$root['error'];
            if (!is_array($error)||array_is_list($error)) throw self::invalid();
            $code=$error['code']??null;
        } elseif (array_key_exists('error_code',$root)) {
            $error=$root;$code=$root['error_code'];
        } else return null;
        if (is_string($code)&&preg_match('/^[0-9]{1,10}$/D',$code)===1) $code=(int)$code;
        if (!is_int($code)||$code<0||$code>2147483647) throw self::invalid();
        $data=$error['error_data']??[];
        if (is_string($data)) {
            if (strlen($data)>65536) throw self::invalid();
            try { $data=json_decode($data,true,16,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); }
            catch (\JsonException) { throw self::invalid(); }
        }
        if (!is_array($data)||($data!==[]&&array_is_list($data))) throw self::invalid();
        // Never retain server messages, recovery contacts or unrelated tokens.
        $context=array_intersect_key($data,array_flip(['uid','machine_id','login_first_factor','url','flow_id']));
        // The remote-approval callback reads auth_token, not login_first_factor.
        // Keep it only for a native406 and only as bounded opaque private data.
        if($code===406&&self::opaque($data['auth_token']??null,16384))$context['auth_token']=$data['auth_token'];
        return new self($code,$context);
    }

    /** Explicit private request context, never a public challenge projection.
     * @return array{uid:string,first_factor:string,machine_id:?string}|null
     */
    public function twoFactorContextPrivate():?array
    {
        if($this->code!==406)return null;
        $uid=$this->context['uid']??null;if(is_int($uid))$uid=(string)$uid;
        $factor=$this->context['login_first_factor']??null;
        $machine=$this->context['machine_id']??null;
        if($machine==='')$machine=null;
        if(!is_string($uid)||preg_match('/^[1-9][0-9]{0,24}$/D',$uid)!==1||!self::opaque($factor,16384)
            ||($machine!==null&&!self::opaque($machine,4096)))return null;
        return ['uid'=>$uid,'first_factor'=>$factor,'machine_id'=>$machine];
    }

    /** Private native406 approval context, separate from code submission.
     * The nullable machine ID requires the actual persisted fallback at the
     * caller; neither this parser nor a status result creates a session.
     * @return array{uid:string,machine_id:?string,auth_token:string}|null
     */
    public function approvalContextPrivate():?array
    {
        if($this->code!==406)return null;
        $uid=$this->context['uid']??null;if(is_int($uid))$uid=(string)$uid;
        $token=$this->context['auth_token']??null;$machine=$this->context['machine_id']??null;
        if($machine==='')$machine=null;
        if(!is_string($uid)||preg_match('/^[1-9][0-9]{0,24}$/D',$uid)!==1||!self::opaque($token,16384)
            ||($machine!==null&&!self::opaque($machine,4096)))return null;
        return ['uid'=>$uid,'machine_id'=>$machine,'auth_token'=>$token];
    }

    /** Original server navigation and optional actual account binding only.
     * This does not prove a Web session, a native callback or a solved challenge.
     * @return array{url:string,flow_id:string,uid?:string}|null
     */
    public function checkpointContextPrivate():?array
    {
        if($this->code!==405)return null;
        $url=$this->context['url']??null;$flow=$this->context['flow_id']??null;
        if(!self::opaque($url,8192)||!self::opaque($flow,4096))return null;
        $parts=parse_url($url);
        if($parts===false||($parts['scheme']??null)!=='https'
            ||!in_array($parts['host']??null,['facebook.com','www.facebook.com','m.facebook.com'],true)
            ||isset($parts['user'])||isset($parts['pass'])||isset($parts['fragment'])||(isset($parts['port'])&&$parts['port']!==443))return null;
        $context=['url'=>$url,'flow_id'=>$flow];
        if(array_key_exists('uid',$this->context)){
            $uid=$this->context['uid'];if(is_int($uid))$uid=(string)$uid;
            if(!is_string($uid)||preg_match('/^[1-9][0-9]{0,24}$/D',$uid)!==1)return null;
            $context['uid']=$uid;
        }
        return $context;
    }

    public function __debugInfo():array{return ['code'=>$this->code,'context'=>'[PRIVATE]'];}
    /** @return array<never,never> */
    public function __serialize():array{throw new \LogicException('Native login challenges must remain private and in memory');}
    /** @param array<mixed> $data */
    public function __unserialize(array $data):void{throw new \LogicException('Native login challenges cannot be restored from serialized input');}

    private static function opaque(mixed $value,int $max):bool
    {
        return is_string($value)&&$value!==''&&strlen($value)<=$max&&preg_match('/[\x00-\x20\x7f]/',$value)===0;
    }
    private static function invalid():MessengerException
    {
        return new MessengerException('login_native_response_invalid','Facebook returned an unsupported native authentication response');
    }
}
