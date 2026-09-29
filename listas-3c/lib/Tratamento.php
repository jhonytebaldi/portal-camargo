<?php
/* =====================================================================
   listas-3c/lib/Tratamento.php: funções PURAS do módulo Listas 3C.

   Sem banco e sem rede de propósito: é aqui que mora cada regra que o
   Jhony descreveu (nome limpo mesmo com emoji, telefone discável, resumo
   curto, motivo que entra ou não entra), e por isso dá para testar tudo
   com `php listas-3c/bin/testes.php`, sem MySQL nem API.
   ===================================================================== */
declare(strict_types=1);

final class L3cTratamento
{
    /** DDDs em uso no Brasil. O 3C recusa o mailing INTEIRO (422) se um
     *  telefone vier fora do formato, então o filtro é antes do envio. */
    private const DDDS = [
        11,12,13,14,15,16,17,18,19,21,22,24,27,28,31,32,33,34,35,37,38,
        41,42,43,44,45,46,47,48,49,51,53,54,55,61,62,63,64,65,66,67,68,69,
        71,73,74,75,77,79,81,82,83,84,85,86,87,88,89,91,92,93,94,95,96,97,98,99,
    ];

    /** Etapas do Robust (jornada 1), como aparecem na tela dele. */
    public const ETAPAS = [0 => 'Lead', 1 => 'Atendimento', 2 => 'Agendamento', 3 => 'Visita', 4 => 'Proposta', 5 => 'Negociado'];

    /**
     * Chave de comparação de um motivo de encerramento.
     * O Robust grava o motivo como texto livre dentro do andamento
     * ("Atendimento encerrado - Sem Entrada"), com caixa, acento e até
     * travessão variando ("Não Comprador – Busca para Parente/Amigo").
     * Comparar texto cru faria um motivo marcado na config nunca casar.
     */
    public static function chaveMotivo(string $motivo): string
    {
        $m = preg_replace('/^\s*atendimento encerrado\s*[-\x{2013}\x{2014}]\s*/iu', '', $motivo) ?? $motivo;
        $m = self::semAcento(mb_strtolower($m, 'UTF-8'));
        $m = preg_replace('/[\x{2013}\x{2014}]/u', '-', $m) ?? $m;
        $m = preg_replace('/[^a-z0-9]+/', ' ', $m) ?? $m;
        return trim($m);
    }

    /** Motivo legível a partir da ação do andamento de encerramento. */
    public static function motivoDaAcao(string $acao): string
    {
        return trim(preg_replace('/^\s*atendimento encerrado\s*[-\x{2013}\x{2014}]\s*/iu', '', $acao) ?? $acao);
    }

    /**
     * Decide se um atendimento encerrado entra na lista pelo motivo.
     * Devolve null quando entra, ou o motivo do descarte.
     * "Nunca" vence o modelo: é a lista que o Jhony disse que não volta
     * a ligar em hipótese nenhuma (número errado, comprou em outro lugar...).
     */
    public static function descartePorMotivo(?string $motivo, array $motivosDoModelo, array $motivosNunca): ?string
    {
        if ($motivo === null || trim($motivo) === '') return 'sem motivo de encerramento registrado';
        $k = self::chaveMotivo($motivo);
        foreach ($motivosNunca as $n) if (self::chaveMotivo((string)$n) === $k) return 'motivo que nunca entra';
        foreach ($motivosDoModelo as $m) if (self::chaveMotivo((string)$m) === $k) return null;
        return 'motivo fora do modelo';
    }

    /**
     * Telefone nacional discável (DDD + número, 10 ou 11 dígitos) ou null.
     * Tolera +55, máscara e espaços. Só desconta o 55 a partir de 12
     * dígitos: abaixo disso 55 é DDD (RS), não código do país.
     */
    public static function telefone(?string $bruto): ?string
    {
        $d = preg_replace('/\D+/', '', (string)$bruto) ?? '';
        if (strlen($d) >= 12 && strncmp($d, '55', 2) === 0) $d = substr($d, 2);
        if (strlen($d) !== 10 && strlen($d) !== 11) return null;
        if (!in_array((int)substr($d, 0, 2), self::DDDS, true)) return null;
        $assinante = substr($d, 2);
        if (strlen($assinante) === 9) return preg_match('/^9\d{8}$/', $assinante) ? $d : null;
        return preg_match('/^[2-5]\d{7}$/', $assinante) ? $d : null;
    }

    /**
     * Nome limpo para a tela do SDR.
     * O Jhony: "às vezes a pessoa tá com emoji no nome, mas o e-mail tem o
     * nome dela". Então: tira emoji/símbolo/número, arruma a caixa, e se
     * não sobrar um nome de verdade, tenta o começo do e-mail.
     */
    public static function nomeLimpo(?string $nome, ?string $email = null): string
    {
        $n = (string)$nome;
        // Mantém só letras (qualquer alfabeto), espaço, apóstrofo e hífen.
        $n = preg_replace("/[^\\p{L}\\s'\\-]+/u", ' ', $n) ?? '';
        // Hífen ou apóstrofo solto ("Ana - Hotel") não é pedaço de nome.
        $n = preg_replace("/(^|\\s)[\\-']+(?=\\s|$)/u", ' ', $n) ?? $n;
        $n = trim(preg_replace('/\s+/u', ' ', $n) ?? '');
        $n = trim($n, " -'");
        $letras = preg_match_all('/\p{L}/u', $n);
        if ($letras < 2 && $email) {
            $local = strtolower((string)strtok((string)$email, '@'));
            $partes = preg_split('/[._\-+0-9]+/', $local) ?: [];
            $partes = array_values(array_filter($partes, fn($p) => strlen($p) >= 2));
            $n = implode(' ', array_slice($partes, 0, 3));
        }
        if ($n === '') return '';
        return self::caixaDeNome($n);
    }

