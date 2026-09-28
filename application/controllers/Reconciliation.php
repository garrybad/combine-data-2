<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Reconciliation extends CI_Controller
{
    public function __construct()
    {
        parent::__construct();
        $this->load->model('Reconciliation_model');
        $this->load->library(array('reconciliation_parser', 'amount_math', 'excel_exporter', 'reconciliation_service'));
    }

    public function index()
    {
        $this->load->view('reconciliation/index');
    }

    public function process()
    {
        if (!$this->input->is_ajax_request()) {
            $this->json_error('Request tidak valid.', 400);
            return;
        }

        $this->output->set_content_type('application/json');
        try {
            $this->validate_file('lkpFile', 'File LKP');
            $this->validate_file('tbFile', 'File Bulanan');

            $lkp = $this->store_upload('lkpFile', 'lkp');
            $tb = $this->store_upload('tbFile', 'tb');
            $output = FCPATH . 'outputs/hasil-rekonsiliasi-' . date('Ymd-His') . '-' . mt_rand(1000, 9999) . '.xlsx';

            if (!is_dir(dirname($output))) @mkdir(dirname($output), 0775, TRUE);
            if (!is_writable(dirname($output))) throw new Exception('Folder outputs tidak dapat ditulis.');

            try {
                $stats = $this->reconciliation_service->process($lkp['path'], $tb['path'], $output);
            } finally {
                @unlink($lkp['path']);
                @unlink($tb['path']);
            }

            if (!is_file($output)) throw new Exception('File hasil tidak berhasil dibuat.');

            $download_name = 'hasil-kombinasi.xlsx';
            $size = filesize($output);
            $this->output->set_header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $this->output->set_header('Content-Disposition: attachment; filename="' . $download_name . '"');
            $this->output->set_header('Content-Length: ' . $size);
            $this->output->set_header('Cache-Control: no-store');
            $this->output->set_header('X-Processing-Stats: ' . rawurlencode(json_encode($stats)));
            $this->output->set_output(file_get_contents($output));
            @unlink($output);
        } catch (Exception $e) {
            log_message('error', 'PROCESS_ERROR: ' . $e->getMessage());
            $this->json_error($e->getMessage(), 500);
        }
    }

    private function validate_file($field, $label)
    {
        if (!isset($_FILES[$field])) throw new Exception($label . ' wajib di-upload.');
        if ($_FILES[$field]['error'] !== UPLOAD_ERR_OK) {
            $map = array(
                UPLOAD_ERR_INI_SIZE => 'melebihi upload_max_filesize server',
                UPLOAD_ERR_FORM_SIZE => 'melebihi batas ukuran form',
                UPLOAD_ERR_PARTIAL => 'hanya ter-upload sebagian',
                UPLOAD_ERR_NO_FILE => 'tidak dipilih',
            );
            $reason = isset($map[$_FILES[$field]['error']]) ? $map[$_FILES[$field]['error']] : 'mengalami error upload';
            throw new Exception($label . ' ' . $reason . '.');
        }
        if ((int) $_FILES[$field]['size'] <= 0) throw new Exception($label . ' tidak boleh kosong.');
        if ((int) $_FILES[$field]['size'] > MAX_UPLOAD_BYTES) throw new Exception($label . ' tidak boleh lebih dari ' . round(MAX_UPLOAD_BYTES / 1024 / 1024) . ' MB.');
    }

    private function store_upload($field, $prefix)
    {
        $original = basename($_FILES[$field]['name']);
        $ext = pathinfo($original, PATHINFO_EXTENSION);
        $name = $prefix . '-' . date('Ymd-His') . '-' . mt_rand(100000, 999999) . ($ext ? '.' . preg_replace('/[^a-zA-Z0-9]/', '', $ext) : '.dat');
        $dir = FCPATH . 'uploads/';
        if (!is_dir($dir) && !@mkdir($dir, 0775, TRUE)) throw new Exception('Folder uploads tidak dapat dibuat.');
        $path = $dir . $name;
        if (!move_uploaded_file($_FILES[$field]['tmp_name'], $path)) throw new Exception('File ' . $original . ' gagal disimpan di server.');
        return array('path' => $path, 'name' => $original);
    }

    private function json_error($message, $status)
    {
        $this->output->set_status_header($status)->set_content_type('application/json')->set_output(json_encode(array('ok' => FALSE, 'message' => $message)));
    }
}
