<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Shared URL replacement code also runs in standalone installer without WordPress loaded.

declare(strict_types=1);

namespace SuperSheepCopy\Shared\Urls;

final class UrlReplacementEngine implements UrlReplacementEngineInterface
{
    public function replace(string $value, string $from, string $to): string
    {
        if ($from === '') {
            return $value;
        }

        foreach ($this->variantPairs($from, $to) as $source => $destination) {
            $value = str_replace($source, $destination, $value);
        }

        return $value;
    }

    public function countReplacements(string $value, string $from): int
    {
        if ($from === '') {
            return 0;
        }

        $sources = array_keys($this->variantPairs($from, $from));

        $pattern = '/' . implode('|', array_map(static function (string $source): string {
            return preg_quote($source, '/');
        }, $sources)) . '/';

        return (int) preg_match_all($pattern, $value);
    }

    /**
     * @return array<string,string>
     */
    private function variantPairs(string $from, string $to): array
    {
        $from_parts = parse_url($from);
        $to_parts = parse_url($to);

        if (!isset($from_parts['host'], $to_parts['host'])) {
            return array($from => $to);
        }

        $from_host = (string) $from_parts['host'];
        $to_host = (string) $to_parts['host'];
        $from_path = isset($from_parts['path']) ? rtrim((string) $from_parts['path'], '/') : '';
        $to_path = isset($to_parts['path']) ? rtrim((string) $to_parts['path'], '/') : '';
        $to_scheme = isset($to_parts['scheme']) ? (string) $to_parts['scheme'] : 'https';
        $to_authority = $to_scheme . '://' . $to_host . (isset($to_parts['port']) ? ':' . (string) $to_parts['port'] : '') . $to_path;

        $sources = array_values(array_unique(array(
            'https://' . $from_host . $from_path,
            'http://' . $from_host . $from_path,
            'https://www.' . preg_replace('/^www\./', '', $from_host) . $from_path,
            'http://www.' . preg_replace('/^www\./', '', $from_host) . $from_path,
            '//' . $from_host . $from_path,
        )));

        $pairs = array();
        foreach ($sources as $source) {
            $destination = strpos($source, '//') === 0 ? '//' . $to_host . (isset($to_parts['port']) ? ':' . (string) $to_parts['port'] : '') . $to_path : $to_authority;
            $pairs[$source] = $destination;
            $pairs[str_replace('/', '\/', $source)] = str_replace('/', '\/', $destination);
            $pairs[rawurlencode($source)] = rawurlencode($destination);
        }

        uksort($pairs, static function (string $a, string $b): int {
            return strlen($b) <=> strlen($a);
        });

        return $pairs;
    }
}
