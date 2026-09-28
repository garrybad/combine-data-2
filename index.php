<?php

define('ENVIRONMENT', 'development');

$system_path = 'vendor/codeigniter/framework/system';

if (($_SERVER['HTTP_HOST'] ?? '') !== '') {
    $base_path = __DIR__;
    $system_path = realpath($base_path . '/' . $system_path);
}

if ($system_path === FALSE) {
    exit('CodeIgniter belum terpasang. Jalankan composer install terlebih dahulu.');
}

$application_folder = 'application';
$view_folder = '';

if (defined('STDIN')) {
    chdir(__DIR__);
}

if (($_temp = realpath($system_path)) !== FALSE) {
    $system_path = $_temp . DIRECTORY_SEPARATOR;
} else {
    $system_path = rtrim($system_path, '/\\') . DIRECTORY_SEPARATOR;
}

define('SELF', pathinfo(__FILE__, PATHINFO_BASENAME));
define('BASEPATH', $system_path);
define('FCPATH', __DIR__ . DIRECTORY_SEPARATOR);
require_once FCPATH . 'application/config/env.php';
define('SYSDIR', basename(BASEPATH));

define('APPPATH', FCPATH . trim($application_folder, '/\\') . DIRECTORY_SEPARATOR);

if (is_dir($view_folder)) {
    define('VIEWPATH', $view_folder . DIRECTORY_SEPARATOR);
} elseif (is_dir(APPPATH . 'views' . DIRECTORY_SEPARATOR)) {
    define('VIEWPATH', APPPATH . 'views' . DIRECTORY_SEPARATOR);
} else {
    exit('View directory tidak ditemukan.');
}

require_once BASEPATH . 'core/CodeIgniter.php';
