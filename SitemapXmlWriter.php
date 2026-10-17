<?php
declare(strict_types=1);
final class SitemapXmlWriter {
 private ?XMLWriter $xml=null;
 private int $count=0;
 private int $part=0;
 private array $files=[];
 public function __construct(private string $dir,private int $maxUrls,private int $maxBytes) {}
 private function start(): void {
  $this->part++;
  $file=sprintf('sitemap-%04d.xml',$this->part);
  $xml=new XMLWriter();
  if (!$xml->openURI($this->dir.'/'.$file)) throw new RuntimeException('Cannot open '.$file);
  $xml->startDocument('1.0','UTF-8');$xml->startElement('urlset');$xml->writeAttribute('xmlns','http://www.sitemaps.org/schemas/sitemap/0.9');
  $this->xml=$xml;$this->count=0;$this->files[]=$file;
 }
 private function closePart(): void {if ($this->xml!==null) {$this->xml->endElement();$this->xml->endDocument();$this->xml->flush();$this->xml=null;}}
 public function add(string $url,?string $lastmod): void {
  if ($this->xml===null) $this->start();
  $estimate=strlen($url)+($lastmod?strlen($lastmod):0)+120;
  $current=filesize($this->dir.'/'.end($this->files));
  clearstatcache(true,$this->dir.'/'.end($this->files));
  $current=filesize($this->dir.'/'.end($this->files));
  if ($this->count>0 && ($this->count >= $this->maxUrls || $current+$estimate+1000 >= $this->maxBytes)) {$this->closePart();$this->start();}
  $this->xml->startElement('url');$this->xml->writeElement('loc',$url);
  if ($lastmod!==null) $this->xml->writeElement('lastmod',$lastmod);
  $this->xml->endElement();$this->xml->flush();$this->count++;
 }
 public function finish(string $base): array {
  $this->closePart();
  $xml=new XMLWriter();$index=$this->dir.'/sitemap_index.xml';
  if (!$xml->openURI($index)) throw new RuntimeException('Cannot open index');
  $xml->startDocument('1.0','UTF-8');$xml->startElement('sitemapindex');$xml->writeAttribute('xmlns','http://www.sitemaps.org/schemas/sitemap/0.9');
  foreach ($this->files as $file) {$xml->startElement('sitemap');$xml->writeElement('loc',rtrim($base,'/').'/'.$file);$xml->endElement();}
  $xml->endElement();$xml->endDocument();$xml->flush();
  return $this->files;
 }
}
