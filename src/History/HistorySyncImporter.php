<?php
declare(strict_types=1);
namespace MVO\History;

use MVO\Message\ContentDecoder;
use MVO\Protocol\{BinaryData,Jid};
use MVO\Service\{AccountExtensionsService,TrustedContactTokenService};
use MVO\Signal\SignalStore;

/** @internal Applies one authenticated history record at a time inside the caller's transaction. */
final class HistorySyncImporter
{
    private readonly HistoryStore $history;
    private readonly TrustedContactTokenService $tokens;
    private readonly string $pending;
    /** @var array<string,mixed> */
    private array $summary;
    private int $sequence=0;
    private bool $finished=false;

    /** @param \Closure():array<string,mixed> $getCredentials
     * @param array<string,mixed> $notification */
    public function __construct(
        private readonly SignalStore $store,
        private readonly \Closure $getCredentials,
        array $notification=[],
        private readonly bool $persist=true,
    ) {
        $this->history=new HistoryStore($store);
        $this->tokens=new TrustedContactTokenService(static fn()=>throw new \LogicException('History import cannot query'),$store,$getCredentials);
        $this->pending='history-import-contacts-'.bin2hex(random_bytes(16));
        $this->summary=['syncType'=>$notification['syncType'] ?? null,'chunkOrder'=>$notification['chunkOrder'] ?? null,
            'progress'=>$notification['progress'] ?? null,'messagesCount'=>0,'conversationsCount'=>0,'pushnamesCount'=>0,'inlineContactsCount'=>0];
    }

    /** No consumer callbacks or network I/O run here.
     * @param array<string,mixed> $item */
    public function item(array $item):void
    {
        if ($this->finished) throw new \LogicException('History import is already finished');
        $kind=$item['kind'] ?? null;
        $value=$item['value'] ?? null;
        switch ($kind) {
            case 'chunkOrder':
            case 'progress':
                $this->summary[$kind]=$value;
                break;
            case 'conversations.messages':
                if (!is_array($value) || !is_array($value['message'] ?? null)) break;
                $chat=$item['conversationId'] ?? null;
                if (!is_string($chat) || $chat==='') throw new \UnexpectedValueException('History conversation missing ID');
                $this->message($value['message'],$chat);
                ++$this->summary['messagesCount'];
                break;
            case 'statusV3Messages':
                if (!is_array($value)) break;
                $chat=$value['key']['remoteJid'] ?? StatusStore::CHAT;
                if (!is_string($chat)) throw new \UnexpectedValueException('Invalid history message chat');
                $this->message($value,$chat);
                ++$this->summary['messagesCount'];
                break;
            case 'conversations':
                if (is_array($value)) $this->conversation($value);
                break;
            case 'phoneNumberToLidMappings':
                if (is_array($value)) $this->mapping($value);
                break;
            case 'pushnames':
                if (!is_array($value) || !is_string($value['id'] ?? null) || $value['id']==='') break;
                ++$this->summary['pushnamesCount'];
                if ($this->persist) $this->defer('pushnames',$value);
                break;
            case 'inlineContacts':
                if (!is_array($value)) break;
                $this->mapping($value);
                $jid=$value['lidJid'] ?? $value['pnJid'] ?? null;
                if (!is_string($jid) || $jid==='') break;
                ++$this->summary['inlineContactsCount'];
                if ($this->persist) $this->defer('inlineContacts',$value);
                break;
            case 'globalSettings':
                if (is_array($value)) AccountExtensionsService::observeGlobalSettings($this->store,$value);
                break;
            case 'nctSalt':
                if (is_string($value)) $this->store->set('history-meta','nctSalt',$value);
                break;
            case 'omittedConversationsProvided':
                $this->summary['omittedConversationsProvided']=$value===true;
                if ($this->persist) $this->history->omittedInventoryProvided($value===true);
                break;
            case 'omittedConversations':
                if (!is_array($value)) break;
                if ($this->persist) $this->history->recordOmittedConversation($value);
                $this->count('omittedConversationsCount');
                break;
            case 'callLogRecords':
                if (!is_array($value)) break;
                if ($this->persist) $this->history->observed()->callLog($value);
                $this->count('callLogsCount');
                break;
            case 'pastParticipants':
                if (!is_array($value)) break;
                if ($this->persist) $this->history->messageObservations()->pastParticipants($value);
                $this->count('pastParticipantGroupsCount');
                break;
            case 'recentStickers':
            case 'favoriteStickers':
                if (!is_array($value)) break;
                if ($this->persist) $this->history->observed()->sticker($value,$kind==='recentStickers' ? 'recent' : 'favorite');
                $this->count('stickersCount');
                break;
        }
    }

