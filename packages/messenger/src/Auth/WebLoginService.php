<?php
declare(strict_types=1);

namespace MVO\Messenger\Auth;

use MVO\Messenger\MessengerException;
use MVO\Messenger\Transport\{PublicBundleLoader,WebLoginCurlTransport,WebLoginResponse,WebLoginTransport};

/** Facebook-account login through the currently observed Messenger Web HTML form. */
final class WebLoginService
{
    private readonly WebLoginTransport $transport;
    private ?WebLoginResponse $challengeResponse = null;
    private ?string $challengeUrl = null;
    private int $challengeExpiresAt = 0;
    private string $challengeType = 'two_factor';
    /** @var array<string,mixed>|null */ private ?array $challengeSpec = null;
    private bool $confirmationConsumed = false;
    private readonly \Closure $bundleLoader;

    /** @param callable(list<string>):list<string>|null $bundleLoader */
    public function __construct(?WebLoginTransport $transport = null,?callable $bundleLoader = null)
    {
        $this->transport = $transport ?? new WebLoginCurlTransport();
        $this->bundleLoader=$bundleLoader===null?(new PublicBundleLoader())->loadTwoFactorBundles(...):\Closure::fromCallable($bundleLoader);
    }

    public function login(#[\SensitiveParameter] string $email, #[\SensitiveParameter] string $password): CookieJar
    {
        if (trim($email) === '' || strlen($email) > 320 || preg_match('/[\x00-\x1f\x7f]/', $email) || $password === '' || strlen($password) > 4096) {
            throw new MessengerException('login_invalid_credentials', 'Informe um identificador e uma senha válidos para o login.');
        }
        $this->cancel();
        $fields = [];
        try {
            [$page, $url] = $this->follow('GET', 'https://www.messenger.com/');
            $parser = new WebLoginPageParser();
            $form = $parser->parse($page->body, $url);
            $fields = $form['fields'];
            $timestamp = (string) time();
            if (array_key_exists('lgnjs', $fields)) $fields['lgnjs'] = $timestamp;
            $fields['email'] = trim($email);
            $fields[$form['password_field']] = PasswordEncryption::encrypt($form['key_id'], $form['public_key'], $password, $timestamp);
            [$response] = $this->follow('POST', $form['url'], $fields);
            self::checkChallenge($response, $form['url']);
            return $this->verifyIdentity();
        } finally {
            if (function_exists('sodium_memzero')) sodium_memzero($password);
            $fields = [];
            if ($this->challengeResponse === null) $this->transport->clear();
        }
    }

    /** Only this projection may leave the private, memory-only login context.
     * @return array{type:string,expires_at:int,ready:bool}|null
     */
    public function challenge(): ?array
    {
        if ($this->challengeResponse === null || $this->challengeUrl === null) return null;
        if ($this->challengeExpiresAt <= time()) { $this->cancel(); return null; }
        return ['type'=>$this->challengeType,'expires_at'=>$this->challengeExpiresAt,'ready'=>$this->challengeType==='two_factor'&&$this->challengeSpec!==null&&!$this->confirmationConsumed];
    }

    public function confirm(#[\SensitiveParameter] string $code): CookieJar
    {
        try {
            if ($this->challenge() === null) throw new MessengerException('login_challenge_expired','A verificação expirou; inicie o login novamente no formulário local.');
            if($this->challengeType==='captcha')throw new MessengerException('login_captcha_required','O desafio atual exige CAPTCHA humano no Facebook; não é uma solicitação de código do autenticador.');
            if($this->confirmationConsumed)throw new MessengerException('login_continuation_required','Esta confirmação já foi enviada; o Facebook ainda não confirmou uma sessão utilizável.');
            if (preg_match('/^[0-9]{6,8}$/D',$code) !== 1) throw new MessengerException('login_code_invalid','Informe o código numérico atual do aplicativo autenticador.');
            if($this->challengeSpec===null||$this->challengeUrl===null)throw new MessengerException('login_challenge_unavailable','O formato desta etapa de verificação ainda precisa ser reconhecido pelo conector.');
            $handler=new TwoFactorService($this->transport);$spec=$this->challengeSpec;$url=$this->challengeUrl;
            try{$response=$handler->submit($spec,$code);}
            catch(MessengerException $error){
                if($error->reason==='login_transport_failed'){$this->challengeSpec=null;throw new MessengerException('login_two_factor_outcome_uncertain','O resultado da confirmação é incerto; o código não será reenviado automaticamente.');}
                throw $error;
            }
            $expectedId=null;
            if(($spec['kind']??null)==='graphql'){
                try{$result=$handler->result($response,$url);}
                catch(MessengerException $error){
                    if($error->reason==='login_two_factor_protocol_unavailable'){$this->challengeSpec=null;throw new MessengerException('login_two_factor_outcome_uncertain','A resposta da confirmação não foi reconhecida; o código não será reenviado automaticamente.');}
                    throw $error;
                }
                $this->confirmationConsumed=true;$this->challengeSpec=null;$expectedId=$result['user_id'];
                if($result['next']!==null)$this->follow('GET',$result['next'],[],false);
            }else{
                $this->challengeSpec=null;
                $continued=$response;$continuedUrl=is_string($spec['form']['url']??null)?$spec['form']['url']:$url;
                if(in_array($response->status,[301,302,303],true)){
                    $location=$response->header('location');
                    if($location===null)throw new MessengerException('login_http_rejected','A confirmação retornou um redirecionamento incompleto.');
                    [$continued,$continuedUrl]=$this->follow('GET',WebLoginPageParser::resolveUrl($continuedUrl,$location),[],false);
                }elseif($response->status!==200)throw new MessengerException('login_two_factor_outcome_uncertain','A resposta da confirmação não foi reconhecida; o código não será reenviado automaticamente.');
                $retry=$handler->prepare($continued,$continuedUrl);
                if($retry!==null){$this->challengeResponse=$continued;$this->challengeUrl=$continuedUrl;$this->challengeSpec=$retry;throw new MessengerException('login_two_factor_invalid_code','O Facebook manteve o formulário de verificação; confira o código atual.');}
                $this->confirmationConsumed=true;
            }
            try{$jar=$this->verifyIdentity(false);}
            catch(MessengerException $error){
                if($this->confirmationConsumed&&in_array($error->reason,['login_rejected','login_unconfirmed'],true))throw new MessengerException('login_continuation_required','A confirmação foi enviada, mas o Facebook ainda não confirmou uma sessão utilizável.');
                throw $error;
            }
            if($expectedId!==null&&$expectedId!==$jar->accountId())throw new MessengerException('login_identity_mismatch','A identidade final não corresponde à confirmação do código.');
            $this->cancel();return $jar;
        }catch(MessengerException $error){
            if($error->reason==='login_captcha_required'&&$this->challengeResponse!==null){$this->challengeType='captcha';$this->challengeSpec=null;}
            throw $error;
        } finally { if ($code !== '') sodium_memzero($code); }
    }

    public function cancel(): void
    {
        $this->challengeResponse = null; $this->challengeUrl = null; $this->challengeExpiresAt = 0;
        $this->challengeSpec=null;$this->challengeType='two_factor';$this->confirmationConsumed=false;
        $this->transport->clear();
    }

    /** Private application boundary for an optional human-operated browser.
     * Never return this object through HTTP, tools, events, logs or diagnostics.
     * @return array{url:string,cookies:list<array<string,mixed>>,expires_at:int}
     */
    public function exportPrivateChallenge():array
    {
        if($this->challenge()===null||$this->challengeUrl===null)throw new MessengerException('login_challenge_expired','A verificação expirou; inicie outra tentativa pelo formulário local.');
        return ['url'=>$this->challengeUrl,'cookies'=>$this->transport->cookies(),'expires_at'=>$this->challengeExpiresAt];
    }

    private function verifyIdentity(bool $captureVerification=true): CookieJar
    {
        try { $jar = new CookieJar($this->transport->cookies()); }
        catch (\InvalidArgumentException) { throw new MessengerException('login_rejected', 'O Facebook não confirmou o login. Confira as credenciais ou conclua a verificação no aplicativo/site.'); }
        $verification = null;
        foreach (['https://www.messenger.com/', 'https://www.facebook.com/messages/'] as $candidate) {
            $header = $jar->headerFor($candidate);
            if (preg_match('/(?:^|; )c_user=/', $header) && preg_match('/(?:^|; )xs=/', $header)) { $verification = $candidate; break; }
        }
        if ($verification === null) throw new MessengerException('login_unconfirmed', 'Os cookies recebidos não autorizam um destino de verificação suportado.');
        [$confirmed] = $this->follow('GET', $verification,[],$captureVerification);
        try { $jar = new CookieJar($this->transport->cookies()); }
        catch (\InvalidArgumentException) { throw new MessengerException('login_unconfirmed', 'O servidor não manteve uma sessão válida depois da verificação.'); }
        $id = (new WebLoginPageParser())->userId($confirmed->body);
        if ($id === null) throw new MessengerException('login_unconfirmed', 'O servidor não confirmou uma identidade autenticada depois do login.');
        if ($id !== $jar->accountId()) throw new MessengerException('login_identity_mismatch', 'A identidade confirmada não corresponde à sessão recebida.');
        return $jar;
    }

    /**
     * @param array<string,string> $form
     * @return array{WebLoginResponse,string}
     */
    private function follow(string $method, #[\SensitiveParameter] string $url, #[\SensitiveParameter] array $form = [],bool $captureVerification=true): array
    {
        for ($redirect = 0; $redirect <= 5; ++$redirect) {
            WebLoginCurlTransport::validateUrl($url);
            $response = $this->transport->request($method, $url, $form);
            $twoFactor = self::isTwoFactorUrl($url);
            if($twoFactor&&$response->status===200&&$captureVerification)$this->captureChallenge($response,$url);
            if($twoFactor&&$response->status===200&&!$captureVerification){
                $method=(new TwoFactorService($this->transport))->method($response,$url);
                if($method!==null&&str_contains($method,'CAPTCHA'))$this->captureChallenge($response,$url);
            }
            self::checkChallenge($response, $url, $twoFactor);
            if (!in_array($response->status, [301, 302, 303, 307, 308], true)) {
                if ($response->status !== 200) throw new MessengerException('login_http_rejected', 'O servidor não aceitou a requisição de login.');
                return [$response, $url];
            }
            if ($redirect === 5) throw new MessengerException('login_redirect_limit', 'O fluxo de login excedeu o limite de redirecionamentos.');
            $location = $response->header('location');
            if ($location === null) throw new MessengerException('login_http_rejected', 'O servidor retornou um redirecionamento de login incompleto.');
            $url = WebLoginPageParser::resolveUrl($url, $location);
            self::checkChallenge(new WebLoginResponse(200, ''), $url, self::isTwoFactorUrl($url));
            if ($method === 'POST' && in_array($response->status, [307, 308], true)) throw new MessengerException('login_redirect_unsupported', 'O login pediu reenvio de credenciais; a tentativa não será repetida automaticamente.');
            $method = 'GET'; $form = [];
        }
        throw new \LogicException('Unreachable login redirect state');
    }

    private function captureChallenge(#[\SensitiveParameter] WebLoginResponse $response,#[\SensitiveParameter] string $url):never
    {
        $handler=new TwoFactorService($this->transport);$method=$handler->method($response,$url);
        $this->challengeResponse=$response;$this->challengeUrl=$url;$this->challengeExpiresAt=time()+600;
        $this->confirmationConsumed=false;$this->challengeSpec=null;
        $this->challengeType=$method!==null&&str_contains($method,'CAPTCHA')?'captcha':'two_factor';
        if($this->challengeType==='captcha')throw new MessengerException('login_captcha_required','O Facebook exige um CAPTCHA humano de pré-autenticação; este desafio não aceita o código TOTP.');
        $this->challengeSpec=$handler->prepare($response,$url);
        if($this->challengeSpec===null&&$method==='TOTP'){
            $urls=$handler->bundleUrls($response->body);
            if($urls!==[])$this->challengeSpec=$handler->prepare($response,$url,($this->bundleLoader)($urls));
        }
        throw new MessengerException('login_two_factor_required','Informe o código do autenticador no painel local quando o desafio reconhecido estiver pronto.');
    }

    private static function isTwoFactorUrl(#[\SensitiveParameter] string $url): bool
    {
        $path = strtolower(parse_url($url,PHP_URL_PATH)??'');
        return str_contains($path,'two_factor') || str_contains($path,'two_step');
    }

    private static function checkChallenge(#[\SensitiveParameter] WebLoginResponse $response, #[\SensitiveParameter] string $url, bool $allowTwoFactor = false): void
    {
        $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
        if ($response->status === 429 || str_contains($path, '/blocked')) throw new MessengerException('login_rate_limited', 'O Facebook limitou novas tentativas de login; aguarde antes de tentar novamente.');
        if (!$allowTwoFactor && self::isTwoFactorUrl($url)) throw new MessengerException('login_two_factor_required', 'Confirme a autenticação em duas etapas no Facebook; a biblioteca não contorna essa verificação.');
        if (str_contains($path, 'checkpoint') || str_contains($path, 'challenge') || (!$allowTwoFactor && preg_match('~<(?:form|input)\b[^>]*(?:id|name)\s*=\s*["\'][^"\']*(?:checkpoint|approvals_code)[^"\']*["\']~i', $response->body))) throw new MessengerException('login_challenge_required', 'O Facebook exige confirmação de segurança no aplicativo ou navegador.');
        if (str_contains($path, 'captcha') || preg_match('~<(?:form|input|iframe)\b[^>]*(?:id|name|src)\s*=\s*["\'][^"\']*captcha[^"\']*["\']~i', $response->body)) throw new MessengerException('login_captcha_required', 'O Facebook exige um CAPTCHA; conclua a verificação pelo aplicativo ou navegador.');
        if (str_contains($path, '/consent')) throw new MessengerException('login_consent_required', 'O Facebook exige uma decisão de consentimento no aplicativo ou navegador.');
    }

    public function __debugInfo(): array { return ['transport' => '[PRIVATE LOGIN CONTEXT]','challenge'=>$this->challenge()]; }
    public function __destruct() { if ($this->challengeResponse !== null) $this->cancel(); }
}
