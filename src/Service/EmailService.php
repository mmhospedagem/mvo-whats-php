<?php
declare(strict_types=1);
namespace MVO\Service;
use MVO\Protocol\BinaryNode;

/** Protocol methods for primary/mobile transports. Companion servers normally reject these IQs. */
final class EmailService
{
    public function __construct(private readonly ServiceTransport $transport) {}
    /**
     * @return array{email:?string,verified:bool,confirmed:bool} */
    public function getStatus(): array { return self::status($this->iq('getStatus','get',new BinaryNode('email'))); }
    /**
     * @return array{email:?string,verified:bool,confirmed:bool} */
    public function setEmail(string $email,?string $context=null): array
    {
        if (strlen($email)>320 || filter_var($email,FILTER_VALIDATE_EMAIL) === false) throw new \InvalidArgumentException('Invalid email address'); $nodes=self::context($context); $nodes[]=new BinaryNode('email_address',[],$email); return self::status($this->iq('set','set',new BinaryNode('email',[],$nodes)));
    }
    public function requestVerificationCode(string $languageCode,string $localeCode): void
    {
        if (!preg_match('/^[a-zA-Z]{2,3}$/D',$languageCode) || !preg_match('/^[a-zA-Z]{2,3}$/D',$localeCode)) throw new \InvalidArgumentException('Invalid language/locale');
        $this->iq('requestCode','set',new BinaryNode('verify_email',[],[new BinaryNode('lg',[],$languageCode),new BinaryNode('lc',[],$localeCode)]));
    }
    /**
     * @return array{verified:bool,autoVerifyFailed:bool,email:?string} */
    public function verifyCode(string $code): array
    {
        if (!preg_match('/^[0-9]{6}$/D',$code)) throw new \InvalidArgumentException('Verification code must contain six digits'); $n=$this->iq('verifyCode','set',new BinaryNode('verify_email',[],[new BinaryNode('code',[],$code)]))->child('email');
        return ['verified'=>($n?->attrs['do_verify'] ?? '') === 'true','autoVerifyFailed'=>ServiceTransport::text($n?->child('auto_verify')) === 'fail','email'=>ServiceTransport::text($n?->child('email_address'))];
    }
    public function confirm(?string $context=null): void { $this->iq('confirm','set',new BinaryNode('confirm_email',[],self::context($context))); }
    private function iq(string $op,string $type,BinaryNode $node): BinaryNode { return $this->transport->iq('email.'.$op,$type,'s.whatsapp.net','urn:xmpp:whatsapp:account',[$node]); }
    /**
     * @return list<BinaryNode> */
    private static function context(?string $context): array { if ($context === null) return []; if (!in_array($context,['onboarding','settings'],true)) throw new \InvalidArgumentException('Invalid email context'); return [new BinaryNode('context',[],$context)]; }
    /**
     * @return array{email:?string,verified:bool,confirmed:bool} */
    private static function status(BinaryNode $r): array { $n=$r->child('email'); return ['email'=>ServiceTransport::text($n?->child('email_address')),'verified'=>($n?->attrs['verified'] ?? '') === 'true','confirmed'=>ServiceTransport::text($n?->child('confirmed')) === 'true']; }
}
