<?php
declare(strict_types=1);

namespace MVO\Messenger\Protocol;

use MVO\Messenger\MessengerException;

/** Reads inert JSON data-sjs/ScheduledServerJS payloads; never evaluates JavaScript. */
final class BootstrapParser
{
    private const MODULES = ['CurrentUserInitialData','DTSGInitialData','DTSGInitData','LSD','SiteData',
        'SprinkleConfig','MqttWebDeviceID','DGWWebConfig','MessengerWebInitData','LSPlatformMessengerSyncParams','LSVersion'];
    private int $nodes = 0;
    /** @var array<string,mixed> */ private array $modules = [];
    /** @var list<array<string,mixed>> */ private array $payloads = [];
    /** @var array<int,array{epochId:string,failureCount:int,cursor?:?string}> */ private array $initialRequests = [];
    private ?string $version = null;

    /** @param list<string> $scripts Optional public bundle bytes fetched separately without cookies. */
    public function parse(#[\SensitiveParameter] string $html, string $expectedUserId, string $origin = 'https://www.facebook.com', #[\SensitiveParameter] array $scripts = []): BootstrapConfig
    {
        if (!preg_match('/^[1-9][0-9]{0,24}$/D', $expectedUserId)) throw new \InvalidArgumentException('Expected the imported c_user identity');
        if (!in_array($origin, ['https://www.facebook.com','https://www.messenger.com'], true)) throw new \InvalidArgumentException('Unsupported Facebook Web origin');
        if (strlen($html) > 16777216 || count($scripts) > 64) throw new MessengerException('bootstrap_limit','Facebook bootstrap exceeds the local parser limit');
        $this->nodes=0; $this->modules=[]; $this->payloads=[]; $this->initialRequests=[]; $this->version=null;
        preg_match_all('~<script\b[^>]*>(.*?)</script\s*>~is', $html, $matches);
        foreach ($matches[1] as $body) {
            $body = trim($body);
            if ($body === '' || !in_array($body[0], ['{','['], true)) continue;
            try { $value=json_decode($body,true,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); }
            catch (\JsonException) { continue; }
            $this->walk($value, 0);
        }
        $identity=$this->modules['CurrentUserInitialData'] ?? null;
        $userId=is_array($identity) ? self::decimal($identity['USER_ID'] ?? null) : null;
        if ($userId === null || $userId === '0') throw new MessengerException('session_invalid','Facebook returned a login or unauthenticated page');
        if ($userId !== $expectedUserId) throw new MessengerException('account_mismatch','Facebook bootstrap identity does not match the imported session');
        $dgw=$this->modules['DGWWebConfig'] ?? null;
        $device=$this->modules['MqttWebDeviceID'] ?? null;
        $appId=is_array($dgw) ? self::decimal($dgw['appId'] ?? null) : null;
        $deviceId=is_array($device) ? ($device['clientID'] ?? null) : null;
        $appVersion=is_array($dgw) && (is_string($dgw['appVersion'] ?? null) || is_int($dgw['appVersion'] ?? null)) ? (string)$dgw['appVersion'] : null;
        if ($appId === null || $appId === '0' || !is_string($deviceId) || $deviceId === '' || strlen($deviceId)>200 || preg_match('/[\x00-\x20\x7f]/',$deviceId) || $appVersion === null || !preg_match('/^[A-Za-z0-9_.-]{1,100}$/D',$appVersion)) {
            throw new MessengerException('bootstrap_incomplete','Facebook did not provide the DGW application or Web device configuration');
        }
        if (isset($dgw['fbId']) && self::decimal($dgw['fbId']) !== '0' && self::decimal($dgw['fbId']) !== $userId) throw new MessengerException('account_mismatch','Facebook gateway identity does not match the imported session');
        foreach ($scripts as $script) {
            if (!is_string($script) || strlen($script)>8388608) throw new MessengerException('bootstrap_limit','Public Facebook bundle exceeds the local parser limit');
            $version=self::extractVersion($script);
            if ($version !== null) $this->recordVersion($version);
        }
        $lightspeedAppId=is_array($identity)?self::decimal($identity['APP_ID']??null):null;
        if($lightspeedAppId==='0')$lightspeedAppId=null;
        return new BootstrapConfig($userId,$appId,$deviceId,$this->version,$appVersion,$origin,$this->modules,$this->payloads,self::scriptUrls($html),$lightspeedAppId,$this->initialRequests);
    }

    /** Literal-only LSVersion export recovery; a computed or executed export stays unknown. */
    public static function extractVersion(string $script): ?string
    {
        if (preg_match('/__d\(\s*["\']LSVersion["\']\s*,.{0,600}?\.exports\s*=\s*["\']([1-9][0-9]{0,24})["\']/s',$script,$match)) return $match[1];
        return null;
    }

    /** @return list<string> */
    public static function scriptUrls(string $html): array
    {
        preg_match_all('~<(?:script|link)\b[^>]*\b(?:src|href)\s*=\s*(["\'])(.*?)\1~is',$html,$matches);
        $urls=[];
        foreach ($matches[2] as $value) {
            $url=html_entity_decode($value,ENT_QUOTES|ENT_HTML5,'UTF-8'); $parts=parse_url($url);
            if (strlen($url)>4096 || $parts===false || ($parts['scheme'] ?? '')!=='https' || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment']) || (isset($parts['port']) && $parts['port']!==443)) continue;
            $host=strtolower($parts['host'] ?? '');
            if (!($host==='static.xx.fbcdn.net' || str_ends_with($host,'.fbcdn.net')) || !str_starts_with($parts['path'] ?? '', '/rsrc.php/')) continue;
            $urls[$url]=true;
            if (count($urls)>=64) break;
        }
        return array_keys($urls);
    }

    private function walk(mixed $value, int $depth, bool $expectedPreloader = false): void
    {
        if (++$this->nodes>100000 || $depth>100) throw new MessengerException('bootstrap_limit','Facebook bootstrap nesting exceeds the local parser limit');
        if (!is_array($value)) return;
        if (isset($value['define']) && is_array($value['define'])) {
            foreach ($value['define'] as $definition) {
                if (!is_array($definition) || !is_string($definition[0] ?? null) || !in_array($definition[0],self::MODULES,true) || !array_key_exists(2,$definition)) continue;
                $name=$definition[0]; $data=$definition[2];
                if (in_array($name,['CurrentUserInitialData','DGWWebConfig','MqttWebDeviceID'],true) && isset($this->modules[$name]) && $this->modules[$name] !== $data) throw new MessengerException('bootstrap_conflict','Conflicting Facebook identity or gateway configuration');
                $this->modules[$name]=$data;
                if ($name==='LSVersion') {
                    $version=self::decimal(is_array($data) ? ($data['version'] ?? null) : $data);
                    if ($version!==null && $version!=='0') $this->recordVersion($version);
                }
            }
        }
        if (isset($value['requestPayload']) && is_string($value['requestPayload'])) {
            try { $request=json_decode($value['requestPayload'],true,64,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); }
            catch (\JsonException) { $request=null; }
            if (is_array($request) && ($version=self::decimal($request['version'] ?? null))!==null && $version!=='0') $this->recordVersion($version);
            if ($expectedPreloader && is_array($request)) $this->recordInitialRequest($request);
        }
        if (isset($value['payload']) && is_string($value['payload']) && isset($value['dependencies']) && is_array($value['dependencies'])) {
            try { $payload=json_decode($value['payload'],true,128,JSON_THROW_ON_ERROR|JSON_BIGINT_AS_STRING); }
            catch (\JsonException) { $payload=null; }
            if (is_array($payload) && array_key_exists('step',$payload)) $this->payloads[]=['payload'=>$payload,'dependencies'=>$value['dependencies']];
        }
        foreach ($value as $key=>$child) if (is_array($child)) $this->walk($child,$depth+1,$expectedPreloader || $key==='expectedPreloaders');
    }

    /** @param array<array-key,mixed> $request */
    private function recordInitialRequest(array $request): void
    {
        $database=self::decimal($request['database'] ?? null);
        $epoch=self::decimal($request['epoch_id'] ?? null);
        // Database keys must fit PHP integers; protocol epoch is an exact signed int64.
        if ($database===null || $epoch===null || strlen($database)>18 || strlen($epoch)>19 || (strlen($epoch)===19 && strcmp($epoch,'9223372036854775807')>0)) return;
        $id=(int)$database;
        $state=['epochId'=>$epoch,'failureCount'=>0];
        if (array_key_exists('last_applied_cursor',$request)) {
            if ($request['last_applied_cursor']!==null && !is_string($request['last_applied_cursor'])) return;
            $state['cursor']=$request['last_applied_cursor'];
        }
        if (isset($this->initialRequests[$id]) && $this->initialRequests[$id]!==$state) throw new MessengerException('bootstrap_conflict','Conflicting Facebook initial sync requests');
        $this->initialRequests[$id]=$state;
    }

    private function recordVersion(string $version): void
    {
        if ($this->version!==null && $this->version!==$version) throw new MessengerException('bootstrap_conflict','Conflicting Facebook Lightspeed versions');
        $this->version=$version;
    }

    private static function decimal(mixed $value): ?string
    {
        if (is_int($value) && $value>=0) return (string)$value;
        return is_string($value) && preg_match('/^(?:0|[1-9][0-9]{0,24})$/D',$value) ? $value : null;
    }
}
