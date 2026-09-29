<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Reconciliation_service
{
    private $CI;
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

    public function process($lkp_path, $tb_path, $output_path, $format = 'csv')
    {
        $mapping_rows = $this->model->get_mapping_efs();

        $mapping_by_rincian = array();
        foreach ($mapping_rows as $row) {
            $key = $this->key($row['rincianAkun']);
            if ($key === '') continue;
            if (!isset($mapping_by_rincian[$key])) $mapping_by_rincian[$key] = array();
            $mapping_by_rincian[$key][] = $row;
        }

        $duplicate_mapping = 0;
        foreach ($mapping_by_rincian as $rows) if (count($rows) > 1) $duplicate_mapping++;

        $stats = array('tbRows' => 0, 'mappedRows' => 0, 'unmatchedRincianAkun' => 0,
            'filteredByPeriod' => 0, 'filteredByF5' => 0, 'unmatchedEfsGroups' => 0,
            'resultRows' => 0, 'duplicateMappingKeys' => $duplicate_mapping);
        $lkp_groups = array();
        $lkp_handle = fopen($lkp_path, 'rb');
        if (!$lkp_handle) throw new Exception('File LKP tidak dapat dibuka.');
        try {
            $this->parser->parse_lkp_handle($lkp_handle, function ($row) use (&$lkp_groups, &$stats) {
                $branch = $this->key($row['f5']);
                if ($branch === '0000') { $stats['filteredByF5']++; return; }
                $coa = $this->key($row['f2']);
                $currency = $this->key($row['f4']);
                $key = $this->group_key($branch, $coa, $currency);
                if (!isset($lkp_groups[$key])) {
                    $lkp_groups[$key] = array('branch' => $branch, 'coaF1' => $coa,
                        'currency' => $currency, 'lkp_ori' => '0.00', 'lkp_eqIDR' => '0.00');
                }
                // CASE WHEN keeps the group even if none of its rows has f8 = 0000.
                if ($this->key($row['f8']) === '0000') {
                    $lkp_groups[$key]['lkp_ori'] = $this->amount->sum($lkp_groups[$key]['lkp_ori'], $row['f6']);
                    $lkp_groups[$key]['lkp_eqIDR'] = $this->amount->sum($lkp_groups[$key]['lkp_eqIDR'], $row['f7']);
                }
            });
        } finally { fclose($lkp_handle); }

        $efs_groups = array();
        $tb_handle = fopen($tb_path, 'rb');
        if (!$tb_handle) throw new Exception('File TB Juni tidak dapat dibuka.');
        try {
            $this->parser->parse_tb_handle($tb_handle, function ($row) use (&$stats, &$mapping_by_rincian, &$efs_groups) {
                $stats['tbRows']++;
                if (!is_numeric($row['PERIOD_NUM']) || (float) $row['PERIOD_NUM'] != 6) {
                    $stats['filteredByPeriod']++;
                    return;
                }
                $segments = explode('-', $row['CONCATENATED_SEGMENTS']);
                $branch = $this->key($segments[0]);
                $rincian = $this->parser->extract_rincian_akun($row['CONCATENATED_SEGMENTS']);
                $mappings = isset($mapping_by_rincian[$rincian]) ? $mapping_by_rincian[$rincian] : array();
                if (!$mappings) { $stats['unmatchedRincianAkun']++; return; }
                $stats['mappedRows']++;
                foreach ($mappings as $mapping) {
                    if ($mapping['coaF1'] === NULL) continue;
                    $key = $this->group_key($branch, $this->key($mapping['coaF1']), $this->key($row['CURRENCY_CODE']));
                    if (!isset($efs_groups[$key])) {
                        $efs_groups[$key] = array('rincian' => array(), 'efs_ori' => '0.00', 'efs_eqIDR' => '0.00');
                    }
                    $efs_groups[$key]['rincian'][$rincian] = $rincian;
                    $efs_groups[$key]['efs_ori'] = $this->amount->sum($efs_groups[$key]['efs_ori'], $row['AMOUNT']);
                    $efs_groups[$key]['efs_eqIDR'] = $this->amount->sum($efs_groups[$key]['efs_eqIDR'], $row['BASE_AMOUNT']);
                }
            });
        } finally { fclose($tb_handle); }

        uasort($lkp_groups, function ($a, $b) {
            foreach (array('branch', 'coaF1', 'currency') as $column) {
                $comparison = strcmp($a[$column], $b[$column]);
                if ($comparison !== 0) return $comparison;
            }
            return 0;
        });
        $exporter = new Excel_exporter();
        $exporter->start($format);
        foreach ($lkp_groups as $key => $lkp) {
            $efs = isset($efs_groups[$key]) ? $efs_groups[$key] : NULL;
            $rincian = NULL;
            if ($efs !== NULL) {
                sort($efs['rincian'], SORT_STRING);
                $rincian = implode(',', $efs['rincian']);
            } else {
                $stats['unmatchedEfsGroups']++;
            }
            $exporter->add_row(array_merge($lkp, array(
                'efs_rincianAkun' => $rincian,
                'efs_ori' => $efs !== NULL ? $efs['efs_ori'] : NULL,
                'selisih_ori' => $this->amount->subtract($lkp['lkp_ori'], $efs !== NULL ? $efs['efs_ori'] : '0'),
                'efs_eqIDR' => $efs !== NULL ? $efs['efs_eqIDR'] : NULL,
                'selisih_eqIDR' => $this->amount->subtract($lkp['lkp_eqIDR'], $efs !== NULL ? $efs['efs_eqIDR'] : '0')
            )));
            $stats['resultRows']++;
        }

        $exporter->save($output_path);
        return $stats;
    }

    private function group_key($branch, $coa, $currency)
    {
        return serialize(array($branch, $coa, $currency));
    }

    private function key($value) { return trim((string) $value); }
}
