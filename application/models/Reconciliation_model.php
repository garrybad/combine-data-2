<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Reconciliation_model extends CI_Model
{
    private function history_query($sql, $bindings = array())
    {
        $result = $this->db->query($sql, $bindings);
        if ($result === FALSE) throw new Exception('Operasi riwayat database gagal. Periksa izin CREATE/SELECT/INSERT/DELETE database.');
        return $result;
    }

    public function get_chart_history()
    {
        if (!$this->db->table_exists('reconciliation_history')) return array();
        return $this->history_query('SELECT data_date AS date, COALESCE(SUM(selisih_eqIDR), 0) AS total_selisih_eqIDR
            FROM reconciliation_history GROUP BY data_date ORDER BY data_date')->result_array();
    }

    public function save_history($date, $path)
    {
        $this->history_query('CREATE TABLE IF NOT EXISTS reconciliation_history (
            data_date DATE NOT NULL, `row_number` INT UNSIGNED NOT NULL,
            branch VARCHAR(255) NOT NULL, coaF1 VARCHAR(255) NOT NULL, currency VARCHAR(255) NOT NULL,
            efs_rincianAkun LONGTEXT NULL,
            lkp_ori DECIMAL(65,2) NULL, efs_ori DECIMAL(65,2) NULL, selisih_ori DECIMAL(65,2) NULL,
            lkp_eqIDR DECIMAL(65,2) NULL, efs_eqIDR DECIMAL(65,2) NULL, selisih_eqIDR DECIMAL(65,2) NULL,
            is_rasionalisasi TINYINT(1) NOT NULL,
            PRIMARY KEY (data_date, `row_number`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');
        $lock = $this->history_query("SELECT GET_LOCK(CONCAT(DATABASE(), ':reconciliation_history'), 10) AS acquired")->row_array();
        if ((int) $lock['acquired'] !== 1) throw new Exception('Riwayat sedang disimpan oleh proses lain. Silakan coba lagi.');
        $handle = NULL;
        try {
            if (!$this->db->trans_begin()) throw new Exception('Transaksi database tidak dapat dimulai.');
            $this->history_query('DELETE FROM reconciliation_history WHERE data_date = ?', array($date));
            $handle = fopen($path, 'rb');
            if (!$handle) throw new Exception('Data sementara tidak tersedia. Proses ulang file.');
            $batch = array();
            $number = 0;
            while (($line = fgets($handle)) !== FALSE) {
                $row = json_decode($line, TRUE);
                if (!is_array($row)) throw new Exception('Data sementara tidak valid.');
                // DECIMAL(65,2) has 63 integer digits. Reject overflow instead of clipping.
                foreach (array('lkp_ori', 'efs_ori', 'selisih_ori', 'lkp_eqIDR', 'efs_eqIDR', 'selisih_eqIDR') as $field) {
                    if ($row[$field] !== NULL && (!preg_match('/^-?[0-9]+\.[0-9]{2}$/D', $row[$field]) || strlen(ltrim(explode('.', $row[$field])[0], '-0')) > 63))
                        throw new Exception('Nominal melebihi kapasitas database.');
                }
                foreach (array('branch', 'coaF1', 'currency') as $field)
                    if (strlen($row[$field]) > 255) throw new Exception('Kode identitas terlalu panjang untuk database.');
                $row['data_date'] = $date;
                $row['row_number'] = ++$number;
                $batch[] = $row;
                if (count($batch) === 250) {
                    if ($this->db->insert_batch('reconciliation_history', $batch) === FALSE) throw new Exception('Gagal menyimpan baris riwayat.');
                    $batch = array();
                }
            }
            if ($number === 0) throw new Exception('Tidak ada baris hasil untuk disimpan.');
            if ($batch && $this->db->insert_batch('reconciliation_history', $batch) === FALSE) throw new Exception('Gagal menyimpan baris riwayat.');
            if (!$this->db->trans_status() || !$this->db->trans_commit()) throw new Exception('Penyimpanan riwayat gagal.');
            return $number;
        } catch (Throwable $e) {
            $this->db->trans_rollback();
            throw $e;
        } finally {
            if (is_resource($handle)) fclose($handle);
            $this->db->query("SELECT RELEASE_LOCK(CONCAT(DATABASE(), ':reconciliation_history'))");
        }
    }

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
