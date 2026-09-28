<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Excel_exporter
{
    private $handle;
    private $headers = array(
        'PERIOD_NUM', 'rincianAkun_tb', 'rincianAkun', 'namaCOA', 'penjelasanCOA',
        'coaF1', 'f1', 'f2', 'f3', 'f4', 'f5', 'f8', 'BASE_AMOUNT', 'f7', 'selisih'
    );

    public function start()
    {
        // Menggunakan stream php://temp yang otomatis membuat file sementara di disk jika data besar (sangat hemat RAM)
        $this->handle = fopen('php://temp', 'w+');
        // Tambahkan BOM agar bisa dibaca Excel dengan baik
        fwrite($this->handle, "\xEF\xBB\xBF");
        // Tulis header
        fputcsv($this->handle, $this->headers, ';'); // Menggunakan titik koma agar otomatis menjadi kolom di Excel bahasa Indonesia/Eropa, ubah ke ',' jika perlu
    }

    public function add_row($row)
    {
        $values = array(
            $row['PERIOD_NUM'], $row['rincianAkun_tb'], $row['rincianAkun'], $row['namaCOA'], $row['penjelasanCOA'],
            $row['coaF1'], $row['f1'], $row['f2'], $row['f3'], $row['f4'], $row['f5'], $row['f8'],
            $row['BASE_AMOUNT'], $row['f7'], $row['selisih']
        );
        
        fputcsv($this->handle, $values, ';');
    }

    public function save($path)
    {
        rewind($this->handle);
        $out = fopen($path, 'w');
        stream_copy_to_stream($this->handle, $out);
        fclose($out);
        fclose($this->handle);
    }
}
