<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Service\ServiceException;

/** A group plan failed before the caller persisted or wrote its publication.
 * This is not proof about any previously written/nested message; callers must
 * still check that the current publication has no durable outbox record.
 */
final class GroupPreparationException extends \RuntimeException
{
    public const STAGES=['metadata','identity','device_discovery','signal_sessions'];
    public const REASONS=[
        'metadata_group_missing','metadata_group_mismatch','metadata_roster_empty','metadata_query_rejected',
        'participant_alias_invalid','roster_mode_mismatch','participant_identity_invalid','phone_linkage_conflict','identity_succession_invalid',
        'device_response_missing','device_list_missing','device_list_empty','device_identity_mismatch','device_query_rejected',
        'signal_bundle_missing','signal_bundle_duplicate','signal_bundle_field_invalid','signal_registration_invalid',
        'signal_signed_prekey_id_invalid','signal_prekey_id_invalid','signal_signature_invalid','signal_public_key_invalid',
        'signal_trusted_identity_changed','signal_session_identity_mismatch','signal_local_identity_mismatch','signal_session_cache_invalid',
        'signal_binary_encoding_invalid','signal_state_invalid','signal_keypair_invalid','query_timeout','preparation_failed',
    ];

    private function __construct(
        public readonly string $groupJid,
        public readonly string $stage,
        public readonly string $reason,
        public readonly ?string $serverCode,
        \Throwable $previous,
    ){parent::__construct('Group preparation failed at '.$stage.': '.$reason,0,$previous);}

    public static function fromFailure(string $group,string $stage,\Throwable $error):self
    {
        if(!in_array($stage,self::STAGES,true))throw new \InvalidArgumentException('Invalid group preparation stage');
        $message=$error->getMessage();
        $exact=[
            'Group metadata response is missing its group'=>'metadata_group_missing',
            'Group metadata belongs to another group'=>'metadata_group_mismatch',
            'Group metadata has no participant roster'=>'metadata_roster_empty',
            'WhatsApp group metadata query rejected'=>'metadata_query_rejected',
            'Invalid group participant aliases'=>'participant_alias_invalid',
            'Group roster does not match its addressing mode'=>'roster_mode_mismatch',
            'Invalid group participant identity'=>'participant_identity_invalid',
            'Conflicting phone linkage'=>'phone_linkage_conflict',
            'Invalid identity succession'=>'identity_succession_invalid',
            'Device discovery response missing usync list'=>'device_response_missing',
            'Device discovery returned an unexpected group participant'=>'device_identity_mismatch',
            'WhatsApp device discovery rejected a target'=>'device_query_rejected',
            'Duplicate Signal key bundle'=>'signal_bundle_duplicate',
            'Invalid WhatsApp key bundle field'=>'signal_bundle_field_invalid',
            'Invalid remote Signal registration ID'=>'signal_registration_invalid',
            'Invalid remote Signal signed prekey ID'=>'signal_signed_prekey_id_invalid',
            'Invalid remote Signal prekey ID'=>'signal_prekey_id_invalid',
            'Invalid remote Signal signed prekey signature'=>'signal_signature_invalid',
            'Invalid Signal public key'=>'signal_public_key_invalid',
            'Signal session identity mismatch'=>'signal_session_identity_mismatch',
            'Stored Signal session belongs to a different local identity'=>'signal_local_identity_mismatch',
            'Invalid stored Signal session'=>'signal_session_cache_invalid',
            'Invalid Signal binary encoding'=>'signal_binary_encoding_invalid',
            'Invalid Signal base64'=>'signal_binary_encoding_invalid',
            'Invalid Signal state value'=>'signal_state_invalid',
            'Inconsistent Signal keypair'=>'signal_keypair_invalid',
        ];
        $reason=$exact[$message]??match(true){
            str_starts_with($message,'No device list returned for group participant ')=>'device_list_missing',
            str_starts_with($message,'Empty device list returned for group participant ')=>'device_list_empty',
            str_starts_with($message,'WhatsApp key bundle missing or rejected for ')=>'signal_bundle_missing',
            str_starts_with($message,'Signal identity changed for ')=>'signal_trusted_identity_changed',
            str_contains(strtolower($message),'timed out'),str_contains(strtolower($message),'timeout')=>'query_timeout',
            default=>'preparation_failed',
        };
        $code=$error instanceof ServiceException&&is_string($error->serverCode)&&preg_match('/^[0-9]{1,5}$/D',$error->serverCode)===1?$error->serverCode:null;
        return new self($group,$stage,$reason,$code,$error);
    }

    /** Never includes the original error text, participant identities or protocol data.
     * @return array{stage:string,reason:string,serverCode?:string} */
    public function diagnostic():array{return ['stage'=>$this->stage,'reason'=>$this->reason,...($this->serverCode===null?[]:['serverCode'=>$this->serverCode])];}
}
