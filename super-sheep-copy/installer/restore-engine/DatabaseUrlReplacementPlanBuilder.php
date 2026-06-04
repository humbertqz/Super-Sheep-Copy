<?php
// phpcs:disable WordPress.WP.AlternativeFunctions.parse_url_parse_url -- Standalone installer cannot rely on wp_parse_url before WordPress is restored.

declare(strict_types=1);

namespace SuperSheepCopyInstaller;

use InvalidArgumentException;

final class DatabaseUrlReplacementPlanBuilder
{
    /**
     * @param array<string,string> $table_map
     * @return array{status:string,source_urls:list<string>,destination_url:string,table_count:int,tables:list<string>,planned_at:string}
     */
    public function build(string $source_site_url, string $source_home_url, string $destination_url, array $table_map, string $planned_at): array
    {
        $destination_url = $this->normalizeUrl($destination_url);
        if ($destination_url === '') {
            throw new InvalidArgumentException('Destination URL is required for URL replacement planning.');
        }

        $source_urls = array();
        foreach (array($source_site_url, $source_home_url) as $source_url) {
            foreach ($this->variants($this->normalizeUrl($source_url)) as $variant) {
                if ($variant !== '' && !in_array($variant, $source_urls, true)) {
                    $source_urls[] = $variant;
                }
            }
        }

        return array(
            'status' => 'planned',
            'source_urls' => $source_urls,
            'destination_url' => $destination_url,
            'table_count' => count($table_map),
            'tables' => array_keys($table_map),
            'planned_at' => $planned_at,
        );
    }

    private function normalizeUrl(string $url): string
    {
        return rtrim(trim($url), '/');
    }

    /**
     * @return list<string>
     */
    private function variants(string $url): array
    {
        if ($url === '') {
            return array();
        }

        $variants = array($url);
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            return $variants;
        }

        foreach (array('http', 'https') as $scheme) {
            $rebuilt = $this->buildUrl($parts, $scheme, (string) $parts['host']);
            if (!in_array($rebuilt, $variants, true)) {
                $variants[] = $rebuilt;
            }

            $host = (string) $parts['host'];
            $alt_host = strpos($host, 'www.') === 0 ? substr($host, 4) : 'www.' . $host;
            $rebuilt = $this->buildUrl($parts, $scheme, $alt_host);
            if (!in_array($rebuilt, $variants, true)) {
                $variants[] = $rebuilt;
            }
        }

        return $variants;
    }

    /**
     * @param array<string,mixed> $parts
     */
    private function buildUrl(array $parts, string $scheme, string $host): string
    {
        $url = $scheme . '://' . $host;
        if (isset($parts['port'])) {
            $url .= ':' . (string) $parts['port'];
        }
        if (isset($parts['path'])) {
            $url .= rtrim((string) $parts['path'], '/');
        }

        return $url;
    }
}
