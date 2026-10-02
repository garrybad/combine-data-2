<?php
error_reporting(E_ALL & ~E_DEPRECATED);
define('BASEPATH', __DIR__);
foreach (array('Reconciliation_parser', 'Amount_math', 'Excel_exporter', 'Reconciliation_service') as $library)
    require __DIR__ . '/../application/libraries/' . $library . '.php';
function &get_instance()
{
    global $ci;
    return $ci;
}
class MappingFixture
{
    public $rows = array();
    public $rasionalisasi = array();
    public function get_rasionalisasi_accounts() { return $this->rasionalisasi; }
    public function get_mapping_efs()
    {
        return $this->rows;
    }
}
$ci = new stdClass();
$ci->reconciliation_parser = new Reconciliation_parser();
$ci->amount_math = new Amount_math();
$ci->Reconciliation_model = new MappingFixture();
$ci->Reconciliation_model->rows = array(
    array('rincianAkun' => '20', 'coaF1' => '100'),
    array('rincianAkun' => '10', 'coaF1' => '100'),
    array('rincianAkun' => '30', 'coaF1' => NULL),
    array('rincianAkun' => '40', 'coaF1' => '200')
);
$lkp = tempnam(sys_get_temp_dir(), 'lkp');
$tb = tempnam(sys_get_temp_dir(), 'tb');
$out = tempnam(sys_get_temp_dir(), 'out');
function check($actual, $expected)
{
    if ($actual !== $expected)
        throw new Exception(var_export(array('actual' => $actual, 'expected' => $expected), TRUE));
}
try {
    file_put_contents($lkp, "x|100|x|USD|0002|9|90|0000\nx|100|x| usd |0001|100.25|1000|0000\nx|100|x|USD|0001|-10.10|-100|0000\nx|100|x|USD|0001|999|999|1111\nx|100|x|IDR|0001|5|5|0000\nx|200|x|USD|0001|50|50|1111\nx|100|x|USD|0000|100|100|0000\nx|100|x|USD|9001|12|120|0000\nx|100|x|IDR|w18|8|80|0000\n");
    file_put_contents($tb, "CONCATENATED_SEGMENTS\tPERIOD_NUM\tCURRENCY_CODE\tAMOUNT\tBASE_AMOUNT\n0001-x-20\t6\t UsD \t20.10\t200\n0001-x-10\t6\tUSD\t30\t300\n0001-x-20\t6\tUSD\t-5\t-50\n0001-x-20\t5\tUSD\t999\t999\n0003-x-10\t6\tUSD\t99\t99\n0001-x-30\t6\tUSD\t99\t99\n0001-x-99\t6\tUSD\t99\t99\n0000-x-10\t6\tusd\t10\t20\n0001-x-40\t6\tUSD\t7\t70\n");
    $service = new Reconciliation_service();
    $stats = $service->process($lkp, $tb, $out);
    $handle = fopen($out, 'r');
    $header = fgetcsv($handle, 0, ';');
    $rows = array();
    while (($row = fgetcsv($handle, 0, ';')) !== FALSE)
        $rows[] = $row;
    fclose($handle);
    check(count($header), 10);
    check($rows, array(
        array('0001', '100', 'IDR', '', '5.00', '', '', '5.00', '', ''),
        array('0001', '100', 'USD', '10,20', '90.15', '45.10', '45.05', '900.00', '450.00', '450.00'),
        array('0001', '200', 'USD', '40', '', '7.00', '-7.00', '', '70.00', '-70.00'),
        array('0002', '100', 'USD', '', '9.00', '', '', '90.00', '', ''),
        array('0003', '100', 'USD', '10', '', '99.00', '-99.00', '', '99.00', '-99.00'),
        array('9001', '100', 'USD', '', '12.00', '', '', '120.00', '', ''),
        array('w18', '100', 'IDR', '', '8.00', '', '', '80.00', '', '')
    ));
    check($stats['resultRows'], 7);
    check($stats['summary'], array('matched' => 0, 'different' => 1, 'f1Only' => 4, 'efsOnly' => 2, 'rasionalisasi' => 0,
        'f1TotalIDR' => '1195.00', 'efsTotalIDR' => '619.00', 'combinedTotalIDR' => '1814.00', 'f1MatchedGroups' => 1, 'f1UnmatchedGroups' => 4));
    check($stats['unmatchedEfsGroups'], 4);
    check($stats['lkpRows'], 9);
    check($stats['filteredByF8'], 2);
    check($stats['filteredByPeriod'], 1);
    require_once __DIR__ . '/../vendor/autoload.php';
    $service->process($lkp, $tb, $out, 'xlsx');
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($out);
    $sheet = $book->getActiveSheet();
    check((int) $sheet->getHighestRow(), 8);
    check((string) $sheet->getCell('A2')->getValue(), '0001');
    check($sheet->getCell('A2')->getDataType(), 'inlineStr');
    check((string) $sheet->getCell('D3')->getValue(), '10,20');
    check((string) $sheet->getCell('E3')->getValue(), '90.15');
    check($sheet->getCell('E3')->getDataType(), 'n');
    foreach (array('D2', 'F2', 'G2', 'I2', 'J2', 'E4', 'H4', 'G5', 'E6', 'H6', 'J8') as $cell)
        check($sheet->getCell($cell)->getValue(), NULL);
    check((string) $sheet->getCell('J3')->getValue(), '450');
    check((string) $sheet->getCell('G6')->getValue(), '-99');
    check($sheet->getFreezePane(), 'A2');
    $book->disconnectWorksheets();

    // Suppress both differences for matched, F1-only, and EFS-only accounts.
    $ci->Reconciliation_model->rasionalisasi = array('100' => TRUE, '200' => TRUE);
    $rationalizedStats = $service->process($lkp, $tb, $out);
    $handle = fopen($out, 'r');
    fgetcsv($handle, 0, ';');
    foreach ($rows as $expected) {
        $expected[6] = $expected[9] = '';
        check(fgetcsv($handle, 0, ';'), $expected);
    }
    fclose($handle);
    check($rationalizedStats['summary']['different'], 0);
    check($rationalizedStats['summary']['rasionalisasi'], 1);
    check($rationalizedStats['summary']['f1MatchedGroups'], 1);
    $service->process($lkp, $tb, $out, 'xlsx');
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($out);
    foreach (array('G3', 'J3', 'G4', 'J4', 'G6', 'J6') as $cell)
        check($book->getActiveSheet()->getCell($cell)->getValue(), NULL);
    foreach (range('A', 'J') as $column) {
        foreach (range(2, 8) as $rowNumber) {
            $fill = $book->getActiveSheet()->getStyle($column . $rowNumber)->getFill();
            check($fill->getFillType(), 'solid');
            check($fill->getStartColor()->getARGB(), 'FFFFCC80');
        }
    }
    check($book->getActiveSheet()->getStyle('A1')->getFill()->getFillType(), 'none');
    check($book->getActiveSheet()->getStyle('E3')->getNumberFormat()->getFormatCode(), '#,##0.00');
    $book->disconnectWorksheets();
    // Highlight only the selected account; unrelated rows retain their default fill.
    $ci->Reconciliation_model->rasionalisasi = array('200' => TRUE);
    $service->process($lkp, $tb, $out, 'xlsx');
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($out);
    check($book->getActiveSheet()->getStyle('A3')->getFill()->getFillType(), 'none');
    check($book->getActiveSheet()->getStyle('J4')->getFill()->getStartColor()->getARGB(), 'FFFFCC80');
    $book->disconnectWorksheets();
    $ci->Reconciliation_model->rasionalisasi = array();

    // Duplicate mapping rows multiply EFS values just as a SQL LEFT JOIN does.
    $ci->Reconciliation_model->rows[] = array('rincianAkun' => '10', 'coaF1' => '100');
    $service->process($lkp, $tb, $out);
    check(strpos(file_get_contents($out), '90.15;75.10;15.05;900.00;750.00;150.00') !== FALSE, TRUE);
    $ci->Reconciliation_model->rows = array();
    $emptyStats = $service->process($lkp, $tb, $out);
    check($emptyStats['unmatchedEfsGroups'], 5);
    check($emptyStats['resultRows'], 5);
    check($emptyStats['summary']['f1TotalIDR'], '1195.00');
    check($emptyStats['summary']['f1MatchedGroups'], 0);
    check($emptyStats['summary']['f1UnmatchedGroups'], 5);
    check(count(file($out)), 6);
    check($ci->amount_math->sum('999999999999999999.99', '0.01'), '1000000000000000000.00');
    // Preserve quoted delimiters, escaped quotes, empty fields, and whitespace.
    check($ci->reconciliation_parser->parse_delimited_line(' a || b |', '|'), array('a', '', 'b', ''));
    check($ci->reconciliation_parser->parse_delimited_line('"a|b"|"c""d"|', '|'), array('a|b', 'c"d', ''));
    // Fast quoted exports must retain delimiters inside fields and empty values.
    check($ci->reconciliation_parser->parse_delimited_line('" a "|""|"b|c"|""', '|'), array('a', '', 'b|c', ''));
    check($ci->reconciliation_parser->parse_delimited_line('"a"|"b""c"|"d"', '|'), array('a', 'b"c', 'd'));
    check($ci->reconciliation_parser->parse_delimited_line('"a"|b|"c"', '|'), array('a', 'b', 'c'));
    foreach (array(
        array('9999999999999999.99', '9999999999999999.99', '19999999999999999.98'),
        array('-9999999999999999.99', '-9999999999999999.99', '-19999999999999999.98'),
        array('100.01', '-100.02', '-0.01'),
        array('-0.01', '0.01', '0.00'),
        array('1.239', '0.009', '1.23'),
        array('100000000000000000000.01', '-100000000000000000000.02', '-0.01')
    ) as $case)
        check($ci->amount_math->sum($case[0], $case[1]), $case[2]);
    $total = 0;
    $reference = '0.00';
    // Extended-precision exports keep the integer fast path and truncate per row.
    foreach (array(
        '123.456789' => '123.45',
        '-0.009000' => '0.00',
        '+0002.309999' => '2.30',
        '-123.459999' => '-123.45'
    ) as $value => $expected) {
        $cents = $ci->amount_math->accumulate(0, $value);
        check(is_int($cents), TRUE);
        check($ci->amount_math->format_accumulator($cents), $expected);
    }
    foreach (array(
        '1.239',
        '-0.019',
        '',
        '+0002.30',
        '99999999999999999.99',
        '0.01',
        '-99999999999999999.99',
        '-3.51',
        '-100000000000000000000.02',
        '100000000000000000000.01'
    ) as $value) {
        $total = $ci->amount_math->accumulate($total, $value);
        $reference = $ci->amount_math->sum($reference, $value);
        check($ci->amount_math->format_accumulator($total), $reference);
    }
    // Fast cents conversion must agree with decimal arithmetic for signed values,
    // leading zeroes, one/two decimals, extra precision, and integer overflow.
    mt_srand(20260930);
    $fastTotal = 0;
    $decimalTotal = '0.00';
    for ($i = 0; $i < 2000; $i++) {
        $value = ($i % 2 ? '-' : '+') . '000' . mt_rand(0, 10000000)
            . '.' . str_pad((string) mt_rand(0, 99), 2, '0', STR_PAD_LEFT);
        if ($i % 3 === 0)
            $value = substr($value, 0, -1);
        $fastTotal = $ci->amount_math->accumulate($fastTotal, $value);
        $decimalTotal = $ci->amount_math->sum($decimalTotal, $value);
        check($ci->amount_math->format_accumulator($fastTotal), $decimalTotal);
    }
    foreach (array('1e3', '1.2.3', '--1', '12x', '.', '+', '1.') as $invalid) {
        $rejected = FALSE;
        try {
            $ci->amount_math->accumulate(0, $invalid);
        } catch (Exception $e) {
            $rejected = TRUE;
        }
        check($rejected, TRUE);
    }
    // Cross the native integer limit in both directions without rounding.
    foreach (array('', '-') as $sign) {
        $total = 0;
        $reference = '0.00';
        for ($i = 0; $i < 12; $i++) {
            $value = $sign . '9999999999999999.99';
            $total = $ci->amount_math->accumulate($total, $value);
            $reference = $ci->amount_math->sum($reference, $value);
            check($ci->amount_math->format_accumulator($total), $reference);
        }
    }
    $rejected = FALSE;
    try {
        $ci->amount_math->accumulate(0, 'invalid');
    } catch (Exception $e) {
        $rejected = TRUE;
    }
    check($rejected, TRUE);
    check(isset($stats['timingsSeconds']['total']), TRUE);
    foreach (array(
        array(100, 201),
        array(-100, 201),
        array(PHP_INT_MAX, -1),
        array(-PHP_INT_MAX, 1),
        array('100000000000000000000.01', '100000000000000000000.02')
    ) as $pair) {
        check(
            $ci->amount_math->subtract_accumulators($pair[0], $pair[1]),
            $ci->amount_math->subtract($ci->amount_math->format_accumulator($pair[0]), $ci->amount_math->format_accumulator($pair[1]))
        );
    }
    // Projection retains reordered/duplicate-header semantics and validates row width.
    $stream = fopen('php://memory', 'w+b');
    fwrite($stream, "EXTRA\tAMOUNT\tPERIOD_NUM\tCURRENCY_CODE\tCONCATENATED_SEGMENTS\tBASE_AMOUNT\tAMOUNT\nignored\t1\t6\tUSD\t0001-x-20\t3\t2\n");
    rewind($stream);
    $projected = NULL;
    $ci->reconciliation_parser->parse_tb_handle($stream, function ($row) use (&$projected) {
        $projected = $row; }, TRUE);
    check(count($projected), 5);
    check($projected['AMOUNT'], '2');
    fclose($stream);
    // Encoded group keys must preserve tuple order and avoid collisions.
    $method = new ReflectionMethod('Reconciliation_service', 'group_key');
    $method->setAccessible(TRUE);
    $tuples = array();
    foreach (array('', '0', '00', '2', '10', '/', ':', "a\0", 'a', 'aa', 'é') as $branch) {
        foreach (array('', '1', '01', '/') as $coa) {
            $tuples[] = array($branch, $coa, 'USD');
        }
    }
    $expectedOrder = $tuples;
    usort($expectedOrder, function ($a, $b) {
        for ($i = 0; $i < 3; $i++) {
            $cmp = strcmp($a[$i], $b[$i]);
            if ($cmp !== 0)
                return $cmp;
        }
        return 0;
    });
    $encoded = array();
    foreach ($tuples as $tuple)
        $encoded[$method->invokeArgs($service, $tuple)] = $tuple;
    ksort($encoded, SORT_STRING);
    check(array_values($encoded), $expectedOrder);
    echo "Reconciliation checks passed\n";
} finally {
    unlink($lkp);
    unlink($tb);
    unlink($out);
}
