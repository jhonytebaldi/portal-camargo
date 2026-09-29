<?php
/* =====================================================================
   listas-3c/bin/processar.php: continua as listas pelo cron do hPanel.

   A tela já monta a lista sozinha enquanto está aberta (passos curtos).
   Este script existe para quando ninguém está olhando: fechou a aba no
   meio, a lista continua. No CLI não há o corte de 60 s da web, mas cada
   lista ainda anda em passos de 5 min, e o GET_LOCK impede que o cron e a
   tela trabalhem na mesma lista ao mesmo tempo.

   hPanel → Avançado → Cron Jobs, a cada 5 minutos:
     php /home/USUARIO/domains/portal.imobcamargo.com.br/public_html/listas-3c/bin/processar.php
   É OPCIONAL: sem ele a lista só anda com a tela aberta.
   ===================================================================== */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('Só pelo cron.'); }
require_once __DIR__ . '/../_comum.php';
@set_time_limit(1700);

$inicio = time();
$ids = db()->query("SELECT id, status FROM l3c_listas WHERE status IN ('montando','enviando') ORDER BY id")->fetchAll();
foreach ($ids as $r) {
    if (time() - $inicio > 1500) break;
    $m = l3c_montador($r['status'] === 'enviando');
    do {
        $e = $m->avancar((int)$r['id'], 300.0);
        echo date('H:i:s') . " lista {$e['id']}: {$e['status']} {$e['progresso']}% {$e['texto']}\n";
    } while (in_array($e['status'], ['montando', 'enviando'], true) && empty($e['ocupada']) && time() - $inicio < 1500);
}
