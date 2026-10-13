<?php
error_reporting(E_ALL & ~E_DEPRECATED);
define('BASEPATH', __DIR__);
foreach (array('Reconciliation_parser', 'Amount_math', 'Excel_exporter', 'Reconciliation_service', 'Reconciliation_date') as $library)
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
$ci->reconciliation_date = new Reconciliation_date();
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
    file_put_contents($lkp, str_replace('x|', '20260630|', "x|100|x|USD|0002|9|90|0000\nx|100|x| usd |0001|100.25|1000|0000\nx|100|x|USD|0001|-10.10|-100|0000\nx|100|x|USD|0001|999|999|1111\nx|100|x|IDR|0001|5|5|0000\nx|200|x|USD|0001|50|50|1111\nx|100|x|USD|0000|100|100|0000\nx|100|x|USD|9001|12|120|0000\nx|100|x|IDR|w18|8|80|0000\n"));
    file_put_contents($tb, "CONCATENATED_SEGMENTS\tPERIOD_NUM\tCURRENCY_CODE\tAMOUNT\tBASE_AMOUNT\n0001-x-20\t6\t UsD \t20.10\t200\n0001-x-10\t6\tUSD\t30\t300\n0001-x-20\t6\tUSD\t-5\t-50\n0001-x-20\t5\tUSD\t999\t999\n0003-x-10\t6\tUSD\t99\t99\n0001-x-30\t6\tUSD\t99\t99\n0001-x-99\t6\tUSD\t99\t99\n0000-x-10\t6\tusd\t10\t20\n0001-x-40\t6\tUSD\t7\t70\n");
    $service = new Reconciliation_service();
    $captured = array();
    $stats = $service->process($lkp, $tb, $out, 'csv', function ($row, $rationalized) use (&$captured) {
        $captured[] = array($row, $rationalized);
    });
    check(count($captured), 7);
    check($captured[1][0]['selisih_eqIDR'], '450.00');
    check($captured[1][1], FALSE);
    check($stats['sourceDates'], array(20260630));
    check($stats['efsLayout']['amountMode'], 'ori_idr');
    check($stats['efsPeriod'], '2026-06');
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
    check($stats['rasionalisasiRows'], 0);
    check($stats['nonRasionalisasiRows'], 7);
    check($stats['summary'], array('matched' => 0, 'different' => 1, 'f1Only' => 4, 'efsOnly' => 2, 'rasionalisasi' => 0,
        'f1TotalIDR' => '1195.00', 'efsTotalIDR' => '619.00', 'combinedTotalIDR' => '1814.00', 'f1MatchedGroups' => 1, 'f1UnmatchedGroups' => 4));
    check($stats['unmatchedEfsGroups'], 4);
    check($stats['lkpRows'], 9);
    check($stats['filteredByF8'], 7);
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
    foreach (range('A', 'J') as $column)
        check($sheet->getStyle($column . '3')->getFill()->getStartColor()->getARGB(), 'FFFFFF00');
    check($sheet->getStyle('A6')->getFill()->getFillType(), 'none');
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
    check($rationalizedStats['rasionalisasiRows'], 7);
    check($rationalizedStats['nonRasionalisasiRows'], 0);
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
            check($fill->getStartColor()->getARGB(), $rowNumber === 3 ? 'FFFFFF00' : 'FFFFCC80');
        }
    }
    check($book->getActiveSheet()->getStyle('A1')->getFill()->getFillType(), 'none');
    check($book->getActiveSheet()->getStyle('E3')->getNumberFormat()->getFormatCode(), '#,##0.00');
    $book->disconnectWorksheets();
    // Highlight only the selected account; unrelated rows retain their default fill.
    $ci->Reconciliation_model->rasionalisasi = array('200' => TRUE);
    $mixedStats = $service->process($lkp, $tb, $out, 'xlsx');
    check($mixedStats['rasionalisasiRows'], 1);
    check($mixedStats['nonRasionalisasiRows'], 6);
    check($mixedStats['rasionalisasiRows'] + $mixedStats['nonRasionalisasiRows'], $mixedStats['resultRows']);
    $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($out);
    check($book->getActiveSheet()->getStyle('A3')->getFill()->getStartColor()->getARGB(), 'FFFFFF00');
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
    $parser = $ci->reconciliation_parser;
    $parse_all = function ($path) use ($parser) {
        $layout = $parser->detect_efs_layout($path);
        $rows = array();
        $parser->parse_efs_file($path, $layout, function ($row) use (&$rows) { $rows[] = $row; });
        return array($layout, $rows);
    };
    // Reordered headers: a later duplicate header overrides an earlier one.
    file_put_contents($tb, "EXTRA\tAMOUNT\tPERIOD_NUM\tCURRENCY_CODE\tCONCATENATED_SEGMENTS\tBASE_AMOUNT\tAMOUNT\nignored\t1\t6\tUSD\t0001-x-20\t3\t2\n");
    list($layout, $parsed) = $parse_all($tb);
    check($parsed, array(array('branch' => '0001', 'rincian' => '20', 'currency' => 'USD', 'period' => '6', 'ori' => '2', 'idr' => '3')));
    check($layout['names']['ori'], 'AMOUNT');
    check($layout['columns']['ori'], 6);
    // Quoted wide exports trim selected fields and retain escaped delimiters.
    foreach (array('" unused "', '"unused""value"') as $extra) {
        file_put_contents($tb, "EXTRA\tAMOUNT\tPERIOD_NUM\tCURRENCY_CODE\tCONCATENATED_SEGMENTS\tBASE_AMOUNT\n" .
            $extra . "\t\" 1.25 \"\t\" 6 \"\t\" USD \"\t\" 0001-x-20 \"\t\" 3.50 \"\n");
        list(, $parsed) = $parse_all($tb);
        check($parsed, array(array('branch' => '0001', 'rincian' => '20', 'currency' => 'USD', 'period' => '6', 'ori' => '1.25', 'idr' => '3.50')));
    }
    // Rows must keep the header width in delimited files.
    file_put_contents($tb, "CONCATENATED_SEGMENTS\tPERIOD_NUM\tCURRENCY_CODE\tAMOUNT\tBASE_AMOUNT\n0001-x-20\t6\tUSD\t1\n");
    $rejected = FALSE;
    try { $parse_all($tb); } catch (Exception $e) { $rejected = strpos($e->getMessage(), 'baris 2') !== FALSE; }
    check($rejected, TRUE);
    // Headerless semicolon file: columns are recognised from their contents only.
    file_put_contents($tb, "0001;1011010001;IDR;100.50;100.50\n0002;1011010002;usd;-5;-75000.25\n");
    list($layout, $parsed) = $parse_all($tb);
    check($layout['columns'], array('branch' => 0, 'currency' => 2, 'idr' => 4, 'ori' => 3, 'rincian' => 1));
    check(count($parsed), 2);
    check($parsed[1], array('branch' => '0002', 'rincian' => '1011010002', 'currency' => 'usd', 'period' => NULL, 'ori' => '-5', 'idr' => '-75000.25'));
    // Unknown header names fall back to content; a single amount means original only.
    file_put_contents($tb, "Kode,Akun,Mata,Nilai\n0001,1011010001,IDR,1.5E3\n");
    list($layout, $parsed) = $parse_all($tb);
    check($layout['hasIdr'], FALSE);
    check($parsed[0]['ori'], '1500');
    $rejected = FALSE;
    try {
        file_put_contents($tb, "A\tB\n1\t2\n");
        $parser->detect_efs_layout($tb);
    } catch (Exception $e) { $rejected = strpos($e->getMessage(), 'tidak dikenali') !== FALSE; }
    check($rejected, TRUE);
    foreach (array('6' => array(6, NULL), '06' => array(6, NULL), 'SEP-26' => array(9, 2026), 'Sep-2026' => array(9, 2026),
        'MEI 26' => array(5, 2026), '2026-09' => array(9, 2026), '202609' => array(9, 2026), '09/2026' => array(9, 2026)) as $value => $expected)
        check($parser->parse_period((string) $value), array('month' => $expected[0], 'year' => $expected[1]));
    foreach (array('', '0', '14', 'XYZ-26', '0001') as $value) check($parser->parse_period($value), NULL);
    check($ci->reconciliation_date->period_from_source(array('20260630', '30/06/2026', '20260615')), array('year' => 2026, 'month' => 6));
    check($ci->reconciliation_date->period_from_source(array('20260630', '20260731')), NULL);
    check($ci->reconciliation_date->period_from_source(array('x')), NULL);

    // XLSX with only an original amount: skips non-table sheets, filters by
    // F1 month, rounds Excel doubles, and exports original columns only.
    $xlsx = tempnam(sys_get_temp_dir(), 'efs') . '.xlsx';
    $zip = new ZipArchive();
    $zip->open($xlsx, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"';
    $zip->addFromString('xl/workbook.xml', '<workbook ' . $ns . '><sheets><sheet name="SQL" sheetId="1" r:id="rId1"/><sheet name="Export" sheetId="2" r:id="rId2"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Target="/xl/worksheets/sheet2.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<sst ' . $ns . '><si><t>PERIOD_NAME</t></si><si><t>ENTITY_CODE</t></si><si><r><t>NATURAL_</t></r><r><t>ACCOUNT</t></r></si><si><t>CURRENCY_CODE</t></si><si><t>ENDING_BALANCE</t></si><si><t>JUN-26</t></si><si><t>SELECT 1</t></si></sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet ' . $ns . '><sheetData><row r="2"><c r="A2" t="s"><v>6</v></c></row></sheetData></worksheet>');
    $cell = function ($ref, $value, $type = NULL) {
        if ($type === 's') return '<c r="' . $ref . '" t="s"><v>' . $value . '</v></c>';
        if ($type === 'inline') return '<c r="' . $ref . '" t="inlineStr"><is><t>' . $value . '</t></is></c>';
        return '<c r="' . $ref . '"><v>' . $value . '</v></c>';
    };
    $zip->addFromString('xl/worksheets/sheet2.xml', '<worksheet ' . $ns . '><sheetData>' .
        '<row r="1"><c r="A1" t="inlineStr"><is><t>Trial Balance</t></is></c></row>' .
        '<row r="3">' . $cell('A3', 0, 's') . $cell('B3', 1, 's') . $cell('C3', 2, 's') . $cell('D3', 3, 's') . $cell('E3', 4, 's') . '</row>' .
        '<row r="4">' . $cell('A4', 5, 's') . $cell('B4', '0001', 'inline') . $cell('C4', '20') . $cell('D4', 'IDR', 'inline') . $cell('E4', '329.39999999999998') . '</row>' .
        '<row r="5">' . $cell('A5', 5, 's') . $cell('B5', '0001', 'inline') . $cell('C5', '10') . $cell('D5', 'IDR', 'inline') . $cell('E5', '0.60000000000000009') . '</row>' .
        '<row r="6">' . $cell('A6', 'SEP-26', 'inline') . $cell('B6', '0001', 'inline') . $cell('C6', '10') . $cell('D6', 'IDR', 'inline') . $cell('E6', '999') . '</row>' .
        '<row r="7">' . $cell('A7', 5, 's') . $cell('B7', '0001', 'inline') . $cell('C7', '40') . $cell('D7', 'USD', 'inline') . '</row>' .
        '</sheetData></worksheet>');
    $zip->close();
    try {
        list($layout, $parsed) = $parse_all($xlsx);
        check($layout['source']['name'], 'Export');
        check($layout['names'], array('branch' => 'ENTITY_CODE', 'currency' => 'CURRENCY_CODE', 'ori' => 'ENDING_BALANCE',
            'period' => 'PERIOD_NAME', 'rincian' => 'NATURAL_ACCOUNT'));
        check($layout['hasIdr'], FALSE);
        check($parsed[0], array('branch' => '0001', 'rincian' => '20', 'currency' => 'IDR', 'period' => 'JUN-26', 'ori' => '329.4', 'idr' => NULL));
        check($parsed[3]['ori'], '');
        $ci->Reconciliation_model->rows = array(array('rincianAkun' => '20', 'coaF1' => '100'), array('rincianAkun' => '10', 'coaF1' => '100'),
            array('rincianAkun' => '40', 'coaF1' => '200'));
        $xlsxStats = $service->process($lkp, $xlsx, $out);
        check($xlsxStats['efsLayout']['amountMode'], 'ori');
        check($xlsxStats['filteredByPeriod'], 1);
        $lines = array_map(function ($line) { return str_getcsv($line, ';'); }, file($out, FILE_IGNORE_NEW_LINES));
        check($lines[0], array("\xEF\xBB\xBFbranch", 'coaF1', 'currency', 'efs_rincianAkun', 'lkp_ori', 'efs_ori', 'selisih_ori'));
        check($lines[1], array('0001', '100', 'IDR', '10,20', '5.00', '330.00', '-325.00'));
        check($lines[3], array('0001', '200', 'USD', '40', '', '0.00', '0.00'));
        // Original amounts count toward IDR totals only when every EFS group is IDR.
        check($xlsxStats['summary']['efsTotalIDR'], NULL);
        check($xlsxStats['summary']['different'], 1);
        $service->process($lkp, $xlsx, $out, 'xlsx');
        $book = \PhpOffice\PhpSpreadsheet\IOFactory::load($out);
        check($book->getActiveSheet()->getHighestColumn(), 'G');
        check((string) $book->getActiveSheet()->getCell('F2')->getValue(), '330');
        check($book->getActiveSheet()->getAutoFilter()->getRange(), 'A1:G7');
        $book->disconnectWorksheets();
        // A F1 month absent from the EFS file is reported instead of producing F1-only rows.
        file_put_contents($lkp, str_replace('20260630|', '20260731|', file_get_contents($lkp)));
        $rejected = FALSE;
        try { $service->process($lkp, $xlsx, $out); } catch (Exception $e) { $rejected = strpos($e->getMessage(), '2026-07') !== FALSE; }
        check($rejected, TRUE);
    } finally {
        unlink($xlsx);
    }
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
