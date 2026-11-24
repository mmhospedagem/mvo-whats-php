<?php
declare(strict_types=1);

namespace MVO\Facebook\Protocol;

/** Internal ephemeral bootstrap document. Never persist, return through HTTP, log or share its HTML. */
final class PageInboxDocument implements \JsonSerializable
{
    public function __construct(
        public readonly PageInboxContext $context,
        #[\SensitiveParameter] private readonly string $html,
        public readonly string $url,
    ) {}

    /** Private account data for inert protocol readers running inside the original session owner. */
    public function html():string{return $this->html;}

    /** @return array<string,mixed> */
    public function __debugInfo():array{return ['origin'=>$this->context->origin,'pageId'=>$this->context->pageId,'html'=>'[PRIVATE]','context'=>'[PRIVATE]','url'=>'[PRIVATE]'];}
    public function jsonSerialize():never{throw new \LogicException('Do not serialize a private Page inbox document');}
    /** @return array<string,mixed> */
    public function __serialize():array{throw new \LogicException('Do not serialize a private Page inbox document');}
}
