<?php
declare(strict_types=1);
namespace MVO\Messenger\Protocol;

/** Original PHP encoders for observed Lightspeed wire fields. No Graph/Pages API. */
final class TaskBuilder
{
    public static function decimalId(string $id): int
    {
        if (!preg_match('/^[1-9][0-9]{0,18}$/D', $id) || strlen($id) > strlen((string)PHP_INT_MAX) || (strlen($id) === strlen((string)PHP_INT_MAX) && strcmp($id,(string)PHP_INT_MAX)>0)) {
            throw new \InvalidArgumentException('Use the exact decimal Facebook ID within signed 64-bit range');
        }
        return (int)$id;
    }

    /** Time-ordered, random low bits; persisted by the caller before a network write. */
    public static function epoch(?int $milliseconds = null): string
    {
        $milliseconds ??= (int)floor(microtime(true)*1000);
        if ($milliseconds < 1 || $milliseconds > (PHP_INT_MAX >> 22)) throw new \InvalidArgumentException('Epoch is outside the supported signed 64-bit range');
        return (string)(($milliseconds << 22) | random_int(0,4194303));
    }

    /** @return array<string,mixed> */
    public static function text(string $threadId, string $text, string $offlineId, int $taskId): array
    {
        $id = self::decimalId($threadId); self::decimalId($offlineId);
        if ($text === '' || strlen($text)>20000 || preg_match('//u',$text)!==1) throw new \InvalidArgumentException('Text must be valid UTF-8 between 1 and 20000 bytes');
        if ($taskId < 1) throw new \InvalidArgumentException('Invalid Lightspeed task ID');
        return self::task('46', ['thread_id'=>$id,'otid'=>$offlineId,'source'=>65537,'send_type'=>1,'sync_group'=>1,
            'text'=>$text,'initiating_source'=>1,'skip_url_preview_gen'=>0,'text_has_links'=>preg_match('~https?://~i',$text)===1?1:0,'multitab_env'=>0], $threadId, $taskId);
    }

    /** @return array<string,mixed> */
    public static function threads(int $taskId, int $syncGroup = 1, ?string $cursor = null): array
    {
        if ($taskId < 1 || !in_array($syncGroup,[1,95],true)) throw new \InvalidArgumentException('Invalid thread fetch');
        return self::task('145',['is_after'=>0,'parent_thread_key'=>-1,'reference_thread_key'=>0,'reference_activity_timestamp'=>9999999999999,
            'additional_pages_to_fetch'=>0,'cursor'=>$cursor,'messaging_tag'=>null,'sync_group'=>$syncGroup], 'trq', $taskId);
    }

    /** @param list<array<string,mixed>> $tasks
     * @return array<string,mixed>
     */
    public static function batch(string $versionId, array $tasks, ?string $epoch = null): array
    {
        if ($tasks===[] || count($tasks)>100) throw new \InvalidArgumentException('Expected 1 to 100 tasks');
        $epoch ??= self::epoch();
        self::decimalId($versionId);
        return ['epoch_id'=>self::decimalId($epoch),'tasks'=>$tasks,'version_id'=>$versionId];
    }

    /** @param array<string,mixed> $payload */
    public static function request(string $appId, int $requestId, int $type, array $payload): string
    {
        self::decimalId($appId);
        if ($requestId<1 || !in_array($type,[1,2,3,4],true)) throw new \InvalidArgumentException('Invalid Lightspeed request');
        return self::json(['app_id'=>$appId,'payload'=>self::json($payload),'request_id'=>$requestId,'type'=>$type]);
    }

    /** @return array<string,mixed> */
    public static function sync(string $versionId, int $database, ?string $cursor, ?string $syncParams): array
    {
        if (!in_array($database,[1,2,95,104],true)) throw new \InvalidArgumentException('Unsupported Lightspeed database');
        if ($cursor===null && $syncParams===null) throw new \InvalidArgumentException('Initial sync parameters are not available');
        // A saved cursor does not imply that the server can resume without a
        // snapshot. Preserve the current bootstrap parameters when supplied.
        return ['database'=>$database,'last_applied_cursor'=>$cursor,'sync_params'=>$syncParams,
            'epoch_id'=>self::decimalId(self::epoch()),'version'=>self::decimalId($versionId),'failure_count'=>null];
    }

    /** @param array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private static function task(string $label,array $payload,string $queue,int $id): array
    {
        return ['failure_count'=>null,'label'=>$label,'payload'=>self::json($payload),'queue_name'=>$queue,'task_id'=>$id];
    }
    /** @param array<mixed> $value */
    private static function json(array $value): string { return json_encode($value,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); }
}
