<?php
declare(strict_types=1);
namespace MVO\Console;
use MVO\Protocol\BinaryData;

/** Shared CLI input; arbitrary message types use a binary-aware JSON payload. */
final class MessageInput
{
    /**
     * @param array<string,mixed> $options
     * @return string|array<string,mixed> */
    public static function parse(array $options):string|array
    {
        $sources = array_intersect(['text','text-file','file','payload-file'],array_keys($options));
        if (count($sources)!==1) throw new \InvalidArgumentException('Informe exatamente um: --text, --text-file, --file ou --payload-file.');
        if (isset($options['payload-file'])) {
            $value = BinaryData::fromJsonInput(json_decode(self::read($options['payload-file'],524288),true,128,JSON_THROW_ON_ERROR));
            if (!is_array($value) || $value===[] || array_is_list($value)) throw new \InvalidArgumentException('Payload JSON deve ser um objeto de mensagem.');
            return $value;
        }
        if (isset($options['file'])) {
            $path = self::path($options['file']);
            $type = $options['type'] ?? 'document';
            if (!is_string($type) || !in_array($type,['image','video','gif','ptv','audio','ptt','document','sticker'],true)) throw new \InvalidArgumentException('Tipo de arquivo invalido.');
            $content = ['type'=>$type,'media'=>['path'=>$path]];
            foreach (['caption'=>'caption','mimetype'=>'mimetype','file-name'=>'fileName'] as $option=>$key) {
                if (!isset($options[$option])) continue;
                if (!is_string($options[$option])) throw new \InvalidArgumentException('Opcao de midia invalida: '.$option);
                $content[$key] = $options[$option];
            }
            return $content;
        }
        $text = isset($options['text-file']) ? self::read($options['text-file'],65536) : $options['text'];
        if (!is_string($text) || trim($text)==='' || strlen($text)>65536 || preg_match('//u',$text)!==1) throw new \InvalidArgumentException('Texto deve ser UTF-8, nao vazio e ate 64 KiB.');
        return $text;
    }
    private static function path(mixed $path):string
    {
        if (!is_string($path) || !is_file($path) || !is_readable($path) || ($absolute = realpath($path))===false) throw new \InvalidArgumentException('Arquivo inexistente ou sem permissao de leitura.');
        return $absolute;
    }
    private static function read(mixed $path,int $limit):string
    {
        $path = self::path($path);
        $value = file_get_contents($path,false,null,0,$limit+1);
        if ($value===false) throw new \RuntimeException('Nao foi possivel ler o arquivo.');
        if (strlen($value)>$limit) throw new \LengthException('Arquivo de entrada excede o limite.');
        return $value;
    }
}
