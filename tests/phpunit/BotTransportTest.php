<?php
declare(strict_types=1);
namespace MVO\Tests;

use PHPUnit\Framework\TestCase;
use MVO\Message\{BotTransport,MessageCodec};
use MVO\Protocol\{BinaryNode,SchemaCodec};

final class BotTransportTest extends TestCase
{
    public function testDirectPromptCarriesCapabilitiesThreadAndPersistentParentSecret():void
    {
        $prompt=BotTransport::prompt('867051314767696@bot',['conversation'=>'Olá'],['personaId'=>'persona','aiThreadId'=>'thread','botJid'=>'1807055946647696@bot']);
        self::assertSame('867051314767696@bot',$prompt['to']); self::assertSame('thread',$prompt['threadId']);
        $message=SchemaCodec::decode('Message',SchemaCodec::encode('Message',$prompt['message']));
        self::assertSame('Olá',$message['conversation']); self::assertSame(32,strlen($message['messageContextInfo']['messageSecret']));
        $metadata=$message['messageContextInfo']['botMetadata']; self::assertSame('persona',$metadata['personaId']); self::assertCount(15,$metadata['capabilityMetadata']['capabilities']);
        self::assertSame('thread',$message['messageContextInfo']['threadId'][0]['threadKey']['id']);
        self::assertSame('867051314767696@bot',$message['messageContextInfo']['threadId'][0]['threadKey']['remoteJid']);
    }
    public function testMentionPromptKeepsOnlyRenderingMetadataAndBuildsDerivedSecretCopy():void
    {
        $parent=random_bytes(32);
        $prompt=BotTransport::prompt('100-200@g.us',['conversation'=>'@Meta AI olá','messageContextInfo'=>['messageSecret'=>$parent,'botMetadata'=>['personaId'=>'old']]],
            ['botJid'=>'13135550002@s.whatsapp.net','personaId'=>'must-be-ignored','aiThreadId'=>'ignored','extraMentionedJids'=>['200@s.whatsapp.net']]);
        self::assertNull($prompt['threadId']); self::assertSame('867051314767696@bot',BotTransport::invokedBot($prompt['message']));
        $context=$prompt['message']['messageContextInfo']; self::assertSame($parent,$context['messageSecret']);
        self::assertSame(['botRenderingConfigMetadata'],array_keys($context['botMetadata']));
        $inner=$prompt['message']['botInvokeMessage']['message']; self::assertArrayNotHasKey('messageContextInfo',$inner);
        self::assertSame(['867051314767696@bot','200@s.whatsapp.net'],$inner['extendedTextMessage']['contextInfo']['mentionedJid']);
        $copy=BotTransport::sidecarCopy($prompt['message']); self::assertArrayNotHasKey('messageSecret',$copy['messageContextInfo']);
        self::assertSame(hash_hkdf('sha256',$parent,32,'Bot Message'),$copy['messageContextInfo']['botMessageSecret']);
    }
    public function testInnerChunkUsesEditTargetSaltAndRejectsForeignRecipientOrTamperedTag():void
    {
        $parent=random_bytes(32); $iv=random_bytes(12); $salt='first-id'; $target='100@lid'; $author='867051314767696@bot';
        $plain=MessageCodec::text('Parte');
        $key=hash_hkdf('sha256',hash_hkdf('sha256',$parent,32,'Bot Message'),32,$salt.$target.$author);
        $tag=''; $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$salt."\0".$author);
        $event=['id'=>'inner-id','from'=>$author,'proto'=>['secretEncryptedMessage'=>['targetMessageKey'=>['id'=>'prompt'],'encIv'=>$iv,'encPayload'=>$cipher.$tag]]];
        $node=new BinaryNode('message',[],[new BinaryNode('bot',['edit'=>'inner','edit_target_id'=>$salt]),new BinaryNode('meta',['target_sender_jid'=>$target])]);
        $chunk=BotTransport::decryptChunk(['secret'=>$parent],$event,$node,[$target]);
        self::assertSame('Parte',$chunk['text']); self::assertSame('inner',$chunk['editType']); self::assertSame($salt,$chunk['saltId']);
        self::assertNull(BotTransport::decryptChunk(['secret'=>$parent],$event,$node,['999@lid']));
        $event['proto']['secretEncryptedMessage']['encPayload'].='tamper';
        $this->expectException(\UnexpectedValueException::class); BotTransport::decryptChunk(['secret'=>$parent],$event,$node,[$target]);
    }

    public function testAuthenticatedRichStreamingEditsAreReplacementSnapshotsRatherThanNullProtocolText():void
    {
        $parent=random_bytes(32);$target='100@lid';$author='867051314767696@bot';$first='first-response';
        $rich=['richResponseMessage'=>['messageType'=>1,'submessages'=>[['messageType'=>2,'messageText'=>'MVO BOT OK']],
            'unifiedResponse'=>['data'=>json_encode(['sections'=>[['view_model'=>['__typename'=>'GenAISingleLayoutViewModel',
                'primitive'=>['__typename'=>'GenAIMarkdownTextUXPrimitive','text'=>'MVO BOT OK']]]]],JSON_THROW_ON_ERROR)]]];
        foreach (['first','inner','last'] as $i=>$edit) {
            $id=$i===0?$first:'response-'.$i;$salt=$first;$iv=random_bytes(12);
            $proto=$i===0?$rich:['protocolMessage'=>['type'=>14,'key'=>['id'=>$first], 'editedMessage'=>$rich,'timestampMs'=>1792182832500+$i]];
            $plain=SchemaCodec::encode('Message',$proto);$key=hash_hkdf('sha256',hash_hkdf('sha256',$parent,32,'Bot Message'),32,$salt.$target.$author);$tag='';
            $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$salt."\0".$author);
            $event=['id'=>$id,'from'=>$author,'proto'=>['secretEncryptedMessage'=>['targetMessageKey'=>['id'=>'prompt'],'encIv'=>$iv,'encPayload'=>$cipher.$tag]]];
            $node=new BinaryNode('message',[],[new BinaryNode('bot',['edit'=>$edit,'edit_target_id'=>$i===0?'':$first]),new BinaryNode('meta',['target_sender_jid'=>$target])]);
            $chunk=BotTransport::decryptChunk(['secret'=>$parent],$event,$node,[$target]);
            self::assertSame('MVO BOT OK',$chunk['text']);self::assertSame('rich-response',$chunk['type']);self::assertSame('replace',$chunk['responseMode']);
            self::assertSame('prompt',$chunk['targetMessageId']);self::assertSame('parsed',$chunk['richResponse']['status']);self::assertSame($id,$event['id']);
            if ($i>0) self::assertSame(1792182832500+$i,$chunk['revisionTimestampMs']);
        }
    }

    public function testAuthenticatedBotEditCannotTargetAnUnrelatedResponseStream():void
    {
        $parent=random_bytes(32);$iv=random_bytes(12);$target='100@lid';$author='867051314767696@bot';$salt='first';
        $plain=SchemaCodec::encode('Message',['protocolMessage'=>['type'=>14,'key'=>['id'=>'unrelated'],'editedMessage'=>['conversation'=>'wrong']]]);
        $key=hash_hkdf('sha256',hash_hkdf('sha256',$parent,32,'Bot Message'),32,$salt.$target.$author);$tag='';
        $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$salt."\0".$author);
        $event=['id'=>'edit','from'=>$author,'proto'=>['secretEncryptedMessage'=>['targetMessageKey'=>['id'=>'prompt'],'encIv'=>$iv,'encPayload'=>$cipher.$tag]]];
        $node=new BinaryNode('message',[],[new BinaryNode('bot',['edit'=>'inner','edit_target_id'=>$salt])]);
        $this->expectException(\UnexpectedValueException::class);$this->expectExceptionMessage('Bot edit target');
        BotTransport::decryptChunk(['secret'=>$parent],$event,$node,[$target]);
    }

    public function testEditedBotTextAcceptsOnlyItsBoundGroupOrAuthenticatedRecipients():void
    {
        $parent=random_bytes(32);$iv=random_bytes(12);$target='100@lid';$author='867051314767696@bot';$salt='first';$group='100-200@g.us';
        $plain=SchemaCodec::encode('Message',['protocolMessage'=>['type'=>14,'key'=>['id'=>$salt,'remoteJid'=>$group],
            'editedMessage'=>['extendedTextMessage'=>['text'=>'Final','contextInfo'=>['stanzaId'=>'quote']]]]]);
        $key=hash_hkdf('sha256',hash_hkdf('sha256',$parent,32,'Bot Message'),32,$salt.$target.$author);$tag='';
        $cipher=openssl_encrypt($plain,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,$salt."\0".$author);
        $event=['id'=>'last','chat'=>$group,'from'=>$author,'proto'=>['secretEncryptedMessage'=>['targetMessageKey'=>['id'=>'prompt'],'encIv'=>$iv,'encPayload'=>$cipher.$tag]]];
        $node=new BinaryNode('message',[],[new BinaryNode('bot',['edit'=>'last','edit_target_id'=>$salt])]);
        $secret=['secret'=>$parent,'targetChat'=>$group,'expectedBot'=>$author];
        $chunk=BotTransport::decryptChunk($secret,$event,$node,[$target]);
        self::assertSame('Final',$chunk['text']);self::assertSame('replace',$chunk['responseMode']);self::assertSame(['stanzaId'=>'quote'],$chunk['context']);
        self::assertSame(SchemaCodec::fieldNumbers($plain),$chunk['fields']);
        self::assertNull(BotTransport::decryptChunk([...$secret,'expectedBot'=>'1807055946647696@bot'],$event,$node,[$target]));
        $this->expectException(\UnexpectedValueException::class);$this->expectExceptionMessage('another chat');
        BotTransport::decryptChunk([...$secret,'targetChat'=>'200-300@g.us'],$event,$node,[$target]);
    }
}
