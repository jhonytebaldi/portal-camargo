<?php
/* =====================================================================
   imoveis-campanha/lib/Ficha.php: as regras da ficha, sem banco nem rede
   (bin/testes.php prova cada uma).

   A ficha é uma lista de campos {chave, rotulo, valor, pode_falar, fonte}
   NA ORDEM em que a Bianca lê. "pode_falar" é o LIMITAR que o Jhony pediu
   na reunião de 22/10: campo desligado não chega à Bianca, e se o lead
   perguntar ela passa ao corretor. Campo vazio também não chega.
   ===================================================================== */
declare(strict_types=1);

final class ImcFicha
{
    /** O que o código do Robust preenche (GET /v1/imoveis/{id}). */
    public const ROBUST = [
        'tipo' => 'Tipo', 'bairro' => 'Bairro', 'quartos' => 'Quartos', 'suites' => 'Suítes',
        'vagas' => 'Vagas', 'valor' => 'Valor', 'entrega_prevista' => 'Entrega prevista',
    ];
    /** O que o corretor completa (o Robust não tem ou não é da campanha). */
    public const CORRETOR = ['metragem' => 'Metragem', 'abertura' => 'Abertura', 'entrada' => 'Entrada', 'fgts' => 'FGTS'];
    public const DUVIDAS = 'duvidas';
    public const MESES = ['janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
    public const MESES_CURTOS = ['jan', 'fev', 'mar', 'abr', 'mai', 'jun', 'jul', 'ago', 'set', 'out', 'nov', 'dez'];

    /** R$ 319.900 */
    public static function reais(float|int|string|null $v): string
    {
        if ($v === null || $v === '' || (float)$v <= 0) return '';
        return 'R$ ' . number_format((float)$v, (float)$v == floor((float)$v) ? 0 : 2, ',', '.');
    }

    /**
     * Campos do Robust a partir do detalhe do imóvel. mes_conclusao conta de
     * ZERO (0 = janeiro), como a Busca já trata em busca/sync.php.
     * Número zero ou ausente fica vazio: "0 suítes" não é fato que ela deva dizer.
     */
    public static function doRobust(array $d): array
    {
        $n = fn($k) => isset($d[$k]) && (int)$d[$k] > 0 ? (string)(int)$d[$k] : '';
        $mes = isset($d['mes_conclusao']) && $d['mes_conclusao'] !== null ? (int)$d['mes_conclusao'] : null;
        $ano = isset($d['ano_conclusao']) ? (int)$d['ano_conclusao'] : 0;
        $entrega = $ano > 2000 ? (($mes !== null && $mes >= 0 && $mes <= 11 ? self::MESES[$mes] . ' de ' : '') . $ano) : '';
        return [
            'tipo' => trim((string)($d['tipo'] ?? '')),
            'bairro' => trim((string)($d['endereco_bairro'] ?? '')),
            'quartos' => $n('quartos'),
            'suites' => $n('suites'),
            'vagas' => $n('vagas'),
            'valor' => self::reais($d['valor_venda'] ?? null),
            'entrega_prevista' => $entrega,
        ];
    }

    /** Parcelas mensais que faltam até 'AAAA-MM', a partir do mês que vem (out/2026 até mai/2027 = 7). Mesma conta do Trigger. */
    public static function parcelasQueFaltam(string $ate, DateTimeInterface $agora): int
    {
        if (!preg_match('/^(\d{4})-(\d{2})$/', $ate, $m)) return 0;
        $sp = (new DateTimeImmutable('@' . $agora->getTimestamp()))->setTimezone(new DateTimeZone('America/Sao_Paulo'));
        return max(0, (int)$m[1] * 12 + (int)$m[2] - ((int)$sp->format('Y') * 12 + (int)$sp->format('n')));
    }

    /** 'mai/2027' */
    public static function mesCurto(?string $ate): string
    {
        if (!$ate || !preg_match('/^(\d{4})-(\d{2})$/', $ate, $m)) return '';
        return self::MESES_CURTOS[(int)$m[2] - 1] . '/' . $m[1];
    }

    /** O valor de uma chave na ficha ('' se não houver). */
    public static function valor(array $campos, string $chave): string
    {
        foreach ($campos as $c) if (($c['chave'] ?? '') === $chave) return (string)($c['valor'] ?? '');
        return '';
    }

    /**
     * Junta o que veio do formulário na ficha atual SEM mudar a ordem dos
     * campos que já existiam (é a ordem em que ela lê) e SEM mexer no
     * pode_falar (isso é a aba "O que ela fala"). Campo novo entra no fim,
     * liberado; campo que ficou vazio sai.
     *
     * $novos: [chave => [rotulo, valor, fonte]]; chaves de $atuais que não
     * vierem em $novos ficam como estão, a não ser que estejam em $removidas.
     */
    public static function juntar(array $atuais, array $novos, array $removidas = []): array
    {
        $out = [];
        $vistos = [];
        foreach ($atuais as $c) {
            $k = (string)($c['chave'] ?? '');
            if ($k === '' || in_array($k, $removidas, true)) continue;
            if (isset($novos[$k])) {
                [$rotulo, $valor, $fonte] = $novos[$k];
                $vistos[$k] = true;
                if (trim($valor) === '') continue;
                $c['rotulo'] = trim($rotulo) !== '' ? trim($rotulo) : (string)$c['rotulo'];
                $c['valor'] = trim($valor);
                $c['fonte'] = $fonte;
            }
            $c['pode_falar'] = (bool)($c['pode_falar'] ?? true);
            $out[] = $c;
        }
        foreach ($novos as $k => [$rotulo, $valor, $fonte]) {
            if (isset($vistos[$k]) || trim($valor) === '' || in_array($k, $removidas, true)) continue;
            $out[] = ['chave' => $k, 'rotulo' => trim($rotulo), 'valor' => trim($valor), 'pode_falar' => true, 'fonte' => $fonte];
        }
        return $out;
    }

    /** Aplica a aba "O que ela fala": [chave => bool]. Chave fora do mapa fica desligada (checkbox desmarcado não é enviado). */
    public static function limitar(array $campos, array $liberados): array
    {
        foreach ($campos as &$c) $c['pode_falar'] = !empty($liberados[(string)$c['chave']]);
        return $campos;
    }

    /** Chave para um campo livre ("Home club" → home_club), sem colidir com as existentes. */
    public static function chaveLivre(string $rotulo, array $usadas): string
    {
        $base = trim(preg_replace('/[^a-z0-9]+/', '_', strtolower(self::semAcento($rotulo))), '_') ?: 'campo';
        $base = substr($base, 0, 40);
        $k = $base; $i = 2;
        while (in_array($k, $usadas, true)) $k = $base . '_' . $i++;
        return $k;
    }

    /** id do imóvel novo: 'portal-1588-geminado-vila-nova' (o banco exige ^[a-z0-9-]{3,80}$). */
    public static function idNovo(string $nome, string $codigo): string
    {
        $s = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower(self::semAcento($nome))), '-');
        $c = preg_replace('/\D/', '', $codigo);
        return substr('portal-' . ($c !== '' ? $c . '-' : '') . ($s !== '' ? $s : 'imovel'), 0, 80);
    }

    public static function semAcento(string $t): string
    {
        $t = class_exists('Normalizer') ? (string)Normalizer::normalize($t, Normalizer::FORM_D) : $t;
        return (string)preg_replace('/\p{Mn}/u', '', $t);
    }

    /** Números de um texto: "R$ 319.900" → 319900; "5,5 m" → 5.5; "36x" → 36. */
    public static function numeros(string $t): array
    {
        preg_match_all('/\d{1,3}(?:\.\d{3})+(?:,\d+)?|\d+(?:,\d+)?/', $t, $m);
        $out = [];
        foreach ($m[0] as $n) {
            $v = (float)str_replace(['.', ','], ['', '.'], $n);
            if (preg_match('/^\s*mil\b/i', substr($t, strpos($t, $n) + strlen($n), 5))) $v *= 1000;
            $out[] = $v;
        }
        return $out;
    }

    /**
     * Quais campos liberados a resposta usou. Campo com número (fora ano):
     * o número aparece na resposta. Campo sem número: o valor inteiro
     * ("junho de 2027") ou o nome do campo ("FGTS") aparece. Ano não conta
     * sozinho: "maio de 2027" não é a "Entrega prevista: junho de 2027".
     * O nome do imóvel sai do texto antes ("Geminado Vila Nova" não é uso
     * do Tipo nem do Bairro), e valor de uma palavra só não casa por texto.
     * Só serve para marcar a ficha na prévia e na auditoria; nada decide com isto.
     */
    public static function usados(string $resposta, array $campos, string $nome = ''): array
    {
        $semAno = fn(array $ns) => array_values(array_filter($ns, fn($n) => $n >= 10 && !($n >= 1900 && $n <= 2100 && $n == floor($n))));
        $resNum = array_map('strval', $semAno(self::numeros($resposta)));
        $resTxt = strtolower(self::semAcento($resposta));
        if (trim($nome) !== '') $resTxt = str_replace(strtolower(self::semAcento(trim($nome))), ' ', $resTxt);
        $out = [];
        foreach ($campos as $c) {
            $valor = trim((string)($c['valor'] ?? ''));
            if (empty($c['pode_falar']) || $valor === '') continue;
            $nums = array_map('strval', $semAno(self::numeros($valor)));
            if ($nums) { if (array_intersect($nums, $resNum)) $out[] = (string)$c['chave']; continue; }
            $val = strtolower(self::semAcento($valor));
            $rot = strtolower(self::semAcento((string)$c['rotulo']));
            if ((str_contains($val, ' ') && strlen($val) >= 4 && str_contains($resTxt, $val)) || (strlen($rot) >= 4 && str_contains($resTxt, $rot))) $out[] = (string)$c['chave'];
        }
        return $out;
    }

    /** 'maio de 2027' */
    public static function mesLongo(?string $ate): string
    {
        if (!$ate || !preg_match('/^(\d{4})-(\d{2})$/', $ate, $m)) return '';
        return self::MESES[(int)$m[2] - 1] . ' de ' . $m[1];
    }

    /** A resposta passou ao corretor? (é a frase que o Trigger escreve quando falta o dado) */
    public static function passouAoCorretor(string $resposta): bool
    {
        return (bool)preg_match('/\bconfirm[oa]\b.*\b(com|pelo|o)\b|\bconfirma (esse|este|isso)\b/u', strtolower(self::semAcento($resposta)));
    }
}
