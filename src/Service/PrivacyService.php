<?php
declare(strict_types=1);
namespace MVO\Service;
use MVO\Protocol\BinaryNode;

final class PrivacyService
{
    private const CATEGORIES=['readReceipts'=>'readreceipts','lastSeen'=>'last','online'=>'online','profilePicture'=>'profile','about'=>'status','groupAdd'=>'groupadd','callAdd'=>'calladd','messages'=>'messages','defenseMode'=>'defense','linkedProfiles'=>'linked_profiles','pix'=>'pix'];
    private const VALUES=['readReceipts'=>['all','none'],'online'=>['all','none','match_last_seen'],'groupAdd'=>['all','contacts','contact_blacklist'],'callAdd'=>['all','known','contacts'],'messages'=>['all','contacts'],'defenseMode'=>['off','on_standard']];
    public function __construct(private readonly ServiceTransport $transport) {}
    /**
     * @return array<string,string> */
    public function getPrivacySettings(): array
    {
        $r=$this->transport->iq('privacy.getSettings','get','s.whatsapp.net','privacy',[new BinaryNode('privacy')]); $out=[];
        foreach(ServiceTransport::children($r->child('privacy'),'category') as $n) { $key=array_search($n->attrs['name'] ?? '',self::CATEGORIES,true); $value=$n->attrs['value'] ?? ''; if (is_string($key) && in_array($value,self::values($key),true)) $out[$key]=$value; } return $out;
    }
    public function setPrivacySetting(string $setting,string $value): ?string
    {
        $category=self::category($setting); if (!in_array($value,self::values($setting),true)) throw new \InvalidArgumentException('Invalid value for privacy setting');
        $r=$this->transport->iq('privacy.setSetting','set','s.whatsapp.net','privacy',[new BinaryNode('privacy',[],[new BinaryNode('category',['name'=>$category,'value'=>$value])])]); return self::dhash($r,$category);
    }
    /**
     * @return array{jids:list<string>,dhash:?string,raw:BinaryNode} */
    public function getDisallowedList(string $setting): array
    {
        $category=self::denyCategory($setting); $r=$this->transport->iq('privacy.getDisallowedList','get','s.whatsapp.net','privacy',[new BinaryNode('privacy',$this->addressing(),[new BinaryNode('list',['name'=>$category,'value'=>'contact_blacklist'])])]);
        $p=$r->child('privacy');$list=$p?->child('list') ?? $p?->child('category');$jids=[]; foreach(ServiceTransport::children($list,'user') as $n) if (isset($n->attrs['jid'])) $jids[]=$n->attrs['jid'];
        return ['jids'=>$jids,'dhash'=>$list?->attrs['dhash'] ?? null,'raw'=>$r];
    }
    /** Optimistic server version; one retry after a concurrent edit.
     * @param list<string> $add
     * @param list<string> $remove */
    public function setDisallowedList(string $setting,array $add=[],array $remove=[]): ?string
    {
        $category=self::denyCategory($setting); if ($add === [] && $remove === []) throw new \InvalidArgumentException('An add/remove entry is required'); $users=[];
        foreach(['add'=>$add,'remove'=>$remove] as $action=>$jids) foreach(array_unique($jids) as $jid) { $pair=$this->transport->userPair($jid); $attrs=['action'=>$action,'jid'=>$pair['jid']]; if ($pair['lid'] !== null && $pair['pn'] !== null) $attrs['pn_jid']=$pair['pn']; $users[]=new BinaryNode('user',$attrs); }
        $attempt=0;
        while(true) {
            $current=$this->getDisallowedList($setting);
            try { $r=$this->transport->iq('privacy.setDisallowedList','set','s.whatsapp.net','privacy',[new BinaryNode('privacy',$this->addressing(),[new BinaryNode('category',['name'=>$category,'value'=>'contact_blacklist','dhash'=>$current['dhash'] ?? 'none'],$users)])]); return self::dhash($r,$category); }
            catch(ServiceException $e) { if ($e->serverCode !== '409' || $attempt===1) throw $e; $attempt++; }
        }
    }
    /**
     * @return array{jids:list<string>,raw:BinaryNode} */
    public function getBlocklist(): array
    {
        $r=$this->transport->iq('privacy.getBlocklist','get','s.whatsapp.net','blocklist'); $out=[]; foreach(ServiceTransport::children($r->child('list') ?? $r,'item') as $n) if (isset($n->attrs['jid'])) $out[]=$n->attrs['jid']; return ['jids'=>$out,'raw'=>$r];
    }
    public function blockUser(string $jid): void
    {
        $pair=$this->transport->userPair($jid); $attrs=['action'=>'block','jid'=>$pair['jid']]; if ($pair['lid'] !== null) $attrs+=$pair['pn'] !== null ? ['pn_jid'=>$pair['pn']] : ['unknown_identifier'=>'true'];
        $this->transport->iq('privacy.blockUser','set','s.whatsapp.net','blocklist',[new BinaryNode('item',$attrs)]);
    }
    public function unblockUser(string $jid): void { $pair=$this->transport->userPair($jid); $this->transport->iq('privacy.unblockUser','set','s.whatsapp.net','blocklist',[new BinaryNode('item',['action'=>'unblock','jid'=>$pair['jid']])]); }
    /**
     * @return array<string,string> */
    private function addressing(): array { $c=$this->transport->credentials(); return !empty($c['meLid']) || !empty($c['meLidJid']) ? ['addressing_mode'=>'lid'] : []; }
    private static function category(string $setting): string { return self::CATEGORIES[$setting] ?? throw new \InvalidArgumentException('Unknown privacy setting'); }
    private static function denyCategory(string $setting): string { if (!in_array($setting,['about','groupAdd','lastSeen','profilePicture','linkedProfiles','pix'],true)) throw new \InvalidArgumentException('Privacy setting does not support a deny list'); return self::category($setting); }
    /**
     * @return list<string> */
    private static function values(string $setting): array { return self::VALUES[$setting] ?? ['all','contacts','contact_blacklist','none']; }
    private static function dhash(BinaryNode $r,string $category): ?string { foreach(ServiceTransport::children($r->child('privacy')) as $n) if (($n->attrs['name'] ?? '') === $category) return $n->attrs['dhash'] ?? null; return null; }
}
