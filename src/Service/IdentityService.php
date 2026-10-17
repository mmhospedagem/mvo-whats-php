<?php
declare(strict_types=1);
namespace MVO\Service;

use MVO\Protocol\{BinaryData,Jid};
use MVO\Signal\SignalStore;

/** Authenticated identity linkage; routing aliases are never permission to clone a ratchet. */
final class IdentityService
{
    public function __construct(private readonly SignalStore $store) {}

    public static function phone(string $value):string { return self::user($value,'s.whatsapp.net'); }
    public static function lid(string $value):string { return self::user($value,'lid'); }
    private static function user(string $value,string $server):string
    {
        $jid=Jid::recipient($value)->canonical();
        if($jid->server!==$server||$jid->device!==0||!ctype_digit($jid->user))throw new \InvalidArgumentException('Expected a bare '.$server.' identity');
        return $jid->bare();
    }

    /** Fresh USync/app-state evidence may replace a mapping; a delayed inbound message may not. */
    public function remember(string $phoneJid,string $lidJid,bool $authoritative=false):void
    {
        $pn=self::phone($phoneJid);$lid=self::lid($lidJid);
        $this->store->transaction(function()use($pn,$lid,$authoritative):void{
            if($this->currentLid($lid)!==$lid)return;
            $previous=$this->store->get('message-pn-to-lid',$pn);
            if(!$authoritative&&$previous!==null&&$previous!==$lid)return;
            $reverse=$this->store->get('message-lid-to-pn',$lid);
            if($reverse!==null&&$reverse!==$pn){
                if($previous===$lid)return; // Keep the canonical reverse for a known PN alias.
                if(!$authoritative)return;
                throw new \UnexpectedValueException('Conflicting phone linkage');
            }
            $this->store->set('message-pn-to-lid',$pn,$lid);
            $this->store->set('message-lid-to-pn',$lid,$pn);
        });
    }

    /** A single correlated USync row proves both queried/corrected PN aliases. */
    public function confirmPair(string $queriedPhone,string $canonicalPhone,string $lidJid):void
    {
        $queried=self::phone($queriedPhone);$canonical=self::phone($canonicalPhone);$lid=self::lid($lidJid);
        $this->store->transaction(function()use($queried,$canonical,$lid):void{
            if($this->currentLid($lid)!==$lid)return;
            $reverse=$this->store->get('message-lid-to-pn',$lid);
            if($reverse!==null&&!in_array($reverse,[$queried,$canonical],true)&&$this->store->get('message-pn-to-lid',$queried)!==$lid&&$this->store->get('message-pn-to-lid',$canonical)!==$lid)throw new \UnexpectedValueException('Conflicting phone linkage');
            $this->store->set('message-pn-to-lid',$queried,$lid);
            $this->store->set('message-pn-to-lid',$canonical,$lid);
            $this->store->set('message-lid-to-pn',$lid,$canonical);
        });
    }

    public function resolveLid(string $phoneJid):?string
    {
        $lid=$this->store->get('message-pn-to-lid',self::phone($phoneJid));
        return $lid===null?null:$this->currentLid($lid);
    }

    public function resolvePhone(string $lidJid):?string
    {
        $lid=self::lid($lidJid);$pn=$this->store->get('message-lid-to-pn',$lid);
        return $pn??$this->store->get('message-lid-to-pn',$this->currentLid($lid));
    }

    public function currentLid(string $lidJid):string
    {
        $lid=self::lid($lidJid);$seen=[];
        while(($next=$this->store->get('identity-lid-successor',$lid))!==null){
            if(isset($seen[$lid])||count($seen)>=128)throw new \UnexpectedValueException('Invalid identity succession');
            $seen[$lid]=true;$lid=self::lid($next);
        }
        return $lid;
    }

    /**
     * Apply an authenticated LidChangeNotification atomically. Superseded/forked
     * notifications cannot reverse a known succession. Signal sessions and outbox
     * remain at their original addresses for delayed messages and uncertain sends;
     * the new address obtains a fresh verified bundle through normal Signal sync.
     */
    public function changeLid(string $oldLidJid,string $newLidJid):bool
    {
        $old=self::lid($oldLidJid);$new=self::lid($newLidJid);
        if($old===$new)return false;
        return $this->store->transaction(function()use($old,$new):bool{
            if($this->store->get('identity-lid-successor',$old)!==null||$this->currentLid($new)!==$new)return false;
            $pn=$this->store->get('message-lid-to-pn',$old);
            if($pn!==null){$current=$this->store->get('message-pn-to-lid',$pn);if($current!==null&&$current!==$old&&$current!==$new)return false;}
            $oldIdentities=$this->store->peerIdentities($old);$newIdentities=$this->store->peerIdentities($new);
            foreach($oldIdentities as$device=>$identity)if(isset($newIdentities[$device])&&!hash_equals($identity,$newIdentities[$device]))throw new \UnexpectedValueException('Conflicting trusted identity during LID change');
            $newPn=$this->store->get('message-lid-to-pn',$new);
            if($pn!==null&&$newPn!==null&&$newPn!==$pn)throw new \UnexpectedValueException('Conflicting phone linkage during LID change');
            $this->store->set('identity-lid-successor',$old,$new);
            $after='';
            do{
                $page=$this->store->entries('message-pn-to-lid',1000,$after);
                foreach($page as$phone=>$lid){$after=(string)$phone;if($lid===$old)$this->store->set('message-pn-to-lid',(string)$phone,$new);}
            }while(count($page)===1000);
            if($pn!==null)$this->store->set('message-lid-to-pn',$new,$pn);
            // Receiver/sender privacy tokens are address-bound and must be renewed.
            foreach([$old,$new]as$jid){$this->store->delete('privacy-token-records',$jid);$this->store->delete('privacy-token-pending',$jid);}
            $this->store->set('identity-peer-revision',$old,$new);
            return true;
        });
    }

    /** Version ordering is already authenticated by AppStateService's transaction. */
    public function pnForLidChat(string $lidJid,?string $phoneJid,bool $remove=false):void
    {
        if($remove)return;
        $lid=self::lid($lidJid);
        $this->store->transaction(function()use($lid,$phoneJid):void{
            if($phoneJid===null)throw new \InvalidArgumentException('PN/LID app-state linkage requires a phone JID');
            $this->remember($phoneJid,$lid,true);
        });
    }

    public function shareOwnPhone(string $lidJid,bool $shared):void
    {
        $lid=self::lid($lidJid);
        // Sharing policy does not infer any PN and does not publish a message.
        $this->store->set('identity-share-own-pn',$lid,BinaryData::encode(['lidJid'=>$lid,'shared'=>$shared]));
    }
}
