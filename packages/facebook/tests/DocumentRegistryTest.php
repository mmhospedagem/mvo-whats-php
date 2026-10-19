<?php
declare(strict_types=1);

namespace MVO\Facebook\Tests;

use MVO\Facebook\Protocol\{Document,DocumentRegistry};
use PHPUnit\Framework\TestCase;

require_once __DIR__.'/../src/FacebookException.php';
require_once __DIR__.'/../src/Protocol/Document.php';
require_once __DIR__.'/../src/Protocol/DocumentRegistry.php';

final class DocumentRegistryTest extends TestCase
{
    /** @param array<string,mixed> $changes */
    private function document(array $changes=[]):Document
    {
        $fields=[
            'name'=>'SyntheticAccountChooserQuery','id'=>'123456789','kind'=>'query',
            'variables'=>['skipLwiDefaultAdAccount','count'],
            'defaults'=>['skipLwiDefaultAdAccount'=>null,'count'=>20],
            'sourceSha256'=>str_repeat('a',64),
            'sourceUrl'=>'https://static.xx.fbcdn.net/rsrc.php/v4/aa/r/synthetic.js',
            'fixedVariables'=>['skipLwiDefaultAdAccount'=>true],
        ];
        return new Document(...array_replace($fields,$changes));
    }

    public function testEquivalentRegistrationIsIdempotent():void
    {
        $registry=new DocumentRegistry([$this->document()]);
        $registry->add($this->document());
        self::assertCount(1,$registry->inventory());
        self::assertSame(['skipLwiDefaultAdAccount'=>true,'count'=>20],$registry->get('SyntheticAccountChooserQuery')->arguments([]));
    }

    public function testSameAssetCannotReplaceAnyPartOfAnExistingContract():void
    {
        foreach([
            ['id'=>'987654321'],
            ['kind'=>'mutation'],
            ['variables'=>['skipLwiDefaultAdAccount','count','anotherField']],
            ['variables'=>['count','skipLwiDefaultAdAccount']],
            ['defaults'=>['skipLwiDefaultAdAccount'=>null,'count'=>'20']],
            ['fixedVariables'=>[]],
            ['fixedVariables'=>['skipLwiDefaultAdAccount'=>false]],
            ['sourceUrl'=>'https://static.xx.fbcdn.net/rsrc.php/v4/aa/r/another.js'],
            ['sourceSha256'=>str_repeat('b',64)],
        ] as $changes){
            $original=$this->document();$registry=new DocumentRegistry([$original]);
            try{
                $registry->add($this->document($changes));
                self::fail('Redefining an existing document contract must fail');
            }catch(\InvalidArgumentException){
                self::assertSame($original,$registry->get($original->name));
                self::assertCount(1,$registry->inventory());
                self::assertSame(['skipLwiDefaultAdAccount'=>true],$registry->get($original->name)->fixedVariables);
            }
        }
    }

    public function testAttemptedPolicyReplacementCannotEnableAccountCreation():void
    {
        $original=$this->document();$registry=new DocumentRegistry([$original]);
        try{$registry->add($this->document(['fixedVariables'=>[]]));}
        catch(\InvalidArgumentException){}
        $this->expectException(\InvalidArgumentException::class);
        $registry->get($original->name)->arguments(['skipLwiDefaultAdAccount'=>false]);
    }

    public function testDistinctDocumentsInTheSameAssetRemainAvailable():void
    {
        $one=$this->document();$two=$this->document(['name'=>'SyntheticOtherQuery','id'=>'987654321']);
        $registry=new DocumentRegistry([$one,$two]);
        self::assertCount(2,$registry->inventory());
        self::assertSame($one,$registry->get($one->name));
        self::assertSame($two,$registry->get($two->name));
    }
}
