<?php
declare(strict_types=1);

namespace MVO\Messenger\Tests;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Protocol\LightspeedDecoder;
use MVO\Messenger\Protocol\LightspeedDictionary;
use MVO\Messenger\Protocol\LightspeedIntegerValue;
use MVO\Messenger\Protocol\LightspeedUndefinedValue;
use MVO\Messenger\Protocol\LightspeedVector;
use PHPUnit\Framework\TestCase;

final class LightspeedContainersTest extends TestCase
{
    public function testVectorAppendReturnsSameObjectAndMutatesAllAliases(): void
    {
        $vector = new LightspeedVector();
        $alias = $vector;
        self::assertSame($vector, $alias->append('first'));
        self::assertSame(['first'], $vector->values);
    }

    public function testVectorCopiesInputListButKeepsObjectEntriesShared(): void
    {
        $child = new LightspeedVector();
        $source = [$child, 'original'];
        $vector = new LightspeedVector($source);
        $source[1] = 'changed';
        $child->append('shared');
        self::assertSame('original', $vector->values[1]);
        self::assertSame($child, $vector->values[0]);
        self::assertSame(['shared'], $vector->values[0]->values);
    }

    public function testVectorLimitRejectsAppendWithoutPartialMutation(): void
    {
        $vector = new LightspeedVector(['first'], maximumElements: 1);
        try {
            $vector->append('second');
            self::fail('A full vector must reject another value');
        } catch (MessengerException $exception) {
            self::assertSame('lightspeed_limit', $exception->reason);
        }
        self::assertSame(['first'], $vector->values);
    }

    public function testVectorRejectsOversizedInitialListAndNonListInput(): void
    {
        foreach ([[['first', 'second'], 1, 'lightspeed_limit'], [['field' => 'value'], 5, 'invalid_lightspeed_container']] as [$values, $limit, $reason]) {
            try {
                new LightspeedVector($values, $limit);
                self::fail('Invalid initial array must be rejected');
            } catch (MessengerException $exception) {
                self::assertSame($reason, $exception->reason);
            }
        }
    }

    public function testDictionaryKeepsStringNumberBoolAndI64KeysDistinct(): void
    {
        $dictionary = new LightspeedDictionary();
        $integer = new LightspeedIntegerValue('0');
        self::assertSame($dictionary, $dictionary->set('0', 'string'));
        $dictionary->set(0, 'number')->set(false, 'boolean')->set($integer, 'i64');
        self::assertSame('string', $dictionary->get('0'));
        self::assertSame('number', $dictionary->get(0.0));
        self::assertSame('boolean', $dictionary->get(false));
        self::assertSame('i64', $dictionary->get(new LightspeedIntegerValue('-0')));
        self::assertSame([['0', 'string'], [0, 'number'], [false, 'boolean'], [$integer, 'i64']], $dictionary->entries());
    }

    public function testNumberKeysNormalizeIntegerFloatExponentsAndNegativeZero(): void
    {
        $dictionary = new LightspeedDictionary();
        $dictionary->set(1, 'old')->set(1.0, 'one')->set(100000000000000000, 'large')->set(0, 'zero');
        self::assertSame('one', $dictionary->get(1));
        self::assertSame('large', $dictionary->get(1.0e17));
        self::assertSame('zero', $dictionary->get(-0.0));
        self::assertCount(3, $dictionary->entries());
        self::assertSame(1, $dictionary->entries()[0][0]);
        $dictionary->set(1.25, 'fraction')->set(-1.25, 'negative fraction');
        self::assertSame('fraction', $dictionary->get(1.25));
        self::assertSame('negative fraction', $dictionary->get(-1.25));
    }

    public function testI64KeysPreserveExactRangeAndDoNotCollideWithDecimalStrings(): void
    {
        $dictionary = new LightspeedDictionary();
        foreach (['9223372036854775807', '-9223372036854775808', '9007199254740992', '9007199254740993'] as $index => $decimal) {
            $dictionary->set(new LightspeedIntegerValue($decimal), $index)->set($decimal, 'string ' . $index);
            self::assertSame($index, $dictionary->get(new LightspeedIntegerValue($decimal)));
            self::assertSame('string ' . $index, $dictionary->get($decimal));
        }
        self::assertCount(8, $dictionary->entries());
    }

