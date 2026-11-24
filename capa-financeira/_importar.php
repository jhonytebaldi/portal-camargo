<?php
/* =====================================================================
   capa-financeira/_importar.php — lê uma capa (.xlsx) e grava capa +
   lançamentos em "revisão", cruzando com a versão anterior (diff) e com
   duplicatas. Usado pelo upload manual (upload.php) e pelo formulário de
   vendas (vendas/), que gera a planilha e importa por aqui — assim as duas
   entradas passam pelas MESMAS regras do leitor de capas.
   $anteriorId: versão anterior explícita (vendas); null = procura pelo COD (upload).
   Lança RuntimeException com mensagem para o usuário.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';

/** os dois nomes de cliente parecem ser da mesma venda? Basta UM comprador em comum: nome igual, grafia parecida,
    ou um nome contido no outro (ex.: "ANDRE FRANCO" × "ANDRE RODOLFO FRANCO"). Sobrenome em comum não conta. */
function cf_mesmo_cliente(string $a, string $b): bool
{
    if (CapaParser::key($a) === '' || CapaParser::key($b) === '') return true;   // sem nome não dá para comparar: não trava
    $pessoas = fn(string $s) => array_values(array_filter(array_map('trim', preg_split('/\s+E\s+|[,;\/]/u', CapaParser::key($s)) ?: [])));
    $tok = fn(string $k) => array_values(array_unique(array_filter(preg_split('/[^A-Z0-9]+/', $k) ?: [], fn($t) => strlen($t) >= 3 && !in_array($t, ['DOS', 'DAS', 'LTDA'], true))));
    foreach ($pessoas($a) as $pa) foreach ($pessoas($b) as $pb) {
        if ($pa === $pb || CapaParser::grafiaParecida($pa, $pb)) return true;
        $ta = $tok($pa); $tb = $tok($pb);
        $menor = min(count($ta), count($tb));
        if ($menor >= 2 && count(array_intersect($ta, $tb)) === $menor && $ta[0] === $tb[0]) return true;
    }
    return false;
}
/** capa já existente (em revisão/confirmada) com o MESMO COD e cliente DIFERENTE — COD copiado de outra planilha? null = não há conflito */
function cf_cod_outro_cliente(PDO $pdo, array $capa): ?array
{
    if (($capa['cod'] ?? null) === null || $capa['cod'] === '') return null;
    $st = $pdo->prepare("SELECT id, versao, cod, cliente, construtora, status, criado_em FROM cf_capas WHERE cod = ? AND status IN ('revisao','confirmada') ORDER BY versao DESC, id DESC LIMIT 1");
    $st->execute([(string)$capa['cod']]);
    $a = $st->fetch();
    if (!$a) return null;
    return cf_mesmo_cliente((string)$a['cliente'], (string)($capa['cliente'] ?? '')) ? null : $a;
}

/** Importa UM arquivo dentro de um envio em lote (vários arquivos do computador ou escolhidos no Drive). Só importa quando não há
    nada para decidir: COD de venda do formulário ou COD que já está em outra capa ficam de fora, com o motivo e o que fazer.
    Apaga $destino quando não importa. @return array{st:string,msg:string,id?:int}  st = ok | sozinha | erro */
function cf_importar_item_lote(PDO $pdo, string $destino, string $empresa, string $nome, array $u): array
{
    try {
        $capaUp = cf_parser()->parse($destino)['capa'];
        $codUp = $capaUp['cod'] ?? null;
        if ($codUp !== null && $codUp !== '') {
            $q = $pdo->prepare("SELECT COUNT(*) FROM cv_vendas WHERE cod_imovel = ? AND status <> 'cancelada'"); $q->execute([(string)$codUp]);
            if ((int)$q->fetchColumn() > 0) { @unlink($destino); return ['st' => 'sozinha', 'msg' => 'COD ' . $codUp . ' é de uma venda feita pelo formulário. Envie este arquivo sozinho (campo de arquivo) para ver o aviso e decidir.']; }
            // já existe capa com este COD: num lote a ordem dos arquivos é arbitrária (capa × aditivo), então a versão nova é decidida uma a uma
            $q = $pdo->prepare("SELECT id, cliente FROM cf_capas WHERE cod = ? AND status IN ('revisao','confirmada') ORDER BY id DESC LIMIT 1"); $q->execute([(string)$codUp]);
            if ($ex = $q->fetch()) {
                @unlink($destino);
                $outro = !cf_mesmo_cliente((string)$ex['cliente'], (string)($capaUp['cliente'] ?? ''));
                return ['st' => 'sozinha', 'msg' => 'COD ' . $codUp . ' já está na capa #' . (int)$ex['id'] . ' (' . $ex['cliente'] . ')' . ($outro ? ', de OUTRO cliente — confira o COD na planilha.' : '. Se for aditivo/nova versão, envie este arquivo sozinho, depois da capa original; se o COD estiver errado, corrija na planilha.')];
            }
        }
        $id = cf_importar_capa($destino, $empresa, $nome, $u);
        $q = $pdo->prepare('SELECT cliente, cod FROM cf_capas WHERE id = ?'); $q->execute([$id]); $c = $q->fetch();
        return ['st' => 'ok', 'id' => $id, 'msg' => ($c['cod'] ? 'COD ' . $c['cod'] : 'sem COD') . ' · ' . $c['cliente']];
    } catch (Throwable $e) {
        @unlink($destino);
        return ['st' => 'erro', 'msg' => $e->getMessage() . ' Confira se o arquivo é uma capa no modelo e tente de novo.'];
    }
}

