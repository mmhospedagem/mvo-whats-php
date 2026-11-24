<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\FacebookException;
use MVO\Facebook\Protocol\PageInboxBootstrapSnapshotReader;
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/PageInboxBootstrapSnapshotFixture.php';

final class PageInboxBootstrapSnapshotReaderTest extends TestCase
{
    public function testOnlyCorrelatedPageDatabaseRowsAreProjectedWithExactIdentifiers():void
    {
        $snapshot=(new PageInboxBootstrapSnapshotReader())->read(PageInboxBootstrapSnapshotFixture::document());
        self::assertSame('page_inbox_bootstrap_lightspeed',$snapshot['source']);self::assertNull($snapshot['has_more']);self::assertNull($snapshot['selected_thread_id']);
        self::assertCount(2,$snapshot['threads']);self::assertCount(2,$snapshot['messages']);
        $thread=$snapshot['threads'][0];
        self::assertSame('50006',$thread['id']);self::assertSame('50006',$thread['selection_id']);self::assertSame('950006',$thread['comm_item_id']);
        self::assertSame('snapshot_thread_key',$thread['selection_source']);self::assertSame(['thread_fbid'=>'50006','other_user_id'=>'60007'],$thread['thread_key']);
        self::assertSame('Dos',$thread['name']);self::assertSame('inbox',$thread['folder']);self::assertSame(2,$thread['unread_count']);self::assertNull($thread['messages_count']);
        self::assertSame('1792500002000',$thread['updated_time_precise']);self::assertFalse($thread['composer_input_disabled']);
        self::assertSame('mid.synthetic.50006',$snapshot['messages'][0]['message_id']);self::assertSame('60007',$snapshot['messages'][0]['sender_id']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE',json_encode($snapshot,JSON_THROW_ON_ERROR));
    }

    public function testSelectionFiltersOnlyTheCurrentSnapshotAndKeepsWireAndBusinessIdsDistinct():void
    {
        $reader=new PageInboxBootstrapSnapshotReader();$snapshot=$reader->read(PageInboxBootstrapSnapshotFixture::document(),'50005');
        self::assertSame('50005',$snapshot['selected_thread_id']);self::assertCount(2,$snapshot['threads']);self::assertCount(1,$snapshot['messages']);
        self::assertSame('50005',$snapshot['messages'][0]['thread_id']);self::assertSame('Synthetic message 50005',$snapshot['messages'][0]['text']);
        foreach(['950005','mid.synthetic.50005','99999']as $id){
            try{$reader->read(PageInboxBootstrapSnapshotFixture::document(),$id);self::fail('Other ID domains must not become a snapshot thread selection');}
            catch(FacebookException $error){self::assertSame('facebook_inbox_selection_mismatch',$error->reason);}
        }
    }

    public function testLimitsAndTimestampFilterOnlyTheLimitedBootstrapWithoutClaimingExhaustion():void
    {
        $reader=new PageInboxBootstrapSnapshotReader();$doc=PageInboxBootstrapSnapshotFixture::document();
        $one=$reader->read($doc,limit:1);self::assertCount(1,$one['threads']);self::assertCount(1,$one['messages']);self::assertNull($one['has_more']);
        $before=$reader->read($doc,beforeTimePrecise:'1792500002000');self::assertSame(['50005'],array_column($before['threads'],'id'));self::assertSame(['50005'],array_column($before['messages'],'thread_id'));self::assertNull($before['has_more']);
        foreach([0,101]as $limit){try{$reader->read($doc,limit:$limit);self::fail('Unbounded limit accepted');}catch(\InvalidArgumentException){}}
        try{$reader->read($doc,beforeTimePrecise:'1e12');self::fail('Invented cursor accepted');}catch(\InvalidArgumentException){}
    }

    public function testCrossPageEpochDatabaseMissingAndConflictingResponsesFailClosed():void
    {
        $f=PageInboxBootstrapSnapshotFixture::fixture();$cases=[];
        $x=$f;$x['require'][1][3][0]['expectedPreloaders'][2]['variables']['pageAssetId']='90009';$cases[]=$x;
        $x=$f;$p=json_decode($x['require'][1][3][0]['expectedPreloaders'][2]['variables']['requestPayload'],true,flags:JSON_THROW_ON_ERROR);$p['epoch_id']='99999';$x['require'][1][3][0]['expectedPreloaders'][2]['variables']['requestPayload']=json_encode($p,JSON_THROW_ON_ERROR);$cases[]=$x;
        $x=$f;$p['epoch_id']='70007';$p['database']=1;$x['require'][1][3][0]['expectedPreloaders'][2]['variables']['requestPayload']=json_encode($p,JSON_THROW_ON_ERROR);$cases[]=$x;
        $x=$f;unset($x['require'][2]);$cases[]=$x;
        $x=$f;$x['require'][2][3][0]='another-preloader';$cases[]=$x;
        $x=$f;$x['require'][1][3][0]['expectedPreloaders'][]=$x['require'][1][3][0]['expectedPreloaders'][2];$cases[]=$x;
        $x=$f;$x['require'][1][3][0]['expectedPreloaders'][2]['variables']['igdAssetId']='90009';$cases[]=$x;
        $x=$f;$x['require'][1][3][0]['expectedPreloaders'][2]['variables']['requestType']=3;$cases[]=$x;
        $x=$f;$r=$x['require'][2];$r[3][1]['__bbox']['result']['data']['viewer']['unified_inbox_lightspeed_web_request']['payload']='{}';$x['require'][]=$r;$cases[]=$x;
        foreach($cases as $case){
            try{(new PageInboxBootstrapSnapshotReader())->read(PageInboxBootstrapSnapshotFixture::document($case));self::fail('Unconfirmed initial response must not expose Page data');}
            catch(FacebookException $error){self::assertContains($error->reason,['facebook_inbox_snapshot_unconfirmed','facebook_inbox_snapshot_unsupported']);}
        }
    }

    public function testPageMembershipMessageSenderBusinessMetadataAndUnknownOpcodeAreAtomic():void
    {
        foreach(['missing_page','unknown_sender','missing_business','wrong_sync_group','unknown_opcode','wrong_message_thread']as $mutation){
            $f=PageInboxBootstrapSnapshotFixture::fixture();$response=&$f['require'][2][3][1]['__bbox']['result']['data']['viewer']['unified_inbox_lightspeed_web_request'];
            $batch=json_decode($response['payload'],true,flags:JSON_THROW_ON_ERROR);$calls=&$batch['step'][2][2];
            foreach($calls as &$call){
                if(!is_array($call))continue;
                if($mutation==='missing_page'&&($call[1]??null)==='LSAddParticipantIdToGroupThread'&&($call[3]??null)===[19,'30003'])$call[3]=[19,'90009'];
                if($mutation==='unknown_sender'&&($call[1]??null)==='LSUpsertMessage')$call[12]=[19,'90009'];
                if($mutation==='wrong_message_thread'&&($call[1]??null)==='LSUpsertMessage')$call[5]=[19,'90009'];
                if($mutation==='missing_business'&&($call[1]??null)==='LSSetBusinessThreadInfo')$call[1]='LSUnknownBusinessMetadata';
                if($mutation==='wrong_sync_group'&&($call[1]??null)==='LSDeleteThenInsertThread')$call[68]=[19,'1'];
            }
            unset($call);if($mutation==='unknown_opcode')$calls[]=[105,'unsupported'];
            $response['payload']=json_encode($batch,JSON_THROW_ON_ERROR);unset($response,$calls);
            try{(new PageInboxBootstrapSnapshotReader())->read(PageInboxBootstrapSnapshotFixture::document($f));self::fail('Unconfirmed rows must abort the whole snapshot');}
            catch(FacebookException $error){self::assertContains($error->reason,['facebook_inbox_snapshot_unconfirmed','facebook_inbox_snapshot_unsupported']);}
        }
    }

    public function testEmptyConfirmedPageSnapshotIsNotPersonalFallbackOrACompletenessClaim():void
    {
        $f=PageInboxBootstrapSnapshotFixture::fixture();$f['require'][2]=PageInboxBootstrapSnapshotFixture::response(205,PageInboxBootstrapSnapshotFixture::batch(205,'70007',[]));
        $reader=new PageInboxBootstrapSnapshotReader();$snapshot=$reader->read(PageInboxBootstrapSnapshotFixture::document($f));
        self::assertSame([],$snapshot['threads']);self::assertSame([],$snapshot['messages']);self::assertNull($snapshot['has_more']);
        // Reader reuse must not carry prior responses or identities into another document.
        self::assertCount(2,$reader->read(PageInboxBootstrapSnapshotFixture::document())['threads']);
        self::assertStringNotContainsString('SYNTHETIC_PRIVATE',print_r($reader,true));
    }
}
