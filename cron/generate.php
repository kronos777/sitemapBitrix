<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$base = dirname(__DIR__);
$logPath = null;
$exitCode = 0;

try {
    $configFile = $base . '/SitemapConfig.php';
    $isTest = false;
    for ($i = 1; $i < $argc; ++$i) {
        if ($argv[$i] === '--help') {
            echo "Usage: php generate.php [--config=/absolute/path/config.php] [--test]\n";
            echo "--test builds and validates XML in a staging directory; the root sitemap is unchanged.\n";
            exit(0);
        } elseif ($argv[$i] === '--test') {
            $isTest = true;
        } elseif (str_starts_with($argv[$i], '--config=')) {
            $configFile = substr($argv[$i], 9);
        } elseif ($argv[$i] === '--config' && isset($argv[$i + 1]) && !str_starts_with($argv[$i + 1], '--')) {
            $configFile = $argv[++$i];
        } else {
            throw new InvalidArgumentException('Unknown or incomplete argument: ' . $argv[$i]);
        }
    }
    foreach (['SitemapSettings', 'SitemapRepository', 'SitemapUrl', 'SitemapUrlStore', 'SitemapXmlWriter', 'SitemapGenerator'] as $class) {
        require_once $base . '/' . $class . '.php';
    }
    $config = SitemapSettings::load($configFile);
    $root = $config['document_root'];
    $prolog = $root . '/bitrix/modules/main/include/prolog_before.php';
    if (!is_file($prolog)) {
        throw new RuntimeException('Bitrix prolog not found in document_root');
    }
    $work = $root . $config['work_relative'];
    if (!is_dir($work) && !mkdir($work, 0775, true) && !is_dir($work)) {
        throw new RuntimeException('Cannot create runtime directory');
    }
    if (!str_starts_with(str_replace('\\', '/', (string) realpath($work)) . '/', $root . '/')) {
        throw new RuntimeException('Runtime directory must stay inside document_root');
    }
    $logPath = $work . '/sitemap.log';
    $setOrigin = static function (string $host): void {
        $origin = parse_url($host);
        $_SERVER['HTTP_HOST'] = $origin['host'] . (isset($origin['port']) ? ':' . $origin['port'] : '');
        $_SERVER['SERVER_NAME'] = $origin['host'];
        $_SERVER['SERVER_PORT'] = (string) ($origin['port'] ?? ($origin['scheme'] === 'https' ? 443 : 80));
        $_SERVER['HTTPS'] = $origin['scheme'] === 'https' ? 'on' : 'off';
        $_SERVER['REQUEST_SCHEME'] = $origin['scheme'];
    };
    $_SERVER['DOCUMENT_ROOT'] = $root;
    // SITE_ID задаётся явно; настоящий домен читается после загрузки настроек Битрикс.
    $setOrigin($config['host'] ?? 'https://localhost');
    $_SERVER['REQUEST_URI'] = '/';
    $_SERVER['REQUEST_METHOD'] = 'GET';
    foreach (['NO_KEEP_STATISTIC' => true, 'NOT_CHECK_PERMISSIONS' => true,
        'NO_AGENT_CHECK' => true, 'DisableEventsCheck' => true, 'SITE_ID' => $config['site_id']] as $name => $value) {
        if (!defined($name)) {
            define($name, $value);
        }
    }
    require $prolog;
    if (!CModule::IncludeModule('iblock')) {
        throw new RuntimeException('Bitrix iblock module unavailable');
    }
    $config = SitemapSettings::resolveHost($config);
    $setOrigin($config['host']);
    $stats = (new SitemapGenerator($config))->run($isTest);
    $line = date('c') . ' ' . json_encode($stats, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    echo $line . PHP_EOL;
} catch (Throwable $error) {
    $line = date('c') . ' ERROR ' . $error->getMessage();
    fwrite(STDERR, $line . PHP_EOL);
    $exitCode = $error->getCode() === 3 ? 3 : 1;
}

if ($logPath !== null && @file_put_contents($logPath, $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
    fwrite(STDERR, "WARNING: Cannot append sitemap log: " . $logPath . PHP_EOL);
}
exit($exitCode);
