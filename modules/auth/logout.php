<?php
/**
 * Modul Autentikasi: Logout
 */

require_once dirname(dirname(__DIR__)) . '/config/app.php';
require_once dirname(dirname(__DIR__)) . '/core/Session.php';
require_once dirname(dirname(__DIR__)) . '/core/Auth.php';

Session::start();
Auth::logout();

Session::start();
Session::setFlash('info', 'Anda telah berhasil keluar dari sistem ToKo.');
header('Location: index.php?page=home');
exit;
