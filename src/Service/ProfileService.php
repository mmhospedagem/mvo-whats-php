<?php
declare(strict_types=1);
namespace MVO\Service;
use MVO\Protocol\BinaryNode;
use MVO\AppState\AppStateService;

/** @phpstan-import-type LidSyncResult from ServiceTransport */
final class ProfileService
{
    /** @var \Closure():AppStateService|null */
    private ?\Closure $appState;
    /** @var \Closure(string):void|null */
    private ?\Closure $applyName;
    /** @param callable():AppStateService|null $appState
     * @param callable(string):void|null $applyOwnPushName */
    public function __construct(private readonly ServiceTransport $transport,private readonly MexService $mex,?callable $appState=null,?callable $applyOwnPushName=null)
    {
        $this->appState=$appState===null ? null : \Closure::fromCallable($appState);$this->applyName=$applyOwnPushName===null ? null : \Closure::fromCallable($applyOwnPushName);
    }
    public function setPushName(string $name): void
    {
        $app=($this->appState ?? throw new \LogicException('App-state is not configured'))();
        $app->setPushName($name);
        if ($this->applyName !== null) ($this->applyName)($name);
        $this->transport->send(new BinaryNode('presence',['type'=>'available','name'=>$name]));
    }
    /**
     * @return array<string,string> */
    public function getProfilePicture(string $jid,string $type='preview',?string $existingId=null,?BinaryNode $privacyToken=null): array
    {
        ServiceTransport::jid($jid); if (!in_array($type,['preview','image'],true)) throw new \InvalidArgumentException('Invalid picture type');
        if ($privacyToken !== null && $privacyToken->tag !== 'tctoken') throw new \InvalidArgumentException('Expected a tctoken');
        $attrs=['type'=>$type,'query'=>'url']; if ($existingId !== null) $attrs['id']=$existingId;
        $p=$this->transport->iq('profile.getPicture','get','s.whatsapp.net','w:profile:picture',[new BinaryNode('picture',$attrs,$privacyToken === null ? null : [$privacyToken])],['target'=>$jid])->child('picture');
        if ($p === null) return []; $out=[]; foreach(['url'=>'url','direct_path'=>'directPath','id'=>'id','type'=>'type'] as $a=>$k) if (isset($p->attrs[$a])) $out[$k]=$p->attrs[$a]; return $out;
    }
    public function setProfilePicture(string $jpegBytes,?string $targetJid=null): ?string
    {
        if ($jpegBytes === '' || strlen($jpegBytes)>8*1024*1024) throw new \InvalidArgumentException('Invalid picture bytes');
        $attrs=$targetJid === null ? [] : ['target'=>ServiceTransport::jid($targetJid)];
        return $this->transport->iq('profile.setPicture','set','s.whatsapp.net','w:profile:picture',[new BinaryNode('picture',['type'=>'image'],$jpegBytes)],$attrs)->child('picture')?->attrs['id'] ?? null;
    }
    public function deleteProfilePicture(?string $targetJid=null): void { $this->transport->iq('profile.deletePicture','set','s.whatsapp.net','w:profile:picture',null,$targetJid === null ? [] : ['target'=>ServiceTransport::jid($targetJid)]); }
    /**
     * @return array{status:?string} */
    public function getStatus(string $jid,?BinaryNode $privacyToken=null): array
    {
        $users=$this->transport->usync([new BinaryNode('status')],[new BinaryNode('user',['jid'=>ServiceTransport::jid($jid)],$privacyToken === null ? null : [$privacyToken])]);
        $n=($users[0] ?? null)?->child('status'); return ['status'=>($n?->attrs['code'] ?? '') === '401' ? '' : ServiceTransport::text($n)];
    }
    public function setStatus(string $text): void { $this->transport->iq('profile.setStatus','set','s.whatsapp.net','status',[new BinaryNode('status',[],$text)]); }
    /**
     * @param list<string> $jids
     * @return list<array<string,mixed>> */
    public function getProfiles(array $jids): array
    {
        $out=[]; foreach($this->batch($jids,['contact','status','picture']) as $user) { if (!isset($user->attrs['jid'])) continue; $row=['jid'=>$user->attrs['jid'],'status'=>ServiceTransport::text($user->child('status'))]; if (isset($user->child('picture')?->attrs['id'])) $row['pictureId']=(int)$user->child('picture')->attrs['id']; $out[]=$row; } return $out;
    }
    /**
     * @param list<string> $jids
     * @return list<array<string,mixed>> */
    public function getDisappearingMode(array $jids): array
    {
        $out=[]; foreach($this->batch($jids,['disappearing_mode']) as $u) { $n=$u->child('disappearing_mode'); if ($n === null || $n->child('error') !== null) continue; $out[]=['jid'=>$u->attrs['jid'] ?? '','duration'=>(int)($n->attrs['duration'] ?? 0),'timestamp'=>(int)($n->attrs['t'] ?? 0),'ephemeralityDisabled'=>($n->attrs['ephemerality_disabled'] ?? '') === 'true']; } return $out;
    }
    public function setDisappearingMode(int $seconds): void { if ($seconds<0) throw new \InvalidArgumentException('Negative duration'); $this->transport->iq('profile.setDisappearingMode','set','s.whatsapp.net','disappearing_mode',[new BinaryNode('disappearing_mode',['duration'=>(string)$seconds])]); }
    /**
     * @param list<string> $jids
     * @return list<array<string,mixed>> */
    public function getTextStatuses(array $jids): array
    {
        $out=[]; foreach($this->batch($jids,['text_status']) as $u) { $n=$u->child('text_status'); if ($n?->child('error') !== null) $n=null; $out[]=['jid'=>$u->attrs['jid'] ?? '','text'=>$n?->attrs['text'] ?? null,'emoji'=>$n?->child('emoji')?->attrs['content'] ?? null,'ephemeralDurationSec'=>isset($n?->attrs['ephemeral_duration_sec']) ? (int)$n->attrs['ephemeral_duration_sec'] : null,'lastUpdateTime'=>isset($n?->attrs['last_update_time']) ? (int)$n->attrs['last_update_time'] : null]; } return $out;
    }
    public function setTextStatus(?string $text,?string $emoji=null,int $ephemeralDurationSec=0): void
    {
        if ($ephemeralDurationSec<0) throw new \InvalidArgumentException('Negative duration');
        $input=['text'=>$text === '' ? null : $text,'ephemeral_duration_sec'=>($text === null || $text === '') && ($emoji === null || $emoji === '') ? 0 : $ephemeralDurationSec]; if ($emoji !== null && $emoji !== '') $input['emoji']=['content'=>$emoji]; $this->mex->run('UpdateTextStatus',['input'=>$input]);
    }
    /**
     * @param list<string> $jids
     * @return list<array{jid:string,username:?string}> */
    public function getUsernames(array $jids): array { $out=[]; foreach($this->batch($jids,['username']) as $u) $out[]=['jid'=>$u->attrs['jid'] ?? '','username'=>$u->child('username')?->child('error') === null ? ServiceTransport::text($u->child('username')) : null]; return $out; }
    /**
     * @return array<string,mixed> */
    public function resolveUsername(string $username,?string $key=null): array
    {
        $input=self::parseUsername($username); $username=$input['username']; $key ??= $input['key']; self::key($key);
        $attrs=['username'=>$username]+($key === null ? [] : ['pin'=>$key]);
        $users=$this->transport->usync([new BinaryNode('contact',['addressing_mode'=>'lid']),new BinaryNode('business',[],[new BinaryNode('verified_name')])],[new BinaryNode('user',[],[new BinaryNode('contact',$attrs)])]);
        if ($users===[]) return ['status'=>'not-found']; if (count($users)!==1) return ['status'=>'invalid-result'];
        $u=$users[0]; $c=$u->child('contact'); if ($c?->child('error') !== null || ($c?->attrs['type'] ?? '') === 'out') return ['status'=>'not-found'];
        $name=$c?->attrs['username'] ?? null;
        if ($u->child('error')!==null || $c===null || ($c->attrs['type'] ?? '')!=='in' || ($name!==null && strcasecmp($name,$username)!==0)) return ['status'=>'invalid-result'];
        if (!isset($u->attrs['jid'])) return ['status'=>'key-required','username'=>$name];
        try { $jid=\MVO\Protocol\Jid::parse($u->attrs['jid'])->canonical(); } catch (\InvalidArgumentException) { return ['status'=>'invalid-result']; }
        if (!in_array($jid->server,['s.whatsapp.net','lid'],true) || $jid->device!==0 || !ctype_digit($jid->user)) return ['status'=>'invalid-result'];
        $business=$u->child('business');
        return ['status'=>'found','jid'=>(string)$jid,'username'=>$name,'isBusiness'=>$business !== null && $business->child('error') === null,'pnJid'=>$business?->attrs['pn_jid'] ?? null];
    }
    /** Validate a username and optional four-digit contact key without network I/O.
     * @return array{username:string,key:?string} */
    public static function parseUsername(string $input):array
    {
        $input=trim($input); $key=null;
        if (str_contains($input,':')) [$input,$key]=explode(':',$input,2);
        $username=self::username($input); self::key($key);
        return ['username'=>$username,'key'=>$key];
    }
    /**
     * @return array<string,mixed> */
    public function getOwnUsername(): array { try { $d=$this->mex->run('GetUsername'); } catch(ServiceException $e) { if ($e->serverCode !== '404') throw $e; $d=null; } return $d['xwa2_username_get']['username_info'] ?? ['username'=>null,'state'=>null,'pin'=>null]; }
    public function setUsername(string $username,bool $reserved=false,string $sessionId=''): bool { $d=$this->mex->run('SetUsername',['input'=>self::username($username),'reserved'=>$reserved,'session_id'=>$sessionId,'source'=>'USER_INPUT']); return ($d['xwa2_username_set']['result'] ?? '') === 'SUCCESS'; }
    public function deleteUsername(): bool { return ($this->mex->run('SetUsername')['xwa2_username_set']['result'] ?? '') === 'SUCCESS'; }
    /**
     * @return array<string,mixed> */
    public function checkUsernameAvailability(string $username): array { $d=$this->mex->run('UsernameAvailability',['input'=>self::username($username)]); return ['available'=>($d['xwa2_username_check']['result'] ?? '') === 'SUCCESS','suggestions'=>$d['xwa2_username_check']['suggestions'] ?? []]; }
    public function setUsernameKey(string $pin): bool { self::key($pin); return ($this->mex->run('SetUsernameKey',['pin'=>$pin])['xwa2_username_pin_set']['result'] ?? '') === 'SUCCESS'; }
    public function getAboutStatus(string $jid): ?string { ServiceTransport::jid($jid); return $this->mex->run('FetchAboutStatus',['user'=>['user_id'=>explode('@',$jid)[0]]])['xwa2_users_updates_since'][0]['updates'][0]['text'] ?? null; }
    /**
     * @param list<string> $numbers
     * `status` distinguishes a confirmed absence from an unanswered/error lookup.
     * @return list<LidSyncResult> */
    public function getLidsByPhoneNumbers(array $numbers): array { return $this->transport->userPairs($numbers); }
    /**
     * @param list<string> $jids
     * @param list<string> $tags
     * @return list<BinaryNode> */
    private function batch(array $jids,array $tags): array { if ($jids === []) return []; return $this->transport->usync(array_map(static fn(string $t):BinaryNode=>new BinaryNode($t),$tags),ServiceTransport::users($jids,'user')); }
    private static function username(string $name): string
    {
        $name=trim($name); if (str_starts_with($name,'@')) $name=substr($name,1); if (!preg_match('/^(?=.{3,35}$)(?=.*[a-zA-Z])[a-zA-Z0-9_]+(?:\.[a-zA-Z0-9_]+)*$/D',$name)) throw new \InvalidArgumentException('Invalid username');
        $lower=strtolower($name); if (str_starts_with($lower,'www.')) throw new \InvalidArgumentException('Invalid username prefix');
        foreach(['.com','.org','.net','.int','.edu','.gov','.mil','.arpa','.html','.htm','.txt','.xml'] as $suffix) if (str_ends_with($lower,$suffix)) throw new \InvalidArgumentException('Invalid username suffix');
        foreach(['whatsapp','instagram','facebook','oculus'] as $reserved) if (str_contains($lower,$reserved)) throw new \InvalidArgumentException('Reserved username'); return $name;
    }
    private static function key(?string $key): void { if ($key !== null && !preg_match('/^[0-9]{4}$/D',$key)) throw new \InvalidArgumentException('Username key must contain four digits'); }
}
