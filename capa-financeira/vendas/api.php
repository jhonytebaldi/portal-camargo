<?php
/* =====================================================================
   capa-financeira/vendas/api.php — ações da tela da venda (JSON).
     previa    cálculos + descrição + validação do que está na tela (não grava)
     salvar    grava rascunho da etapa 1 (sem exigir tudo preenchido)
     concluir  valida, grava, confere/cadastra os compradores no Omie (Vertical)
               e marca a etapa 1 como concluída. Etapa já concluída: "salvar
               alterações" passa por aqui (continua tendo que estar válida).
     omie      confere um comprador no Omie (chip ao lado do CPF/CNPJ)
     cancelar  cancela a venda (fica no histórico; some da lista padrão)
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_vendas.php';

$u = require_tool('capa-financeira');
header('Content-Type: application/json; charset=utf-8');
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$_POST['csrf'] = $in['csrf'] ?? '';
csrf_check();

$resp = function (array $r, int $code = 200): never {
    http_response_code($code);
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
};
$acao = (string)($in['acao'] ?? '');
$id = (int)($in['id'] ?? 0);
$rev = (int)($in['rev'] ?? 0);

try {
    if ($acao === 'previa') {
        [$v, $comp, $fmt] = Venda::doFormulario((array)($in['venda'] ?? []));
        $val = Venda::validarE1($v, $comp, cv_contexto($v, $id));
        $calc = array_map(fn($x) => round($x, 6), Venda::calc($v));
        $bs = cv_bairros(); $kb = Venda::chave((string)$v['bairro']);
        $bairro = ['vazio' => $kb === '', 'na_lista' => isset($bs[$kb]), 'nome' => $bs[$kb]['nome'] ?? null,
                   'sugestao' => ($kb !== '' && !isset($bs[$kb])) ? Venda::parecido((string)$v['bairro'], array_map(fn($b) => $b['nome'], $bs), 75) : null];
        $resp(['ok' => true, 'calc' => $calc, 'descricao' => Venda::descricao($v, $comp),
               'exemplo_pagar' => Venda::descricaoPagar('NOME DO CORRETOR', 'CORRETOR', 'COMISSAO', Venda::descricao($v, $comp)),
               'erros' => array_merge($fmt, $val['erros']), 'avisos' => $val['avisos'],
               'sugestao' => cv_sugestao_cod((string)$v['cod_imovel']), 'bairro' => $bairro]);
    }

    /* ---------------- etapa 2: distribuição ---------------- */
    if (in_array($acao, ['previa2', 'salvar2', 'concluir2'], true)) {
        $venda = cv_carregar($id);
        if (!$venda) $resp(['ok' => false, 'erros' => ['Salve a etapa 1 antes de preencher a distribuição.']]);
        [$d, $fmt] = Distribuicao::doFormulario((array)($in['e2'] ?? []), cv_resolver_pessoa());
        $c1 = Venda::calc($venda);
        $val = Distribuicao::validar($d, $c1);
        if ($venda['e1_status'] === 'rascunho') $val['erros'][] = 'Conclua a etapa 1 antes de concluir a distribuição (os valores dependem dela).';
        if ($acao === 'previa2') {
            $calc = Distribuicao::calc($d, $c1);
            $resp(['ok' => true, 'calc' => $calc, 'c1' => $c1, 'erros' => array_merge($fmt, $val['erros']), 'avisos' => $val['avisos']]);
        }
        if ($venda['status'] === 'cancelada') $resp(['ok' => false, 'erros' => ['Venda cancelada — não pode ser editada.']]);
        if ($fmt) $resp(['ok' => false, 'erros' => $fmt]);
        $concluida = $venda['e2_status'] !== 'rascunho';
        if (($acao === 'concluir2' || $concluida) && $val['erros']) {
            $resp(['ok' => false, 'erros' => $val['erros'], 'msg' => $concluida ? 'A distribuição já está concluída: as alterações só são gravadas se tudo continuar válido.' : 'Corrija antes de concluir:']);
        }
        $g = cv_gravar_e2($id, $rev, $d, $u);
        if ($acao === 'concluir2' && !$concluida) {
            db()->prepare("UPDATE cv_vendas SET e2_status = 'concluida', e2_em = NOW(), e2_por = ?, rev = rev + 1 WHERE id = ?")->execute([(int)$u['id'], $id]);
            cv_hist($id, 2, 'concluir', 'Etapa 2', 'rascunho', 'concluída', $u);
        }
        $rev = (int)db()->query('SELECT rev FROM cv_vendas WHERE id = ' . $id)->fetchColumn();
        $resp(['ok' => true, 'id' => $id, 'rev' => $rev, 'avisos' => $val['avisos'],
               'msg' => $acao === 'salvar2' ? 'Rascunho da distribuição salvo.' : ($concluida ? 'Alterações gravadas (etapa 2 continua concluída).' : 'Etapa 2 concluída.')]);
    }

    if ($acao === 'add_bairro') {
        $b = cv_add_bairro((string)($in['nome'] ?? ''), Venda::texto($in['regiao'] ?? '', false), Venda::texto($in['cidade'] ?? '', false));
        if ($id) cv_hist($id, 1, 'bairro_novo', 'Lista de bairros', null, 'cadastrou "' . $b['nome'] . '" (' . $b['regiao'] . ', ' . $b['cidade'] . ')', $u);
        $resp(['ok' => true, 'bairro' => $b]);
    }

    if ($acao === 'descancelar') {
        $atual = cv_carregar($id);
        if (!$atual || $atual['status'] !== 'cancelada') $resp(['ok' => false, 'erros' => ['Esta venda não está cancelada.']]);
        $volta = in_array($atual['cancelada_de'], ['andamento', 'concluida'], true) ? $atual['cancelada_de'] : 'andamento';
        db()->prepare('UPDATE cv_vendas SET status = ?, cancelada_de = NULL, rev = rev + 1, atualizado_por = ?, atualizado_em = NOW() WHERE id = ?')->execute([$volta, (int)$u['id'], $id]);
        cv_hist($id, 0, 'descancelar', 'Venda', 'cancelada', $volta, $u);
        $resp(['ok' => true]);
    }

    if ($acao === 'omie') {
        $doc = Venda::docLimpo($in['documento'] ?? '');
        if (!Venda::tipoDoc($doc)) $resp(['ok' => true, 'status' => 'invalido', 'msg' => 'CPF/CNPJ inválido']);
        $r = OmieCompradores::localizar($doc, Venda::texto($in['nome'] ?? ''));
        if ($r['status'] === 'ok' && $r['nome'] && Venda::chave($r['nome']) !== Venda::chave((string)($in['nome'] ?? ''))) $r['nome_difere'] = true;
        $resp(['ok' => true] + $r);
    }

    if ($acao === 'salvar' || $acao === 'concluir') {
        [$v, $comp, $fmt] = Venda::doFormulario((array)($in['venda'] ?? []));
        if ($fmt) $resp(['ok' => false, 'erros' => $fmt]);
        $atual = $id ? cv_carregar($id) : null;
        if ($id && !$atual) $resp(['ok' => false, 'erros' => ['Venda não encontrada.']], 404);
        if ($atual && $atual['status'] === 'cancelada') $resp(['ok' => false, 'erros' => ['Venda cancelada — não pode ser editada.']]);
        $concluida = $atual && $atual['e1_status'] !== 'rascunho';
        $val = Venda::validarE1($v, $comp, cv_contexto($v, $id));
        if (($acao === 'concluir' || $concluida) && $val['erros']) {
            $resp(['ok' => false, 'erros' => $val['erros'], 'msg' => $concluida ? 'A etapa 1 já está concluída: as alterações só são gravadas se tudo continuar válido.' : 'Corrija antes de concluir:']);
        }
        if ($acao === 'salvar' && $concluida) $acao = 'concluir';   // etapa concluída: salvar = revalidar + conferir no Omie

        $g = cv_gravar_e1($id, $rev, $v, $comp, $u);
        $id = $g['id']; $rev = $g['rev'];
        if ($acao === 'salvar') $resp(['ok' => true, 'id' => $id, 'rev' => $rev, 'mudou' => $g['mudou'], 'msg' => 'Rascunho salvo.']);

        // ---- Omie: cada comprador precisa estar ligado a um cadastro da Vertical ----
        $venda = cv_carregar($id);
        $confirmados = array_map(fn($d) => Venda::docLimpo($d), (array)($in['confirmar_omie'] ?? []));
        $pendentes = []; $avisos = []; $falhas = [];
        $upd = db()->prepare('UPDATE cv_compradores SET omie_codigo = ?, omie_nome = ?, omie_status = ?, omie_em = NOW() WHERE id = ?');
        foreach ($venda['compradores'] as $c) {
            if ($c['omie_codigo']) continue;   // já ligado (documento não mudou)
            $l = OmieCompradores::localizar($c['documento'], $c['nome']);
            if ($l['status'] === 'erro') { $falhas[] = $c['nome'] . ': ' . $l['msg']; continue; }
            if ($l['status'] === 'ok') {
                $upd->execute([$l['codigo'], $l['nome'], 'ok', $c['id']]);
                cv_hist($id, 1, 'omie_vinculo', $c['nome'], null, 'Omie Vertical cód. ' . $l['codigo'] . ' (' . $l['nome'] . ')', $u);
                if ($l['nome'] && Venda::chave($l['nome']) !== Venda::chave($c['nome'])) $avisos[] = $c['nome'] . ': no Omie o cadastro deste CPF/CNPJ está como "' . $l['nome'] . '" — confira se é a mesma pessoa.';
                continue;
            }
            if (!in_array($c['documento'], $confirmados, true)) {
                $pendentes[] = ['documento' => $c['documento'], 'doc_fmt' => Venda::docFormatado($c['documento']), 'nome' => $c['nome'], 'email' => $c['email'],
                                'status' => $l['status'], 'msg' => $l['status'] === 'so_nome'
                                    ? 'Existe no Omie um cadastro com esse nome mas com OUTRO documento (' . ($l['nome'] ?? '') . ', cód. ' . ($l['codigo'] ?? '?') . '). Se for a mesma pessoa, corrija o documento no Omie em vez de cadastrar de novo.'
                                    : 'Não encontrado no Omie da Vertical — será cadastrado.'];
                continue;
            }
            $r = OmieCompradores::cadastrar($c);
            if (!$r['ok']) { $falhas[] = $c['nome'] . ': ' . $r['msg']; continue; }
            $upd->execute([$r['codigo'], mb_substr($c['nome'], 0, 60), 'cadastrado', $c['id']]);
            cv_hist($id, 1, 'omie_cadastro', $c['nome'], null, 'Cadastrado no Omie Vertical — cód. ' . $r['codigo'], $u);
        }
        if (($falhas || $pendentes) && $concluida) {   // comprador novo/alterado sem vínculo no Omie: a etapa volta a rascunho até vincular
            db()->prepare("UPDATE cv_vendas SET e1_status = 'rascunho', rev = rev + 1 WHERE id = ?")->execute([$id]);
            cv_hist($id, 1, 'reabrir', 'Etapa 1', 'concluída', 'rascunho (comprador sem vínculo no Omie)', $u);
            $rev = (int)db()->query('SELECT rev FROM cv_vendas WHERE id = ' . $id)->fetchColumn();
            $concluida = false;
        }
        if ($falhas) $resp(['ok' => false, 'id' => $id, 'rev' => $rev, 'erros' => $falhas, 'msg' => 'Dados salvos, mas a etapa NÃO foi concluída — problema no Omie:']);
        if ($pendentes) $resp(['ok' => false, 'id' => $id, 'rev' => $rev, 'confirmar' => $pendentes, 'avisos' => $avisos]);

        if (!$concluida) {
            db()->prepare("UPDATE cv_vendas SET e1_status = 'concluida', e1_em = NOW(), e1_por = ?, rev = rev + 1 WHERE id = ?")->execute([(int)$u['id'], $id]);
            cv_hist($id, 1, 'concluir', 'Etapa 1', 'rascunho', 'concluída', $u);
        }
        $rev = (int)db()->query('SELECT rev FROM cv_vendas WHERE id = ' . $id)->fetchColumn();
        $resp(['ok' => true, 'id' => $id, 'rev' => $rev, 'concluida' => true, 'avisos' => array_merge($avisos, $val['avisos']),
               'msg' => $concluida ? 'Alterações gravadas (etapa 1 continua concluída).' : 'Etapa 1 concluída.']);
    }

    if ($acao === 'cancelar') {
        $atual = cv_carregar($id);
        if (!$atual) $resp(['ok' => false, 'erros' => ['Venda não encontrada.']], 404);
        // contas já geradas/enviadas ao Omie: o cancelamento com exclusão dos títulos entra junto com a etapa 3 (geração das contas)
        if ($atual['capa_id']) $resp(['ok' => false, 'erros' => ['Esta venda já gerou contas na Capa Financeira — o cancelamento com exclusão dos títulos no Omie ainda não está disponível por aqui.']]);
        $motivo = Venda::texto($in['motivo'] ?? '', false);
        if ($motivo === '') $resp(['ok' => false, 'erros' => ['Informe o motivo do cancelamento.']]);
        db()->prepare("UPDATE cv_vendas SET cancelada_de = status, status = 'cancelada', rev = rev + 1, atualizado_por = ?, atualizado_em = NOW() WHERE id = ?")->execute([(int)$u['id'], $id]);
        cv_hist($id, 0, 'cancelar', 'Venda', $atual['status'], 'cancelada — ' . $motivo, $u);
        $resp(['ok' => true]);
    }

    $resp(['ok' => false, 'erros' => ['ação desconhecida']], 400);
} catch (Throwable $e) {
    $resp(['ok' => false, 'erros' => [$e->getMessage()]], 200);
}
