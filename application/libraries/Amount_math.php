<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Amount_math
{
    public function subtract($base, $f7)
    {
        $a = $this->parse($base);
        $b = $this->parse($f7);
        if ($a === NULL || $b === NULL) throw new Exception("Nilai amount tidak valid: BASE_AMOUNT='$base', f7='$f7'.");
        return $this->format($this->add($a, $this->negate($b)));
    }

    private function parse($value)
    {
        $value = trim((string) $value);
        if ($value === '') $value = '0';
        if (!preg_match('/^[+-]?(?:\d+)(?:\.\d+)?$/', $value)) return NULL;
        $negative = substr($value, 0, 1) === '-';
        if ($value[0] === '+' || $value[0] === '-') $value = substr($value, 1);
        $parts = explode('.', $value, 2);
        $int = ltrim($parts[0], '0');
        if ($int === '') $int = '0';
        $dec = isset($parts[1]) ? substr($parts[1] . '00', 0, 2) : '00';
        return array('negative' => $negative && $int !== '0' || ($negative && $dec !== '00'), 'digits' => ltrim($int . $dec, '0') ?: '0');
    }

    private function negate($n) { $n['negative'] = $n['digits'] !== '0' ? !$n['negative'] : FALSE; return $n; }

    private function add($a, $b)
    {
        if ($a['negative'] === $b['negative']) return array('negative' => $a['negative'], 'digits' => $this->add_int($a['digits'], $b['digits']));
        $cmp = $this->cmp_int($a['digits'], $b['digits']);
        if ($cmp === 0) return array('negative' => FALSE, 'digits' => '0');
        if ($cmp > 0) return array('negative' => $a['negative'], 'digits' => $this->sub_int($a['digits'], $b['digits']));
        return array('negative' => $b['negative'], 'digits' => $this->sub_int($b['digits'], $a['digits']));
    }

    private function add_int($a, $b)
    {
        $i = strlen($a) - 1; $j = strlen($b) - 1; $carry = 0; $out = '';
        while ($i >= 0 || $j >= 0 || $carry) {
            $sum = ($i >= 0 ? intval($a[$i--]) : 0) + ($j >= 0 ? intval($b[$j--]) : 0) + $carry;
            $out = ($sum % 10) . $out; $carry = intdiv($sum, 10);
        }
        return ltrim($out, '0') ?: '0';
    }

    private function sub_int($a, $b)
    {
        $i = strlen($a) - 1; $j = strlen($b) - 1; $borrow = 0; $out = '';
        while ($i >= 0) {
            $diff = intval($a[$i--]) - ($j >= 0 ? intval($b[$j--]) : 0) - $borrow;
            if ($diff < 0) { $diff += 10; $borrow = 1; } else $borrow = 0;
            $out = $diff . $out;
        }
        return ltrim($out, '0') ?: '0';
    }

    private function cmp_int($a, $b)
    {
        $a = ltrim($a, '0') ?: '0'; $b = ltrim($b, '0') ?: '0';
        if (strlen($a) !== strlen($b)) return strlen($a) > strlen($b) ? 1 : -1;
        return $a === $b ? 0 : ($a > $b ? 1 : -1);
    }

    private function format($n)
    {
        $digits = str_pad($n['digits'], 3, '0', STR_PAD_LEFT);
        $int = substr($digits, 0, -2); $dec = substr($digits, -2);
        $int = ltrim($int, '0') ?: '0';
        return ($n['negative'] ? '-' : '') . $int . '.' . $dec;
    }
}
