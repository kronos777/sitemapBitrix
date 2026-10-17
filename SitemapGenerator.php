<?php
declare(strict_types=1);

final class SitemapGenerator
{
    public function __construct(private array $config) {}

    public function run(bool $test = false): array
    {
        if (!class_exists('XMLReader')) {
            throw new RuntimeException('PHP extension xmlreader is required');
        }
        $root = $this->config['document_root'];
        $lockPath = $root . '/.sitemap-' . substr(hash('sha256', $this->config['index_name']), 0, 16) . '.lock';
        if (is_link($lockPath)) {
            throw new RuntimeException('Lock must not be a symlink');
        }
        $lock = fopen($lockPath, 'c');
        if ($lock === false) {
            throw new RuntimeException('Cannot open sitemap lock');
        }
        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                throw new RuntimeException('Another generation is running', 3);
            }
            return $this->generate($root, $test);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    private function generate(string $root, bool $test): array
    {
        $started = microtime(true);
        $work = $root . $this->config['work_relative'];
        if (!is_dir($work) && !mkdir($work, 0775, true) && !is_dir($work)) {
            throw new RuntimeException('Cannot create work directory');
        }
        $resolved = str_replace('\\', '/', (string) realpath($work));
        if (!str_starts_with($resolved . '/', $root . '/')) {
            throw new RuntimeException('Work directory must stay inside document_root');
        }
        $token = gmdate('YmdHis') . '-' . bin2hex(random_bytes(8));
        $stage = $work . '/run-' . $token;
        if (!mkdir($stage, 0700)) {
            throw new RuntimeException('Cannot create staging directory');
        }
        $stats = [
            'processed' => 0, 'included' => 0, 'invalid' => 0, 'duplicate' => 0,
            'invalid_lastmod' => 0, 'invalid_samples' => [], 'warnings' => [], 'test' => $test,
        ];
        try {
            $store = new SitemapUrlStore($stage, $this->config['sort_chunk_size']);
            $urls = new SitemapUrl($this->config);
            (new SitemapRepository($this->config))->iterate(function (array $element) use ($store, $urls, &$stats): void {
                ++$stats['processed'];
                $url = $urls->fromElement($element);
                if ($url === null) {
                    ++$stats['invalid'];
                    if (count($stats['invalid_samples']) < 10) {
                        $stats['invalid_samples'][] = ['iblock' => $element['IBLOCK_ID'], 'id' => $element['ID']];
                    }
                    return;
                }
                $lastmod = SitemapUrl::lastmod($element);
                if ($lastmod === null && !empty($element['TIMESTAMP_X'])) {
                    ++$stats['invalid_lastmod'];
                }
                $store->add($url, $lastmod);
            });
            $writer = new SitemapXmlWriter($stage, $this->config['file_prefix'] . '-' . $token,
                $this->config['max_urls'], $this->config['max_bytes']);
            foreach ($store->records() as [$url, $lastmod]) {
                $writer->add($url, $lastmod === '' ? null : $lastmod);
                ++$stats['included'];
            }
            if (!$stats['included'] && !$this->config['allow_empty']) {
                throw new RuntimeException('No valid URLs; previous sitemap preserved (allow_empty=false)');
            }
            $stats['duplicate'] = $stats['processed'] - $stats['invalid'] - $stats['included'];
            $files = $writer->finish($this->config['host'], $this->config['index_name']);
            $store->cleanup();
            foreach (array_merge($files, [$this->config['index_name']]) as $name) {
                $this->validateXml($stage . '/' . $name, $name === $this->config['index_name'] ? 'sitemapindex' : 'urlset');
            }
            if ($test) {
                $stats['preview_directory'] = $stage;
            } else {
                $this->publish($root, $work, $stage, $token, $files, $stats);
            }
            $stats['files'] = count($files);
            $stats['index_url'] = $this->config['host'] . '/' . $this->config['index_name'];
            $stats['seconds'] = round(microtime(true) - $started, 3);
            $stats['peak_memory_bytes'] = memory_get_peak_usage(true);
            return $stats;
        } catch (Throwable $error) {
            throw new RuntimeException($error->getMessage() . '; staging: ' . $stage, (int) $error->getCode(), $error);
        }
    }

