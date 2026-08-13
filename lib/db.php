<?php
/* =====================================================================
   lib/db.php — carrega a configuração (fora da web) e abre a conexão PDO.
   A configuração NÃO fica no repositório; ver config/config.example.php.
   ===================================================================== */
declare(strict_types=1);

/** Localiza e carrega o config.php (procura em alguns caminhos prováveis). */
function portal_load_config(): void {
    static $carregado = false;
    if ($carregado) return;

    $candidatos = array_filter([
        getenv('PORTAL_CONFIG') ?: null,
        // Hostinger: portal em public_html/dados/ e config em ~/portal-config/
        dirname(__DIR__, 3) . '/portal-config/config.php',
        dirname(__DIR__, 2) . '/portal-config/config.php',
        // Desenvolvimento local: config dentro de config/config.php
        dirname(__DIR__) . '/config/config.php',
    ]);
    foreach ($candidatos as $c) {
        if ($c && is_readable($c)) { require_once $c; $carregado = true; return; }
    }
    http_response_code(500);
    exit('Configuração do portal não encontrada. Crie o config.php (veja config/config.example.php).');
}

/** Retorna a conexão PDO (singleton). */
function db(): PDO {
    static $pdo = null;
    if ($pdo instanceof PDO) return $pdo;
    portal_load_config();
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        $pdo->exec("SET time_zone = '-03:00'");
    } catch (Throwable $e) {
        http_response_code(500);
        exit('Falha ao conectar no banco. Confira as credenciais no config.php.');
    }
    return $pdo;
}
