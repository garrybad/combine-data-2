<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Reconciliation_date
{
    public function from_source($values)
    {
        $dates = array();
        foreach ($values as $value) {
            $value = trim((string) $value);
            $matched = FALSE;
            foreach (array('Ymd', 'Y-m-d', 'd/m/Y', 'd-m-Y', 'dmY') as $format) {
                $date = DateTime::createFromFormat('!' . $format, $value);
                if ($date && $date->format($format) === $value) {
                    $dates[$date->format('Y-m-d')] = TRUE;
                    $matched = TRUE;
                    break;
                }
            }
            if (!$matched) throw new Exception('Tanggal pada kolom f1 tidak valid: ' . $value . '. Gunakan YYYYMMDD atau DD/MM/YYYY.');
        }
        if (count($dates) !== 1) throw new Exception('Penyimpanan memerlukan satu tanggal yang sama pada seluruh kolom f1.');
        return key($dates);
    }

    /** Returns array('year' => int, 'month' => int) when every f1 date shares one month, otherwise NULL. */
    public function period_from_source($values)
    {
        $periods = array();
        foreach ($values as $value) {
            $value = trim((string) $value);
            $matched = FALSE;
            foreach (array('Ymd', 'Y-m-d', 'd/m/Y', 'd-m-Y', 'dmY') as $format) {
                $date = DateTime::createFromFormat('!' . $format, $value);
                if ($date && $date->format($format) === $value) {
                    $periods[$date->format('Y-m')] = array('year' => (int) $date->format('Y'), 'month' => (int) $date->format('n'));
                    $matched = TRUE;
                    break;
                }
            }
            if (!$matched) return NULL;
        }
        return count($periods) === 1 ? reset($periods) : NULL;
    }
}