    private function validateXml(string $path, string $rootName): void
    {
        if (!is_file($path) || filesize($path) > 52428800) {
            throw new RuntimeException('Invalid XML size: ' . basename($path));
        }
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        $reader = new XMLReader();
        try {
            if (!$reader->open($path, null, LIBXML_NONET)) {
                throw new RuntimeException('Cannot read XML: ' . $path);
            }
            $foundRoot = false;
            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::DOC_TYPE) {
                    throw new RuntimeException('DOCTYPE is not allowed');
                }
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->depth === 0) {
                    $foundRoot = $reader->localName === $rootName
                        && $reader->namespaceURI === 'http://www.sitemaps.org/schemas/sitemap/0.9';
                }
            }
            if (!$foundRoot || libxml_get_errors()) {
                throw new RuntimeException('Malformed XML: ' . basename($path));
            }
        } finally {
            $reader->close();
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function publish(string $root, string $work, string $stage, string $token, array $files, array &$stats): void
    {
        $published = [];
        $indexName = $this->config['index_name'];
        $temporaryIndex = $root . '/.' . $indexName . '-' . $token . '.tmp';
        $manifest = $work . '/published-' . $token . '.json';
        $committed = false;
        try {
            foreach ($files as $file) {
                $target = $root . '/' . $file;
                if (file_exists($target) || is_link($target)) {
                    throw new RuntimeException('Sitemap part already exists: ' . $file);
                }
                // Имена уникальны для запуска: старый индекс продолжает ссылаться на старые части.
                $this->copyExclusive($stage . '/' . $file, $target);
                $published[] = $target;
            }
            $this->copyExclusive($stage . '/' . $indexName, $temporaryIndex);
            $metadata = json_encode([
                'index' => $indexName, 'prefix' => $this->config['file_prefix'], 'token' => $token,
                'published_at' => time(), 'files' => $files,
            ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (file_put_contents($manifest, $metadata, LOCK_EX) !== strlen($metadata)) {
                throw new RuntimeException('Cannot write publication manifest');
            }
            if (is_link($root . '/' . $indexName) || (file_exists($root . '/' . $indexName) && !is_file($root . '/' . $indexName))) {
                throw new RuntimeException('Index target must be a regular file');
            }
            // Временный индекс и конечный индекс находятся на одной файловой системе.
            if (!rename($temporaryIndex, $root . '/' . $indexName)) {
                throw new RuntimeException('Cannot replace root sitemap index');
            }
            $committed = true;
        } finally {
            if (!$committed) {
                foreach ($published as $path) {
                    @unlink($path);
                }
                @unlink($temporaryIndex);
                @unlink($manifest);
            }
        }

        // Индекс уже опубликован. Сбой обслуживания не отменяет успешное обновление.
        try {
            $this->cleanupOld($root, $work, $token, $files);
            foreach (array_merge($files, [$indexName]) as $file) {
                if (!unlink($stage . '/' . $file)) {
                    throw new RuntimeException('Cannot remove staged XML');
                }
            }
            if (!rmdir($stage)) {
                throw new RuntimeException('Cannot remove completed staging directory');
            }
        } catch (Throwable $error) {
            $stats['warnings'][] = $error->getMessage();
        }
    }

    private function copyExclusive(string $source, string $target): void
    {
        $input = fopen($source, 'rb');
        if ($input === false) {
            throw new RuntimeException('Cannot open generated XML');
        }
        $output = null;
        $complete = false;
        try {
            $output = fopen($target, 'xb');
            if ($output === false) {
                throw new RuntimeException('Cannot create ' . basename($target));
            }
            if (stream_copy_to_stream($input, $output) !== filesize($source) || !fflush($output) || !chmod($target, 0644)) {
                throw new RuntimeException('Cannot publish ' . basename($target));
            }
            $complete = true;
        } finally {
            fclose($input);
            if (is_resource($output)) {
                fclose($output);
                if (!$complete) {
                    @unlink($target);
                }
            }
        }
    }

    private function cleanupOld(string $root, string $work, string $currentToken, array $currentFiles): void
    {
        $cutoff = time() - $this->config['retention_days'] * 86400;
        foreach (new DirectoryIterator($work) as $entry) {
            if (!$entry->isFile() || $entry->isLink() || !preg_match('~^published-(\d{14}-[a-f0-9]{16})\.json$~D', $entry->getFilename(), $matches)
                || $matches[1] === $currentToken) {
                continue;
            }
            $data = json_decode((string) file_get_contents($entry->getPathname()), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($data) || ($data['index'] ?? null) !== $this->config['index_name']
                || ($data['token'] ?? null) !== $matches[1] || !is_int($data['published_at'] ?? null)
                || !is_array($data['files'] ?? null)
                || !is_string($data['prefix'] ?? null) || !preg_match('~^[a-z0-9][a-z0-9_-]{0,39}$~D', $data['prefix'])) {
                continue;
            }
            // Срок хранения отсчитывается от замены, даже если старая карта жила месяц.
            if (!isset($data['retired_at'])) {
                $data['retired_at'] = time();
                $temporary = $entry->getPathname() . '.tmp';
                $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                if (file_put_contents($temporary, $json, LOCK_EX) !== strlen($json)
                    || !rename($temporary, $entry->getPathname())) {
                    throw new RuntimeException('Cannot retire previous sitemap manifest');
                }
                continue;
            }
            if (!is_int($data['retired_at']) || $data['retired_at'] >= $cutoff) {
                continue;
            }
            foreach ($data['files'] as $file) {
                if (!is_string($file) || !preg_match('~^' . preg_quote($data['prefix'] . '-' . $data['token'], '~') . '-[0-9]{4,5}\.xml$~D', $file)
                    || in_array($file, $currentFiles, true) || is_link($root . '/' . $file)) {
                    throw new RuntimeException('Unsafe cleanup manifest: ' . $entry->getFilename());
                }
                if (is_file($root . '/' . $file) && !unlink($root . '/' . $file)) {
                    throw new RuntimeException('Cannot remove expired sitemap part: ' . $file);
                }
            }
            if (!unlink($entry->getPathname())) {
                throw new RuntimeException('Cannot remove expired manifest');
            }
        }
    }
}
