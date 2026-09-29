<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Reconciliation_parser
{
    public function parse_delimited_line($line, $delimiter)
    {
        $values = array();
        $current = '';
        $in_quotes = FALSE;
        $length = strlen($line);
        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];
            if ($char === '"') {
                if ($in_quotes && $i + 1 < $length && $line[$i + 1] === '"') {
                    $current .= '"';
                    $i++;
                } else {
                    $in_quotes = !$in_quotes;
                }
                continue;
            }
            if ($char === $delimiter && !$in_quotes) {
                $values[] = trim($current);
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $values[] = trim($current);
        return $values;
    }

    public function normalize_line($line)
    {
        $line = rtrim($line, "\r\n");
        return preg_replace('/^\xEF\xBB\xBF/', '', $line);
    }

    public function parse_lkp_handle($handle, callable $on_row)
    {
        $line_no = 0;
        while (($line = fgets($handle)) !== FALSE) {
            $line_no++;
            $line = $this->normalize_line($line);
            if (trim($line) === '') continue;
            $values = $this->parse_delimited_line($line, '|');
            if (count($values) !== 8) {
                throw new Exception('Format file LKP tidak valid pada baris ' . $line_no . '. Ditemukan ' . count($values) . ' kolom, seharusnya 8 kolom.');
            }
            $on_row(array(
                'f1' => $values[0], 'f2' => $values[1], 'f3' => $values[2],
                'f4' => $values[3], 'f5' => $values[4], 'f6' => $values[5], 'f7' => $values[6], 'f8' => $values[7]
            ), $line_no);
        }
    }

    public function parse_tb_handle($handle, callable $on_row)
    {
        $line_no = 0;
        $headers = NULL;
        $required = array('CONCATENATED_SEGMENTS', 'PERIOD_NUM', 'CURRENCY_CODE', 'AMOUNT', 'BASE_AMOUNT');

        while (($line = fgets($handle)) !== FALSE) {
            $line_no++;
            $line = $this->normalize_line($line);
            if (trim($line) === '') continue;

            if ($headers === NULL) {
                $headers = array_map(function ($v) { return trim($v); }, $this->parse_delimited_line($line, "\t"));
                foreach ($required as $required_header) {
                    if (!in_array($required_header, $headers, TRUE)) {
                        throw new Exception("Kolom wajib '$required_header' tidak ditemukan pada file TB Juni.");
                    }
                }
                continue;
            }

            $values = $this->parse_delimited_line($line, "\t");
            if (count($values) !== count($headers)) {
                throw new Exception('Format file TB Juni tidak valid pada baris ' . $line_no . '. Ditemukan ' . count($values) . ' kolom, seharusnya ' . count($headers) . ' kolom.');
            }
            $row = array();
            foreach ($headers as $index => $header) $row[$header] = isset($values[$index]) ? $values[$index] : '';
            $on_row($row, $line_no);
        }

        if ($headers === NULL) throw new Exception('File TB Juni kosong atau hanya memiliki header.');
    }

    public function extract_rincian_akun($concatenated_segments)
    {
        $parts = explode('-', $concatenated_segments);
        return trim($parts[min(2, count($parts) - 1)]);
    }
}