    /** "MARIA DA SILVA" / "maria da silva" → "Maria da Silva". */
    public static function caixaDeNome(string $n): string
    {
        $conect = ['da', 'de', 'do', 'das', 'dos', 'e'];
        $out = [];
        foreach (explode(' ', mb_strtolower($n, 'UTF-8')) as $i => $p) {
            if ($p === '') continue;
            $out[] = ($i > 0 && in_array($p, $conect, true)) ? $p : mb_strtoupper(mb_substr($p, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($p, 1, null, 'UTF-8');
        }
        return implode(' ', $out);
    }

    /** E-mail válido em minúsculas, ou '' (o 3C mostra o campo como veio). */
    public static function email(?string $e): string
    {
        $e = strtolower(trim((string)$e));
        return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
    }

    /** Canal de origem legível ("site" e "Site" viram o mesmo). */
    public static function canal(?string $origin): string
    {
        $o = trim((string)$origin);
        if ($o === '' || preg_match('/^0\s*-/', $o)) return 'Sem origem';
        return mb_strtoupper(mb_substr($o, 0, 1, 'UTF-8'), 'UTF-8') . mb_substr($o, 1, null, 'UTF-8');
    }

    /** A origem é de corretor (campanha, celular, carteira, orgânico dele)? */
    public static function origemDeCorretor(?string $origin, array $marcadores): bool
    {
        $o = self::semAcento(mb_strtolower((string)$origin, 'UTF-8'));
        foreach ($marcadores as $m) {
            $m = self::semAcento(mb_strtolower((string)$m, 'UTF-8'));
            if ($m !== '' && str_contains($o, $m)) return true;
        }
        return false;
    }

    /**
     * Resumo curto do atendimento, para caber na tela do SDR no 3C.
     * Só fatos que o Robust já tem (etapa, datas, motivo, agenda e a
     * observação do corretor), sem IA: o resumo tem que ser o mesmo toda
     * vez que a lista for montada, e sem custo por contato.
     */
    public static function resumo(array $it, int $max = 280): string
    {
        $p = [];
        $etapa = self::ETAPAS[(int)($it['stage'] ?? 0)] ?? ('Etapa ' . (int)($it['stage'] ?? 0));
        $p[] = 'Etapa ' . $etapa;
        if (!empty($it['criado_em']))    $p[] = 'entrou ' . self::dataCurta($it['criado_em']);
        if (!empty($it['encerrado_em'])) $p[] = 'encerrado ' . self::dataCurta($it['encerrado_em']) . (!empty($it['motivo']) ? ' (' . $it['motivo'] . ')' : '');
        if (!empty($it['agenda_em']))    $p[] = 'agendou para ' . self::dataCurta($it['agenda_em'], true) . ' e não veio';
        if (!empty($it['atendente']))    $p[] = 'corretor ' . $it['atendente'];
        $txt = implode('; ', $p);
        $obs = trim(preg_replace('/\s+/u', ' ', (string)($it['obs'] ?? '')) ?? '');
        if ($obs !== '') $txt .= '. Obs: ' . $obs;
        if (mb_strlen($txt, 'UTF-8') > $max) $txt = rtrim(mb_substr($txt, 0, $max - 1, 'UTF-8')) . '…';
        return $txt;
    }

    /** "2026-09-24T17:07:51-03:00" → "24/09/26" (com hora se pedir). */
    public static function dataCurta(string $iso, bool $hora = false): string
    {
        // DateTime guarda o fuso que veio no texto (-03:00 do Robust); date()
        // usaria o fuso do servidor, que na hospedagem pode ser UTC.
        try { $d = new DateTime($iso); } catch (Throwable $e) { return $iso; }
        return $d->format($hora ? 'd/m/y H:i' : 'd/m/y');
    }

    /** Máscara para demonstração: nada de nome/telefone de lead real na tela gravada. */
    public static function mascarar(string $s, string $tipo): string
    {
        if ($s === '') return '';
        if ($tipo === 'telefone') return '(' . substr($s, 0, 2) . ') •••••-••••';
        if ($tipo === 'email') return mb_substr($s, 0, 1, 'UTF-8') . '•••@•••';
        $out = [];
        foreach (explode(' ', $s) as $p) $out[] = mb_substr($p, 0, 1, 'UTF-8') . str_repeat('•', max(2, min(6, mb_strlen($p, 'UTF-8') - 1)));
        return implode(' ', $out);
    }

    public static function semAcento(string $s): string
    {
        $de   = ['á','à','ã','â','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','õ','ô','ö','ú','ù','û','ü','ç','ñ'];
        $para = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'];
        return str_replace($de, $para, $s);
    }
}
