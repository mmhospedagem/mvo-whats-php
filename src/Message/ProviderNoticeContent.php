<?php
declare(strict_types=1);
namespace MVO\Message;

use MVO\Protocol\BinaryNode;

/** An explicit provider notice with an optional third-party service declaration. */
final class ProviderNoticeContent
{
    private const TEXT = 'Esta empresa utiliza a plataforma MVO para gerenciar esta conversa.';
    private const ACTORS_BSP = '2';
    private const STORAGE_ON_PREMISE = '1';

    /** @param array<string,mixed> $options */
    public static function validateOptions(array $options): void
    {
        $format = array_key_exists('format', $options) ? $options['format'] : 'text';
        if (!in_array($format, ['text','native'], true)) throw new \InvalidArgumentException('Provider notice format must be text or native');
        if ($format === 'native') {
            if (array_key_exists('privacyUrl', $options)) throw new \InvalidArgumentException('privacyUrl is available only for text provider notices');
            $text = $options['text'] ?? null;
            if (!is_string($text)) throw new \InvalidArgumentException('Native provider notices require visible text');
            MessageCodec::text($text);
            // Keep normal line breaks and tabs, but reject control-only or invisible carriers.
            $withoutLineBreaks = str_replace(["\r", "\n", "\t"], '', $text);
            $visible = preg_replace('/[\p{Z}\p{Cf}\p{Mn}\p{Me}\s\x{115F}\x{1160}\x{2800}\x{3164}\x{FFA0}]/u', '', $text);
            if (preg_match('/\p{Cc}/u', $withoutLineBreaks) === 1 || $visible === null || $visible === '') {
                throw new \InvalidArgumentException('Native provider notices require visible text without control characters');
            }
            return;
        }
        if (array_key_exists('text', $options)) throw new \InvalidArgumentException('Custom provider notice text is available only in native format');
        if (!array_key_exists('privacyUrl', $options)) return;
        $url = $options['privacyUrl'];
        if (!is_string($url) || $url === '' || preg_match('/[\x00-\x20\x7f]/', $url)
            || preg_match('/[\x00-\x1f\x7f]/', rawurldecode($url))
            || preg_match('/\p{Cc}/u', $url) === 1 || preg_match('/\p{Cc}/u', rawurldecode($url)) === 1) {
            throw new \InvalidArgumentException('privacyUrl must be an HTTPS URL without credentials, a fragment or control characters');
        }
        $parts = parse_url($url);
        if (filter_var($url, FILTER_VALIDATE_URL) === false || !is_array($parts)
            || strtolower($parts['scheme'] ?? '') !== 'https' || empty($parts['host'])
            || isset($parts['user']) || isset($parts['pass']) || array_key_exists('fragment', $parts)) {
            throw new \InvalidArgumentException('privacyUrl must be an HTTPS URL without credentials, a fragment or control characters');
        }
    }

    /** @param array<string,mixed> $options
     * @return array{extendedTextMessage:array{text:string}} */
    public static function build(array $options = []): array
    {
        self::validateOptions($options);
        if (($options['format'] ?? 'text') === 'native') return ['extendedTextMessage' => ['text' => $options['text']]];
        $text = self::TEXT;
        if (array_key_exists('privacyUrl', $options)) $text .= "\nSaiba mais: " . $options['privacyUrl'];
        return ['extendedTextMessage' => ['text' => $text]];
    }

    /** The app chooses the native third-party notice text; these values never claim Meta hosting.
     * @param array<string,mixed> $options
     * @return list<BinaryNode> */
    public static function companionNodes(array $options, int $nowSeconds): array
    {
        self::validateOptions($options);
        if (($options['format'] ?? 'text') !== 'native') return [];
        if ($nowSeconds <= 0) throw new \InvalidArgumentException('Provider notice timestamp must be current positive seconds');
        return [new BinaryNode('biz', [
            'actual_actors'=>self::ACTORS_BSP,
            'host_storage'=>self::STORAGE_ON_PREMISE,
            'privacy_mode_ts'=>(string)$nowSeconds,
        ])];
    }
}
