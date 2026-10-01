<?php
/* =====================================================================
   capa-financeira/exportar.php — gera as planilhas de importação do Omie
   (Contas a Pagar / Contas a Receber) a partir dos lançamentos confirmados
   e ainda não exportados. Também: download das exportações anteriores,
   desfazer uma exportação (se a importação no Omie falhou) e a lista de
   alterações feitas depois de exportar (ajuste manual no Omie).
   GET  ?empresa=&tipo=P|R            tela
   GET  ?baixar=ID                    download do .xlsx gerado
   POST JSON acao: editar | gerar | desfazer | ajustado | verificar_clientes
                   | conferir_api | enviar_api | desfazer_linha | atualizar_catalogo
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/lib/OmieXlsx.php';
require_once __DIR__ . '/lib/Exportacao.php';
require_once __DIR__ . '/../recibos-omie/lib/OmieApi.php';
require_once __DIR__ . '/lib/OmieEnvio.php';

$u = require_tool('capa-financeira');
$pdo = db();
$empresas = array_column(cf_config('empresas', []), null, 'id');
$modelos = ['P' => __DIR__ . '/modelos/Modelo_Omie_Contas_Pagar_v1_1_5.xlsx', 'R' => __DIR__ . '/modelos/Modelo_Omie_Contas_Receber_v1_0_6.xlsx'];

/* ---------- download ---------- */
if (isset($_GET['baixar'])) {
    $st = $pdo->prepare('SELECT * FROM cf_exportacoes WHERE id = ?'); $st->execute([(int)$_GET['baixar']]);
    $e = $st->fetch();
    if (!$e || !is_file($e['arquivo_path'])) { http_response_code(404); exit('arquivo não encontrado'); }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $e['arquivo_nome'] . '"');
    header('Content-Length: ' . filesize($e['arquivo_path']));
    readfile($e['arquivo_path']); exit;
}

/** lançamentos confirmados (não exportados) da empresa/tipo, com capa e pessoa */
function cf_exp_candidatas(PDO $pdo, string $empresa, string $tipo, ?array $ids = null): array
{
    $sql = "SELECT l.*, c.cod AS capa_cod, c.cliente AS capa_cliente, c.construtora AS capa_construtora, c.bairro AS capa_bairro, c.unidade AS capa_unidade,
                   c.data_venda AS capa_data_venda, c.empresa AS capa_empresa, c.versao AS capa_versao, c.compradores AS capa_compradores
            FROM cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id
            WHERE l.status = 'confirmado' AND c.status = 'confirmada' AND c.empresa = ? AND l.tipo = ?";
    $args = [$empresa, $tipo];
    if ($ids !== null) { if (!$ids) return []; $sql .= ' AND l.id IN (' . implode(',', array_map('intval', $ids)) . ')'; }
    $sql .= ' ORDER BY c.cod, l.linha_xlsx';
    $st = $pdo->prepare($sql); $st->execute($args);
    return $st->fetchAll();
}
function cf_exp_capa(array $l): array
{
    return ['cod' => $l['capa_cod'], 'cliente' => $l['capa_cliente'], 'construtora' => $l['capa_construtora'], 'bairro' => $l['capa_bairro'],
            'unidade' => $l['capa_unidade'], 'data_venda' => $l['capa_data_venda'], 'compradores' => $l['capa_compradores'] ?? null];
}

/**
 * Confere no Omie (API) se os clientes das contas a receber existem: por CPF/CNPJ e, se não achar, pelo nome.
 * $clientes = ['texto da coluna Cliente' => 'nome do comprador (para busca por nome)']
 * Devolve [texto => ['status' => 'ok'|'so_nome'|'varios'|'nao'|'erro', 'msg' => ...]]
 */
function cf_exp_conferir_clientes(string $empresa, array $clientes): array
{
    $out = [];
    foreach ($clientes as $txt => $nome) {
        $txt = (string)$txt; $dig = preg_replace('/\D+/', '', $txt); $porDoc = in_array(strlen($dig), [11, 14], true);
        try {
            $achou = null;
            if ($porDoc) {
                try {
                    $r = OmieApi::call($empresa, 'geral/clientes/', 'ListarClientes', ['pagina' => 1, 'registros_por_pagina' => 5, 'apenas_importado_api' => 'N', 'clientesFiltro' => ['cnpj_cpf' => $txt]]);
                    foreach ($r['clientes_cadastro'] ?? [] as $c) if (preg_replace('/\D+/', '', (string)($c['cnpj_cpf'] ?? '')) === $dig) { $achou = $c; break; }
                } catch (RuntimeException $e) { if (!preg_match('/n[ãa]o existem registros|SOAP-ENV:Client-5113|n[ãa]o (foi )?(encontrad|localizad)/iu', $e->getMessage())) throw $e; }
                if ($achou) { $out[$txt] = ['status' => 'ok', 'msg' => 'cadastrado no Omie: ' . ($achou['razao_social'] ?? $achou['nome_fantasia'] ?? '') . ' (cód. ' . ($achou['codigo_cliente_omie'] ?? '?') . ')']; continue; }
            }
            // por nome (o texto da coluna, quando é nome; ou o nome do comprador, quando o texto é o CPF que não achou)
            $busca = $porDoc ? (string)$nome : $txt;
            $lista = [];
            if ($busca !== '') {
                try { $r = OmieApi::call($empresa, 'geral/clientes/', 'ListarClientes', ['pagina' => 1, 'registros_por_pagina' => 50, 'apenas_importado_api' => 'N', 'clientesFiltro' => ['razao_social' => $busca]]); $lista = $r['clientes_cadastro'] ?? []; }
                catch (RuntimeException $e) { if (!preg_match('/n[ãa]o existem registros|SOAP-ENV:Client-5113/iu', $e->getMessage())) throw $e; }   // "Não existem registros para a página" = lista vazia
                $k = CapaParser::key($busca);
                $exatos = array_values(array_filter($lista, fn($c) => CapaParser::key((string)($c['razao_social'] ?? '')) === $k));
                if ($exatos) $lista = $exatos;
            }
            if (count($lista) === 1) {
                $c = $lista[0]; $doc = preg_replace('/\D+/', '', (string)($c['cnpj_cpf'] ?? ''));
                $out[$txt] = $porDoc
                    ? ['status' => 'so_nome', 'msg' => 'CPF não está no Omie, mas existe cadastro com esse nome: ' . ($c['razao_social'] ?? '') . ' (cód. ' . ($c['codigo_cliente_omie'] ?? '?') . ($doc ? ', CPF/CNPJ ' . $c['cnpj_cpf'] : ', sem CPF') . ') — corrija o CPF no cadastro do Omie ou use o nome']
                    : ['status' => 'ok', 'msg' => 'cadastrado no Omie: ' . ($c['razao_social'] ?? '') . ' (cód. ' . ($c['codigo_cliente_omie'] ?? '?') . ($doc ? ', CPF/CNPJ ' . $c['cnpj_cpf'] : ', sem CPF') . ')'];
            } elseif (count($lista) > 1) {
                $out[$txt] = ['status' => 'varios', 'msg' => count($lista) . ' cadastros com esse nome no Omie — informe o CPF em "cliente Omie" para não dar ambiguidade'];
            } else {
                $out[$txt] = ['status' => 'nao', 'msg' => 'NÃO encontrado no Omie' . ($porDoc ? ' (nem pelo CPF nem pelo nome)' : ' (pelo nome)') . ' — cadastre o cliente com CPF antes de importar'];
            }
        } catch (Throwable $e) {
            $out[$txt] = ['status' => 'erro', 'msg' => 'não deu para conferir: ' . $e->getMessage()];
        }
    }
    return $out;
}

/** monta a linha (regras da planilha) e confere para a API; devolve [montada, conferida] */
function cf_exp_conferir_api(PDO $pdo, string $empresa, string $tipo, array $l, array $pessoas, array $emp, array $opts, array $cat, string $porQuem): array
{
    $p = $l['pessoa_id'] ? ($pessoas[(int)$l['pessoa_id']] ?? null) : null;
    $m = Exportacao::montar($l, cf_exp_capa($l), $p, $emp, $opts);
    return OmieEnvio::conferir($empresa, $tipo, $l, cf_exp_capa($l), $p, $emp, $m, $opts, $cat, $porQuem);
}
function cf_exp_log_envio(PDO $pdo, ?int $expId, array $l, string $empresa, string $tipo, string $acao, string $status, ?int $omieId, ?array $pedido, ?array $resposta, ?string $msg, int $por, bool $verificado = false): void
{
    $pdo->prepare('INSERT INTO cf_envios_omie (exportacao_id, lancamento_id, empresa, tipo, codigo_integracao, acao, status, omie_id, pedido, resposta, mensagem, verificado, por) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$expId, (int)$l['id'], $empresa, $tipo, (string)$l['codigo_integracao'], $acao, $status, $omieId,
                   $pedido !== null ? json_encode($pedido, JSON_UNESCAPED_UNICODE) : null, $resposta !== null ? json_encode($resposta, JSON_UNESCAPED_UNICODE) : null, $msg, $verificado ? 1 : 0, $por]);
}
/** exclui no Omie o título de uma linha exportada pela API e devolve a linha para 'confirmado'. @return array{ok:bool,msg:string} */
function cf_exp_desfazer_linha(PDO $pdo, array $l, string $empresa, int $por): array
{
    $tipo = $l['tipo'] === 'R' ? 'R' : 'P';
    try { $r = OmieEnvio::excluir($empresa, $tipo, (string)$l['codigo_integracao']); }
    catch (Throwable $e) { $r = ['ok' => false, 'msg' => 'erro ao excluir no Omie: ' . $e->getMessage()]; }
    cf_exp_log_envio($pdo, $l['exportacao_id'] ? (int)$l['exportacao_id'] : null, $l, $empresa, $tipo, 'excluir', $r['ok'] ? 'ok' : 'recusado', $l['omie_id'] ? (int)$l['omie_id'] : null, null, null, $r['msg'], $por);
    if ($r['ok']) $pdo->prepare("UPDATE cf_lancamentos SET status = 'confirmado', exportacao_id = NULL, omie_id = NULL, omie_erro = NULL, alteracao_pos_exportacao = 0 WHERE id = ?")->execute([(int)$l['id']]);
    return $r;
}

