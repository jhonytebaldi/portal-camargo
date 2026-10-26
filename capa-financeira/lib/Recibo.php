<?php
/* =====================================================================
   capa-financeira/lib/Recibo.php — monta o PDF "Declaração e Recibo"
   (modelo Camargo) para um ou mais lançamentos a pagar da mesma pessoa.
   Texto e layout copiam o recibo em uso (ago/2026):
     logo · "Recibo Nº" · DECLARAÇÃO E RECIBO · parágrafo "Eu, RAZÃO, CNPJ…
     recebi de EMPRESA, CNPJ…" · uma linha por lançamento ("Comissão: …,
     Valor: R$") · Valor total · quitação · "Por ser verdade" · assinatura
     · "Joinville, dd de mês de aaaa".
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/Pdf.php';

final class Recibo
{
    public const MESES = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];

    /**
     * @param array $linhas  lançamentos (cf_lancamentos + campos capa_*) da MESMA pessoa
     * @param array $pessoa  cf_pessoas
     * @param array $emp     config da empresa (nome, razao, cnpj, logo_propria, endereco)
     * @param string $dataRecibo  Y-m-d
     * @param string $cidade
     * @return array{pdf:string, numero:string, valor:float, recebedor:string}
     */
    public static function gerar(array $linhas, array $pessoa, array $emp, string $dataRecibo, string $cidade = 'Joinville', ?string $logo = null, array $opcoes = []): array
    {
        [$recebedor, $docRotulo, $docNum] = self::identidade($pessoa);
        $numero = implode(' / ', array_map(fn($l) => (string)$l['codigo_integracao'], $linhas));
        $total = 0.0;

        $p = new Pdf();
        $logo = $logo ?: __DIR__ . '/../modelos/logo_camargo.jpg';
        if (is_file($logo)) $p->imagemJpeg($logo, 170, 'C');
        $p->espaco(6);
        $p->linha('Recibo Nº: ' . $numero, 'Helvetica', 11, 'D');
        $p->espaco(14);
        $p->linha('DECLARAÇÃO E RECIBO', 'Helvetica-Bold', 12, 'C');
        $p->espaco(14);
        $razaoEmp = trim((string)($emp['razao'] ?? '')) ?: (string)($emp['nome'] ?? '');
        $p->paragrafo([
            ['Eu, ', false],
            [$recebedor . ', ' . $docRotulo . ' sob nº ' . $docNum, true],
            [' para todos os fins de direito e a quem interessar possa que, na finalidade de agenciador e/ou corretor de imóveis autônomo, sem vínculo empregatício, subordinação, controle de horário e/ou exclusividade, recebi de ' . $razaoEmp . ', CNPJ sob nº ' . ($emp['cnpj'] ?? '') . ', proveniente do pagamento de comissão(ões) e bônus realizado(s) no imóvel(is) abaixo relacionado(s):', false],
        ]);
        $p->espaco(18);
        foreach ($linhas as $l) {
            $trechos = [[self::rotulo($l) . ': ' . self::descricao($l, $pessoa) . ', ', false], ['Valor: ' . self::brl((float)$l['valor']), true]];
            // o bloco de um título (texto + faixa de desconto) não fica partido entre páginas quando cabe inteiro numa página
            $alt = $p->alturaParagrafo($trechos); $descT = (float)($l['desconto'] ?? 0) > 0 || (float)($l['impostos'] ?? 0) > 0 || (float)($l['juros'] ?? 0) > 0;
            if ($descT) $alt += $p->alturaParagrafo([[(string)($l['obs_baixa'] ?? ''), false], ['Valor final: R$ 0,00', true]]) + 14;
            if ($alt < 300) $p->garantir($alt + 8);
            $p->paragrafo($trechos);
            // ajustes (título "Pago Parcialmente" no Omie): desconto, impostos retidos, juros/multa → valor final
            $desc = (float)($l['desconto'] ?? 0); $imp = (float)($l['impostos'] ?? 0); $jur = (float)($l['juros'] ?? 0);
            if ($desc > 0 || $imp > 0 || $jur > 0) {
                $final = round((float)$l['valor'] - $desc - $imp + $jur, 2);
                $tr = [];
                if ($desc > 0) $tr[] = ['Desconto: ' . self::brl($desc), false];
                // descrição digitada na baixa do Omie (ex.: "Desconto 4,82% adiantamento de 2 meses")
                $obsB = trim((string)($l['obs_baixa'] ?? ''));
                if ($imp > 0) $tr[] = [($tr ? ' · ' : '') . 'Impostos retidos: ' . self::brl($imp), false];
                if ($jur > 0) $tr[] = [($tr ? ' · ' : '') . 'Juros e multa: ' . self::brl($jur), false];
                if ($obsB !== '') $tr[] = [(str_contains($obsB, "\n") ? "\n" : ' — ') . $obsB . (str_contains($obsB, "\n") ? "\n" : ''), false];
                $tr[] = ['   Valor final: ' . self::brl($final), true];
                $p->paragrafoComFundo($tr);
                $total += $final;
            } else $total += (float)$l['valor'];
            $p->espaco(8);
        }
        $p->espaco(10);
        $bruto = round(array_sum(array_map(fn($l) => (float)$l['valor'], $linhas)), 2);
        $nf = self::descricaoNf($linhas);
        $layout = (string)($opcoes['nf_layout'] ?? 'junto');
        // bruto, líquido e (se for o caso) a descrição da NF ficam juntos na mesma página
        $p->garantir(40 + ($layout === 'junto' ? $p->alturaParagrafo([['Descrição para Nota Fiscal (copiar e colar): ' . $nf['texto'], false]], 8.5, 1.4) + 16 : 0));
        $p->paragrafo([['Valor bruto: ' . self::brl($bruto), true], [abs($bruto - $total) > 0.005 ? '   (descontos: ' . self::brl($bruto - $total) . ')' : '', false]]);
        $p->paragrafo([['Valor líquido: ' . self::brl($total), true]]);
        if ($layout === 'junto') { $p->espaco(6); self::blocoNf($p, $nf['texto']); }
        $p->espaco(10);
        $p->paragrafo([['Desta forma, dou plena, geral, irrevogável, irretratável quitação das comissões, para nada mais reclamar em juízo ou fora dele.', false]]);
        $p->espaco(18);
        $p->paragrafo([['Por ser verdade, firmo o presente.', false]]);
        if ($layout === 'depois') { $p->espaco(12); self::blocoNf($p, $nf['texto']); }
        // assinatura sempre perto do rodapé (mas nunca antes do texto); se não couber, vai para a próxima página
        $p->garantir(80 + 50);
        $p->setY(max($p->y() + 80, min(560, $p->limiteInferior() - 50)));
        $p->linhaPontilhada(380);
        $p->espaco(6);
        $p->linha($recebedor, 'Helvetica-Bold', 11, 'C');
        $p->linha($cidade . ', ' . self::dataExtenso($dataRecibo), 'Helvetica', 11, 'C');
        if ($layout === 'pagina') { $p->novaPagina(); $p->linha('Descrição para Nota Fiscal — ' . $numero, 'Helvetica-Bold', 12, 'E'); $p->espaco(10); $p->paragrafo([['Valor bruto: ' . self::brl($bruto), true]]); $p->paragrafo([['Valor líquido: ' . self::brl($total), true]]); $p->espaco(10); self::blocoNf($p, $nf['texto'], 10); }
        return ['pdf' => $p->bytes(), 'numero' => $numero, 'valor' => round($total, 2), 'bruto' => $bruto, 'descricao_nf' => $nf['texto'], 'nf_formato' => $nf['formato'], 'nf_encurtado' => $nf['encurtado'], 'recebedor' => $recebedor];
    }

    /** caixa cinza "Descrição para Nota Fiscal" (texto corrido, fonte menor) */
    private static function blocoNf(Pdf $p, string $texto, float $tam = 8.5): void
    {
        $p->paragrafoComFundo([['Descrição para Nota Fiscal (copiar e colar): ', true], [$texto, false]], [0.94, 0.94, 0.94], $tam, 1.4);
    }

    /** tipo de comissão para a NF, a partir da função/natureza do título */
    public static function tipoComissao(array $l): string
    {
        if (($l['natureza'] ?? '') === 'BONUS') return 'BÔNUS';
        $f = mb_strtoupper(trim((string)($l['funcao'] ?? '')), 'UTF-8'); $f = str_replace(['Ç', 'Ã', 'Á', 'É'], ['C', 'A', 'A', 'E'], $f);
        $m = ['CORRETOR' => 'CORRETOR', 'CAPTADOR' => 'CAPTAÇÃO', 'CAPTACAO' => 'CAPTAÇÃO', 'COORDENADOR' => 'GERENTE', 'GERENTE' => 'GERENTE', 'DIRETOR' => 'DIRETOR', 'INTEGRACAO' => 'INTEGRAÇÃO', 'PRE VENDA' => 'PRÉ-VENDA', 'PRE-VENDA' => 'PRÉ-VENDA', 'PREVENDA' => 'PRÉ-VENDA'];
        return $m[$f] ?? ($f !== '' ? $f : 'COMISSÃO');
    }

    /** bairro da obra: campo da capa (quando vier) ou o trecho do texto do imóvel antes de "UNIDADE" */
    public static function bairro(array $l): string
    {
        $b = trim((string)($l['bairro'] ?? $l['capa_bairro'] ?? '')); if ($b !== '') return mb_strtoupper($b, 'UTF-8');
        $im = (string)($l['unidade'] ?? $l['capa_unidade'] ?? ''); $partes = array_map('trim', explode(' - ', $im));
        foreach ($partes as $i => $pt) if ($i > 0 && preg_match('/^(UNIDADE|UNID|APTO|APARTAMENTO|CASA|LOTE)\b/iu', $pt)) return mb_strtoupper($partes[$i - 1], 'UTF-8');
        return count($partes) >= 3 ? mb_strtoupper($partes[count($partes) - 2], 'UTF-8') : '';
    }

    /**
     * Texto para a descrição da NF: por imóvel (COD), os números dos recibos, cliente, construtora + bairro e um valor BRUTO por título
     * com o tipo de comissão. Sem quebras de linha. Formato A (rótulos) enquanto cabe em $limite; senão B (compacto); senão encurta nomes.
     * @return array{texto:string, formato:string, encurtado:bool}
     */
    public static function descricaoNf(array $linhas, int $limite = 1300): array
    {
        $grupos = [];
        foreach ($linhas as $l) {
            $k = (string)($l['capa_cod'] ?? '') ?: (string)($l['capa_cliente'] ?? '');
            $g = &$grupos[$k]; $g ??= ['recibos' => [], 'cliente' => (string)($l['capa_cliente'] ?? $l['clientes_hist'] ?? ''), 'construtora' => (string)($l['capa_construtora'] ?? $l['construtora_hist'] ?? ''), 'bairro' => self::bairro($l), 'valores' => []];
            if (!in_array((string)$l['codigo_integracao'], $g['recibos'], true)) $g['recibos'][] = (string)$l['codigo_integracao'];
            $g['valores'][] = [self::tipoComissao($l), (float)$l['valor']]; unset($g);
        }
        $bruto = round(array_sum(array_map(fn($l) => (float)$l['valor'], $linhas)), 2);
        $monta = function (string $fmt, int $nivel) use ($grupos, $bruto): string {
            $enc = function (string $nome) use ($nivel): string {
                $n = trim($nome);
                if ($nivel >= 1) $n = trim((string)preg_replace('/\b(LTDA|ME|EPP|EIRELI|S\/A|SA)\b\.?/u', '', $n));
                if ($nivel >= 2) $n = trim((string)preg_replace('/\b(INCORPORA[ÇC][ÕO]ES|CONSTRU[ÇC][ÕO]ES|EMPREENDIMENTOS( IMOBILI[ÁA]RIOS)?|CONSTRUTORA E INCORPORADORA|GESTAO ADMINISTRATIVA)\b/u', '', $n));
                return trim((string)preg_replace('/\s+(E\s+)?$/u', '', (string)preg_replace('/\s{2,}/', ' ', $n)));
            };
            $cli = function (string $nomes) use ($nivel): string {
                if ($nivel < 3) return $nomes;
                return implode(' E ', array_map(function ($n) { $t = preg_split('/\s+/', trim($n)) ?: []; return count($t) > 2 ? $t[0] . ' ' . end($t) : trim($n); }, preg_split('/\s+E\s+/u', $nomes) ?: []));
            };
            $itens = []; $i = 0;
            foreach ($grupos as $g) {
                $i++; $rec = $g['recibos']; $con = $enc($g['construtora']) . ($g['bairro'] !== '' ? ($fmt === 'A' ? ' - ' : ' (') . $g['bairro'] . ($fmt === 'A' ? '' : ')') : '');
                if ($fmt === 'A') $itens[] = 'RECIBO ' . implode(' / ', $rec) . ' | CLIENTE: ' . $cli($g['cliente']) . ' | CONSTRUTORA: ' . $con . ' | ' . implode(' | ', array_map(fn($v) => $v[0] . ': ' . self::brl($v[1]), $g['valores']));
                else $itens[] = $i . ') ' . (count($rec) > 1 ? 'Recibos ' : 'Recibo ') . implode(', ', $rec) . ' - ' . $cli($g['cliente']) . ' - ' . $con . ' - ' . implode(', ', array_map(fn($v) => mb_convert_case(mb_strtolower($v[0], 'UTF-8'), MB_CASE_TITLE, 'UTF-8') . ' ' . self::brl($v[1]), $g['valores']));
            }
            return $fmt === 'A' ? implode('  ||  ', $itens) . '  ||  TOTAL BRUTO: ' . self::brl($bruto) : implode('; ', $itens) . '. Total bruto: ' . self::brl($bruto);
        };
        foreach ([['A', 0], ['B', 0], ['B', 1], ['B', 2], ['B', 3]] as [$fmt, $nivel]) {
            $t = $monta($fmt, $nivel);
            if (mb_strlen($t, 'UTF-8') <= $limite) return ['texto' => $t, 'formato' => $fmt, 'encurtado' => $nivel > 0];
        }
        return ['texto' => mb_substr($t, 0, $limite - 1, 'UTF-8') . '…', 'formato' => 'B', 'encurtado' => true];
    }

    /** quem assina: razão social + CNPJ (pagar por CNPJ) ou nome + CPF */
    public static function identidade(array $pessoa): array
    {
        $cnpj = trim((string)($pessoa['cnpj'] ?? '')); $cpf = trim((string)($pessoa['cpf'] ?? ''));
        $razao = trim((string)($pessoa['razao_social'] ?? '')); $nome = trim((string)($pessoa['nome'] ?? ''));
        $porCnpj = ($pessoa['pagar_por'] ?? 'CNPJ') === 'CNPJ' && $cnpj !== '';
        if ($porCnpj) return [mb_strtoupper($razao ?: $nome, 'UTF-8'), 'CNPJ', $cnpj];
        if ($cpf !== '') return [mb_strtoupper($nome, 'UTF-8'), 'CPF', $cpf];
        if ($cnpj !== '') return [mb_strtoupper($razao ?: $nome, 'UTF-8'), 'CNPJ', $cnpj];
        return [mb_strtoupper($nome, 'UTF-8'), 'CPF', '—'];
    }

    public static function rotulo(array $l): string { return ($l['natureza'] ?? '') === 'BONUS' ? 'Bônus' : 'Comissão'; }

    /** histórico no formato da capa, com o RECIBO preenchido */
    public static function descricao(array $l, array $pessoa): string
    {
        $partes = ['RECIBO ' . $l['codigo_integracao'], mb_strtoupper((string)($pessoa['nome'] ?? $l['cf_raw']), 'UTF-8')];
        if (!empty($l['funcao'])) $partes[] = $l['funcao'];
        $partes[] = ($l['natureza'] ?? 'COMISSAO') === 'BONUS' ? 'BONUS' : 'COMISSAO';
        $partes[] = (string)($l['capa_cliente'] ?? $l['clientes_hist'] ?? '');
        $partes[] = (string)($l['capa_construtora'] ?? $l['construtora_hist'] ?? '');
        $unid = (string)($l['unidade'] ?: ($l['capa_unidade'] ?? ''));
        if ($unid !== '') $partes[] = $unid;
        if (!empty($l['status_imovel'])) $partes[] = $l['status_imovel'];
        if (!empty($l['capa_data_venda'])) $partes[] = 'VENDA: ' . cf_data_br($l['capa_data_venda']);
        if (!empty($l['capa_cod'])) $partes[] = 'COD ' . $l['capa_cod'];
        return implode(' - ', array_filter($partes, fn($x) => $x !== ''));
    }

    public static function brl(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
    public static function dataExtenso(string $iso): string
    {
        $t = strtotime($iso) ?: time();
        return sprintf('%02d de %s de %d', (int)date('d', $t), self::MESES[(int)date('n', $t)], (int)date('Y', $t));
    }
    /** nome de arquivo: Recibo_1375-P006_Jhony_Tebaldi.pdf */
    public static function nomeArquivo(string $numero, string $nomePessoa): string
    {
        $n = preg_replace('/[^A-Za-z0-9]+/', '_', (string)iconv('UTF-8', 'ASCII//TRANSLIT', $nomePessoa)) ?: 'recibo';
        $num = preg_replace('/[^A-Za-z0-9-]+/', '_', str_replace(' / ', '+', $numero));
        return 'Recibo_' . $num . '_' . trim($n, '_') . '.pdf';
    }
}
