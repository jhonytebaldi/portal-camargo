<?php
/* =====================================================================
   capa-financeira/vendas/_vendas.php — acesso a dados do formulário da
   capa de venda (cv_*). Usado pelas telas, pela API e pelo .xlsx.
   Permissão: mesma ferramenta da Capa Financeira ('capa-financeira').
   ===================================================================== */
declare(strict_types=1);
require_once dirname(__DIR__) . '/_comum.php';
require_once __DIR__ . '/lib/Venda.php';
require_once __DIR__ . '/lib/OmieCompradores.php';

/** venda + compradores; null se não existe */
function cv_carregar(int $id): ?array
{
    $st = db()->prepare('SELECT v.*, uc.nome AS criado_nome, ua.nome AS atualizado_nome, u1.nome AS e1_nome
                         FROM cv_vendas v LEFT JOIN users uc ON uc.id = v.criado_por LEFT JOIN users ua ON ua.id = v.atualizado_por
                         LEFT JOIN users u1 ON u1.id = v.e1_por WHERE v.id = ?');
    $st->execute([$id]);
    $v = $st->fetch();
    if (!$v) return null;
    foreach (['valor_contrato', 'valor_bonus', 'documentacao', 'descontos', 'pct_corretores', 'pct_fundo', 'pct_nf', 'pct_nf_bonus', 'brinde', 'cesta'] as $k)
        $v[$k] = $v[$k] === null ? null : (float)$v[$k];
    $st = db()->prepare('SELECT * FROM cv_compradores WHERE venda_id = ? ORDER BY ordem, id');
    $st->execute([$id]);
    $v['compradores'] = array_map(function ($c) {
        $c['omie_codigo'] = $c['omie_codigo'] !== null ? (int)$c['omie_codigo'] : null;
        return $c;
    }, $st->fetchAll());
    return $v;
}

function cv_hist(int $vendaId, int $etapa, string $acao, ?string $campo, ?string $antes, ?string $depois, array $u): void
{
    db()->prepare('INSERT INTO cv_historico (venda_id, etapa, acao, campo, antes, depois, user_id, user_nome) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$vendaId, $etapa, $acao, $campo, $antes, $depois, (int)$u['id'], (string)$u['nome']]);
}

function cv_historico(int $vendaId, int $limite = 300): array
{
    $st = db()->prepare('SELECT * FROM cv_historico WHERE venda_id = ? ORDER BY id DESC LIMIT ' . $limite);
    $st->execute([$vendaId]);
    return $st->fetchAll();
}

/** construtoras conhecidas: capas anteriores (cf_capas + cv_vendas) e cadastros PJ do Omie. chave → [nome, origem] */
function cv_construtoras(): array
{
    $out = [];
    $add = function (string $nome, string $origem, int $n = 1) use (&$out) {
        $nome = Venda::texto($nome); $k = Venda::chave($nome);
        if ($k === '') return;
        if (!isset($out[$k])) $out[$k] = ['nome' => $nome, 'origem' => $origem, 'n' => 0];
        $out[$k]['n'] += $n;
    };
    foreach (db()->query("SELECT construtora, COUNT(*) n FROM cf_capas WHERE construtora <> '' AND status <> 'descartada' GROUP BY construtora") as $r) $add($r['construtora'], 'capas', (int)$r['n']);
    foreach (db()->query("SELECT construtora, COUNT(*) n FROM cv_vendas WHERE construtora <> '' AND status <> 'cancelada' AND e1_status <> 'rascunho' GROUP BY construtora") as $r) $add($r['construtora'], 'capas', (int)$r['n']);
    // Omie: só pessoa jurídica (CNPJ) — a lista de clientes tem também todos os compradores pessoa física
    foreach (['vertical', 'camargo'] as $emp) {
        $f = (defined('CF_DATA_DIR') ? CF_DATA_DIR : dirname(__DIR__, 3) . '/capa-dados') . "/omie-cache/cadastros-$emp.json";
        $j = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        foreach (($j['por_doc'] ?? []) as $doc => $regs) {
            if (strlen((string)$doc) !== 14) continue;
            foreach ($regs as $r) if (($r['inativo'] ?? 'N') !== 'S') $add((string)$r['razao_social'], 'omie', 0);
        }
    }
    uasort($out, fn($a, $b) => [$b['origem'] === 'capas', $b['n'], $a['nome']] <=> [$a['origem'] === 'capas', $a['n'], $b['nome']]);
    return $out;
}

/** outras vendas/capas com o mesmo código de imóvel (aviso de possível duplicidade) */
function cv_mesmo_cod(string $cod, string $unidade, int $vendaId): array
{
    if ($cod === '') return [];
    $msgs = [];
    $st = db()->prepare("SELECT id, unidade FROM cv_vendas WHERE cod_imovel = ? AND id <> ? AND status <> 'cancelada'");
    $st->execute([$cod, $vendaId]);
    foreach ($st->fetchAll() as $r) {
        $mesmaUn = Venda::chave($r['unidade']) === Venda::chave($unidade);
        $msgs[] = 'Já existe a venda #' . $r['id'] . ' com o COD ' . $cod . ($mesmaUn ? ' e a MESMA unidade — possível duplicidade' : ' (unidade ' . $r['unidade'] . ')') . '.';
    }
    $st = db()->prepare("SELECT id, unidade, cliente FROM cf_capas WHERE cod = ? AND status IN ('revisao','confirmada')");
    $st->execute([$cod]);
    foreach ($st->fetchAll() as $r) $msgs[] = 'Capa enviada #' . $r['id'] . ' (' . $r['cliente'] . ') tem o mesmo COD ' . $cod . ' — confira se não é a mesma venda.';
    return $msgs;
}

/** sugestão da Busca de imóveis pelo código (NUNCA pré-preenche: só mostra) */
function cv_sugestao_cod(string $cod): ?array
{
    if ($cod === '') return null;
    $f = dirname(__DIR__, 3) . '/dados-imoveis/imoveis.json';   // mesmo arquivo que a Busca lê (busca/config.php → ARQ_MERGE)
    if (!is_file($f)) return null;
    static $base = null;
    if ($base === null) { $j = json_decode((string)file_get_contents($f), true); $base = $j['imoveis'] ?? []; }
    foreach ($base as $x) if ((string)($x['c'] ?? '') === $cod) {
        return ['construtora' => trim((string)($x['prop'] ?? '')), 'endereco' => trim((string)($x['e'] ?? '')), 'bairro' => trim((string)($x['b'] ?? '')),
                'titulo' => trim((string)($x['ti'] ?? '')), 'tipo' => trim((string)($x['t'] ?? ''))];
    }
    return null;
}

/** contexto da validação (construtoras conhecidas + mesmo COD) */
function cv_contexto(array $v, int $vendaId): array
{
    return ['construtoras' => cv_construtoras(), 'mesmo_cod' => cv_mesmo_cod((string)$v['cod_imovel'], (string)$v['unidade'], $vendaId)];
}

/**
 * Grava a etapa 1 (cria a venda se $id = 0). Registra cada campo alterado no histórico.
 * $rev = revisão que a tela carregou (trava otimista: se outra pessoa gravou depois, recusa).
 * @return array{id:int, rev:int, mudou:int}
 */
function cv_gravar_e1(int $id, int $rev, array $v, array $comp, array $u): array
{
    $pdo = db();
    $pdo->beginTransaction();
    try {
        if ($id) {
            $st = $pdo->prepare('SELECT rev FROM cv_vendas WHERE id = ? FOR UPDATE');
            $st->execute([$id]);
            $atual = $st->fetchColumn();
            if ($atual === false) throw new RuntimeException('Venda não encontrada.');
            if ((int)$atual !== $rev) throw new RuntimeException('Esta venda foi alterada por outra pessoa enquanto você editava. Recarregue a página (suas mudanças não foram gravadas).');
            $antes = cv_carregar($id);
        } else {
            $pdo->prepare('INSERT INTO cv_vendas (criado_por, atualizado_por) VALUES (?, ?)')->execute([(int)$u['id'], (int)$u['id']]);
            $id = (int)$pdo->lastInsertId();
            $antes = null;
            cv_hist($id, 1, 'criar', null, null, null, $u);
        }
        $cols = array_keys(Venda::CAMPOS_E1);
        $set = implode(', ', array_map(fn($c) => "$c = ?", $cols));
        $args = array_map(fn($c) => $v[$c] ?? null, $cols);
        $pdo->prepare("UPDATE cv_vendas SET $set, rev = rev + 1, atualizado_por = ?, atualizado_em = NOW() WHERE id = ?")->execute([...$args, (int)$u['id'], $id]);

        // compradores: reaproveita o vínculo com o Omie quando o documento não mudou
        $velhos = $antes['compradores'] ?? [];
        $porDoc = [];
        foreach ($velhos as $c) $porDoc[$c['documento']] = $c;
        $pdo->prepare('DELETE FROM cv_compradores WHERE venda_id = ?')->execute([$id]);
        $ins = $pdo->prepare('INSERT INTO cv_compradores (venda_id, ordem, nome, documento, email, nascimento, omie_codigo, omie_nome, omie_status, omie_em) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach ($comp as $i => $c) {
            $o = $porDoc[$c['documento']] ?? null;
            $ins->execute([$id, $i + 1, $c['nome'], $c['documento'], $c['email'], $c['nascimento'],
                           $o['omie_codigo'] ?? null, $o['omie_nome'] ?? null, $o['omie_status'] ?? null, $o['omie_em'] ?? null]);
        }

        $dif = $antes ? Venda::diferencas($antes, $v, $velhos, $comp) : Venda::diferencas(Venda::PADROES, $v, [], $comp);
        foreach ($dif as [$campo, $a, $d]) cv_hist($id, 1, $antes ? 'alterar' : 'preencher', $campo, $a, $d, $u);
        $novoRev = (int)$pdo->query('SELECT rev FROM cv_vendas WHERE id = ' . $id)->fetchColumn();
        $pdo->commit();
        return ['id' => $id, 'rev' => $novoRev, 'mudou' => count($dif)];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/** rótulo e cor do status de uma etapa */
function cv_etapa_badge(string $st): string
{
    $m = ['rascunho' => ['rascunho', 'cf-st-revisao'], 'concluida' => ['concluída', 'cf-st-confirmada'], 'conferida' => ['conferida', 'cf-st-confirmada']];
    [$t, $c] = $m[$st] ?? [$st, ''];
    return '<span class="cf-status ' . $c . '">' . h($t) . '</span>';
}
