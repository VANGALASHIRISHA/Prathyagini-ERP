<?php
// Minimal pure-PHP ZIP writer (stored, no compression) - no zip extension needed on shared hosting.
class SimpleZip {
    private $fh; private array $central = []; private int $offset = 0; private int $count = 0;
    public function __construct(string $path) { $this->fh = fopen($path, 'wb'); if (!$this->fh) throw new RuntimeException('Cannot create zip file.'); }
    private function dos(int $ts): array { $d = getdate($ts); $y = max(1980, $d['year']); return [($d['hours'] << 11) | ($d['minutes'] << 5) | intdiv($d['seconds'], 2), (($y - 1980) << 9) | ($d['mon'] << 5) | $d['mday']]; }
    public function addString(string $name, string $data, ?int $ts = null): void {
        $name = str_replace(chr(92), '/', $name); [$time, $date] = $this->dos($ts ?? time());
        $crc = crc32($data); $size = strlen($data); $nl = strlen($name);
        $hdr = pack('VvvvvvVVVvv', 0x04034b50, 20, 0x0800, 0, $time, $date, $crc, $size, $size, $nl, 0) . $name;
        fwrite($this->fh, $hdr); fwrite($this->fh, $data);
        $this->central[] = pack('VvvvvvvVVVvvvvvVV', 0x02014b50, 20, 20, 0x0800, 0, $time, $date, $crc, $size, $size, $nl, 0, 0, 0, 0, 0, $this->offset) . $name;
        $this->offset += strlen($hdr) + $size; $this->count++;
    }
    public function addFile(string $name, string $path): void { $this->addString($name, (string)file_get_contents($path), (int)filemtime($path)); }
    public function close(): void {
        $cd = implode('', $this->central);
        fwrite($this->fh, $cd . pack('VvvvvVVv', 0x06054b50, 0, 0, $this->count, $this->count, strlen($cd), $this->offset, 0));
        fclose($this->fh);
    }
}
