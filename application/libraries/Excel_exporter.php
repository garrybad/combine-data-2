<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Excel_exporter
{
    private $handle;
    private $buffer;
    private $format;
    private $temp_path;
    private $row_number = 0;
    private $headers = array(
        'branch', 'coaF1', 'currency', 'efs_rincianAkun',
        'lkp_ori', 'efs_ori', 'selisih_ori', 'lkp_eqIDR', 'efs_eqIDR', 'selisih_eqIDR'
    );

    public function start($format = 'csv')
    {
        if (!in_array($format, array('csv', 'xlsx'), TRUE)) throw new Exception('Format output tidak valid.');
        $this->format = $format;
        $this->row_number = 0;
        if ($format === 'xlsx' && !class_exists('ZipArchive')) throw new Exception('Ekstensi PHP zip diperlukan untuk ekspor XLSX.');
        $this->cleanup();
        $directory = dirname(__DIR__, 2) . '/outputs';
        if (!is_dir($directory) || !is_writable($directory)) throw new Exception('Folder outputs tidak dapat ditulis.');
        // Explicit disk file: never spill php://temp into the system temp directory.
        $this->temp_path = $directory . '/reconciliation-temp-' . bin2hex(random_bytes(16));
        $this->handle = fopen($this->temp_path, 'x+b');
        if (!$this->handle) throw new Exception('File sementara di folder outputs tidak dapat dibuat.');
        // Bound memory to about 1 MB and batch small row writes to disk.
        $this->buffer = fopen('php://memory', 'w+b');
        if (!$this->buffer) throw new Exception('Buffer hasil tidak dapat dibuat.');
        if ($format === 'xlsx') {
            $this->write('<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols><col min="1" max="3" width="18" customWidth="1"/><col min="4" max="4" width="35" customWidth="1"/><col min="5" max="10" width="22" customWidth="1"/></cols><sheetData>');
            $this->write_xlsx_row($this->headers, TRUE);
        } else {
            $this->write("\xEF\xBB\xBF");
            $this->write_csv($this->headers);
        }
    }

    public function add_row($row)
    {
        $values = array();
        foreach ($this->headers as $header) $values[] = $row[$header];
        if ($this->format === 'xlsx') $this->write_xlsx_row($values);
        else $this->write_csv($values);
    }

    private function write($text)
    {
        if (!$this->buffer || fwrite($this->buffer, $text) !== strlen($text)) throw new Exception('Gagal menulis file hasil.');
        if (ftell($this->buffer) >= 1048576) $this->flush_buffer();
    }

    private function write_csv($values)
    {
        if (fputcsv($this->buffer, $values, ';', '"', '\\') === FALSE) throw new Exception('Gagal menulis CSV.');
        if (ftell($this->buffer) >= 1048576) $this->flush_buffer();
    }

    private function flush_buffer()
    {
        $length = ftell($this->buffer);
        rewind($this->buffer);
        if (stream_copy_to_stream($this->buffer, $this->handle) !== $length) throw new Exception('Gagal menyimpan buffer hasil.');
        if (!ftruncate($this->buffer, 0)) throw new Exception('Gagal mengosongkan buffer hasil.');
        rewind($this->buffer);
    }

    private function write_xlsx_row($values, $header = FALSE)
    {
        if (++$this->row_number > 1048576) throw new Exception('Hasil melebihi batas baris XLSX. Pilih format CSV.');
        $xml = '<row r="' . $this->row_number . '">';
        foreach ($values as $index => $value) {
            if ($value === NULL) continue;
            $reference = chr(65 + $index) . $this->row_number;
            if (!$header && $index >= 4) {
                $xml .= '<c r="' . $reference . '" s="2"><v>' . htmlspecialchars($value, ENT_XML1, 'UTF-8') . '</v></c>';
            } else {
                // Explicit strings preserve leading zeroes and never become Excel formulas.
                $value = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', (string) $value);
                $xml .= '<c r="' . $reference . '" t="inlineStr" s="' . ($header ? '1' : '0') . '"><is><t xml:space="preserve">' . htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8') . '</t></is></c>';
            }
        }
        $this->write($xml . '</row>');
    }

    public function save($path)
    {
        if ($this->format === 'csv') {
            $this->flush_buffer();
            rewind($this->handle);
            $out = fopen($path, 'wb');
            if (!$out) throw new Exception('File hasil tidak dapat dibuka.');
            try {
                if (stream_copy_to_stream($this->handle, $out) === FALSE) throw new Exception('Gagal menyimpan CSV.');
            } finally { fclose($out); }
        } else {
            $this->write('</sheetData><autoFilter ref="A1:J' . $this->row_number . '"/></worksheet>');
            $this->flush_buffer();
            fflush($this->handle);
            $zip = new ZipArchive();
            if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== TRUE) throw new Exception('Gagal membuat XLSX.');
            $files = array(
                '[Content_Types].xml' => '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>',
                '_rels/.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>',
                'xl/workbook.xml' => '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Rekonsiliasi" sheetId="1" r:id="rId1"/></sheets></workbook>',
                'xl/_rels/workbook.xml.rels' => '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>',
                'xl/styles.xml' => '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="3"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/><xf numFmtId="4" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>'
            );
            foreach ($files as $name => $xml) {
                if (!$zip->addFromString($name, '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>' . $xml)) throw new Exception('Gagal menulis komponen XLSX.');
            }
            if (!$zip->addFile($this->temp_path, 'xl/worksheets/sheet1.xml')) throw new Exception('Gagal menyimpan XLSX.');
            // Large worksheet XML dominates packaging time. Fast DEFLATE keeps
            // the same worksheet contents with a modest increase in file size.
            if (!$zip->setCompressionName('xl/worksheets/sheet1.xml', ZipArchive::CM_DEFLATE, 1)) {
                throw new Exception('Gagal mengatur kompresi XLSX.');
            }
            if (!$zip->close()) throw new Exception('Gagal menyimpan XLSX.');
        }
        $this->cleanup();
    }

    private function cleanup()
    {
        if (is_resource($this->buffer)) fclose($this->buffer);
        if (is_resource($this->handle)) fclose($this->handle);
        if ($this->temp_path && is_file($this->temp_path)) unlink($this->temp_path);
        $this->temp_path = NULL;
    }

    public function __destruct() { $this->cleanup(); }
}
