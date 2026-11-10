<?php
/* =====================================================================
   capa-financeira/omie-cadastro.php — busca/cadastro de clientes e fornecedores
   no Omie, para o seletor reutilizável (cf-omie-cad.js).
     POST JSON acao: buscar   {empresa, termo, atualizar}  → {lista:[{codigo,nome,fantasia,doc,inativo,pontos,de}]}
                     cadastrar {empresa, nome, doc, email} → {cadastro:{codigo,nome,doc}, msg}
   Em desenvolvimento (CV_OMIE_FAKE) usa o arquivo omie-fake-clientes.json.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/_comum.php';
require_once __DIR__ . '/../recibos-omie/lib/OmieApi.php';
require_once __DIR__ . '/lib/OmieEnvio.php';

$u = require_tool('capa-financeira');
header('Content-Type: application/json; charset=utf-8');
$in = json_decode((string)file_get_contents('php://input'), true) ?: [];
$_POST['csrf'] = $in['csrf'] ?? '';
csrf_check();
$falha = function (string $m): never { echo json_encode(['ok' => false, 'erro' => $m], JSON_UNESCAPED_UNICODE); exit; };

$empresas = array_column(cf_config('empresas', []), null, 'id');
$empresa = (string)($in['empresa'] ?? '');
if (!isset($empresas[$empresa])) $falha('empresa inválida — escolha a empresa da venda na etapa 1');
$fake = defined('CV_OMIE_FAKE') && CV_OMIE_FAKE;
$fakeArq = (defined('CF_DATA_DIR') ? CF_DATA_DIR : sys_get_temp_dir()) . '/omie-fake-clientes.json';
if (!$fake && (!OmieApi::disponivel() || !isset(OmieApi::contas()[$empresa]))) $falha('API do Omie não configurada para esta empresa — as chaves ficam no omie.php da configuração do portal');
$acao = (string)($in['acao'] ?? '');

try {
    if ($acao === 'buscar') {
        $termo = trim((string)($in['termo'] ?? '')); if ($termo === '') $falha('digite parte do nome ou o CPF/CNPJ');
        if ($fake) {
            $toks = array_filter(preg_split('/\s+/', CapaParser::key($termo)) ?: [], fn($t) => mb_strlen($t) > 2);
            $dig = preg_replace('/\D+/', '', $termo); $lista = [];
            foreach ((json_decode((string)@file_get_contents($fakeArq), true) ?: []) as $c) {
                $n = 0; foreach ($toks as $t) if (str_contains(CapaParser::key($c['nome']), $t)) $n++;
                if ($n || (strlen($dig) >= 11 && $dig === $c['doc'])) $lista[] = ['codigo' => (int)$c['codigo'], 'nome' => $c['nome'], 'fantasia' => '', 'doc' => cf_fmt_doc($c['doc']), 'inativo' => false, 'pontos' => $n, 'de' => count($toks)];
            }
            usort($lista, fn($a, $b) => $b['pontos'] <=> $a['pontos']);
            exit(json_encode(['ok' => true, 'lista' => array_slice($lista, 0, 12)], JSON_UNESCAPED_UNICODE));
        }
        if (!empty($in['atualizar'])) { try { OmieEnvio::cadastros($empresa, true); } catch (Throwable $e) {} }
        $lista = array_map(fn($c) => ['codigo' => (int)$c['codigo_cliente_omie'], 'nome' => (string)$c['razao_social'], 'fantasia' => (string)($c['nome_fantasia'] ?? ''), 'doc' => (string)($c['cnpj_cpf'] ?? ''),
                                      'inativo' => ($c['inativo'] ?? 'N') === 'S', 'pontos' => (int)($c['pontos'] ?? 0), 'de' => (int)($c['de'] ?? 0)], OmieEnvio::buscarCadastros($empresa, $termo));
        exit(json_encode(['ok' => true, 'lista' => $lista], JSON_UNESCAPED_UNICODE));
    }
    if ($acao === 'cadastrar') {
        $nome = trim((string)preg_replace('/\s+/', ' ', (string)($in['nome'] ?? ''))); $dig = preg_replace('/\D+/', '', (string)($in['doc'] ?? '')); $email = trim((string)($in['email'] ?? ''));
        if ($fake) {
            if ($nome === '' || !in_array(strlen($dig), [11, 14], true) || !cf_doc_valido($dig)) $falha('nome e CPF/CNPJ válido são obrigatórios');
            $l = json_decode((string)@file_get_contents($fakeArq), true) ?: [];
            foreach ($l as $c) if ($c['doc'] === $dig) $falha('já existe cadastro no Omie com esse documento: ' . $c['nome'] . ' — use "usar este"');
            $cod = 9100000 + count($l); $l[] = ['codigo' => $cod, 'nome' => mb_strtoupper($nome), 'doc' => $dig, 'email' => $email];
            file_put_contents($fakeArq, json_encode($l, JSON_UNESCAPED_UNICODE));
            exit(json_encode(['ok' => true, 'cadastro' => ['codigo' => $cod, 'nome' => mb_strtoupper($nome), 'doc' => cf_fmt_doc($dig)], 'msg' => 'cadastrado no Omie (simulação local): ' . mb_strtoupper($nome)], JSON_UNESCAPED_UNICODE));
        }
        if (($resta = OmieApi::bloqueadoPor($empresa)) > 0) $falha('API do Omie bloqueada temporariamente (anti-abuso). Aguarde ' . ceil($resta / 60) . ' min.');
        try { $r = OmieEnvio::incluirCadastro($empresa, $nome, $dig, $email); }
        catch (Throwable $e) { $falha('não cadastrei no Omie: ' . $e->getMessage() . (OmieEnvio::erroTemporario($e->getMessage()) ? ' — aguarde alguns minutos e tente de novo' : '')); }
        $c = $r['cadastro'];
        exit(json_encode(['ok' => true, 'cadastro' => ['codigo' => (int)$c['codigo_cliente_omie'], 'nome' => (string)$c['razao_social'], 'doc' => (string)($c['cnpj_cpf'] ?? cf_fmt_doc($dig))], 'msg' => $r['msg']], JSON_UNESCAPED_UNICODE));
    }
    $falha('ação inválida');
} catch (Throwable $e) {
    $falha('erro: ' . $e->getMessage());
}
