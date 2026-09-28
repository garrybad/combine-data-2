<?php
defined('BASEPATH') OR exit('No direct script access allowed');

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;

class Excel_exporter
{
    private $spreadsheet;
    private $sheet;
    private $row_number = 2;
    private $headers = array(
        'PERIOD_NUM', 'rincianAkun_tb', 'rincianAkun', 'namaCOA', 'penjelasanCOA',
        'coaF1', 'f1', 'f2', 'f3', 'f4', 'f5', 'f8', 'BASE_AMOUNT', 'f7', 'selisih'
    );

    public function start()
    {
        $this->spreadsheet = new Spreadsheet();
        $this->spreadsheet->getProperties()->setCreator('Reconciliation Automation')->setTitle('Hasil Rekonsiliasi');
        $this->sheet = $this->spreadsheet->getActiveSheet();
        $this->sheet->setTitle('Hasil');

        foreach ($this->headers as $index => $header) {
            $column = $this->column_letter($index + 1);
            $this->sheet->setCellValue($column . '1', $header);
            $this->sheet->getColumnDimension($column)->setWidth($header === 'penjelasanCOA' ? 40 : min(max(strlen($header) + 2, 14), 30));
        }
        $this->sheet->freezePane('A2');
        $this->sheet->setAutoFilter('A1:O1');
    }

    public function add_row($row)
    {
        $values = array(
            $row['PERIOD_NUM'], $row['rincianAkun_tb'], $row['rincianAkun'], $row['namaCOA'], $row['penjelasanCOA'],
            $row['coaF1'], $row['f1'], $row['f2'], $row['f3'], $row['f4'], $row['f5'], $row['f8'],
            $row['BASE_AMOUNT'], $row['f7'], $row['selisih']
        );
        foreach ($values as $index => $value) {
            $column = $this->column_letter($index + 1);
            $cell = $column . $this->row_number;
            if (in_array($index, array(12, 13, 14), TRUE) && is_numeric($value)) {
                $this->sheet->setCellValue($cell, (float) $value);
                $this->sheet->getStyle($cell)->getNumberFormat()->setFormatCode('#,##0.00');
            } else {
                $this->sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
            }
        }
        $this->row_number++;
    }

    public function save($path)
    {
        $writer = new Xlsx($this->spreadsheet);
        $writer->save($path);
        $this->spreadsheet->disconnectWorksheets();
        $this->spreadsheet = NULL;
        $this->sheet = NULL;
    }

    private function column_letter($number)
    {
        $letter = '';
        while ($number > 0) {
            $mod = ($number - 1) % 26;
            $letter = chr(65 + $mod) . $letter;
            $number = (int) (($number - $mod) / 26);
        }
        return $letter;
    }
}
