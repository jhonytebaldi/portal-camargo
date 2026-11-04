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
        // quem recebe tem que existir no Omie da empresa da venda (empresa/CNPJ conforme o cadastro de Pessoas)
        $ids = array_merge(array_column($d['linhas'], 'pessoa_id'), $d['conclusao']['ativo'] ? array_column($d['conclusao']['recebem'], 'pessoa_id') : []);
        $omie = cv_pessoas_omie((string)$venda['empresa'], $ids);
        if ($omie !== null) {
            $pes = cf_pessoas(false);
            foreach ($omie as $pid => $o) {
                $nm = (string)($pes[$pid]['nome'] ?? $pid);
                if ($o['status'] === 'so_nome') $val['avisos'][] = "$nm: " . $o['msg'];
                elseif ($o['status'] !== 'ok') $val['erros'][] = "$nm: não confere com o Omie da " . strtoupper((string)$venda['empresa']) . ' — ' . $o['msg'];
            }
        }
        if ($acao === 'previa2') {
            $calc = Distribuicao::calc($d, $c1);
            $resp(['ok' => true, 'calc' => $calc, 'c1' => $c1, 'omie' => $omie, 'recebem_ids' => $d['conclusao']['ativo'] ? array_column($d['conclusao']['recebem'], 'pessoa_id') : [], 'empresa' => strtoupper((string)$venda['empresa']), 'erros' => array_merge($fmt, $val['erros']), 'avisos' => $val['avisos']]);
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
               'msg' => ($acao === 'salvar2' && !$concluida) ? 'Rascunho da distribuição salvo.' : ($concluida ? 'Alterações gravadas (etapa 2 continua concluída).' : 'Etapa 2 concluída.')]);
    }

    /* ---------------- etapa 4: pagamentos aos corretores → contas a pagar ---------------- */
    if (in_array($acao, ['previa4', 'salvar4', 'liberar4', 'desfazer4'], true)) {
        $venda = cv_carregar($id);
        if (!$venda) $resp(['ok' => false, 'erros' => ['Venda não encontrada.']]);
        $e4 = $venda['e4'] ?? ['etapas' => [], 'liberados' => []];
        $e4 += ['etapas' => [], 'liberados' => [], 'linhas' => []];
        // datas manuais e condições vindas da tela (liberações nunca vêm da tela)
        $fmt = []; $novas = [];
        foreach ((array)($in['e4']['etapas'] ?? []) as $k => $cfg) {
            $k = (string)$k;
            $dm = trim((string)($cfg['data_manual'] ?? ''));
            if ($dm !== '' && !(($d = DateTimeImmutable::createFromFormat('!Y-m-d', $dm)) && $d->format('Y-m-d') === $dm)) { $fmt[] = 'Data inválida: ' . $dm; $dm = ''; }
            $cond = mb_strtoupper(mb_substr(preg_replace('/\s+/', ' ', trim((string)($cfg['condicao'] ?? ''))), 0, 120));
            if ($dm !== '' || $cond !== '') $novas[$k] = ['data_manual' => $dm ?: null, 'condicao' => $cond];
        }
        // ajustes por linha de pagamento (só os campos que diferem do calculado vêm da tela)
        $linhas = [];
        foreach ((array)($in['e4']['linhas'] ?? []) as $k => $ov) {
            $o = [];
            foreach ((array)$ov as $f => $val) {
                $val = trim((string)$val);
                if ($f === 'data') {
                    if ($val !== '' && !(($d = DateTimeImmutable::createFromFormat('!Y-m-d', $val)) && $d->format('Y-m-d') === $val)) { $fmt[] = 'Data inválida: ' . $val; continue; }
                    $o['data'] = $val;
                } elseif ($f === 'valor') {
                    $n = str_contains($val, ',') ? str_replace(',', '.', str_replace('.', '', preg_replace('/[R$\s]/', '', $val))) : preg_replace('/[R$\s]/', '', $val);
                    if (!is_numeric($n) || (float)$n < 0) { $fmt[] = 'Valor inválido: ' . $val; continue; }
                    $o['valor'] = round((float)$n, 2);
                } elseif ($f === 'condicao') $o['condicao'] = mb_strtoupper(mb_substr(preg_replace('/\s+/', ' ', $val), 0, 120));
                elseif ($f === 'obs') $o['obs'] = mb_substr($val, 0, 200);
            }
            if ($o) $linhas[(string)$k] = $o;
        }
        $e4n = ['etapas' => $novas, 'liberados' => $e4['liberados'], 'linhas' => $linhas];
        $fer = cv_feriados();
        $calc = Pagamentos::calcular($venda, $e4n, $fer);
        $pode = cv_pode_liberar($u);
        $base = [];
        if ($venda['e2_status'] === 'rascunho') $base[] = 'Conclua a etapa 2 (distribuição) antes de liberar pagamentos.';
        if ($venda['e3_status'] === 'rascunho') $base[] = 'Conclua a etapa 3 (fluxo do cliente) antes de liberar pagamentos.';
        if ($acao === 'previa4') $resp(['ok' => true, 'calc' => $calc, 'status' => cv_status_pagamentos($venda), 'pode_liberar' => $pode, 'erros' => array_merge($fmt, $base, $calc['erros']), 'avisos' => $calc['avisos']]);
        if ($venda['status'] === 'cancelada') $resp(['ok' => false, 'erros' => ['Venda cancelada — não pode ser editada.']]);
        if ($fmt) $resp(['ok' => false, 'erros' => $fmt]);
        // histórico das datas/condições por etapa
        $rot = array_column($calc['etapas'], 'rotulo', 'chave');
        $hist = [];
        foreach (array_unique(array_merge(array_keys($e4['etapas']), array_keys($novas))) as $k) {
            $a = $e4['etapas'][$k] ?? []; $d = $novas[$k] ?? [];
            $nome = 'Pagamento ' . ($rot[$k] ?? $k);
            if ((string)($a['data_manual'] ?? '') !== (string)($d['data_manual'] ?? '')) $hist[] = ['editar', $nome . ' — data manual', cv_data_br($a['data_manual'] ?? null), cv_data_br($d['data_manual'] ?? null)];
            if ((string)($a['condicao'] ?? '') !== (string)($d['condicao'] ?? '')) $hist[] = ['editar', $nome . ' — condição', (string)($a['condicao'] ?? ''), (string)($d['condicao'] ?? '')];
        }
        $porChave = array_column($calc['pagamentos'], null, 'chave');
        $rotP = fn(array $p) => $p['nome'] . ' (' . $p['cargo'] . ($p['natureza'] === 'BONUS' ? ' · bônus' : '') . ') — ' . ($rot[$p['etapa']] ?? $p['etapa']);
        $rotK = fn(string $k) => isset($porChave[$k]) ? $rotP($porChave[$k]) : str_replace('|', ' · ', preg_replace('/\|\d+$/', '', $k));
        $nomesF = ['data' => 'data', 'valor' => 'valor', 'condicao' => 'condição', 'obs' => 'observação'];
        $mostra = fn(string $f, $x) => $x === null ? '' : ($f === 'valor' ? Distribuicao::brl((float)$x) : ($f === 'data' ? cv_data_br((string)$x) : (string)$x));
        foreach (array_unique(array_merge(array_keys($e4['linhas']), array_keys($linhas))) as $k) {
            foreach ($nomesF as $f => $nf) {
                $a = $e4['linhas'][$k][$f] ?? null; $d = $linhas[$k][$f] ?? null;
                if ((string)$a === (string)$d) continue;
                $calcF = $porChave[$k]['calc'][$f] ?? null;
                $hist[] = ['editar', 'Pagamento ' . $rotK((string)$k) . ' — ' . $nf, $a !== null ? $mostra($f, $a) : 'calculado: ' . $mostra($f, $calcF), $d !== null ? $mostra($f, $d) : 'voltou ao calculado (' . $mostra($f, $calcF) . ')'];
            }
        }
        $chaves = array_values(array_unique(array_map('strval', (array)($in['chaves'] ?? []))));
        if ($acao === 'liberar4' && !empty($in['todos'])) {   // padrão: libera tudo o que falta (e o que mudou depois de liberado)
            $chaves = array_values(array_map(fn($p) => $p['chave'], array_filter($calc['pagamentos'], fn($p) => !$p['liberado'] || $p['alterado_depois'])));
            if (!$chaves) $resp(['ok' => false, 'erros' => ['Todos os pagamentos já estão liberados.']]);
        }
        $gerar = false;
        if ($acao === 'liberar4' || $acao === 'desfazer4') {
            if (!$pode) $resp(['ok' => false, 'erros' => ['Só o financeiro libera pagamentos. Peça a um administrador para incluir o seu login em Capa Financeira → Configurações → “Vendas — pagamentos aos corretores”.']]);
            if (!$chaves) $resp(['ok' => false, 'erros' => ['Marque os pagamentos.']]);
        }
        if ($acao === 'liberar4') {
            if ($base || $calc['erros']) $resp(['ok' => false, 'erros' => array_merge($base, $calc['erros']), 'msg' => 'Corrija antes de liberar:']);
            $err = []; $faltaE = [];
            foreach ($chaves as $k) {
                $p = $porChave[$k] ?? null;
                if (!$p) { $err[] = 'Um dos pagamentos marcados não existe mais no cálculo (recarregue a página).'; continue; }
                $etP = array_column($calc['etapas'], null, 'chave')[$p['etapa']] ?? [];
                if (!$p['data']) $faltaE[$p['etapa']]['data'][] = $p['nome'];
                if (!empty($etP['exige_condicao']) && trim((string)$p['condicao']) === '') $faltaE[$p['etapa']]['condição'][] = $p['nome'];
                if ($p['valor'] <= 0) $err[] = $rotP($p) . ': valor zerado.';
            }
            foreach ($faltaE as $etK => $f) {   // agrupado por etapa (lista curta)
                $et = array_column($calc['etapas'], null, 'chave')[$etK] ?? ['rotulo' => $etK, 'travado' => false];
                $err[] = 'Etapa ' . $et['rotulo'] . (!empty($et['travado']) ? ' (travada)' : '') . ': informe ' . implode(' e ', array_map(fn($c, $ns) => 'a ' . $c . ' (' . count($ns) . ' pagamento' . (count($ns) > 1 ? 's' : '') . ')', array_keys($f), $f)) . ' — na etapa ou linha a linha.';
            }
            if ($err) $resp(['ok' => false, 'erros' => $err, 'msg' => 'Não liberei nada:']);
            $sts = cv_status_pagamentos($venda);
            foreach ($chaves as $k) {
                $p = $porChave[$k]; $ant = $e4n['liberados'][$k] ?? null;
                $e4n['liberados'][$k] = ['por' => (string)$u['nome'], 'por_id' => (int)$u['id'], 'em' => date('Y-m-d H:i:s'), 'valor' => round((float)$p['valor'], 2), 'data' => $p['data'], 'condicao' => $p['condicao']]
                                      + ($ant ? ['linha' => $ant['linha'] ?? null, 'capa' => $ant['capa'] ?? null] : []);
                $hist[] = ['liberar', 'Pagamento ' . $rotP($p),
                           $ant ? Distribuicao::brl((float)$ant['valor']) . ' em ' . cv_data_br($ant['data']) . (($sts[$k]['status'] ?? '') === 'exportado' ? ' (já estava no Omie)' : '') : 'aguardando',
                           'liberado: ' . Distribuicao::brl((float)$p['valor']) . ' em ' . cv_data_br($p['data']) . ($p['condicao'] !== '' ? ' · ' . $p['condicao'] : '')];
            }
            $gerar = true;
        }
        if ($acao === 'desfazer4') {
            $sts = cv_status_pagamentos($venda);
            $err = [];
            foreach ($chaves as $k) {
                if (!isset($e4n['liberados'][$k])) continue;
                if (($sts[$k]['status'] ?? '') === 'exportado') { $err[] = 'Um pagamento já foi enviado ao Omie (' . ($sts[$k]['codigo'] ?? '') . ') — não dá para desfazer a liberação aqui; exclua/ajuste o título no Omie e na Capa Financeira.'; continue; }
                $x = $e4n['liberados'][$k];
                $p = $porChave[$k] ?? ['nome' => preg_replace('/\|\d+$/', '', $k), 'cargo' => '', 'natureza' => '', 'etapa' => ''];
                unset($e4n['liberados'][$k]);
                $hist[] = ['desliberar', 'Pagamento ' . ($p['cargo'] !== '' ? $rotP($p) : str_replace('|', ' · ', (string)$p['nome'])), 'liberado: ' . Distribuicao::brl((float)$x['valor']) . ' em ' . cv_data_br($x['data']), 'aguardando liberação'];
            }
            if ($err) $resp(['ok' => false, 'erros' => $err]);
            $gerar = true;
        }
        $rev = cv_gravar_e4($id, $rev, $e4n, $u, $hist);
        $capaId = null;
        if ($gerar) {
            $capaId = cv_gerar_capa($id, $u);
            cv_atualizar_conclusao($id, $u);
            $rev = (int)db()->query('SELECT rev FROM cv_vendas WHERE id = ' . $id)->fetchColumn();
        }
        $n = count($chaves);
        $msg = match ($acao) {
            'liberar4' => $n . ' pagamento(s) liberado(s). Contas a pagar na capa #' . $capaId . ' — revise e confirme na Capa Financeira para enviar ao Omie.',
            'desfazer4' => 'Liberação desfeita (' . $n . '). Capa #' . $capaId . ' regerada sem esse(s) pagamento(s) — revise na Capa Financeira.',
            default => $hist ? 'Alterações dos pagamentos gravadas.' : 'Nada mudou.',
        };
        $resp(['ok' => true, 'id' => $id, 'rev' => $rev, 'capa_id' => $capaId, 'msg' => $msg, 'avisos' => Pagamentos::calcular(cv_carregar($id), $e4n, $fer)['avisos']]);
    }

    /* ---------------- etapa 3: fluxo do cliente → contas a receber ---------------- */
    if (in_array($acao, ['previa3', 'salvar3', 'concluir3'], true)) {
        $venda = cv_carregar($id);
        if (!$venda) $resp(['ok' => false, 'erros' => ['Salve a etapa 1 antes de preencher o fluxo.']]);
        [$d, $fmt] = Fluxo::doFormulario((array)($in['e3'] ?? []));
        $c1 = Venda::calc($venda);
        $val = Fluxo::validar($d, $c1, $venda);
        if ($venda['e1_status'] === 'rascunho') $val['erros'][] = 'Conclua a etapa 1 antes de concluir o fluxo (compradores e valores vêm dela).';
        if ($acao === 'previa3') $resp(['ok' => true, 'calc' => Fluxo::calc($d, $c1), 'c1' => $c1, 'erros' => array_merge($fmt, $val['erros']), 'avisos' => $val['avisos']]);
        if ($venda['status'] === 'cancelada') $resp(['ok' => false, 'erros' => ['Venda cancelada — não pode ser editada.']]);
        if ($fmt) $resp(['ok' => false, 'erros' => $fmt]);
        $concluida = $venda['e3_status'] !== 'rascunho';
        if (($acao === 'concluir3' || $concluida) && $val['erros']) {
            $resp(['ok' => false, 'erros' => $val['erros'], 'msg' => $concluida ? 'O fluxo já está concluído: as alterações só são gravadas se tudo continuar válido.' : 'Corrija antes de concluir:']);
        }
        $g = cv_gravar_e3($id, $rev, $d, $u);
        $rev = $g['rev'];
        if ($acao === 'salvar3' && !$concluida) $resp(['ok' => true, 'id' => $id, 'rev' => $rev, 'msg' => 'Rascunho do fluxo salvo.']);
        // quem paga precisa existir no Omie da empresa da venda (é lá que as contas a receber são lançadas)
        $venda = cv_carregar($id);
        $om = cv_pagadores_omie($venda, $venda['e3'], array_map(fn($x) => Venda::docLimpo($x), (array)($in['confirmar_omie'] ?? [])), $u);
        if ($om['falhas']) $resp(['ok' => false, 'id' => $id, 'rev' => $rev, 'erros' => $om['falhas'], 'msg' => 'Fluxo salvo, mas as contas a receber NÃO foram geradas — problema no Omie:']);
        if ($om['pendentes']) $resp(['ok' => false, 'id' => $id, 'rev' => $rev, 'confirmar' => $om['pendentes'], 'empresa' => strtoupper((string)$venda['empresa'])]);
        if (!$concluida) {
            db()->prepare("UPDATE cv_vendas SET e3_status = 'concluida', e3_em = NOW(), e3_por = ?, rev = rev + 1 WHERE id = ?")->execute([(int)$u['id'], $id]);
            cv_hist($id, 3, 'concluir', 'Etapa 3', 'rascunho', 'concluída', $u);
        }
        $capaId = null;
        if (!$concluida || $g['mudou'] > 0 || !$venda['capa_id']) $capaId = cv_gerar_receber($id, $u);
        $rev = (int)db()->query('SELECT rev FROM cv_vendas WHERE id = ' . $id)->fetchColumn();
        $resp(['ok' => true, 'id' => $id, 'rev' => $rev, 'capa_id' => $capaId, 'avisos' => $val['avisos'],
               'msg' => ($concluida ? 'Alterações gravadas.' : 'Etapa 3 concluída.') . ($capaId ? ' Contas a receber geradas na capa #' . $capaId . ' — revise e confirme na Capa Financeira para enviar ao Omie.' : ' Nada mudou nas parcelas: as contas a receber continuam as mesmas.')]);
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

    if ($acao === 'cancelar_previa') {
        $atual = cv_carregar($id);
        if (!$atual) $resp(['ok' => false, 'erros' => ['Venda não encontrada.']], 404);
        $t = cv_titulos_para_cancelar($atual);
        $resp(['ok' => true, 'titulos' => $t['titulos'], 'capas' => $t['capas']]);
    }

    if ($acao === 'cancelar') {
        $atual = cv_carregar($id);
        if (!$atual) $resp(['ok' => false, 'erros' => ['Venda não encontrada.']], 404);
        if ($atual['status'] === 'cancelada') $resp(['ok' => false, 'erros' => ['A venda já está cancelada.']]);
        $motivo = Venda::texto($in['motivo'] ?? '', false);
        if ($motivo === '') $resp(['ok' => false, 'erros' => ['Informe o motivo do cancelamento.']]);
        $t = cv_titulos_para_cancelar($atual);
        $temOmie = array_filter($t['titulos'], fn($x) => $x['acao'] !== 'nada');
        if ($temOmie && empty($in['confirmado'])) $resp(['ok' => false, 'precisa_confirmar' => true, 'titulos' => $t['titulos']]);
        $res = []; $ficaram = 0;
        $pdo = db();
        foreach ($t['titulos'] as $x) {
            if ($x['acao'] === 'bloqueado') { $ficaram++; $res[] = $x['codigo'] . ': NÃO excluído — já tem baixa no Omie (' . $x['status_omie'] . ')'; continue; }
            if ($x['acao'] !== 'excluir') continue;
            try { $r = (defined('CV_OMIE_FAKE') && CV_OMIE_FAKE) ? ['ok' => true, 'msg' => 'excluído (simulação local)'] : OmieEnvio::excluir($t['empresa'], $x['tipo'], $x['codigo']); }
            catch (Throwable $e) { $r = ['ok' => false, 'msg' => 'erro ao excluir no Omie: ' . $e->getMessage()]; }
            if (!$r['ok']) $ficaram++;
            $res[] = $x['codigo'] . ': ' . $r['msg'];
            if ($r['ok']) $pdo->prepare("UPDATE cf_lancamentos SET status = 'removido', exportacao_id = NULL, omie_id = NULL WHERE codigo_integracao = ? AND capa_id IN (" . implode(',', array_map('intval', $t['capas'])) . ')')->execute([$x['codigo']]);
        }
        // capas desta venda: sem nada que tenha ficado no Omie → descartadas; a etapa 3 volta a rascunho (descancelar = gerar de novo)
        if ($t['capas'] && !$ficaram) {
            $pdo->exec("UPDATE cf_capas SET status = 'descartada' WHERE id IN (" . implode(',', array_map('intval', $t['capas'])) . ") AND status IN ('revisao','confirmada')");
            $pdo->prepare("UPDATE cv_vendas SET capa_id = NULL, e3_status = 'rascunho' WHERE id = ?")->execute([$id]);
        }
        $pdo->prepare("UPDATE cv_vendas SET cancelada_de = status, status = 'cancelada', rev = rev + 1, atualizado_por = ?, atualizado_em = NOW() WHERE id = ?")->execute([(int)$u['id'], $id]);
        cv_hist($id, 0, 'cancelar', 'Venda', $atual['status'], 'cancelada — ' . $motivo . ($res ? ' · Omie: ' . implode('; ', $res) : '') . ($t['capas'] && !$ficaram ? ' · capas #' . implode(', #', $t['capas']) . ' descartadas' : ''), $u);
        $resp(['ok' => true, 'resultado' => $res, 'ficaram' => $ficaram]);
    }

    $resp(['ok' => false, 'erros' => ['ação desconhecida']], 400);
} catch (Throwable $e) {
    $resp(['ok' => false, 'erros' => [$e->getMessage()]], 200);
}
