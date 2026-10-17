<?php
declare(strict_types=1);

/** External merge sort: bounded chunks and at most 32 open inputs per merge. */
final class SitemapUrlStore
{
    private array $buffer = [];
    private int $bufferBytes = 0;
    private array $runs = [];
    private int $serial = 0;

    public function __construct(private string $directory, private int $chunkSize) {}

    public function add(string $url, ?string $lastmod): void
    {
        if (!isset($this->buffer[$url])) {
            $this->bufferBytes += strlen($url) + 128;
        }
        $this->buffer[$url] = max($this->buffer[$url] ?? '', $lastmod ?? '');
        if (count($this->buffer) >= $this->chunkSize || $this->bufferBytes >= 8 * 1024 * 1024) {
            $this->flush();
        }
    }

    public function records(): Generator
    {
        $this->flush();
        while (count($this->runs) > 32) {
            $next = [];
            foreach (array_chunk($this->runs, 32) as $group) {
                $path = $this->nextPath();
                $this->writeRecords($path, $this->merge($group));
                $next[] = $path;
                foreach ($group as $input) {
                    if (!unlink($input)) {
                        throw new RuntimeException('Cannot remove sort input: ' . $input);
                    }
                }
            }
            $this->runs = $next;
        }
        yield from $this->merge($this->runs);
    }

    public function cleanup(): void
    {
        foreach ($this->runs as $path) {
            if (!unlink($path)) {
                throw new RuntimeException('Cannot remove sort input: ' . $path);
            }
        }
        $this->runs = [];
    }

    private function nextPath(): string
    {
        return $this->directory . '/sort-' . (++$this->serial) . '.tsv';
    }

    private function flush(): void
    {
        if (!$this->buffer) {
            return;
        }
        ksort($this->buffer, SORT_STRING);
        $path = $this->nextPath();
        $records = function (): Generator {
            foreach ($this->buffer as $url => $date) {
                yield [$url, $date];
            }
        };
        $this->writeRecords($path, $records());
        $this->runs[] = $path;
        $this->buffer = [];
        $this->bufferBytes = 0;
    }

    private function writeRecords(string $path, iterable $records): void
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) {
            throw new RuntimeException('Cannot create sort file');
        }
        try {
            foreach ($records as [$url, $date]) {
                $line = $url . "\t" . $date . "\n";
                if (fwrite($handle, $line) !== strlen($line)) {
                    throw new RuntimeException('Sort write failed (check free disk space)');
                }
            }
            if (!fflush($handle)) {
                throw new RuntimeException('Sort flush failed');
            }
        } finally {
            fclose($handle);
        }
    }

    private function read($handle): ?array
    {
        $line = fgets($handle);
        if ($line === false) {
            if (!feof($handle)) {
                throw new RuntimeException('Sort read failed');
            }
            return null;
        }
        $record = explode("\t", rtrim($line, "\r\n"));
        if (count($record) !== 2 || $record[0] === '' || ($record[1] !== '' && !preg_match('~^\d{4}-\d{2}-\d{2}$~D', $record[1]))) {
            throw new RuntimeException('Corrupt sort record');
        }
        return $record;
    }

    private function merge(array $paths): Generator
    {
        $heap = new class extends SplPriorityQueue {
            public function compare($priority1, $priority2): int
            {
                return strcmp($priority2, $priority1);
            }
        };
        $heap->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $handles = [];
        try {
            foreach ($paths as $index => $path) {
                $handle = fopen($path, 'rb');
                if ($handle === false) {
                    throw new RuntimeException('Cannot open sort input');
                }
                $handles[$index] = $handle;
                $record = $this->read($handle);
                if ($record !== null) {
                    $heap->insert([$record[0], $record[1], $index], $record[0]);
                }
            }
            $pending = null;
            while (!$heap->isEmpty()) {
                [$url, $date, $index] = $heap->extract();
                if ($pending !== null && $pending[0] !== $url) {
                    yield $pending;
                    $pending = null;
                }
                $pending = [$url, max($pending[1] ?? '', $date)];
                $record = $this->read($handles[$index]);
                if ($record !== null) {
                    $heap->insert([$record[0], $record[1], $index], $record[0]);
                }
            }
            if ($pending !== null) {
                yield $pending;
            }
        } finally {
            foreach ($handles as $handle) {
                fclose($handle);
            }
        }
    }
}
