# Gudok.ru Sitemap — DEV-only prototype

## Important boundaries

This archive does not modify the existing sitemap, robots.txt, Bitrix handlers, or old module. Production execution is deliberately disabled. It has NOT been executed against the Gudok dev server. Validate PHP extensions `xmlwriter`, `xmlreader` and GNU `sort`, and Bitrix API behavior before any scheduled execution.

## Installation

Copy `local/php_interface/sitemap/` into the matching location under `/mnt/home/bitrix/ext_www/gudok/public_html/`. Do not deploy to production. The configured output is `/upload/sitemap_dev_test/`, which is publicly accessible on dev unless protected by server configuration; do not link it from robots.txt.

## Commands (one line each)

`cd /mnt/home/bitrix/ext_www/gudok/public_html && php -m | grep -Ei 'xmlwriter|xmlreader'`

`cd /mnt/home/bitrix/ext_www/gudok/public_html && php -l local/php_interface/sitemap/cron/generate.php && php -l local/php_interface/sitemap/SitemapRepository.php && php -l local/php_interface/sitemap/SitemapGenerator.php && php -l local/php_interface/sitemap/SitemapXmlWriter.php`

`cd /mnt/home/bitrix/ext_www/gudok/public_html && php local/php_interface/sitemap/cron/generate.php --test`

`cd /mnt/home/bitrix/ext_www/gudok/public_html && find upload/sitemap_dev_test -maxdepth 1 -name '*.xml' -type f -printf '%f %s bytes\n' | sort`

`cd /mnt/home/bitrix/ext_www/gudok/public_html && tail -n 5 upload/sitemap_dev_test.log`

## Cron example (DEV ONLY; do not enable before acceptance)

`0 3 * * * cd /mnt/home/bitrix/ext_www/gudok/public_html && /usr/bin/php local/php_interface/sitemap/cron/generate.php --test >> /tmp/gudok-sitemap-cron.log 2>&1`

## Scope and unresolved checks

Configured: 49, 50, 53, 167–181, 185, 367–382, 234. Candidate 305 disabled. 187, 446, 447 excluded. `ACTIVE_DATE` filtering is disabled so active archival elements are included regardless of publication window. Bitrix `GetNext()` returns processed URL templates; verify resolved `DETAIL_PAGE_URL` on dev. Canonical and HTTP status are NOT automatically checked: manually inspect `/zdr/` and news duplicates before approval. This version uses disk-backed URL-hash deduplication with GNU `sort` (`LC_ALL=C`, 32 MB sort buffer); it requires sufficient free disk space for spool and sort temporary files. It does not use SQLite or change the database. This version uses URL-level deduplication, not content-level deduplication. `TIMESTAMP_X` supplies lastmod. The script does not crawl pages or inspect robots/noindex directives. Do not claim that every exported URL is publicly indexable without additional validation.

The writer uses conservative 45,000 URL / 45 MB split targets. XMLReader parses all generated files before publication. On failure, staging directory is preserved for diagnosis. Successful repeat runs move the previous test directory to `.sitemap-previous-PID`; remove old backups manually after validation. The rename of a directory is atomic on the same filesystem, but concurrent readers holding old file paths can still encounter a swap boundary.

## Before production

1. Check representative output URLs and actual canonical HTML, especially `/zdr/` and blocks 367–382; decide about 305.
2. Check HTTP 200, robots meta/X-Robots-Tag, canonical, and duplicate-content mapping with an agreed validation procedure.
3. Confirm dev URL resolution, GNU sort availability, and server resources.
4. Confirm backup, production output location, production cron schedule, and explicit approval before enabling production mode or changing robots.txt.

## Revision 2 (2026-10-14)

Removed SQLite3 dependency. Two-pass spool and GNU sort deduplicate URLs using SHA-256 hashes on disk. Removed ACTIVE_DATE=Y. Existing sitemap files remain untouched; only `--test` is accepted. No cron should be enabled until dev tests pass. Note: errors while processing elements now abort publication.
