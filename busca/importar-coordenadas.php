<?php
/* Recebe a planilha de coordenadas preenchida por fora e grava no cache
   de geocodificação. Colunas necessárias: Codigo, Latitude, Longitude.
   Aceita CSV separado por ; ou , */
require_once __DIR__ . '/config.php';
session_start();
if (empty($_SESSION['autenticado'])) { http_response_code(401); exit('Faça login primeiro.'); }

const LAT_MIN = -26.90, LAT_MAX = -25.90, LNG_MIN = -49.40, LNG_MAX = -48.40;
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['arquivo']['tmp_name'])) {
    $fh = fopen($_FILES['arquivo']['tmp_name'], 'r');
    $primeira = fgets($fh);
    $sep = substr_count($primeira, ';') >= substr_count($primeira, ',') ? ';' : ',';
    rewind($fh);

    $cab = fgetcsv($fh, 0, $sep);
    $cab = array_map(function ($c) {
        $c = strtolower(trim(str_replace("\xEF\xBB\xBF", '', (string)$c)));
        return strtr($c, ['ó'=>'o','ç'=>'c','á'=>'a','ú'=>'u','í'=>'i','é'=>'e','ã'=>'a']);
    }, $cab ?: []);
    $iCod = array_search('codigo', $cab);
    $iLat = array_search('latitude', $cab);
    $iLng = array_search('longitude', $cab);
    if ($iCod === false || $iLat === false || $iLng === false) {
        $msg = '<p class="err">A planilha precisa ter as colunas <b>Codigo</b>, <b>Latitude</b> e <b>Longitude</b>.</p>';
    } else {
        $cache = file_exists(DATA_DIR . '/geocode.json')
            ? (json_decode(@file_get_contents(DATA_DIR . '/geocode.json'), true) ?: []) : [];
        $ok = 0; $ruins = 0; $vazios = 0;
        while (($l = fgetcsv($fh, 0, $sep)) !== false) {
            $cod = trim((string)($l[$iCod] ?? ''));
            $la = str_replace(',', '.', trim((string)($l[$iLat] ?? '')));
            $lo = str_replace(',', '.', trim((string)($l[$iLng] ?? '')));
            if ($cod === '' || $la === '' || $lo === '') { $vazios++; continue; }
            if (!is_numeric($la) || !is_numeric($lo)) { $ruins++; continue; }
            $la = (float)$la; $lo = (float)$lo;
            // Fora de Joinville é erro de digitação ou coordenada inventada.
            if ($la < LAT_MIN || $la > LAT_MAX || $lo < LNG_MIN || $lo > LNG_MAX) { $ruins++; continue; }
            $cache[$cod] = ['la' => round($la, 6), 'lo' => round($lo, 6),
                            'q' => 'numero', 'end' => 'importado', 'em' => date('c')];
            $ok++;
        }
        file_put_contents(DATA_DIR . '/geocode.json', json_encode($cache, JSON_UNESCAPED_UNICODE));
        log_sync("coordenadas importadas: $ok válidas, $ruins recusadas");
        $msg = "<p class=\"ok\"><b>$ok coordenadas importadas.</b>"
             . ($ruins ? " $ruins recusadas por estarem fora de Joinville ou mal formatadas." : '')
             . ($vazios ? " $vazios linhas sem coordenada foram puladas." : '')
             . '<br>Agora clique em <b>Sincronizar com o CRM</b> na ferramenta.</p>';
    }
    fclose($fh);
}
?><!DOCTYPE html><html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><title>Importar coordenadas</title>
<style>
body{font-family:system-ui,sans-serif;max-width:640px;margin:50px auto;padding:0 20px;color:#12211c;line-height:1.6}
h1{font-size:22px}
input[type=file]{padding:12px;border:2px dashed #d8d3c4;border-radius:3px;width:100%;background:#fbfaf6}
button{margin-top:14px;padding:12px 24px;background:#2f5d4f;color:#fff;border:none;border-radius:3px;
  font-size:15px;font-weight:600;cursor:pointer}
.ok{background:#dde9e3;border-left:4px solid #2f5d4f;padding:13px;border-radius:3px}
.err{background:rgba(180,81,47,.1);border-left:4px solid #b4512f;padding:13px;border-radius:3px}
code{background:#f2efe6;padding:2px 6px;border-radius:2px;font-size:13px}
</style></head><body>
<h1>Importar coordenadas</h1>
<?= $msg ?>
<p>Envie o CSV com as colunas <code>Codigo</code>, <code>Latitude</code> e <code>Longitude</code>.
Linhas sem coordenada são ignoradas — dá para importar por partes.</p>
<form method="post" enctype="multipart/form-data">
  <input type="file" name="arquivo" accept=".csv,.txt" required>
  <button type="submit">Importar</button>
</form>
<p style="margin-top:24px;font-size:14px;color:#6b7770">
Ainda não tem o arquivo? <a href="exportar-enderecos.php">Baixe a lista de endereços sem coordenada</a>.</p>
</body></html>
