<?php
declare(strict_types=1);

use MVO\Messenger\Auth\TwoFactorPageParser;
use MVO\Messenger\MessengerException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/bootstrap.php';

final class TwoFactorPageParserTest extends TestCase
{
    public function testServerActionHiddenFieldsAndActualSubmitNameArePreserved(): void
    {
        $parser = new TwoFactorPageParser();
        $spec = $parser->parse(self::form(), 'https://www.facebook.com/checkpoint/?next=%2Fmessages');
        self::assertNotNull($spec);
        self::assertSame('https://www.facebook.com/checkpoint/?nonce=synthetic&step=2', $spec['url']);
        self::assertSame(['fb_dtsg' => 'synthetic&csrf', 'checkpoint_data' => 'synthetic-private-context', 'submit[Submit Code]' => 'Continue'], $spec['fields']);
        self::assertSame('approvals_code', $spec['code_field']);
        self::assertSame(6, $spec['min_length']);
        self::assertSame(6, $spec['max_length']);
        $post = $parser->submission($spec, '123 456');
        self::assertSame('123456', $post['fields']['approvals_code']);
        self::assertSame($spec['url'], $post['url']);
        self::assertArrayNotHasKey('approvals_code', $spec['fields']);
    }

    public function testSemanticOneTimeCodeInputCanHaveAServerChosenNameAndEightDigits(): void
    {
        $html = str_replace('name="approvals_code" maxlength="6"', 'name="authenticator_code" autocomplete="one-time-code" minlength="8" maxlength="8"', self::form());
        $parser = new TwoFactorPageParser();
        $spec = $parser->parse($html, 'https://www.messenger.com/two_factor/');
        self::assertNotNull($spec);
        self::assertSame('87654321', $parser->submission($spec, '87654321')['fields']['authenticator_code']);
    }

    public function testCaaPageWithoutServerProvidedHtmlFormIsUnavailableWithoutGuessingAnOperation(): void
    {
        $html = '<div><input autocomplete="one-time-code"></div><script>requireLazy(["TwoStepVerificationRoot.react"],function(){});</script>';
        self::assertNull((new TwoFactorPageParser())->parse($html, 'https://www.facebook.com/two_step_verification/'));
    }

    public function testFormsInsideScriptsCommentsAndTemplatesAreNeverSubmitted(): void
    {
        foreach (['<script>%s</script>', '<!--%s-->', '<template>%s</template>', '<textarea>%s</textarea>'] as $wrapper) {
            self::assertNull((new TwoFactorPageParser())->parse(sprintf($wrapper, self::form()), 'https://www.facebook.com/checkpoint/'));
        }
    }

    public function testDisabledAndUnrelatedFormsAreNotSelected(): void
    {
        $html = '<form method="post" action="/login/password/"><input name="email"><input type="password" name="pass"></form>';
        $html .= str_replace('<input name="approvals_code"', '<input disabled name="approvals_code"', self::form());
        self::assertNull((new TwoFactorPageParser())->parse($html, 'https://www.facebook.com/checkpoint/'));
    }

    #[DataProvider('invalidForms')]
    public function testAmbiguousUnsafeAndUnsupportedFormsAreRejected(string $html, string $reason = 'login_two_factor_protocol_unavailable'): void
    {
        try { (new TwoFactorPageParser())->parse($html, 'https://www.facebook.com/checkpoint/'); self::fail('Expected rejection.'); }
        catch (MessengerException $error) { self::assertSame($reason, $error->reason); }
    }

    /** @return iterable<string,array{string,string?}> */
    public static function invalidForms(): iterable
    {
        yield 'two forms' => [self::form() . self::form()];
        yield 'duplicated OTP' => [str_replace('</form>', '<input name="other_otp" autocomplete="one-time-code"></form>', self::form())];
        yield 'duplicate hidden CSRF' => [str_replace('</form>', '<input type="hidden" name="fb_dtsg" value="other"></form>', self::form())];
        yield 'hidden OTP collision' => [str_replace('</form>', '<input type="hidden" name="approvals_code" value="old"></form>', self::form())];
        yield 'external action' => [str_replace('/checkpoint/?nonce=synthetic&amp;step=2', 'https://evil.example/steal', self::form()), 'login_unsafe_redirect'];
        yield 'relative external action' => [str_replace('/checkpoint/?nonce=synthetic&amp;step=2', '//evil.example/steal', self::form()), 'login_unsafe_redirect'];
        yield 'plain HTTP action' => [str_replace('/checkpoint/?nonce=synthetic&amp;step=2', 'http://www.facebook.com/checkpoint/', self::form()), 'login_unsafe_redirect'];
        yield 'submit override' => [str_replace('type="submit"', 'type="submit" formaction="https://evil.example/"', self::form())];
        yield 'two actions' => [str_replace('</form>', '<button type="submit" name="logout">Log out</button></form>', self::form())];
        yield 'password requested' => [str_replace('</form>', '<input type="password" name="pass"></form>', self::form())];
        yield 'password retained' => [str_replace('</form>', '<input type="hidden" name="encpass" value="old-password"></form>', self::form())];
        yield 'attribute ambiguity' => [str_replace('method="post"', 'method="post" method="get"', self::form())];
        yield 'unknown digit pattern' => [str_replace('maxlength="6"', 'maxlength="6" pattern=".*"', self::form())];
        yield 'oversized value' => [str_replace('synthetic-private-context', str_repeat('x', 65537), self::form())];
        yield 'hidden leading field shape' => [str_replace('name="checkpoint_data"', 'name="0"', self::form())];
    }

    #[DataProvider('invalidCodes')]
    public function testBadAuthenticatorCodesAreRejectedLocally(string $code): void
    {
        $parser = new TwoFactorPageParser();
        $spec = $parser->parse(self::form(), 'https://www.facebook.com/checkpoint/');
        self::assertNotNull($spec);
        try { $parser->submission($spec, $code); self::fail('Expected rejection.'); }
        catch (MessengerException $error) { self::assertSame('login_two_factor_invalid_code', $error->reason); }
    }

    /** @return iterable<array{string}> */
    public static function invalidCodes(): iterable
    {
        yield ['12345']; yield ['1234567']; yield ['ABCDEF']; yield ["123\n456"]; yield [str_repeat('1', 200)]; yield ['１２３４５６'];
    }

    public function testServerDigitPatternIsReadWithoutExecutingRegexFromThePage(): void
    {
        foreach (['[0-9]{6}', '^\\d{6}$'] as $pattern) {
            $spec = (new TwoFactorPageParser())->parse(str_replace('maxlength="6"', 'pattern="' . $pattern . '"', self::form()), 'https://www.facebook.com/checkpoint/');
            self::assertNotNull($spec);
            self::assertSame(6, $spec['min_length']); self::assertSame(6, $spec['max_length']);
        }
    }

    private static function form(): string
    {
        return '<form method="post" action="/checkpoint/?nonce=synthetic&amp;step=2">'
            . '<input type="hidden" name="fb_dtsg" value="synthetic&amp;csrf">'
            . '<input type="hidden" name="checkpoint_data" value="synthetic-private-context">'
            . '<input name="approvals_code" maxlength="6" inputmode="numeric">'
            . '<button type="submit" name="submit[Submit Code]" value="Continue">Continue</button></form>';
    }
}
