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

    /** Como a observação do atendimento aparece no resumo (e o que a máscara procura). */
    public const ROTULO_OBS = 'Obs. do atendimento no Robust:';

    /**
     * Nome da lista no padrão que o time já usa à mão na Campanha Padrão do
     * 3C (lido em 29/09/2026 por GET nas 15 listas dela): período entre
     * colchetes, as iniciais das etapas (L.A.A = Lead, Atendimento,
     * Agendamento) e o tipo, em caixa alta. Ex.: "[15-09 a 25-09] L.A.A
     * ENCERRADOS MOTIVOS". O Jhony pediu isso "pra gente não confundir".
     * O sufixo " PORTAL" separa, na mesma campanha, a lista que o portal
     * montou da que foi montada à mão (as duas convivem na Campanha Padrão).
     */
    public static function nomePadrao(array $modelo, string $de, string $ate): string
    {
        return '[' . date('d-m', strtotime($de)) . ' a ' . date('d-m', strtotime($ate)) . '] ' . self::sufixoNome($modelo);
    }

    /** A parte do nome que não depende do período (a tela usa para o exemplo). */
    public static function sufixoNome(array $modelo): string
    {
        $tipo = (string)($modelo['tipo'] ?? '');
        if ($tipo === 'agendamento_vencido') return 'AGENDAMENTO VENCIDO PORTAL';
        $sigla = ['L', 'A', 'A', 'V'];
        $etapas = array_values(array_unique(array_map('intval', (array)($modelo['etapas'] ?? []))));
        sort($etapas);
        $ini = implode('.', array_map(fn($e) => $sigla[$e] ?? (string)$e, $etapas));
        $resto = $tipo === 'encerrados' ? 'ENCERRADOS MOTIVOS' : 'ATIVOS';
        return trim($ini . ' ' . $resto) . ' PORTAL';
    }

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

    /** O 3C guarda o número da ligação como 55 + DDD + número (medido em /calls). */
    public static function telefone3c(string $nacional): string
    {
        return '55' . $nacional;
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
     * O atendimento fica fora por ser lead recente de origem do corretor?
     * Três condições, todas pedidas pelo Jhony em 30/09/2026:
     *  - "Geralmente não quero pegar esses leads recentes, que são campanha
     *    deles, mas eventualmente quero": quem monta a lista marca a caixa
     *    'incluir_recentes_corretor' e aí ninguém fica fora por isso;
     *  - "Se entrou ontem e o corretor encerrou ontem, esse cara está livre":
     *    atendimento ENCERRADO nunca é segurado por esta regra; ela só
     *    protege o atendimento que ainda está aberto com o corretor;
     *  - recente = cadastrado há menos de 'dias_origem_corretor' dias (0
     *    desliga a regra).
     */
    public static function seguraPorCorretor(array $a, array $modelo, int $agora): bool
    {
        if (!empty($modelo['incluir_recentes_corretor'])) return false;
        $dias = (int)($modelo['dias_origem_corretor'] ?? 0);
        if ($dias <= 0) return false;
        // ended_at preenchido ou ativo=false: o corretor já encerrou.
        if (!empty($a['ended_at']) || (array_key_exists('ativo', $a) && ($a['ativo'] === false || $a['ativo'] === 0 || $a['ativo'] === 'false'))) return false;
        if (!self::origemDeCorretor($a['origin'] ?? '', (array)($modelo['origens_corretor'] ?? []))) return false;
        $criado = strtotime((string)($a['created_at'] ?? ''));
        return $criado !== false && $criado > $agora - $dias * 86400;
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
        // Rótulo por extenso: o Jhony viu "Obs: ***" no vídeo e não soube de
        // onde vinha. É o campo de observação do atendimento no Robust
        // (texto livre do corretor), e o SDR precisa reconhecer isso no 3C.
        if ($obs !== '') $txt .= '. ' . self::ROTULO_OBS . ' ' . $obs;
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

    /** Telefone no "ver quais" dos repetidos: DDD e os 4 últimos, o bastante
     *  para achar o contato sem expor o número inteiro na tela. */
    public static function mascararMeio(string $tel): string
    {
        if (strlen($tel) < 8) return $tel === '' ? '' : '•••';
        return '(' . substr($tel, 0, 2) . ') •••••-' . substr($tel, -4);
    }

    /**
     * Resumo no modo demonstração. A observação é texto livre do corretor e
     * às vezes traz nome ou telefone, por isso some; mas no lugar vai uma
     * frase que se explica, não "•••" (que ninguém entendeu no vídeo).
     */
    public static function mascararResumo(string $resumo): string
    {
        $p = mb_strpos($resumo, self::ROTULO_OBS, 0, 'UTF-8');
        if ($p === false) return $resumo;
        return mb_substr($resumo, 0, $p, 'UTF-8') . self::ROTULO_OBS . ' (escondida no modo demonstração; na lista real ela vai inteira)';
    }

    /**
     * Texto do selo de status de uma lista. Quando todos os contatos já
     * estavam na campanha, a lista termina como 'enviada' sem criar nada no
     * 3C, e o selo dizia "No 3C": em 30/09 o Jhony viu isso na segunda lista
     * igual e entendeu que ela tinha subido de novo.
     */
    public static function rotuloStatus(array $lista): string
    {
        $st = (string)($lista['status'] ?? '');
        if ($st === 'enviada' && empty($lista['tresc_lista_id'])) return 'Nada subiu: todos repetidos';
        return [
            'montando' => 'Montando', 'pronta' => 'Pronta para aprovar', 'enviando' => 'Subindo no 3C',
            'enviada' => 'No 3C', 'erro' => 'Erro', 'cancelada' => 'Cancelada',
        ][$st] ?? $st;
    }

    /* ---- repetidos -------------------------------------------------
       Os três jeitos de um contato ser repetido. Os textos são a chave
       gravada em l3c_itens.descarte (não mudar: listas antigas guardam
       estes mesmos textos). Moram aqui, e não no Montador, para a
       contagem por tipo ter teste sem banco. */
    public const DUP_PORTAL = 'já subiu por este portal nesta campanha';
    public const DUP_3C     = 'já recebeu ligação nesta campanha (últimos 60 dias)';
    public const DUP_LISTA  = 'telefone repetido na lista';
    /** Rótulo curto de cada tipo no cartão "Repetidos" (o texto longo vai no title). */
    public const TIPOS_REPETIDO = [
        self::DUP_PORTAL => 'Já subiu pelo portal',
        self::DUP_3C     => 'Ligado no 3C (60 dias)',
        self::DUP_LISTA  => 'Repetido nesta lista',
    ];

    /**
     * Separa os repetidos das outras exclusões. Recebe as linhas
     * [descarte, n] do GROUP BY da tela e devolve sempre os três tipos,
     * com 0 quando não há: o Jhony (reunião de 05/10) pediu que o cartão
     * "mostre zerado", porque um número que só aparece quando é maior que
     * zero não deixa saber se a conta foi feita.
     * ['total' => n, 'tipos' => [descarte => n, ...]]
     */
    public static function repetidosPorTipo(array $linhas): array
    {
        $tipos = array_fill_keys(array_keys(self::TIPOS_REPETIDO), 0);
        foreach ($linhas as $l) {
            $d = $l['descarte'] ?? null;
            if ($d !== null && isset($tipos[$d])) $tipos[$d] += (int)($l['n'] ?? 0);
        }
        return ['total' => array_sum($tipos), 'tipos' => $tipos];
    }

    public static function ehRepetido(?string $descarte): bool
    {
        return $descarte !== null && isset(self::TIPOS_REPETIDO[$descarte]);
    }

    /**
     * Nome com número de ordem quando o mesmo nome já existe no portal.
     * Combinado com o Jhony (05/10): duas listas do mesmo período e tipo
     * ficavam com nome idêntico no 3C e não dava para saber qual era qual.
     * A primeira fica sem número (é o padrão das listas feitas à mão e não
     * dá para renomear depois no 3C), a segunda vira " #2", a terceira
     * " #3", logo depois do "]" do período. Nome digitado sem colchetes
     * ganha o número no fim. Conta pelo MAIOR número já usado, não pela
     * quantidade: se a #2 foi cancelada, a próxima é #3, nunca outra #2.
     */
    public static function nomeComNumero(string $base, array $existentes): string
    {
        $base = trim($base);
        [$cab, $resto] = self::partesDoNome($base);
        $maior = 0;
        foreach ($existentes as $e) {
            [$c, $r] = self::partesDoNome(trim((string)$e));
            if ($c !== $cab) continue;
            if ($r === $resto) { $maior = max($maior, 1); continue; }
            // "#2 RESTO" (com colchetes) ou "RESTO #2" (sem colchetes)
            $padrao = $cab !== '' ? '/^#(\d+)(?: (.*))?$/s' : '/^(?:(.*) )?#(\d+)$/s';
            if (!preg_match($padrao, $r, $m)) continue;
            [$num, $sobra] = $cab !== '' ? [(int)$m[1], (string)($m[2] ?? '')] : [(int)$m[2], (string)($m[1] ?? '')];
            if ($sobra === $resto) $maior = max($maior, $num);
        }
        if ($maior === 0) return $base;
        $n = $maior + 1;
        if ($cab === '') return $base . ' #' . $n;
        return $cab . ' #' . $n . ($resto !== '' ? ' ' . $resto : '');
    }

    /** "[15-09 a 25-09] L.A.A X" → ["[15-09 a 25-09]", "L.A.A X"]; sem colchete → ["", nome]. */
    private static function partesDoNome(string $nome): array
    {
        if (preg_match('/^(\[[^\]]*\])\s*(.*)$/s', $nome, $m)) return [$m[1], trim($m[2])];
        return ['', $nome];
    }

    public static function semAcento(string $s): string
    {
        $de   = ['á','à','ã','â','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','õ','ô','ö','ú','ù','û','ü','ç','ñ'];
        $para = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c','n'];
        return str_replace($de, $para, $s);
    }
}
