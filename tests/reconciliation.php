<?php
error_reporting(E_ALL & ~E_DEPRECATED);
define('BASEPATH', __DIR__);
foreach (array('Reconciliation_parser', 'Amount_math', 'Excel_exporter', 'Reconciliation_service') as $library) require __DIR__ . '/../application/libraries/' . $library . '.php';
function &get_instance() { global $ci; return $ci; }
class MappingFixture {
    public $rows = array();
    public function get_mapping_efs() { return $this->rows; }
}
$ci = new stdClass();
$ci->reconciliation_parser = new Reconciliation_parser();
$ci->amount_math = new Amount_math();
$ci->Reconciliation_model = new MappingFixture();
$ci->Reconciliation_model->rows = array(
    array('rincianAkun' => '20', 'coaF1' => '100'),
    array('rincianAkun' => '10', 'coaF1' => '100'),
    array('rincianAkun' => '30', 'coaF1' => NULL)
);
$lkp = tempnam(sys_get_temp_dir(), 'lkp');
$tb = tempnam(sys_get_temp_dir(), 'tb');
$out = tempnam(sys_get_temp_dir(), 'out');
function check($actual, $expected) {
    if ($actual !== $expected) throw new Exception(var_export(array('actual' => $actual, 'expected' => $expected), TRUE));
}
try {
    file_put_contents($lkp, "x|100|x|USD|0002|9|90|0000\nx|100|x|USD|0001|100.25|1000|0000\nx|100|x|USD|0001|-10.10|-100|0000\nx|100|x|USD|0001|999|999|1111\nx|100|x|IDR|0001|5|5|0000\nx|200|x|USD|0001|50|50|1111\nx|100|x|USD|0000|100|100|0000\n");
    file_put_contents($tb, "CONCATENATED_SEGMENTS\tPERIOD_NUM\tCURRENCY_CODE\tAMOUNT\tBASE_AMOUNT\n0001-x-20\t6\tUSD\t20.10\t200\n0001-x-10\t6\tUSD\t30\t300\n0001-x-20\t6\tUSD\t-5\t-50\n0001-x-20\t5\tUSD\t999\t999\n0003-x-10\t6\tUSD\t99\t99\n0001-x-30\t6\tUSD\t99\t99\n0001-x-99\t6\tUSD\t99\t99\n");
    $service = new Reconciliation_service();
    $stats = $service->process($lkp, $tb, $out);
    $handle = fopen($out, 'r');
    $header = fgetcsv($handle, 0, ';');
    $rows = array();
    while (($row = fgetcsv($handle, 0, ';')) !== FALSE) $rows[] = $row;
    fclose($handle);
    check(count($header), 10);
    check($rows, array(
        array('0001','100','IDR','','5.00','','5.00','5.00','','5.00'),
        array('0001','100','USD','10,20','90.15','45.10','45.05','900.00','450.00','450.00'),
        array('0001','200','USD','','0.00','','0.00','0.00','','0.00'),
        array('0002','100','USD','','9.00','','9.00','90.00','','90.00')
    ));
    check($stats['resultRows'], 4);
    require_once __DIR__ . '/../vendor/autoload.php';
    $service->process($lkp, $tb, $out, 'xlsx');
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($out);
    $sheet = $book->getActiveSheet();
    check((int) $sheet->getHighestRow(), 5);
    check((string) $sheet->getCell('A2')->getValue(), '0001');
    check($sheet->getCell('A2')->getDataType(), 'inlineStr');
    check((string) $sheet->getCell('D3')->getValue(), '10,20');
    check((string) $sheet->getCell('E3')->getValue(), '90.15');
    check($sheet->getCell('E3')->getDataType(), 'n');
    check($sheet->getCell('F2')->getValue(), NULL);
    check((string) $sheet->getCell('J3')->getValue(), '450');
    check($sheet->getFreezePane(), 'A2');
    $book->disconnectWorksheets();

    // Duplicate mapping rows multiply EFS values just as a SQL LEFT JOIN does.
    $ci->Reconciliation_model->rows[] = array('rincianAkun' => '10', 'coaF1' => '100');
    $service->process($lkp, $tb, $out);
    check(strpos(file_get_contents($out), '90.15;75.10;15.05;900.00;750.00;150.00') !== FALSE, TRUE);
    $ci->Reconciliation_model->rows = array();
    check($service->process($lkp, $tb, $out)['unmatchedEfsGroups'], 4);
    check($ci->amount_math->sum('999999999999999999.99', '0.01'), '1000000000000000000.00');
    echo "Reconciliation checks passed\n";
} finally { unlink($lkp); unlink($tb); unlink($out); }
