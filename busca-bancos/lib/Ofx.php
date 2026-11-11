<?php
/* =====================================================================
   busca-bancos/lib/Ofx.php — texto, CPF/CNPJ e leitor de OFX (SGML/XML).

   Os bancos mandam coisas diferentes (visto nos extratos reais):
     Sicredi (748): sem <NAME>; MEMO "TED-105465    35797142000106 NOME…" (doc + nome, cortado em ~80)
     Sicoob  (756): <NAME> "Recebimento Pix NOME **" (cortado/mascarado), MEMO genérico, FITID inventado
     Asaas   (461): MEMO "Cobranca recebida - fatura nr. 778390375 NOME", CHECKNUM = nº da cobrança
   Nenhum informa a chave Pix.
   ===================================================================== */
declare(strict_types=1);

final class BbTexto
{
    /** minúsculas, sem acento, só letras/números/espaço */
    public static function norm(string $s): string
    {
        $s = mb_strtolower($s, 'UTF-8');
        $s = strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n']);
        $s = preg_replace('/[^a-z0-9]+/', ' ', $s) ?? '';
        return trim($s);
    }

    public static function docValido(string $d): bool
    {
        if (strlen($d) === 11) {
            if (preg_match('/^(\d)\1{10}$/', $d)) return false;
            for ($t = 9; $t < 11; $t++) { $s = 0; for ($i = 0; $i < $t; $i++) $s += (int)$d[$i] * (($t + 1) - $i); if ((int)$d[$t] !== (($s * 10) % 11) % 10) return false; }
            return true;
        }
        if (strlen($d) === 14) {
            if (preg_match('/^(\d)\1{13}$/', $d)) return false;
            $p = [[5,4,3,2,9,8,7,6,5,4,3,2], [6,5,4,3,2,9,8,7,6,5,4,3,2]];
            for ($t = 12; $t < 14; $t++) { $s = 0; foreach ($p[$t - 12] as $i => $w) $s += (int)$d[$i] * $w; if ((int)$d[$t] !== ($s % 11 < 2 ? 0 : 11 - $s % 11)) return false; }
            return true;
        }
        return false;
    }

    /** primeiro CPF/CNPJ válido dentro do texto: [dígitos, trecho como aparece] ou null */
    public static function acharDoc(string $texto): ?array
    {
        $re = '/(?<![\d\/])(\d{2}[.\s]?\d{3}[.\s]?\d{3}[\/\s]?\d{4}[-\s]?\d{2}|\d{3}[.\s]?\d{3}[.\s]?\d{3}[-\s]?\d{2})(?!\d)/';
        if (!preg_match_all($re, $texto, $m)) return null;
        foreach ($m[1] as $c) { $d = preg_replace('/\D+/', '', $c); if (self::docValido($d)) return [$d, $c]; }
        return null;
    }

