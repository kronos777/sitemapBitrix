<?php
declare(strict_types=1);

final class SitemapUrl
{
    private array $origin;

    public function __construct(private array $config)
    {
        $this->origin = parse_url($config['host']);
    }

    public function fromElement(array $element): ?string
    {
        $url = (string) ($element['DETAIL_PAGE_URL'] ?? '');
        $encoding = defined('LANG_CHARSET') ? LANG_CHARSET : (defined('SITE_CHARSET') ? SITE_CHARSET : 'UTF-8');
        if (strcasecmp($encoding, 'UTF-8') !== 0) {
            if (!function_exists('iconv')) {
                throw new RuntimeException('iconv is required for non-UTF-8 Bitrix sites');
            }
            $converted = iconv($encoding, 'UTF-8', $url);
            if ($converted === false) {
                throw new RuntimeException('Cannot convert element URL to UTF-8');
            }
            $url = $converted;
        }
        $url = trim($url);
        // GetNext(false, false) возвращает исходные &, повторное HTML decode меняет URL.
        if ($url === '' || preg_match('~[\x00-\x20<>"#\\\\]~', $url) || preg_match('//u', $url) !== 1) {
            return null;
        }
        if (str_starts_with($url, '//')) {
            $url = $this->origin['scheme'] . ':' . $url;
        } elseif (str_starts_with($url, '/')) {
            $url = $this->config['host'] . $url;
        }
        $parts = parse_url($url);
        if ($parts === false || isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])
            || strtolower($parts['scheme'] ?? '') !== $this->origin['scheme']
            || strtolower($parts['host'] ?? '') !== $this->origin['host']
            || $this->port($parts) !== $this->port($this->origin)) {
            return null;
        }
        $path = $parts['path'] ?? '/';
        $decoded = rawurldecode($path);
        if (preg_match('~[\x00-\x20\\\\]~', $decoded) || preg_match('~(?:^|/)\.{1,2}(?:/|$)~', $decoded)) {
            return null;
        }
        foreach ($this->config['exclude_path_patterns'] as $pattern) {
            if (preg_match($pattern, $path) || preg_match($pattern, $decoded)) {
                return null;
            }
        }
        $url = $this->config['host'] . $path . (isset($parts['query']) ? '?' . $parts['query'] : '');
        return strlen($url) < 2048 ? $url : null;
    }

    private function port(array $parts): int
    {
        return $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
    }

    public static function lastmod(array $element): ?string
    {
        $raw = trim((string) ($element['TIMESTAMP_X'] ?? ''));
        if ($raw === '') {
            return null;
        }
        try {
            if (class_exists('Bitrix\\Main\\Type\\DateTime')) {
                $date = new \Bitrix\Main\Type\DateTime($raw);
                return $date->format('Y-m-d');
            }
            $date = new DateTimeImmutable($raw);
            $errors = DateTimeImmutable::getLastErrors();
            return $errors !== false && ($errors['warning_count'] || $errors['error_count']) ? null : $date->format('Y-m-d');
        } catch (Throwable $error) {
            return null;
        }
    }
}
