<?php

declare(strict_types=1);

namespace Msgpit\Mime;

/**
 * Parses the messages our own SMTP server just accepted.
 *
 * Deliberately not a general purpose library: it handles what mail clients send, not two decades
 * of broken mail from the open internet. The hard parts are already in PHP itself, and they are
 * the reason this stays small: iconv_mime_decode_headers() does header folding and RFC 2047
 * encoded-words, quoted_printable_decode() and base64_decode() do the transfer encodings, and
 * iconv() does the charsets. What is left is splitting on boundaries and recursing.
 */
final class Parser
{
    private const MAX_DEPTH = 20;

    public static function parse(string $raw): ParsedMessage
    {
        $raw = str_replace("\r\n", "\n", $raw);
        [$head, $body] = self::split($raw);

        $headers = self::headers($head);
        $parts = [];

        self::walk($head, $body, $parts);

        return new ParsedMessage(
            headers: $headers,
            subject: $headers['subject'] ?? '',
            from: $headers['from'] ?? '',
            to: $headers['to'] ?? '',
            parts: $parts,
            raw: $raw,
        );
    }

    /**
     * Headers, unfolded and decoded in one go. Doing this by hand is where it goes wrong: the
     * whitespace between two encoded-words has to disappear rather than become a space, and
     * iconv knows that.
     *
     * @return array<string, string> Lowercased names.
     */
    public static function headers(string $head): array
    {
        $decoded = @iconv_mime_decode_headers($head, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');
        $headers = [];

        foreach (is_array($decoded) ? $decoded : [] as $name => $value) {
            // A repeated header (Received, and friends) comes back as an array; keep the first.
            $first = is_array($value) ? ($value[0] ?? '') : $value;

            if (is_string($name) && is_scalar($first)) {
                $headers[strtolower($name)] = (string) $first;
            }
        }

        return $headers;
    }

    /**
     * @param list<Part> $parts
     */
    private static function walk(string $head, string $body, array &$parts, int $depth = 0): void
    {
        if ($depth > self::MAX_DEPTH) {
            return;
        }

        $flat = self::unfold($head);
        $contentType = self::value($flat, 'content-type') ?? 'text/plain';
        $type = strtolower(trim(explode(';', $contentType)[0]));
        $boundary = self::parameter($contentType, 'boundary');

        if (str_starts_with($type, 'multipart/') && $boundary !== null) {
            foreach (self::sections($body, $boundary) as $section) {
                [$sectionHead, $sectionBody] = self::split($section);
                self::walk($sectionHead, $sectionBody, $parts, $depth + 1);
            }

            return;
        }

        $parts[] = self::leaf($flat, $type, $contentType, $body);
    }

    private static function leaf(string $head, string $type, string $contentType, string $body): Part
    {
        $encoding = strtolower(trim(self::value($head, 'content-transfer-encoding') ?? ''));
        $disposition = self::value($head, 'content-disposition');
        $charset = self::parameter($contentType, 'charset');

        $content = match ($encoding) {
            'base64' => (string) base64_decode(preg_replace('/\s+/', '', $body) ?? '', true),
            'quoted-printable' => quoted_printable_decode($body),
            default => $body,
        };

        // Text is stored as UTF-8 so the UI and the API never have to think about charsets.
        if ($charset !== null && str_starts_with($type, 'text/') && strtolower($charset) !== 'utf-8') {
            $converted = @iconv($charset, 'UTF-8//TRANSLIT', $content);
            $content = $converted === false ? $content : $converted;
        }

        $contentId = self::value($head, 'content-id');

        return new Part(
            contentType: $type,
            content: str_starts_with($type, 'text/') ? trim($content, "\n") : $content,
            headers: self::all($head),
            contentId: $contentId === null ? null : trim($contentId, '<>'),
            filename: self::parameter($disposition ?? '', 'filename') ?? self::parameter($contentType, 'name'),
            disposition: $disposition === null ? null : strtolower(trim(explode(';', $disposition)[0])),
            charset: $charset,
        );
    }

    /**
     * The sections between the boundary markers. Anything before the first and after the closing
     * marker is preamble and epilogue, which readers never see.
     *
     * @return list<string>
     */
    private static function sections(string $body, string $boundary): array
    {
        $marker = '--' . $boundary;
        $pieces = explode($marker, $body);

        array_shift($pieces);

        $sections = [];

        foreach ($pieces as $piece) {
            if (str_starts_with($piece, '--')) {
                break;
            }

            $sections[] = ltrim($piece, "\n");
        }

        return $sections;
    }

    /** @return array{0: string, 1: string} */
    private static function split(string $raw): array
    {
        $position = strpos($raw, "\n\n");

        if ($position === false) {
            return [$raw, ''];
        }

        return [substr($raw, 0, $position), substr($raw, $position + 2)];
    }

    private static function unfold(string $head): string
    {
        return (string) preg_replace('/\n[ \t]+/', ' ', $head);
    }

    private static function value(string $unfoldedHead, string $name): ?string
    {
        $pattern = '/^' . preg_quote($name, '/') . ':[ \t]*(.*)$/mi';

        return preg_match($pattern, $unfoldedHead, $matches) === 1 ? trim($matches[1]) : null;
    }

    /** @return array<string, string> */
    private static function all(string $unfoldedHead): array
    {
        $headers = [];

        foreach (explode("\n", $unfoldedHead) as $line) {
            if (preg_match('/^([A-Za-z0-9-]+):[ \t]*(.*)$/', $line, $matches) === 1) {
                $headers[strtolower($matches[1])] = trim($matches[2]);
            }
        }

        return $headers;
    }

    /** Reads boundary=..., charset=..., filename=..., quoted or bare. */
    private static function parameter(string $header, string $name): ?string
    {
        $pattern = '/;[ \t]*' . preg_quote($name, '/') . '\*?[ \t]*=[ \t]*(?:"([^"]*)"|([^;\s]+))/i';

        if (preg_match($pattern, $header, $matches) !== 1) {
            return null;
        }

        $value = $matches[1] !== '' ? $matches[1] : ($matches[2] ?? '');

        // RFC 2231: filename*=UTF-8''name%20with%20spaces
        if (str_contains($value, "''")) {
            [$charset, $encoded] = explode("''", $value, 2);
            $decoded = rawurldecode($encoded);
            $converted = strtolower($charset) === 'utf-8' ? $decoded : @iconv($charset, 'UTF-8//TRANSLIT', $decoded);

            return $converted === false ? $decoded : $converted;
        }

        return $value;
    }
}
