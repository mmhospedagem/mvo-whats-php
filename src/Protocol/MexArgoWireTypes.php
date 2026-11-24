<?php
declare(strict_types=1);
namespace MVO\Protocol;

/** Built-in MEX wire types from the Zapo #340 Android wire type store export. */
final class MexArgoWireTypes
{
    private const MESSAGE_CAPPING='FetchNewChatMessageCappingInfo';
    private const REACHOUT_TIMELOCK='FetchReachoutTimelock';

    /** @return list<string> */
    public static function names():array
    {
        return [self::MESSAGE_CAPPING,self::REACHOUT_TIMELOCK];
    }

    /** @return array<string,mixed>|null */
    public static function get(string $operation):?array
    {
        $integer=self::block('Int','VARINT',false);
        $string=self::block('String','STRING',true);
        $fields=match ($operation) {
            self::MESSAGE_CAPPING=>[
                'xwa2_message_capping_info'=>['type'=>'NULLABLE','of'=>['type'=>'RECORD','fields'=>[
                    'total_quota'=>$integer,
                    'used_quota'=>$integer,
                    'cycle_start_timestamp'=>$string,
                    'cycle_end_timestamp'=>$string,
                    'server_sent_timestamp'=>$string,
                    'ote_status'=>self::block('XWA2MessageCappingOTEStatus','STRING',true),
                    'mv_status'=>self::block('XWA2MessageCappingMVStatus','STRING',true),
                    'capping_status'=>self::block('XWA2MessageCappingStatus','STRING',true),
                ]]],
            ],
            self::REACHOUT_TIMELOCK=>[
                'xwa2_fetch_account_reachout_timelock'=>['type'=>'RECORD','fields'=>[
                    'is_active'=>['type'=>'BOOLEAN'],
                    'time_enforcement_ends'=>['type'=>'NULLABLE','of'=>$string],
                    'enforcement_type'=>['type'=>'NULLABLE','of'=>self::block('XWA2ReachoutTimelockType','STRING',true)],
                ]],
            ],
            default=>null,
        };
        if ($fields===null) return null;

        return ['type'=>'RECORD','fields'=>[
            'data'=>['type'=>'NULLABLE','of'=>['type'=>'RECORD','fields'=>$fields]],
            'errors'=>['type'=>'ARRAY','of'=>['type'=>'ERROR'],'optional'=>true],
            'extensions'=>['type'=>'EXTENSIONS','optional'=>true],
        ]];
    }

    /** @return array<string,mixed> */
    private static function block(string $key,string $type,bool $dedupe):array
    {
        return ['type'=>'BLOCK','key'=>$key,'dedupe'=>$dedupe,'of'=>['type'=>$type]];
    }
}
