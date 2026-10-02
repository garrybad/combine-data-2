<?php
// Synthetic end-to-end benchmark; does not access the database or real uploads.
// Run: php -d memory_limit=512M tests/benchmark_reconciliation.php
error_reporting(E_ALL & ~E_DEPRECATED);
define('BASEPATH', __DIR__);
foreach (array('Amount_math', 'Reconciliation_parser', 'Reconciliation_service', 'Excel_exporter') as $library) {
    require __DIR__ . '/../application/libraries/' . $library . '.php';
}
function &get_instance() { global $ci; return $ci; }
class BenchmarkMapping {
    public function get_rasionalisasi_accounts() { return array(); }
    public function get_mapping_efs() { return array(array('rincianAkun' => '10', 'coaF1' => '100')); }
}
$ci = new stdClass();
$ci->amount_math = new Amount_math();
$ci->reconciliation_parser = new Reconciliation_parser();
$ci->Reconciliation_model = new BenchmarkMapping();
$lkp = tempnam(sys_get_temp_dir(), 'bench-lkp-');
$tb = tempnam(sys_get_temp_dir(), 'bench-tb-');
$out = tempnam(sys_get_temp_dir(), 'bench-out-');
$precision = in_array('--extended-precision', $argv, TRUE) ? '000000' : '';
try {
    $l = fopen($lkp, 'wb');
    $t = fopen($tb, 'wb');
    try {
        fwrite($t, "CONCATENATED_SEGMENTS\tPERIOD_NUM\tCURRENCY_CODE\tAMOUNT\tBASE_AMOUNT\n");
        for ($i = 0; $i < 520010; $i++) {
            $branch = sprintf('%06d', $i % 158470 + 1);
            fwrite($l, "x|100|x|USD|$branch|123456.78{$precision}|987654321.12{$precision}|0000\n");
            fwrite($t, "$branch-x-10\t6\tUSD\t123450.23{$precision}\t987654000.56{$precision}\n");
        }
    } finally { fclose($l); fclose($t); }
    $service = new Reconciliation_service();
    $stats = $service->process($lkp, $tb, $out, 'xlsx');
    if ($stats['resultRows'] !== 158470) throw new Exception('Unexpected result count.');
    $zip = new ZipArchive();
    if ($zip->open($out) !== TRUE) throw new Exception('Cannot read XLSX.');
    $hash = hash('sha256', $zip->getFromName('xl/worksheets/sheet1.xml'));
    $zip->close();
    if ($hash !== '8b80c2c963b2a2f0a4e1c62b1ae2c4eb429f30d3b4ed8e82a4c040233cd9e2d8') {
        throw new Exception('Worksheet content changed.');
    }
    echo json_encode($stats, JSON_PRETTY_PRINT) . PHP_EOL;
    echo 'XLSX bytes: ' . filesize($out) . PHP_EOL;
    echo 'Worksheet SHA-256: ' . $hash . PHP_EOL;
} finally {
    foreach (array($lkp, $tb, $out) as $path) if (is_file($path)) unlink($path);
}
