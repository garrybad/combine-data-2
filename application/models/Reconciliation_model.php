<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Reconciliation_model extends CI_Model
{
    public function get_rasionalisasi_accounts()
    {
        $query = $this->db->query("SELECT `SL F1` AS coaF1,
            MAX(CASE WHEN UPPER(TRIM(`Status`)) = 'RASIONALISASI' THEN 1 ELSE 0 END) AS is_rasionalisasi
            FROM `GL_Rasionalisasi` GROUP BY `SL F1`");
        if ($query === FALSE)
            throw new Exception('Tabel GL_Rasionalisasi tidak dapat dibaca. Pastikan kolom SL F1 dan Status tersedia.');
        $accounts = array();
        foreach ($query->result_array() as $row) {
            if ($row['coaF1'] !== NULL && (int) $row['is_rasionalisasi'] === 1)
                $accounts[trim((string) $row['coaF1'])] = TRUE;
        }
        return $accounts;
    }

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
