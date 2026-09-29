<?php
defined('BASEPATH') OR exit('No direct script access allowed');

class MY_Security extends CI_Security
{
    public function csrf_set_cookie()
    {
        if (PHP_VERSION_ID >= 70300) return parent::csrf_set_cookie();

        $secure = (bool) config_item('cookie_secure');
        if ($secure && !is_https()) return FALSE;

        // CI 3.1.13 URL-encodes Path on PHP < 7.3. Cookie paths must retain '/'.
        $path = config_item('cookie_path');
        $domain = trim(config_item('cookie_domain'));
        if (!is_string($path) || substr($path, 0, 1) !== '/' || preg_match('/[;\r\n]/', $path . $domain)) {
            throw new RuntimeException('Konfigurasi path/domain cookie tidak valid.');
        }
        header('Set-Cookie: ' . $this->_csrf_cookie_name . '=' . $this->_csrf_hash
            . '; Expires=' . gmdate('D, d-M-Y H:i:s T', time() + $this->_csrf_expire)
            . '; Max-Age=' . $this->_csrf_expire
            . '; Path=' . $path
            . ($domain === '' ? '' : '; Domain=' . $domain)
            . ($secure ? '; Secure' : '')
            . (config_item('cookie_httponly') ? '; HttpOnly' : '')
            . '; SameSite=Strict', FALSE);
        return $this;
    }
}
