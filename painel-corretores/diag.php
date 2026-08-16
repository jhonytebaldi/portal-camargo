<?php
/* painel-corretores/diag.php — diagnóstico rápido da coleta (só admin).
   Mostra estado dos arquivos de dados e o fim do log do coletor. Temporário. */
declare(strict_types=1);
require_once __DIR__ . '/../lib/auth.php';
$u = current_user();
if (!$u || $u['papel'] !== 'admin') { http_response_code(403); exit('Apenas admin.'); }
portal_load_config();
header('Content-Type: text/plain; charset=utf-8');

$dir = rtrim(defined('PAINEL_DATA_DIR') ? PAINEL_DATA_DIR : (dirname(__DIR__, 2) . '/painel-dados'), '/');
echo "data_dir: $dir\n\n";

foreach (['aguardando.json','painel_live.json','status.json','coletor.log'] as $f) {
    $p = $dir . '/' . $f;
    if (!is_file($p)) { echo "$f — (não existe)\n"; continue; }
    echo sprintf("%-18s %8d bytes  mtime=%s\n", $f, filesize($p), date('d/m H:i:s', (int)filemtime($p)));
}
echo "\n--- aguardando.json (resumo) ---\n";
$ag = json_decode((string)@file_get_contents($dir.'/aguardando.json'), true);
if (is_array($ag)) {
    $items = $ag['items'] ?? [];
    $withThread = 0; foreach ($items as $it) if (!empty($it['thread'])) $withThread++;
    echo "generated: " . ($ag['generated'] ?? '?') . "\n";
    echo "items: " . count($items) . " · com thread: $withThread\n";
    $k = $items[0] ?? null; if ($k) echo "keys[0]: " . implode(',', array_keys($k)) . "\n";
} else echo "(inválido/vazio)\n";

echo "\n--- código implantado ---\n";
foreach (['coletor.php','index.php'] as $src) {
    $p = __DIR__ . '/' . $src;
    $c = (string)@file_get_contents($p);
    echo sprintf("%-12s mtime=%s  tem 'thread'=%s  tem 'wesalescrm'=%s\n",
        $src, date('d/m H:i:s', (int)@filemtime($p)),
        (strpos($c, "'thread'") !== false || strpos($c,'thread') !== false) ? 'SIM' : 'não',
        (strpos($c, 'wesalescrm') !== false) ? 'SIM' : 'não');
}
echo "opcache: " . (function_exists('opcache_get_status') ? 'disponível' : 'ausente') . "\n";

echo "\n--- coletor.log (últimas 40 linhas) ---\n";
$log = $dir . '/coletor.log';
if (is_file($log)) {
    $lines = @file($log, FILE_IGNORE_NEW_LINES);
    if ($lines) echo implode("\n", array_slice($lines, -40));
} else echo "(sem log)";
echo "\n";
