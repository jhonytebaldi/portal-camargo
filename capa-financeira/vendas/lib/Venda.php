<?php
/* =====================================================================
   capa-financeira/vendas/lib/Venda.php — regras do formulário da capa de
   venda. FONTE ÚNICA da verdade: os cálculos, a descrição padrão e as
   validações que a tela mostra ao vivo vêm daqui (a tela pede a prévia ao
   servidor em vez de recalcular em JS), e a planilha .xlsx usa os mesmos
   números.

   Etapa 1 = dados da venda (linhas 1–27 da capa):
     VALOR PARA CÁLCULO (= VGV) = contrato − bônus − documentação − descontos
     COMISSÃO BASE CORRETORES    = valor de cálculo × % corretores (5%)
     FUNDO DE MKT                = valor de cálculo × % fundo (0,5%)
     COMISSÃO TOTAL              = base + fundo
     DESCONTO NF                 = base × % NF (14%)
     VALOR FINAL P/ COMISSÕES    = base − NF − brinde − cesta
     BÔNUS P/ DISTRIBUIÇÃO       = bônus − bônus × % NF do bônus (14%)
     COMISSÃO + BÔNUS            = comissão total + bônus  (o que o cliente paga à imobiliária)
   ===================================================================== */
declare(strict_types=1);

final class Venda
{
    /** padrões editáveis da etapa 1 */
    public const PADROES = ['pct_corretores' => 0.05, 'pct_fundo' => 0.005, 'pct_nf' => 0.14, 'pct_nf_bonus' => 0.14, 'brinde' => 300.0, 'cesta' => 120.0];

    /** campos da etapa 1 em cv_vendas → [rótulo no histórico, tipo] */
    public const CAMPOS_E1 = [
        'empresa' => ['Empresa', 'txt'], 'construtora' => ['Construtora', 'txt'], 'endereco' => ['Endereço', 'txt'],
        'bairro' => ['Bairro', 'txt'], 'cep' => ['CEP', 'txt'], 'unidade' => ['Unidade', 'txt'], 'cod_imovel' => ['Cód. do imóvel', 'txt'],
        'status_imovel' => ['Planta/Pronto', 'txt'], 'data_venda' => ['Data da venda', 'data'],
        'valor_contrato' => ['Valor do contrato', 'brl'], 'valor_bonus' => ['Bônus', 'brl'],
        'documentacao' => ['Documentação', 'brl'], 'descontos' => ['Descontos adicionais', 'brl'],
        'pct_corretores' => ['% comissão corretores', 'pct'], 'pct_fundo' => ['% fundo de MKT', 'pct'],
        'pct_nf' => ['% desconto NF', 'pct'], 'pct_nf_bonus' => ['% NF do bônus', 'pct'],
        'brinde' => ['Desconto brinde', 'brl'], 'cesta' => ['Desconto cesta básica', 'brl'], 'obs' => ['Observação', 'txt'],
    ];
    public const CAMPOS_COMPRADOR = ['nome' => 'nome', 'documento' => 'CPF/CNPJ', 'email' => 'e-mail', 'nascimento' => 'nascimento'];

    /* ---------------- normalização ---------------- */

    public static function texto(mixed $s, bool $maiusculas = true): string
    {
        $s = trim(preg_replace('/\s+/u', ' ', (string)$s) ?? '');
        return $maiusculas ? mb_strtoupper($s, 'UTF-8') : $s;
    }

    /** "318.000,00" | "318000" | "318000.5" | "R$ 1.234,5" → float; vazio → null; ilegível → false */
    public static function dinheiro(mixed $v): float|null|false
    {
        if ($v === null) return null;
        if (is_int($v) || is_float($v)) return round((float)$v, 2);
        $s = preg_replace('/[R$\s]/u', '', (string)$v) ?? '';
        if ($s === '') return null;
        if (str_contains($s, ',')) $s = str_replace(['.', ','], ['', '.'], $s);          // formato BR
        elseif (preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) $s = str_replace('.', '', $s);   // "318.000"
        if (!is_numeric($s)) return false;
        return round((float)$s, 2);
    }

