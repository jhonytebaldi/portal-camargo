<?php
/* =====================================================================
   imoveis-campanha/acao.php: ações da tela (JSON, POST com CSRF).

     ligar        liga/desliga a Bianca no imóvel (interruptor do cartão)
     robust       lê o imóvel no Robust pelo código (só leitura)
     previa       pede à Bianca a resposta para uma pergunta "como o lead";
                  nada é enviado a ninguém (a task do Trigger não tem envio)
     previa_ler   a resposta da prévia, quando ficar pronta
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

$u = require_tool('imoveis-campanha');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') imc_json(['ok' => false, 'erro' => 'use POST'], 405);
$in = json_decode((string)file_get_contents('php://input'), true) ?: $_POST;
$_POST['csrf'] = $in['csrf'] ?? '';
csrf_check();
@set_time_limit(40);
$acao = (string)($in['acao'] ?? '');

try {
    if ($acao === 'robust') {
        $cod = preg_replace('/\D/', '', (string)($in['codigo'] ?? ''));
        if ($cod === '') imc_json(['ok' => false, 'erro' => 'Digite o código.'], 400);
        $r = L3cRobust::doConfig();
        try { $d = $r->get('/imoveis/' . $cod); }
        catch (RuntimeException $e) {
            if (str_contains($e->getMessage(), 'HTTP 404')) imc_json(['ok' => false, 'erro' => "O Robust não tem o imóvel $cod."]);
            throw $e;
        }
        $d = $d['data'] ?? $d;
        // Primeira foto visível, pelo /files (como a Busca: o 'arquivos' do detalhe às vezes vem incompleto).
        $foto = null;
        try {
            foreach ((array)($r->get("/imoveis/$cod/files")['data'] ?? []) as $a) {
                if (($a['filetype'] ?? '') !== 'media' || (isset($a['visible']) && !$a['visible'])) continue;
                $foto = $a['urls']['full'] ?? $a['urls']['small'] ?? null;
                if ($foto) break;
            }
        } catch (Throwable $e) { /* sem foto não impede o cadastro */ }
        imc_json(['ok' => true, 'campos' => ImcFicha::doRobust($d), 'titulo' => trim((string)($d['titulo'] ?? '')), 'foto' => $foto]);
    }

    $b = ImcBianca::doConfig();
    $id = (string)($in['id'] ?? '');
    $imovel = $b->rpc('camargo_portal_imovel', ['p_id' => $id]);
    if (!$imovel || !imc_visiveis($u, [$imovel])) imc_json(['ok' => false, 'erro' => 'Imóvel não encontrado.'], 404);

    if ($acao === 'ligar') {
        if (!imc_pode_editar($u, $imovel)) imc_json(['ok' => false, 'erro' => 'Só o corretor do imóvel ou um admin liga e desliga.'], 403);
        $imovel['ligado'] = !empty($in['ligado']);
        $b->rpc('camargo_portal_imovel_salvar', ['p_imovel' => $imovel, 'p_usuario' => (string)$u['nome']]);
        imc_json(['ok' => true]);
    }

    if ($acao === 'previa') {
        $sessao = (string)($in['sessao'] ?? '');
        $p = trim((string)($in['pergunta'] ?? ''));
        if (!preg_match('/^[0-9a-f-]{36}$/', $sessao) || $p === '') imc_json(['ok' => false, 'erro' => 'Pergunta vazia.'], 400);
        $r = $b->rpc('camargo_portal_previa_pedir', ['p_id' => $id, 'p_sessao' => $sessao, 'p_pergunta' => mb_substr($p, 0, 1000), 'p_usuario' => (string)$u['nome']]);
        imc_json(['ok' => true, 'id' => $r['id'] ?? null, 'chamou' => !empty($r['chamou'])]);
    }

    if ($acao === 'previa_ler') {
        $p = $b->rpc('camargo_portal_previa_ler', ['p_previa' => (string)($in['previa'] ?? '')]);
        if (!$p || ($p['imovel_id'] ?? '') !== $id) imc_json(['ok' => false, 'erro' => 'Prévia não encontrada.'], 404);
        $resp = (string)($p['resposta'] ?? '');
        $campos = imc_campos_para_marcar($imovel);
        $linhas = array_values(array_filter(preg_split('/\n+/', $resp) ?: [], fn($l) => trim($l) !== ''));
        imc_json([
            'ok' => true, 'status' => $p['status'] ?? 'pendente', 'resposta' => $resp, 'erro' => $p['erro'] ?? null,
            'numeros_tirados' => (array)($p['numeros_tirados'] ?? []),
            'usados' => ImcFicha::usados($resp, $campos, (string)$imovel['nome']),
            'usados_por_linha' => array_map(fn($l) => ImcFicha::usados($l, $campos, (string)$imovel['nome']), $linhas),
            'passou_por_linha' => array_map(fn($l) => ImcFicha::passouAoCorretor($l), $linhas),
        ]);
    }
    imc_json(['ok' => false, 'erro' => 'Ação desconhecida.'], 400);
} catch (Throwable $e) {
    imc_json(['ok' => false, 'erro' => imc_erro_legivel($e)], 500);
}