    public static function docFmt(string $d): string
    {
        if (strlen($d) === 11) return preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d);
        if (strlen($d) === 14) return preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d);
        return $d;
    }

    public static function brl(float $v): string { return ($v < 0 ? '−' : '') . 'R$ ' . number_format(abs($v), 2, ',', '.'); }
    public static function dataBr(?string $iso): string { return $iso && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) ? "$m[3]/$m[2]/$m[1]" : ''; }

    public static function categoria(string $tipoOfx, string $n): string
    {
        if ($tipoOfx === 'FEE' || preg_match('/\b(taxa|tarifa|cesta|iof|anuidade|mensalidade)\b/', $n)) return 'tarifa';
        if (str_contains($n, 'pix')) return 'pix';
        if (preg_match('/\b(ted|doc|tev|transf)/', $n)) return 'ted';
        if (preg_match('/\b(boleto|cobranca|liquidacao|titulo|cob bx)/', $n)) return 'boleto';
        if (preg_match('/\b(darf|simples nacional|das|gps|fgts|imposto|tributo)\b/', $n)) return 'imposto';
        if (preg_match('/\b(fatura|cartao|debito visa|maestro)/', $n)) return 'cartao';
        return 'outro';
    }

    /** nome da outra parte, quando dá para separar do resto da descrição */
    public static function contraparte(string $memo, string $nome, ?array $doc): string
    {
        $limpa = fn(string $s) => trim(preg_replace('/[\s*]+$/', '', trim($s)) ?? '');
        if ($nome !== '' && BbTexto::norm($nome) === BbTexto::norm($memo)) return '';   // Inter repete a descrição no nome (DARF, Simples Nacional…)
        if ($nome !== '') {
            $n = preg_replace('/^(recebimento|pagamento|transfer[eê]ncia|devolu[cç][aã]o)\s+(de\s+)?pix\s+/iu', '', $nome) ?? $nome;
            if ($doc) $n = str_replace($doc[1], '', $n);
            return $limpa($n);
        }
        if ($doc && ($p = strpos($memo, $doc[1])) !== false) return $limpa(substr($memo, $p + strlen($doc[1])));
        if (preg_match('/(?:fatura nr\.?|cobran[cç]a)\s*\d+\s+(.+)$/iu', $memo, $m)) return $limpa($m[1]);
        if (preg_match('/\bpara\s+([A-ZÀ-Ú][A-ZÀ-Ú .&\-]{4,})$/u', $memo, $m)) return $limpa($m[1]);
        return '';
    }
}

final class BbOfx
{
    public const BANCOS = [
        'sicoob' => 'Sicoob', 'sicredi' => 'Sicredi', 'caixa' => 'Caixa Econômica', 'asaas' => 'Asaas',
        'inter' => 'Inter', 'omie-cash' => 'Omie Cash', 'outro' => 'Outro banco',
    ];
    private const POR_CODIGO = ['756' => 'sicoob', '748' => 'sicredi', '104' => 'caixa', '461' => 'asaas', '77' => 'inter', '450' => 'omie-cash'];

    public static function bancoDe(string $bankId, string $org): string
    {
        $cod = ltrim(preg_replace('/\D+/', '', $bankId) ?? '', '0');
        if (isset(self::POR_CODIGO[$cod])) return self::POR_CODIGO[$cod];
        $o = BbTexto::norm($org . ' ' . $bankId);
        foreach (['sicoob' => 'sicoob|cooperativo do brasil|bancoob', 'sicredi' => 'sicredi|ccpi', 'asaas' => 'asaas', 'inter' => '\binter\b|intermedium',
                  'caixa' => 'caixa economica|\bcef\b', 'omie-cash' => 'omie|fitbank|fiti'] as $slug => $re) if (preg_match("/$re/", $o)) return $slug;
        return 'outro';
    }

