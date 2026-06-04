<?php

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Urls;

use SuperSheepCopy\Shared\Serialization\SerializationWalkerInterface;

final class StructuredValueReplacer
{
    private UrlReplacementEngineInterface $url_replacer;
    private SerializationWalkerInterface $walker;

    public function __construct(UrlReplacementEngineInterface $url_replacer, SerializationWalkerInterface $walker)
    {
        $this->url_replacer = $url_replacer;
        $this->walker = $walker;
    }

    public function replace(string $value, string $from, string $to): StructuredValueReplacementResult
    {
        if ($this->isSerialized($value)) {
            return $this->replaceSerializedStrings($value, $from, $to);
        }

        $json = json_decode($value, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($json)) {
            $count = 0;
            $replaced = $this->walker->walk($json, function (string $item) use ($from, $to, &$count): string {
                $count += $this->url_replacer->countReplacements($item, $from);

                return $this->url_replacer->replace($item, $from, $to);
            });

            return new StructuredValueReplacementResult(
                (string) json_encode($replaced, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                $count,
                'json'
            );
        }

        return new StructuredValueReplacementResult(
            $this->url_replacer->replace($value, $from, $to),
            $this->url_replacer->countReplacements($value, $from),
            'plain'
        );
    }

    private function isSerialized(string $value): bool
    {
        if ($value === 'b:0;') {
            return true;
        }

        if (!preg_match('/^(a|O|s|i|d|b|N):/', $value)) {
            return false;
        }

        return unserialize($value, array('allowed_classes' => false)) !== false;
    }

    private function replaceSerializedStrings(string $value, string $from, string $to): StructuredValueReplacementResult
    {
        $offset = 0;
        $length = strlen($value);
        $output = '';
        $count = 0;

        while ($offset < $length) {
            if (preg_match('/s:(\d+):"/A', $value, $matches, 0, $offset) !== 1) {
                $output .= $value[$offset];
                ++$offset;
                continue;
            }

            $header = $matches[0];
            $string_length = (int) $matches[1];
            $content_start = $offset + strlen($header);
            $content = substr($value, $content_start, $string_length);
            $terminator_start = $content_start + $string_length;

            if (substr($value, $terminator_start, 2) !== '";') {
                $output .= $value[$offset];
                ++$offset;
                continue;
            }

            $count += $this->url_replacer->countReplacements($content, $from);
            $replaced = $this->url_replacer->replace($content, $from, $to);
            $output .= 's:' . strlen($replaced) . ':"' . $replaced . '";';
            $offset = $terminator_start + 2;
        }

        return new StructuredValueReplacementResult($output, $count, 'serialized');
    }
}
