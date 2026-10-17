<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$config = require dirname(__DIR__) . '/SitemapConfig.php';
$options = getopt('', ['test', 'help']);

if (isset($options['help'])) {
    echo "Usage: php generate.php --test\n";
    echo "       php generate.php\n";
    exit(0);
}

$isTest = isset($options['test']);
$root = rtrim($config['document_root'], '/');
$_SERVER['DOCUMENT_ROOT'] = $root;
$_SERVER['HTTP_HOST'] = 'gudok.ru';

define('NO_KEEP_STATISTIC', true);
define('NOT_CHECK_PERMISSIONS', true);
require $root . '/bitrix/modules/main/include/prolog_before.php';

if (!CModule::IncludeModule('iblock')) {
    throw new RuntimeException('iblock module unavailable');
}

foreach (['SitemapRepository', 'SitemapXmlWriter', 'SitemapGenerator'] as $class) {
    require dirname(__DIR__) . '/' . $class . '.php';
}

$lockPath = $root . $config['lock_relative'];
$handle = fopen($lockPath, 'c');

if (!$handle || !flock($handle, LOCK_EX | LOCK_NB)) {
    fwrite(STDERR, "Another generation is running\n");
    exit(3);
}

$logPath = $root . $config['log_relative'];

try {
    $stats = (new SitemapGenerator($config))->run();

    if (!$isTest) {
        $sourceDir = $root . $config['output_relative'];
        $sourceIndex = $sourceDir . '/sitemap_index.xml';
        $targetIndex = $root . '/sitemap_index.xml';
        $tempIndex = $root . '/sitemap_index.xml.tmp';
        $backupIndex = $root . '/sitemap_index.xml.backup';

        if (!is_file($sourceIndex) || !is_readable($sourceIndex)) {
            throw new RuntimeException('Generated sitemap index not found or not readable');
        }

        // Read the index as XML and use only sitemap filenames generated in the output directory.
        $index = new DOMDocument();
        $index->preserveWhiteSpace = false;
        $index->formatOutput = true;
        if (!$index->load($sourceIndex, LIBXML_NONET)) {
            throw new RuntimeException('Cannot parse generated sitemap index');
        }

        $locNodes = $index->getElementsByTagNameNS('http://www.sitemaps.org/schemas/sitemap/0.9', 'loc');
        if ($locNodes->length === 0) {
            throw new RuntimeException('Generated sitemap index contains no sitemap locations');
        }

        $files = [];
        foreach ($locNodes as $loc) {
            $url = trim($loc->textContent);
            $path = parse_url($url, PHP_URL_PATH);
            $file = is_string($path) ? basename($path) : '';
            if (!preg_match('/^sitemap-[0-9]{4}\.xml$/D', $file)) {
                throw new RuntimeException('Unexpected sitemap file in generated index: ' . $url);
            }
            if (isset($files[$file])) {
                throw new RuntimeException('Duplicate sitemap file in generated index: ' . $file);
            }
            $source = $sourceDir . '/' . $file;
            if (!is_file($source) || !is_readable($source)) {
                throw new RuntimeException('Generated sitemap file not found: ' . $file);
            }
            $files[$file] = $source;
            $loc->nodeValue = rtrim($config['host'], '/') . '/' . $file;
        }

        // Stage all files first, so a failed copy does not overwrite the public XML files.
        $staged = [];
        try {
            foreach ($files as $file => $source) {
                $temp = $root . '/.' . $file . '.tmp.' . getmypid();
                if (!copy($source, $temp)) {
                    throw new RuntimeException('Cannot stage sitemap file: ' . $file);
                }
                $staged[$file] = $temp;
            }

            if ($index->save($tempIndex) === false) {
                throw new RuntimeException('Cannot create temporary root sitemap index');
            }

            // Keep the existing root index until all public sitemap parts have been published.
            foreach ($staged as $file => $temp) {
                $target = $root . '/' . $file;
                if (is_file($target)) {
                    $permissions = fileperms($target);
                    if ($permissions !== false) {
                        chmod($temp, $permissions & 0777);
                    }
                }
                if (!rename($temp, $target)) {
                    throw new RuntimeException('Cannot publish sitemap file: ' . $file);
                }
                unset($staged[$file]);
            }

            if (is_file($targetIndex)) {
                if (!copy($targetIndex, $backupIndex)) {
                    throw new RuntimeException('Cannot backup current root sitemap index');
                }
                $permissions = fileperms($targetIndex);
                if ($permissions !== false) {
                    chmod($tempIndex, $permissions & 0777);
                }
            }

            if (!rename($tempIndex, $targetIndex)) {
                throw new RuntimeException('Cannot publish root sitemap index');
            }
        } finally {
            foreach ($staged as $temp) {
                if (is_file($temp)) {
                    unlink($temp);
                }
            }
            if (is_file($tempIndex)) {
                unlink($tempIndex);
            }
        }

        $stats['root_files_published'] = count($files);
        $stats['root_index_published'] = true;
        $stats['root_index_backup'] = $backupIndex;
    } else {
        $stats['root_index_published'] = false;
    }

    $line = date('c') . ' ' . json_encode(
        $stats,
        JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
    file_put_contents($logPath, $line . "\n", FILE_APPEND | LOCK_EX);
    echo $line . "\n";
} catch (Throwable $e) {
    $line = date('c') . ' ERROR ' . $e->getMessage();
    file_put_contents($logPath, $line . "\n", FILE_APPEND | LOCK_EX);
    fwrite(STDERR, $line . "\n");
    exit(1);
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}
