<?php
declare(strict_types=1);
namespace MVO\Facebook\Protocol;

use MVO\Facebook\FacebookException;

final class DocumentRegistry
{
    /** @var array<string,Document> */ private array $documents=[];
    /** @param list<Document> $documents */
    public function __construct(array $documents=[]){foreach($documents as $document)$this->add($document);}
    public function add(Document $document):void
    {
        if(isset($this->documents[$document->name])&&(array)$this->documents[$document->name]!==(array)$document)throw new \InvalidArgumentException('Conflicting Facebook document contracts');
        $this->documents[$document->name]=$document;
    }
    public function get(string $name):Document{return $this->documents[$name]??throw new FacebookException('facebook_document_unavailable','The current public Web document has not been established for this operation');}
    public function has(string $name):bool{return isset($this->documents[$name]);}
    /** @return list<array{name:string,kind:string,source_sha256:string}> */
    public function inventory():array{return array_values(array_map(static fn(Document $d):array=>['name'=>$d->name,'kind'=>$d->kind,'source_sha256'=>$d->sourceSha256],$this->documents));}
}
