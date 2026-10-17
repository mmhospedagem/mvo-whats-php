<?php
declare(strict_types=1);

namespace MVO\Service;

use MVO\AppState\{AppStateProjector, AppStateSchema, AppStateService};
use MVO\History\HistoryStore;
use MVO\Protocol\Jid;

/** Business tools synchronized through authenticated app-state; reads use the local projection. */
final class BusinessToolsService
{
    public function __construct(private readonly AppStateService $appState, private readonly AppStateProjector $projector, private readonly HistoryStore $history) {}

    /** @return list<array<string,mixed>> */
    public function listLabels(int $limit = 100, string $after = ''): array
    {
        self::page($limit, $after);
        return array_map(self::labelRecord(...), $this->projector->listLabels($limit, $after));
    }

    /** @phpstan-impure
     * @return array<string,mixed>|null */
    public function getLabel(string $id): ?array
    {
        self::labelId($id);
        $label = $this->projector->getLabel($id);
        return $label === null ? null : self::labelRecord($label);
    }

    /** @return array<string,mixed> */
    public function createLabel(string $name, int $color, ?string $id = null): array
    {
        self::labelName($name);
        self::labelColor($color);
        $id ??= $this->nextLabelId();
        self::labelId($id);
        if ($this->projector->getLabel($id) !== null || $this->pendingLabel($id)) throw new \InvalidArgumentException('Label ID already exists or is pending synchronization');
        $this->appState->editLabel($id, ['name'=>$name, 'color'=>$color, 'deleted'=>false]);
        return $this->getLabel($id) ?? throw new \UnexpectedValueException('Label was acknowledged but no local projection exists');
    }

    /** @param array<string,mixed> $patch
     * @return array<string,mixed> */
    public function updateLabel(string $id, array $patch): array
    {
        self::labelId($id);
        if ($patch === []) throw new \InvalidArgumentException('Empty label update');
        self::fields($patch, ['name','color','predefinedId']);
        $existing = $this->getLabel($id) ?? throw new \OutOfBoundsException('Label not present in synchronized local state');
        $data = array_intersect_key($existing, array_flip(['name','color','predefinedId']));
        $data = array_replace($data, $patch);
        if (!is_string($data['name'] ?? null)) throw new \InvalidArgumentException('Label name must be a string');
        self::labelName($data['name']);
        if (!is_int($data['color'] ?? null)) throw new \InvalidArgumentException('Label color must be integer');
        self::labelColor($data['color']);
        if (isset($data['predefinedId']) && (!is_int($data['predefinedId']) || $data['predefinedId'] < 0 || $data['predefinedId'] > 2147483647)) throw new \InvalidArgumentException('Invalid predefined label ID');
        $data['deleted'] = false;
        $this->appState->editLabel($id, $data);
        return $this->getLabel($id) ?? throw new \UnexpectedValueException('Label update has no synchronized projection');
    }

    public function deleteLabel(string $id): void
    {
        self::labelId($id);
        if ($this->getLabel($id) === null) throw new \OutOfBoundsException('Label not present in synchronized local state');
        $this->appState->editLabel($id, ['deleted'=>true]);
        if ($this->getLabel($id) !== null) throw new \UnexpectedValueException('Label delete has no synchronized projection');
    }

    public function setChatLabel(string $jid, string $labelId, bool $associated): void
    {
        self::labelId($labelId);
        $jid = Jid::recipient($jid)->canonical()->bare();
        if ($associated && $this->getLabel($labelId) === null) throw new \OutOfBoundsException('Label not present in synchronized local state');
        $this->appState->setChatLabel($jid, $labelId, $associated);
    }

    public function setMessageLabel(string $jid, string $messageId, string $labelId, bool $associated): void
    {
        self::labelId($labelId);
        self::text($messageId, 'message ID', 256);
        $jid = Jid::recipient($jid)->canonical()->bare();
        if ($associated && $this->getLabel($labelId) === null) throw new \OutOfBoundsException('Label not present in synchronized local state');
        $this->appState->setMessageLabel($jid, $messageId, $labelId, $associated);
    }

    /** @return list<string> */
    public function getChatLabels(string $jid): array
    {
        $chat = $this->history->getChat(Jid::recipient($jid)->canonical()->bare());
        return self::projectedLabels($chat['labelIds'] ?? []);
    }

    /** @return list<string> */
    public function getMessageLabels(string $jid, string $messageId): array
    {
        self::text($messageId, 'message ID', 256);
        $message = $this->history->getMessage(Jid::recipient($jid)->canonical()->bare(), $messageId);
        return self::projectedLabels($message['labelIds'] ?? []);
    }

    /** @return list<array<string,mixed>> */
    public function listQuickReplies(int $limit = 100, string $after = ''): array
    {
        self::page($limit, $after);
        return array_map(self::replyRecord(...), $this->projector->listQuickReplies($limit, $after));
    }