    /** Finalize root contact projections after every PN/LID mapping has arrived.
     * The temporary rows are transaction-local disk staging, never an in-memory contact list. */
    public function finish():void
    {
        if ($this->finished) return;
        // Match the buffered importer: pushnames first, then inline contact names.
        foreach (['pushnames','inlineContacts'] as $kind) {
            $namespace=$this->pending.'-'.$kind;
            $after='';
            while (($page=$this->store->entries($namespace,1,$after))!==[]) {
                foreach ($page as $id=>$bytes) {
                    $after=(string)$id;
                    $row=BinaryData::decode($bytes);
                    if (!is_array($row)) throw new \UnexpectedValueException('Invalid staged history contact');
                    if ($kind==='pushnames') {
                        $jid=Jid::parse($row['id'])->canonical()->bare();
                        $lid=$this->store->get('message-pn-to-lid',$jid);
                        $this->history->upsertContact(['jid'=>$lid ?? $jid,'pushName'=>$row['pushname'] ?? '']);
                    } else {
                        $record=['jid'=>$row['lidJid'] ?? $row['pnJid'],'displayName'=>$row['fullName'] ?? $row['firstName'] ?? ''];
                        if (isset($row['username'])) $record['username']=$row['username'];
                        if (isset($row['pnJid'])) $record['phoneNumber']=$row['pnJid'];
                        $this->history->upsertContact($record);
                    }
                    $this->store->delete($namespace,(string)$id);
                }
            }
        }
        $this->finished=true;
    }

    /** Must run inside the same transaction as item(); completes the staged contacts.
     * @return array<string,mixed> */
    public function result():array { $this->finish(); return $this->summary; }

    /** @param array<string,mixed> $value */
    private function defer(string $kind,array $value):void
    {
        $this->store->set($this->pending.'-'.$kind,str_pad((string)++$this->sequence,12,'0',STR_PAD_LEFT),BinaryData::encode($value));
    }

    /** @param array<string,mixed> $chat */
    private function conversation(array $chat):void
    {
        $id=$chat['id'] ?? null;
        if (!is_string($id) || $id==='') throw new \UnexpectedValueException('History conversation missing ID');
        $jid=Jid::parse($id)->canonical()->bare();
        $lid=$chat['lidJid'] ?? $chat['accountLid'] ?? null;
        if (isset($chat['pnJid']) && is_string($lid)) $this->mapping(['pnJid'=>$chat['pnJid'],'lidJid'=>$lid]);
        if (!$this->persist) $this->privacyTokens($chat,$jid);
        ++$this->summary['conversationsCount'];
        if (!$this->persist) return;
        unset($chat['messages']);
        $this->history->upsertChat([...$chat,'jid'=>$jid]);
        foreach ($chat['participant'] ?? [] as $participant) {
            if (is_array($participant) && is_string($participant['userJid'] ?? null) && is_array($participant['memberLabel'] ?? null)) {
                $this->history->messageObservations()->memberLabel($jid,$participant['userJid'],$participant['memberLabel']);
            }
        }
        $this->history->reconcileOmittedConversation($jid);
        if (isset($chat['displayName']) || isset($chat['username'])) {
            $this->history->upsertContact(['jid'=>$lid ?? $jid,'displayName'=>$chat['displayName'] ?? '', 'username'=>$chat['username'] ?? '']);
        }
    }

