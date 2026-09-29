<?php
// Run with an unusable system temp path to catch php://temp spill regressions.
define('BASEPATH', __DIR__);
require __DIR__ . '/../application/libraries/Excel_exporter.php';
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$directory = __DIR__ . '/../outputs';
$before = glob($directory . '/reconciliation-temp-*');
$path = $directory . '/test-large-' . bin2hex(random_bytes(8)) . '.csv';
$exporter = new Excel_exporter();
try {
    $exporter->start('csv');
    $row = array('branch' => '0001', 'coaF1' => '100', 'currency' => 'IDR',
        'efs_rincianAkun' => str_repeat('1234567890', 100),
        'lkp_ori' => '100.00', 'efs_ori' => '90.00', 'selisih_ori' => '10.00',
        'lkp_eqIDR' => '100.00', 'efs_eqIDR' => '90.00', 'selisih_eqIDR' => '10.00');
    for ($i = 0; $i < 4000; $i++) $exporter->add_row($row);
    $exporter->save($path);
    if (filesize($path) <= 2097152) throw new Exception('Fixture must exceed php://temp memory threshold.');
    $handle = fopen($path, 'rb');
    fgetcsv($handle, 0, ';');
    $count = 0;
    while (($values = fgetcsv($handle, 0, ';')) !== FALSE) {
        if ($values !== array_values($row)) throw new Exception('CSV row was corrupted.');
        $count++;
    }
    fclose($handle);
    if ($count !== 4000) throw new Exception('CSV row count mismatch.');
    if (glob($directory . '/reconciliation-temp-*') !== $before) throw new Exception('Temporary file leaked after save.');
    $exporter->start('csv');
    $exporter->add_row($row);
    unset($exporter);
    if (glob($directory . '/reconciliation-temp-*') !== $before) throw new Exception('Temporary file leaked after destruction.');
    echo "Large CSV checks passed (4000 rows, over 4 MB, system temp unavailable)\n";
} finally {
    if (is_file($path)) unlink($path);
    restore_error_handler();
}