    /** percentual digitado em pontos ("5", "0,5", "14%") → fração (0.05) */
    public static function percentual(mixed $v): float|null|false
    {
        if ($v === null || $v === '') return null;
        if (is_int($v) || is_float($v)) return (float)$v;
        $s = str_replace([',', '%', ' '], ['.', '', ''], (string)$v);
        if (!is_numeric($s)) return false;
        return round((float)$s / 100, 6);
    }

    /** "09/04/2026" | "2026-04-09" → Y-m-d; vazio → null; inválida → false */
    public static function data(mixed $v): string|null|false
    {
        $s = trim((string)$v);
        if ($s === '') return null;
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        elseif (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $s, $m)) [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        else return false;
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : false;
    }

    /* ---------------- CPF / CNPJ ---------------- */

    /** só os caracteres do documento (o CNPJ alfanumérico de 2026 mantém letras) */
    public static function docLimpo(mixed $s): string
    {
        return strtoupper(preg_replace('/[^0-9A-Za-z]/', '', (string)$s) ?? '');
    }

    /** 'CPF' | 'CNPJ' quando os dígitos verificadores conferem; null caso contrário */
    public static function tipoDoc(string $d): ?string
    {
        if (preg_match('/^\d{11}$/', $d)) {
            if (preg_match('/^(\d)\1{10}$/', $d)) return null;
            for ($t = 9; $t < 11; $t++) {
                $s = 0;
                for ($i = 0; $i < $t; $i++) $s += (int)$d[$i] * ($t + 1 - $i);
                $dv = ((10 * $s) % 11) % 10;
                if ((int)$d[$t] !== $dv) return null;
            }
            return 'CPF';
        }
        // CNPJ numérico ou alfanumérico (Receita, jul/2026): valor de cada caractere = ASCII − 48
        if (preg_match('/^[0-9A-Z]{12}\d{2}$/', $d)) {
            if (preg_match('/^(\d)\1{13}$/', $d)) return null;
            foreach ([12, 13] as $t) {
                $pesos = $t === 12 ? [5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2] : [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];
                $s = 0;
                for ($i = 0; $i < $t; $i++) $s += (ord($d[$i]) - 48) * $pesos[$i];
                $r = $s % 11;
                $dv = $r < 2 ? 0 : 11 - $r;
                if ((int)$d[$t] !== $dv) return null;
            }
            return 'CNPJ';
        }
        return null;
    }

