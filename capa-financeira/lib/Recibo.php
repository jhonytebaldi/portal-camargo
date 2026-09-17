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
    public static function gerar(array $linhas, array $pessoa, array $emp, string $dataRecibo, string $cidade = 'Joinville', ?string $logo = null): array
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
            $total += (float)$l['valor'];
            $p->paragrafo([[self::rotulo($l) . ': ' . self::descricao($l, $pessoa) . ', ', false], ['Valor: ' . self::brl((float)$l['valor']), true]]);
            $p->espaco(8);
        }
        $p->espaco(10);
        $p->paragrafo([['Valor total: ' . self::brl($total), true]]);
        $p->espaco(10);
        $p->paragrafo([['Desta forma, dou plena, geral, irrevogável, irretratável quitação das comissões, para nada mais reclamar em juízo ou fora dele.', false]]);
        $p->espaco(18);
        $p->paragrafo([['Por ser verdade, firmo o presente.', false]]);
        // assinatura sempre perto do rodapé (mas nunca antes do texto)
        $p->setY(max($p->y() + 80, 560));
        $p->linhaPontilhada(380);
        $p->espaco(6);
        $p->linha($recebedor, 'Helvetica-Bold', 11, 'C');
        $p->linha($cidade . ', ' . self::dataExtenso($dataRecibo), 'Helvetica', 11, 'C');
        return ['pdf' => $p->bytes(), 'numero' => $numero, 'valor' => round($total, 2), 'recebedor' => $recebedor];
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
