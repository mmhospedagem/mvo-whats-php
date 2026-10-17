<?php
declare(strict_types=1);
namespace MVO\Mcp;
use MVO\Protocol\BinaryNode;

/** Public MCP serialization. Secrets and credential fields never appear in resources/events. */
final class JsonValue
{
    private const SECRET='/^(?:private|privateKey|credentials|advSecret|advSecretKey|noiseKey|identityKey|signedPreKey|preKeys|messageSecret|botMessageSecret|mediaKey|secret|root|chainKey|encKey|macKey|handoffKey|token|pin|axolotlSenderKeyDistributionMessage|ephemeralSharedSecret|callKey|appStateSyncKeyShare|tcToken|nctSalt|cstoken|tctoken)$/iD';
    public static function encode(mixed $value,int $depth=0): mixed
    {
        if($depth>48) throw new \LengthException('MCP output nesting limit');
        if($value instanceof BinaryNode) return ['tag'=>$value->tag,'attrs'=>self::encode($value->attrs,$depth+1),'content'=>preg_match(self::SECRET,$value->tag) ? '[redacted]' : (is_string($value->content) ? ['$base64'=>base64_encode($value->content)] : self::encode($value->content,$depth+1))];
        if(is_array($value)) { if(count($value)>10000) throw new \LengthException('MCP output collection limit');$out=[];foreach($value as $key=>$child) $out[$key]=is_string($key) && preg_match(self::SECRET,$key) ? '[redacted]' : self::encode($child,$depth+1);return $out; }
        if(is_string($value)) { if(strlen($value)>1048576) throw new \LengthException('MCP output string limit');return preg_match('//u',$value)===1 ? $value : ['$base64'=>base64_encode($value)]; }
        if(is_int($value) || is_bool($value) || $value===null || (is_float($value) && is_finite($value))) return $value;
        throw new \UnexpectedValueException('Unsupported MCP output value');
    }
    public static function decode(mixed $value,int $depth=0): mixed
    {
        if($depth>48) throw new \InvalidArgumentException('MCP input nesting limit');
        if(is_array($value) && array_keys($value)===['$base64']) { if(!is_string($value['$base64']) || strlen($value['$base64'])>1048576) throw new \InvalidArgumentException('Invalid MCP base64 input');$raw=base64_decode($value['$base64'],true);if($raw===false) throw new \InvalidArgumentException('Invalid MCP base64 input');return $raw; }
        if(is_array($value)) { $out=[];foreach($value as $k=>$v) $out[$k]=self::decode($v,$depth+1);return $out; }return $value;
    }
}
