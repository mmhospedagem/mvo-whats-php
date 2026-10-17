<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Message\GroupPreparationException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GroupPreparationExceptionTest extends TestCase
{
    #[DataProvider('safeReasons')]
    public function testDiagnosticContainsOnlySafeEnumsAndKeepsOriginalCausePrivate(string $stage,string $message,string $reason):void
    {
        $original=new \UnexpectedValueException($message);$error=GroupPreparationException::fromFailure('123456@g.us',$stage,$original);
        self::assertSame(['stage'=>$stage,'reason'=>$reason],$error->diagnostic());self::assertContains($reason,GroupPreparationException::REASONS);self::assertSame($original,$error->getPrevious());
        self::assertSame('Group preparation failed at '.$stage.': '.$reason,$error->getMessage());self::assertStringNotContainsString('551199991111',json_encode($error->diagnostic(),JSON_THROW_ON_ERROR));
    }
    public static function safeReasons():iterable
    {
        yield ['metadata','Group metadata response is missing its group','metadata_group_missing'];
        yield ['identity','Conflicting phone linkage','phone_linkage_conflict'];
        yield ['device_discovery','Device discovery response missing usync list','device_response_missing'];
        yield ['device_discovery','No device list returned for group participant 551199991111@s.whatsapp.net','device_list_missing'];
        yield ['device_discovery','Empty device list returned for group participant 551199991111@s.whatsapp.net','device_list_empty'];
        yield ['signal_sessions','WhatsApp key bundle missing or rejected for 551199991111@s.whatsapp.net','signal_bundle_missing'];
        yield ['signal_sessions','Duplicate Signal key bundle','signal_bundle_duplicate'];
        yield ['signal_sessions','Invalid WhatsApp key bundle field','signal_bundle_field_invalid'];
        yield ['signal_sessions','Invalid remote Signal signed prekey signature','signal_signature_invalid'];
        yield ['signal_sessions','Invalid stored Signal session','signal_session_cache_invalid'];
        yield ['signal_sessions','Signal identity changed for 551199991111@s.whatsapp.net','signal_trusted_identity_changed'];
        yield ['signal_sessions','Stored Signal session belongs to a different local identity','signal_local_identity_mismatch'];
        yield ['signal_sessions','Invalid Signal state value','signal_state_invalid'];
        yield ['device_discovery','Query timed out PRIVATE 551199991111','query_timeout'];
        yield ['metadata','Unrecognized PRIVATE 551199991111','preparation_failed'];
    }
}
