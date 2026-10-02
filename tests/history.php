<?php
error_reporting(E_ALL & ~E_DEPRECATED);
define('BASEPATH', __DIR__);
class CI_Model { public $db; }
require __DIR__ . '/../application/models/Reconciliation_model.php';
require __DIR__ . '/../application/libraries/Reconciliation_date.php';
require __DIR__ . '/../application/libraries/Amount_math.php';
function verify($actual, $expected) {
    if ($actual !== $expected) throw new Exception(var_export(array($actual, $expected), TRUE));
}
class HistoryQuery {
    private $rows;
    public function __construct($rows = array()) { $this->rows = $rows; }
    public function row_array() { return $this->rows[0]; }
    public function result_array() { return $this->rows; }
}
class HistoryDb {
    public $rows = array();
    public $queries = array();
    public $failInsert = FALSE;
    private $snapshot;
    public function table_exists($name) { return TRUE; }
    public function trans_begin() { $this->snapshot = $this->rows; return TRUE; }
    public function trans_status() { return TRUE; }
    public function trans_commit() { return TRUE; }
    public function trans_rollback() { $this->rows = $this->snapshot; }
    public function insert_batch($table, $rows) {
        if ($this->failInsert) return FALSE;
        $this->rows = array_merge($this->rows, $rows);
        return count($rows);
    }
    public function query($sql, $bindings = array()) {
        $this->queries[] = $sql;
        if (strpos($sql, 'GET_LOCK') !== FALSE) return new HistoryQuery(array(array('acquired' => 1)));
        if (strpos($sql, 'DELETE FROM') === 0) {
            $this->rows = array_values(array_filter($this->rows, function ($row) use ($bindings) { return $row['data_date'] !== $bindings[0]; }));
        }
        return new HistoryQuery();
    }
}
$date = new Reconciliation_date();
foreach (array('20260630', '2026-06-30', '30/06/2026', '30-06-2026', '30062026') as $value)
    verify($date->from_source(array($value)), '2026-06-30');
foreach (array(array('20260230'), array('x'), array(), array('20260630', '20260701')) as $values) {
    $rejected = FALSE;
    try { $date->from_source($values); } catch (Exception $e) { $rejected = TRUE; }
    verify($rejected, TRUE);
}
$model = new Reconciliation_model();
$model->db = new HistoryDb();
$row = array('branch' => '0001', 'coaF1' => '00100', 'currency' => 'IDR', 'efs_rincianAkun' => '10,20',
    'lkp_ori' => '10.00', 'efs_ori' => '7.00', 'selisih_ori' => '3.00',
    'lkp_eqIDR' => '10.00', 'efs_eqIDR' => '7.00', 'selisih_eqIDR' => '3.00', 'is_rasionalisasi' => 0);
$path = tempnam(sys_get_temp_dir(), 'history-');
try {
    file_put_contents($path, json_encode($row) . "\n");
    verify($model->save_history('2026-06-30', $path), 1);
    verify($model->db->rows[0]['branch'], '0001');
    verify($model->db->rows[0]['data_date'], '2026-06-30');
    $rationalized = $row;
    $rationalized['selisih_ori'] = $rationalized['selisih_eqIDR'] = NULL;
    $rationalized['is_rasionalisasi'] = 1;
    file_put_contents($path, json_encode($rationalized) . "\n");
    verify($model->save_history('2026-06-30', $path), 1);
    verify(count($model->db->rows), 1);
    verify($model->db->rows[0]['selisih_eqIDR'], NULL);
    verify($model->save_history('2026-07-01', $path), 1);
    verify(count($model->db->rows), 2);
    $before = $model->db->rows;
    $model->db->failInsert = TRUE;
    $rejected = FALSE;
    try { $model->save_history('2026-06-30', $path); } catch (Exception $e) { $rejected = TRUE; }
    verify($rejected, TRUE);
    verify($model->db->rows, $before);
    $model->db->failInsert = FALSE;
    $row['lkp_ori'] = str_repeat('9', 64) . '.00';
    file_put_contents($path, json_encode($row) . "\n");
    $rejected = FALSE;
    try { $model->save_history('2026-06-30', $path); } catch (Exception $e) { $rejected = TRUE; }
    verify($rejected, TRUE);
    verify($model->db->rows, $before);
    echo "History checks passed\n";
} finally { unlink($path); }
