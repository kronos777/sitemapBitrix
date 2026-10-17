<?php
declare(strict_types=1);

final class SitemapXmlWriter
{
    private const HEADER = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
        . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
    private const FOOTER = "</urlset>\n";
    private $handle = null;
    private int $count = 0;
    private int $bytes = 0;
    private array $files = [];

    public function __construct(
        private string $directory,
        private string $prefix,
        private int $maxUrls,
        private int $maxBytes
    ) {}

    public function add(string $url, ?string $lastmod): void
    {
        $entry = '  <url><loc>' . self::escape($url) . '</loc>'
            . ($lastmod !== null ? '<lastmod>' . self::escape($lastmod) . '</lastmod>' : '') . "</url>\n";
        if (strlen(self::HEADER) + strlen($entry) + strlen(self::FOOTER) > $this->maxBytes) {
            throw new RuntimeException('Single URL exceeds max_bytes');
        }
        if ($this->handle === null) {
            $this->start();
        }
        if ($this->count >= $this->maxUrls || $this->bytes + strlen($entry) + strlen(self::FOOTER) > $this->maxBytes) {
            $this->closePart();
            $this->start();
        }
        $this->write($entry);
        ++$this->count;
    }

    public function finish(string $host, string $indexName): array
    {
        if (!$this->files) {
            $this->start();
        }
        $this->closePart();
        $handle = fopen($this->directory . '/' . $indexName, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create sitemap index');
        }
        $size = 0;
        $write = static function (string $text) use ($handle, &$size): void {
            $size += strlen($text);
            if ($size > 52428800 || fwrite($handle, $text) !== strlen($text)) {
                throw new RuntimeException('Index write failed or index size limit exceeded');
            }
        };
        try {
            $write('<?xml version="1.0" encoding="UTF-8"?>' . "\n"
                . '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n");
            foreach ($this->files as $file) {
                $write('  <sitemap><loc>' . self::escape($host . '/' . $file) . "</loc></sitemap>\n");
            }
            $write("</sitemapindex>\n");
            if (!fflush($handle)) {
                throw new RuntimeException('Index flush failed');
            }
        } finally {
            fclose($handle);
        }
        return $this->files;
    }

    private function start(): void
    {
        if (count($this->files) >= 50000) {
            throw new RuntimeException('Sitemap index cannot reference more than 50000 parts');
        }
        $name = sprintf('%s-%04d.xml', $this->prefix, count($this->files) + 1);
        $this->handle = fopen($this->directory . '/' . $name, 'xb');
        if ($this->handle === false) {
            $this->handle = null;
            throw new RuntimeException('Cannot create sitemap part');
        }
        $this->files[] = $name;
        $this->bytes = 0;
        $this->count = 0;
        $this->write(self::HEADER);
    }

    private function write(string $text): void
    {
        if (fwrite($this->handle, $text) !== strlen($text)) {
            throw new RuntimeException('XML write failed (check free disk space)');
        }
        $this->bytes += strlen($text);
    }

    private function closePart(): void
    {
        if ($this->handle !== null) {
            try {
                $this->write(self::FOOTER);
                if (!fflush($this->handle)) {
                    throw new RuntimeException('XML flush failed');
                }
            } finally {
                fclose($this->handle);
                $this->handle = null;
            }
        }
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
        }
    }

    private static function escape(string $text): string
    {
        return htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
