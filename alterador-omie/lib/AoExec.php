<?php
/* =====================================================================
   alterador-omie/lib/AoExec.php — envia um item da rodada ao Omie e confere.

   Regras do Omie que moldam este arquivo (testadas em 17/11/2026):
   - AlterarConta* é PARCIAL: vai só o id + os campos que mudam.
   - Consulta IDÊNTICA repetida em < ~60 s devolve a resposta ANTIGA (ou REDUNDANT).
     Por isso cada leitura de um título usa uma "variante" não usada nos últimos 70 s:
       id = ConsultarConta* por codigo_lancamento_omie
       ci = ConsultarConta* por codigo_lancamento_integracao (só se o título tiver)
       pq = PesquisarLancamentos por nCodTitulo (nRegPorPagina variável → sempre inédita)
   - Depois do Alterar, a PesquisarLancamentos pode levar alguns segundos a refletir.
   - Em título pago, vencimento/previsão/valor são aceitos e IGNORADOS → a conferência
     é o que separa "atraso do Omie" de "o Omie não aplicou".
   Conferência: 1ª ≥3 s (ci ou pq) · 2ª ≥10 s (pq) · 3ª ≥72 s depois da leitura do "antes"
   (id — a mesma consulta do "antes", que a essa altura já não vem do cache do Omie).
   Só a 3ª pode concluir "não aplicado".
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/AoTitulo.php';
require_once __DIR__ . '/AoRegras.php';
require_once __DIR__ . '/AoCache.php';

final class AoExec
{
    public const ESPERA = [1 => 3, 2 => 10, 3 => 72];

    /* ---------------- leitura de um título ---------------- */

    private static function usosArq(string $emp): string { return AoCache::dir() . "/leituras-$emp.json"; }
    private static function usou(string $emp, string $chave): int
    {
        $j = json_decode((string)@file_get_contents(self::usosArq($emp)), true) ?: [];
        return (int)($j[$chave] ?? 0);
    }
    private static function marcar(string $emp, string $chave): void
    {
        $f = self::usosArq($emp);
        $j = json_decode((string)@file_get_contents($f), true) ?: [];
        $j = array_filter($j, fn($t) => $t > time() - 600);
        $j[$chave] = time();
        @file_put_contents($f, json_encode($j));
    }
    private static function path(string $tipo): string { return $tipo === 'R' ? 'financas/contareceber/' : 'financas/contapagar/'; }
    private static function suf(string $tipo): string { return $tipo === 'R' ? 'ContaReceber' : 'ContaPagar'; }

    /** variantes livres (não usadas nos últimos 70 s) para este título, na ordem de preferência */
    public static function variantesLivres(string $emp, array $it, array $pref): array
    {
        $out = [];
        foreach ($pref as $v) {
            if ($v === 'ci' && (string)$it['ci'] === '') continue;
            if ($v !== 'pq' && time() - self::usou($emp, "{$it['tipo']}{$it['omie_id']}:$v") < 70) continue;
            $out[] = $v;
        }
        return $out;
    }

    /** lê o título por uma variante. null = não existe mais no Omie. Lança RuntimeException em erro do Omie. */
    public static function ler(string $emp, array $it, string $var): ?array
    {
        $tipo = (string)$it['tipo']; $id = (int)$it['omie_id'];
        self::marcar($emp, "$tipo$id:$var");
        try {
            if ($var === 'pq') {
                $r = OmieApi::call($emp, 'financas/pesquisartitulos/', 'PesquisarLancamentos', ['nPagina' => 1, 'nRegPorPagina' => random_int(5, 99), 'cNatureza' => $tipo, 'nCodTitulo' => $id], true);
                foreach ($r['titulosEncontrados'] ?? [] as $x) if ((int)(($x['cabecTitulo'] ?? [])['nCodTitulo'] ?? 0) === $id) return AoTitulo::dePesquisa($x);
                return null;
            }
            $chave = $var === 'ci' ? ['codigo_lancamento_integracao' => (string)$it['ci']] : ['codigo_lancamento_omie' => $id];
            $x = OmieApi::call($emp, self::path($tipo), 'Consultar' . self::suf($tipo), $chave, true);
            return $x ? AoTitulo::deCadastro($x, $tipo) : null;
        } catch (RuntimeException $e) {
            if (preg_match('/n[ãa]o cadastrad|n[ãa]o (foi )?(encontrad|localizad)|Client-105|Client-103|n[ãa]o existem registros|Client-5113/iu', $e->getMessage())) return null;
            throw $e;
        }
    }

    /** erro passageiro do Omie? devolve em quantos segundos tentar de novo (0 = não é passageiro) */
    public static function passageiro(string $msg): int
    {
        if (preg_match('/MISUSE|consumo indevido/iu', $msg)) return -1;   // bloqueio de 30 min: parar a rodada
        if (preg_match('/tentar novamente [àa]s (\d{2}):(\d{2}):(\d{2})/u', $msg, $m)) {
            $alvo = (new DateTimeImmutable('today'))->setTime((int)$m[1], (int)$m[2], (int)$m[3])->getTimestamp();
            return max(5, min(180, $alvo - time() + 3));
        }
        if (preg_match('/Aguarde (\d+) segundos/u', $msg, $m)) return max(5, (int)$m[1] + 2);
        if (preg_match('/REDUNDANT|Client-6\]|Client-1880|sendo executada|sendo processada|j[áa] foi processada|bloqueada temporariamente|pausa preventiva|indispon[ií]vel|timed out|timeout|Client-1013/iu', $msg)) return 30;
        return 0;
    }

    /** diferenças de campos entre o que se pretendia e o que veio */
    private static function falhas(array $alvo, array $lido): array
    {
        $f = [];
        foreach ($alvo as $c => $v) {
            if (!AoTitulo::fonteMostra($lido, $c)) continue;
            if (!AoTitulo::igual($c, $v, AoTitulo::valor($lido, $c))) $f[$c] = AoTitulo::valor($lido, $c);
        }
        return $f;
    }

    /* ---------------- envio ---------------- */

    /**
     * Envia um item: relê o título, recalcula, manda o Alterar mínimo.
     * @return array{status:string, msg:string, tentar_em?:int, parar?:bool}
     */
    public static function enviar(PDO $pdo, string $emp, array $rod, array $it, array $ctx): array
    {
        $ops = json_decode((string)$rod['ops'], true) ?: []; $opts = json_decode((string)$rod['opts'], true) ?: [];
        $alvo = json_decode((string)$it['alvo'], true) ?: []; $fixos = json_decode((string)($it['fixos'] ?? ''), true) ?: [];
        $vars = self::variantesLivres($emp, $it, ['id', 'ci', 'pq']);
        if (array_key_exists('pix', $alvo)) $vars = array_values(array_diff($vars, ['pq']));
        if (!$vars) return ['status' => 'pendente', 'msg' => 'aguardando para reler o título (o Omie não deixa repetir a consulta em menos de 1 min)', 'tentar_em' => 30];
        try { $t = self::ler($emp, $it, $vars[0]); }
        catch (RuntimeException $e) { return self::erroOmie($e->getMessage(), 'ao reler o título'); }
        if (!$t) return ['status' => 'erro', 'msg' => 'o título não existe mais no Omie (foi excluído?)'];
        $t['tipo'] = (string)$it['tipo'];
        if ($t['_fonte'] === 'pesquisa') { $c0 = json_decode((string)$it['antes'], true) ?: []; foreach (['forma', 'fin', 'bol', 'bloq'] as $k) $t[$k] = $c0[$k] ?? null; }
        $r = AoRegras::calcular($t, $ops, (int)$it['pos'], (int)$it['total'], $opts, $ctx, $fixos);
        $antesJson = json_encode($t, JSON_UNESCAPED_UNICODE);
        $pdo->prepare('UPDATE ao_itens SET antes = ?, lido_em = ? WHERE id = ?')->execute([$antesJson, time(), $it['id']]);
        AoCache::lembrar($emp, $t);   // a lista local fica com o título como está agora no Omie
        if (!empty($r['bloqueios']['*'])) return ['status' => 'bloqueado', 'msg' => $r['bloqueios']['*']];
        // o que falta mudar = campos do alvo que ainda não estão como pretendido
        $payloadCampos = []; $conflitos = [];
        foreach ($alvo as $c => $v) {
            if (AoTitulo::igual($c, AoTitulo::valor($t, $c), $v)) continue;
            if (isset($r['bloqueios'][$c])) { $conflitos[] = AoTitulo::rotulo($c, $t['tipo']) . ': ' . $r['bloqueios'][$c]; continue; }
            if (!array_key_exists($c, $r['novo']) || !AoTitulo::igual($c, $r['novo'][$c], $v))
                $conflitos[] = AoTitulo::rotulo($c, $t['tipo']) . ' mudou no Omie desde a prévia (agora: "' . AoTitulo::mostrar($c, AoTitulo::valor($t, $c), AoCache::nomes($ctx)) . '") — o resultado seria diferente do que você aprovou';
            else $payloadCampos[] = $c;
        }
        if ($conflitos) return ['status' => 'conflito', 'msg' => implode('; ', $conflitos)];
        if (!$payloadCampos) return ['status' => 'ja_ok', 'msg' => 'o título no Omie já estava como pretendido'];
        $ped = ['codigo_lancamento_omie' => (int)$it['omie_id']];
        foreach ($payloadCampos as $c) {
            if ($c === 'obs') { $ped['observacao'] = (string)($r['extra']['obs_add'] ?? ''); continue; }
            $ped += AoTitulo::paraOmie($c, $alvo[$c], (string)$it['tipo'], $r['extra']);
        }
        try { OmieApi::call($emp, self::path((string)$it['tipo']), 'Alterar' . self::suf((string)$it['tipo']), $ped); }
        catch (RuntimeException $e) { return self::erroOmie($e->getMessage(), 'ao alterar'); }
        $pdo->prepare('UPDATE ao_itens SET payload = ?, enviado_em = ?, passo = 1 WHERE id = ?')
            ->execute([json_encode(array_intersect_key($alvo, array_flip($payloadCampos)), JSON_UNESCAPED_UNICODE), time(), $it['id']]);
        return ['status' => 'enviado', 'msg' => 'alteração aceita pelo Omie — conferindo…', 'tentar_em' => self::ESPERA[1]];
    }

    private static function erroOmie(string $msg, string $quando): array
    {
        $p = self::passageiro($msg);
        $msg = preg_replace('/^Omie:\s*(ERROR:\s*)?/u', '', $msg);
        if ($p < 0) return ['status' => 'pendente', 'msg' => "o Omie bloqueou a API por uso excessivo ($quando) — rodada pausada; continue em 30 min pelo histórico", 'parar' => true];
        if ($p > 0) return ['status' => 'pendente', 'msg' => "limite temporário do Omie $quando — nova tentativa em {$p}s ($msg)", 'tentar_em' => $p];
        return ['status' => 'erro', 'msg' => "o Omie recusou ($quando): $msg"];
    }

    /* ---------------- conferência ---------------- */

    /** @return array{status:string, msg:string, tentar_em?:int, depois?:array} */
    public static function conferir(PDO $pdo, string $emp, array $it, array $ctx): array
    {
        $passo = max(1, (int)$it['passo']); $env = (int)$it['enviado_em']; $lido = (int)$it['lido_em'];
        $pay = json_decode((string)$it['payload'], true) ?: [];
        $quando = $passo === 3 ? max($env, $lido) + self::ESPERA[3] : $env + self::ESPERA[$passo];
        if (time() < $quando) return ['status' => 'enviado', 'msg' => 'aguardando para conferir', 'tentar_em' => $quando - time()];
        $temPix = array_key_exists('pix', $pay);
        $pref = match ($passo) { 1 => $temPix ? ['ci'] : ['ci', 'pq'], 2 => $temPix ? ['ci'] : ['pq'], default => ['id', 'ci'] };
        $vars = self::variantesLivres($emp, $it, $pref);
        if (!$vars) { self::proximo($pdo, $it, $passo); return ['status' => 'enviado', 'msg' => 'conferência adiada (consulta usada há pouco)', 'tentar_em' => self::espera($it, $passo + 1)]; }
        try { $t = self::ler($emp, $it, $vars[0]); }
        catch (RuntimeException $e) {
            $p = self::passageiro($e->getMessage());
            $falhasLeitura = (int)$it['falhas'] + 1;
            $pdo->prepare('UPDATE ao_itens SET falhas = ? WHERE id = ?')->execute([$falhasLeitura, $it['id']]);
            if ($passo >= 3 && $falhasLeitura >= 4) return ['status' => 'aviso', 'msg' => 'alteração aceita pelo Omie; não consegui conferir de volta (' . preg_replace('/^Omie:\s*/u', '', $e->getMessage()) . ') — confira no Omie mais tarde'];
            if ($p < 0) return ['status' => 'aviso', 'msg' => 'alteração aceita pelo Omie; a conferência esbarrou no bloqueio de uso da API — use "Reconferir" no histórico mais tarde', 'parar' => true];
            return ['status' => 'enviado', 'msg' => 'conferência adiada (limite temporário do Omie)', 'tentar_em' => max(15, $p)];
        }
        if (!$t) return ['status' => 'erro', 'msg' => 'o Omie aceitou a alteração, mas a consulta de volta não encontrou o título — confira no Omie'];
        $t['tipo'] = (string)$it['tipo'];
        $f = self::falhas($pay, $t);
        $nomes = AoCache::nomes($ctx);
        if (!$f) {
            $antes = json_decode((string)$it['antes'], true) ?: [];
            $outros = [];
            if ($t['_fonte'] === 'cadastro' && ($antes['_fonte'] ?? '') === 'cadastro')
                foreach (array_keys(AoTitulo::CAMPOS) as $c) if (!isset($pay[$c]) && !AoTitulo::igual($c, AoTitulo::valor($antes, $c), AoTitulo::valor($t, $c))) $outros[] = AoTitulo::rotulo($c, $t['tipo']);
            AoCache::lembrar($emp, $t);
            $pdo->prepare('UPDATE ao_itens SET depois = ? WHERE id = ?')->execute([json_encode($t, JSON_UNESCAPED_UNICODE), $it['id']]);
            if ($outros) return ['status' => 'aviso', 'msg' => 'alterado e conferido, mas outros campos também estão diferentes de antes (' . implode(', ', $outros) . ') — alguém mexeu no título ao mesmo tempo? Confira no Omie'];
            return ['status' => 'conferido', 'msg' => 'alterado e conferido (' . implode(', ', array_map(fn($c) => AoTitulo::rotulo($c, $t['tipo']), array_keys($pay))) . ')'];
        }
        if ($passo < 3) { self::proximo($pdo, $it, $passo); return ['status' => 'enviado', 'msg' => 'o Omie ainda não mostra a mudança — conferindo de novo', 'tentar_em' => self::espera($it, $passo + 1)]; }
        $pdo->prepare('UPDATE ao_itens SET depois = ? WHERE id = ?')->execute([json_encode($t, JSON_UNESCAPED_UNICODE), $it['id']]);
        AoCache::lembrar($emp, $t);
        $d = []; foreach ($f as $c => $v) $d[] = AoTitulo::rotulo($c, $t['tipo']) . ' ficou "' . AoTitulo::mostrar($c, $v, $nomes) . '" (pedido: "' . AoTitulo::mostrar($c, $pay[$c], $nomes) . '")';
        return ['status' => 'nao_aplicado', 'msg' => 'o Omie respondeu "alterado com sucesso" mas não aplicou: ' . implode('; ', $d)];
    }

    private static function proximo(PDO $pdo, array &$it, int $passo): void
    {
        $it['passo'] = min(3, $passo + 1);
        $pdo->prepare('UPDATE ao_itens SET passo = ? WHERE id = ?')->execute([$it['passo'], $it['id']]);
    }
    private static function espera(array $it, int $passo): int
    {
        $passo = min(3, $passo);
        $q = $passo === 3 ? max((int)$it['enviado_em'], (int)$it['lido_em']) + self::ESPERA[3] : (int)$it['enviado_em'] + self::ESPERA[$passo];
        return max(1, $q - time());
    }
}