/** $flagsExtras: avisos da capa acrescentados por quem chama (ex.: COD repetido em outra venda, importada separada). */
function cf_importar_capa(string $destino, string $empresa, string $nomeOrig, array $u, ?int $anteriorId = null, bool $procurarPorCod = true, array $flagsExtras = []): int
{
    $pdo = db();
    /* ---- parse ---- */
    try { $res = cf_parser()->parse($destino); }
    catch (Throwable $e) { throw new RuntimeException('Não consegui ler a planilha: ' . $e->getMessage()); }
    $capa = $res['capa'];
    $capaFlags = array_merge($res['capa_flags'], $flagsExtras);
    $condIgnorar = array_flip((array)cf_config('cond_ignorar', []));   // textos do bloco de corretores que não são condição (decidido em "Resolver em lote")

    /* ---- arquivo idêntico já enviado? ---- */
    $st = $pdo->prepare('SELECT id, status, criado_em FROM cf_capas WHERE sha256 = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$res['sha256']]);
    if ($ig = $st->fetch()) $capaFlags[] = "ARQUIVO_IDENTICO_JA_ENVIADO (capa #{$ig['id']}, {$ig['status']}, {$ig['criado_em']})";

    /* ---- versão anterior da mesma capa (mesmo COD) ---- */
    $anterior = null; $versao = 1;
    if ($anteriorId) {
        $st = $pdo->prepare('SELECT * FROM cf_capas WHERE id = ?');
        $st->execute([$anteriorId]);
        $anterior = $st->fetch() ?: null;
        if ($anterior && !in_array($anterior['status'], ['revisao', 'confirmada'], true)) $anterior = null;
        if ($anterior) $versao = (int)$anterior['versao'] + 1;
    } elseif ($procurarPorCod && $capa['cod'] !== null) {
        $st = $pdo->prepare("SELECT * FROM cf_capas WHERE cod = ? AND status IN ('revisao','confirmada') ORDER BY versao DESC, id DESC LIMIT 1");
        $st->execute([(string)$capa['cod']]);
        $anterior = $st->fetch() ?: null;
        if ($anterior) $versao = (int)$anterior['versao'] + 1;
    }

    /* ---- pessoas (resolução exata) ---- */
    $pessoas = cf_pessoas(false);   // desligados também: quem saiu ainda recebe parcelas

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare('INSERT INTO cf_capas (cod, versao, empresa, cliente, compradores, construtora, bairro, unidade, data_venda, valor_contrato, vgv, valor_bonus, obs_capa, obs_final,
            arquivo_nome, arquivo_path, sha256, capa_flags, total_pagar, total_receber, status, capa_anterior_id, enviado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'revisao\',?,?)');
        $st->execute([
            $capa['cod'] !== null ? (string)$capa['cod'] : null, $versao, $empresa, $capa['cliente'],
            !empty($capa['compradores']) ? json_encode($capa['compradores'], JSON_UNESCAPED_UNICODE) : null,
            $capa['construtora'], $capa['bairro'], $capa['unidade'],
            $capa['data_venda'], $capa['valor_contrato'], $capa['vgv'], $capa['valor_bonus'], $capa['obs_c8'], $res['observacao_final'] ?? null,
            $nomeOrig, $destino, $res['sha256'], json_encode($capaFlags, JSON_UNESCAPED_UNICODE),
            $res['total_pagar'] ?? 0, $res['total_receber'] ?? 0, $anterior['id'] ?? null, $u['id'],
        ]);
        $capaId = (int)$pdo->lastInsertId();

        // linhas da versão anterior (para diff) e de outras capas (possível duplicata)
        $antLinhas = [];
        if ($anterior) {
            $q = $pdo->prepare("SELECT * FROM cf_lancamentos WHERE capa_id = ? AND status <> 'removido'");
            $q->execute([$anterior['id']]);
            foreach ($q->fetchAll() as $l) $antLinhas[$l['chave_estavel']] = $l;
        }
        // possível duplicata = mesma pessoa, valor e data repetidos DENTRO desta capa (entre capas os valores se repetem por natureza)
        $vistosCapa = [];

        $ins = $pdo->prepare('INSERT INTO cf_lancamentos (capa_id, linha_xlsx, tipo, ordinal, chave_estavel, chave_exata, data_raw, cf_raw, favorecido_hist,
            funcao_raw, natureza_raw, clientes_hist, construtora_hist, unidade, status_imovel, data_venda_hist, col_g, prefixo, valor, parcela, total_parcelas,
            rotulo_fluxo, flags, data_prevista, pessoa_id, funcao, natureza, categoria, nota_fiscal, condicao, chave_pix, status, dup_tipo, dup_de)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,\'revisao\',?,?)');

        $ordinais = [];
        $vistos = [];
        foreach ($res['linhas'] as $l) {
            $tipo = $l['tipo'] === 'PAGAR' ? 'P' : 'R';
            $gk = $tipo . '|' . CapaParser::key($l['cf']) . '|' . ($l['funcao'] ?? '') . '|' . ($l['natureza'] ?? '');
            $ordinais[$gk] = ($ordinais[$gk] ?? 0) + 1;
            $flags = $l['flags'];
            $pessoaId = $tipo === 'P' ? cf_resolver_pessoa($l['cf'], $pessoas) : null;
            if ($tipo === 'P' && $pessoaId === null) $flags[] = 'PESSOA_NAO_IDENTIFICADA';
            if ($condIgnorar) $flags = array_values(array_filter($flags, fn($f) => !(preg_match("/^CONDICAO_BLOCO_FORA_DO_DICIONARIO \\('(.*?)' no bloco/u", $f, $mm) && isset($condIgnorar[CapaParser::key($mm[1])]))));
            // diff com a versão anterior
            $dupTipo = null; $dupDe = null;
            if ($anterior) {
                $a = $antLinhas[$l['chave_estavel']] ?? null;
                if (!$a) {
                    // 2ª tentativa: mesma pessoa, função e valor (mudou só natureza/ordem)
                    foreach ($antLinhas as $cand) {
                        if (isset($vistos[$cand['id']])) continue;
                        if ($cand['tipo'] === $tipo && CapaParser::key($cand['cf_raw']) === CapaParser::key($l['cf']) && ($cand['funcao_raw'] ?? '') === ($l['funcao'] ?? '')
                            && abs((float)$cand['valor'] - (float)$l['valor']) < 0.005) { $a = $cand; break; }
                    }
                }
                if ($a) { $dupDe = (int)$a['id']; $dupTipo = ($a['chave_exata'] === $l['chave_exata']) ? 'IGUAL' : 'ALTERADA'; $vistos[$a['id']] = true; }
                else $dupTipo = 'NOVA';
            }
            $kd = $tipo . '|' . CapaParser::key($l['cf']) . '|' . number_format((float)$l['valor'], 2, '.', '') . '|' . ($l['data'] ?? '');
            if (isset($vistosCapa[$kd])) { if ($dupTipo === null) $dupTipo = 'POSSIVEL_DUPLICATA'; $flags[] = "GRAVE:POSSIVEL_DUPLICATA (mesmo favorecido, valor e data na linha {$vistosCapa[$kd]} desta capa)"; }
            else $vistosCapa[$kd] = $l['linha_xlsx'];
            $categoria = cf_categoria($tipo, $l['funcao'], $l['natureza'], $l['status_imovel']);
            $ins->execute([
                $capaId, $l['linha_xlsx'], $tipo, $ordinais[$gk], $l['chave_estavel'], $l['chave_exata'], $l['data_raw'], $l['cf'], $l['favorecido_hist'],
                $l['funcao'], $l['natureza'], $l['clientes_hist'], $l['construtora_hist'], $l['unidade'], $l['status_imovel'], $l['data_venda_hist'],
                $l['col_g'], $l['prefixo'], $l['valor'], $l['parcela'] ?? null, $l['total_parcelas'] ?? null, $l['rotulo_fluxo'] ?? null,
                json_encode($flags, JSON_UNESCAPED_UNICODE),
                $l['data'], $pessoaId, $l['funcao'], $l['natureza'], $categoria, $l['nota_fiscal'], $l['condicao'],
                ($pessoaId && !empty($pessoas[$pessoaId]['chave_pix'])) ? $pessoas[$pessoaId]['chave_pix'] : null, $dupTipo, $dupDe,
            ]);
        }
        // linhas da versão anterior que sumiram
        if ($anterior) {
            $removidas = array_filter($antLinhas, fn($a) => !isset($vistos[$a['id']]));
            if ($removidas) {
                $capaFlags[] = 'LINHAS_REMOVIDAS_NA_NOVA_VERSAO (' . count($removidas) . ')';
                $pdo->prepare('UPDATE cf_capas SET capa_flags = ? WHERE id = ?')->execute([json_encode($capaFlags, JSON_UNESCAPED_UNICODE), $capaId]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw new RuntimeException('Erro ao gravar: ' . $e->getMessage());
    }
    return $capaId;
}