    /** @return array<string,mixed>|null */
    public function getQuickReply(string $id): ?array
    {
        self::quickReplyId($id);
        $record = $this->projector->getAction('QuickReply', ['id'=>$id]);
        return $record === null || ($record['deleted'] ?? false) ? null : self::replyRecord($record);
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function saveQuickReply(string $id, array $input): array
    {
        self::quickReplyId($id);
        self::fields($input, ['shortcut','message','keywords','count','deleted']);
        if ($input === []) throw new \InvalidArgumentException('Empty quick reply update');
        $existing = $this->getQuickReply($id) ?? [];
        $data = array_replace(['keywords'=>[], 'count'=>0, 'deleted'=>false], array_intersect_key($existing, array_flip(['shortcut','message','keywords','count','deleted'])), $input);
        if (!is_bool($data['deleted'])) throw new \InvalidArgumentException('Quick reply deleted must be boolean');
        foreach (['shortcut'=>64,'message'=>4096] as $field=>$limit) {
            if (!is_string($data[$field] ?? null)) throw new \InvalidArgumentException('Quick reply '.$field.' must be a string');
            self::text($data[$field], $field, $limit, $data['deleted']);
        }
        if (!is_int($data['count']) || $data['count'] < 0 || $data['count'] > 2147483647) throw new \InvalidArgumentException('Quick reply count must be a nonnegative integer');
        if (!is_array($data['keywords']) || !array_is_list($data['keywords']) || count($data['keywords']) > 20) throw new \InvalidArgumentException('Quick reply keywords must be a list of at most twenty strings');
        foreach ($data['keywords'] as $keyword) { if (!is_string($keyword)) throw new \InvalidArgumentException('Quick reply keyword must be a string'); self::text($keyword, 'keyword', 64); }
        $this->appState->setQuickReply($id, $data);
        if ($data['deleted']) {
            if ($this->getQuickReply($id) !== null) throw new \UnexpectedValueException('Quick reply delete has no synchronized projection');
            return ['id'=>$id,'deleted'=>true];
        }
        return $this->getQuickReply($id) ?? throw new \UnexpectedValueException('Quick reply update has no synchronized projection');
    }

    public function deleteQuickReply(string $id): void
    {
        self::quickReplyId($id);
        $reply = $this->getQuickReply($id) ?? throw new \OutOfBoundsException('Quick reply not present in synchronized local state');
        $this->saveQuickReply($id, ['shortcut'=>$reply['shortcut'] ?? '', 'message'=>$reply['message'] ?? '', 'deleted'=>true]);
    }

    /** @return list<array<string,mixed>> */
    public function listBroadcastLists(int $limit = 100, string $after = ''): array
    {
        self::page($limit, $after);
        return array_map(self::broadcastRecord(...), $this->projector->listBroadcastLists($limit, $after));
    }

    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function saveBroadcastList(array $input): array
    {
        self::fields($input, ['id','listName','participants','labelIds']);
        if (!is_string($input['id'] ?? null)) throw new \InvalidArgumentException('Broadcast list ID must be string');
        self::text($input['id'], 'broadcast list ID', 256);
        if (!is_string($input['listName'] ?? null)) throw new \InvalidArgumentException('Broadcast list name must be string');
        self::text($input['listName'], 'broadcast list name', 100);
        if (!is_array($input['participants'] ?? null) || !array_is_list($input['participants']) || $input['participants'] === [] || count($input['participants']) > 256) throw new \InvalidArgumentException('Broadcast requires one to 256 participants');
        foreach ($input['participants'] as $participant) {
            if (!is_array($participant)) throw new \InvalidArgumentException('Invalid broadcast participant');
            self::fields($participant, ['lidJid','pnJid']);
        }
        $labels = $input['labelIds'] ?? [];
        if (!is_array($labels) || !array_is_list($labels) || count($labels) > 100) throw new \InvalidArgumentException('Broadcast label IDs must be a list');
        foreach ($labels as $label) { if (!is_string($label)) throw new \InvalidArgumentException('Broadcast label ID must be string'); self::labelId($label); }
        $this->appState->setBroadcastList($input);
        $record = $this->projector->getAction('BusinessBroadcastList', ['id'=>$input['id']]);
        return $record === null ? throw new \UnexpectedValueException('Broadcast list has no synchronized projection') : self::broadcastRecord($record);
    }

    public function deleteBroadcastList(string $id): void
    {
        self::text($id, 'broadcast list ID', 256);
        if ($this->projector->getAction('BusinessBroadcastList', ['id'=>$id]) === null) throw new \OutOfBoundsException('Broadcast list not present in synchronized local state');
        $this->appState->removeBroadcastList($id);
        if ($this->projector->getAction('BusinessBroadcastList', ['id'=>$id]) !== null) throw new \UnexpectedValueException('Broadcast delete has no synchronized projection');
    }

    /** @return list<array<string,mixed>> */
    public function listLabelSublists(?string $chat=null,int $limit=100,string $after=''):array
    {
        StateFeatureInputs::page($limit,$after);$chat=$chat===null?null:StateFeatureInputs::chat($chat);
        // Filtering happens before limiting; a page never silently loses matching records.
        $out=[];$cursor=$after;
        do{ $page=$this->projector->listActions('LabelSublist',1000,$cursor);
            foreach($page as$record){$cursor=$record['cursor'];if($chat!==null&&($record['chatJid']??null)!==$chat)continue;$out[]=$record;if(count($out)===$limit)return $out;}
        }while(count($page)===1000);
        return $out;
    }
    /** @return array<string,mixed> */
    public function setLabelSublist(int $predefinedId,string $chat,int $subListId):array
    {
        $args=['predefinedId'=>(string)StateFeatureInputs::predefinedId($predefinedId),'chatJid'=>StateFeatureInputs::chat($chat)];
        $this->appState->mutate('LabelSublist',$args,['subListId'=>StateFeatureInputs::labelSublist($subListId)]);
        return $this->projected('LabelSublist',$args);
    }
    public function removeLabelSublist(int $predefinedId,string $chat):void
    {
        $args=['predefinedId'=>(string)StateFeatureInputs::predefinedId($predefinedId),'chatJid'=>StateFeatureInputs::chat($chat)];
        $this->appState->mutate('LabelSublist',$args,null,true);
        if($this->projector->getAction('LabelSublist',$args)!==null)throw new \UnexpectedValueException('Label sublist deletion was not synchronized');
    }
    /** @return array<string,mixed> */
    public function setContactManagerHidden(string $lid,bool $hidden):array
    {
        $args=['metadataJid'=>StateFeatureInputs::contactManager($lid)];
        $this->appState->mutate('ContactManagerMetadata',$args,['isHidden'=>$hidden]);
        return $this->projected('ContactManagerMetadata',$args);
    }
    /** @return array<string,mixed>|null */
    public function getCustomerData(string $lid):?array
    {
        $record=$this->projector->getAction('CustomerData',['chatJid'=>StateFeatureInputs::contactManager($lid)]);
        if($record!==null)unset($record['_raw']);return $record;
    }
    /** @param array<string,mixed> $input
     * @return array<string,mixed> */
    public function saveCustomerData(string $lid,array $input):array
    {
        $lid=StateFeatureInputs::contactManager($lid);$input=StateFeatureInputs::customerData($lid,$input);$current=$this->getCustomerData($lid)??[];
        $fields=['contactType','email','altPhoneNumbers','birthday','address','acquisitionSource','leadStage','lastOrder','createdAt'];
        $data=[...array_intersect_key($current,array_flip($fields)),...$input,'chatJid'=>$lid,'createdAt'=>$current['createdAt']??time(),'modifiedAt'=>time()];
        $this->appState->mutate('CustomerData',['chatJid'=>$lid],$data);return $this->projected('CustomerData',['chatJid'=>$lid]);
    }
    public function deleteCustomerData(string $lid):void
    {
        $lid=StateFeatureInputs::contactManager($lid);$this->appState->mutate('CustomerData',['chatJid'=>$lid],null,true);
        if($this->getCustomerData($lid)!==null)throw new \UnexpectedValueException('Customer data deletion was not synchronized');
    }
    /** @return array<string,mixed> */
    public function setGroupHistorySharing(string $group,bool $enabled):array
    {
        $args=['chatJid'=>StateFeatureInputs::groupHistory($group)];
        $this->appState->mutate('GroupHistoryToggle',$args,['groupHistoryToggleMode'=>$enabled?1:2]);
        return $this->projected('GroupHistoryToggle',$args);
    }
    /** Preserves the synchronized contact's other fields; this is not contact creation.
     * @return array<string,mixed> */
    public function setContactBirthday(string $jid,string $birthday):array
    {
        $jid=StateFeatureInputs::chat($jid);$birthday=StateFeatureInputs::contactBirthday($birthday);
        $schema=str_ends_with($jid,'@lid')?'LidContact':'Contact';
        if(str_ends_with($jid,'@g.us'))throw new \InvalidArgumentException('Birthday requires a user contact');
        $args=['id'=>$jid];$current=$this->projector->getAction($schema,$args)??throw new \OutOfBoundsException('A synchronized contact is required before editing its birthday');
        $fields=$schema==='Contact'?['fullName','firstName','lidJid','saveOnPrimaryAddressbook','pnJid','username','birthday']:['fullName','firstName','username','birthday'];
        $data=array_intersect_key($current,array_flip($fields));$data['birthday']=$birthday;
        $this->appState->mutate($schema,$args,$data);return $this->projected($schema,$args);
    }
    /** @return list<array<string,mixed>> */
    public function listPendingCustomerActions(int $limit=100,string $after=''):array{return $this->projector->listActions('BBProPendingCustomerBase',$limit,$after);}
    /** Only clears an action learned from synchronized state; cannot manufacture pending work.
     * @return array<string,mixed> */
    public function clearPendingCustomerAction(string $action):array
    {
        $args=['action'=>StateFeatureInputs::action($action)];
        if($this->projector->getAction('BBProPendingCustomerBase',$args)===null)throw new \OutOfBoundsException('Pending customer action has not been observed');
        $this->appState->mutate('BBProPendingCustomerBase',$args,['pending'=>false]);return $this->projected('BBProPendingCustomerBase',$args);
    }
    /** @param array<string,mixed> $args
     * @return array<string,mixed> */
    private function projected(string $schema,array $args):array
    {
        $record=$this->projector->getAction($schema,$args)??throw new \UnexpectedValueException('State update has no synchronized projection');
        unset($record['_raw']);return $record;
    }

    private function nextLabelId(): string
    {
        $after = '';
        $maximum = 0;
        do {
            $page = $this->projector->listLabels(1000, $after);
            foreach ($page as $label) { $after = $label['id']; if (is_string($after) && preg_match('/^[1-9][0-9]{0,9}$/D', $after) === 1) $maximum = max($maximum, (int)$after); }
        } while (count($page) === 1000);
        foreach ($this->appState->pendingMutations() as $mutation) {
            $event = AppStateSchema::parse($mutation);
            if (($event['schema'] ?? '') === 'LabelEdit' && is_string($event['id'] ?? null) && preg_match('/^[1-9][0-9]{0,9}$/D', $event['id']) === 1) $maximum = max($maximum, (int)$event['id']);
        }
        if ($maximum >= 2147483647) throw new \OverflowException('Label ID space is exhausted');
        return (string)($maximum + 1);
    }

    private function pendingLabel(string $id): bool
    {
        foreach ($this->appState->pendingMutations() as $mutation) {
            $event = AppStateSchema::parse($mutation);
            if (($event['schema'] ?? '') === 'LabelEdit' && ($event['id'] ?? '') === $id) return true;
        }
        return false;
    }

    /** @param array<string,mixed> $record
     * @return array<string,mixed> */
    private static function labelRecord(array $record): array { return array_intersect_key($record, array_flip(['id','name','color','predefinedId','deleted','timestamp'])); }
    /** @param array<string,mixed> $record
     * @return array<string,mixed> */
    private static function replyRecord(array $record): array { return array_intersect_key($record, array_flip(['id','shortcut','message','keywords','count','deleted','timestamp'])); }
    /** @param array<string,mixed> $record
     * @return array<string,mixed> */
    private static function broadcastRecord(array $record): array { return array_intersect_key($record, array_flip(['id','listName','participants','labelIds','timestamp'])); }
    /** @return list<string> */
    private static function projectedLabels(mixed $labels): array { return is_array($labels) ? array_values(array_filter($labels, 'is_string')) : []; }
    /** @param array<string,mixed> $data
     * @param list<string> $allowed */
    private static function fields(array $data, array $allowed): void { foreach (array_keys($data) as $field) if (!in_array($field, $allowed, true)) throw new \InvalidArgumentException('Unknown business tool field '.$field); }
    private static function labelId(string $id): void { if (preg_match('/^[1-9][0-9]{0,9}$/D', $id) !== 1 || (int)$id > 2147483647) throw new \InvalidArgumentException('Label ID must be a positive 32-bit integer string'); }
    private static function quickReplyId(string $id): void { if (preg_match('/^[1-9][0-9]{0,18}$/D', $id) !== 1) throw new \InvalidArgumentException('Quick reply ID must be a positive numeric string'); }
    private static function labelColor(int $color): void { if ($color < 0 || $color > 19) throw new \InvalidArgumentException('Label color must be between 0 and 19'); }
    private static function labelName(string $name): void { self::text($name, 'label name', 100); }
    private static function page(int $limit, string $after): void { if ($limit < 1 || $limit > 1000 || strlen($after) > 256 || str_contains($after, "\0")) throw new \InvalidArgumentException('Invalid business tools pagination'); }
    private static function text(string $value, string $field, int $limit, bool $allowEmpty = false): void { if ((!$allowEmpty && trim($value) === '') || strlen($value) > $limit || preg_match('//u', $value) !== 1 || str_contains($value, "\0")) throw new \InvalidArgumentException('Invalid '.$field); }
}
