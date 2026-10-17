<?php
declare(strict_types=1);

namespace MVO\Tests;

use BaconQrCode\Common\ErrorCorrectionLevel;
use BaconQrCode\Encoder\Encoder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use MVO\Auth\QrCode;
use MVO\Console\PairingDisplay;

final class TerminalQrTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function payloads(): iterable
    {
        yield 'short payload' => ['WhatsApp pairing'];
        yield 'WhatsApp-sized pairing payload' => [
            '2@' . str_repeat('aB4xQ9w0', 14) . ',' . base64_encode(str_repeat('N', 32)) . ','
            . base64_encode(str_repeat('I', 32)) . ',' . base64_encode(str_repeat('S', 32)) . ',1',
        ];
    }

    #[DataProvider('payloads')]
    public function testTerminalModulesMatchEncoderAndKeepQuietZone(string $payload): void
    {
        $plain = QrCode::terminal($payload, false);
        $ansi = QrCode::terminal($payload, true);
        self::assertStringNotContainsString($payload, $plain);
        self::assertStringNotContainsString("\r", $plain);
        self::assertStringNotContainsString("\x1b", $plain);
        self::assertStringContainsString("\x1b[", $ansi);
        self::assertSame($plain, self::stripAnsi($ansi));
        foreach (explode("\n", rtrim($ansi, "\n")) as $ansiRow) {
            self::assertStringStartsWith("\x1b[30;47m", $ansiRow, 'Every row needs black ink and a white quiet-zone background.');
            self::assertStringEndsWith("\x1b[0m", $ansiRow, 'QR colours must not leak into later terminal output.');
        }

        $matrix = Encoder::encode($payload, ErrorCorrectionLevel::L(), 'UTF-8')->getMatrix();
        $dimension = $matrix->getWidth() + 8;
        $rows = explode("\n", rtrim($plain, "\n"));
        self::assertCount((int) ceil($dimension / 2), $rows);
        $pixels = [];
        foreach ($rows as $line => $row) {
            $characters = preg_split('//u', $row, -1, PREG_SPLIT_NO_EMPTY);
            self::assertIsArray($characters);
            self::assertCount($dimension, $characters, 'Every rendered row must preserve the white side margins.');
            foreach ($characters as $column => $character) {
                self::assertContains($character, [' ', '▀', '▄', '█']);
                $pixels[$line * 2][$column] = $character === '▀' || $character === '█';
                $pixels[$line * 2 + 1][$column] = $character === '▄' || $character === '█';
            }
        }
        for ($y = 0; $y < $dimension; ++$y) {
            for ($x = 0; $x < $dimension; ++$x) {
                $inMatrix = $x >= 4 && $y >= 4 && $x < $dimension - 4 && $y < $dimension - 4;
                $expected = $inMatrix && $matrix->get($x - 4, $y - 4) === 1;
                self::assertSame($expected, $pixels[$y][$x], "Incorrect module at {$x},{$y}");
            }
        }
        // The odd QR height leaves one additional lower half-module, which must stay white.
        self::assertNotContains(true, $pixels[$dimension]);
    }

    public function testTerminalRejectsEmptyPayload(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        QrCode::terminal('');
    }

    public function testDisplayRotatesQrAndTicksCountdownWithoutReprintingMatrix(): void
    {
        $writes = [];
        $display = new PairingDisplay(static function (string $text) use (&$writes): void { $writes[] = $text; }, true);
        $display->showQr('first-pairing-payload', 60, 1000.0);
        $first = implode('', $writes);
        self::assertStringContainsString("\x1b[?1049h", $first);
        self::assertStringContainsString(QrCode::terminal('first-pairing-payload', true), $first);
        self::assertStringContainsString('60', self::stripAnsi($first));
        self::assertStringNotContainsString('first-pairing-payload', $first);

        $writes = [];
        $display->tick(1001.0);
        $tick = implode('', $writes);
        self::assertStringContainsString('59', self::stripAnsi($tick));
        self::assertStringNotContainsString('▀', $tick);
        self::assertStringNotContainsString('▄', $tick);
        self::assertStringNotContainsString('█', $tick);
        $writes = [];
        $display->tick(1001.2);
        self::assertSame([], $writes, 'Repeated ticks within the same second should not repaint.');

        $display->showQr('second-pairing-payload', 20, 1002.0);
        $second = implode('', $writes);
        self::assertMatchesRegularExpression('/\x1b\[(?:2J|[0-9]*H)/', $second);
        self::assertStringContainsString(QrCode::terminal('second-pairing-payload', true), $second);
        self::assertStringNotContainsString('second-pairing-payload', $second);
        self::assertStringContainsString('20', self::stripAnsi($second));
        $display->close();
    }

    public function testExpiredQrIsClearedAndDoesNotReappearOnLaterTicks(): void
    {
        $writes = [];
        $display = new PairingDisplay(static function (string $text) use (&$writes): void { $writes[] = $text; }, true);
        $display->showQr('expires-pairing-payload', 2, 1000.0);
        $writes = [];
        $display->tick(1002.0);
        $expired = implode('', $writes);
        self::assertStringContainsString("\x1b[?1049l", $expired, 'The expired QR must leave the visible alternate screen.');
        self::assertStringNotContainsString('▀', $expired);
        self::assertStringNotContainsString('▄', $expired);
        self::assertStringNotContainsString('█', $expired);
        $writes = [];
        $display->tick(1010.0);
        self::assertSame([], $writes);
        $display->close();
    }

    public function testPairingAndConnectionEndRestoreTerminal(): void
    {
        foreach (['paired', 'state', 'close'] as $end) {
            $writes = [];
            $display = new PairingDisplay(static function (string $text) use (&$writes): void { $writes[] = $text; }, true);
            $display->showQr('complete-pairing-payload', 60, 1000.0);
            $writes = [];
            match ($end) {
                'paired' => $display->paired('5511999999999@s.whatsapp.net'),
                'state' => $display->state('connected'),
                default => $display->close(),
            };
            self::assertStringContainsString("\x1b[?1049l", implode('', $writes));
            $writes = [];
            $display->close();
            $display->tick(1001.0);
            self::assertSame([], $writes, 'Closing should be idempotent and cancel the countdown.');
        }
    }

    private static function stripAnsi(string $text): string
    {
        return preg_replace('/\x1b\[[0-9;?]*[A-Za-z]/', '', $text) ?? $text;
    }
}
