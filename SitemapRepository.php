<?php
declare(strict_types=1);

final class SitemapRepository
{
    public function __construct(private array $config) {}

    public function iterate(callable $callback): void
    {
        // Проверяем все ID заранее: опечатка не должна молча урезать карту.
        foreach ($this->config['root_iblocks'] as $iblockId) {
            $result = CIBlock::GetList([], [
                'ID' => $iblockId, 'ACTIVE' => 'Y', 'SITE_ID' => $this->config['site_id'],
                'CHECK_PERMISSIONS' => 'N', // здесь проверяем существование, права — в выборке элементов
            ]);
            if (!$result || !$result->Fetch()) {
                throw new RuntimeException('Iblock ' . $iblockId . ' is missing, inactive or not attached to site ' . $this->config['site_id']);
            }
        }
        foreach ($this->config['root_iblocks'] as $iblockId) {
            $lastId = 0;
            do {
                $filter = [
                    'IBLOCK_ID' => $iblockId, 'IBLOCK_LID' => $this->config['site_id'],
                    'IBLOCK_ACTIVE' => 'Y', 'ACTIVE' => 'Y', '>ID' => $lastId,
                    'CHECK_PERMISSIONS' => $this->config['check_permissions'] ? 'Y' : 'N',
                    'PERMISSIONS_BY' => 0, 'MIN_PERMISSION' => 'R',
                ];
                if ($this->config['active_date']) {
                    $filter['ACTIVE_DATE'] = 'Y';
                }
                $result = CIBlockElement::GetList(
                    ['ID' => 'ASC'], $filter, false, ['nTopCount' => $this->config['batch_size']],
                    ['ID', 'IBLOCK_ID', 'IBLOCK_SECTION_ID', 'CODE', 'NAME', 'DETAIL_PAGE_URL', 'TIMESTAMP_X']
                );
                if (!$result) {
                    throw new RuntimeException('Cannot read iblock ' . $iblockId);
                }
                $count = 0;
                // GetNext разрешает URL-шаблоны; false отключает HTML-экранирование.
                while ($element = $result->GetNext(false, false)) {
                    $id = (int) $element['ID'];
                    if ($id <= $lastId) {
                        throw new RuntimeException('Non-increasing element ID in iblock ' . $iblockId);
                    }
                    $lastId = $id;
                    ++$count;
                    $callback($element);
                }
            } while ($count === $this->config['batch_size']);
        }
    }
}