/* ---------- ações JSON ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $in = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $_POST['csrf'] = $in['csrf'] ?? ''; csrf_check();
    $falha = function (string $m, int $c = 400): never { http_response_code($c); exit(json_encode(['ok' => false, 'erro' => $m], JSON_UNESCAPED_UNICODE)); };
    $acao = (string)($in['acao'] ?? '');
    try {
        if ($acao === 'editar') {
            $id = (int)($in['id'] ?? 0); $campo = (string)($in['campo'] ?? ''); $valor = trim((string)($in['valor'] ?? ''));
            $st = $pdo->prepare("SELECT * FROM cf_lancamentos WHERE id = ? AND status = 'confirmado'"); $st->execute([$id]);
            if (!($l = $st->fetch())) $falha('linha não está confirmada/pendente de exportação');
            if ($campo === 'chave_pix') {
                $a = Pix::analisar($valor);
                if (!$a['valido']) $falha('chave Pix inválida: ' . $a['erro']);
                $valor = (string)($a['valor'] ?? '');
            } elseif (!in_array($campo, ['conta_corrente', 'cliente_omie', 'nota_fiscal'], true)) $falha('campo inválido');
            $pdo->prepare("UPDATE cf_lancamentos SET $campo = ? WHERE id = ?")->execute([$valor !== '' ? mb_substr($valor, 0, 60) : null, $id]);
            if ($campo === 'conta_corrente' && !empty($in['aplicar_todas'])) {
                // mesma conta em todas as linhas confirmadas do mesmo tipo/empresa ainda sem conta
                $pdo->prepare("UPDATE cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id SET l.conta_corrente = ?
                               WHERE l.status = 'confirmado' AND l.tipo = ? AND c.empresa = ? AND (l.conta_corrente IS NULL OR l.conta_corrente = '')")
                    ->execute([$valor ?: null, $l['tipo'], (string)$in['empresa']]);
            }
            exit(json_encode(['ok' => true, 'valor' => $valor]));
        }
        if ($acao === 'conta_padrao') {
            $empresa = (string)($in['empresa'] ?? ''); $tipo = ($in['tipo'] ?? 'P') === 'R' ? 'R' : 'P';
            if (!isset($empresas[$empresa])) $falha('empresa inválida');
            $valor = mb_substr(trim((string)($in['valor'] ?? '')), 0, 40);
            $emp = cf_config('empresas', []);
            foreach ($emp as &$e) if ($e['id'] === $empresa) $e[$tipo === 'P' ? 'conta_padrao_cp' : 'conta_padrao'] = $valor;
            unset($e);
            cf_config_set('empresas', $emp);
            exit(json_encode(['ok' => true, 'valor' => $valor]));
        }
        if ($acao === 'verificar_clientes') {
            $empresa = (string)($in['empresa'] ?? ''); $tipo = (string)($in['tipo'] ?? 'R');
            if (!isset($empresas[$empresa])) $falha('empresa inválida');
            if ($tipo !== 'R') $falha('só para contas a receber');
            if (!OmieApi::disponivel() || !isset(OmieApi::contas()[$empresa])) $falha('API do Omie não configurada para esta empresa');
            $ids = array_values(array_unique(array_map('intval', (array)($in['ids'] ?? []))));
            $pessoas = cf_pessoas(false); $clientes = []; $porLinha = [];
            foreach (cf_exp_candidatas($pdo, $empresa, $tipo, $ids ?: null) as $l) {
                $capa = cf_exp_capa($l);
                $m = Exportacao::montar($l, $capa, null, $empresas[$empresa], ['data_registro' => date('Y-m-d'), 'emissao' => 'venda']);
                $txt = $m['cols']['C']['s'] ?? ''; if ($txt === '') continue;
                $clientes[$txt] = Exportacao::clientePadrao((string)$capa['cliente']); $porLinha[(int)$l['id']] = $txt;
            }
            $res = cf_exp_conferir_clientes($empresa, $clientes);
            exit(json_encode(['ok' => true, 'clientes' => $res, 'linhas' => $porLinha], JSON_UNESCAPED_UNICODE));
        }
        if ($acao === 'atualizar_catalogo') {
            $empresa = (string)($in['empresa'] ?? ''); if (!isset($empresas[$empresa])) $falha('empresa inválida');
            if (!OmieApi::disponivel() || !isset(OmieApi::contas()[$empresa])) $falha('API do Omie não configurada para esta empresa');
            $cat = OmieEnvio::catalogo($empresa, true);
            exit(json_encode(['ok' => true, 'contas' => count($cat['contas']), 'categorias' => count($cat['categorias']), 'departamentos' => count($cat['departamentos']), 'projetos' => count($cat['projetos'])]));
        }
        if ($acao === 'conferir_api' || $acao === 'enviar_api') {
            $empresa = (string)($in['empresa'] ?? ''); $tipo = (string)($in['tipo'] ?? 'P');
            if (!isset($empresas[$empresa]) || !in_array($tipo, ['P', 'R'], true)) $falha('empresa/tipo inválidos — recarregue a página e tente de novo');
            if (!OmieApi::disponivel() || !isset(OmieApi::contas()[$empresa])) $falha('API do Omie não configurada para esta empresa — as chaves (app_key/app_secret) ficam no arquivo omie.php da configuração do portal; confira se existe uma entrada para "' . $empresa . '"');
            if (($resta = OmieApi::bloqueadoPor($empresa)) > 0) $falha('A API do Omie está bloqueada temporariamente para esta empresa (anti-abuso do Omie, por chamadas repetidas). Aguarde ' . ceil($resta / 60) . ' min e tente de novo — não adianta insistir antes, só prolonga o bloqueio.');
            $ids = array_values(array_unique(array_map('intval', (array)($in['ids'] ?? []))));
            if ($acao === 'enviar_api' && !$ids) $falha('nenhuma linha selecionada — marque as linhas na tabela antes de enviar');
            try { $catTeste = OmieEnvio::catalogo($empresa); } catch (Throwable $e) { $falha('Não consegui ler os cadastros do Omie (contas, categorias, departamentos): ' . $e->getMessage() . '. Se for indisponibilidade do Omie, tente em alguns minutos.'); }
            $reg = (string)($in['data_registro'] ?? date('Y-m-d')); if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reg)) $reg = date('Y-m-d');
            $opts = ['data_registro' => $reg, 'emissao' => in_array($in['emissao'] ?? '', ['venda', 'registro', 'prevista'], true) ? $in['emissao'] : 'venda'];
            $pessoas = cf_pessoas(false); $cat = OmieEnvio::catalogo($empresa);
            $linhas = cf_exp_candidatas($pdo, $empresa, $tipo, $ids ?: null);
            if ($acao === 'enviar_api' && count($linhas) !== count($ids)) $falha('alguma linha selecionada já foi exportada ou não está confirmada — recarregue a página');
            $porQuem = (string)($u['nome'] ?? $u['login'] ?? 'portal');
            if ($acao === 'conferir_api') {
                $res = [];
                foreach ($linhas as $l) { $r = cf_exp_conferir_api($pdo, $empresa, $tipo, $l, $pessoas, $empresas[$empresa], $opts, $cat, $porQuem); $res[(int)$l['id']] = ['erros' => $r['erros'], 'avisos' => $r['avisos'], 'fornecedor' => $r['pedido']['codigo_cliente_fornecedor'] ?? null, 'existente' => !empty($r['existente'])]; }
                exit(json_encode(['ok' => true, 'linhas' => $res], JSON_UNESCAPED_UNICODE));
            }
            // enviar: linha a linha; cada uma independente (o Omie não tem transação entre títulos)
            // o navegador envia em blocos de 5 (evita o 504 da Hostinger); os blocos seguintes reaproveitam o mesmo registro de envio
            $expId = (int)($in['exportacao_id'] ?? 0);
            if ($expId) { $st = $pdo->prepare("SELECT id FROM cf_exportacoes WHERE id = ? AND empresa = ? AND tipo = ? AND modo = 'api' AND status = 'gerada'"); $st->execute([$expId, $empresa, $tipo]); if (!$st->fetch()) $expId = 0; }
            if (!$expId) {
                $pdo->prepare("INSERT INTO cf_exportacoes (tipo, empresa, modo, arquivo_nome, arquivo_path, n_linhas, total, data_registro, avisos, gerado_por) VALUES (?,?,'api','(envio pela API)','',0,0,?,'[]',?)")
                    ->execute([$tipo, $empresa, $reg, $u['id']]);
                $expId = (int)$pdo->lastInsertId();
            }
            $enviadas = []; $falhas = []; $avisos = []; $total = 0.0; $parou = null;
            foreach ($linhas as $l) {
                $cod = (string)$l['codigo_integracao'];
                if ($parou) { $falhas[] = ['id' => (int)$l['id'], 'codigo' => $cod, 'msg' => 'não tentado: ' . $parou]; continue; }
                try { $r = cf_exp_conferir_api($pdo, $empresa, $tipo, $l, $pessoas, $empresas[$empresa], $opts, $cat, $porQuem); }
                catch (Throwable $e) {
                    // erro de comunicação/anti-abuso na conferência: para o lote (insistir só piora o bloqueio)
                    $parou = 'API do Omie indisponível — ' . $e->getMessage();
                    $falhas[] = ['id' => (int)$l['id'], 'codigo' => $cod, 'msg' => 'não tentado: ' . $parou]; continue;
                }
                foreach ($r['avisos'] as $a) $avisos[] = "$cod: $a";
                if (!$r['erros'] && !empty($r['existente'])) {
                    // já estava no Omie com nosso código (envio anterior que falhou na resposta): vincula em vez de reenviar
                    $omieId = (int)$r['existente']['codigo_lancamento_omie'];
                    $pdo->prepare("UPDATE cf_lancamentos SET status = 'exportado', exportacao_id = ?, omie_id = ?, omie_erro = NULL, alteracao_pos_exportacao = 0 WHERE id = ?")->execute([$expId, $omieId, (int)$l['id']]);
                    cf_exp_log_envio($pdo, $expId, $l, $empresa, $tipo, 'incluir', 'ok', $omieId, $r['pedido'], $r['existente'], 'já existia no Omie com este código — vinculado sem reenviar', (int)$u['id'], true);
                    $enviadas[] = ['id' => (int)$l['id'], 'codigo' => $cod, 'omie_id' => $omieId, 'vinculado' => true]; $total += (float)$l['valor']; continue;
                }
                if ($r['erros']) {
                    $msg = implode(' | ', $r['erros']);
                    $pdo->prepare('UPDATE cf_lancamentos SET omie_erro = ? WHERE id = ?')->execute([$msg, (int)$l['id']]);
                    cf_exp_log_envio($pdo, null, $l, $empresa, $tipo, 'incluir', 'recusado', null, $r['pedido'], null, $msg, (int)$u['id']);
                    $falhas[] = ['id' => (int)$l['id'], 'codigo' => $cod, 'msg' => $msg]; continue;
                }
                try {
                    $resp = OmieEnvio::incluir($empresa, $tipo, $r['pedido']);
                    $omieId = (int)($resp['codigo_lancamento_omie'] ?? 0);
                    if (($resp['codigo_status'] ?? '0') !== '0' || !$omieId) throw new RuntimeException('Omie respondeu: ' . ($resp['descricao_status'] ?? json_encode($resp)));
                    // confere de volta (pelo código Omie — chamada diferente da pré-checagem); se a conferência falhar por motivo temporário, o título já está lá: não é erro
                    try { $ver = OmieEnvio::consultarPorOmieId($empresa, $tipo, $omieId); } catch (Throwable $e) { $ver = null; }
                    $okVer = $ver && abs((float)($ver['valor_documento'] ?? 0) - (float)$l['valor']) < 0.005;
                    $pdo->prepare("UPDATE cf_lancamentos SET status = 'exportado', exportacao_id = ?, omie_id = ?, omie_erro = NULL, alteracao_pos_exportacao = 0 WHERE id = ?")->execute([$expId, $omieId, (int)$l['id']]);
                    cf_exp_log_envio($pdo, $expId, $l, $empresa, $tipo, 'incluir', 'ok', $omieId, $r['pedido'], $resp, $okVer ? null : 'incluído, mas a consulta de conferência não bateu', (int)$u['id'], $okVer);
                    if (!$okVer) $avisos[] = "$cod: incluído (cód. $omieId), mas a consulta de conferência não bateu — confira no Omie";
                    $enviadas[] = ['id' => (int)$l['id'], 'codigo' => $cod, 'omie_id' => $omieId]; $total += (float)$l['valor'];
                } catch (Throwable $e) {
                    $msg = 'erro no envio: ' . $e->getMessage();
                    if (OmieEnvio::erroTemporario($e->getMessage())) $parou = $e->getMessage();   // anti-abuso/indisponível: não insiste nas próximas
                    // se o Omie chegou a criar, não deixar órfão: tenta localizar pelo código de integração
                    try { $ex = $parou ? null : OmieEnvio::consultar($empresa, $tipo, $cod); } catch (Throwable $e2) { $ex = null; }
                    if ($ex && !empty($ex['codigo_lancamento_omie'])) {
                        $omieId = (int)$ex['codigo_lancamento_omie'];
                        $pdo->prepare("UPDATE cf_lancamentos SET status = 'exportado', exportacao_id = ?, omie_id = ?, omie_erro = NULL WHERE id = ?")->execute([$expId, $omieId, (int)$l['id']]);
                        cf_exp_log_envio($pdo, $expId, $l, $empresa, $tipo, 'incluir', 'ok', $omieId, $r['pedido'], $ex, 'resposta com erro, mas o título existe no Omie: ' . $e->getMessage(), (int)$u['id'], true);
                        $enviadas[] = ['id' => (int)$l['id'], 'codigo' => $cod, 'omie_id' => $omieId]; $total += (float)$l['valor'];
                        $avisos[] = "$cod: o Omie respondeu erro mas o título foi criado (cód. $omieId)"; continue;
                    }
                    $pdo->prepare('UPDATE cf_lancamentos SET omie_erro = ? WHERE id = ?')->execute([$msg, (int)$l['id']]);
                    cf_exp_log_envio($pdo, null, $l, $empresa, $tipo, 'incluir', 'erro', null, $r['pedido'], null, $msg, (int)$u['id']);
                    $falhas[] = ['id' => (int)$l['id'], 'codigo' => $cod, 'msg' => $msg];
                }
            }
            if ($parou) $avisos[] = 'Envio interrompido: ' . $parou . '. As linhas não tentadas continuam pendentes — aguarde e envie de novo (a conferência antes de enviar vai vincular o que já entrou).';
            $resumo = array_merge(array_map(fn($f) => 'ERRO ' . $f['codigo'] . ': ' . $f['msg'], $falhas), $avisos);
            // acumula no registro do envio (pode ser o 2º, 3º… bloco)
            $st = $pdo->prepare('SELECT n_linhas, total, avisos FROM cf_exportacoes WHERE id = ?'); $st->execute([$expId]); $acum = $st->fetch();
            $avAnt = json_decode((string)($acum['avisos'] ?? '[]'), true) ?: [];
            $nTot = (int)$acum['n_linhas'] + count($enviadas); $vTot = (float)$acum['total'] + $total;
            if ($nTot > 0) $pdo->prepare('UPDATE cf_exportacoes SET n_linhas = ?, total = ?, avisos = ? WHERE id = ?')->execute([$nTot, $vTot, json_encode(array_merge($avAnt, $resumo), JSON_UNESCAPED_UNICODE), $expId]);
            elseif (!empty($in['ultimo'])) { $pdo->prepare('DELETE FROM cf_exportacoes WHERE id = ?')->execute([$expId]); $expId = 0; }
            exit(json_encode(['ok' => true, 'exportacao_id' => $expId ?: null, 'enviadas' => $enviadas, 'falhas' => $falhas, 'avisos' => $avisos, 'total' => $total, 'parou' => $parou], JSON_UNESCAPED_UNICODE));
        }
        if ($acao === 'conferir_exportacao') {
            // planilha importada: confere título a título no Omie pelo código de integração
            $id = (int)($in['id'] ?? 0);
            $st = $pdo->prepare('SELECT * FROM cf_exportacoes WHERE id = ?'); $st->execute([$id]);
            if (!($ex = $st->fetch())) $falha('exportação não encontrada');
            if (!OmieApi::disponivel() || !isset(OmieApi::contas()[$ex['empresa']])) $falha('API do Omie não configurada para esta empresa');
            // em blocos (o servidor da Hostinger corta requisições longas — erro 504): o navegador chama de 8 em 8 linhas
            $offset = max(0, (int)($in['offset'] ?? 0)); $limite = min(20, max(1, (int)($in['limite'] ?? 8)));
            $q = $pdo->prepare("SELECT id, codigo_integracao, valor, data_prevista, status, omie_id FROM cf_lancamentos WHERE exportacao_id = ? ORDER BY codigo_integracao"); $q->execute([$id]);
            $todas = $q->fetchAll(); $total = count($todas);
            $conf = $offset === 0 ? [] : (json_decode((string)$ex['conferencia'], true) ?: []);
            $res = [];
            foreach (array_slice($todas, $offset, $limite) as $l) {
                try {
                    $t = OmieEnvio::consultar((string)$ex['empresa'], $ex['tipo'] === 'R' ? 'R' : 'P', (string)$l['codigo_integracao']);
                    if (!$t) $res[] = ['id' => (int)$l['id'], 'codigo' => $l['codigo_integracao'], 'ok' => false, 'msg' => 'NÃO está no Omie (nenhum título com este código de integração)'];
                    else {
                        $vOmie = (float)$t['valor_documento']; $vNosso = (float)$l['valor']; $dOmie = (string)$t['data_vencimento']; $dNosso = OmieEnvio::br((string)$l['data_prevista']);
                        $dif = [];
                        if (abs($vOmie - $vNosso) >= 0.005) $dif[] = 'valor: portal R$ ' . number_format($vNosso, 2, ',', '.') . ' × Omie R$ ' . number_format($vOmie, 2, ',', '.');
                        if ($dOmie !== $dNosso) $dif[] = "vencimento: portal $dNosso × Omie $dOmie";
                        $bate = !$dif;
                        $res[] = ['id' => (int)$l['id'], 'codigo' => $l['codigo_integracao'], 'ok' => $bate, 'existe' => true, 'omie_id' => (int)$t['codigo_lancamento_omie'],
                                  'msg' => $bate ? 'ok — cód. Omie ' . $t['codigo_lancamento_omie'] . ', R$ ' . number_format($vOmie, 2, ',', '.') . ' venc. ' . $dOmie . ', status ' . ($t['status_titulo'] ?? '')
                                                 : 'está no Omie (cód. ' . $t['codigo_lancamento_omie'] . ', status ' . ($t['status_titulo'] ?? '') . ') mas com dados diferentes do portal — ' . implode('; ', $dif) . '. Se o Omie está certo, vincule; se o portal está certo, ajuste no Omie'];
                        if ($bate && !$l['omie_id']) $pdo->prepare('UPDATE cf_lancamentos SET omie_id = ? WHERE id = ?')->execute([(int)$t['codigo_lancamento_omie'], (int)$l['id']]);
                    }
                } catch (Throwable $e) { $res[] = ['id' => (int)$l['id'], 'codigo' => $l['codigo_integracao'], 'ok' => false, 'erro_api' => true, 'msg' => 'não deu para conferir (API do Omie): ' . $e->getMessage() . ' — tente de novo mais tarde']; }
            }
            foreach ($res as $r) $conf[$r['codigo']] = $r;
            $fim = $offset + $limite >= $total;
            $pdo->prepare('UPDATE cf_exportacoes SET conferencia = ?, conferido_em = ' . ($fim ? 'NOW()' : 'conferido_em') . ' WHERE id = ?')->execute([json_encode(array_values($conf), JSON_UNESCAPED_UNICODE), $id]);
            exit(json_encode(['ok' => true, 'linhas' => $res, 'offset' => $offset, 'total' => $total, 'fim' => $fim, 'conferencia' => array_values($conf)], JSON_UNESCAPED_UNICODE));
        }
        if ($acao === 'corrigir_pix') {
            // títulos a pagar de uma exportação por planilha que ficaram como "PIX QR-Code": corrige N por vez, em ordem, pulando os já tratados
            $id = (int)($in['id'] ?? 0); $qtd = min(20, max(1, (int)($in['qtd'] ?? 5)));
            $st = $pdo->prepare("SELECT * FROM cf_exportacoes WHERE id = ? AND tipo = 'P'"); $st->execute([$id]);
            if (!($ex = $st->fetch())) $falha('exportação não encontrada ou não é de contas a pagar');
            $emp = (string)$ex['empresa'];
            if (!OmieApi::disponivel() || !isset(OmieApi::contas()[$emp])) $falha('API do Omie não configurada para esta empresa — as chaves ficam no omie.php da configuração do portal');
            if (($resta = OmieApi::bloqueadoPor($emp)) > 0) $falha('API do Omie bloqueada temporariamente (anti-abuso). Aguarde ' . ceil($resta / 60) . ' min.');
            $q = $pdo->prepare("SELECT l.* FROM cf_lancamentos l WHERE l.exportacao_id = ? AND l.tipo = 'P' AND l.status = 'exportado'
                                AND NOT EXISTS (SELECT 1 FROM cf_envios_omie e WHERE e.lancamento_id = l.id AND e.acao = 'alterar' AND e.status = 'ok') ORDER BY l.codigo_integracao LIMIT " . $qtd); $q->execute([$id]);
            $linhas = $q->fetchAll(); $res = [];
            foreach ($linhas as $l) {
                $cod = (string)$l['codigo_integracao'];
                try { $r = OmieEnvio::corrigirPix($emp, $cod, (string)($l['chave_pix'] ?? '')); }
                catch (Throwable $e) { $r = ['status' => 'erro', 'msg' => 'erro na API do Omie: ' . $e->getMessage() . (OmieEnvio::erroTemporario($e->getMessage()) ? ' — aguarde alguns minutos e tente de novo' : ''), 'antes' => null, 'depois' => null]; }
                $stLog = in_array($r['status'], ['corrigido', 'ja_ok'], true) ? 'ok' : ($r['status'] === 'pulado' ? 'recusado' : 'erro');
                cf_exp_log_envio($pdo, $id, $l, $emp, 'P', 'alterar', $stLog, $l['omie_id'] ? (int)$l['omie_id'] : null, $r['antes'] !== null ? ['cnab_antes' => $r['antes']] : null, $r['depois'] !== null ? ['cnab_depois' => $r['depois']] : null, $r['msg'], (int)$u['id'], $r['status'] === 'corrigido');
                $res[] = ['codigo' => $cod, 'status' => $r['status'], 'msg' => $r['msg']];
                if ($r['status'] === 'erro' && OmieEnvio::erroTemporario($r['msg'])) break;
            }
            $q = $pdo->prepare("SELECT COUNT(*) FROM cf_lancamentos l WHERE l.exportacao_id = ? AND l.tipo = 'P' AND l.status = 'exportado' AND NOT EXISTS (SELECT 1 FROM cf_envios_omie e WHERE e.lancamento_id = l.id AND e.acao = 'alterar' AND e.status = 'ok')"); $q->execute([$id]);
            exit(json_encode(['ok' => true, 'linhas' => $res, 'restam' => (int)$q->fetchColumn()], JSON_UNESCAPED_UNICODE));
        }
        if ($acao === 'vincular_linha') {
            // título existe no Omie com nosso código mas com valor/vencimento diferentes: o usuário decide que o Omie está certo → só guarda o vínculo
            $id = (int)($in['id'] ?? 0); $omieId = (int)($in['omie_id'] ?? 0);
            if (!$id || !$omieId) $falha('dados incompletos');
            $pdo->prepare("UPDATE cf_lancamentos SET omie_id = ?, omie_erro = NULL WHERE id = ? AND status = 'exportado'")->execute([$omieId, $id]);
            exit(json_encode(['ok' => true]));
        }
        if ($acao === 'devolver_linha') {
            // linha "exportada" por planilha que não chegou no Omie: volta para confirmado para enviar de novo (pela API ou nova planilha)
            $id = (int)($in['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM cf_lancamentos WHERE id = ? AND status = 'exportado'"); $st->execute([$id]);
            if (!($l = $st->fetch())) $falha('linha não está como exportada');
            $pdo->prepare("UPDATE cf_lancamentos SET status = 'confirmado', exportacao_id = NULL, omie_id = NULL, omie_erro = ?, alteracao_pos_exportacao = 0 WHERE id = ?")
                ->execute(['devolvida em ' . date('d/m/Y H:i') . ' — não estava no Omie após a importação da planilha', $id]);
            cf_exp_log_envio($pdo, $l['exportacao_id'] ? (int)$l['exportacao_id'] : null, $l, (string)($in['empresa'] ?? ''), $l['tipo'] === 'R' ? 'R' : 'P', 'incluir', 'recusado', null, null, null, 'linha devolvida para confirmado: título não encontrado no Omie após importação da planilha', (int)$u['id']);
            exit(json_encode(['ok' => true]));
        }
        if ($acao === 'desfazer_linha') {
            $empresa = (string)($in['empresa'] ?? ''); if (!isset($empresas[$empresa])) $falha('empresa inválida');
            $st = $pdo->prepare("SELECT l.* FROM cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id WHERE l.id = ? AND l.status = 'exportado' AND l.omie_id IS NOT NULL AND c.empresa = ?"); $st->execute([(int)($in['id'] ?? 0), $empresa]);
            if (!($l = $st->fetch())) $falha('linha não foi enviada pela API (ou já foi desfeita)');
            $r = cf_exp_desfazer_linha($pdo, $l, $empresa, (int)$u['id']);
            if (!$r['ok']) $falha($r['msg']);
            exit(json_encode(['ok' => true, 'msg' => $r['msg']], JSON_UNESCAPED_UNICODE));
        }
        if ($acao === 'gerar') {
            $empresa = (string)($in['empresa'] ?? ''); $tipo = (string)($in['tipo'] ?? 'P');
            if (!isset($empresas[$empresa]) || !in_array($tipo, ['P', 'R'], true)) $falha('empresa/tipo inválidos');
            $ids = array_values(array_unique(array_map('intval', (array)($in['ids'] ?? []))));
            if (!$ids) $falha('nenhuma linha selecionada');
            $reg = (string)($in['data_registro'] ?? date('Y-m-d'));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $reg)) $reg = date('Y-m-d');
            $opts = ['data_registro' => $reg, 'emissao' => in_array($in['emissao'] ?? '', ['venda', 'registro', 'prevista'], true) ? $in['emissao'] : 'venda'];
            $pessoas = cf_pessoas(false);
            $linhas = cf_exp_candidatas($pdo, $empresa, $tipo, $ids);
            if (count($linhas) !== count($ids)) $falha('alguma linha selecionada já foi exportada ou não está confirmada — recarregue a página');
            $rows = []; $erros = []; $avisos = []; $avisosPor = []; $total = 0.0; $clientes = [];
            foreach ($linhas as $l) {
                $m = Exportacao::montar($l, cf_exp_capa($l), $l['pessoa_id'] ? ($pessoas[(int)$l['pessoa_id']] ?? null) : null, $empresas[$empresa], $opts);
                if ($m['erros']) { $erros[] = $l['codigo_integracao'] . ': ' . implode('; ', $m['erros']); continue; }
                foreach ($m['avisos'] as $a) $avisosPor[$a][] = $l['codigo_integracao'];
                if ($tipo === 'R') $clientes[$m['cols']['C']['s']] = Exportacao::clientePadrao((string)cf_exp_capa($l)['cliente']);
                $rows[] = $m['cols']; $total += (float)$l['valor'];
            }
            if ($erros) $falha('Corrija antes de gerar: ' . implode(' | ', array_slice($erros, 0, 6)));
            // Identidade da importação por planilha: Fornecedor + Categoria + Valor + Vencimento + Parcela + NF. Duas linhas com a mesma
            // identidade (no lote ou contra um título já existente) viram UMA no Omie — a importação "atualiza" em vez de criar. Bloqueia.
            $ident = [];
            foreach ($rows as $i => $r) { $k = implode('|', [CapaParser::key((string)($r['C']['s'] ?? '')), CapaParser::key((string)($r['D']['s'] ?? '')), number_format((float)($r['F']['n'] ?? $linhas[$i]['valor'] ?? 0), 2, '.', ''), (string)($r['K']['d'] ?? ''), (string)($r['V']['n'] ?? ''), CapaParser::key((string)($r['Y']['s'] ?? ''))]); $ident[$k][] = $r['B']['s']; }
            $colisoes = array_filter($ident, fn($c) => count($c) > 1);
            if ($colisoes && empty($in['forcar'])) exit(json_encode(['ok' => false, 'pode_forcar' => true, 'erro' => "Linhas com a MESMA identidade para a importação do Omie (fornecedor + categoria + valor + vencimento + parcela + NF) — o Omie importaria só uma delas:\n- " . implode("\n- ", array_map(fn($c) => implode(' = ', $c), $colisoes)) . "\n\nUse o envio pela API (que identifica pelo código de integração) ou mude vencimento/NF de uma delas."], JSON_UNESCAPED_UNICODE));
            if (OmieApi::disponivel() && isset(OmieApi::contas()[$empresa]) && empty($in['forcar'])) {
                // contra títulos já existentes no Omie (mesmo fornecedor/cliente, valor e vencimento)
                $jaExistem = [];
                foreach ($linhas as $i => $l) {
                    $r = $rows[$i] ?? null; if (!$r) continue;
                    $doc = preg_replace('/\D+/', '', (string)($r['C']['s'] ?? '')); if (!in_array(strlen($doc), [11, 14], true)) continue;
                    try {
                        $pl = OmieApi::call($empresa, 'financas/pesquisartitulos/', 'PesquisarLancamentos', ['nPagina' => 1, 'nRegPorPagina' => 50, 'cNatureza' => $tipo, 'cCPFCNPJCliente' => (string)$r['C']['s'], 'dDtVencDe' => OmieEnvio::br((string)$r['K']['d']), 'dDtVencAte' => OmieEnvio::br((string)$r['K']['d'])]);
                        foreach ($pl['titulosEncontrados'] ?? [] as $t) { $h = $t['cabecTitulo'] ?? $t; if (abs((float)($h['nValorTitulo'] ?? 0) - (float)$l['valor']) < 0.005) { $jaExistem[] = $l['codigo_integracao'] . ' ≈ título ' . ($h['nCodTitulo'] ?? '?') . ' (' . ($h['cCodIntTitulo'] ?: 'sem código') . ')'; break; } }
                    } catch (RuntimeException $e) { /* nenhum título = ok */ }
                }
                if ($jaExistem) exit(json_encode(['ok' => false, 'pode_forcar' => true, 'erro' => "Já existe no Omie um título do mesmo fornecedor/cliente com o mesmo valor e vencimento — a importação por planilha pode ATUALIZAR esse título em vez de criar o novo:\n- " . implode("\n- ", $jaExistem) . "\n\nPrefira o envio pela API, ou gere mesmo assim se tiver certeza."], JSON_UNESCAPED_UNICODE));
            }
            foreach ($avisosPor as $a => $cods) $avisos[] = ucfirst($a) . ' — ' . count($cods) . ' linha(s): ' . implode(', ', array_slice($cods, 0, 12)) . (count($cods) > 12 ? '…' : '');
            if ($tipo === 'R' && $clientes) {
                if (OmieApi::disponivel() && isset(OmieApi::contas()[$empresa])) {
                    // confere na API antes de gerar: cliente que não existe bloqueia (a não ser que o usuário force)
                    $conf = cf_exp_conferir_clientes($empresa, $clientes); $faltam = []; $duvida = [];
                    foreach ($conf as $txt => $r) {
                        if ($r['status'] === 'nao') $faltam[] = "$txt: {$r['msg']}";
                        elseif ($r['status'] !== 'ok') $duvida[] = "$txt: {$r['msg']}";
                    }
                    if ($faltam && empty($in['forcar'])) exit(json_encode(['ok' => false, 'erro' => 'Cliente(s) não encontrado(s) no Omie — cadastre antes de importar, ou gere mesmo assim:' . "\n- " . implode("\n- ", $faltam), 'pode_forcar' => true], JSON_UNESCAPED_UNICODE));
                    foreach (array_merge($faltam, $duvida) as $d) $avisos[] = 'Cliente no Omie — ' . $d;
                } else {
                    $avisos[] = 'Clientes das contas a receber — confira se existem no Omie (API não configurada; senão cadastre com CPF e data de nascimento): ' . implode(', ', array_keys($clientes));
                }
            }

            $pdo->beginTransaction();
            $pdo->prepare('INSERT INTO cf_exportacoes (tipo, empresa, arquivo_nome, arquivo_path, n_linhas, total, data_registro, avisos, gerado_por) VALUES (?,?,?,?,?,?,?,?,?)')
                ->execute([$tipo, $empresa, 'tmp', 'tmp', count($rows), $total, $reg, json_encode($avisos, JSON_UNESCAPED_UNICODE), $u['id']]);
            $expId = (int)$pdo->lastInsertId();
            $agora = new DateTimeImmutable();
            $nome = Exportacao::nomeArquivo($tipo, $empresa, $expId, $agora);
            $dir = cf_data_dir() . '/exportacoes/' . $agora->format('Y');
            if (!is_dir($dir)) @mkdir($dir, 0750, true);
            $path = $dir . '/' . $nome;
            OmieXlsx::gerar($modelos[$tipo], $rows, $path);
            $pdo->prepare('UPDATE cf_exportacoes SET arquivo_nome = ?, arquivo_path = ? WHERE id = ?')->execute([$nome, $path, $expId]);
            $pdo->prepare("UPDATE cf_lancamentos SET status = 'exportado', exportacao_id = ?, alteracao_pos_exportacao = 0 WHERE id IN (" . implode(',', $ids) . ") AND status = 'confirmado'")->execute([$expId]);
            $pdo->commit();
            exit(json_encode(['ok' => true, 'exportacao_id' => $expId, 'arquivo' => $nome, 'n' => count($rows), 'total' => $total, 'avisos' => $avisos], JSON_UNESCAPED_UNICODE));
        }
        if ($acao === 'desfazer') {
            $id = (int)($in['id'] ?? 0);
            $st = $pdo->prepare("SELECT * FROM cf_exportacoes WHERE id = ? AND status = 'gerada'"); $st->execute([$id]);
            if (!($ex = $st->fetch())) $falha('exportação não encontrada ou já desfeita');
            if (($ex['modo'] ?? 'planilha') === 'api') {
                // exclui título a título no Omie; o que já tem baixa fica e é reportado
                $q = $pdo->prepare("SELECT * FROM cf_lancamentos WHERE exportacao_id = ? AND status = 'exportado'"); $q->execute([$id]);
                $okN = 0; $recusas = [];
                foreach ($q->fetchAll() as $l) { $r = cf_exp_desfazer_linha($pdo, $l, (string)$ex['empresa'], (int)$u['id']); if ($r['ok']) $okN++; else $recusas[] = $l['codigo_integracao'] . ': ' . $r['msg']; }
                $q = $pdo->prepare("SELECT COUNT(*) FROM cf_lancamentos WHERE exportacao_id = ? AND status = 'exportado'"); $q->execute([$id]); $restam = (int)$q->fetchColumn();
                $av = json_decode((string)$ex['avisos'], true) ?: [];
                foreach ($recusas as $rc) $av[] = 'DESFAZER — ' . $rc;
                $pdo->prepare('UPDATE cf_exportacoes SET status = ?, avisos = ?, n_linhas = ? WHERE id = ?')->execute([$restam ? 'gerada' : 'desfeita', json_encode($av, JSON_UNESCAPED_UNICODE), $restam, $id]);
                exit(json_encode(['ok' => true, 'excluidos' => $okN, 'recusas' => $recusas], JSON_UNESCAPED_UNICODE));
            }
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE cf_lancamentos SET status = 'confirmado', exportacao_id = NULL WHERE exportacao_id = ? AND status = 'exportado'")->execute([$id]);
            $pdo->prepare("UPDATE cf_exportacoes SET status = 'desfeita' WHERE id = ?")->execute([$id]);
            $pdo->commit();
            exit(json_encode(['ok' => true]));
        }
        if ($acao === 'ajustado') {
            $pdo->prepare('UPDATE cf_lancamentos SET alteracao_pos_exportacao = 0 WHERE id = ?')->execute([(int)($in['id'] ?? 0)]);
            exit(json_encode(['ok' => true]));
        }
        $falha('ação inválida');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $m = $e->getMessage();
        $dica = str_contains($m, 'Omie') ? ' O erro veio do Omie; se for bloqueio/indisponibilidade, aguarde alguns minutos e tente de novo — nada foi perdido, a conferência antes de enviar vincula o que já entrou.'
              : (str_contains($m, 'SQLSTATE') ? ' É um erro do banco de dados do portal — rode a migração em Admin › Migração do banco e tente de novo; se persistir, me avise com esta mensagem.' : ' Se persistir, me avise com esta mensagem.');
        $falha('Falhou: ' . $m . '.' . $dica, 500);
    }
}

