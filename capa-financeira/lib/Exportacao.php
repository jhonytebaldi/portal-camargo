<?php
/* =====================================================================
   capa-financeira/lib/Exportacao.php — monta as linhas das planilhas de
   importação do Omie (Contas a Pagar v1.1.5 / Contas a Receber v1.0.6)
   a partir dos lançamentos confirmados. Só regra de negócio; quem grava
   o .xlsx é OmieXlsx e quem conversa com o banco é exportar.php.

   Colunas CP: B Cód. Integração · C Fornecedor · D Categoria · E Conta
   Corrente · F Valor · H Projeto · I Emissão · J Registro · K Vencimento
   · L Previsão · S Observações · U Nº Documento · Y Nota Fiscal ·
   AA Forma de Pagamento · AK Chave Pix · AX Departamento
   Colunas CR: iguais até S; V Parcela · W Total de Parcelas · Y Nota
   Fiscal · AP Departamento
   ===================================================================== */
declare(strict_types=1);

final class Exportacao
{
    public const LIM = ['codigo' => 20, 'nome' => 60, 'categoria' => 70, 'conta' => 40, 'projeto' => 70, 'documento' => 30, 'nf' => 20, 'departamento' => 50, 'pix' => 512];

    /**
     * Monta uma linha. Devolve ['cols' => [...], 'erros' => [...], 'avisos' => [...]].
     * $l = cf_lancamentos (+ campos da capa em $capa), $p = cf_pessoas ou null, $emp = config da empresa,
     * $opts = ['data_registro' => 'Y-m-d', 'emissao' => 'venda'|'registro'|'prevista']
     */
    public static function montar(array $l, array $capa, ?array $p, array $emp, array $opts): array
    {
        $erros = []; $avisos = []; $c = [];
        $tipo = $l['tipo'];
        $codigo = (string)($l['codigo_integracao'] ?? '');
        if ($codigo === '') $erros[] = 'sem código de integração (confirme a capa)';
        elseif (mb_strlen($codigo) > self::LIM['codigo']) $erros[] = 'código de integração com mais de 20 caracteres';
        $c['B'] = ['s' => $codigo];

        // C — fornecedor (CP) / cliente (CR)
        if ($tipo === 'P') {
            if (!$p) $erros[] = 'favorecido sem pessoa do dicionário';
            else {
                [$forn, $avForn] = self::fornecedor($p);
                if ($forn === '') $erros[] = 'pessoa sem CNPJ, CPF nem razão social cadastrados';
                if ($avForn) $avisos[] = $avForn;
                $c['C'] = ['s' => $forn];
            }
        } else {
            $cli = trim((string)($l['cliente_omie'] ?? ''));
            if ($cli === '') $cli = self::clientePadrao((string)$capa['cliente']);
            if ($cli === '') $erros[] = 'sem cliente (informe o nome/CPF como está no Omie)';
            $c['C'] = ['s' => $cli];
        }
        if (isset($c['C']) && mb_strlen($c['C']['s']) > self::LIM['nome']) $erros[] = 'nome do fornecedor/cliente com mais de 60 caracteres';

        // D — categoria
        $cat = (string)($l['categoria'] ?? '');
        if ($cat === '') $erros[] = 'sem categoria';
        $c['D'] = ['s' => $cat];

        // E — conta corrente
        $conta = trim((string)($l['conta_corrente'] ?? ''));
        if ($conta === '') {
            if ($tipo === 'P') $conta = trim((string)($p[$emp['id'] === 'vertical' ? 'conta_vertical' : 'conta_camargo'] ?? '')) ?: trim((string)($emp['conta_padrao_cp'] ?? ''));
            else $conta = trim((string)($emp['conta_padrao'] ?? ''));
        }
        if ($conta === '') $erros[] = 'sem conta corrente (defina na linha, na pessoa ou em Configurações › empresa)';
        elseif (mb_strlen($conta) > self::LIM['conta']) $erros[] = 'conta corrente com mais de 40 caracteres';
        $c['E'] = ['s' => $conta];

        // F — valor
        $valor = round((float)$l['valor'], 2);
        if ($valor <= 0) $erros[] = 'valor zerado ou negativo';
        $c['F'] = ['n' => $valor];

        // H — projeto (opcional, só se configurado)
        if (!empty($emp['projeto_omie']) && !empty($capa['construtora'])) $c['H'] = ['s' => mb_substr((string)$capa['construtora'], 0, self::LIM['projeto'])];

        // datas
        $reg = $opts['data_registro'] ?? date('Y-m-d');
        $prev = (string)($l['data_prevista'] ?? '');
        if ($prev === '') $erros[] = 'sem data prevista (vencimento)';
        $emissaoFonte = $opts['emissao'] ?? 'venda';
        $emissao = $emissaoFonte === 'venda' ? (string)($capa['data_venda'] ?? '') : ($emissaoFonte === 'prevista' ? $prev : $reg);
        if ($emissao !== '') $c['I'] = ['d' => $emissao];
        $c['J'] = ['d' => $reg];
        if ($prev !== '') { $c['K'] = ['d' => $prev]; $c['L'] = ['d' => $prev]; }

        // S — observações (o "banco de dados" do título dentro do Omie)
        $obs = cf_observacao($capa, $l);
        $c['S'] = ['s' => $obs];

        // U — nº do documento = código de integração (dá pra achar o título pela busca do Omie)
        $c['U'] = ['s' => $codigo];

        if ($tipo === 'P') {
            $nf = (string)($l['nota_fiscal'] ?? '');
            if (mb_strlen($nf) > self::LIM['nf']) { $avisos[] = 'nota fiscal cortada em 20 caracteres'; $nf = mb_substr($nf, 0, self::LIM['nf']); }
            if ($nf !== '') $c['Y'] = ['s' => $nf];
            $pix = trim((string)($l['chave_pix'] ?? ''));
            if ($pix !== '') { $c['AA'] = ['s' => 'Chave Pix']; $c['AK'] = ['s' => $pix]; }
            else $avisos[] = 'sem chave Pix (forma de pagamento fica em branco)';
            $dep = trim((string)($p['departamento_omie'] ?? ''));
            if ($dep !== '') $c['AX'] = ['s' => mb_substr($dep, 0, self::LIM['departamento'])];
        } else {
            if (!empty($l['parcela']) && !empty($l['total_parcelas'])) { $c['V'] = ['n' => (int)$l['parcela']]; $c['W'] = ['n' => (int)$l['total_parcelas']]; }
            $nf = (string)($l['nota_fiscal'] ?? '');
            if ($nf !== '') $c['Y'] = ['s' => mb_substr($nf, 0, self::LIM['nf'])];
            if (($l['natureza'] ?? '') === 'BONUS' || str_contains(mb_strtolower($cat), 'bonifica')) $avisos[] = 'bônus a receber: fazer o rateio de categorias no Omie depois de importar';
        }
        return ['cols' => $c, 'erros' => $erros, 'avisos' => $avisos];
    }

