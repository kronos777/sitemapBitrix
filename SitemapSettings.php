<?php
declare(strict_types=1);

final class SitemapSettings
{
    public static function load(string $file): array
    {
        if (!is_file($file)) {
            throw new RuntimeException('Config not found: ' . $file);
        }
        $config = require $file;
        if (!is_array($config)) {
            throw new InvalidArgumentException('Config must return an array');
        }
        return self::normalize($config, dirname((string) realpath($file)));
    }

    public static function normalize(array $config, string $configDirectory): array
    {
        $defaults = [
            'host' => null,
            'document_root' => null, 'site_id' => 's1', 'batch_size' => 500,
            'index_name' => 'sitemap_index.xml', 'file_prefix' => 'sitemap-auto',
            'work_relative' => '/upload/sitemap-runtime', 'sort_chunk_size' => 10000,
            'max_urls' => 45000, 'max_bytes' => 45000000, 'retention_days' => 7,
            'allow_empty' => false, 'active_date' => false, 'check_permissions' => true,
            'exclude_path_patterns' => ['~^/(?:bitrix|local|upload)(?:/|$)~i'],
        ];
        $unknown = array_diff(array_keys($config), array_merge(array_keys($defaults), ['host', 'root_iblocks']));
        if ($unknown) {
            throw new InvalidArgumentException('Unknown config keys: ' . implode(', ', $unknown));
        }
        $config += $defaults;
        if ($config['host'] !== null) {
            $config['host'] = self::normalizeHost($config['host']);
        }
        if (!is_string($config['site_id']) || !preg_match('~^[a-zA-Z0-9_]{1,32}$~D', $config['site_id'])) {
            throw new InvalidArgumentException('Invalid site_id');
        }
        $config['root_iblocks'] = self::parseIblocks($config['root_iblocks'] ?? null);
        foreach (['batch_size' => [1, 10000], 'sort_chunk_size' => [1, 100000],
            'max_urls' => [1, 50000], 'max_bytes' => [1024, 52428800], 'retention_days' => [1, 3650]] as $key => $range) {
            if (!is_int($config[$key]) || $config[$key] < $range[0] || $config[$key] > $range[1]) {
                throw new InvalidArgumentException('Invalid ' . $key);
            }
        }
        foreach (['allow_empty', 'active_date', 'check_permissions'] as $key) {
            if (!is_bool($config[$key])) {
                throw new InvalidArgumentException($key . ' must be boolean');
            }
        }
        if (!is_string($config['index_name']) || !preg_match('~^[a-zA-Z0-9_-]+\.xml$~D', $config['index_name'])
            || !is_string($config['file_prefix']) || !preg_match('~^[a-z0-9][a-z0-9_-]{0,39}$~D', $config['file_prefix'])) {
            throw new InvalidArgumentException('Invalid index_name or file_prefix');
        }
        if (!is_string($config['work_relative']) || !preg_match('~^/(?:[a-zA-Z0-9_-]+/)*[a-zA-Z0-9_-]+$~D', $config['work_relative'])) {
            throw new InvalidArgumentException('Invalid work_relative');
        }
        if (!is_array($config['exclude_path_patterns'])) {
            throw new InvalidArgumentException('exclude_path_patterns must be an array');
        }
        foreach ($config['exclude_path_patterns'] as $pattern) {
            if (!is_string($pattern) || @preg_match($pattern, '') === false) {
                throw new InvalidArgumentException('Invalid exclude_path_patterns expression');
            }
        }
        $root = $config['document_root'];
        if ($root === null) {
            $root = $configDirectory;
            while (!is_file($root . '/bitrix/modules/main/include/prolog_before.php')) {
                $parent = dirname($root);
                if ($parent === $root) {
                    throw new RuntimeException('Cannot detect document_root; set it explicitly in config');
                }
                $root = $parent;
            }
        }
        if (!is_string($root) || !is_dir($root) || realpath($root) === false) {
            throw new InvalidArgumentException('document_root must be an existing local directory');
        }
        $config['document_root'] = rtrim(str_replace('\\', '/', (string) realpath($root)), '/');
        return $config;
    }

    /** Accepts '10-15,20,25-30', a single ID, or a PHP array of IDs. */
    public static function parseIblocks($input): array
    {
        if (is_string($input) && strlen($input) <= 200000) {
            $parts = explode(',', $input);
        } elseif (is_array($input)) {
            $parts = $input;
        } elseif (is_int($input)) {
            $parts = [$input];
        } else {
            throw new InvalidArgumentException('root_iblocks: use IDs or ranges, e.g. 10-15,20,25-30');
        }
        if (!$parts || count($parts) > 10000) {
            throw new InvalidArgumentException('root_iblocks: specify between 1 and 10000 IDs');
        }
        $ids = [];
        $expanded = 0;
        foreach ($parts as $part) {
            if ((!is_int($part) && !is_string($part)) || !preg_match('~^([1-9][0-9]*)(?:\s*-\s*([1-9][0-9]*))?$~D', trim((string) $part), $matches)) {
                throw new InvalidArgumentException('Invalid root_iblocks ID or range');
            }
            $start = filter_var($matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            $end = filter_var($matches[2] ?? $matches[1], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($start === false || $end === false || $end < $start || $end - $start >= 10000 - $expanded) {
                throw new InvalidArgumentException('Invalid root_iblocks range (maximum 10000 expanded IDs)');
            }
            $expanded += $end - $start + 1;
            for ($id = $start; ; ++$id) {
                $ids[$id] = $id;
                if ($id === $end) {
                    break;
                }
            }
        }
        return array_values($ids);
    }

    /** Called after the Bitrix prolog has loaded; no request domain is trusted. */
    public static function resolveHost(array $config): array
    {
        if ($config['host'] !== null) {
            return $config;
        }
        $result = CSite::GetByID($config['site_id']);
        $site = $result ? $result->Fetch() : false;
        if (!$site || ($site['ACTIVE'] ?? 'N') !== 'Y') {
            throw new RuntimeException('Active Bitrix site not found: ' . $config['site_id']);
        }
        $server = trim((string) ($site['SERVER_NAME'] ?? ''));
        if ($server === '') {
            $server = trim((string) COption::GetOptionString('main', 'server_name', '', $config['site_id']));
        }
        if ($server === '') {
            throw new RuntimeException('Set SERVER_NAME in Bitrix site settings or host in SitemapConfig.php');
        }
        $config['host'] = self::normalizeHost(str_contains($server, '://') ? $server : 'https://' . $server);
        return $config;
    }

    private static function normalizeHost($value): string
    {
        $host = is_string($value) ? parse_url($value) : false;
        if ($host === false || empty($host['host']) || !in_array($host['scheme'] ?? '', ['http', 'https'], true)
            || !in_array($host['path'] ?? '', ['', '/'], true)
            || isset($host['user']) || isset($host['pass']) || isset($host['query']) || isset($host['fragment'])
            || !preg_match('~^[a-z0-9.\[\]:-]+$~iD', $host['host'])) {
            throw new InvalidArgumentException('host must be an http(s) origin, e.g. https://example.ru (IDN: punycode)');
        }
        return strtolower($host['scheme'] . '://' . $host['host']) . (isset($host['port']) ? ':' . $host['port'] : '');
    }
}
