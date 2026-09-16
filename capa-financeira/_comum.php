<?php
/* =====================================================================
   capa-financeira/_comum.php — helpers do módulo Capa Financeira → Omie.

   Nível 1: exige a ferramenta 'capa-financeira' (financeiro/admin).
   Os arquivos enviados ficam FORA do public_html (CF_DATA_DIR no
   config.php; padrão ../capa-dados ao lado do portal-config).
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/../lib/layout.php';
require_once __DIR__ . '/../admin/comum.php';     // csrf_token() / csrf_check()
require_once __DIR__ . '/lib/Parser.php';
require_once __DIR__ . '/lib/Pix.php';

/** Pasta de dados do módulo (fora da web). */
function cf_data_dir(): string
{
    portal_load_config();
    $dir = defined('CF_DATA_DIR') ? CF_DATA_DIR : dirname(__DIR__, 2) . '/capa-dados';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    if (!is_dir($dir) || !is_writable($dir)) {
        // último recurso: dentro do módulo, protegido por .htaccess
        $dir = __DIR__ . '/dados';
        if (!is_dir($dir)) { @mkdir($dir, 0750, true); @file_put_contents($dir . '/.htaccess', "Require all denied\n"); }
    }
    return $dir;
}

/** Lê uma configuração (JSON decodificado quando possível). */
function cf_config(string $chave, mixed $padrao = null): mixed
{
    static $cache = [];
    if (!array_key_exists($chave, $cache)) {
        $st = db()->prepare('SELECT valor FROM cf_config WHERE chave = ?');
        $st->execute([$chave]);
        $v = $st->fetchColumn();
        if ($v === false) $cache[$chave] = null;
        else { $j = json_decode((string)$v, true); $cache[$chave] = (json_last_error() === JSON_ERROR_NONE) ? $j : $v; }
    }
    return $cache[$chave] ?? $padrao;
}

function cf_config_set(string $chave, mixed $valor): void
{
    $v = is_string($valor) ? $valor : json_encode($valor, JSON_UNESCAPED_UNICODE);
    db()->prepare('INSERT INTO cf_config (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')->execute([$chave, $v]);
}

/** Parser configurado com o dicionário de condições atual. */
function cf_parser(): CapaParser
{
    $dict = cf_config('nf_dict');
    return new CapaParser(is_array($dict) && $dict ? $dict : null);
}

/** Categoria Omie para uma linha (função/natureza/tipo). */
function cf_categoria(string $tipo, ?string $funcao, ?string $natureza, ?string $statusImovel): ?string
{
    $map = cf_config('categorias', []);
    if ($tipo === 'R') return $map[$statusImovel === 'PRONTO' ? 'RECEBER_NOVO' : 'RECEBER_NOVO'] ?? null; // PRONTO confirma na tela
    if ($natureza === 'BONUS') return $map['BONUS'] ?? null;
    $f = CapaParser::key($funcao ?? '');
    return $map[$f] ?? null;
}

/* ---------------- pessoas (dicionário) ---------------- */

/** Todas as pessoas ativas com seus apelidos. */
function cf_pessoas(bool $soAtivas = true): array
{
    $sql = 'SELECT * FROM cf_pessoas' . ($soAtivas ? ' WHERE ativo = 1' : '') . ' ORDER BY nome';
    $ps = db()->query($sql)->fetchAll();
    $al = db()->query('SELECT pessoa_id, alias, alias_key FROM cf_pessoa_aliases ORDER BY alias')->fetchAll();
    $byId = [];
    foreach ($ps as $p) { $p['aliases'] = []; $byId[(int)$p['id']] = $p; }
    foreach ($al as $a) if (isset($byId[(int)$a['pessoa_id']])) $byId[(int)$a['pessoa_id']]['aliases'][] = $a;
    return $byId;
}

/** Resolve um nome da capa para pessoa_id SÓ por igualdade exata (nome ou apelido). */
function cf_resolver_pessoa(string $nome, ?array $pessoas = null): ?int
{
    $k = CapaParser::key($nome);
    if ($k === '') return null;
    $pessoas = $pessoas ?? cf_pessoas();
    foreach ($pessoas as $id => $p) {
        if ($p['nome_key'] === $k) return $id;
        foreach ($p['aliases'] as $a) if ($a['alias_key'] === $k) return $id;
    }
    return null;
}

