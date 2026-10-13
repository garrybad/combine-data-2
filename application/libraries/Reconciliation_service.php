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

    public function process($lkp_path, $tb_path, $output_path, $format = 'csv', $on_result = NULL)
    {
        $started = microtime(TRUE);
        $mapping_rows = $this->model->get_mapping_efs();
        $rasionalisasi_accounts = $this->model->get_rasionalisasi_accounts();

        $mapping_by_rincian = array();
        foreach ($mapping_rows as $row) {
            $key = $this->key($row['rincianAkun']);
            if ($key === '')
                continue;
            if (!isset($mapping_by_rincian[$key]))
                $mapping_by_rincian[$key] = array();
            $mapping_by_rincian[$key][] = $row['coaF1'] === NULL ? NULL : $this->key($row['coaF1']);
        }

        $duplicate_mapping = 0;
        foreach ($mapping_by_rincian as $rows)
            if (count($rows) > 1)
                $duplicate_mapping++;
        $mapping_done = microtime(TRUE);

        $stats = array(
            'tbRows' => 0,
            'mappedRows' => 0,
            'unmatchedRincianAkun' => 0,
            'filteredByPeriod' => 0,
            'filteredByF8' => 0,
            'unmatchedEfsGroups' => 0,
            'resultRows' => 0,
            'rasionalisasiRows' => 0,
            'nonRasionalisasiRows' => 0,
            'duplicateMappingKeys' => $duplicate_mapping,
            'lkpRows' => 0
        );
        $f1_total_idr = 0;
        $source_dates = array();
        $lkp_groups = array();
        $lkp_handle = fopen($lkp_path, 'rb');
        if (!$lkp_handle)
            throw new Exception('File LKP tidak dapat dibuka.');
        try {
            $this->parser->parse_lkp_handle($lkp_handle, function ($row) use (&$lkp_groups, &$stats, &$f1_total_idr, &$source_dates) {
                $stats['lkpRows']++;
                $source_dates[$row['f1']] = TRUE;
                // Parser already trims every source field.
                $branch = $row['f5'];
                if ($row['f8'] !== '0000') {
                    return;
                }
                $stats['filteredByF8']++;
                if ($branch === '0000') {
                    return;
                }
                $f1_total_idr = $this->amount->accumulate($f1_total_idr, $row['f7']);
                $coa = $row['f2'];
                $currency = strtoupper($row['f4']);
                $key = $this->group_key($branch, $coa, $currency);
                if (!isset($lkp_groups[$key])) {
                    $lkp_groups[$key] = array(
                        'branch' => $branch,
                        'coaF1' => $coa,
                        'currency' => $currency,
                        'lkp_ori' => 0,
                        'lkp_eqIDR' => 0
                    );
                }
                $lkp_groups[$key]['lkp_ori'] = $this->amount->accumulate($lkp_groups[$key]['lkp_ori'], $row['f6']);
                $lkp_groups[$key]['lkp_eqIDR'] = $this->amount->accumulate($lkp_groups[$key]['lkp_eqIDR'], $row['f7']);
            });
        } finally {
            fclose($lkp_handle);
        }
        $stats['sourceDates'] = array_keys($source_dates);
        $lkp_done = microtime(TRUE);

        $layout = $this->parser->detect_efs_layout($tb_path);
        $has_idr = $layout['hasIdr'];
        $has_period = isset($layout['columns']['period']);
        $target_period = $this->CI->reconciliation_date->period_from_source($stats['sourceDates']);
        $stats['efsLayout'] = array(
            'format' => $layout['source']['type'] === 'xlsx' ? 'XLSX' : 'Teks',
            'sheet' => $layout['source']['type'] === 'xlsx' ? $layout['source']['name'] : NULL,
            'columns' => $layout['names'],
            'amountMode' => $has_idr ? 'ori_idr' : 'ori'
        );
        $stats['efsPeriod'] = $has_period && $target_period
            ? sprintf('%04d-%02d', $target_period['year'], $target_period['month']) : NULL;

        $efs_groups = array();
        $period_matches = array();
        $seen_periods = array();
        $this->parser->parse_efs_file($tb_path, $layout, function ($row) use (&$stats, &$mapping_by_rincian, &$efs_groups,
            &$period_matches, &$seen_periods, $has_idr, $has_period, $target_period) {
            $stats['tbRows']++;
            if ($has_period) {
                $period = $row['period'];
                if (!isset($period_matches[$period])) {
                    $parsed = $this->parser->parse_period($period);
                    if (count($seen_periods) < 10) $seen_periods[$period === '' ? '(kosong)' : $period] = TRUE;
                    $period_matches[$period] = $parsed !== NULL && ($target_period === NULL
                        || $parsed['month'] === $target_period['month'] && ($parsed['year'] === NULL || $parsed['year'] === $target_period['year']));
                }
                if (!$period_matches[$period]) {
                    $stats['filteredByPeriod']++;
                    return;
                }
            }
            $branch = $row['branch'];
            $rincian = $row['rincian'];
            $mappings = isset($mapping_by_rincian[$rincian]) ? $mapping_by_rincian[$rincian] : array();
            if (!$mappings) {
                $stats['unmatchedRincianAkun']++;
                return;
            }
            $stats['mappedRows']++;
            $currency = strtoupper($row['currency']);
            foreach ($mappings as $coa) {
                if ($coa === NULL)
                    continue;
                $key = $this->group_key($branch, $coa, $currency);
                if (!isset($efs_groups[$key])) {
                    $efs_groups[$key] = array(
                        'branch' => $branch,
                        'coaF1' => $coa,
                        'currency' => $currency,
                        'rincian' => array(),
                        'efs_ori' => 0,
                        'efs_eqIDR' => 0
                    );
                }
                $efs_groups[$key]['rincian'][$rincian] = $rincian;
                $efs_groups[$key]['efs_ori'] = $this->amount->accumulate($efs_groups[$key]['efs_ori'], $row['ori']);
                if ($has_idr)
                    $efs_groups[$key]['efs_eqIDR'] = $this->amount->accumulate($efs_groups[$key]['efs_eqIDR'], $row['idr']);
            }
        });
        if ($has_period && $target_period === NULL && count($period_matches) > 1) {
            throw new Exception('File EFS berisi beberapa periode (' . implode(', ', array_keys($seen_periods)) .
                '), tetapi periode F1 tidak dapat ditentukan karena kolom f1 tidak berisi satu bulan yang sama.');
        }
        if ($stats['tbRows'] > 0 && $stats['filteredByPeriod'] === $stats['tbRows']) {
            throw new Exception('Tidak ada baris EFS untuk periode F1 ' . $stats['efsPeriod'] . '. Periode di file EFS: ' .
                implode(', ', array_keys($seen_periods)) . '.');
        }
        $tb_done = microtime(TRUE);

        // UNION ALL: append EFS-only groups before sorting the combined result.
        foreach ($efs_groups as $key => $efs) {
            if ($efs['branch'] === '0000' || isset($lkp_groups[$key]))
                continue;
            $lkp_groups[$key] = array(
                'branch' => $efs['branch'],
                'coaF1' => $efs['coaF1'],
                'currency' => $efs['currency'],
                'lkp_ori' => NULL,
                'lkp_eqIDR' => NULL
            );
        }

        // Keys preserve the original branch/COA/currency string order.
        // Native sorting avoids millions of PHP comparator calls on large results.
        ksort($lkp_groups, SORT_STRING);
        $sort_done = microtime(TRUE);
        $summary = array('matched' => 0, 'different' => 0, 'f1Only' => 0, 'efsOnly' => 0, 'rasionalisasi' => 0,
            'f1TotalIDR' => $f1_total_idr, 'efsTotalIDR' => 0);
        // Without an IDR amount column, original amounts are IDR only for IDR groups.
        $efs_total_known = TRUE;
        $headers = array('branch', 'coaF1', 'currency', 'efs_rincianAkun', 'lkp_ori', 'efs_ori', 'selisih_ori');
        if ($has_idr) $headers = array_merge($headers, array('lkp_eqIDR', 'efs_eqIDR', 'selisih_eqIDR'));
        $exporter = new Excel_exporter();
        $exporter->start($format, $headers);
        foreach ($lkp_groups as $key => $lkp) {
            $efs = isset($efs_groups[$key]) ? $efs_groups[$key] : NULL;
            if ($efs === NULL) {
                $stats['unmatchedEfsGroups']++;
            }
            $is_rasionalisasi = isset($rasionalisasi_accounts[$lkp['coaF1']]);
            // Count every exported row, including F1-only and EFS-only groups.
            if ($is_rasionalisasi) $stats['rasionalisasiRows']++;
            else $stats['nonRasionalisasiRows']++;
            $difference_ori = $efs === NULL || $is_rasionalisasi ? NULL : $this->amount->subtract_accumulators($lkp['lkp_ori'] === NULL ? 0 : $lkp['lkp_ori'], $efs['efs_ori']);
            $difference_idr = $efs === NULL || $is_rasionalisasi || !$has_idr ? NULL : $this->amount->subtract_accumulators($lkp['lkp_eqIDR'] === NULL ? 0 : $lkp['lkp_eqIDR'], $efs['efs_eqIDR']);
            $lkp['lkp_ori'] = $lkp['lkp_ori'] === NULL ? NULL : $this->amount->format_accumulator($lkp['lkp_ori']);
            $lkp['lkp_eqIDR'] = $lkp['lkp_eqIDR'] === NULL || !$has_idr ? NULL : $this->amount->format_accumulator($lkp['lkp_eqIDR']);
            // Statistics describe the existing output; they do not alter reconciliation.
            if ($efs !== NULL) {
                if ($has_idr || $efs['currency'] === 'IDR') {
                    $summary['efsTotalIDR'] = $this->amount->accumulate($summary['efsTotalIDR'],
                        $this->amount->format_accumulator($has_idr ? $efs['efs_eqIDR'] : $efs['efs_ori']));
                } else {
                    $efs_total_known = FALSE;
                }
            }
            if ($efs === NULL) $summary['f1Only']++;
            elseif ($lkp['lkp_ori'] === NULL) $summary['efsOnly']++;
            elseif ($is_rasionalisasi) $summary['rasionalisasi']++;
            elseif ($difference_ori === '0.00' && (!$has_idr || $difference_idr === '0.00')) $summary['matched']++;
            else $summary['different']++;
            if ($efs !== NULL)
                sort($efs['rincian'], SORT_STRING);
            $result_row = array_merge($lkp, array(
                'efs_rincianAkun' => $efs === NULL ? NULL : implode(',', $efs['rincian']),
                'efs_ori' => $efs === NULL ? NULL : $this->amount->format_accumulator($efs['efs_ori']),
                'selisih_ori' => $difference_ori,
                'efs_eqIDR' => $efs === NULL || !$has_idr ? NULL : $this->amount->format_accumulator($efs['efs_eqIDR']),
                'selisih_eqIDR' => $difference_idr
            ));
            $exporter->add_row($result_row, $is_rasionalisasi, $efs !== NULL && count($efs['rincian']) > 1);
            if ($on_result !== NULL) call_user_func($on_result, $result_row, $is_rasionalisasi);
            $stats['resultRows']++;
        }

        $summary['f1TotalIDR'] = $this->amount->format_accumulator($summary['f1TotalIDR']);
        $summary['efsTotalIDR'] = $efs_total_known ? $this->amount->format_accumulator($summary['efsTotalIDR']) : NULL;
        $summary['combinedTotalIDR'] = $efs_total_known ? $this->amount->sum($summary['f1TotalIDR'], $summary['efsTotalIDR']) : NULL;
        $summary['f1MatchedGroups'] = $summary['matched'] + $summary['different'] + $summary['rasionalisasi'];
        $summary['f1UnmatchedGroups'] = $summary['f1Only'];
        $stats['summary'] = $summary;
        $write_done = microtime(TRUE);
        $exporter->save($output_path);
        $finished = microtime(TRUE);
        // Backend timings exclude browser upload/download and web-server queuing.
        $stats['timingsSeconds'] = array(
            'mapping' => round($mapping_done - $started, 3),
            'lkp' => round($lkp_done - $mapping_done, 3),
            'tb' => round($tb_done - $lkp_done, 3),
            'sortAndExport' => round($finished - $tb_done, 3),
            'sort' => round($sort_done - $tb_done, 3),
            'writeRows' => round($write_done - $sort_done, 3),
            'saveFile' => round($finished - $write_done, 3),
            'total' => round($finished - $started, 3)
        );
        $stats['processorVersion'] = '2026-10-13.1';
        $stats['runtime'] = array(
            'phpVersion' => PHP_VERSION,
            'integerBits' => PHP_INT_SIZE * 8,
            'sapi' => PHP_SAPI,
            'xdebugLoaded' => extension_loaded('xdebug'),
            'xdebugMode' => (string) ini_get('xdebug.mode'),
            'opcacheEnabled' => function_exists('opcache_get_status')
                ? (bool) @opcache_get_status(FALSE) : FALSE
        );
        $stats['peakMemoryMB'] = round(memory_get_peak_usage(TRUE) / 1048576, 1);
        return $stats;
    }

    private function group_key($branch, $coa, $currency)
    {
        // Hex encoding handles every byte, including separators in source data.
        // '/' sorts before hex digits, preserving shorter-prefix-first ordering.
        return bin2hex($branch) . '/' . bin2hex($coa) . '/' . bin2hex($currency);
    }

    private function key($value)
    {
        return trim((string) $value);
    }
}
