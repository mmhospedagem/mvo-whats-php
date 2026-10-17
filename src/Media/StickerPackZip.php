<?php
declare(strict_types=1);

namespace MVO\Media;

/** Small, dependency-free ZIP STORE writer; never accepts directory/path entries. */
final class StickerPackZip
{
    /** @param list<array{fileName:string,bytes:string}> $entries */
    public static function create(array $entries, int $maxBytes): string
    {
        if ($entries === [] || count($entries) > 1000) throw new \InvalidArgumentException('Sticker pack requires 1 to 1000 entries');
        $seen = [];
        $body = $central = '';
        foreach ($entries as $entry) {
            $name = $entry['fileName'];
            if ($name === '' || strlen($name) > 255 || $name === '.' || $name === '..' || preg_match('/[\x00-\x1f\x7f\/\\\\:]/', $name)) throw new \InvalidArgumentException('Sticker pack entry requires a safe fileName without directories');
            $folded = strtolower($name);
            if (isset($seen[$folded])) throw new \InvalidArgumentException('Duplicate sticker pack fileName: '.$name);
            $seen[$folded] = true;
            $data = $entry['bytes'];
            if ($data === '') throw new \InvalidArgumentException('Sticker pack entry is empty');
            $length = strlen($data);
            $crc = crc32($data);
            $offset = strlen($body);
            $nameLength = strlen($name);
            if ($offset + $length + strlen($central) + 30 + 46 + 2*$nameLength + 22 > $maxBytes) throw new \LengthException('Sticker pack ZIP exceeds maxBytes');
            $body .= pack('VvvvvvVVVvv',0x04034b50,20,0x800,0,0,0,$crc,$length,$length,$nameLength,0).$name.$data;
            $central .= pack('VvvvvvvVVVvvvvvVV',0x02014b50,20,20,0x800,0,0,0,$crc,$length,$length,$nameLength,0,0,0,0,0,$offset).$name;
        }
        return $body.$central.pack('VvvvvVVv',0x06054b50,0,0,count($entries),count($entries),strlen($central),strlen($body),0);
    }
}
