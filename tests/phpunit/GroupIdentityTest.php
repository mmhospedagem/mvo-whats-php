<?php
declare(strict_types=1);
namespace MVO\Tests;

use MVO\Auth\CredentialStore;
use MVO\Message\GroupTransport;
use MVO\Protocol\BinaryNode;
use MVO\Service\IdentityService;
use MVO\Signal\{SignalProtocol,SignalStore};
use PHPUnit\Framework\TestCase;

final class GroupIdentityTest extends TestCase
{
    private const GROUP='100-200@g.us';private const OWN_PN='5511000000000@s.whatsapp.net';private const OWN_LID='88880000000@lid';
    private string $path;private SignalStore $store;private array $credentials;
    protected function setUp():void
    {
        $this->path=tempnam(sys_get_temp_dir(),'mvo-group-identities-');$this->store=new SignalStore($this->path);
        $this->credentials=[...CredentialStore::fresh(),'registered'=>true,'meJid'=>'5511000000000:5@s.whatsapp.net','meLid'=>self::OWN_LID];
    }
    protected function tearDown():void{$this->store->close();foreach([$this->path,$this->path.'-wal',$this->path.'-shm']as$file)if(is_file($file))unlink($file);}
    private function transport(array $participants,?string $mode=null):GroupTransport
    {
        return new GroupTransport(new SignalProtocol($this->store,$this->credentials),$this->store,
            static fn():BinaryNode=>new BinaryNode('iq',['type'=>'result'],[new BinaryNode('group',['id'=>self::GROUP,...($mode===null?[]:['addressing_mode'=>$mode])],
                array_map(static fn(array $attrs):BinaryNode=>new BinaryNode('participant',$attrs),$participants))]),
            static function(array $users):array{$devices=[];foreach($users as$user)$devices[$user]=[$user===self::OWN_LID?'88880000000:5@lid':$user];return $devices;},
            static function():void{},fn():array=>$this->credentials);
    }
    public function testMissingModeScansEntireRosterAndConvertsOnlyConfirmedPhoneAliases():void
    {
        $plan=$this->transport([['jid'=>self::OWN_PN],['jid'=>'77770000000@hosted.lid','phone_number'=>'5522000000000@s.whatsapp.net']])->plan(self::GROUP);
        self::assertSame('lid',$plan['mode']);self::assertSame('88880000000:5@lid',$plan['sender']);self::assertSame(['77770000000@lid'],$plan['devices']);
        self::assertSame('77770000000@lid',(new IdentityService($this->store))->resolveLid('5522000000000@s.whatsapp.net'));
    }
    public function testMixedRosterUsesConfirmedCachedMappingForPhoneParticipant():void
    {
        (new IdentityService($this->store))->remember('5533000000000@s.whatsapp.net','66660000000@lid',true);
        $plan=$this->transport([['jid'=>self::OWN_PN],['jid'=>'5533000000000@s.whatsapp.net'],['jid'=>'77770000000@lid']])->plan(self::GROUP);
        self::assertSame('lid',$plan['mode']);self::assertSame(['66660000000@lid','77770000000@lid'],$plan['devices']);
    }
    public function testMixedRosterWithUnknownPhoneMappingRejectsInsteadOfInventingLid():void
    {
        $transport=$this->transport([['jid'=>self::OWN_PN],['jid'=>'5533000000000@s.whatsapp.net'],['jid'=>'77770000000@lid']]);
        try{$transport->plan(self::GROUP);self::fail('Unknown participant was invented');}catch(\UnexpectedValueException $error){self::assertStringContainsString('addressing mode',$error->getMessage());}
        self::assertNull((new IdentityService($this->store))->resolveLid('5533000000000@s.whatsapp.net'));self::assertSame([],$this->store->entries('group-membership'));
    }
    public function testExplicitServerPhoneModeOverridesLidRosterWhenPhoneAliasesAreProvided():void
    {
        $plan=$this->transport([['jid'=>self::OWN_LID,'phone_number'=>self::OWN_PN],['jid'=>'77770000000@lid','phone_number'=>'5522000000000@s.whatsapp.net']],'pn')->plan(self::GROUP);
        self::assertSame('pn',$plan['mode']);self::assertSame('5511000000000:5@s.whatsapp.net',$plan['sender']);
        self::assertContains('5522000000000@s.whatsapp.net',$plan['devices']);
    }
    public function testInvalidLaterParticipantDoesNotPartiallyPersistEarlierAliasClaims():void
    {
        $transport=$this->transport([['jid'=>self::OWN_PN],['jid'=>'77770000000@lid','phone_number'=>'5522000000000@s.whatsapp.net'],['jid'=>'5533000000000@s.whatsapp.net']],'lid');
        try{$transport->plan(self::GROUP);self::fail('Incomplete roster accepted');}catch(\UnexpectedValueException){}
        self::assertNull((new IdentityService($this->store))->resolveLid('5522000000000@s.whatsapp.net'));
    }
}
