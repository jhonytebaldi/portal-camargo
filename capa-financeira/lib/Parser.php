<?php
/* =====================================================================
   capa-financeira/lib/Parser.php — parser da Capa Financeira (PHP).

   Porte fiel da implementação de referência em Python (capa_parser.py,
   guardada fora do repositório junto com os casos de teste). Qualquer
   mudança de regra tem que ser feita nos dois e passar no teste de
   paridade (bin/paridade.php). Regras (14/09/2026):
   - bloco de lançamentos achado pela âncora DATA | CONTA | Cliente e
     fornecedor | histórico | A RECEBER | A PAGAR (posição varia);
   - histórico CP: [PREFIXO - ]RECIBO XXXX - FAVORECIDO - FUNÇÃO -
     COMISSAO|BONUS - CLIENTES - CONSTRUTORA - ... - PRONTO|PLANTA -
     VENDA: dd/mm/aaaa[ - COD nnnn];
   - datas de parcela nunca são corrigidas, só alertadas;
   - favorecido = coluna C; divergência com o histórico: abreviação =
     aviso, conflito = GRAVE;
   - natureza cruzada com o bloco BONUS da capa e coluna G;
   - Nota Fiscal (≤20) pela opção B: dicionário de códigos curtos.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/Xlsx.php';

final class CapaParser
{
    public const NF_MAX = 20;
    public const STATUS_IMOVEL = ['PRONTO', 'PLANTA'];
    public const FUNCOES = ['CORRETOR', 'CAPTADOR', 'COORDENADOR', 'INTEGRAÇÃO', 'INTEGRACAO', 'DIRETOR', 'PRE VENDA', 'PRE-VENDA', 'FINANCEIRO', 'SAC'];
    public const NATUREZAS = ['COMISSAO', 'BONUS', 'REPASSE'];

    /** Dicionário padrão de condições → código curto (configurável em cf_config). */
    public const NF_DICT_PADRAO = [
        'SE ENTREGAR IMOVEL'         => 'ENTREGA IMOVEL',
        'SE ENTREGAR AS CHAVES'      => 'ENTREGA CHAVES',
        'SE ENTREGAR A CHAVE'        => 'ENTREGA CHAVES',
        'AGUARDAR APROVACAO'         => 'AGUARDA APROVACAO',
        'RESERVA 50%'                => 'RESERVA 50%',
        'RESERVA 50% (FALTA ALVARA)' => 'RESERVA 50% ALVARA',
        'BONUS'                      => 'BONUS',
    ];

    private array $nfDict;
    private string $hoje;   // Y-m-d

    public function __construct(?array $nfDict = null, ?string $hoje = null)
    {
        $this->nfDict = $nfDict ?? self::NF_DICT_PADRAO;
        $this->hoje = $hoje ?? date('Y-m-d');
    }

    /* ---------------- utilidades (iguais ao Python) ---------------- */

    public static function norm(mixed $s): string
    {
        if ($s === null) return '';
        if ($s instanceof DateTimeInterface) $s = $s->format('Y-m-d');
        if (is_bool($s)) $s = $s ? 'True' : 'False';
        if (is_float($s)) $s = self::floatStr($s);
        $s = preg_replace('/\s+/u', ' ', (string)$s) ?? '';
        return trim($s);
    }

    /** Representação de float igual ao str() do Python para os casos comuns. */
    private static function floatStr(float $f): string
    {
        if (floor($f) == $f && abs($f) < 1e15) return sprintf('%.1f', $f);
        return rtrim(rtrim(sprintf('%.10F', $f), '0'), '.');
    }

    public static function stripAccents(string $s): string
    {
        static $map = null;
        if ($map === null) {
            $from = ['À','Á','Â','Ã','Ä','Å','È','É','Ê','Ë','Ì','Í','Î','Ï','Ò','Ó','Ô','Õ','Ö','Ù','Ú','Û','Ü','Ý','Ç','Ñ',
                     'à','á','â','ã','ä','å','è','é','ê','ë','ì','í','î','ï','ò','ó','ô','õ','ö','ù','ú','û','ü','ý','ç','ñ'];
            $to   = ['A','A','A','A','A','A','E','E','E','E','I','I','I','I','O','O','O','O','O','U','U','U','U','Y','C','N',
                     'a','a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','y','c','n'];
            $map = array_combine($from, $to);
        }
        return strtr($s, $map);
    }

    /** Chave de comparação: sem acento, maiúsculas, espaços colapsados, sem " .-" nas bordas. */
    public static function key(mixed $s): string
    {
        $k = mb_strtoupper(self::stripAccents(self::norm($s)), 'UTF-8');
        $k = preg_replace('/\s+/u', ' ', $k) ?? '';
        return trim($k, " .-");
    }

    public static function money(mixed $v): ?float
    {
        if ($v === null || $v === '') return null;
        if ($v instanceof DateTimeInterface) return null;
        if (is_string($v)) {
            $v = trim(str_replace(['R$', '.'], '', $v));
            $v = str_replace(',', '.', $v);
            if ($v === '' || !is_numeric($v)) return null;
        }
        return round((float)$v, 2);
    }

    /** @return array{0:?string,1:string,2:?string}  [Y-m-d|null, raw, flag] */
    public static function parseDateCell(mixed $v): array
    {
        if ($v instanceof DateTimeInterface) return [$v->format('Y-m-d'), $v->format('Y-m-d'), null];
        $raw = self::norm($v);
        if (!preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{2,4})$/', $raw, $m)) return [null, $raw, 'DATA_ILEGIVEL'];
        [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        if (!checkdate($mo, $d, $y) || $y < 1) return [null, $raw, 'DATA_ILEGIVEL'];
        return [sprintf('%04d-%02d-%02d', $y, $mo, $d), $raw, $y < 2000 ? 'ANO_SUSPEITO' : null];
    }

    private function dateAlert(?string $d, ?string $dataVenda): ?string
    {
        if ($d === null) return 'DATA_ILEGIVEL';
        $y = (int)substr($d, 0, 4);
        $anoHoje = (int)substr($this->hoje, 0, 4);
        if ($y < 2000 || $y > $anoHoje + 2) return 'ANO_ABSURDO';
        if ($dataVenda && $d < $dataVenda) return 'ANTERIOR_A_VENDA';
        return null;
    }

    /** True se um nome é o outro encurtado (palavras do menor no maior, na ordem; 1º nome igual). */
    public static function nomeAbreviado(string $a, string $b): bool
    {
        $ta = preg_split('/ /', self::key($a), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $tb = preg_split('/ /', self::key($b), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($ta) < count($tb)) [$ta, $tb] = [$tb, $ta];
        if (!$tb || $tb[0] !== $ta[0]) return false;
        $i = 0;
        foreach ($ta as $w) if ($i < count($tb) && $w === $tb[$i]) $i++;
        return $i === count($tb);
    }

    /* ---------------- histórico ---------------- */

    /** @return array{0:string,1:array,2:array} [tipo, campos, flags] */
    public function parseHist(mixed $hRaw): array
    {
        $h = self::norm($hRaw);
        $out = ['raw' => $h, 'prefixo' => null, 'recibo_marcador' => null, 'favorecido' => null, 'funcao' => null,
                'natureza' => null, 'clientes' => null, 'construtora' => null, 'unidade' => null,
                'status_imovel' => null, 'data_venda_hist' => null, 'cod_hist' => null];
        $flags = [];
        if (preg_match('/\s*-\s*COD\.?\s*(\d*)\s*$/iu', $h, $m, PREG_OFFSET_CAPTURE)) {
            $out['cod_hist'] = $m[1][0] !== '' ? (int)$m[1][0] : null;
            $h = substr($h, 0, $m[0][1]);
        }
        if (preg_match('/VENDA:?\s*(\d{1,2}\/\d{1,2}\/\d{2,4})\s*$/iu', $h, $m, PREG_OFFSET_CAPTURE)) {
            $out['data_venda_hist'] = $m[1][0];
            $h = rtrim(substr($h, 0, $m[0][1]), ' -');
        } else {
            $flags[] = 'HIST_SEM_DATA_VENDA';
        }
        if (stripos($h, 'RECIBO') !== 0 && preg_match('/^(.*?)\s*-\s*(RECIBO\b.*)$/isu', $h, $m)) {
            $out['prefixo'] = self::norm($m[1]);
            $h = $m[2];
        }
        $tipo = stripos($h, 'RECIBO') === 0 ? 'PAGAR' : 'RECEBER';
        $tok = [];
        foreach (preg_split('/\s+-\s*|\s*-\s+/u', $h) ?: [] as $t) { $t = self::norm($t); if ($t !== '') $tok[] = $t; }
        if ($tipo === 'PAGAR') {
            if (count($tok) < 6) { $flags[] = 'HIST_TOKENS_INSUFICIENTES'; return [$tipo, $out, $flags]; }
            $out['recibo_marcador'] = $tok[0]; $out['favorecido'] = $tok[1]; $out['funcao'] = self::key($tok[2]);
            $nat = str_replace('COMISSÃO', 'COMISSAO', self::key($tok[3]));
            $out['natureza'] = $nat;
            if (!in_array($nat, self::NATUREZAS, true)) $flags[] = 'NATUREZA_DESCONHECIDA:' . $tok[3];
            $funcs = array_map([self::class, 'key'], self::FUNCOES);
            if (!in_array($out['funcao'], $funcs, true)) $flags[] = 'FUNCAO_DESCONHECIDA:' . $tok[2];
            $out['clientes'] = $tok[4]; $out['construtora'] = $tok[5]; $resto = array_slice($tok, 6);
        } else {
            if (count($tok) < 2) { $flags[] = 'HIST_TOKENS_INSUFICIENTES'; return [$tipo, $out, $flags]; }
            $out['clientes'] = $tok[0]; $out['construtora'] = $tok[1]; $resto = array_slice($tok, 2);
        }
        $st = array_values(array_filter($resto, fn($t) => in_array(self::key($t), self::STATUS_IMOVEL, true)));
        $out['status_imovel'] = $st ? self::key($st[0]) : null;
        if (!$st) $flags[] = 'HIST_SEM_STATUS_IMOVEL';
        $out['unidade'] = implode(' - ', array_filter($resto, fn($t) => !in_array(self::key($t), self::STATUS_IMOVEL, true)));
        return [$tipo, $out, $flags];
    }

    /* ---------------- Nota Fiscal (opção B) ---------------- */

    /** @return array{0:string,1:bool} [código, cortado?] */
    private function nfCode(string $texto): array
    {
        $k = self::key($texto);
        if (isset($this->nfDict[$k])) return [$this->nfDict[$k], false];
        return [mb_substr($k, 0, self::NF_MAX, 'UTF-8'), mb_strlen($k, 'UTF-8') > self::NF_MAX];
    }

    /** @return array{0:?string,1:?string} [nota_fiscal, condicao] */
    public function buildNf(?string $natureza, ?string $colG, ?string $prefixo, array &$flags): array
    {
        $partes = [];
        if ($natureza === 'BONUS') $partes[] = 'BONUS';
        if ($colG && $prefixo && self::key($colG) !== self::key($prefixo)) {
            $flags[] = "CONDICAO_DIVERGE (G='{$colG}' × prefixo='{$prefixo}')";
            $cond = $colG;
        } else {
            $cond = $colG ?: $prefixo;
        }
        foreach (preg_split('/\s*[\/+;]\s*/u', $cond ?? '') ?: [] as $parte) {
            if ($parte === '') continue;
            if (self::key($parte) === 'BONUS') { if (!in_array('BONUS', $partes, true)) array_unshift($partes, 'BONUS'); continue; }
            [$code, $cut] = $this->nfCode($parte);
            if ($cut) $flags[] = 'CONDICAO_DESCONHECIDA_CORTADA:' . $parte;
            elseif (!isset($this->nfDict[self::key($parte)])) $flags[] = 'CONDICAO_DESCONHECIDA:' . $parte;
            $partes[] = $code;
        }
        $nf = implode('-', $partes);
        if (mb_strlen($nf, 'UTF-8') > self::NF_MAX) { $nf = mb_substr($nf, 0, self::NF_MAX, 'UTF-8'); $flags[] = 'NF_CORTADA_20'; }
        return [$nf !== '' ? $nf : null, $cond ?: null];
    }

    /* ---------------- capa ---------------- */

    private static function findHeader(Xlsx $ws): ?int
    {
        for ($r = 1; $r <= $ws->maxRow(); $r++) {
            if (self::key($ws->cell($r, 1)) === 'DATA' && str_contains(self::key($ws->cell($r, 4)), 'HIST')) return $r;
        }
        return null;
    }

    /** @return array{0:array,1:?string} [capa, data_venda Y-m-d] */
    private static function readCapaHeader(Xlsx $ws): array
    {
        $lab = [];
        for ($r = 1; $r < 30; $r++) {
            $k = self::key($ws->cell($r, 1));
            if ($k !== '' && !isset($lab[$k])) $lab[$k] = $r;
        }
        $val = fn(string $k, int $col = 2) => isset($lab[$k]) ? $ws->cell($lab[$k], $col) : null;
        $cod = $val('COD');
        $g3 = $ws->ref('G3');
        if ($g3 !== null) [$dataVenda, , $dvFlag] = self::parseDateCell($g3);
        else { $dataVenda = null; $dvFlag = 'SEM_DATA_VENDA'; }
        $codOut = null;
        if (is_int($cod) || is_float($cod)) $codOut = (int)$cod;
        elseif ($cod !== null) { $n = self::norm($cod); $codOut = $n !== '' ? $n : null; }
        return [[
            'cliente' => self::norm($val('CLIENTE')),
            'construtora' => self::norm($val('CONTRUTORA') ?? $val('CONSTRUTORA')),
            'bairro' => self::norm($val('OBRA/BAIRRO')),
            'unidade' => self::norm($val('GEMINADO') ?? $val('UNIDADE')),
            'cod' => $codOut,
            'data_venda' => $dataVenda, 'data_venda_flag' => $dvFlag,
            'valor_contrato' => self::money($val('VALOR CONTRATO')), 'vgv' => self::money($val('VGV')),
            'valor_bonus' => self::money($val('VALOR BONUS')),
            'obs_c8' => self::norm($ws->ref('C8')) !== '' ? self::norm($ws->ref('C8')) : null,
        ], $dataVenda];
    }

    private static function readBonusBlock(Xlsx $ws, int $hdr): array
    {
        $vals = []; $start = null;
        for ($r = 1; $r < $hdr; $r++) if (self::key($ws->cell($r, 1)) === 'BONUS') { $start = $r; break; }
        if ($start) {
            for ($r = $start + 1; $r < $start + 10; $r++) {
                if (self::key($ws->cell($r, 5)) === 'TOTAL') break;
                $v = self::money($ws->cell($r, 7));
                if ($v) $vals[(string)$v] = $v;
            }
        }
        $vals = array_values($vals); sort($vals);
        return $vals;
    }

    private static function readFluxo(Xlsx $ws, int $hdr): array
    {
        $out = []; $start = null;
        for ($r = 1; $r < $hdr; $r++) if (str_starts_with(self::key($ws->cell($r, 1)), 'FLUXO DE PAGAMENTO')) { $start = $r + 2; break; }
        if (!$start) return $out;
        for ($r = $start; $r < $hdr; $r++) {
            $a = $ws->cell($r, 1); $b = $ws->cell($r, 2); $e = $ws->cell($r, 5); $g = $ws->cell($r, 7);
            if (self::key($a) === 'TOTAL' || ($a === null && $b === null)) break;
            $d = $e !== null ? self::parseDateCell($e)[0] : null;
            $out[] = ['nome' => self::norm($a), 'valor' => self::money($b), 'data' => $d, 'rotulo' => self::norm($g) !== '' ? self::norm($g) : null];
        }
        return $out;
    }

    /** Resultado com a mesma estrutura do JSON de referência. */
    public function parse(string $path): array
    {
        $ws = Xlsx::open($path);
        [$capa, $dataVenda] = self::readCapaHeader($ws);
        $capaFlags = [];
        if (!$capa['cod']) $capaFlags[] = 'CAPA_SEM_COD';
        if (!$dataVenda) $capaFlags[] = 'CAPA_SEM_DATA_VENDA';
        $hdr = self::findHeader($ws);
        $base = ['arquivo' => basename($path), 'sha256' => substr(hash_file('sha256', $path), 0, 16), 'capa' => $capa];
        if (!$hdr) {
            $capaFlags[] = 'CABECALHO_NAO_ENCONTRADO';
            return $base + ['capa_flags' => $capaFlags, 'linhas' => []];
        }
        $bonusVals = self::readBonusBlock($ws, $hdr);
        $fluxo = self::readFluxo($ws, $hdr);
        $linhas = [];
        for ($r = $hdr + 1; $r <= $ws->maxRow(); $r++) {
            $a = $ws->cell($r, 1); $c = $ws->cell($r, 3); $d = $ws->cell($r, 4);
            $e = $ws->cell($r, 5); $f = $ws->cell($r, 6); $g = $ws->cell($r, 7);
            if (str_starts_with(self::key($a), 'OBSERV')) break;
            if ($c === null && $d === null && $e === null && $f === null) continue;
            $flags = [];
            $tipoCol = $f !== null ? 'PAGAR' : 'RECEBER';
            $valor = self::money($tipoCol === 'PAGAR' ? $f : $e);
            if ($a !== null) [$data, $dataRaw, $dflag] = self::parseDateCell($a);
            else { $data = null; $dataRaw = null; $dflag = 'SEM_DATA'; }
            if ($dflag) $flags[] = $dflag;
            $da = $data ? $this->dateAlert($data, $dataVenda) : null;
            if ($da) $flags[] = $da;
            [$tipoH, $h, $hflags] = $this->parseHist($d);
            foreach ($hflags as $fl) $flags[] = $fl;
            if ($tipoH !== $tipoCol) $flags[] = 'TIPO_COLUNA_X_HISTORICO';
            $cf = self::norm($c);
            // REPASSE (coluna G ou prefixo do histórico): dinheiro do cliente que passa pela imobiliária e vai para a construtora/terceiro.
            // Não é comissão: sem função, favorecido = coluna C, sem conferência de nome com o histórico.
            $repasse = str_contains(self::key($g), 'REPASSE') || str_contains(self::key($h['prefixo'] ?? ''), 'REPASSE') || str_starts_with(self::key($d), 'REPASSE');
            if ($repasse) {
                $h['natureza'] = 'REPASSE'; $h['funcao'] = null;
                // histórico do repasse é texto livre: não tentar extrair cliente/construtora/imóvel dele (vêm da capa)
                foreach (['favorecido', 'clientes', 'construtora', 'unidade', 'status_imovel', 'data_venda_hist', 'cod_hist'] as $k) $h[$k] = null;
                $flags = array_values(array_filter($flags, fn($f) => !str_starts_with($f, 'HIST_') && $f !== 'TIPO_COLUNA_X_HISTORICO'));
                $flags[] = 'REPASSE (favorecido = terceiro; categoria de repasse)';
            }
            if ($tipoCol === 'PAGAR' && !$repasse) {
                if ($h['favorecido'] && self::key($cf) !== self::key($h['favorecido'])) {
                    $flags[] = (self::nomeAbreviado($cf, $h['favorecido']) ? 'FAVORECIDO_ABREVIADO' : 'GRAVE:FAVORECIDO_DIVERGE')
                             . " (coluna C='{$cf}' × histórico='{$h['favorecido']}')";
                }
                if ($h['natureza'] === 'COMISSAO' && (self::key($g) === 'BONUS' || ($valor !== null && in_array($valor, $bonusVals, true)))) {
                    $flags[] = 'GRAVE:PARECE_BONUS (bate com bloco BONUS da capa ou coluna G)';
                }
            }
            if ($h['clientes'] && self::key($h['clientes']) !== self::key($capa['cliente'])) $flags[] = "CLIENTES_HIST_DIFEREM_CAPA ('{$h['clientes']}')";
            if ($h['construtora'] && str_replace(' ', '', self::key($h['construtora'])) !== str_replace(' ', '', self::key($capa['construtora']))) {
                $flags[] = "CONSTRUTORA_HIST_DIFERE_CAPA ('{$h['construtora']}')";
            }
            if ($h['data_venda_hist'] && $dataVenda) {
                [$dh] = self::parseDateCell($h['data_venda_hist']);
                if ($dh !== $dataVenda) {
                    $dv = DateTimeImmutable::createFromFormat('Y-m-d', $dataVenda);
                    $flags[] = "DATA_VENDA_HIST_DIFERE_G3 ({$h['data_venda_hist']} × " . ($dv ? $dv->format('d/m/Y') : $dataVenda) . ')';
                }
            }
            if ($h['cod_hist'] && $capa['cod'] && $h['cod_hist'] != $capa['cod']) $flags[] = "COD_HIST_DIFERE_B6 ({$h['cod_hist']} × {$capa['cod']})";
            $colG = self::norm($g) !== '' ? self::norm($g) : null;
            if ($repasse) { $nf = 'REPASSE'; $cond = $colG && self::key($colG) !== 'REPASSE' ? $colG : null; }
            elseif ($tipoCol === 'PAGAR') [$nf, $cond] = $this->buildNf($h['natureza'], $colG, $h['prefixo'], $flags);
            else { $nf = null; $cond = $colG; }
            $linhas[] = [
                'linha_xlsx' => $r, 'tipo' => $tipoCol, 'data' => $data, 'data_raw' => $dataRaw,
                'cf' => $cf, 'valor' => $valor, 'col_g' => $colG, 'prefixo' => $h['prefixo'],
                'favorecido_hist' => $h['favorecido'], 'funcao' => $h['funcao'], 'natureza' => $h['natureza'],
                'clientes_hist' => $h['clientes'], 'construtora_hist' => $h['construtora'], 'unidade' => $h['unidade'],
                'status_imovel' => $h['status_imovel'], 'data_venda_hist' => $h['data_venda_hist'],
                'nota_fiscal' => $nf, 'condicao' => $cond, 'flags' => $flags,
            ];
        }
        // sequência de datas por grupo
        $grupos = [];
        foreach ($linhas as $i => $l) {
            $gk = $l['tipo'] === 'RECEBER' ? 'R' : 'P|' . self::key($l['cf']) . '|' . $l['funcao'] . '|' . $l['natureza'];
            $grupos[$gk][] = $i;
        }
        foreach ($grupos as $idx) {
            $prev = null;
            foreach ($idx as $i) {
                $d = $linhas[$i]['data'];
                if ($d && $prev && $d < $prev) $linhas[$i]['flags'][] = 'DATA_FORA_DE_SEQUENCIA';
                if ($d) $prev = $prev ? max($prev, $d) : $d;
            }
        }
        // parcelas (CR) pela ordem; fluxo só confere
        $rec = [];
        foreach ($linhas as $i => $l) if ($l['tipo'] === 'RECEBER') $rec[] = $i;
        $n = count($rec);
        foreach ($rec as $k => $i) { $linhas[$i]['parcela'] = $k + 1; $linhas[$i]['total_parcelas'] = $n; }
        $pareado = $n > 0 && $fluxo && $n === count($fluxo);
        if ($pareado) foreach ($rec as $k => $i) if (abs(($linhas[$i]['valor'] ?? 0) - ($fluxo[$k]['valor'] ?? 0)) >= 0.005) { $pareado = false; break; }
        if ($pareado) { foreach ($rec as $k => $i) $linhas[$i]['rotulo_fluxo'] = $fluxo[$k]['rotulo']; }
        elseif ($n > 0) $capaFlags[] = "FLUXO_NAO_PAREADO (lançamentos={$n} fluxo=" . count($fluxo) . ') — parcelas numeradas pela ordem dos lançamentos';
        // arrasto de ano
        $anoHoje = (int)substr($this->hoje, 0, 4);
        for ($i = 1; $i < count($linhas); $i++) {
            $a = $linhas[$i - 1]['data']; $b = $linhas[$i]['data'];
            if ($a && $b && substr($a, 5) === substr($b, 5) && (int)substr($b, 0, 4) === (int)substr($a, 0, 4) + 1 && (int)substr($b, 0, 4) > $anoHoje) {
                $linhas[$i]['flags'][] = 'GRAVE:ARRASTO_DE_ANO (ano aumenta 1 a cada linha)';
            }
        }
        // totais
        $tp = 0.0; $tr = 0.0;
        foreach ($linhas as $l) { if ($l['tipo'] === 'PAGAR') $tp += $l['valor'] ?? 0; else $tr += $l['valor'] ?? 0; }
        $tp = round($tp, 2); $tr = round($tr, 2);
        $declR = self::money($ws->cell($hdr - 1, 5)); $declP = self::money($ws->cell($hdr - 1, 6));
        if ($declP !== null && abs($declP - $tp) > 0.05) $capaFlags[] = 'TOTAL_PAGAR_DIFERE (linhas=' . self::floatStr($tp) . ' capa=' . self::floatStr($declP) . ')';
        if ($declR !== null && abs($declR - $tr) > 0.05) $capaFlags[] = 'TOTAL_RECEBER_DIFERE (linhas=' . self::floatStr($tr) . ' capa=' . self::floatStr($declR) . ')';
        // chaves
        $codStr = $capa['cod'] === null ? 'None' : (string)$capa['cod'];
        foreach ($grupos as $idx) {
            foreach ($idx as $k => $i) {
                $l = $linhas[$i];
                $b = $codStr . '|' . $l['tipo'] . '|' . self::key($l['cf']) . '|' . ($l['funcao'] ?? 'None') . '|' . ($l['natureza'] ?? 'None') . '|' . ($k + 1);
                $linhas[$i]['chave_estavel'] = substr(sha1($b), 0, 12);
                $ex = $b . '|' . ($l['valor'] !== null ? sprintf('%.2f', $l['valor']) : '') . '|' . ($l['data'] ?? '');
                $linhas[$i]['chave_exata'] = substr(sha1($ex), 0, 12);
            }
        }
        return $base + ['capa_flags' => $capaFlags, 'cabecalho_linha' => $hdr, 'bonus_bloco' => $bonusVals,
                        'fluxo' => $fluxo, 'total_pagar' => $tp, 'total_receber' => $tr, 'linhas' => $linhas];
    }
}
