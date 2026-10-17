<?php
declare(strict_types=1);
namespace MVO\Service;
use MVO\Crypto\Crypto;
use MVO\Protocol\{BinaryNode,SchemaCodec};

/** Meta AI discovery and authenticated streaming-chunk crypto. */
final class BotService
{
    /** @var \Closure(string,string|array<string,mixed>,array<string,mixed>):array<string,mixed>|null */
    private ?\Closure $publisher;
    /** @param callable(string,string|array<string,mixed>,array<string,mixed>):array<string,mixed>|null $publisher */
    public function __construct(private readonly ServiceTransport $transport,?callable $publisher=null) { $this->publisher=$publisher===null ? null : \Closure::fromCallable($publisher); }
    /** @param string|array<string,mixed> $content
     * @param array<string,mixed> $options
     * @return array<string,mixed> */
    public function sendPrompt(string $to,string|array $content,array $options=[]): array
    {
        ServiceTransport::jid($to);
        return ($this->publisher ?? throw new \LogicException('Bot message transport is not configured'))($to,$content,$options);
    }
    /** @return list<array<string,mixed>> */
    public function listBots(): array
    {
        $b=$this->transport->iq('bot.listBots','get','s.whatsapp.net','bot',[new BinaryNode('bot',['v'=>'2'])])->child('bot');$default=$b?->child('default')?->attrs['jid'] ?? null;$out=[];
        foreach(ServiceTransport::children($b,'section') as $section) foreach(ServiceTransport::children($section,'bot') as $n) { $jid=$n->attrs['jid'] ?? null;$persona=$n->attrs['persona_id'] ?? null;if($jid===null || $persona===null) continue;$fbid=str_ends_with($jid,'@bot') ? $jid : ($jid==='13135550002@s.whatsapp.net' ? '867051314767696@bot' : explode('$',$persona)[0].'@bot');$out[]=['jid'=>$jid,'fbidJid'=>$fbid,'personaId'=>$persona,'isDefault'=>$jid===$default,'section'=>$section->attrs['name'] ?? null,'count'=>isset($n->attrs['count']) ? (int)$n->attrs['count'] : null]; }return $out;
    }
    /** @return array<string,mixed>|null */
    public function getBotProfile(string $jid,?string $personaId=null): ?array
    {
        $users=$this->transport->usync([new BinaryNode('bot',[],[new BinaryNode('profile',['v'=>'1'])])],[new BinaryNode('user',['jid'=>ServiceTransport::jid($jid)],[new BinaryNode('bot',[],[new BinaryNode('profile',$personaId === null ? [] : ['persona_id'=>$personaId])])])]);
        $bot=($users[0] ?? null)?->child('bot');$p=$bot?->child('error') === null ? $bot?->child('profile') : null;if($p===null) return null;
        $out=['personaId'=>$p->attrs['persona_id'] ?? null,'isDefault'=>ServiceTransport::text($p->child('default')) === 'true','isMetaCreated'=>$p->child('is_meta_created') === null ? null : ServiceTransport::text($p->child('is_meta_created')) === 'true','raw'=>$p];
        foreach(['name','attributes','description','category'] as $tag) $out[$tag]=ServiceTransport::text($p->child($tag));
        $out['prompts']=array_map(static fn(BinaryNode $n):array=>['emoji'=>ServiceTransport::text($n->child('emoji')) ?? '','text'=>ServiceTransport::text($n->child('text')) ?? ''],ServiceTransport::children($p->child('prompts'),'prompt'));
        $out['commands']=array_map(static fn(BinaryNode $n):array=>['name'=>ServiceTransport::text($n->child('name')) ?? '','description'=>ServiceTransport::text($n->child('description')) ?? ''],ServiceTransport::children($p->child('commands'),'command'));
        $out['commandsDescription']=ServiceTransport::text($p->child('commands')?->child('description'));$out['creatorName']=ServiceTransport::text($p->child('creator')?->child('name'));$out['creatorProfileUrl']=ServiceTransport::text($p->child('creator')?->child('profile_url'));$out['posingAsProfessional']=$p->child('posing_as_professional')?->attrs['type'] ?? null;return $out;
    }
    public static function messageSecret(string $parentSecret): string { Crypto::length($parentSecret,32);return Crypto::hkdf($parentSecret,32,'Bot Message'); }
    public static function chunkKey(string $botMessageSecret,string $saltId,string $targetSenderJid,string $authorJid): string
    {
        Crypto::length($botMessageSecret,32);foreach([$saltId,$targetSenderJid,$authorJid] as $v) if(trim($v)==='') throw new \InvalidArgumentException('Bot chunk identities required');return Crypto::hkdf($botMessageSecret,32,$saltId.$targetSenderJid.$authorJid);
    }
    /** Inputs must come from the authenticated parent prompt and incoming bot envelope.
     * @return array<string,mixed> */
    public static function decryptChunk(string $parentSecret,string $saltId,string $targetSenderJid,string $authorJid,string $iv,string $ciphertext): array
    {
        $key=self::chunkKey(self::messageSecret($parentSecret),$saltId,$targetSenderJid,$authorJid);$plain=Crypto::decryptGcm($key,$iv,$ciphertext,$saltId."\0".$authorJid);return SchemaCodec::decode('Message',$plain);
    }
}
