<?php
/* =====================================================================
   busca-bancos/lib/OfxExport.php — recria extratos .ofx (um por conta e por mês) a partir dos lançamentos
   já importados, e monta um .zip organizado em ANO / MÊS, com o nome da empresa no arquivo.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/Busca.php';

final class BbOfxExport
{
    public const MESES = ['01' => 'JANEIRO', '02' => 'FEVEREIRO', '03' => 'MARÇO', '04' => 'ABRIL', '05' => 'MAIO', '06' => 'JUNHO',
        '07' => 'JULHO', '08' => 'AGOSTO', '09' => 'SETEMBRO', '10' => 'OUTUBRO', '11' => 'NOVEMBRO', '12' => 'DEZEMBRO'];
    private const EMP = ['camargo' => 'IMOBILIARIA CAMARGO', 'vertical' => 'VERTICAL', 'mz7' => 'MZ7'];

    /** texto sem acento, só caracteres seguros para nome de arquivo/pasta */
    public static function seguro(string $s): string
    {
        $s = strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c',
            'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','É'=>'E','Ê'=>'E','Í'=>'I','Ó'=>'O','Ô'=>'O','Õ'=>'O','Ú'=>'U','Ç'=>'C','·'=>'-']);
        return trim(preg_replace('/\s+/', ' ', preg_replace('/[^A-Za-z0-9 ._()-]+/', ' ', $s) ?? '') ?? '');
    }

    /** escapa texto para dentro de uma tag OFX (SGML) em Windows-1252 */
    private static function txt(string $s, int $max = 255): string
    {
        $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
        $s = mb_substr($s, 0, $max);
        $s = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
        $c = @mb_convert_encoding($s, 'Windows-1252', 'UTF-8');
        return $c === false ? $s : $c;
    }

    /** saldo no fim do dia $d, se dá para calcular com certeza a partir de um saldo informado num extrato (sem buraco no meio) */
    private static function saldoEm(PDO $pdo, int $cid, string $d, array $int, array $refs): ?float
    {
        $melhor = null;
        foreach ($refs as $r) {
            [$a, $b] = $r['data'] <= $d ? [$r['data'], $d] : [$d, $r['data']];
            if ($a !== $b && BbCobertura::buracos($int, date('Y-m-d', strtotime($a . ' +1 day')), $b)) continue;
            $dist = abs(strtotime($r['data']) - strtotime($d));
            if ($melhor === null || $dist < $melhor[0]) $melhor = [$dist, $r];
        }
        if ($melhor === null) return null;
        $r = $melhor[1];
        $st = $pdo->prepare('SELECT COALESCE(SUM(valor), 0) FROM bb_lanc WHERE conta_id = ? AND data > ? AND data <= ?');
        if ($r['data'] <= $d) { $st->execute([$cid, $r['data'], $d]); return round($r['saldo'] + (float)$st->fetchColumn(), 2); }
        $st->execute([$cid, $d, $r['data']]); return round($r['saldo'] - (float)$st->fetchColumn(), 2);
    }

    /** um OFX de uma conta num intervalo */
    public static function ofx(array $conta, string $de, string $ate, array $lanc, ?float $saldo, string $saldoData): string
    {
        $dt = fn(string $d) => str_replace('-', '', $d) . '120000[-3:BRT]';
        $bankId = $conta['bank_id'] !== '' ? $conta['bank_id'] : '000';
        $o = "OFXHEADER:100\r\nDATA:OFXSGML\r\nVERSION:102\r\nSECURITY:NONE\r\nENCODING:USASCII\r\nCHARSET:1252\r\nCOMPRESSION:NONE\r\nOLDFILEUID:NONE\r\nNEWFILEUID:NONE\r\n\r\n";
        $o .= "<OFX>\n<SIGNONMSGSRSV1>\n<SONRS>\n<STATUS>\n<CODE>0\n<SEVERITY>INFO\n</STATUS>\n<DTSERVER>" . date('YmdHis') . "[-3:BRT]\n<LANGUAGE>POR\n<FI>\n<ORG>"
            . self::txt(BbOfx::BANCOS[$conta['banco']] ?? 'Banco') . "\n<FID>" . self::txt($bankId) . "\n</FI>\n</SONRS>\n</SIGNONMSGSRSV1>\n";
        $o .= "<BANKMSGSRSV1>\n<STMTTRNRS>\n<TRNUID>1\n<STATUS>\n<CODE>0\n<SEVERITY>INFO\n</STATUS>\n<STMTRS>\n<CURDEF>BRL\n<BANKACCTFROM>\n<BANKID>" . self::txt($bankId)
            . "\n" . ($conta['agencia'] !== '' ? '<BRANCHID>' . self::txt($conta['agencia']) . "\n" : '') . '<ACCTID>' . self::txt($conta['conta']) . "\n<ACCTTYPE>CHECKING\n</BANKACCTFROM>\n";
        $o .= "<BANKTRANLIST>\n<DTSTART>" . $dt($de) . "\n<DTEND>" . $dt($ate) . "\n";
        $usados = [];
        foreach ($lanc as $l) {
            $v = (float)$l['valor'];
            $tipo = strtoupper((string)$l['tipo_ofx']);
            if ($tipo === '' || ($v < 0 && $tipo === 'CREDIT') || ($v > 0 && in_array($tipo, ['DEBIT', 'PAYMENT', 'FEE'], true))) $tipo = $v < 0 ? 'DEBIT' : 'CREDIT';
            $fit = (string)$l['fitid'];
            if ($fit === '' || isset($usados[$fit])) $fit = 'BB' . $l['id'];
            $usados[$fit] = 1;
            $o .= "<STMTTRN>\n<TRNTYPE>" . self::txt($tipo, 20) . "\n<DTPOSTED>" . $dt($l['data']) . "\n<TRNAMT>" . number_format($v, 2, '.', '') . "\n<FITID>" . self::txt($fit, 80) . "\n";
            if ($l['checknum'] !== '') $o .= '<CHECKNUM>' . self::txt($l['checknum'], 40) . "\n";
            if ($l['refnum'] !== '') $o .= '<REFNUM>' . self::txt($l['refnum'], 40) . "\n";
            $o .= '<MEMO>' . self::txt($l['memo'] !== '' ? $l['memo'] : ($l['contraparte'] ?: '-')) . "\n";
            if ($l['nome'] !== '') $o .= '<NAME>' . self::txt($l['nome'], 120) . "\n";
            $o .= "</STMTTRN>\n";
        }
        $o .= "</BANKTRANLIST>\n";
        if ($saldo !== null) $o .= "<LEDGERBAL>\n<BALAMT>" . number_format($saldo, 2, '.', '') . "\n<DTASOF>" . $dt($saldoData) . "\n</LEDGERBAL>\n";
        return $o . "</STMTRS>\n</STMTTRNRS>\n</BANKMSGSRSV1>\n</OFX>\n";
    }

    /**
     * Monta o .zip em $destino. Estrutura: EXTRATOS OFX/ANO/MM - MÊS/EMPRESA - BANCO CONTA.ofx (+ LEIA-ME.txt).
     * Só entram meses com extrato importado (coberto) ou com lançamento. Devolve ['arquivos' => n, 'lanc' => n].
     */
    public static function zip(PDO $pdo, string $destino, ?array $soEmpresas = null): array
    {
        $z = new ZipArchive();
        if ($z->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) throw new RuntimeException('não foi possível criar o .zip');
        $raiz = 'EXTRATOS OFX - BUSCA BANCOS';
        $contas = BbCobertura::contas($pdo); $ints = BbCobertura::intervalos($pdo);
        $refsPor = [];
        foreach ($pdo->query('SELECT conta_id, saldo_final, COALESCE(saldo_data, periodo_ate) d FROM bb_arquivos WHERE saldo_final IS NOT NULL') as $r) $refsPor[(int)$r['conta_id']][] = ['saldo' => (float)$r['saldo_final'], 'data' => $r['d']];
        $st = $pdo->prepare('SELECT id, data, valor, tipo_ofx, fitid, checknum, refnum, memo, nome, contraparte FROM bb_lanc WHERE conta_id = ? AND data BETWEEN ? AND ? ORDER BY data, id');
        $nArq = 0; $nLanc = 0; $leia = []; $ontem = date('Y-m-d', strtotime('-1 day'));
        foreach ($contas as $cid => $c) {
            if ($soEmpresas && !in_array($c['empresa'], $soEmpresas, true)) continue;
            $int = $ints[$cid] ?? [];
            $mm = $pdo->prepare('SELECT MIN(data), MAX(data) FROM bb_lanc WHERE conta_id = ?'); $mm->execute([$cid]); [$lMin, $lMax] = $mm->fetch(PDO::FETCH_NUM);
            $ini = min(array_filter([$int ? $int[0][0] : null, $lMin])); $fim = max(array_filter([$int ? end($int)[1] : null, $lMax]));
            if (!$ini) continue;
            $emp = self::EMP[$c['empresa']] ?? 'EMPRESA NAO INFORMADA';
            $nomeArq = self::seguro($emp . ' - ' . strtoupper(self::seguro(BbOfx::BANCOS[$c['banco']] ?? 'Banco')) . ' ' . $c['conta']) . '.ofx';
            for ($m = substr($ini, 0, 7); $m <= substr($fim, 0, 7); $m = date('Y-m', strtotime($m . '-01 +1 month'))) {
                $a = $m . '-01'; $b = date('Y-m-t', strtotime($a));
                $st->execute([$cid, $a, $b]); $lanc = $st->fetchAll();
                // parte do mês coberta por extrato (o arquivo diz de quando a quando vai)
                $cob = []; foreach ($int as [$x, $y]) if ($y >= $a && $x <= $b) $cob[] = [max($x, $a), min($y, $b)];
                if (!$lanc && !$cob) continue;
                $de = $cob ? $cob[0][0] : $lanc[0]['data']; $ate = $cob ? end($cob)[1] : end($lanc)['data'];
                if ($lanc) { $de = min($de, $lanc[0]['data']); $ate = max($ate, end($lanc)['data']); }
                $saldo = isset($refsPor[$cid]) ? self::saldoEm($pdo, $cid, $ate, $int, $refsPor[$cid]) : null;
                $pasta = $raiz . '/' . substr($m, 0, 4) . '/' . substr($m, 5) . ' - ' . self::seguro(self::MESES[substr($m, 5)]);
                $z->addFromString($pasta . '/' . $nomeArq, self::ofx($c, $de, $ate, $lanc, $saldo, $ate));
                $nArq++; $nLanc += count($lanc);
                $faltas = BbCobertura::buracos($int, $a, min($b, $ontem));
                if ($faltas && $a <= $ontem) $leia[] = substr($m, 0, 4) . '/' . substr($m, 5) . ' · ' . $emp . ' · ' . (BbOfx::BANCOS[$c['banco']] ?? '') . ' ' . $c['conta'] . ': sem extrato em '
                    . implode(', ', array_map(fn($f) => BbTexto::dataBr($f[0]) . ($f[1] !== $f[0] ? ' a ' . BbTexto::dataBr($f[1]) : ''), $faltas));
            }
        }
        $txt = "Extratos recriados pela Busca Bancos (portal.imobcamargo.com.br) em " . date('d/m/Y H:i') . ".\r\n\r\n"
            . "Cada arquivo .ofx tem os lançamentos de UMA conta em UM mês, montados a partir de tudo o que foi importado na ferramenta\r\n"
            . "(OFX dos bancos, PDF da Caixa e Omie Cash pela API), sem lançamentos repetidos. Não são os arquivos originais do banco.\r\n"
            . "DTSTART/DTEND de cada arquivo = parte do mês que estava coberta por extrato. O saldo (LEDGERBAL) só vai quando dá para\r\n"
            . "calcular com certeza a partir de um saldo informado pelo banco.\r\n\r\n"
            . "Total: $nArq arquivo(s), $nLanc lançamento(s).\r\n\r\n"
            . ($leia ? "MESES INCOMPLETOS (dias sem extrato importado):\r\n" . implode("\r\n", $leia) . "\r\n" : "Nenhum mês incompleto.\r\n");
        $z->addFromString($raiz . '/LEIA-ME.txt', $txt);
        $z->close();
        return ['arquivos' => $nArq, 'lanc' => $nLanc];
    }
}
