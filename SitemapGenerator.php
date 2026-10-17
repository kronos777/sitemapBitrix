<?php
declare(strict_types=1);
final class SitemapGenerator {
 public function __construct(private array $config) {}
 private function photoReportUrl(array $row): ?string {
    $sectionToIblock = [
        48437 => 167,
        48438 => 168,
        48439 => 169,
        48440 => 170,
        48441 => 171,
        48442 => 172,
        48443 => 173,
        48444 => 174,
        48445 => 175,
        48446 => 176,
        48447 => 177,
        48448 => 178,
        48449 => 179,
        48450 => 180,
        48451 => 181,
        48453 => 185,
    ];

    $sectionId = (int)($row['IBLOCK_SECTION_ID'] ?? 0);
    $elementId = (int)($row['ID'] ?? 0);

    if ($elementId <= 0 || !isset($sectionToIblock[$sectionId])) {
        error_log(
            'Sitemap: skipped photo report ID=' . $elementId .
            ', section=' . $sectionId
        );
        return null;
    }

    return $this->config['host']
        . '/zdr/'
        . $sectionToIblock[$sectionId]
        . '/foto/?ID='
        . $elementId;
}
 private function url(array $row): ?string {
  if ((int)($row['IBLOCK_ID'] ?? 0) === 305) {
	return $this->photoReportUrl($row);
  } 
  $raw=html_entity_decode(trim((string)($row['DETAIL_PAGE_URL']??'')),ENT_QUOTES|ENT_HTML5,'UTF-8');
  if ($raw==='' || str_contains($raw,'#') || preg_match('~[\x00-\x20<>]~',$raw)) return null;
  if (str_starts_with($raw,'/')) $raw=$this->config['host'].$raw;
  $parts=parse_url($raw);
  if ($parts===false || strtolower($parts['scheme']??'')!=='https' || strtolower($parts['host']??'')!=='gudok.ru' || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
  $path=$parts['path']??'/';
  foreach ($this->config['exclude_path_patterns'] as $pattern) if (preg_match($pattern,$path)) return null;
  if ($path==='/' && empty($parts['query'])) return null;
  return $raw;
 }
 private function date(array $row): ?string {
  $raw=trim((string)($row['TIMESTAMP_X']??''));if ($raw==='') return null;
  try {return (new DateTimeImmutable($raw))->format('Y-m-d');}catch(Throwable){return null;}
 }
 public function run(): array {
  $root=rtrim($this->config['document_root'],'/');$relative=$this->config['output_relative'];
  if (!preg_match('~^/upload/[a-zA-Z0-9_/-]+$~',$relative) || str_contains($relative,'..')) throw new RuntimeException('Unsafe output path');
  $target=$root.$relative;$parent=dirname($target);
  if (!is_dir($parent) && !mkdir($parent,0775,true) && !is_dir($parent)) throw new RuntimeException('Cannot create parent');
  $stage=$parent.'/.sitemap-stage-'.getmypid();
  if (file_exists($stage) || !mkdir($stage,0775)) throw new RuntimeException('Cannot create stage');
  $started=microtime(true);$stats=['processed'=>0,'included'=>0,'invalid'=>0,'duplicate'=>0,'errors'=>0];
  try {
   // Disk-backed spool; GNU sort uses bounded RAM and temporary files in stage.
   $spool=$stage.'/urls.tsv';$fh=fopen($spool,'wb');if (!$fh) throw new RuntimeException('Cannot open spool');
   try {
    (new SitemapRepository($this->config))->iterate(function(array $row)use(&$stats,$fh){
     $stats['processed']++;
     try {
      $url=$this->url($row);if ($url===null) {$stats['invalid']++;return;}
      $line=hash('sha256',$url)."\t".base64_encode($url)."\t".($this->date($row)??'')."\n";
      if (fwrite($fh,$line)!==strlen($line)) throw new RuntimeException('Spool write failed');
     } catch(Throwable $e) {$stats['errors']++;if ($stats['errors']<=20) error_log('Sitemap element '.($row['ID']??'?').': '.$e->getMessage());if ($stats['errors']>100) throw new RuntimeException('Too many errors');}
    });
   } finally {fclose($fh);}
   if ($stats['errors']>0) throw new RuntimeException('Element processing errors; refusing to publish incomplete sitemap');
   $sorted=$stage.'/sorted.tsv';
   $command='LC_ALL=C sort -T '.escapeshellarg($stage).' -S 32M -t '.escapeshellarg("\t").' -k1,1 '.escapeshellarg($spool).' -o '.escapeshellarg($sorted).' 2>&1';
   exec($command,$output,$exit);if ($exit!==0) throw new RuntimeException('GNU sort failed: '.implode(' ',array_slice($output,0,5)));
   $reader=fopen($sorted,'rb');if (!$reader) throw new RuntimeException('Cannot read sorted spool');
   $writer=new SitemapXmlWriter($stage,(int)$this->config['max_urls'],(int)$this->config['max_bytes']);$previous=null;
   try {while (($line=fgets($reader))!==false) {
    $fields=explode("\t",rtrim($line,"\r\n"),3);if (count($fields)!==3) throw new RuntimeException('Corrupted spool record');
    if ($fields[0]===$previous) {$stats['duplicate']++;continue;}
    $url=base64_decode($fields[1],true);if ($url===false || hash('sha256',$url)!==$fields[0]) throw new RuntimeException('Corrupted spool URL');
    $writer->add($url,$fields[2]!==''?$fields[2]:null);$stats['included']++;$previous=$fields[0];
   } if (!feof($reader)) throw new RuntimeException('Sorted spool read failed');} finally {fclose($reader);}
   $files=$writer->finish($this->config['host'].$relative);
   unlink($spool);unlink($sorted);
   foreach (array_merge($files,['sitemap_index.xml']) as $file) {
    if (!is_file($stage.'/'.$file) || filesize($stage.'/'.$file)>50000000) throw new RuntimeException('XML size limit '.$file);
    $xml=new XMLReader();if (!$xml->open($stage.'/'.$file)) throw new RuntimeException('XML open failed');
    try {while($xml->read()){} } finally {$xml->close();}
   }
   $backup=null;
   if (is_dir($target)) {$backup=$parent.'/.sitemap-previous-'.getmypid();if (file_exists($backup)||!rename($target,$backup)) throw new RuntimeException('Cannot move previous target');}
   if (!rename($stage,$target)) {if ($backup!==null) rename($backup,$target);throw new RuntimeException('Cannot publish stage');}
   $stats['files']=count($files);$stats['seconds']=round(microtime(true)-$started,2);$stats['previous_directory']=$backup;
   return $stats;
  } catch(Throwable $e) {error_log('Sitemap generation aborted: '.$e->getMessage().'; staging directory: '.$stage);throw $e;}
 }
}
