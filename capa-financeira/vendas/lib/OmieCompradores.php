<?php
/* =====================================================================
   capa-financeira/vendas/lib/OmieCompradores.php — compradores da venda
   no Omie. REGRA: o comprador é sempre conferido/cadastrado no Omie da
   VERTICAL (decisão do Jhony, 03/11/2026), seja qual for a empresa da venda.

   - localizar(): pelo CPF/CNPJ (exato) e, se não achar, pelo nome — usa
     OmieEnvio::localizarCadastro (mesma lista em cache da Capa Financeira,
     com as proteções contra o anti-abuso do Omie).
   - cadastrar(): IncluirCliente; guarda o código devolvido e acrescenta o
     cadastro novo na lista em cache (senão a próxima conferência só o
     acharia quando o cache expirasse).

   Desenvolvimento local: com CV_OMIE_FAKE definido no config.php, usa um
   arquivo JSON no lugar do Omie (nunca definir em produção).
   ===================================================================== */
declare(strict_types=1);

require_once dirname(__DIR__, 3) . '/recibos-omie/lib/OmieApi.php';
require_once dirname(__DIR__, 2) . '/lib/OmieEnvio.php';
require_once __DIR__ . '/Venda.php';

final class OmieCompradores
{
    public const EMPRESA = 'vertical';

    private static function fake(): bool { return defined('CV_OMIE_FAKE') && CV_OMIE_FAKE; }
    private static function fakeArq(): string { return (defined('CF_DATA_DIR') ? CF_DATA_DIR : sys_get_temp_dir()) . '/omie-fake-clientes.json'; }
    private static function fakeLer(): array { $f = self::fakeArq(); return is_file($f) ? (json_decode((string)file_get_contents($f), true) ?: []) : []; }

    public static function disponivel(string $empresa = self::EMPRESA): bool
    {
        return self::fake() || isset(OmieApi::contas()[$empresa]);
    }

    /**
     * @return array{status: 'ok'|'so_nome'|'varios'|'nao'|'erro', codigo: ?int, nome: ?string, doc_omie: ?string, msg: string}
     */
    public static function localizar(string $doc, string $nome, string $empresa = self::EMPRESA): array
    {
        if (!self::disponivel($empresa)) return ['status' => 'erro', 'codigo' => null, 'nome' => null, 'doc_omie' => null, 'msg' => 'Omie da ' . strtoupper($empresa) . ' não configurado no portal (OMIE_CONTAS) — não dá para conferir.'];
        if (self::fake()) {
            foreach (self::fakeLer() as $c) if ($c['doc'] === $doc) return ['status' => 'ok', 'codigo' => (int)$c['codigo'], 'nome' => $c['nome'], 'doc_omie' => $c['doc'], 'msg' => 'cadastrado no Omie: ' . $c['nome'] . ' (cód. ' . $c['codigo'] . ')'];
            foreach (self::fakeLer() as $c) if (Venda::chave($c['nome']) === Venda::chave($nome)) return ['status' => 'so_nome', 'codigo' => (int)$c['codigo'], 'nome' => $c['nome'], 'doc_omie' => $c['doc'], 'msg' => 'existe cadastro com esse nome e outro documento'];
            return ['status' => 'nao', 'codigo' => null, 'nome' => null, 'doc_omie' => null, 'msg' => 'NÃO encontrado no Omie'];
        }
        try {
            $r = OmieEnvio::localizarCadastro($empresa, Venda::docFormatado($doc), $nome);
        } catch (Throwable $e) {
            return ['status' => 'erro', 'codigo' => null, 'nome' => null, 'doc_omie' => null, 'msg' => 'Não consegui consultar o Omie agora: ' . $e->getMessage()];
        }
        $c = $r['cadastro'] ?? null;
        return ['status' => $r['status'], 'codigo' => $c ? (int)$c['codigo_cliente_omie'] : null, 'nome' => $c['razao_social'] ?? null,
                'doc_omie' => $c ? preg_replace('/[^0-9A-Za-z]/', '', (string)($c['cnpj_cpf'] ?? '')) : null, 'msg' => $r['msg']];
    }