/** Sugestões (não automáticas): primeiro nome igual + palavras em comum (inclui desligados). */
function cf_sugerir_pessoas(string $nome, ?array $pessoas = null, int $max = 4): array
{
    $t = preg_split('/ /', CapaParser::key($nome), -1, PREG_SPLIT_NO_EMPTY) ?: [];
    if (!$t) return [];
    $pessoas = $pessoas ?? cf_pessoas();
    $out = [];
    foreach ($pessoas as $id => $p) {
        $tp = preg_split('/ /', $p['nome_key'], -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $comum = count(array_intersect($t, $tp));
        $score = $comum * 10 + (($tp[0] ?? '') === $t[0] ? 25 : 0);
        if ($score >= 25) $out[$id] = $score;
    }
    arsort($out);
    return array_slice(array_keys($out), 0, $max, true);
}

function cf_add_alias(int $pessoaId, string $alias): void
{
    $k = CapaParser::key($alias);
    if ($k === '') return;
    db()->prepare('INSERT IGNORE INTO cf_pessoa_aliases (pessoa_id, alias, alias_key) VALUES (?,?,?)')->execute([$pessoaId, CapaParser::norm($alias), $k]);
}

/** Observação padronizada que vai para o Omie (facilita a busca lá). */
function cf_observacao(array $capa, array $l): string
{
    $p = [];
    $p[] = 'CLIENTE: ' . $capa['cliente'];
    $p[] = 'CONSTRUTORA: ' . $capa['construtora'];
    if (!empty($l['unidade'])) $p[] = 'IMOVEL: ' . $l['unidade'];
    elseif (!empty($capa['unidade'])) $p[] = 'IMOVEL: ' . $capa['unidade'] . ($capa['bairro'] ? ' - ' . $capa['bairro'] : '');
    if (!empty($l['status_imovel'])) $p[] = 'STATUS: ' . $l['status_imovel'];
    if (!empty($capa['data_venda'])) $p[] = 'VENDA: ' . cf_data_br($capa['data_venda']);
    if (!empty($capa['cod'])) $p[] = 'COD: ' . $capa['cod'];
    if (!empty($l['codigo_integracao'])) $p[] = 'RECIBO: ' . $l['codigo_integracao'];
    if (($l['tipo'] ?? '') === 'P' && !empty($l['funcao'])) $p[] = 'FUNCAO: ' . $l['funcao'] . (($l['natureza'] ?? '') === 'BONUS' ? ' (BONUS)' : '');
    if (!empty($l['condicao'])) $p[] = 'COND: ' . $l['condicao'];
    if (!empty($l['rotulo_fluxo'])) $p[] = 'FLUXO: ' . $l['rotulo_fluxo'];
    return implode(' | ', $p);
}

/** campos que, alterados depois de exportar, precisam de ajuste manual no Omie (retrato gravado ao reabrir a capa) */
function cf_retrato(array $l): array
{
    return ['valor' => number_format((float)$l['valor'], 2, '.', ''), 'data_prevista' => $l['data_prevista'], 'categoria' => $l['categoria'], 'nota_fiscal' => (string)$l['nota_fiscal'],
            'pessoa_id' => $l['pessoa_id'] ? (int)$l['pessoa_id'] : null, 'chave_pix' => (string)$l['chave_pix'], 'conta_corrente' => (string)$l['conta_corrente'],
            'cliente_omie' => (string)$l['cliente_omie'], 'parcela' => $l['parcela'] ? (int)$l['parcela'] : null, 'total_parcelas' => $l['total_parcelas'] ? (int)$l['total_parcelas'] : null];
}
/** rótulos do que mudou entre o retrato e a linha atual (vazio = nada relevante mudou) */
function cf_diferencas_exp(?array $antes, array $l): array
{
    if (!$antes) return [];
    $agora = cf_retrato($l);
    $rot = ['valor' => 'valor', 'data_prevista' => 'vencimento', 'categoria' => 'categoria', 'nota_fiscal' => 'nota fiscal', 'pessoa_id' => 'fornecedor', 'chave_pix' => 'chave Pix',
            'conta_corrente' => 'conta corrente', 'cliente_omie' => 'cliente', 'parcela' => 'parcela', 'total_parcelas' => 'total de parcelas'];
    $dif = [];
    foreach ($rot as $k => $r) {
        if (!array_key_exists($k, $antes)) continue;
        // Pix preenchida automaticamente depois (estava vazia na exportação) não conta como alteração
        if ($k === 'chave_pix' && (string)$antes[$k] === '') continue;
        if ($antes[$k] != $agora[$k]) $dif[] = $r;
    }
    return $dif;
}

/* ---------------- formatação ---------------- */

function cf_brl(float|int|string|null $v): string
{
    if ($v === null || $v === '') return '—';
    return 'R$ ' . number_format((float)$v, 2, ',', '.');
}
function cf_data_br(?string $iso): string
{
    if (!$iso) return '—';
    $d = DateTimeImmutable::createFromFormat('Y-m-d', substr($iso, 0, 10));
    return $d ? $d->format('d/m/Y') : $iso;
}
/** Nível de um alerta: grave | leve. */
function cf_nivel_flag(string $f): string
{
    return str_starts_with($f, 'GRAVE:') ? 'grave' : 'leve';
}
/** Texto amigável dos alertas. */
function cf_flag_texto(string $f): string
{
    $map = [
        'ANO_ABSURDO' => 'Ano da data fora do razoável — confirme a data',
        'ANO_SUSPEITO' => 'Ano com poucos dígitos (ex.: 0202)',
        'ANTERIOR_A_VENDA' => 'Data anterior à data da venda',
        'DATA_FORA_DE_SEQUENCIA' => 'Data anterior à da linha anterior do mesmo grupo',
        'DATA_ILEGIVEL' => 'Data ilegível',
        'SEM_DATA' => 'Sem data prevista',
        'HIST_SEM_DATA_VENDA' => 'Histórico sem "VENDA: dd/mm/aaaa"',
        'HIST_SEM_STATUS_IMOVEL' => 'Histórico sem PRONTO/PLANTA',
        'HIST_TOKENS_INSUFICIENTES' => 'Histórico fora do padrão (faltam campos)',
        'TIPO_COLUNA_X_HISTORICO' => 'Coluna (pagar/receber) não combina com o histórico',
        'GRAVE:PARECE_BONUS (bate com bloco BONUS da capa ou coluna G)' => 'Está como COMISSAO, mas o valor bate com o bloco de BÔNUS da capa (ou a coluna G diz BONUS)',
        'GRAVE:ARRASTO_DE_ANO (ano aumenta 1 a cada linha)' => 'Arrasto de ano na planilha (o ano sobe de 1 em 1 a cada linha)',
        'NF_CORTADA_20' => 'Nota Fiscal cortada em 20 caracteres',
    ];
    if (isset($map[$f])) return $map[$f];
    if (str_starts_with($f, 'GRAVE:FAVORECIDO_DIVERGE')) return 'Favorecido da coluna C é DIFERENTE do nome no histórico ' . substr($f, strlen('GRAVE:FAVORECIDO_DIVERGE '));
    if (str_starts_with($f, 'FAVORECIDO_ABREVIADO')) return 'Nome abreviado no histórico ' . substr($f, strlen('FAVORECIDO_ABREVIADO '));
    if (str_starts_with($f, 'CLIENTES_HIST_DIFEREM_CAPA')) return 'Clientes no histórico diferem da capa ' . substr($f, strlen('CLIENTES_HIST_DIFEREM_CAPA '));
    if (str_starts_with($f, 'CONSTRUTORA_HIST_DIFERE_CAPA')) return 'Construtora no histórico difere da capa ' . substr($f, strlen('CONSTRUTORA_HIST_DIFERE_CAPA '));
    if (str_starts_with($f, 'DATA_VENDA_HIST_DIFERE_G3')) return 'Data da venda no histórico difere da capa (a capa vence) ' . substr($f, strlen('DATA_VENDA_HIST_DIFERE_G3 '));
    if (str_starts_with($f, 'COD_HIST_DIFERE_B6')) return 'COD no histórico difere do COD da capa ' . substr($f, strlen('COD_HIST_DIFERE_B6 '));
    if (str_starts_with($f, 'FLUXO_NAO_PAREADO')) return 'Os recebimentos não batem 1:1 com o bloco FLUXO DE PAGAMENTO da capa — parcelas numeradas pela ordem dos lançamentos ' . substr($f, strlen('FLUXO_NAO_PAREADO '));
    if (str_starts_with($f, 'ARQUIVO_IDENTICO_JA_ENVIADO')) return 'Este arquivo é idêntico a um já enviado ' . substr($f, strlen('ARQUIVO_IDENTICO_JA_ENVIADO '));
    if (str_starts_with($f, 'LINHAS_REMOVIDAS_NA_NOVA_VERSAO')) return 'Linhas da versão anterior que não existem mais nesta ' . substr($f, strlen('LINHAS_REMOVIDAS_NA_NOVA_VERSAO '));
    if (str_starts_with($f, 'CONDICAO_DIVERGE')) return 'Condição da coluna G difere do prefixo do histórico — decida qual vale ' . substr($f, strlen('CONDICAO_DIVERGE '));
    if (str_starts_with($f, 'CONDICAO_DESCONHECIDA_CORTADA:')) return 'Condição não está no dicionário e passou de 20 caracteres: ' . substr($f, 30);
    if (str_starts_with($f, 'CONDICAO_DESCONHECIDA:')) return 'Condição não está no dicionário: ' . substr($f, 22);
    if (str_starts_with($f, 'FUNCAO_DESCONHECIDA:')) return 'Função desconhecida: ' . substr($f, 20);
    if (str_starts_with($f, 'NATUREZA_DESCONHECIDA:')) return 'Natureza desconhecida (esperado COMISSAO ou BONUS): ' . substr($f, 22);
    return $f;
}