    /** @param array<string,mixed> $mapping */
    private function mapping(array $mapping):void
    {
        if (!is_string($mapping['pnJid'] ?? null) || !is_string($mapping['lidJid'] ?? null)) return;
        $pn=Jid::parse($mapping['pnJid'])->canonical();
        $lid=Jid::parse($mapping['lidJid'])->canonical();
        if ($pn->server!=='s.whatsapp.net' || $lid->server!=='lid') throw new \UnexpectedValueException('Invalid history PN/LID mapping');
        $this->store->set('message-pn-to-lid',$pn->bare(),$lid->bare());
        $this->store->set('message-lid-to-pn',$lid->bare(),$pn->bare());
        if ($this->persist) $this->history->upsertContact(['jid'=>$lid->bare(),'phoneNumber'=>$pn->bare()]);
    }

    /** Preserve newer receiver/sender tokens already learned by the live transport.
     * When bulk persistence is enabled the existing history-chat fallback performs
     * this timestamp merge; sink-only imports need the same rule in token storage.
     * @param array<string,mixed> $chat */
    private function privacyTokens(array $chat,string $jid):void
    {
        if (!str_ends_with($jid,'@s.whatsapp.net') && !str_ends_with($jid,'@lid')) return;
        $bytes=$this->store->get('privacy-token-records',$jid);
        $old=$bytes===null ? [] : BinaryData::decode($bytes);
        if (!is_array($old)) throw new \UnexpectedValueException('Invalid privacy token record');
        $legacy=$this->history->getChat($jid) ?? [];
        $fields=['id'=>$jid];
        $timestamp=$chat['tcTokenTimestamp'] ?? null;
        if (is_int($timestamp) && $timestamp>max($old['tcTokenTimestamp'] ?? -1,$legacy['tcTokenTimestamp'] ?? -1)) {
            $fields['tcTokenTimestamp']=$timestamp;
            if (is_string($chat['tcToken'] ?? null)) $fields['tcToken']=$chat['tcToken'];
        }
        $sender=$chat['tcTokenSenderTimestamp'] ?? null;
        if (is_int($sender) && $sender>max($old['tcTokenSenderTimestamp'] ?? -1,$legacy['tcTokenSenderTimestamp'] ?? -1)) $fields['tcTokenSenderTimestamp']=$sender;
        if (count($fields)>1) $this->tokens->hydrateFromHistorySync([$fields]);
    }

    /** @param array<string,mixed> $info */
    private function message(array $info,string $chat):void
    {
        $own=($this->getCredentials)()['meJid'] ?? null;
        if ($this->persist) {
            $this->history->recordWebMessage($info,$chat,is_string($own) ? $own : null);
            return;
        }
        // Crypto state must survive even when bulk chat/contact/message persistence is disabled.
        $key=$info['key'] ?? [];
        $id=$key['id'] ?? null;
        if (!is_string($id) || $id==='') return;
        $proto=$info['message'] ?? [];
        $secret=$info['messageSecret'] ?? (is_array($proto) ? (ContentDecoder::unwrap($proto)['context']['messageSecret'] ?? null) : null);
        if (!is_string($secret) || strlen($secret)!==32) return;
        $chat=Jid::parse($chat)->canonical()->bare();
        $group=str_ends_with($chat,'@g.us') || str_ends_with($chat,'@broadcast');
        $author=$key['participant'] ?? $info['participant'] ?? (($key['fromMe'] ?? false)===true ? $own : ($group ? null : $chat));
        if (!is_string($author)) return;
        $author=Jid::parse($author)->canonical()->bare();
        $this->store->set('message-secrets',hash('sha256',$chat."\0".$id),BinaryData::encode(['author'=>$author,'secret'=>$secret]));
    }

    private function count(string $key):void { $this->summary[$key]=($this->summary[$key] ?? 0)+1; }
}