    /**
     * Lê um OFX e devolve os extratos que ele contém (normalmente 1):
     * [['bank_id','org','agencia','conta','banco','de','ate','saldo','saldo_data','lanc'=>[[data,valor,tipo,fitid,checknum,refnum,memo,nome]...]]]
     */
    public static function ler(string $bruto): array
    {
        if (!preg_match('/<OFX>/i', $bruto)) throw new RuntimeException('não parece um arquivo OFX (sem a marca <OFX>)');
        if (!mb_check_encoding($bruto, 'UTF-8')) $bruto = mb_convert_encoding($bruto, 'UTF-8', 'Windows-1252');
        $bruto = str_replace(["\r\n", "\r"], "\n", $bruto);
        $org = self::tag($bruto, 'ORG');
        $out = [];
        if (!preg_match_all('/<(CC)?STMTRS>(.*?)(<\/(CC)?STMTRS>|\z)/is', $bruto, $blocos)) throw new RuntimeException('OFX sem extrato (<STMTRS>)');
        foreach ($blocos[2] as $b) {
            $bankId = self::tag($b, 'BANKID'); $conta = self::tag($b, 'ACCTID');
            if ($conta === '') throw new RuntimeException('OFX sem número de conta (<ACCTID>)');
            $lanc = [];
            preg_match_all('/<STMTTRN>(.*?)(?=<STMTTRN>|<\/BANKTRANLIST>|\z)/is', $b, $ts);
            foreach ($ts[1] as $t) {
                $data = self::data(self::tag($t, 'DTPOSTED'));
                $v = self::tag($t, 'TRNAMT');
                if ($data === null || $v === '') continue;
                if (str_contains($v, ',') && !str_contains($v, '.')) $v = str_replace(',', '.', $v);
                elseif (str_contains($v, ',')) $v = str_replace(',', '', $v);
                $valor = round((float)$v, 2);
                $tipo = strtoupper(self::tag($t, 'TRNTYPE'));
                if ($valor > 0 && in_array($tipo, ['DEBIT', 'PAYMENT'], true)) $valor = -$valor;   // alguns bancos mandam o débito sem sinal
                $lanc[] = ['data' => $data, 'valor' => $valor, 'tipo' => $tipo, 'fitid' => self::tag($t, 'FITID'), 'checknum' => self::tag($t, 'CHECKNUM'),
                           'refnum' => self::tag($t, 'REFNUM'), 'memo' => self::tag($t, 'MEMO'), 'nome' => self::tag($t, 'NAME')];
            }
            $datas = array_column($lanc, 'data');
            $de = self::data(self::tag($b, 'DTSTART')) ?? ($datas ? min($datas) : null);
            $ate = self::data(self::tag($b, 'DTEND')) ?? ($datas ? max($datas) : null);
            if ($de === null || $ate === null) throw new RuntimeException('OFX sem período e sem lançamentos');
            if ($datas) { $de = min($de, min($datas)); $ate = max($ate, max($datas)); }
            $saldo = null; $saldoData = null;
            if (preg_match('/<LEDGERBAL>(.*?)(<\/LEDGERBAL>|\z)/is', $b, $lb)) {
                $s = self::tag($lb[1], 'BALAMT');
                if ($s !== '') { $saldo = round((float)str_replace(',', '.', $s), 2); $saldoData = self::data(self::tag($lb[1], 'DTASOF')); }
            }
            // Arquivo gerado ANTES do fim do período que ele declara (ex.: extrato de outubro baixado em 14/10, com DTEND 31/10):
            // o banco escreve o mês inteiro no cabeçalho, mas só tem o que já tinha caído. A cobertura vale até a véspera da
            // geração — o próprio dia da geração fica parcial. Data de geração = DTSERVER, só se não for anterior ao último
            // lançamento. NÃO usar a data do saldo (LEDGERBAL/DTASOF): o Sicoob põe ali a data do último lançamento, não a do download.
            $maxL = $datas ? max($datas) : null; $gerado = null;
            $dtServer = self::data(self::tag($bruto, 'DTSERVER'));
            if ($dtServer !== null && $dtServer <= $ate && ($maxL === null || $dtServer >= $maxL)) $gerado = $dtServer;
            $ateDecl = $ate;
            if ($gerado !== null) $ate = date('Y-m-d', strtotime($gerado . ' -1 day'));
            $out[] = ['bank_id' => $bankId, 'org' => $org, 'agencia' => self::tag($b, 'BRANCHID'), 'conta' => $conta, 'banco' => self::bancoDe($bankId, $org),
                      'de' => $de, 'ate' => $ate, 'ate_declarado' => $ateDecl, 'gerado' => $dtServer, 'saldo' => $saldo, 'saldo_data' => $saldoData, 'lanc' => $lanc];
        }
        return $out;
    }

    private static function tag(string $bloco, string $nome): string
    {
        return preg_match('/<' . $nome . '>([^<\n]*)/i', $bloco, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')) : '';
    }
    private static function data(string $s): ?string
    {
        return preg_match('/^(\d{4})(\d{2})(\d{2})/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
    }
}