    public static function docFormatado(string $d): string
    {
        if (strlen($d) === 11) return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9);
        if (strlen($d) === 14) return substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12);
        return $d;
    }

    /* ---------------- cálculo ---------------- */

    /** @return array<string, float> valores da etapa 1 (sem arredondar — a tela/planilha formatam) */
    public static function calc(array $v): array
    {
        $n = fn(string $k) => (float)($v[$k] ?? 0);
        $p = fn(string $k) => isset($v[$k]) && $v[$k] !== null && $v[$k] !== '' ? (float)$v[$k] : self::PADROES[$k];
        $valorCalc = $n('valor_contrato') - $n('valor_bonus') - $n('documentacao') - $n('descontos');
        $base = $valorCalc * $p('pct_corretores');
        $fundo = $valorCalc * $p('pct_fundo');
        $nf = $base * $p('pct_nf');
        $brinde = $p('brinde'); $cesta = $p('cesta');
        $bonus = $n('valor_bonus');
        $nfBonus = $bonus * $p('pct_nf_bonus');
        $total = $base + $fundo;
        return [
            'valor_calculo' => $valorCalc, 'comissao_base' => $base, 'fundo' => $fundo, 'comissao_total' => $total,
            'desconto_nf' => $nf, 'brinde' => $brinde, 'cesta' => $cesta,
            'valor_final' => $base - $nf - $brinde - $cesta,
            'bonus' => $bonus, 'desconto_nf_bonus' => $nfBonus, 'bonus_distribuicao' => $bonus - $nfBonus,
            'comissao_mais_bonus' => $total + $bonus,
            'pct_brinde' => ($total + $bonus) > 0 ? $brinde / ($total + $bonus) : 0.0,
            'pct_cesta' => ($total + $bonus) > 0 ? $cesta / ($total + $bonus) : 0.0,
        ];
    }

    /* ---------------- descrição padrão ---------------- */

    /** nomes dos compradores ("A E B", "A, B E C") */
    public static function nomesCompradores(array $compradores): string
    {
        $n = array_values(array_filter(array_map(fn($c) => self::semHifen(self::texto($c['nome'] ?? '')), $compradores)));
        if (count($n) <= 2) return implode(' E ', $n);
        return implode(', ', array_slice($n, 0, -1)) . ' E ' . end($n);
    }

    /** hífen separa campos na descrição (o leitor de capas conta os campos) — dentro de nome vira espaço */
    private static function semHifen(string $s): string
    {
        return trim(preg_replace('/\s+/u', ' ', str_replace(['-', '–', '—'], ' ', $s)) ?? '');
    }

    public static function unidadeFormatada(string $u): string
    {
        $u = self::texto($u);
        return preg_match('/^\d+[A-Z]?$/', $u) ? 'UNIDADE ' . $u : $u;
    }

    /**
     * Descrição/observação de TODAS as contas a pagar e a receber do processo (ordem aprovada em 03/11/2026):
     *   COMPRADORES - CONSTRUTORA - ENDEREÇO - BAIRRO - UNIDADE - PLANTA|PRONTO - VENDA: dd/mm/aaaa - COD nnn
     * Campos ainda vazios (rascunho) ficam de fora.
     */
    public static function descricao(array $v, array $compradores): string
    {
        $p = [self::nomesCompradores($compradores), self::semHifen(self::texto($v['construtora'] ?? '')),
              self::texto($v['endereco'] ?? ''), self::texto($v['bairro'] ?? ''), self::unidadeFormatada((string)($v['unidade'] ?? '')),
              self::texto($v['status_imovel'] ?? '')];
        if (!empty($v['data_venda'])) $p[] = 'VENDA: ' . self::br((string)$v['data_venda']);
        if (trim((string)($v['cod_imovel'] ?? '')) !== '') $p[] = 'COD ' . trim((string)$v['cod_imovel']);
        return implode(' - ', array_values(array_filter($p, fn($x) => $x !== '')));
    }

    /** prefixo das contas a pagar (etapa 4): RECIBO XXXX - NOME - CARGO - COMISSAO|BONUS - <descrição> */
    public static function descricaoPagar(string $nome, string $cargo, string $natureza, string $descricao): string
    {
        return 'RECIBO XXXX - ' . self::nomeHistorico($nome) . ' - ' . self::texto($cargo) . ' - ' . self::texto($natureza) . ' - ' . $descricao;
    }

    /** nome completo do cadastro; nomes do meio abreviados quando passa de $max caracteres */
    public static function nomeHistorico(string $nome, int $max = 30): string
    {
        $n = self::semHifen(self::texto($nome));
        if (mb_strlen($n) <= $max) return $n;
        $w = explode(' ', $n);
        if (count($w) <= 2) return $n;
        $lig = ['DE', 'DA', 'DO', 'DOS', 'DAS', 'E'];
        $meio = [];
        foreach (array_slice($w, 1, -1) as $x) if (!in_array($x, $lig, true)) $meio[] = mb_substr($x, 0, 1) . '.';
        return $w[0] . ($meio ? ' ' . implode(' ', $meio) : '') . ' ' . end($w);
    }

    /** nome do arquivo: COMPRADORES_x_CONSTRUTORA_dd-mm-aaaa.xlsx (padrão das capas, data da última edição) */
    public static function nomeArquivo(array $v, array $compradores, string $quando): string
    {
        $limpa = function (string $s): string {
            $t = @iconv('UTF-8', 'ASCII//TRANSLIT', $s); $s = $t !== false ? $t : $s;
            $s = strtoupper(preg_replace('/[^A-Za-z0-9 ]+/', ' ', $s) ?? '');
            return preg_replace('/\s+/', '_', trim($s)) ?? '';
        };
        $c = $limpa(self::nomesCompradores($compradores)) ?: 'COMPRADORES';
        $k = $limpa(self::texto($v['construtora'] ?? '')) ?: 'CONSTRUTORA';
        return $c . '_x_' . $k . '_' . date('d-m-Y', strtotime($quando) ?: time()) . '.xlsx';
    }

    public static function br(?string $iso): string
    {
        return $iso && preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $iso, $m) ? "$m[3]/$m[2]/$m[1]" : (string)$iso;
    }

    /* ---------------- leitura do formulário ---------------- */

    /**
     * Converte o que veio da tela em valores de banco. Erros de formato (número/data ilegível) voltam em $erros.
     * @return array{0: array, 1: array, 2: string[]} [venda, compradores, erros de formato]
     */
    public static function doFormulario(array $in): array
    {
        $erros = [];
        $v = [];
        $v['empresa'] = in_array($in['empresa'] ?? '', ['camargo', 'vertical'], true) ? $in['empresa'] : '';
        foreach (['construtora', 'endereco', 'bairro', 'unidade'] as $k) $v[$k] = self::texto($in[$k] ?? '');
        $v['cod_imovel'] = preg_replace('/\D+/', '', (string)($in['cod_imovel'] ?? '')) ?? '';
        $cep = preg_replace('/\D+/', '', (string)($in['cep'] ?? '')) ?? '';
        $v['cep'] = strlen($cep) === 8 ? substr($cep, 0, 5) . '-' . substr($cep, 5) : $cep;
        $v['status_imovel'] = in_array($in['status_imovel'] ?? '', ['PLANTA', 'PRONTO'], true) ? $in['status_imovel'] : null;
        $v['obs'] = self::texto($in['obs'] ?? '', false) ?: null;
        $d = self::data($in['data_venda'] ?? '');
        if ($d === false) { $erros[] = 'Data da venda ilegível — use dd/mm/aaaa.'; $d = null; }
        $v['data_venda'] = $d;
        foreach (['valor_contrato', 'valor_bonus', 'documentacao', 'descontos', 'brinde', 'cesta'] as $k) {
            $x = self::dinheiro($in[$k] ?? null);
            if ($x === false) { $erros[] = self::CAMPOS_E1[$k][0] . ': valor ilegível.'; $x = null; }
            if ($x === null && isset(self::PADROES[$k])) $x = self::PADROES[$k];
            $v[$k] = $x;
        }
        foreach (['pct_corretores', 'pct_fundo', 'pct_nf', 'pct_nf_bonus'] as $k) {
            $x = self::percentual($in[$k] ?? null);
            if ($x === false) { $erros[] = self::CAMPOS_E1[$k][0] . ': percentual ilegível.'; $x = null; }
            $v[$k] = $x ?? self::PADROES[$k];
        }
        $comp = [];
        foreach (array_values((array)($in['compradores'] ?? [])) as $i => $c) {
            $nome = self::texto($c['nome'] ?? '');
            $doc = self::docLimpo($c['documento'] ?? '');
            $email = mb_strtolower(trim((string)($c['email'] ?? '')), 'UTF-8');
            $nasc = self::data($c['nascimento'] ?? '');
            if ($nasc === false) { $erros[] = 'Comprador ' . ($i + 1) . ': data de nascimento ilegível.'; $nasc = null; }
            if ($nome === '' && $doc === '' && $email === '' && !$nasc) continue;   // linha em branco
            $comp[] = ['nome' => $nome, 'documento' => $doc, 'email' => $email, 'nascimento' => $nasc];
        }
        return [$v, $comp, $erros];
    }

    /* ---------------- validação ---------------- */

    /**
     * Validação da etapa 1. $contexto: construtoras conhecidas (key → nome), vendas/capas com o mesmo COD.
     * @return array{erros: string[], avisos: string[]}  erros impedem concluir; avisos só alertam
     */
    public static function validarE1(array $v, array $comp, array $contexto = []): array
    {
        $e = []; $a = [];
        if ($v['empresa'] === '') $e[] = 'Escolha a empresa da venda (Camargo ou Vertical).';

        if (!$comp) $e[] = 'Informe pelo menos 1 comprador.';
        $docs = []; $temEmail = false;
        foreach ($comp as $i => $c) {
            $q = 'Comprador ' . ($i + 1) . ($c['nome'] !== '' ? ' (' . $c['nome'] . ')' : '');
            if ($c['nome'] === '') $e[] = "$q: falta o nome.";
            elseif (count(explode(' ', $c['nome'])) < 2) $e[] = "$q: informe o nome completo.";
            if ($c['documento'] === '') $e[] = "$q: falta o CPF/CNPJ.";
            elseif (!self::tipoDoc($c['documento'])) $e[] = "$q: CPF/CNPJ inválido (dígitos verificadores não conferem).";
            elseif (isset($docs[$c['documento']])) $e[] = "$q: mesmo CPF/CNPJ do comprador " . $docs[$c['documento']] . '.';
            else $docs[$c['documento']] = $i + 1;
            if ($c['email'] !== '') {
                if (!filter_var($c['email'], FILTER_VALIDATE_EMAIL)) $e[] = "$q: e-mail inválido.";
                else $temEmail = true;
            }
            if ($c['nascimento']) {
                $idade = (int)date_diff(date_create($c['nascimento']), date_create('today'))->y;
                if ($c['nascimento'] > date('Y-m-d') || $idade > 110) $e[] = "$q: data de nascimento impossível.";
                elseif ($idade < 18) $a[] = "$q: menor de 18 anos pela data de nascimento — confira.";
            }
            if (self::tipoDoc($c['documento']) === 'CNPJ') $a[] = "$q: comprador pessoa jurídica (CNPJ) — confirme que é isso mesmo.";
        }
        if ($comp && !$temEmail) $e[] = 'Informe o e-mail de pelo menos 1 comprador.';

        if ($v['construtora'] === '') $e[] = 'Falta a construtora.';
        elseif (isset($contexto['construtoras']) && !isset($contexto['construtoras'][self::chave($v['construtora'])])) {
            $melhor = self::parecido($v['construtora'], array_map(fn($c) => $c['nome'], $contexto['construtoras']), 80);
            $a[] = 'Construtora "' . $v['construtora'] . '" não aparece em capas anteriores nem no Omie'
                 . ($melhor ? ' — você quis dizer "' . $melhor . '"?' : ' — confira a grafia (escolha da lista quando existir).');
        }
        if ($v['endereco'] === '') $e[] = 'Falta o endereço da obra.';
        if ($v['bairro'] === '') $e[] = 'Falta o bairro.';
        elseif (isset($contexto['bairros']) && !isset($contexto['bairros'][self::chave($v['bairro'])])) {
            $sug = self::parecido($v['bairro'], array_map(fn($b) => $b['nome'], $contexto['bairros']), 75);
            $e[] = 'Bairro "' . $v['bairro'] . '" não está na lista de bairros' . ($sug ? ' — você quis dizer "' . mb_strtoupper($sug, 'UTF-8') . '"?' : '') . ' Se for um bairro novo, cadastre pelo botão ao lado do campo.';
        }
        if ($v['cep'] !== '' && strlen($v['cep']) !== 9) $e[] = 'CEP incompleto (8 dígitos).';
        if ($v['unidade'] === '') $e[] = 'Falta a unidade.';
        if ($v['cod_imovel'] === '') $e[] = 'Falta o código do imóvel.';
        if (!$v['status_imovel']) $e[] = 'Marque se o imóvel é PLANTA ou PRONTO.';
        if (!$v['data_venda']) $e[] = 'Falta a data da venda.';
        elseif ($v['data_venda'] > date('Y-m-d')) $e[] = 'Data da venda no futuro.';
        elseif ($v['data_venda'] < date('Y-m-d', strtotime('-1 year'))) $a[] = 'Venda com mais de 1 ano — confira a data.';

        $c = self::calc($v);
        if (!$v['valor_contrato'] || $v['valor_contrato'] <= 0) $e[] = 'Falta o valor do contrato.';
        foreach (['valor_bonus', 'documentacao', 'descontos'] as $k) if ($v[$k] !== null && $v[$k] < 0) $e[] = self::CAMPOS_E1[$k][0] . ' não pode ser negativo.';
        if ($v['valor_contrato'] && $c['valor_calculo'] <= 0) $e[] = 'Bônus + documentação + descontos passam do valor do contrato.';
        if ($v['pct_corretores'] <= 0 || $v['pct_corretores'] > 0.10) $e[] = '% de comissão dos corretores fora do razoável (0 a 10%).';
        if ($v['valor_contrato'] && $c['valor_calculo'] > 0 && $c['valor_final'] < 0) $e[] = 'Brinde + cesta + NF passam da comissão base.';
        foreach (['pct_corretores', 'pct_fundo', 'pct_nf', 'pct_nf_bonus', 'brinde', 'cesta'] as $k) {
            if (abs((float)$v[$k] - self::PADROES[$k]) > 1e-9) {
                $pad = in_array($k, ['brinde', 'cesta'], true) ? 'R$ ' . number_format(self::PADROES[$k], 2, ',', '.') : rtrim(rtrim(number_format(self::PADROES[$k] * 100, 2, ',', ''), '0'), ',') . '%';
                $a[] = self::CAMPOS_E1[$k][0] . ' diferente do padrão (' . $pad . ').';
            }
        }
        foreach ($contexto['mesmo_cod'] ?? [] as $m) $a[] = $m;
        return ['erros' => $e, 'avisos' => $a];
    }

    /** nome mais parecido da lista (similaridade ≥ $min %) ou null */
    public static function parecido(string $nome, array $lista, float $min): ?string
    {
        $k = self::chave($nome); $melhor = null; $pm = 0.0;
        foreach ($lista as $n) { similar_text($k, self::chave((string)$n), $pct); if ($pct > $pm) { $pm = $pct; $melhor = (string)$n; } }
        return $pm >= $min ? $melhor : null;
    }

    public static function chave(string $s): string
    {
        $t = @iconv('UTF-8', 'ASCII//TRANSLIT', mb_strtoupper($s, 'UTF-8'));
        $s = $t !== false ? $t : $s;
        return trim(preg_replace('/[^A-Z0-9]+/', ' ', strtoupper($s)) ?? '');
    }

    /* ---------------- histórico ---------------- */

    /** valor legível para o histórico */
    public static function legivel(string $tipo, mixed $x): string
    {
        if ($x === null || $x === '') return '';
        return match ($tipo) {
            'brl' => 'R$ ' . number_format((float)$x, 2, ',', '.'),
            'pct' => rtrim(rtrim(number_format((float)$x * 100, 3, ',', ''), '0'), ',') . '%',
            'data' => self::br((string)$x),
            default => (string)$x,
        };
    }

    /** diferenças entre o que está no banco e o que vai ser gravado → linhas de histórico [campo, antes, depois] */
    public static function diferencas(array $antes, array $depois, array $compAntes, array $compDepois): array
    {
        $out = [];
        foreach (self::CAMPOS_E1 as $k => [$rot, $tipo]) {
            $a = self::legivel($tipo, $antes[$k] ?? null); $d = self::legivel($tipo, $depois[$k] ?? null);
            if ($a !== $d) $out[] = [$rot, $a, $d];
        }
        $n = max(count($compAntes), count($compDepois));
        for ($i = 0; $i < $n; $i++) {
            $ca = $compAntes[$i] ?? null; $cd = $compDepois[$i] ?? null;
            $q = 'Comprador ' . ($i + 1);
            if (!$cd) { $out[] = [$q, $ca['nome'] . ' · ' . self::docFormatado($ca['documento']), '(removido)']; continue; }
            if (!$ca) { $out[] = [$q, '(novo)', $cd['nome'] . ' · ' . self::docFormatado($cd['documento'])]; continue; }
            foreach (self::CAMPOS_COMPRADOR as $k => $rot) {
                $a = (string)($ca[$k] ?? ''); $d = (string)($cd[$k] ?? '');
                if ($k === 'nascimento') { $a = self::br($a); $d = self::br($d); }
                if ($k === 'documento') { $a = self::docFormatado($a); $d = self::docFormatado($d); }
                if ($a !== $d) $out[] = ["$q · $rot", $a, $d];
            }
        }
        return $out;
    }
}
