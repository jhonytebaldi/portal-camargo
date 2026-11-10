<?php
/* =====================================================================
   busca-bancos/lib/Importador.php — grava um extrato no banco de dados
   sem duplicar, e marca tarifas anuladas e transferências internas.

   Impressão digital de um lançamento = conta + data + valor + descrição +
   nome + "ordem entre iguais". Não usa o FITID: o do Sicoob é inventado
   (data+valor+sequência) e pode mudar a cada exportação. A "ordem entre
   iguais" deixa passar 2 Pix legítimos do mesmo valor/pessoa no mesmo dia.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/Ofx.php';

final class BbImportador
{
    public const EMPRESAS = ['camargo' => 'Imobiliária Camargo', 'vertical' => 'Vertical', 'mz7' => 'MZ7'];
    /** trechos (normalizados) que indicam "é a própria empresa do outro lado" — editável em Contas */
    public const PROPRIOS_PADRAO = ['mesma tit', 'michael bruno da', 'imobiliaria camargo', 'camargo gestao', 'vertical gestao', 'mz7'];

    public static function config(PDO $pdo, string $chave, mixed $padrao = null): mixed
    {
        $st = $pdo->prepare('SELECT valor FROM bb_config WHERE chave = ?'); $st->execute([$chave]);
        $v = $st->fetchColumn();
        return $v === false ? $padrao : (json_decode((string)$v, true) ?? $padrao);
    }
    public static function configSet(PDO $pdo, string $chave, mixed $valor): void
    {
        $pdo->prepare('INSERT INTO bb_config (chave, valor) VALUES (?, ?) ON DUPLICATE KEY UPDATE valor = VALUES(valor)')->execute([$chave, json_encode($valor, JSON_UNESCAPED_UNICODE)]);
    }

    /** empresa sugerida pelo caminho da pasta do Drive (IMOBILIARIA/…, VERTICAL/…, MZ7/…) */
    public static function empresaDoCaminho(?string $caminho): string
    {
        $n = BbTexto::norm((string)$caminho);
        if (preg_match('/\bmz7\b/', $n)) return 'mz7';
        if (preg_match('/\bvertical\b/', $n)) return 'vertical';
        if (preg_match('/\b(imobiliaria|imobliaria|camargo)\b/', $n)) return 'camargo';
        return '';
    }

    /**
     * Importa o conteúdo de um OFX. $meta: nome, origem (upload|drive), drive_id, drive_caminho, usuario, empresa (sugestão).
     * Devolve uma lista (um item por conta dentro do arquivo):
     * ['status'=>'ok'|'repetido', 'conta'=>linha bb_contas, 'conta_nova'=>bool, 'novos','dup','total','de','ate','avisos'=>[]]
     */
    public static function importarOfx(PDO $pdo, string $bruto, array $meta = []): array
    {
        $res = [];
        foreach (BbOfx::ler($bruto) as $ext) $res[] = self::gravar($pdo, $ext, sha1($bruto), $meta + ['formato' => 'ofx']);
        return $res;
    }

    /** $ext no formato de BbOfx::ler (também usado para extratos convertidos de PDF) */
    public static function gravar(PDO $pdo, array $ext, string $sha1, array $meta): array
    {
        $avisos = [];
        $sugestao = (string)($meta['empresa'] ?? '') ?: self::empresaDoCaminho($meta['drive_caminho'] ?? null);
        $contaNum = preg_replace('/\s+/', '', $ext['conta']);
        $st = $pdo->prepare('SELECT * FROM bb_contas WHERE bank_id = ? AND conta = ?'); $st->execute([$ext['bank_id'], $contaNum]);
        $conta = $st->fetch(); $nova = false;
        if (!$conta) {
            $apelido = (BbOfx::BANCOS[$ext['banco']] ?? 'Banco') . ' · ' . ($ext['agencia'] !== '' ? $ext['agencia'] . ' / ' : '') . $contaNum;
            $pdo->prepare('INSERT INTO bb_contas (banco, bank_id, agencia, conta, empresa, apelido) VALUES (?,?,?,?,?,?)')
                ->execute([$ext['banco'], $ext['bank_id'], $ext['agencia'], $contaNum, $sugestao, mb_substr($apelido, 0, 80)]);
            $st->execute([$ext['bank_id'], $contaNum]); $conta = $st->fetch(); $nova = true;
            if ($sugestao === '') $avisos[] = 'Conta nova: informe de qual empresa ela é em "Contas".';
        } elseif ($sugestao !== '' && $conta['empresa'] !== '' && $conta['empresa'] !== $sugestao) {
            $avisos[] = 'Atenção: o arquivo está na pasta da ' . (self::EMPRESAS[$sugestao] ?? $sugestao) . ', mas esta conta está cadastrada como ' . (self::EMPRESAS[$conta['empresa']] ?? $conta['empresa']) . '.';
        } elseif ($sugestao !== '' && $conta['empresa'] === '') {
            $pdo->prepare('UPDATE bb_contas SET empresa = ? WHERE id = ?')->execute([$sugestao, $conta['id']]); $conta['empresa'] = $sugestao;
        }
        $cid = (int)$conta['id'];
        // o Inter informa o saldo do dia em que o arquivo foi baixado, não o do fim do período: esse saldo não serve para conferir
        if ($ext['saldo'] !== null && $ext['saldo_data'] !== null && $ext['saldo_data'] > date('Y-m-d', strtotime($ext['ate'] . ' +1 day'))) { $ext['saldo'] = null; $ext['saldo_data'] = null; }
        $base = ['conta' => $conta, 'conta_nova' => $nova, 'de' => $ext['de'], 'ate' => $ext['ate'], 'total' => count($ext['lanc'])];

        $st = $pdo->prepare('SELECT id FROM bb_arquivos WHERE conta_id = ? AND sha1 = ?'); $st->execute([$cid, $sha1]);
        if ($st->fetchColumn()) return $base + ['status' => 'repetido', 'novos' => 0, 'dup' => count($ext['lanc']), 'avisos' => ['Este mesmo arquivo já tinha sido importado — nada mudou.']];

        $pdo->beginTransaction();
        try {
            $pdo->prepare('INSERT INTO bb_arquivos (conta_id, sha1, nome, origem, formato, drive_id, drive_caminho, periodo_de, periodo_ate, saldo_final, saldo_data, n_total, enviado_por, enviado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([$cid, $sha1, mb_substr((string)($meta['nome'] ?? ''), 0, 200), (string)($meta['origem'] ?? 'upload'), (string)($meta['formato'] ?? 'ofx'),
                    $meta['drive_id'] ?? null, isset($meta['drive_caminho']) ? mb_substr((string)$meta['drive_caminho'], 0, 300) : null,
                    $ext['de'], $ext['ate'], $ext['saldo'], $ext['saldo_data'], count($ext['lanc']), $meta['usuario'] ?? null, date('Y-m-d H:i:s')]);
            $aid = (int)$pdo->lastInsertId();

            $st = $pdo->prepare('SELECT fp FROM bb_lanc WHERE conta_id = ? AND data BETWEEN ? AND ?'); $st->execute([$cid, $ext['de'], $ext['ate']]);
            $existe = array_flip($st->fetchAll(PDO::FETCH_COLUMN));
            $ins = $pdo->prepare('INSERT INTO bb_lanc (conta_id, arquivo_id, data, valor, tipo_ofx, categoria, fitid, checknum, refnum, memo, nome, doc, contraparte, busca, fp) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
            $ordem = []; $novos = 0; $dup = 0;
            foreach ($ext['lanc'] as $l) {
                $memoN = BbTexto::norm($l['memo']); $nomeN = BbTexto::norm($l['nome']);
                $chave = $cid . '|' . $l['data'] . '|' . number_format($l['valor'], 2, '.', '') . '|' . $memoN . '|' . $nomeN;
                $k = $ordem[$chave] = ($ordem[$chave] ?? 0) + 1;
                $fp = sha1($chave . '|' . $k);
                if (isset($existe[$fp])) { $dup++; continue; }
                $dd = preg_replace('/\D+/', '', (string)($l['doc'] ?? ''));
                $doc = BbTexto::docValido($dd) ? [$dd, (string)$l['doc']] : BbTexto::acharDoc($l['memo'] . ' ' . $l['nome']);
                $contra = BbTexto::contraparte($l['memo'], $l['nome'], $doc);
                // nº de documento/referência que é só ruído: "0", "Pix" (Sicoob), o próprio FITID (Sicredi) ou o código do banco (Inter manda 077 em tudo)
                $ruido = fn(string $v) => $v === '' || ltrim($v, '0') === '' || BbTexto::norm($v) === 'pix' || $v === $l['fitid'] || ltrim($v, '0') === ltrim((string)$ext['bank_id'], '0');
                $check = $ruido($l['checknum']) ? '' : $l['checknum'];
                $ref = $ruido($l['refnum']) ? '' : $l['refnum'];
                $busca = trim($memoN . ' ' . $nomeN . ' ' . ($doc[0] ?? '') . ' ' . $check . ' ' . ($ref !== $check ? $ref : ''));
                $ins->execute([$cid, $aid, $l['data'], $l['valor'], mb_substr($l['tipo'], 0, 20), BbTexto::categoria($l['tipo'], $memoN . ' ' . $nomeN),
                    mb_substr($l['fitid'], 0, 80), mb_substr($check, 0, 40), mb_substr($ref, 0, 40), mb_substr($l['memo'], 0, 400), mb_substr($l['nome'], 0, 200),
                    $doc[0] ?? '', mb_substr($contra, 0, 200), mb_substr($busca, 0, 800), $fp]);
                $existe[$fp] = 1; $novos++;
            }
            $pdo->prepare('UPDATE bb_arquivos SET n_novos = ?, n_dup = ? WHERE id = ?')->execute([$novos, $dup, $aid]);
            $pdo->commit();
        } catch (Throwable $e) { $pdo->rollBack(); throw $e; }

        if ($novos) { self::marcarAnuladas($pdo, $cid, $ext['de'], $ext['ate']); self::marcarInternas($pdo, $ext['de'], $ext['ate']); }
        if (!$ext['lanc']) $avisos[] = 'O extrato veio sem nenhum lançamento no período (o período conta como coberto).';
        return $base + ['status' => 'ok', 'novos' => $novos, 'dup' => $dup, 'avisos' => $avisos, 'arquivo_id' => $aid];
    }

    /**
     * Extrato da Caixa lido do PDF pelo navegador (assets/pdf-caixa.js). Confere tudo de novo aqui: datas, valores e,
     * principalmente, se saldo anterior ± cada lançamento = saldo impresso em cada linha. Só grava se fechar.
     */
    public static function importarCaixaPdf(PDO $pdo, array $e, array $meta = []): array
    {
        $dt = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && strtotime($v) ? $v : null;
        $de = $dt($e['de'] ?? null); $ate = $dt($e['ate'] ?? null);
        $conta = preg_replace('/[^\d.\-]/', '', (string)($e['conta'] ?? '')); $ag = preg_replace('/\D+/', '', (string)($e['agencia'] ?? ''));
        if (!$de || !$ate || $de > $ate || strlen(preg_replace('/\D+/', '', $conta)) < 6 || !is_numeric($e['saldo_anterior'] ?? null) || !is_array($e['lanc'] ?? null)) throw new RuntimeException('dados do extrato incompletos (conta, período ou saldo anterior)');
        // leitura de imagem pode trocar um dígito da conta: se já existe uma conta da Caixa quase igual, é quase certo que é ela
        $dig = preg_replace('/\D+/', '', $conta);
        foreach ($pdo->query("SELECT conta, apelido FROM bb_contas WHERE banco = 'caixa'") as $c) {
            $d2 = preg_replace('/\D+/', '', $c['conta']);
            if ($c['conta'] !== $conta && ($d2 === $dig || levenshtein($d2, $dig) <= 2)) throw new RuntimeException('a conta lida no PDF (' . $conta . ') é quase igual à conta já cadastrada "' . $c['apelido'] . '" (' . $c['conta'] . '): a leitura deve ter trocado um dígito. Não importei');
        }
        $s = round((float)$e['saldo_anterior'], 2); $lanc = []; $min = date('Y-m-d', strtotime("$de -2 days")); $max = date('Y-m-d', strtotime("$ate +2 days"));
        foreach ($e['lanc'] as $l) {
            $d = $dt($l['data'] ?? null); $v = round((float)($l['valor'] ?? 0), 2); $sl = round((float)($l['saldo'] ?? 0), 2);
            if (!$d || $d < $min || $d > $max || abs($v) < 0.005) throw new RuntimeException('lançamento com data ou valor inválido');
            if (abs($s + $v - $sl) > 0.005) throw new RuntimeException('a conta não fecha em ' . BbTexto::dataBr($d) . ' (' . mb_substr((string)($l['memo'] ?? ''), 0, 40) . '): algum valor foi lido errado. Não importei');
            $s = $sl;
            $lanc[] = ['data' => max($de, min($ate, $d)), 'valor' => $v, 'tipo' => $v > 0 ? 'CREDIT' : 'DEBIT', 'fitid' => mb_substr((string)($l['quando'] ?? ''), 0, 40), 'checknum' => preg_replace('/\D+/', '', (string)($l['checknum'] ?? '')),
                'refnum' => '', 'memo' => trim((string)($l['memo'] ?? '')), 'nome' => trim((string)($l['nome'] ?? '')), 'doc' => (string)($l['doc'] ?? '')];
        }
        $ext = ['bank_id' => '104', 'org' => 'CAIXA ECONOMICA FEDERAL', 'agencia' => $ag, 'conta' => $conta, 'banco' => 'caixa', 'de' => $de, 'ate' => $ate, 'saldo' => $s, 'saldo_data' => $ate, 'lanc' => $lanc];
        return self::gravar($pdo, $ext, sha1(json_encode($ext)), $meta + ['formato' => 'pdf', 'origem' => 'pdf', 'empresa' => self::empresaDoCaminho((string)($e['cliente'] ?? ''))]);
    }

    /** tarifa + "desconto na taxa", pix + "estorno": mesmo dia, mesma conta, mesmo valor com sinal trocado */
    public static function marcarAnuladas(PDO $pdo, int $contaId, string $de, string $ate): int
    {
        $st = $pdo->prepare("SELECT id, data, valor, busca FROM bb_lanc WHERE conta_id = ? AND data BETWEEN ? AND ? AND par_id IS NULL ORDER BY data, id");
        $st->execute([$contaId, $de, $ate]);
        $rows = $st->fetchAll(); $usado = []; $n = 0;
        $upd = $pdo->prepare('UPDATE bb_lanc SET anulada = 1, par_id = ? WHERE id = ?');
        foreach ($rows as $c) {
            if ((float)$c['valor'] <= 0 || !preg_match('/^(desconto n[ao] (taxa|tarifa)|estorno)/', $c['busca'])) continue;
            $melhor = null;
            foreach ($rows as $d) {
                if (isset($usado[$d['id']]) || $d['data'] !== $c['data'] || abs((float)$d['valor'] + (float)$c['valor']) > 0.004) continue;
                if ($melhor === null || abs($d['id'] - $c['id']) < abs($melhor['id'] - $c['id'])) $melhor = $d;
            }
            if (!$melhor) continue;
            $usado[$melhor['id']] = $usado[$c['id']] = 1;
            $upd->execute([$melhor['id'], $c['id']]); $upd->execute([$c['id'], $melhor['id']]); $n++;
        }
        return $n;
    }

    /** saída numa conta própria + entrada de mesmo valor em outra conta própria (até 3 dias), com indício de mesma titularidade */
    public static function marcarInternas(PDO $pdo, string $de, string $ate): int
    {
        $proprios = array_values(array_filter(array_map([BbTexto::class, 'norm'], (array)self::config($pdo, 'proprios', self::PROPRIOS_PADRAO))));
        if (!$proprios) return 0;
        $ehProprio = function (string $busca) use ($proprios): bool { foreach ($proprios as $p) if ($p !== '' && str_contains($busca, $p)) return true; return false; };
        $ini = date('Y-m-d', strtotime($de . ' -4 days')); $fim = date('Y-m-d', strtotime($ate . ' +4 days'));
        $st = $pdo->prepare("SELECT id, conta_id, data, valor, busca FROM bb_lanc WHERE data BETWEEN ? AND ? AND par_id IS NULL AND categoria <> 'tarifa' ORDER BY data, id");
        $st->execute([$ini, $fim]);
        $porValor = [];
        foreach ($st->fetchAll() as $r) $porValor[number_format(abs((float)$r['valor']), 2, '.', '')][] = $r;
        $upd = $pdo->prepare('UPDATE bb_lanc SET interna = 1, par_id = ? WHERE id = ?'); $n = 0;
        foreach ($porValor as $grupo) {
            if (count($grupo) < 2) continue;
            $usado = [];
            foreach ($grupo as $d) {
                if ((float)$d['valor'] >= 0 || isset($usado[$d['id']])) continue;
                $melhor = null; $melhorDist = 99;
                foreach ($grupo as $c) {
                    if ((float)$c['valor'] <= 0 || isset($usado[$c['id']]) || $c['conta_id'] === $d['conta_id']) continue;
                    $dist = (strtotime($c['data']) - strtotime($d['data'])) / 86400;
                    if ($dist < -1 || $dist > 3) continue;
                    if (!$ehProprio($d['busca']) && !$ehProprio($c['busca'])) continue;
                    if (abs($dist) < $melhorDist) { $melhor = $c; $melhorDist = abs($dist); }
                }
                if (!$melhor) continue;
                $usado[$d['id']] = $usado[$melhor['id']] = 1;
                $upd->execute([$melhor['id'], $d['id']]); $upd->execute([$d['id'], $melhor['id']]); $n++;
            }
        }
        return $n;
    }

    /** refaz as marcações de transferência interna (depois de mudar a lista de nomes próprios) */
    public static function refazerInternas(PDO $pdo): int
    {
        $pdo->exec('UPDATE bb_lanc SET interna = 0, par_id = NULL WHERE interna = 1');
        $r = $pdo->query('SELECT MIN(data) a, MAX(data) b FROM bb_lanc')->fetch();
        return $r && $r['a'] ? self::marcarInternas($pdo, $r['a'], $r['b']) : 0;
    }
}
