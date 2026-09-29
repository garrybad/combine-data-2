<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Reconciliation_model extends CI_Model
{
    public function get_mapping_efs()
    {
        $rows = $this->db->get('mappingEfs')->result_array();
        if (!$rows) return array();

        $first = $rows[0];
        $columns = array_keys($first);
        $find = function ($target) use ($columns) {
            foreach ($columns as $column) {
                if (strtolower($column) === strtolower($target)) return $column;
            }
            return NULL;
        };

        $rincian = $find('rincianAkun');
        $nama = $find('namaCOA');
        $penjelasan = $find('penjelasanCOA');
        $coa = $find('coaF1');
        $missing = array();
        foreach (array('rincianAkun' => $rincian, 'coaF1' => $coa) as $name => $column) {
            if (!$column) $missing[] = $name;
        }
        if ($missing) throw new Exception('Kolom mappingEfs tidak lengkap. Dibutuhkan: ' . implode(', ', $missing) . '.');

        $normalized = array();
        foreach ($rows as $row) {
            $copy = array();
            foreach ($row as $key => $value) $copy[$key] = trim((string) ($value === NULL ? '' : $value));
            $copy['rincianAkun'] = trim((string) ($row[$rincian] === NULL ? '' : $row[$rincian]));
            $copy['namaCOA'] = trim((string) ($nama === NULL || $row[$nama] === NULL ? '' : $row[$nama]));
            $copy['penjelasanCOA'] = trim((string) ($penjelasan === NULL || $row[$penjelasan] === NULL ? '' : $row[$penjelasan]));
            $copy['coaF1'] = $row[$coa] === NULL ? NULL : trim((string) $row[$coa]);
            $normalized[] = $copy;
        }
        return $normalized;
    }
}
