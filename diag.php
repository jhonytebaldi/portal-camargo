<?php
ini_set('display_errors','1'); error_reporting(E_ALL);
header('Content-Type: text/plain; charset=utf-8');
echo 'PHP '.PHP_VERSION.' | pdo_mysql='.(int)extension_loaded('pdo_mysql')."\n";
foreach ([dirname(__DIR__).'/portal-config/config.php',
          dirname(__DIR__,2).'/portal-config/config.php',
          dirname(__DIR__,3).'/portal-config/config.php'] as $c) {
    echo $c.' => '.(is_readable($c)?'OK':'nao')."\n";
}
require_once __DIR__.'/lib/db.php';
echo 'config carregada. DB_NAME='.(defined('DB_NAME')?DB_NAME:'undef')."\n";
try { db()->query('SELECT 1'); echo "DB conecta: OK\n"; }
catch (Throwable $e){ echo 'DB erro: '.$e->getMessage()."\n"; }
try { $n=db()->query("SELECT COUNT(*) FROM users")->fetchColumn(); echo "users existe: $n\n"; }
catch (Throwable $e){ echo 'users: '.$e->getMessage()."\n"; }
