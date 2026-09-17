<?php
/* =====================================================================
   recibos-omie/lib/OmieApi.php — cliente mínimo da API do Omie (curl).
   Chaves ficam FORA do repositório, no config.php:
     define('OMIE_CONTAS', '{"vertical":{"nome":"VERTICAL","app_key":"…","app_secret":"…"},
                             "camargo":{"nome":"IMOBILIARIA CAMARGO","app_key":"…","app_secret":"…"}}');
   Sem OMIE_CONTAS a ferramenta funciona só com o relatório .xlsx (sem validação).
   ===================================================================== */
declare(strict_types=1);

final class OmieApi
{
    public const URL = 'https://app.omie.com.br/api/v1/';

    /** @return array<string, array{nome:string, app_key:string, app_secret:string}> */
    public static function contas(): array
    {
        if (!defined('OMIE_CONTAS')) return [];
        $v = OMIE_CONTAS;
        if (is_string($v)) $v = json_decode($v, true) ?: [];
        $out = [];
        foreach ((array)$v as $id => $c) if (!empty($c['app_key']) && !empty($c['app_secret'])) $out[(string)$id] = ['nome' => (string)($c['nome'] ?? strtoupper((string)$id)), 'app_key' => (string)$c['app_key'], 'app_secret' => (string)$c['app_secret']];
        return $out;
    }
    public static function disponivel(): bool { return (bool)self::contas(); }

    /** chamada única; lança RuntimeException com a mensagem do Omie */
    public static function call(string $conta, string $path, string $method, array $param = []): array
    {
        $c = self::contas()[$conta] ?? null;
        if (!$c) throw new RuntimeException("conta Omie '$conta' não configurada (OMIE_CONTAS no config.php)");
        $body = json_encode(['call' => $method, 'app_key' => $c['app_key'], 'app_secret' => $c['app_secret'], 'param' => [$param ?: new stdClass()]], JSON_UNESCAPED_UNICODE);
        $ch = curl_init(self::URL . ltrim($path, '/'));
        curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => $body, CURLOPT_HTTPHEADER => ['Content-Type: application/json'], CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 60]);
        $ca = getenv('CURL_CA_BUNDLE') ?: getenv('SSL_CERT_FILE'); if ($ca && is_file($ca)) curl_setopt($ch, CURLOPT_CAINFO, $ca);
        $raw = curl_exec($ch); $err = curl_error($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        if ($raw === false) throw new RuntimeException('Omie indisponível: ' . $err);
        $j = json_decode((string)$raw, true);
        if (!is_array($j)) throw new RuntimeException("resposta inesperada do Omie (HTTP $code)");
        if (isset($j['faultstring'])) throw new RuntimeException('Omie: ' . $j['faultstring'] . (isset($j['faultcode']) ? ' [' . $j['faultcode'] . ']' : ''));
        return $j;
    }

    /** títulos a pagar por vencimento (PesquisarLancamentos, paginado). [] de cabecTitulo + resumo */
    public static function titulosPagar(string $conta, string $deIso, string $ateIso, int $maxPaginas = 20): array
    {
        $out = []; $pag = 1;
        do {
            $r = self::call($conta, 'financas/pesquisartitulos/', 'PesquisarLancamentos', ['nPagina' => $pag, 'nRegPorPagina' => 500, 'cNatureza' => 'P',
                'dDtVencDe' => self::br($deIso), 'dDtVencAte' => self::br($ateIso), 'cOrdenarPor' => 'DATA_VENCIMENTO']);
            foreach ($r['titulosEncontrados'] ?? [] as $t) $out[] = $t;
            $tot = (int)($r['nTotPaginas'] ?? 1); $pag++;
        } while ($pag <= $tot && $pag <= $maxPaginas);
        return $out;
    }

    /** procura um título pelo CNPJ/CPF + vencimento (validação do relatório) */
    public static function procurarTitulo(string $conta, string $doc, string $vencIso): array
    {
        $r = self::call($conta, 'financas/pesquisartitulos/', 'PesquisarLancamentos', ['nPagina' => 1, 'nRegPorPagina' => 100, 'cNatureza' => 'P', 'cCPFCNPJCliente' => $doc, 'dDtVencDe' => self::br($vencIso), 'dDtVencAte' => self::br($vencIso)]);
        return $r['titulosEncontrados'] ?? [];
    }

    /** fornecedor por código Omie, com cache em ro_fornecedores */
    public static function fornecedor(PDO $pdo, string $conta, int $codigo): array
    {
        $st = $pdo->prepare('SELECT * FROM ro_fornecedores WHERE conta = ? AND codigo_omie = ?'); $st->execute([$conta, $codigo]);
        if ($f = $st->fetch()) return $f;
        try { $r = self::call($conta, 'geral/clientes/', 'ConsultarCliente', ['codigo_cliente_omie' => $codigo]); }
        catch (Throwable $e) { return ['codigo_omie' => $codigo, 'razao_social' => '', 'nome_fantasia' => '', 'cnpj_cpf' => '']; }
        $f = ['conta' => $conta, 'codigo_omie' => $codigo, 'razao_social' => (string)($r['razao_social'] ?? ''), 'nome_fantasia' => (string)($r['nome_fantasia'] ?? ''), 'cnpj_cpf' => (string)($r['cnpj_cpf'] ?? '')];
        $pdo->prepare('INSERT INTO ro_fornecedores (conta, codigo_omie, razao_social, nome_fantasia, cnpj_cpf) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE razao_social = VALUES(razao_social), nome_fantasia = VALUES(nome_fantasia), cnpj_cpf = VALUES(cnpj_cpf)')
            ->execute([$conta, $codigo, $f['razao_social'], $f['nome_fantasia'], $f['cnpj_cpf']]);
        return $f;
    }

    /** categorias código → descrição (cache 1 dia em ro_categorias) */
    public static function categorias(PDO $pdo, string $conta): array
    {
        $st = $pdo->prepare('SELECT codigo, descricao, atualizado_em FROM ro_categorias WHERE conta = ?'); $st->execute([$conta]);
        $rows = $st->fetchAll(); $map = [];
        $velho = !$rows || strtotime((string)$rows[0]['atualizado_em']) < time() - 86400;
        foreach ($rows as $r) $map[$r['codigo']] = $r['descricao'];
        if ($velho) {
            try {
                $pag = 1;
                do {
                    $r = self::call($conta, 'geral/categorias/', 'ListarCategorias', ['pagina' => $pag, 'registros_por_pagina' => 500]);
                    foreach ($r['categoria_cadastro'] ?? [] as $c) $map[(string)$c['codigo']] = (string)$c['descricao'];
                    $tot = (int)($r['total_de_paginas'] ?? 1); $pag++;
                } while ($pag <= $tot && $pag < 10);
                $ins = $pdo->prepare('INSERT INTO ro_categorias (conta, codigo, descricao, atualizado_em) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE descricao = VALUES(descricao), atualizado_em = NOW()');
                foreach ($map as $k => $v) $ins->execute([$conta, $k, $v]);
            } catch (Throwable $e) { /* fica com o cache */ }
        }
        return $map;
    }

    public static function br(string $iso): string { return preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) ? "$m[3]/$m[2]/$m[1]" : $iso; }
    public static function iso(?string $br): ?string { return $br && preg_match('/^(\d{2})\/(\d{2})\/(\d{4})/', $br, $m) ? "$m[3]-$m[2]-$m[1]" : null; }
}
