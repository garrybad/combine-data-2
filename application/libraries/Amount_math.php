<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class Amount_math
{
    // Accumulators start at integer 0 and store cents, never floating point.
    // Amounts outside the integer range fall back to decimal strings.
    public function accumulate($total, $value)
    {
        // Common amounts (up to two decimals) can become integer cents directly.
        // Keep the general decimal parser for larger values and extra precision.
        $value = trim((string) $value);
        if ($value === '') return $total;
        if (is_int($total) && preg_match('/^[+-]?[0-9]+(?:\.[0-9]{1,2})?$/D', $value)) {
            $point = strpos($value, '.');
            $digits = $point === FALSE ? $value . '00'
                : str_replace('.', '', $value) . (strlen($value) - $point === 2 ? '0' : '');
            $safe_digits = PHP_INT_SIZE >= 8 ? 18 : 9;
            if (strlen(ltrim($digits, '+-0')) <= $safe_digits) {
                $cents = (int) $digits;
                if (abs($total) <= PHP_INT_MAX - abs($cents)) return $total + $cents;
            }
        }
        return $this->sum($this->format_accumulator($total), $value);
    }

    public function format_accumulator($total)
    {
        if (!is_int($total)) return $total;
        $digits = str_pad((string) abs($total), 3, '0', STR_PAD_LEFT);
        return ($total < 0 ? '-' : '') . substr($digits, 0, -2) . '.' . substr($digits, -2);
    }

    public function subtract_accumulators($left, $right)
    {
        if (is_int($left) && is_int($right) && abs($left) <= PHP_INT_MAX - abs($right)) {
            return $this->format_accumulator($left - $right);
        }
        return $this->subtract($this->format_accumulator($left), $this->format_accumulator($right));
    }

    public function sum($left, $right)
    {
        $a = $this->parse($left);
        $b = $this->parse($right);
        if ($a === NULL || $b === NULL) throw new Exception("Nilai amount tidak valid: '$left', '$right'.");
        return $this->format($this->add($a, $b));
    }

    public function subtract($base, $f7)
    {
        $a = $this->parse($base);
        $b = $this->parse($f7);
        if ($a === NULL || $b === NULL) throw new Exception("Nilai amount tidak valid: left='$base', right='$f7'.");
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
        // Conservative digit bounds keep both operands and their sum in integers.
        // Larger amounts retain the arbitrary-precision string implementation.
        $safe_digits = PHP_INT_SIZE >= 8 ? 18 : 9;
        if (strlen($a['digits']) <= $safe_digits && strlen($b['digits']) <= $safe_digits) {
            $left = (int) $a['digits'];
            $right = (int) $b['digits'];
            $total = ($a['negative'] ? -$left : $left) + ($b['negative'] ? -$right : $right);
            return array('negative' => $total < 0, 'digits' => (string) abs($total));
        }
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
        return strcmp($a, $b);
    }

    private function format($n)
    {
        $digits = str_pad($n['digits'], 3, '0', STR_PAD_LEFT);
        $int = substr($digits, 0, -2); $dec = substr($digits, -2);
        $int = ltrim($int, '0') ?: '0';
        return ($n['negative'] ? '-' : '') . $int . '.' . $dec;
    }
}
