<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Reconciliation_parser
{
    const EFS_SAMPLE_ROWS = 200;

    // Normalized header names (uppercase, alphanumeric only) per EFS field.
    private $efs_aliases = array(
        'segments' => array('CONCATENATEDSEGMENTS', 'SEGMENTS', 'CODECOMBINATION', 'ACCOUNTCOMBINATION', 'KOMBINASIAKUN'),
        'branch' => array('ENTITYCODE', 'ENTITY', 'SEGMENT1', 'BRANCH', 'BRANCHCODE', 'KODECABANG', 'CABANG', 'KDCAB', 'COMPANYCODE'),
        'rincian' => array('NATURALACCOUNT', 'ACCOUNT', 'ACCOUNTCODE', 'SEGMENT3', 'RINCIANAKUN', 'AKUN', 'NOAKUN', 'GLACCOUNT'),
        'currency' => array('CURRENCYCODE', 'CURRENCY', 'CCY', 'MATAUANG', 'VALUTA'),
        'period' => array('PERIODNUM', 'PERIODNAME', 'PERIOD', 'PERIODE'),
        'ori' => array('AMOUNT', 'ENDINGBALANCE', 'BALANCE', 'ORIGINALAMOUNT', 'AMOUNTORI', 'AMOUNTORIGINAL', 'ORIAMOUNT',
            'ENTEREDAMOUNT', 'ENTEREDBALANCE', 'SALDO', 'SALDOAKHIR', 'NOMINAL'),
        'idr' => array('BASEAMOUNT', 'ACCOUNTEDAMOUNT', 'ACCOUNTEDBALANCE', 'AMOUNTIDR', 'IDRAMOUNT', 'EQIDR', 'AMOUNTEQIDR',
            'EQUIVALENTIDR', 'BASEBALANCE', 'ENDINGBALANCEIDR', 'BALANCEIDR', 'SALDOIDR', 'NOMINALIDR')
    );

    private $efs_labels = array(
        'segments' => 'Kombinasi segmen', 'branch' => 'Cabang', 'rincian' => 'Rincian akun', 'currency' => 'Mata uang',
        'period' => 'Periode', 'ori' => 'Amount original', 'idr' => 'Amount IDR'
    );

    private $months = array(
        'JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'MEI' => 5, 'JUN' => 6, 'JUL' => 7,
        'AUG' => 8, 'AGU' => 8, 'AGS' => 8, 'AGT' => 8, 'SEP' => 9, 'OCT' => 10, 'OKT' => 10, 'NOV' => 11, 'DEC' => 12, 'DES' => 12
    );

    public function parse_delimited_line($line, $delimiter, $trim_fields = TRUE)
    {
        // Most source rows have no quoted fields; avoid a PHP loop per byte.
        if (strpos($line, '"') === FALSE) {
            $fields = explode($delimiter, $line);
            return $trim_fields ? array_map('trim', $fields) : $fields;
        }
        // Fully quoted exports are common. Split at field boundaries in native
        // code; retain the general parser for embedded delimiters/escaped quotes.
        if (substr($line, 0, 1) === '"' && substr($line, -1) === '"') {
            $fields = explode('"' . $delimiter . '"', substr($line, 1, -1));
            if (strpos(implode('', $fields), '"') === FALSE) {
                return $trim_fields ? array_map('trim', $fields) : $fields;
            }
        }
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
                $values[] = $trim_fields ? trim($current) : $current;
                $current = '';
                continue;
            }
            $current .= $char;
        }
        $values[] = $trim_fields ? trim($current) : $current;
        return $values;
    }

    public function normalize_line($line)
    {
        $line = rtrim($line, "\r\n");
        return strncmp($line, "\xEF\xBB\xBF", 3) === 0 ? substr($line, 3) : $line;
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

    /**
     * Detects the EFS layout (XLSX or delimited text) from header names and
     * sampled cell contents. The result is passed to parse_efs_file().
     */
    public function detect_efs_layout($path)
    {
        $errors = array();
        foreach ($this->efs_sources($path) as $source) {
            $records = 0;
            $header_record = NULL;
            $headers = NULL;
            $samples = array();
            $this->read_efs_records($path, $source, function ($cells) use (&$records, &$header_record, &$headers, &$samples) {
                $records++;
                if ($headers === NULL) {
                    // Title or blank rows can precede the table header.
                    if (count(array_filter($cells, 'strlen')) < 2) return TRUE;
                    $header_record = $records;
                    if ($this->looks_like_header($cells)) {
                        $headers = array_map('trim', $cells);
                        return TRUE;
                    }
                    $headers = array();
                }
                if (count(array_filter($cells, 'strlen')) > 0) $samples[] = $cells;
                return count($samples) < self::EFS_SAMPLE_ROWS;
            });
            if ($headers === NULL || !$samples) {
                $errors[] = $this->source_label($source) . ': tidak ada baris data.';
                continue;
            }
            try {
                $columns = $this->detect_efs_columns($headers, $samples);
            } catch (Exception $e) {
                $errors[] = $this->source_label($source) . ': ' . $e->getMessage();
                continue;
            }
            $width = $headers ? count($headers) : count($samples[0]);
            $names = array();
            foreach ($columns as $role => $index)
                $names[$role] = isset($headers[$index]) && $headers[$index] !== '' ? $headers[$index] : 'Kolom ' . ($index + 1);
            return array(
                'source' => $source,
                'skipRecords' => $header_record - ($headers ? 0 : 1),
                'width' => $width,
                'columns' => $columns,
                'names' => $names,
                'hasIdr' => isset($columns['idr'])
            );
        }
        throw new Exception('Format file EFS tidak dikenali. ' . implode(' ', $errors));
    }

    public function parse_efs_file($path, $layout, callable $on_row)
    {
        $columns = $layout['columns'];
        $source = $layout['source'];
        $records = 0;
        $width = $layout['width'];
        $label = $this->source_label($source);
        $this->read_efs_records($path, $source, function ($cells, $line_no) use (&$records, $layout, $columns, $width, $label, $source, $on_row) {
            if (++$records <= $layout['skipRecords']) return TRUE;
            if ($source['type'] === 'delimited') {
                if (count($cells) === 1 && trim($cells[0]) === '') return TRUE;
                if (count($cells) !== $width) {
                    throw new Exception('Format file EFS tidak valid pada baris ' . $line_no . '. Ditemukan ' . count($cells) . ' kolom, seharusnya ' . $width . ' kolom.');
                }
            }
            $value = function ($role) use ($cells, $columns) {
                if (!isset($columns[$role])) return NULL;
                return isset($cells[$columns[$role]]) ? trim($cells[$columns[$role]]) : '';
            };
            if (isset($columns['segments']) && !isset($columns['branch'], $columns['rincian'])) {
                $segments = explode('-', $value('segments'));
                $branch = trim($segments[0]);
                $rincian = trim($segments[min(2, count($segments) - 1)]);
            } else {
                $branch = $value('branch');
                $rincian = $value('rincian');
            }
            // Skip blank and footer rows such as totals without account identity.
            if ($branch === '' && $rincian === '') return TRUE;
            $on_row(array(
                'branch' => $branch,
                'rincian' => $rincian,
                'currency' => $value('currency'),
                'period' => $value('period'),
                'ori' => $this->normalize_amount($value('ori'), $label, $line_no),
                'idr' => isset($columns['idr']) ? $this->normalize_amount($value('idr'), $label, $line_no) : NULL
            ), $line_no);
            return TRUE;
        });
    }

    /** Returns array('month' => 1..13, 'year' => int|NULL) or NULL. */
    public function parse_period($value)
    {
        $value = strtoupper(trim((string) $value));
        if (preg_match('/^(\d{1,2})(?:\.0+)?$/D', $value, $m)) {
            $month = (int) $m[1];
            return $month >= 1 && $month <= 13 ? array('month' => $month, 'year' => NULL) : NULL;
        }
        if (preg_match('/^([A-Z]{3})[A-Z]*[\s\-\/.]*(\d{2}|\d{4})$/D', $value, $m) && isset($this->months[$m[1]]))
            return array('month' => $this->months[$m[1]], 'year' => strlen($m[2]) === 2 ? 2000 + (int) $m[2] : (int) $m[2]);
        if (preg_match('/^(\d{4})[\-\/.]?(\d{2})$/D', $value, $m) && (int) $m[2] >= 1 && (int) $m[2] <= 12)
            return array('month' => (int) $m[2], 'year' => (int) $m[1]);
        if (preg_match('/^(\d{1,2})[\-\/.](\d{4})$/D', $value, $m) && (int) $m[1] >= 1 && (int) $m[1] <= 12)
            return array('month' => (int) $m[1], 'year' => (int) $m[2]);
        return NULL;
    }

    private function detect_efs_columns($headers, $samples)
    {
        $width = count($headers);
        foreach ($samples as $cells) $width = max($width, count($cells));
        $profiles = array();
        for ($i = 0; $i < $width; $i++) {
            $values = array();
            foreach ($samples as $cells) {
                $value = isset($cells[$i]) ? trim($cells[$i]) : '';
                if ($value !== '') $values[] = $value;
            }
            $profiles[$i] = $this->profile_column($values);
        }

        $columns = array();
        $taken = array();
        $assign = function ($role, $index) use (&$columns, &$taken) {
            if (isset($columns[$role])) unset($taken[$columns[$role]]);
            $columns[$role] = $index;
            $taken[$index] = TRUE;
        };
        $normalized = array();
        foreach ($headers as $index => $header)
            $normalized[$index] = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $header));

        // Exact header names first. A later duplicate overrides an earlier one.
        foreach ($normalized as $index => $name) {
            foreach ($this->efs_aliases as $role => $aliases) {
                if (in_array($name, $aliases, TRUE) && $this->content_fits($role, $profiles[$index]))
                    $assign($role, $index);
            }
        }
        // Partial header names, still confirmed by content.
        $hints = array(
            'idr' => '/IDR|BASE|ACCOUNTED|EQUIV|EKUIVALEN/', 'currency' => '/CURR|CCY|VALUTA/', 'period' => '/PERIOD/',
            'branch' => '/BRANCH|CABANG|ENTITY|COMPANY/', 'rincian' => '/ACCOUNT|AKUN/', 'ori' => '/AMOUNT|BALANCE|SALDO|NOMINAL/'
        );
        foreach ($hints as $role => $pattern) {
            if (isset($columns[$role])) continue;
            foreach ($normalized as $index => $name) {
                if (isset($taken[$index]) || !preg_match($pattern, $name) || preg_match('/NAME|NAMA|DESC/', $name)) continue;
                if (!$this->content_fits($role, $profiles[$index], TRUE)) continue;
                $assign($role, $index);
                break;
            }
        }

        // Content only, for unknown or missing header names.
        $has_identity = function () use (&$columns) {
            return isset($columns['segments']) || isset($columns['branch'], $columns['rincian']);
        };
        if (!$has_identity()) {
            foreach ($profiles as $index => $profile) {
                if (!isset($taken[$index]) && $profile['n'] && $profile['segments'] >= 0.95) {
                    $assign('segments', $index);
                    break;
                }
            }
        }
        if (!$has_identity()) {
            $codes = array();
            foreach ($profiles as $index => $profile)
                if (!isset($taken[$index]) && $profile['codeLength'] >= 3) $codes[$index] = $profile['codeLength'];
            asort($codes);
            if (!isset($columns['branch'])) {
                foreach ($codes as $index => $length) {
                    if ($length > 5) break;
                    $assign('branch', $index);
                    unset($codes[$index]);
                    break;
                }
            }
            if (!isset($columns['rincian'])) {
                foreach (array_reverse($codes, TRUE) as $index => $length) {
                    if ($length >= 6 && !isset($taken[$index])) $assign('rincian', $index);
                    break;
                }
            }
        }
        foreach (array('currency' => 'currency', 'period' => 'periodName') as $role => $metric) {
            if (isset($columns[$role])) continue;
            foreach ($profiles as $index => $profile) {
                if (!isset($taken[$index]) && $profile['n'] && $profile[$metric] >= 0.95) {
                    $assign($role, $index);
                    break;
                }
            }
        }
        if (!isset($columns['ori'])) {
            $candidates = array();
            foreach ($profiles as $index => $profile)
                if (!isset($taken[$index]) && $profile['n'] && $profile['numeric'] >= 0.95 && $profile['codeLength'] < 3) $candidates[] = $index;
            if (isset($columns['idr'])) {
                if (count($candidates) === 1) $assign('ori', $candidates[0]);
            } elseif (count($candidates) === 1) {
                $assign('ori', $candidates[0]);
            } elseif (count($candidates) === 2) {
                $assign('ori', $candidates[0]);
                $assign('idr', $candidates[1]);
            } elseif (count($candidates) > 2) {
                $names = array();
                foreach ($candidates as $index) $names[] = isset($headers[$index]) && $headers[$index] !== '' ? $headers[$index] : 'Kolom ' . ($index + 1);
                throw new Exception('Kolom amount ambigu: ' . implode(', ', $names) . '. Beri nama header amount original dan amount IDR.');
            }
        }

        $missing = array();
        if (!$has_identity()) {
            if (!isset($columns['branch'])) $missing[] = $this->efs_labels['branch'];
            if (!isset($columns['rincian'])) $missing[] = $this->efs_labels['rincian'];
        }
        foreach (array('currency', 'ori') as $role)
            if (!isset($columns[$role])) $missing[] = $this->efs_labels[$role];
        if ($missing) {
            throw new Exception('Kolom ' . implode(', ', $missing) . ' tidak ditemukan' .
                ($headers ? ' (header: ' . implode(', ', array_filter($headers, 'strlen')) . ').' : '.'));
        }
        if (isset($columns['segments']) && isset($columns['branch'], $columns['rincian'])) unset($columns['segments']);
        ksort($columns);
        return $columns;
    }

    private function profile_column($values)
    {
        $profile = array('n' => count($values), 'numeric' => 0, 'segments' => 0, 'currency' => 0, 'periodName' => 0, 'periodNum' => 0, 'codeLength' => 0);
        if (!$values) return $profile;
        $length = NULL;
        foreach ($values as $value) {
            if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $value)) $profile['numeric']++;
            if (preg_match('/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+){2,}$/D', $value) && !preg_match('/^\d{4}-\d{2}-\d{2}$|^\d{2}-\d{2}-\d{4}$/D', $value)) $profile['segments']++;
            if (preg_match('/^[A-Za-z]{3}$/D', $value)) $profile['currency']++;
            $period = $this->parse_period($value);
            if ($period !== NULL) {
                if ($period['year'] !== NULL) $profile['periodName']++;
                else $profile['periodNum']++;
            }
            if ($length !== FALSE) $length = ctype_digit($value) && ($length === NULL || $length === strlen($value)) ? strlen($value) : FALSE;
        }
        foreach (array('numeric', 'segments', 'currency', 'periodName', 'periodNum') as $metric)
            $profile[$metric] /= $profile['n'];
        $profile['codeLength'] = $length ?: 0;
        return $profile;
    }

    private function content_fits($role, $profile, $require_values = FALSE)
    {
        if (!$profile['n']) return !$require_values;
        switch ($role) {
            case 'segments': return $profile['segments'] >= 0.95;
            case 'currency': return $profile['currency'] >= 0.95;
            case 'period': return $profile['periodName'] + $profile['periodNum'] >= 0.95;
            case 'ori':
            case 'idr': return $profile['numeric'] >= 0.95;
            default: return TRUE;
        }
    }

    private function looks_like_header($cells)
    {
        foreach ($cells as $cell) {
            $cell = trim($cell);
            if ($cell === '') continue;
            if (preg_match('/^[+-]?(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?$/D', $cell)) return FALSE;
            if (preg_match('/^[A-Za-z0-9]+(?:-[A-Za-z0-9]+){2,}$/D', $cell) && preg_match('/\d/', $cell)) return FALSE;
        }
        return TRUE;
    }

    private function normalize_amount($value, $label, $line_no)
    {
        if ($value === NULL || $value === '' || preg_match('/^[+-]?\d+(?:\.\d+)?$/D', $value)) return $value;
        if (is_numeric($value)) return $this->excel_number($value);
        throw new Exception("Nilai amount tidak valid pada file EFS ($label) baris $line_no: '$value'.");
    }

    private function excel_number($value)
    {
        if (!is_numeric($value)) return $value;
        if (preg_match('/^-?\d{1,15}$/D', $value)) return $value;
        // Excel stores binary doubles (e.g. 329.3999 for 329.40) but keeps
        // 15 significant digits; round to that before decimal truncation.
        $number = (float) $value;
        $text = sprintf('%.15G', $number);
        return stripos($text, 'E') === FALSE ? $text : number_format($number, 2, '.', '');
    }

    private function source_label($source)
    {
        return $source['type'] === 'xlsx' ? "sheet '" . $source['name'] . "'" : 'file teks';
    }

    private function efs_sources($path)
    {
        $handle = fopen($path, 'rb');
        if (!$handle) throw new Exception('File EFS tidak dapat dibuka.');
        $signature = fread($handle, 4);
        fclose($handle);
        if ($signature !== "PK\x03\x04") return array(array('type' => 'delimited', 'delimiter' => NULL));
        if (!class_exists('ZipArchive') || !class_exists('XMLReader')) throw new Exception('Ekstensi PHP zip dan xmlreader diperlukan untuk membaca XLSX.');
        $zip = new ZipArchive();
        if ($zip->open($path) !== TRUE) throw new Exception('File EFS XLSX tidak dapat dibuka.');
        try {
            $workbook = $zip->getFromName('xl/workbook.xml');
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
            if ($workbook === FALSE || $rels === FALSE) throw new Exception('File EFS bukan XLSX yang valid.');
            $targets = array();
            $rel_xml = simplexml_load_string($rels, 'SimpleXMLElement', LIBXML_NONET);
            foreach ($rel_xml->Relationship as $rel) {
                $target = (string) $rel['Target'];
                $targets[(string) $rel['Id']] = substr($target, 0, 1) === '/' ? ltrim($target, '/') : 'xl/' . $target;
            }
            $book = simplexml_load_string($workbook, 'SimpleXMLElement', LIBXML_NONET);
            $sources = array();
            $has_shared = $zip->locateName('xl/sharedStrings.xml') !== FALSE;
            foreach ($book->sheets->sheet as $sheet) {
                $id = (string) $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')->id;
                if (!isset($targets[$id]) || $zip->locateName($targets[$id]) === FALSE) continue;
                $sources[] = array('type' => 'xlsx', 'name' => (string) $sheet['name'], 'entry' => $targets[$id], 'sharedStrings' => $has_shared);
            }
        } finally {
            $zip->close();
        }
        if (!$sources) throw new Exception('File EFS XLSX tidak memiliki sheet.');
        return $sources;
    }

    /** Calls $on_record($cells, $line_no) until it returns FALSE. */
    private function read_efs_records($path, $source, callable $on_record)
    {
        if ($source['type'] === 'xlsx') {
            $this->read_xlsx_records($path, $source, $on_record);
            return;
        }
        $handle = fopen($path, 'rb');
        if (!$handle) throw new Exception('File EFS tidak dapat dibuka.');
        try {
            $delimiter = $source['delimiter'];
            $line_no = 0;
            while (($line = fgets($handle)) !== FALSE) {
                $line_no++;
                $line = $this->normalize_line($line);
                if ($delimiter === NULL) {
                    if (trim($line) === '') continue;
                    $delimiter = $this->detect_delimiter($line);
                }
                // Only selected fields are trimmed later; wide files have many unused columns.
                if ($on_record($this->parse_delimited_line($line, $delimiter, FALSE), $line_no) === FALSE) break;
            }
        } finally {
            fclose($handle);
        }
    }

    private function detect_delimiter($line)
    {
        $best = "\t";
        $best_count = 0;
        foreach (array("\t", ';', ',', '|') as $delimiter) {
            $count = count($this->parse_delimited_line($line, $delimiter, FALSE));
            if ($count > $best_count) {
                $best = $delimiter;
                $best_count = $count;
            }
        }
        return $best;
    }

    private function read_xlsx_records($path, $source, callable $on_record)
    {
        $strings = $source['sharedStrings'] ? $this->read_shared_strings($path) : array();
        $reader = new XMLReader();
        if (!$reader->open('zip://' . $path . '#' . $source['entry'], NULL, LIBXML_NONET | LIBXML_COMPACT))
            throw new Exception("Sheet '" . $source['name'] . "' pada file EFS tidak dapat dibaca.");
        try {
            $row_no = 0;
            $ok = $reader->read();
            while ($ok) {
                if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                    $ok = $reader->read();
                    continue;
                }
                $row = $reader->expand();
                $row_no = $row->getAttribute('r') !== '' ? (int) $row->getAttribute('r') : $row_no + 1;
                $cells = array();
                $next = 0;
                foreach ($row->childNodes as $cell) {
                    if ($cell->nodeType !== XML_ELEMENT_NODE || $cell->localName !== 'c') continue;
                    $ref = $cell->getAttribute('r');
                    $index = $ref !== '' ? $this->column_index($ref) : $next;
                    $cells[$index] = $this->xlsx_cell_value($cell, $strings);
                    $next = $index + 1;
                }
                $values = $cells ? array_fill(0, max(array_keys($cells)) + 1, '') : array();
                foreach ($cells as $index => $value) $values[$index] = $value;
                if ($on_record($values, $row_no) === FALSE) break;
                $ok = $reader->next();
            }
        } finally {
            $reader->close();
        }
    }

    private function xlsx_cell_value($cell, $strings)
    {
        $type = $cell->getAttribute('t');
        $raw = NULL;
        foreach ($cell->childNodes as $child) {
            if ($child->nodeType !== XML_ELEMENT_NODE) continue;
            if ($child->localName === 'v') $raw = $child->textContent;
            elseif ($child->localName === 'is') return $this->rich_text($child);
        }
        if ($raw === NULL) return '';
        switch ($type) {
            case 's': return isset($strings[(int) $raw]) ? $strings[(int) $raw] : '';
            case 'str':
            case 'b': return $raw;
            case 'e': return '';
            default: return $this->excel_number($raw);
        }
    }

    private function rich_text($node)
    {
        $text = '';
        foreach ($node->getElementsByTagName('t') as $part)
            if ($part->parentNode->localName !== 'rPh') $text .= $part->textContent;
        return $text;
    }

    private function read_shared_strings($path)
    {
        $reader = new XMLReader();
        if (!$reader->open('zip://' . $path . '#xl/sharedStrings.xml', NULL, LIBXML_NONET | LIBXML_COMPACT))
            throw new Exception('Shared strings pada file EFS XLSX tidak dapat dibaca.');
        $strings = array();
        try {
            $ok = $reader->read();
            while ($ok) {
                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'si') {
                    $strings[] = $this->rich_text($reader->expand());
                    $ok = $reader->next();
                    continue;
                }
                $ok = $reader->read();
            }
        } finally {
            $reader->close();
        }
        return $strings;
    }

    private function column_index($reference)
    {
        $index = 0;
        $length = strlen($reference);
        for ($i = 0; $i < $length && ctype_alpha($reference[$i]); $i++)
            $index = $index * 26 + (ord(strtoupper($reference[$i])) - 64);
        return $index - 1;
    }
}