/* ---------- tela ---------- */
$empresa = (string)($_GET['empresa'] ?? array_key_first($empresas));
if (!isset($empresas[$empresa])) $empresa = (string)array_key_first($empresas);
$tipo = ($_GET['tipo'] ?? 'P') === 'R' ? 'R' : 'P';
$emp = $empresas[$empresa];
$pessoas = cf_pessoas(false);
$opts = ['data_registro' => date('Y-m-d'), 'emissao' => 'venda'];
$linhas = cf_exp_candidatas($pdo, $empresa, $tipo, null);
$contagem = [];
foreach ($pdo->query("SELECT c.empresa, l.tipo, COUNT(*) n FROM cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id WHERE l.status = 'confirmado' AND c.status = 'confirmada' GROUP BY c.empresa, l.tipo")->fetchAll() as $r) $contagem[$r['empresa']][$r['tipo']] = (int)$r['n'];
$montadas = [];
foreach ($linhas as $l) $montadas[(int)$l['id']] = Exportacao::montar($l, cf_exp_capa($l), $l['pessoa_id'] ? ($pessoas[(int)$l['pessoa_id']] ?? null) : null, $emp, $opts);
$st = $pdo->prepare("SELECT l.*, c.cod AS capa_cod, c.cliente AS capa_cliente, e.arquivo_nome FROM cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id LEFT JOIN cf_exportacoes e ON e.id = l.exportacao_id
                     WHERE l.alteracao_pos_exportacao = 1 AND l.status IN ('exportado','removido') AND c.empresa = ? ORDER BY l.id DESC LIMIT 100");
$st->execute([$empresa]); $alteradas = $st->fetchAll();
$apiOk = OmieApi::disponivel() && isset(OmieApi::contas()[$empresa]);
$st = $pdo->prepare("SELECT l.*, c.cod AS capa_cod, c.cliente AS capa_cliente, e.gerado_em FROM cf_lancamentos l JOIN cf_capas c ON c.id = l.capa_id LEFT JOIN cf_exportacoes e ON e.id = l.exportacao_id
                     WHERE l.status = 'exportado' AND l.omie_id IS NOT NULL AND c.empresa = ? AND l.tipo = ? ORDER BY l.id DESC LIMIT 60");
$st->execute([$empresa, $tipo]); $enviadosApi = $st->fetchAll();
$st = $pdo->prepare('SELECT e.*, u.nome AS por FROM cf_exportacoes e LEFT JOIN users u ON u.id = e.gerado_por WHERE e.empresa = ? ORDER BY e.id DESC LIMIT 20');
$st->execute([$empresa]); $historico = $st->fetchAll();

portal_header('Exportar para o Omie', $u);
?>
<meta name="csrf" content="<?= h(csrf_token()) ?>">
<style>main.wrap{max-width:1680px}</style>
<script src="/capa-financeira/pix.js?v=2"></script>
<div class="cf-rev">
<?php cf_cabecalho('Exportar para o Omie', 'Lançamentos confirmados e ainda não enviados. Envie direto pela API (conferido antes, linha a linha, reversível) ou gere a planilha de importação (modelo oficial).', [['Capa Financeira', '/capa-financeira/'], ['Exportar para o Omie', null]], 'exportar'); ?>

<div class="admin-tabs">
  <?php foreach ($empresas as $id => $e): ?><a class="<?= $id === $empresa ? 'on' : '' ?>" href="?empresa=<?= h($id) ?>&tipo=<?= $tipo ?>"><?= h($e['nome']) ?> <small>(<?= ($contagem[$id]['P'] ?? 0) ?> P / <?= ($contagem[$id]['R'] ?? 0) ?> R)</small></a><?php endforeach; ?>
</div>
<div class="admin-tabs">
  <a class="<?= $tipo === 'P' ? 'on' : '' ?>" href="?empresa=<?= h($empresa) ?>&tipo=P">Contas a pagar (<?= $contagem[$empresa]['P'] ?? 0 ?>)</a>
  <a class="<?= $tipo === 'R' ? 'on' : '' ?>" href="?empresa=<?= h($empresa) ?>&tipo=R">Contas a receber (<?= $contagem[$empresa]['R'] ?? 0 ?>)</a>
</div>

<?php if (!$linhas): ?>
  <p class="home-sub">Nada pendente de exportação para <?= h($emp['nome']) ?> (<?= $tipo === 'P' ? 'contas a pagar' : 'contas a receber' ?>). Confirme capas na revisão para elas aparecerem aqui.</p>
<?php else: ?>
<div class="cf-barra">
  <div class="cf-pend ok" style="display:flex;gap:18px;flex-wrap:wrap;align-items:center">
    <label>Data de registro <input type="date" id="exp-reg" class="cf-in" value="<?= date('Y-m-d') ?>"></label>
    <label>Data de emissão =
      <select id="exp-emi" class="cf-in"><option value="venda">data da venda (capa)</option><option value="registro">data de registro</option><option value="prevista">vencimento</option></select></label>
    <label title="conta bancária da empresa no Omie (nome exato), usada em toda linha que não tiver conta própria">Conta corrente padrão (<?= $tipo === 'P' ? 'pagar' : 'receber' ?>) <input type="text" id="exp-conta" class="cf-in" list="contas-omie" maxlength="40" placeholder="ex.: Sicredi" value="<?= h((string)($emp[$tipo === 'P' ? 'conta_padrao_cp' : 'conta_padrao'] ?? '')) ?>" style="width:170px"> <button type="button" class="cf-x" id="btn-conta" title="salvar como padrão desta empresa">salvar</button></label>
    <datalist id="contas-omie"><?php foreach ((array)(cf_config('contas_omie', [])[$empresa] ?? []) as $c): ?><option value="<?= h($c) ?>"></option><?php endforeach; ?></datalist>
    <span class="cf-dica" style="margin:0">Vencimento e Previsão = data prevista da linha. Nº Documento = código de integração. Observações levam cliente, construtora, imóvel, venda, COD, recibo, função e condição.</span>
  </div>
  <div class="cf-acoes" style="flex-wrap:wrap;gap:8px">
    <?php if ($apiOk): ?>
    <button class="btn cf-btn-sec" id="btn-conferir-api" title="confere na API do Omie tudo o que a importação recusaria: fornecedor/cliente, categoria, conta corrente, departamento, projeto, datas, duplicidade">Conferir para envio</button>
    <button class="btn" id="btn-enviar" title="inclui os títulos direto no Omie pela API (linha a linha; dá pra desfazer)">Enviar pro Omie (API) (<span class="exp-n">0</span>)</button>
    <?php endif; ?>
    <button class="btn <?= $apiOk ? 'cf-btn-sec' : '' ?>" id="btn-gerar">Gerar planilha (<span class="exp-n">0</span> linhas · <span id="exp-total">R$ 0,00</span>)</button>
  </div>
</div>

<?php if ($tipo === 'P'): ?>
<div class="cf-tbl-wrap"><table class="grid cf-tbl" id="tbl-exp">
<thead><tr><th><input type="checkbox" id="exp-todos" checked></th><th>Capa</th><th>Código</th><th>Pessoa → Fornecedor (Omie)</th><th>Categoria</th><th>Conta corrente</th><th>Valor</th><th>Vencimento</th><th>Nota Fiscal</th><th>Chave Pix</th><th>Problemas</th></tr></thead>
<tbody>
<?php foreach ($linhas as $l): $m = $montadas[(int)$l['id']]; $p = $l['pessoa_id'] ? ($pessoas[(int)$l['pessoa_id']] ?? null) : null; $ok = !$m['erros']; ?>
<tr class="cf-row <?= $ok ? '' : 'cf-tem-grave' ?>" data-id="<?= (int)$l['id'] ?>" data-valor="<?= (float)$l['valor'] ?>">
  <td><input type="checkbox" class="exp-sel" <?= $ok ? 'checked' : 'disabled' ?>></td>
  <td><a href="/capa-financeira/revisar.php?id=<?= (int)$l['capa_id'] ?>"><?= h((string)$l['capa_cod']) ?></a><br><small class="cf-raw"><?= h(mb_substr((string)$l['capa_cliente'], 0, 40)) ?></small></td>
  <td><b><?= h((string)$l['codigo_integracao']) ?></b><br><small class="cf-raw">L<?= (int)$l['linha_xlsx'] ?> · <?= h((string)$l['funcao']) ?><?= $l['natureza'] === 'BONUS' ? ' (BONUS)' : '' ?></small></td>
  <td><?= h($p['nome'] ?? '—') ?><br><small class="cf-raw">→ <?= h($m['cols']['C']['s'] ?? '—') ?><?= $p && !empty($p['departamento_omie']) ? ' · dep.: ' . h($p['departamento_omie']) : '' ?></small></td>
  <td><?= h((string)$l['categoria']) ?></td>
  <td><input type="text" class="cf-in exp-edit" data-campo="conta_corrente" list="contas-omie" value="<?= h((string)$l['conta_corrente']) ?>" placeholder="<?= h($m['cols']['E']['s'] ?? 'nome exato no Omie') ?>" maxlength="40" title="vazio = padrão da pessoa/empresa"></td>
  <td class="cf-num"><?= cf_brl($l['valor']) ?></td>
  <td><?= cf_data_br($l['data_prevista']) ?></td>
  <td><input type="text" class="cf-in exp-edit" data-campo="nota_fiscal" value="<?= h((string)$l['nota_fiscal']) ?>" maxlength="20" style="width:130px"></td>
  <td class="cf-pix-cel"><input type="text" class="cf-in cf-pix exp-edit" data-campo="chave_pix" value="<?= h((string)$l['chave_pix']) ?>" autocomplete="off"></td>
  <td class="cf-alertas"><?php foreach ($m['erros'] as $e): ?><div class="cf-flag cf-grave"><?= h($e) ?></div><?php endforeach; foreach ($m['avisos'] as $a): ?><div class="cf-flag cf-leve"><?= h($a) ?></div><?php endforeach; ?><?php if ($l['omie_erro']): ?><div class="cf-flag cf-grave" title="último envio pela API">Omie (último envio): <?= h((string)$l['omie_erro']) ?></div><?php endif; ?><div class="exp-omie"></div></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php else: ?>
<div class="cf-tbl-wrap"><table class="grid cf-tbl" id="tbl-exp">
<thead><tr><th><input type="checkbox" id="exp-todos" checked></th><th>Capa</th><th>Código</th><th>Cliente (como está no Omie)</th><th>Categoria</th><th>Conta corrente</th><th>Valor</th><th>Parcela</th><th>Vencimento</th><th>Nota Fiscal</th><th>Problemas</th></tr></thead>
<tbody>
<?php foreach ($linhas as $l): $m = $montadas[(int)$l['id']]; $ok = !$m['erros']; ?>
<tr class="cf-row <?= $ok ? '' : 'cf-tem-grave' ?>" data-id="<?= (int)$l['id'] ?>" data-valor="<?= (float)$l['valor'] ?>">
  <td><input type="checkbox" class="exp-sel" <?= $ok ? 'checked' : 'disabled' ?>></td>
  <td><a href="/capa-financeira/revisar.php?id=<?= (int)$l['capa_id'] ?>"><?= h((string)$l['capa_cod']) ?></a><br><small class="cf-raw"><?= h(mb_substr((string)$l['capa_cliente'], 0, 40)) ?></small></td>
  <td><b><?= h((string)$l['codigo_integracao']) ?></b><br><small class="cf-raw">L<?= (int)$l['linha_xlsx'] ?> · <?= h((string)$l['cf_raw']) ?></small></td>
  <td><input type="text" class="cf-in exp-edit" data-campo="cliente_omie" value="<?= h((string)$l['cliente_omie']) ?>" placeholder="<?= h($m['cols']['C']['s'] ?? '') ?>" maxlength="60" title="vazio = CPF do 1º comprador da capa (ou o nome, se a capa não tem CPF)"></td>
  <td><?= h((string)$l['categoria']) ?></td>
  <td><input type="text" class="cf-in exp-edit" data-campo="conta_corrente" list="contas-omie" value="<?= h((string)$l['conta_corrente']) ?>" placeholder="<?= h($m['cols']['E']['s'] ?? 'nome exato no Omie') ?>" maxlength="40"></td>
  <td class="cf-num"><?= cf_brl($l['valor']) ?></td>
  <td><?= $l['parcela'] ? (int)$l['parcela'] . '/' . (int)$l['total_parcelas'] : '—' ?></td>
  <td><?= cf_data_br($l['data_prevista']) ?></td>
  <td><input type="text" class="cf-in exp-edit" data-campo="nota_fiscal" value="<?= h((string)$l['nota_fiscal']) ?>" maxlength="20" style="width:130px"></td>
  <td class="cf-alertas"><?php foreach ($m['erros'] as $e): ?><div class="cf-flag cf-grave"><?= h($e) ?></div><?php endforeach; foreach ($m['avisos'] as $a): ?><div class="cf-flag cf-leve"><?= h($a) ?></div><?php endforeach; ?><?php if ($l['omie_erro']): ?><div class="cf-flag cf-grave" title="último envio pela API">Omie (último envio): <?= h((string)$l['omie_erro']) ?></div><?php endif; ?><div class="exp-omie"></div></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php endif; ?>
<p class="cf-dica">Conta corrente vazia usa a conta da pessoa (Pessoas) ou a padrão da empresa (Configurações). Ao preencher uma conta você pode aplicá-la em todas as linhas sem conta. Linhas com problema (vermelho) não entram até serem corrigidas.</p>
<?php endif; ?>

<?php if ($alteradas): ?>
<h2 class="cf-h2">Alterações depois da exportação <small>ajustar manualmente no Omie</small></h2>
<div class="cf-tbl-wrap"><table class="grid cf-tbl">
<thead><tr><th>Código</th><th>Capa</th><th>Favorecido/Cliente</th><th>Valor</th><th>Vencimento</th><th>NF</th><th>Exportado em</th><th>O que fazer no Omie</th><th></th></tr></thead>
<tbody><?php foreach ($alteradas as $a): ?>
<tr data-id="<?= (int)$a['id'] ?>"><td><b><?= h((string)$a['codigo_integracao']) ?></b></td><td><a href="/capa-financeira/revisar.php?id=<?= (int)$a['capa_id'] ?>"><?= h((string)$a['capa_cod']) ?></a> <?= h(mb_substr((string)$a['capa_cliente'], 0, 30)) ?></td>
<td><?= h((string)$a['cf_raw']) ?></td><td class="cf-num"><?= cf_brl($a['valor']) ?></td><td><?= cf_data_br($a['data_prevista']) ?></td><td><?= h((string)$a['nota_fiscal']) ?></td><td><small><?= h((string)$a['arquivo_nome']) ?></small></td>
<td><?php if ($a['status'] === 'removido'): ?><span class="cf-tag cf-grave">excluir o título</span><?php else: $dif = cf_diferencas_exp(json_decode((string)$a['snapshot_exp'], true), $a); echo $dif ? 'alterar: ' . h(implode(', ', $dif)) : 'conferir o título'; endif; ?></td>
<td><button type="button" class="cf-x exp-ajustado" title="já ajustei este título no Omie">✔ ajustado</button></td></tr>
<?php endforeach; ?></tbody></table></div>
<p class="cf-dica">Na importação por planilha o Omie identifica o título por Categoria + Nota Fiscal + Fornecedor + Valor + Parcela + Vencimento — reimportar uma linha alterada criaria um título novo. Por isso estas ficam para ajuste manual (busque pelo Nº Documento = código de integração). Com a API isso passa a ser automático.</p>
<?php endif; ?>

<?php if ($enviadosApi): ?>
<h2 class="cf-h2">Enviados pela API <small>últimos <?= count($enviadosApi) ?> · <?= $tipo === 'P' ? 'contas a pagar' : 'contas a receber' ?></small></h2>
<div class="cf-tbl-wrap"><table class="grid cf-tbl">
<thead><tr><th>Código</th><th>Capa</th><th>Favorecido/Cliente</th><th>Valor</th><th>Vencimento</th><th>Cód. Omie</th><th>Enviado em</th><th></th></tr></thead>
<tbody><?php foreach ($enviadosApi as $a): ?>
<tr data-id="<?= (int)$a['id'] ?>"><td><b><?= h((string)$a['codigo_integracao']) ?></b></td><td><a href="/capa-financeira/revisar.php?id=<?= (int)$a['capa_id'] ?>"><?= h((string)$a['capa_cod']) ?></a> <?= h(mb_substr((string)$a['capa_cliente'], 0, 30)) ?></td>
<td><?= h((string)$a['cf_raw']) ?></td><td class="cf-num"><?= cf_brl($a['valor']) ?></td><td><?= cf_data_br($a['data_prevista']) ?></td><td><?= (int)$a['omie_id'] ?></td><td><small><?= h(substr((string)$a['gerado_em'], 0, 16)) ?></small></td>
<td><button type="button" class="cf-x exp-desfazer-linha" title="exclui este título no Omie (se ainda não foi baixado) e devolve a linha para 'confirmado'">↩ excluir do Omie</button></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>

<h2 class="cf-h2">Exportações anteriores <small><?= h($emp['nome']) ?></small></h2>
<?php if (!$historico): ?><p class="home-sub">Nenhuma ainda.</p><?php else: ?>
<div class="cf-tbl-wrap"><table class="grid cf-tbl">
<thead><tr><th>#</th><th>Quando</th><th>Tipo</th><th>Linhas</th><th>Total</th><th>Arquivo</th><th>Avisos</th><th>Status</th><th></th></tr></thead>
<tbody><?php foreach ($historico as $e): $av = json_decode((string)$e['avisos'], true) ?: []; ?>
<tr data-id="<?= (int)$e['id'] ?>"><td><?= (int)$e['id'] ?></td><td><?= h(substr((string)$e['gerado_em'], 0, 16)) ?><br><small><?= h((string)$e['por']) ?></small></td><td><?= $e['tipo'] === 'P' ? 'Pagar' : 'Receber' ?></td>
<td><?= (int)$e['n_linhas'] ?></td><td class="cf-num"><?= cf_brl($e['total']) ?></td><td><?php if (($e['modo'] ?? 'planilha') === 'api'): ?><span class="cf-tag">API</span><?php else: ?><a href="?baixar=<?= (int)$e['id'] ?>">⬇ <?= h($e['arquivo_nome']) ?></a><?php endif; ?></td>
<td><?php if ($av): ?><details><summary><?= count($av) ?> aviso(s)</summary><ul class="cf-flags"><?php foreach ($av as $x): ?><li><?= h($x) ?></li><?php endforeach; ?></ul></details><?php endif; ?></td>
<td><span class="cf-status cf-st-<?= $e['status'] === 'gerada' ? 'confirmada' : 'descartada' ?>"><?= h($e['status']) ?></span></td>
<td><?php if ($e['status'] === 'gerada' && $e['tipo'] === 'P' && ($e['modo'] ?? 'planilha') === 'planilha' && $apiOk): ?><button type="button" class="cf-x exp-corrigir-pix" title="títulos importados por planilha ficam como 'PIX QR-Code'; isto muda no Omie para Transferência Bancária + finalidade 'Transferência por chave PIX' + chave (pula os já pagos). Processa poucos por vez.">⇄ corrigir forma Pix</button> <?php endif; ?><?php if ($e['status'] === 'gerada' && ($e['modo'] ?? 'planilha') === 'planilha' && $apiOk): ?><button type="button" class="cf-x exp-conferir-exp" title="confere no Omie, título a título, se a planilha importada criou todos (pelo código de integração)"><?= $e['conferido_em'] ? '✔ conferir de novo' : '✔ conferir no Omie' ?></button><?php if ($e['conferido_em']): ?><div class="exp-conf-quando cf-raw">conferida em <?= h(substr((string)$e['conferido_em'], 0, 16)) ?></div><span class="exp-conf-salva" data-conf="<?= h((string)$e['conferencia']) ?>"></span><?php endif; ?> <?php endif; ?><?php if ($e['status'] === 'gerada'): ?><button type="button" class="cf-x exp-desfazer" data-modo="<?= h((string)($e['modo'] ?? 'planilha')) ?>" title="<?= ($e['modo'] ?? '') === 'api' ? 'exclui os títulos no Omie (os já baixados ficam) e devolve as linhas para confirmado' : 'a importação no Omie falhou: devolve as linhas para confirmado e gera de novo' ?>">↩ desfazer</button><?php endif; ?></td></tr>
<?php endforeach; ?></tbody></table></div>
<?php endif; ?>

<script>
(function(){
  const csrf = document.querySelector('meta[name=csrf]').content, empresa = <?= json_encode($empresa) ?>, tipo = <?= json_encode($tipo) ?>;
  async function postRaw(body){ body.csrf = csrf; body.empresa = empresa; body.tipo = tipo;
    const r = await fetch('/capa-financeira/exportar.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)});
    try { return await r.json(); } catch(e) { return {ok:false, erro:'erro ' + r.status}; } }
  const HTTP_EXPL = {504:'O servidor demorou demais para responder e a Hostinger cortou a requisição (tempo limite). Costuma acontecer quando há muitas linhas; a operação agora é feita em blocos pequenos — tente de novo. Se voltar a acontecer, me avise dizendo quantas linhas eram.',
    502:'O servidor do portal não respondeu (gateway). Normalmente é momentâneo — aguarde um minuto e tente de novo.', 503:'O portal está indisponível no momento — aguarde um minuto e tente de novo.',
    500:'Erro interno no portal (a mensagem detalhada não veio). Tente de novo; se persistir, me avise com o horário e o que estava fazendo.', 403:'Sua sessão expirou ou a verificação de segurança (CSRF) falhou — recarregue a página e entre de novo.',
    401:'Você não está mais logado — recarregue a página e entre de novo.', 413:'Requisição grande demais para o servidor — selecione menos linhas.'};
  async function post(body){ body.csrf = csrf; body.empresa = empresa; body.tipo = tipo;
    let r; try { r = await fetch('/capa-financeira/exportar.php', {method:'POST', headers:{'Content-Type':'application/json'}, body: JSON.stringify(body)}); }
    catch(e) { alert('Sem conexão com o portal (a requisição não chegou ao servidor). Confira sua internet e tente de novo.'); return null; }
    let j = null; try { j = await r.json(); } catch(e) {}
    if (!r.ok || !j || !j.ok) { alert(j && j.erro ? j.erro : ('Erro ' + r.status + ': ' + (HTTP_EXPL[r.status] || 'resposta inesperada do servidor. Tente de novo; se persistir, me avise com este código.'))); return null; } return j; }
  function renderConferencia(tr, linhas){
    let html = '<div class="cf-flags" style="margin-top:6px">' + linhas.map(l => '<div class="cf-flag ' + (l.ok ? 'cf-ok' : (l.existe ? 'cf-leve' : 'cf-grave')) + '">' + l.codigo + ': ' + l.msg.replace(/</g,'&lt;')
      + (l.ok ? '' : (l.existe ? ' <button type="button" class="cf-x exp-vincular" data-id="' + l.id + '" data-omie="' + l.omie_id + '" title="guarda o vínculo com este título do Omie como está (não altera nada no Omie)">✔ vincular como está no Omie</button>'
                               : (l.erro_api ? '' : ' <button type="button" class="cf-x exp-devolver" data-id="' + l.id + '" title="a linha volta para a fila e pode ser enviada de novo">↩ devolver p/ confirmado</button>'))) + '</div>').join('') + '</div>';
    let cel = tr.querySelector('.exp-conf-res'); if (!cel) { cel = document.createElement('div'); cel.className = 'exp-conf-res'; tr.querySelector('td:nth-child(7)').appendChild(cel); }
    cel.innerHTML = html;
    cel.querySelectorAll('.exp-vincular').forEach(d => d.addEventListener('click', async () => {
      const r = await post({acao:'vincular_linha', id: +d.dataset.id, omie_id: +d.dataset.omie}); if (r) { toast('Vinculado'); d.remove(); }
    }));
    cel.querySelectorAll('.exp-devolver').forEach(d => d.addEventListener('click', async () => {
      if (!confirm('Devolver esta linha para "confirmado" para enviar de novo (pela API ou nova planilha)?')) return;
      const r = await post({acao:'devolver_linha', id: +d.dataset.id}); if (r) { toast('Linha devolvida'); d.remove(); }
    }));
  }
  // conferências já feitas (salvas): mostra ao abrir a página
  document.querySelectorAll('.exp-conf-salva').forEach(el => { try { renderConferencia(el.closest('tr'), JSON.parse(el.dataset.conf)); } catch(e) {} });
  function toast(msg){ const t = document.createElement('div'); t.className = 'cf-toast'; t.textContent = msg; document.body.appendChild(t); setTimeout(() => t.remove(), 4000); }
  const brl = v => 'R$ ' + v.toFixed(2).replace('.', ',').replace(/\B(?=(\d{3})+(?!\d))/g, '.');
  function soma(){ let n = 0, t = 0; document.querySelectorAll('.exp-sel:checked').forEach(c => { n++; t += parseFloat(c.closest('tr').dataset.valor) || 0; });
    document.querySelectorAll('.exp-n').forEach(en => en.textContent = n); const et = document.getElementById('exp-total'); if (et) et.textContent = brl(t);
    ['btn-gerar','btn-enviar'].forEach(id => { const b = document.getElementById(id); if (b) b.disabled = n === 0; }); }
  soma();
  document.querySelectorAll('.exp-sel').forEach(c => c.addEventListener('change', soma));
  const todos = document.getElementById('exp-todos');
  if (todos) todos.addEventListener('change', () => { document.querySelectorAll('.exp-sel:not(:disabled)').forEach(c => c.checked = todos.checked); soma(); });
  window.cfPix && window.cfPix.ligarTodos && window.cfPix.ligarTodos();
  document.querySelectorAll('.exp-edit').forEach(el => el.addEventListener('change', async () => {
    const tr = el.closest('tr'); const campo = el.dataset.campo; let valor = el.value;
    if (campo === 'chave_pix') { if (el.value && el.dataset.pixValido !== '1') { toast('Chave Pix inválida — não salvei'); return; } valor = el.dataset.pixValor || ''; }
    const body = {acao:'editar', id: +tr.dataset.id, campo, valor};
    if (campo === 'conta_corrente' && valor) {
      const vazias = [...document.querySelectorAll('.exp-edit[data-campo=conta_corrente]')].filter(o => o !== el && !o.value).length;
      if (vazias && confirm('Aplicar a conta "' + valor + '" também nas outras ' + vazias + ' linha(s) sem conta?')) body.aplicar_todas = 1;
    }
    const j = await post(body); if (!j) return;
    toast('Salvo — recarregando para reavaliar'); setTimeout(() => location.reload(), 600);
  }));
  const bcta = document.getElementById('btn-conta');
  if (bcta) bcta.addEventListener('click', async () => {
    const v = document.getElementById('exp-conta').value.trim();
    const j = await post({acao:'conta_padrao', valor: v}); if (!j) return;
    toast(v ? 'Conta padrão salva — recarregando' : 'Conta padrão removida — recarregando'); setTimeout(() => location.reload(), 600);
  });
  const bconf = document.getElementById('btn-conferir');
  if (bconf) bconf.addEventListener('click', async () => {
    bconf.disabled = true; bconf.textContent = 'Consultando o Omie…';
    const j = await post({acao:'verificar_clientes'});
    bconf.disabled = false; bconf.textContent = 'Conferir clientes no Omie';
    if (!j) return;
    const cls = {ok:'cf-ok', so_nome:'cf-leve', varios:'cf-leve', nao:'cf-grave', erro:'cf-leve'};
    let nOk = 0, nProb = 0;
    document.querySelectorAll('#tbl-exp tr.cf-row').forEach(tr => {
      const txt = j.linhas[tr.dataset.id]; const r = txt ? j.clientes[txt] : null; const box = tr.querySelector('.exp-omie'); if (!box) return;
      if (!r) { box.innerHTML = ''; return; }
      box.innerHTML = '<div class="cf-flag ' + (cls[r.status] || 'cf-leve') + '">Omie: ' + r.msg.replace(/</g, '&lt;') + '</div>';
      r.status === 'ok' ? nOk++ : nProb++;
    });
    toast(nOk + ' linha(s) com cliente cadastrado' + (nProb ? ', ' + nProb + ' com pendência' : ''));
  });
  const bg = document.getElementById('btn-gerar');
  if (bg) bg.addEventListener('click', async () => {
    const ids = [...document.querySelectorAll('.exp-sel:checked')].map(c => +c.closest('tr').dataset.id);
    if (!ids.length) return;
    if (!confirm('Gerar a planilha do Omie com ' + ids.length + ' linha(s)? Elas passam a "exportado" (dá pra desfazer se a importação falhar).')) return;
    bg.disabled = true;
    const body = {acao:'gerar', ids, data_registro: document.getElementById('exp-reg').value, emissao: document.getElementById('exp-emi').value};
    let j = await postRaw(body);
    if (j && !j.ok && j.pode_forcar) { if (confirm(j.erro + '\n\nGerar mesmo assim?')) { body.forcar = 1; j = await postRaw(body); } else j = null; }
    if (j && !j.ok) { alert(j.erro || 'erro'); j = null; }
    if (!j) { bg.disabled = false; return; }
    if (j.avisos && j.avisos.length) alert('Planilha gerada (' + j.n + ' linhas). Avisos:\n\n- ' + j.avisos.join('\n- '));
    location.href = '/capa-financeira/exportar.php?baixar=' + j.exportacao_id;
    setTimeout(() => location.href = '/capa-financeira/exportar.php?empresa=' + empresa + '&tipo=' + tipo, 1500);
  });
  document.querySelectorAll('.exp-desfazer').forEach(b => b.addEventListener('click', async () => {
    const id = +b.closest('tr').dataset.id; const api = b.dataset.modo === 'api';
    if (!confirm(api ? 'Desfazer o envio #' + id + '? Os títulos serão EXCLUÍDOS no Omie (os que já tiverem baixa ficam e são avisados) e as linhas voltam para "confirmado".'
                     : 'Desfazer a exportação #' + id + '? As linhas voltam para "confirmado" e aparecem de novo para exportar. Só faça isso se a planilha NÃO foi importada no Omie.')) return;
    b.disabled = true; const j = await post({acao:'desfazer', id}); if (!j) { b.disabled = false; return; }
    if (api) alert('Excluídos no Omie: ' + j.excluidos + (j.recusas.length ? '\n\nNão excluídos:\n- ' + j.recusas.join('\n- ') : ''));
    location.reload();
  }));
  document.querySelectorAll('.exp-conferir-exp').forEach(b => b.addEventListener('click', async () => {
    const tr = b.closest('tr'); const id = +tr.dataset.id; b.disabled = true;
    let offset = 0, todas = [], fim = false, total = 0;
    while (!fim) {
      b.textContent = 'conferindo… ' + (total ? offset + '/' + total : '');
      const j = await post({acao:'conferir_exportacao', id, offset, limite: 8});
      if (!j) { b.disabled = false; b.textContent = '✔ conferir no Omie'; return; }
      todas = j.conferencia; total = j.total; fim = j.fim; offset += 8;
      renderConferencia(tr, todas);
    }
    b.disabled = false; b.textContent = '✔ conferir de novo';
    const marca = tr.querySelector('.exp-conf-quando'); const agora = new Date(); const txt = 'conferida em ' + agora.toLocaleDateString('pt-BR') + ' ' + agora.toTimeString().slice(0,5);
    if (marca) marca.textContent = txt; else { const m = document.createElement('div'); m.className = 'exp-conf-quando cf-raw'; m.textContent = txt; b.parentNode.appendChild(m); }
    const falt = todas.filter(l => !l.ok);
    toast(falt.length ? falt.length + ' título(s) faltando/divergente(s) no Omie' : 'Todos os ' + todas.length + ' títulos estão no Omie');
  }));
  document.querySelectorAll('.exp-corrigir-pix').forEach(b => b.addEventListener('click', async () => {
    const tr = b.closest('tr'); const qtd = parseInt(prompt('Quantos títulos corrigir agora (um lote de cada vez; os já corrigidos são pulados)?', '5') || '0', 10); if (!qtd) return;
    b.disabled = true; b.textContent = 'corrigindo…';
    const j = await post({acao:'corrigir_pix', id: +tr.dataset.id, qtd});
    b.disabled = false; b.textContent = '⇄ corrigir forma Pix';
    if (!j) return;
    const cls = {corrigido:'cf-ok', ja_ok:'cf-ok', pulado:'cf-leve', erro:'cf-grave'};
    let cel = tr.querySelector('.exp-pix-res'); if (!cel) { cel = document.createElement('div'); cel.className = 'exp-pix-res cf-flags'; cel.style.marginTop = '6px'; tr.querySelector('td:nth-child(7)').appendChild(cel); }
    cel.innerHTML = j.linhas.map(l => '<div class="cf-flag ' + cls[l.status] + '">' + l.codigo + ': ' + l.msg.replace(/</g,'&lt;') + '</div>').join('') + '<div class="cf-raw">' + (j.restam ? 'faltam ' + j.restam + ' título(s) nesta planilha — clique de novo para o próximo lote' : 'todos os títulos desta planilha já foram tratados') + '</div>';
    toast(j.linhas.filter(l => l.status === 'corrigido').length + ' corrigido(s)');
  }));
  document.querySelectorAll('.exp-desfazer-linha').forEach(b => b.addEventListener('click', async () => {
    const tr = b.closest('tr'); const cod = tr.querySelector('b').textContent;
    if (!confirm('Excluir o título ' + cod + ' no Omie e devolver a linha para "confirmado"?')) return;
    b.disabled = true; const j = await post({acao:'desfazer_linha', id: +tr.dataset.id}); if (!j) { b.disabled = false; return; }
    toast(j.msg); setTimeout(() => location.reload(), 800);
  }));
  function mostraConferencia(linhas){
    let nOk = 0, nErr = 0;
    document.querySelectorAll('#tbl-exp tr.cf-row').forEach(tr => {
      const r = linhas[tr.dataset.id]; const box = tr.querySelector('.exp-omie'); if (!box) return;
      if (!r) { box.innerHTML = ''; return; }
      let html = '';
      r.erros.forEach(e => html += '<div class="cf-flag cf-grave">API: ' + e.replace(/</g, '&lt;') + '</div>');
      r.avisos.forEach(a => html += '<div class="cf-flag cf-leve">API: ' + a.replace(/</g, '&lt;') + '</div>');
      if (!r.erros.length) html += '<div class="cf-flag cf-ok">API: ' + (r.existente ? 'já está no Omie — ao enviar, só vincula' : 'pronto para enviar') + (r.fornecedor ? ' (fornecedor cód. ' + r.fornecedor + ')' : '') + '</div>';
      box.innerHTML = html;
      const sel = tr.querySelector('.exp-sel'); if (r.erros.length) { sel.checked = false; nErr++; } else nOk++;
    });
    soma(); return {nOk, nErr};
  }
  const bca = document.getElementById('btn-conferir-api');
  if (bca) bca.addEventListener('click', async () => {
    bca.disabled = true; bca.textContent = 'Conferindo no Omie…';
    const ids = [...document.querySelectorAll('.exp-sel:checked')].map(c => +c.closest('tr').dataset.id);
    const linhas = {}; const BL = 8;
    for (let i = 0; i < ids.length; i += BL) {
      bca.textContent = 'Conferindo no Omie… ' + Math.min(i + BL, ids.length) + '/' + ids.length;
      const j = await post({acao:'conferir_api', ids: ids.slice(i, i + BL), data_registro: document.getElementById('exp-reg').value, emissao: document.getElementById('exp-emi').value});
      if (!j) { bca.disabled = false; bca.textContent = 'Conferir para envio'; return; }
      Object.assign(linhas, j.linhas);
    }
    bca.disabled = false; bca.textContent = 'Conferir para envio';
    const r = mostraConferencia(linhas); toast(r.nOk + ' linha(s) prontas' + (r.nErr ? ', ' + r.nErr + ' com erro (desmarcadas)' : ''));
  });
  const be = document.getElementById('btn-enviar');
  if (be) be.addEventListener('click', async () => {
    const ids = [...document.querySelectorAll('.exp-sel:checked')].map(c => +c.closest('tr').dataset.id);
    if (!ids.length) return;
    if (!confirm('Enviar ' + ids.length + ' título(s) para o Omie pela API? Cada linha é conferida antes; as que passarem são incluídas no Omie na hora (dá pra desfazer enquanto não forem baixadas).')) return;
    be.disabled = true;
    const j = {enviadas:[], falhas:[], avisos:[], total:0, exportacao_id:null}; const BL = 5;
    for (let i = 0; i < ids.length; i += BL) {
      be.textContent = 'Enviando… ' + Math.min(i + BL, ids.length) + '/' + ids.length;
      const r = await post({acao:'enviar_api', ids: ids.slice(i, i + BL), exportacao_id: j.exportacao_id, ultimo: i + BL >= ids.length ? 1 : 0, data_registro: document.getElementById('exp-reg').value, emissao: document.getElementById('exp-emi').value});
      if (!r) { if (j.enviadas.length) alert('O envio parou no meio. Já enviados: ' + j.enviadas.map(e => e.codigo).join(', ') + '. Recarregue a página: os enviados aparecem em "Enviados pela API" e os demais continuam pendentes.'); be.disabled = false; be.textContent = 'Enviar pro Omie (API)'; if (j.enviadas.length) location.reload(); return; }
      j.enviadas.push(...r.enviadas); j.falhas.push(...r.falhas); j.avisos.push(...r.avisos); j.total += r.total; if (r.exportacao_id) j.exportacao_id = r.exportacao_id;
      if (r.parou) { ids.slice(i + BL).forEach(id => j.falhas.push({codigo: 'linha ' + id, msg: 'não tentado: ' + r.parou})); break; }
    }
    let msg = 'Enviados: ' + j.enviadas.length + ' título(s) (' + brl(j.total) + ')';
    if (j.enviadas.length) msg += '\n' + j.enviadas.map(e => '  ' + e.codigo + ' → cód. Omie ' + e.omie_id + (e.vinculado ? ' (já existia — vinculado)' : '')).join('\n');
    if (j.falhas.length) msg += '\n\nNÃO enviados (' + j.falhas.length + '):\n' + j.falhas.map(f => '  ' + f.codigo + ': ' + f.msg).join('\n');
    if (j.avisos.length) msg += '\n\nAvisos:\n' + j.avisos.map(a => '  ' + a).join('\n');
    alert(msg); location.reload();
  });
  document.querySelectorAll('.exp-ajustado').forEach(b => b.addEventListener('click', async () => {
    const tr = b.closest('tr'); const j = await post({acao:'ajustado', id: +tr.dataset.id}); if (j) tr.remove();
  }));
})();
</script>
</div>
<?php portal_footer();
