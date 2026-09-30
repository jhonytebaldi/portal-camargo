<?php
/* =====================================================================
   listas-3c/lib/Modelos.php: os 4 modelos de lista e seus padrões.

   Tirados do que o Jhony marcou na tela do Robust na reunião de 24/09
   (período do cadastro, etapas, e motivo por motivo de encerramento).
   Ele disse "depende" várias vezes, então NADA aqui é fixo: a tela
   listas-3c/modelos.php grava por cima destes padrões na tabela
   l3c_config (chave 'modelos'), e o que estiver lá vence.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/Tratamento.php';

final class L3cModelos
{
    /** Motivos que ele marcou "sim" para lista recente. */
    public const RECUPERAVEIS_RECENTE = [
        'Agendou, não veio e não responde/atende',
        'Bloqueou Ligações pela operadora e no Whatsapp',
        'Lead inativo de corretor que saiu da imob',
        'Não atende mais - Parou de responder',
        'Sem retorno após todas as tentativas',
    ];

    /** Motivos que ele marcou "só se a lista for antiga". */
    public const SO_LISTA_ANTIGA = [
        'Cliente curioso/sem interesse de compra',
        'Cliente optou por esperar mais',
        'Depende de outra negociação para comprar',
        'Restrição no Nome',
        'Sem Entrada',
        'Cliente desistiu de comprar',
    ];

    /** Motivos que ele marcou "não" (nunca voltam para o 3C, em modelo nenhum). */
    public const NUNCA = [
        'Cliente duplicado',
        'Cliente sem condição de compra',
        'Comprador sem condição de compra',
        'Comprou carta de crédito',
        'Comprou com outra imobiliária',
        'Corretor querendo parceria',
        'Crédito não aprovou',
        'Já sendo atendido ou comprou com outro corretor da Camargo',
        'Comprou com outro corretor da Camargo',
        'Não Comprador – Busca para Parente/Amigo',
        'Número incorreto',
        'Número não existe',
        'O número não existe',
        'Sem Renda',
        'Tem Financiamento Ativo',
    ];

    /**
     * Todos os motivos de encerramento que existem no Robust da Camargo,
     * medidos em 50.704 encerramentos (andamentos tipo=encerrado desde
     * 2025). Serve só para a tela de edição mostrar as caixas; um motivo
     * novo que aparecer ao montar uma lista entra no catálogo sozinho.
     */
    public const CATALOGO = [
        'Sem retorno após todas as tentativas', 'Desatualizado além do permitido!',
        'Não atende mais - Parou de responder', 'Encerrar vários', 'Outros',
        'Cliente curioso/sem interesse de compra', 'Cliente não quer agendar',
        'Cliente optou por esperar mais', 'Cliente sem condição de compra',
        'Cliente busca imóvel em outra cidade', 'Cliente desistiu de comprar', 'Cliente desistiu',
        'Sem Entrada', 'Comprou com outra imobiliária', 'Agendou, não veio e não responde/atende',
        'Sem Renda', 'Número incorreto', 'Restrição no Nome',
        'Já sendo atendido ou comprou com outro corretor da Camargo', 'Número não existe',
        'Depende de outra negociação para comprar', 'Cliente não chegou no valor e condições',
        'Imóvel para troca/venda', 'Buscando aluguel', 'Não Comprador – Busca para Parente/Amigo',
        'Bloqueou Ligações pela operadora e no Whatsapp', 'Encontrou o imóvel em outro local',
        'Cliente duplicado', 'Lead inativo de corretor que saiu da imob', 'Tem Financiamento Ativo',
        'Comprador sem condição de compra', 'O número não existe',
        'Buscando Terreno/Galpão/Chácara/Sala Comercial/etc', 'Corretor querendo parceria',
        'Crédito não aprovou', 'Vendedor desistiu de vender', 'Comprou carta de crédito',
        'Imóvel fora da área de atuação', 'Comprou com outro corretor da Camargo',
        'Proprietário desistiu', 'O e-mail não existe', 'Não é cliente',
        'Imóvel sem condições de negociação', 'Proprietário deixou em exclusividade em outra imobiliária',
        'Imóvel sem comprovação de regularidade', 'Locador desistiu de alugar',
    ];

    /**
     * Origens (atendimento.origin no Robust) que são do corretor. Lead
     * recente dessas origens fica fora: "senão daqui a pouco esse cara não
     * cadastra mais a campanha dele dentro do meu sistema".
     */
    public const ORIGENS_CORRETOR = ['do corretor'];

    /** Padrões. 'tipo' define quais passos o montador roda. */
    public static function padroes(): array
    {
        $base = [
            'jornada' => 1,
            // Dias em que um lead de origem do corretor ainda é "recente".
            'dias_origem_corretor' => 30,
            'origens_corretor' => self::ORIGENS_CORRETOR,
            'motivos_nunca' => self::NUNCA,
        ];
        return [
            'encerrados_recentes' => $base + [
                'nome' => 'Encerrados recuperáveis (lista recente)',
                'descricao' => 'Cadastrados no período e encerrados por um motivo que vale ligar de novo logo.',
                'tipo' => 'encerrados',
                // Etapa em que o atendimento estava ao encerrar. Visita fica
                // fora na lista recente: "se o cara veio pra loja e foi
                // encerrado, naquele momento não tinha potencial".
                'etapas' => [0, 1, 2],
                'motivos' => self::RECUPERAVEIS_RECENTE,
            ],
            'encerrados_antigos' => $base + [
                'nome' => 'Encerrados recuperáveis (lista antiga)',
                'descricao' => 'Os da lista recente e mais os motivos que só valem depois de um tempo.',
                'tipo' => 'encerrados',
                // "Agora se é uma lista mais antiga, aí eu boto visita junto."
                'etapas' => [0, 1, 2, 3],
                'motivos' => array_merge(self::RECUPERAVEIS_RECENTE, self::SO_LISTA_ANTIGA),
            ],
            'agendamento_vencido' => $base + [
                'nome' => 'Agendamento vencido',
                'descricao' => 'Ainda em Agendamento no Robust, a data do agendamento caiu no período, já passou e o cliente não veio.',
                'tipo' => 'agendamento_vencido',
                'etapas' => [2],
                'motivos' => [],
            ],
            'primeiras_etapas' => $base + [
                'nome' => 'Primeiras etapas',
                'descricao' => 'Ativos em Lead, Atendimento ou Agendamento (este só se a data já passou). Nunca Visita, Proposta ou Negociado.',
                'tipo' => 'primeiras_etapas',
                'etapas' => [0, 1, 2],
                'motivos' => [],
            ],
        ];
    }

    /**
     * Caixas de motivo de um modelo na tela de edição.
     * Antes, motivo marcado em "Nunca entram" sumia do modelo, e o Jhony
     * (30/09) procurou "Crédito não aprovou" e "Sem Renda" na lista antiga e
     * não achou. Agora ele aparece travado, com o aviso de onde destravar.
     * A regra não muda: "Nunca entram" continua vencendo o modelo
     * (L3cTratamento::descartePorMotivo).
     * Devolve [['motivo' => ..., 'marcado' => bool, 'travado' => bool], ...].
     */
    public static function caixasDoModelo(array $catalogo, array $motivosDoModelo, array $motivosNunca): array
    {
        $ch = fn(array $l) => array_map(fn($x) => L3cTratamento::chaveMotivo((string)$x), $l);
        $doModelo = $ch($motivosDoModelo);
        $nunca = $ch($motivosNunca);
        $out = [];
        foreach ($catalogo as $mot) {
            $k = L3cTratamento::chaveMotivo((string)$mot);
            $out[] = ['motivo' => (string)$mot, 'marcado' => in_array($k, $doModelo, true), 'travado' => in_array($k, $nunca, true)];
        }
        return $out;
    }

    /**
     * Motivos do modelo ao salvar. Caixa travada (desabilitada) não vai no
     * formulário, então sem isto salvar a tela apagaria a marcação que o
     * modelo já tinha para um motivo em "Nunca entram". Guarda a marcação
     * antiga dos travados; o resto é o que veio marcado.
     */
    public static function motivosAoSalvar(array $postados, array $antes, array $motivosNunca, array $catalogo): array
    {
        $nunca = array_map(fn($x) => L3cTratamento::chaveMotivo((string)$x), $motivosNunca);
        $guardados = array_filter($antes, fn($x) => in_array(L3cTratamento::chaveMotivo((string)$x), $nunca, true));
        $out = [];
        foreach (array_merge(array_values(array_intersect($catalogo, $postados)), array_values($guardados)) as $m) {
            $out[L3cTratamento::chaveMotivo((string)$m)] = (string)$m;
        }
        return array_values($out);
    }

    /** Padrões + o que foi editado na tela (l3c_config 'modelos'). */
    public static function todos(?array $editado): array
    {
        $out = self::padroes();
        foreach ((array)$editado as $slug => $cfg) {
            if (!isset($out[$slug]) || !is_array($cfg)) continue;
            foreach (['etapas', 'motivos', 'dias_origem_corretor', 'origens_corretor', 'motivos_nunca'] as $k) {
                if (array_key_exists($k, $cfg)) $out[$slug][$k] = $cfg[$k];
            }
        }
        // Proposta (4) e Negociado (5) nunca entram, mesmo que alguém marque.
        foreach ($out as &$m) $m['etapas'] = array_values(array_filter(array_map('intval', (array)$m['etapas']), fn($e) => $e >= 0 && $e <= 3));
        return $out;
    }
}