    /** texto do campo Fornecedor conforme "pagar por" da pessoa. [valor, aviso|null] */
    public static function fornecedor(array $p): array
    {
        $cnpj = trim((string)($p['cnpj'] ?? '')); $cpf = trim((string)($p['cpf'] ?? '')); $razao = trim((string)($p['razao_social'] ?? ''));
        $por = $p['pagar_por'] ?? 'CNPJ';
        if ($por === 'CPF') {
            if ($cpf !== '') return [$cpf, null];
            if ($cnpj !== '') return [$cnpj, 'pessoa marcada para pagar por CPF mas só tem CNPJ cadastrado'];
        } else {
            if ($cnpj !== '') return [$cnpj, null];
            if ($cpf !== '') return [$cpf, 'pessoa marcada para pagar por CNPJ mas só tem CPF cadastrado'];
        }
        if ($razao !== '') return [$razao, 'sem CNPJ/CPF: o Omie vai procurar o fornecedor pela razão social'];
        return [trim((string)($p['nome'] ?? '')), 'sem CNPJ/CPF/razão social: o Omie vai procurar pelo nome'];
    }

    /** primeiro comprador da capa ("A E B", "A, B") */
    public static function clientePadrao(string $clientes): string
    {
        $partes = preg_split('/\s+E\s+|\s*,\s*|\s*\/\s*/u', trim($clientes)) ?: [];
        return trim((string)($partes[0] ?? ''));
    }

    /** nome do arquivo gerado */
    public static function nomeArquivo(string $tipo, string $empresa, int $n, DateTimeInterface $quando): string
    {
        return sprintf('Omie_%s_%s_%s_%03d.xlsx', $tipo === 'P' ? 'ContasPagar' : 'ContasReceber', strtoupper($empresa), $quando->format('Ymd-Hi'), $n);
    }
}