    public function testNullAndUndefinedAreIgnoredAndMissingNeverBecomesStoredNull(): void
    {
        $dictionary = new LightspeedDictionary();
        $dictionary->set(null, 'ignored')->set(new LightspeedUndefinedValue(), 'ignored')->set('present', null);
        self::assertSame([['present', null]], $dictionary->entries());
        self::assertNull($dictionary->get('present'));
        $first = $dictionary->get('missing');
        self::assertInstanceOf(LightspeedUndefinedValue::class, $first);
        self::assertNotSame($first, $dictionary->get('missing'));
        self::assertInstanceOf(LightspeedUndefinedValue::class, $dictionary->get(null));
        self::assertInstanceOf(LightspeedUndefinedValue::class, $dictionary->get(new LightspeedUndefinedValue()));
    }

    public function testDictionaryAliasesAndEntriesRetainContainerValueIdentity(): void
    {
        $dictionary = new LightspeedDictionary();
        $alias = $dictionary;
        $child = new LightspeedVector();
        self::assertSame($dictionary, $alias->set('child', $child));
        $child->append('shared');
        self::assertSame($child, $dictionary->get('child'));
        self::assertSame($child, $dictionary->entries()[0][1]);
        $entries = $dictionary->entries();
        $entries[0][1] = 'external replacement';
        self::assertSame($child, $dictionary->get('child'));
    }

    public function testDictionaryUpdatesAtCapacityWithoutChangingOrderAndRejectsNewKeyAtomically(): void
    {
        $dictionary = new LightspeedDictionary(maximumElements: 2);
        $dictionary->set('first', 1)->set('second', 2)->set('first', 3);
        try {
            $dictionary->set('third', 4);
            self::fail('A full dictionary must reject a new key');
        } catch (MessengerException $exception) {
            self::assertSame('lightspeed_limit', $exception->reason);
        }
        self::assertSame([['first', 3], ['second', 2]], $dictionary->entries());
        self::assertInstanceOf(LightspeedUndefinedValue::class, $dictionary->get('third'));
    }

    public function testUnsupportedOrNonFiniteKeysFailWithoutLeakingKeyContent(): void
    {
        $dictionary = new LightspeedDictionary();
        $object = (object)['private' => 'PRIVATE KEY'];
        $resource = fopen('php://memory', 'r+');
        self::assertIsResource($resource);
        try {
            foreach ([$object, [], new LightspeedVector(), new LightspeedDictionary(), INF, -INF, NAN, $resource, new LightspeedIntegerValue('9223372036854775808'), new LightspeedIntegerValue('01')] as $key) {
                try {
                    $dictionary->set($key, 'value');
                    self::fail('Unsupported map keys must be rejected');
                } catch (MessengerException $exception) {
                    self::assertSame('invalid_lightspeed_map_key', $exception->reason);
                    self::assertStringNotContainsString('PRIVATE', $exception->getMessage());
                }
                self::assertSame([], $dictionary->entries());
            }
        } finally {
            fclose($resource);
        }
    }

    public function testEmptyAndZeroCapacityContainersAreDistinctAndBounded(): void
    {
        $vector = new LightspeedVector(maximumElements: 0);
        $dictionary = new LightspeedDictionary(maximumElements: 0);
        self::assertSame([], $vector->values);
        self::assertSame([], $dictionary->entries());
        self::assertSame($dictionary, $dictionary->set(null, 'ignored'));
        foreach ([static fn() => $vector->append('item'), static fn() => $dictionary->set('key', 'value')] as $append) {
            try {
                $append();
                self::fail('Zero-capacity containers must remain empty');
            } catch (MessengerException $exception) {
                self::assertSame('lightspeed_limit', $exception->reason);
            }
        }
    }

    public function testNegativeLimitsAreConfigurationErrors(): void
    {
        foreach ([static fn() => new LightspeedVector(maximumElements: -1), static fn() => new LightspeedDictionary(maximumElements: -1)] as $construct) {
            try {
                $construct();
                self::fail('Negative container limits must be rejected');
            } catch (\InvalidArgumentException $exception) {
                self::assertStringContainsString('non-negative', $exception->getMessage());
            }
        }
    }
}