    /** pedido de inclusão (separado para dar para conferir/testar sem chamar a API) */
    public static function pedido(array $c): array
    {
        $tipo = Venda::tipoDoc($c['documento']);
        $p = [
            'codigo_cliente_integracao' => 'CV' . $c['documento'],
            'razao_social' => mb_substr($c['nome'], 0, 60),
            'nome_fantasia' => mb_substr($c['nome'], 0, 100),
            'cnpj_cpf' => Venda::docFormatado($c['documento']),
            'pessoa_fisica' => $tipo === 'CPF' ? 'S' : 'N',
        ];
        if ($c['email'] !== '') $p['email'] = $c['email'];
        return $p;
    }

    /** @return array{ok: bool, codigo: ?int, msg: string} */
    public static function cadastrar(array $c, string $empresa = self::EMPRESA): array
    {
        $p = self::pedido($c);
        if (self::fake()) {
            $l = self::fakeLer(); $cod = 9000000 + count($l) + 1;
            $l[] = ['codigo' => $cod, 'nome' => $p['razao_social'], 'doc' => $c['documento'], 'email' => $c['email']];
            file_put_contents(self::fakeArq(), json_encode($l, JSON_UNESCAPED_UNICODE));
            return ['ok' => true, 'codigo' => $cod, 'msg' => 'cadastrado (simulação local)'];
        }
        try {
            $r = OmieApi::call($empresa, 'geral/clientes/', 'IncluirCliente', $p);
            $cod = (int)($r['codigo_cliente_omie'] ?? 0);
            if (!$cod) return ['ok' => false, 'codigo' => null, 'msg' => 'Omie não devolveu o código do cadastro: ' . json_encode($r, JSON_UNESCAPED_UNICODE)];
            self::lembrar($cod, $p, $c['documento'], $empresa);
            return ['ok' => true, 'codigo' => $cod, 'msg' => 'cadastrado no Omie da ' . strtoupper($empresa) . ' (cód. ' . $cod . ')'];
        } catch (Throwable $e) {
            // já existe (ex.: cadastrado por outra pessoa entre a conferência e a conclusão) → localizar de novo
            if (preg_match('/j[áa] (est[áa] )?cadastrad|j[áa] existe|Client-102/iu', $e->getMessage())) {
                $l = self::localizar($c['documento'], $c['nome'], $empresa);
                if ($l['status'] === 'ok') return ['ok' => true, 'codigo' => $l['codigo'], 'msg' => 'já estava cadastrado no Omie (cód. ' . $l['codigo'] . ')'];
            }
            return ['ok' => false, 'codigo' => null, 'msg' => 'Omie recusou o cadastro: ' . $e->getMessage()];
        }
    }

    /** acrescenta o cadastro recém-criado na lista em cache da Capa Financeira (mesmo arquivo do OmieEnvio::cadastros) */
    private static function lembrar(int $cod, array $p, string $doc, string $empresa = self::EMPRESA): void
    {
        $d = (defined('CF_DATA_DIR') ? CF_DATA_DIR : dirname(__DIR__, 4) . '/capa-dados') . '/omie-cache';
        $f = $d . '/cadastros-' . $empresa . '.json';
        if (!is_file($f)) return;
        $j = json_decode((string)file_get_contents($f), true);
        if (!is_array($j)) return;
        $mt = filemtime($f);
        $reg = ['codigo_cliente_omie' => $cod, 'razao_social' => $p['razao_social'], 'nome_fantasia' => $p['nome_fantasia'], 'cnpj_cpf' => $p['cnpj_cpf'], 'inativo' => 'N', 'dadosBancarios' => ['cChavePix' => '']];
        $j['por_doc'][$doc][] = $reg;
        $j['por_nome'][CapaParser::key($p['razao_social'])][] = $reg;
        @file_put_contents($f, json_encode($j, JSON_UNESCAPED_UNICODE));
        if ($mt) @touch($f, $mt);   // não "rejuvenesce" a lista
    }
}
