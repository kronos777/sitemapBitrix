<?php
declare(strict_types=1);
final class SitemapRepository {
 public function __construct(private array $config) {}
 public function iterate(callable $callback): void {
  foreach ($this->config['iblocks'] as $iblockId) {
   $lastId=0;
   do {
    $rs=CIBlockElement::GetList(['ID'=>'ASC'],['IBLOCK_ID'=>(int)$iblockId,'ACTIVE'=>'Y','>ID'=>$lastId],false,['nTopCount'=>$this->config['batch_size']],['ID','IBLOCK_ID','NAME','DETAIL_PAGE_URL','TIMESTAMP_X','ACTIVE','CODE','IBLOCK_SECTION_ID']);
    $count=0;
    while ($element=$rs->GetNext()) {
     $count++;$lastId=(int)$element['ID'];
     $callback($element);
    }
   } while ($count===$this->config['batch_size']);
  }
 }
}
