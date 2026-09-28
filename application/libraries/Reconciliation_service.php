<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Reconciliation_service
{
    private $parser;
    private $amount;
    private $model;

    public function __construct()
    {
        $this->CI =& get_instance();
        $this->parser = $this->CI->reconciliation_parser;
        $this->amount = $this->CI->amount_math;
        $this->model = $this->CI->Reconciliation_model;
    }

    public function process($lkp_path, $tb_path, $output_path)
    {
        $mapping_rows = $this->model->get_mapping_efs();
        if (!$mapping_rows) throw new Exception('Tabel mappingEfs tidak memiliki data.');

        $mapping_by_rincian = array();
        foreach ($mapping_rows as $row) {
            $key = $this->key($row['rincianAkun']);
            if ($key === '') continue;
            if (!isset($mapping_by_rincian[$key])) $mapping_by_rincian[$key] = array();
            $mapping_by_rincian[$key][] = $row;
        }

        $duplicate_mapping = 0;
        foreach ($mapping_by_rincian as $rows) if (count($rows) > 1) $duplicate_mapping++;

        $all_lkp_keys = array();
        $valid_lkp_by_f2 = array();
        $lkp_handle = fopen($lkp_path, 'rb');
        if (!$lkp_handle) throw new Exception('File LKP tidak dapat dibuka.');
        try {
            $this->parser->parse_lkp_handle($lkp_handle, function ($row) use (&$all_lkp_keys, &$valid_lkp_by_f2) {
                $key = $this->key($row['f2']);
                if ($key === '') return;
                if (!isset($all_lkp_keys[$key])) $all_lkp_keys[$key] = 0;
                $all_lkp_keys[$key]++;
                if ($this->key($row['f5']) === '0000') {
                    if (!isset($valid_lkp_by_f2[$key])) $valid_lkp_by_f2[$key] = array();
                    $valid_lkp_by_f2[$key][] = $row;
                }
            });
        } finally { fclose($lkp_handle); }

        $duplicate_lkp = 0;
        foreach ($all_lkp_keys as $count) if ($count > 1) $duplicate_lkp++;

        $exporter = new Excel_exporter();
        $exporter->start();
        $stats = array('tbRows' => 0, 'mappedRows' => 0, 'unmatchedRincianAkun' => 0, 'unmatchedCoaF1' => 0, 'filteredByF5' => 0, 'resultRows' => 0, 'duplicateMappingKeys' => $duplicate_mapping, 'duplicateLkpF2Keys' => $duplicate_lkp);

        $tb_handle = fopen($tb_path, 'rb');
        if (!$tb_handle) throw new Exception('File TB Juni tidak dapat dibuka.');
        try {
            $this->parser->parse_tb_handle($tb_handle, function ($tb_row) use (&$stats, &$mapping_by_rincian, &$all_lkp_keys, &$valid_lkp_by_f2, $exporter) {
                $stats['tbRows']++;
                $rincian = $this->parser->extract_rincian_akun(isset($tb_row['CONCATENATED_SEGMENTS']) ? $tb_row['CONCATENATED_SEGMENTS'] : '');
                $mapping_key = $this->key($rincian);
                $mappings = isset($mapping_by_rincian[$mapping_key]) ? $mapping_by_rincian[$mapping_key] : array();
                if (!$mappings) { $stats['unmatchedRincianAkun']++; return; }
                $stats['mappedRows']++;

                foreach ($mappings as $mapping) {
                    $coa_key = $this->key($mapping['coaF1']);
                    $all_count = isset($all_lkp_keys[$coa_key]) ? $all_lkp_keys[$coa_key] : 0;
                    $valid_rows = isset($valid_lkp_by_f2[$coa_key]) ? $valid_lkp_by_f2[$coa_key] : array();
                    if ($all_count === 0) { $stats['unmatchedCoaF1']++; continue; }
                    if (!$valid_rows) { $stats['filteredByF5'] += $all_count; continue; }

                    foreach ($valid_rows as $lkp) {
                        $exporter->add_row(array(
                            'PERIOD_NUM' => isset($tb_row['PERIOD_NUM']) ? $tb_row['PERIOD_NUM'] : '',
                            'rincianAkun_tb' => $rincian,
                            'rincianAkun' => $mapping['rincianAkun'],
                            'namaCOA' => $mapping['namaCOA'],
                            'penjelasanCOA' => $mapping['penjelasanCOA'],
                            'coaF1' => $mapping['coaF1'],
                            'f1' => $lkp['f1'], 'f2' => $lkp['f2'], 'f3' => $lkp['f3'], 'f4' => $lkp['f4'],
                            'f5' => $lkp['f5'], 'f8' => $lkp['f8'],
                            'BASE_AMOUNT' => isset($tb_row['BASE_AMOUNT']) ? $tb_row['BASE_AMOUNT'] : '0',
                            'f7' => $lkp['f7'],
                            'selisih' => $this->amount->subtract(isset($tb_row['BASE_AMOUNT']) ? $tb_row['BASE_AMOUNT'] : '0', $lkp['f7'])
                        ));
                        $stats['resultRows']++;
                    }
                }
            });
        } finally { fclose($tb_handle); }

        $exporter->save($output_path);
        return $stats;
    }

    private function key($value) { return trim((string) $value); }
}
