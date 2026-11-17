<?php
/* =====================================================================
   alterador-omie/lib/AoCache.php — títulos e cadastros do Omie em cache.

   Títulos: um arquivo por empresa+tipo (P/R) em <CF_DATA_DIR>/alterador-omie/,
   com TODOS os títulos (ListarContasPagar/Receber, 100 por página).
     - leitura completa: em partes (orçamento de tempo por requisição; o
       progresso fica num arquivo "parcial" e a próxima requisição continua);
       trava de 10 min entre leituras completas.
     - atualização incremental: só os títulos incluídos/alterados desde a
       última leitura (filtrar_por_data_de/_ate do Listar = data de inclusão
       OU alteração — confirmado em 17/11/2026). Trava de 60 s (REDUNDANT).
     - exclusões feitas no Omie só somem do cache na leitura completa.
   Catálogo: categorias, contas correntes, departamentos e projetos (6 h) +
   fornecedores/clientes (reaproveita o cache da Capa Financeira — OmieEnvio).
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/AoTitulo.php';

final class AoCache
{
    public const TRAVA_INCREMENTAL = 60;
    public const TRAVA_COMPLETA = 600;
    public const CATALOGO_SEG = 6 * 3600;

    public static function dir(): string
    {
        $d = cf_data_dir() . '/alterador-omie';
        if (!is_dir($d)) @mkdir($d, 0750, true);
        return $d;
    }
    private static function arq(string $emp, string $tipo): string { return self::dir() . "/titulos-$emp-$tipo.json"; }
    private static function parcial(string $emp, string $tipo): string { return self::dir() . "/parcial-$emp-$tipo.json"; }
    private static function ler(string $f): ?array { if (!is_file($f)) return null; $j = json_decode((string)file_get_contents($f), true); return is_array($j) ? $j : null; }
    private static function gravar(string $f, array $j): void
    {
        $tmp = $f . '.tmp' . getmypid();
        file_put_contents($tmp, json_encode($j, JSON_UNESCAPED_UNICODE));
        rename($tmp, $f);
    }

    public static function titulos(string $emp, string $tipo): ?array { return self::ler(self::arq($emp, $tipo)); }

    /** situação do cache para a tela */
    public static function estado(string $emp, string $tipo): array
    {
        $j = self::titulos($emp, $tipo); $p = self::ler(self::parcial($emp, $tipo));
        $agora = time();
        return [
            'tem' => (bool)$j, 'n' => $j ? count($j['itens'] ?? []) : 0,
            'completa' => (int)($j['completa'] ?? 0), 'atualizada' => (int)($j['atualizada'] ?? 0),
            'carregando' => $p ? ['pag' => (int)$p['pag'], 'tot' => (int)$p['tot']] : null,
            'livre_incremental' => $j ? max(0, (int)$j['atualizada'] + self::TRAVA_INCREMENTAL - $agora) : 0,
            'livre_completa' => $j && !$p ? max(0, (int)$j['completa'] + self::TRAVA_COMPLETA - $agora) : 0,
        ];
    }

    private static function metodo(string $tipo): array
    {
        return $tipo === 'R' ? ['financas/contareceber/', 'ListarContasReceber', 'conta_receber_cadastro'] : ['financas/contapagar/', 'ListarContasPagar', 'conta_pagar_cadastro'];
    }

    /**
     * Atualiza o cache. $modo: auto (completa se não houver cache, senão incremental) | incremental | completa.
     * Para no orçamento de tempo ($prazo = microtime) e devolve continuar=true — a tela chama de novo.
     * @return array{ok: bool, continuar: bool, msg: string, n?: int}
     */
    public static function atualizar(string $emp, string $tipo, string $modo, float $prazo): array
    {
        $lk = fopen(self::dir() . "/lock-$emp-$tipo", 'c');
        if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return ['ok' => false, 'continuar' => true, 'msg' => 'outra atualização desta lista está em andamento — aguardando…'];
        try {
            $j = self::titulos($emp, $tipo); $par = self::ler(self::parcial($emp, $tipo));
            if ($par && (time() - (int)($par['mexido'] ?? 0)) > 900) { @unlink(self::parcial($emp, $tipo)); $par = null; }   // leitura abandonada
            if ($modo === 'auto') $modo = ($j && !$par) ? 'incremental' : 'completa';
            [$path, $met, $lista] = self::metodo($tipo);
            $rot = $tipo === 'R' ? 'contas a receber' : 'contas a pagar';

            if ($modo === 'incremental') {
                if (!$j) return self::atualizar($emp, $tipo, 'completa', $prazo);
                $resta = (int)$j['atualizada'] + self::TRAVA_INCREMENTAL - time();
                if ($resta > 0) return ['ok' => true, 'continuar' => false, 'msg' => "$rot: atualizado há menos de 1 min — o Omie não deixa repetir a consulta tão cedo (libera em {$resta}s)", 'n' => count($j['itens'])];
                $de = (new DateTimeImmutable('@' . (int)$j['atualizada']))->setTimezone(new DateTimeZone(date_default_timezone_get()))->modify('-1 day')->format('d/m/Y');
                $filtro = ['filtrar_por_data_de' => $de, 'filtrar_por_data_ate' => (new DateTimeImmutable('+1 day'))->format('d/m/Y')];
                $pag = 1; $n = 0;
                do {
                    try { $r = OmieApi::call($emp, $path, $met, ['pagina' => $pag, 'registros_por_pagina' => 100, 'apenas_importado_api' => 'N', 'exibir_obs' => 'S'] + $filtro, true); }
                    catch (RuntimeException $e) { if (preg_match('/n[ãa]o existem registros|Client-5113/iu', $e->getMessage())) break; throw $e; }
                    foreach ($r[$lista] ?? [] as $x) { $t = AoTitulo::deCadastro($x, $tipo); $j['itens'][(string)$t['id']] = $t; $n++; }
                    $tot = (int)($r['total_de_paginas'] ?? 1); $pag++;
                } while ($pag <= $tot && $pag < 60);
                $j['atualizada'] = time();
                self::gravar(self::arq($emp, $tipo), $j);
                return ['ok' => true, 'continuar' => false, 'msg' => "$rot: $n título(s) incluído(s)/alterado(s) desde a última leitura", 'n' => count($j['itens'])];
            }

            // leitura completa (em partes)
            if (!$par) {
                if ($j && ($resta = (int)$j['completa'] + self::TRAVA_COMPLETA - time()) > 0) return ['ok' => true, 'continuar' => false, 'msg' => "$rot: a leitura completa só pode ser repetida a cada 10 min (libera em " . ceil($resta / 60) . ' min)', 'n' => count($j['itens'])];
                $par = ['pag' => 1, 'tot' => 0, 'itens' => [], 'inicio' => time()];
            }
            do {
                $r = null;
                try { $r = OmieApi::call($emp, $path, $met, ['pagina' => (int)$par['pag'], 'registros_por_pagina' => 100, 'apenas_importado_api' => 'N', 'exibir_obs' => 'S'], true); }
                catch (RuntimeException $e) { if (!preg_match('/n[ãa]o existem registros|Client-5113/iu', $e->getMessage())) { $par['mexido'] = time(); self::gravar(self::parcial($emp, $tipo), $par); throw $e; } }
                foreach ($r[$lista] ?? [] as $x) { $t = AoTitulo::deCadastro($x, $tipo); $par['itens'][(string)$t['id']] = $t; }
                $par['tot'] = (int)($r['total_de_paginas'] ?? 1); $par['pag']++;
                if ($par['pag'] <= $par['tot'] && microtime(true) > $prazo) {
                    $par['mexido'] = time(); self::gravar(self::parcial($emp, $tipo), $par);
                    return ['ok' => true, 'continuar' => true, 'msg' => "$rot: página " . ($par['pag'] - 1) . " de {$par['tot']} lida…"];
                }
            } while ($par['pag'] <= $par['tot'] && $par['pag'] < 500);
            $novo = ['completa' => time(), 'atualizada' => time(), 'itens' => $par['itens']];
            self::gravar(self::arq($emp, $tipo), $novo);
            @unlink(self::parcial($emp, $tipo));
            return ['ok' => true, 'continuar' => false, 'msg' => "$rot: leitura completa — " . count($novo['itens']) . ' título(s)', 'n' => count($novo['itens'])];
        } finally { flock($lk, LOCK_UN); fclose($lk); }
    }

    /** grava no cache o título como ficou depois de uma alteração (sem nova chamada) */
    public static function lembrar(string $emp, array $t): void
    {
        $tipo = (string)$t['tipo']; $f = self::arq($emp, $tipo);
        $lk = fopen(self::dir() . "/lock-$emp-$tipo", 'c'); if ($lk) flock($lk, LOCK_EX);
        try {
            $j = self::titulos($emp, $tipo); if (!$j) return;
            $velho = $j['itens'][(string)$t['id']] ?? [];
            foreach ($t as $k => $v) if ($v !== null && $k !== '_fonte') $velho[$k] = $v;   // a Pesquisa não traz alguns campos: mantém os do cache
            $j['itens'][(string)$t['id']] = $velho;
            self::gravar($f, $j);
        } finally { if ($lk) { flock($lk, LOCK_UN); fclose($lk); } }
    }

    /* ---------------- catálogo ---------------- */

    private static function paginar(string $emp, string $path, string $met, string $lista, array $extra = []): array
    {
        $out = []; $pag = 1;
        do {
            try { $r = OmieApi::call($emp, $path, $met, ['pagina' => $pag, 'registros_por_pagina' => 100] + $extra); }
            catch (RuntimeException $e) { if (preg_match('/n[ãa]o existem registros|Client-5113/iu', $e->getMessage())) break; throw $e; }
            foreach ($r[$lista] ?? [] as $x) $out[] = $x;
            $tot = (int)($r['total_de_paginas'] ?? 1); $pag++;
        } while ($pag <= $tot && $pag < 50);
        return $out;
    }

    /** categorias, contas, departamentos e projetos da empresa (cache 6 h) */
    public static function catalogo(string $emp, bool $forcar = false): array
    {
        $f = self::dir() . "/catalogo-$emp.json"; $j = self::ler($f);
        if (!$forcar && $j && (time() - (int)$j['quando']) < self::CATALOGO_SEG) return $j;
        if ($forcar && $j && (time() - (int)$j['quando']) < 120) return $j;   // não repetir em seguida (REDUNDANT)
        $c = ['cat' => [], 'cc' => [], 'deps' => [], 'proj' => [], 'quando' => time()];
        foreach (self::paginar($emp, 'geral/categorias/', 'ListarCategorias', 'categoria_cadastro') as $x)
            $c['cat'][(string)$x['codigo']] = ['d' => (string)$x['descricao'], 'lanc' => ($x['totalizadora'] ?? 'N') !== 'S' && ($x['conta_inativa'] ?? 'N') !== 'S',
                'desp' => ($x['conta_despesa'] ?? '') === 'S', 'rec' => ($x['conta_receita'] ?? '') === 'S'];
        foreach (self::paginar($emp, 'geral/contacorrente/', 'ListarContasCorrentes', 'ListarContasCorrentes') as $x)
            $c['cc'][(string)$x['nCodCC']] = ['d' => (string)$x['descricao'], 'ativa' => ($x['inativo'] ?? 'N') !== 'S'];
        foreach (self::paginar($emp, 'geral/departamentos/', 'ListarDepartamentos', 'departamentos') as $x)
            $c['deps'][(string)$x['codigo']] = ['d' => (string)$x['descricao'], 'ativa' => ($x['inativo'] ?? 'N') !== 'S'];
        try {
            foreach (self::paginar($emp, 'geral/projetos/', 'ListarProjetos', 'cadastro') as $x)
                $c['proj'][(string)$x['codigo']] = ['d' => (string)$x['nome'], 'ativa' => ($x['inativo'] ?? 'N') !== 'S'];
        } catch (Throwable $e) { /* empresa sem projetos */ }
        self::gravar($f, $c);
        return $c;
    }

    /** fornecedores/clientes: código → [nome, doc, chave pix]. Pode lançar OmieContinuar na primeira leitura (lista grande). */
    public static function cadastros(string $emp, bool $atualizar = false): array
    {
        $j = OmieEnvio::cadastros($emp, $atualizar);
        $out = [];
        foreach (['por_doc', 'por_nome'] as $ix) foreach ($j[$ix] ?? [] as $lst) foreach ($lst as $c) {
            $id = (string)$c['codigo_cliente_omie'];
            if (isset($out[$id])) continue;
            $nome = trim((string)$c['razao_social']) !== '' ? (string)$c['razao_social'] : (string)$c['nome_fantasia'];
            $out[$id] = ['nome' => $nome, 'fant' => (string)$c['nome_fantasia'], 'doc' => (string)$c['cnpj_cpf'], 'chave' => (string)($c['dadosBancarios']['cChavePix'] ?? ''), 'inativo' => ($c['inativo'] ?? 'N') === 'S'];
        }
        return $out;
    }

    /** contexto para o motor de regras (nomes simples) */
    public static function contexto(string $emp): array
    {
        $c = self::catalogo($emp);
        $f = self::cadastros($emp);
        return [
            'cat' => $c['cat'],
            'cc' => array_map(fn($x) => $x['d'], $c['cc']),
            'deps' => array_map(fn($x) => $x['d'], $c['deps']),
            'proj' => array_map(fn($x) => $x['d'], $c['proj']),
            'forn' => array_map(fn($x) => $x['nome'], $f),
            'pix' => array_map(fn($x) => ['chave' => $x['chave'], 'doc' => $x['doc'], 'nome' => $x['nome']], $f),
        ];
    }
    /** nomes para AoTitulo::mostrar */
    public static function nomes(array $ctx): array
    {
        return ['cat' => array_map(fn($x) => $x['d'], $ctx['cat']), 'cc' => $ctx['cc'], 'deps' => $ctx['deps'], 'proj' => $ctx['proj'], 'forn' => $ctx['forn']];
    }
}
