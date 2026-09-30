<?php
/* =====================================================================
   listas-3c/acao.php: ações da tela (JSON, POST com CSRF).

     criar     modelo + período + nome → nova lista (status montando)
     passo     trabalha ~8 s na lista e devolve o progresso; a tela chama
               de novo até terminar (a hospedagem corta em ~60 s, então
               nunca uma chamada só para a lista inteira)
     aprovar   pronta → enviando, na campanha escolhida (primeiro confere
               duplicatas nela, depois cria a lista e sobe)
     cancelar  qualquer status que não seja enviada
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('listas-3c');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') l3c_json(['ok' => false, 'erro' => 'use POST'], 405);
$in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$_POST['csrf'] = $in['csrf'] ?? '';
csrf_check();
@set_time_limit(55);

$pdo = db();
$acao = (string)($in['acao'] ?? '');
$id = (int)($in['id'] ?? 0);

try {
    if ($acao === 'criar') {
        $modelos = l3c_modelos();
        $slug = (string)($in['modelo'] ?? '');
        if (!isset($modelos[$slug])) l3c_json(['ok' => false, 'erro' => 'Modelo desconhecido.'], 400);
        $de = (string)($in['de'] ?? ''); $ate = (string)($in['ate'] ?? '');
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $de) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate) || $de > $ate) {
            l3c_json(['ok' => false, 'erro' => 'Período inválido.'], 400);
        }
        // Sem nome digitado, vai o padrão das listas feitas à mão no 3C
        // (ver L3cTratamento::nomePadrao), para não confundir na campanha.
        $nome = trim((string)($in['nome'] ?? '')) ?: L3cTratamento::nomePadrao($modelos[$slug], $de, $ate);
        // Caixa da tela, por lista e desmarcada por padrão. O Jhony (30/09):
        // "geralmente não quero pegar esses leads recentes, que são campanha
        // deles, mas eventualmente quero". Fica gravada na foto do modelo
        // (l3c_listas.parametros), então a lista sabe como foi montada.
        $modelo = $modelos[$slug];
        $modelo['incluir_recentes_corretor'] = !empty($in['incluir_corretor']);
        $novo = L3cMontador::criar($pdo, $slug, $modelo, $de, $ate, mb_substr($nome, 0, 160), (int)$u['id']);
        l3c_json(['ok' => true, 'id' => $novo]);
    }

    if ($acao === 'passo') {
        $st = $pdo->prepare('SELECT status FROM l3c_listas WHERE id=?'); $st->execute([$id]);
        $status = $st->fetchColumn();
        if ($status === false) l3c_json(['ok' => false, 'erro' => 'Lista não existe.'], 404);
        $m = l3c_montador($status === 'enviando');
        l3c_json(['ok' => true] + $m->avancar($id, 8.0));
    }

    if ($acao === 'aprovar') {
        $campanha = (int)($in['campanha'] ?? 0);
        $tresc = L3cTresC::doConfig();
        $campanhas = $tresc->campanhas();   // já filtradas pela trava L3C_CAMPANHAS_PERMITIDAS
        if (!isset($campanhas[$campanha])) l3c_json(['ok' => false, 'erro' => 'Campanha não permitida ou inexistente no 3C.'], 400);
        // Só sai de 'pronta' uma vez: um segundo clique não cria outra lista no 3C.
        // cursor_json zerado: o envio começa conferindo duplicatas na campanha
        // escolhida (Montador::conferirDuplicatas) e guarda ali onde parou.
        $st = $pdo->prepare("UPDATE l3c_listas SET status='enviando', campanha_id=?, campanha_nome=?, aprovado_por=?, aprovado_em=NOW(), progresso=0,
                             cursor_json='{}', progresso_txt='Aprovada, conferindo quem já está na campanha' WHERE id=? AND status='pronta'");
        $st->execute([$campanha, $campanhas[$campanha], (int)$u['id'], $id]);
        if ($st->rowCount() !== 1) l3c_json(['ok' => false, 'erro' => 'A lista não está pronta para aprovar.'], 409);
        l3c_json(['ok' => true]);
    }

    if ($acao === 'cancelar') {
        $pdo->prepare("UPDATE l3c_listas SET status='cancelada', progresso_txt='Cancelada' WHERE id=? AND status<>'enviada'")->execute([$id]);
        l3c_json(['ok' => true]);
    }

    l3c_json(['ok' => false, 'erro' => 'Ação desconhecida.'], 400);
} catch (Throwable $e) {
    l3c_json(['ok' => false, 'erro' => $e->getMessage()], 500);
}
