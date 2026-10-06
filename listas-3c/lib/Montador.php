<?php
/* =====================================================================
   listas-3c/lib/Montador.php: monta e sobe a lista em passos curtos.

   Por que em passos: a Hostinger corta a requisição web em ~60 s e uma
   lista antiga pode pedir dezenas de páginas de 500 ao Robust (cada uma
   leva ~1,5 s, medido). Então cada chamada de avancar() trabalha no
   máximo $orcamento segundos, grava onde parou (l3c_listas.cursor_json)
   e devolve. Quem chama de novo (a tela, a cada segundo, ou o cron do
   hPanel) continua do mesmo ponto. Um GET_LOCK no MySQL impede que a tela
   e o cron trabalhem na mesma lista ao mesmo tempo.

   Passos por tipo de modelo:
     encerrados           atendimentos → motivos → ativos → pessoas → tratar
     agendamento_vencido  atendimentos → agenda → pessoas → tratar
     primeiras_etapas     atendimentos → agenda → pessoas → tratar
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/Tratamento.php';
require_once __DIR__ . '/Modelos.php';
require_once __DIR__ . '/Apis.php';

final class L3cMontador
{
    public const DESCARTE_CORRETOR = 'lead recente de origem do corretor, atendimento ainda aberto';
    public const HEADER_3C = ['identifier', 'phone', 'nome', 'email', 'contactid', 'mensagem', 'origem'];
    private const POR_PAGINA = 500;
    private const IDS_POR_CHAMADA = 80;     // /pessoas?ids= e /andamentos?ids= (per_page=100, ver automacao/README)
    private const CONTATOS_POR_POST = 100;

    private float $inicio;
    private float $orc = 20.0;

    public function __construct(private PDO $pdo, private ?L3cRobust $robust = null, private ?L3cTresC $tresc = null) {}

    public static function passos(string $tipo): array
    {
        if ($tipo === 'encerrados') return ['atendimentos', 'motivos', 'ativos', 'pessoas', 'tratar'];
        return ['atendimentos', 'agenda', 'pessoas', 'tratar'];
    }

    /** Cria a lista (status montando) e devolve o id. */
    public static function criar(PDO $pdo, string $modeloSlug, array $modelo, string $de, string $ate, string $nome, ?int $uid): int
    {
        $pdo->prepare('INSERT INTO l3c_listas (modelo, periodo_de, periodo_ate, nome, parametros, cursor_json, criado_por, progresso_txt)
                       VALUES (?,?,?,?,?,?,?,?)')
            ->execute([$modeloSlug, $de, $ate, $nome, json_encode($modelo, JSON_UNESCAPED_UNICODE),
                       json_encode(['i' => 0, 'page' => 1, 'pages' => null]), $uid, 'Na fila para montar']);
        return (int)$pdo->lastInsertId();
    }

    /**
     * Trabalha na lista por até $orcamento segundos. Devolve o estado para
     * a tela (status, progresso, texto). Nunca lança: erro vira status.
     */
    public function avancar(int $id, float $orcamento = 20.0): array
    {
        $this->inicio = microtime(true);
        $this->orc = $orcamento;
        $lock = 'l3c_lista_' . $id;
        if ((int)$this->pdo->query('SELECT GET_LOCK(' . $this->pdo->quote($lock) . ', 0)')->fetchColumn() !== 1) {
            return $this->estado($id) + ['ocupada' => true];
        }
        try {
            $l = $this->lista($id);
            if ($l['status'] === 'montando') $this->montar($l, $orcamento);
            elseif ($l['status'] === 'enviando') $this->enviar($l, $orcamento);
        } catch (Throwable $e) {
            $this->pdo->prepare("UPDATE l3c_listas SET status='erro', erro=?, progresso_txt=? WHERE id=?")
                ->execute([$e->getMessage(), 'Parou com erro: ' . mb_substr($e->getMessage(), 0, 200), $id]);
        } finally {
            if ($this->robust) $this->pdo->prepare('UPDATE l3c_listas SET chamadas_robust = chamadas_robust + ? WHERE id=?')->execute([$this->robust->chamadas, $id]);
            if ($this->robust) $this->robust->chamadas = 0;
            $this->pdo->query('SELECT RELEASE_LOCK(' . $this->pdo->quote($lock) . ')');
        }
        return $this->estado($id);
    }

    public function estado(int $id): array
    {
        $l = $this->lista($id);
        $st = $this->pdo->prepare('SELECT COUNT(*) total, SUM(descarte IS NULL) entram FROM l3c_itens WHERE lista_id=?');
        $st->execute([$id]);
        $c = $st->fetch();
        return ['id' => $id, 'status' => $l['status'], 'progresso' => (float)$l['progresso'], 'texto' => $l['progresso_txt'],
                'brutos' => (int)$c['total'], 'entram' => (int)$c['entram'], 'enviados' => (int)$l['enviados'],
                'importados' => (int)$l['importados'], 'chamadas' => (int)$l['chamadas_robust'], 'erro' => $l['erro']];
    }

    // ------------------------------------------------------------------
    private function montar(array $l, float $orcamento): void
    {
        $m = json_decode($l['parametros'], true);
        $passos = self::passos($m['tipo']);
        $cur = json_decode($l['cursor_json'], true);
        while (($cur['i'] ?? 0) < count($passos) && !$this->estourou($orcamento)) {
            $passo = $passos[$cur['i']];
            $terminou = $this->{'passo_' . $passo}($l, $m, $cur);
            if ($terminou) { $cur = ['i' => $cur['i'] + 1, 'page' => 1, 'pages' => null]; }
            $frac = ($cur['pages'] ?? 0) ? min(1, ($cur['page'] - 1) / $cur['pages']) : 0;
            $pct = min(99.0, 100 * (($cur['i'] ?? 0) + $frac) / count($passos));
            $txt = $this->textoDoPasso($passos[$cur['i']] ?? 'tratar', $cur);
            $this->pdo->prepare('UPDATE l3c_listas SET cursor_json=?, progresso=?, progresso_txt=? WHERE id=?')
                ->execute([json_encode($cur), $pct, $txt, $l['id']]);
        }
        if (($cur['i'] ?? 0) >= count($passos)) {
            $this->pdo->prepare("UPDATE l3c_listas SET status='pronta', progresso=100, progresso_txt=? WHERE id=?")
                ->execute(['Pronta para revisar e aprovar', $l['id']]);
        }
    }

    private function textoDoPasso(string $p, array $cur): string
    {
        $pag = ($cur['pages'] ?? null) ? ' (página ' . min($cur['page'], $cur['pages']) . ' de ' . $cur['pages'] . ')' : '';
        $t = [
            'atendimentos' => 'Lendo os atendimentos do período no Robust',
            'motivos'      => 'Lendo o motivo de encerramento de cada um',
            'ativos'       => 'Tirando quem já tem outro atendimento ativo',
            'agenda'       => 'Conferindo a data do agendamento na agenda do Robust',
            'pessoas'      => 'Buscando nome, e-mail e telefone',
            'tratar'       => 'Tratando nomes, telefones e resumo',
        ][$p] ?? $p;
        return $t . $pag;
    }

    private function estourou(float $orcamento): bool
    {
        return microtime(true) - $this->inicio > $orcamento;
    }

    /** Lê uma página de uma listagem do Robust e anota o total de páginas no cursor. */
    private function pagina(string $caminho, array $q, array &$cur): array
    {
        $q['per_page'] = self::POR_PAGINA;
        $q['page'] = $cur['page'];
        $j = $this->robust->get($caminho, $q);
        $cur['pages'] = max(1, (int)($j['meta']['pages'] ?? 1));
        $cur['page']++;
        return $j['data'] ?? [];
    }

    // ---- passo 1: atendimentos --------------------------------------
    private function passo_atendimentos(array $l, array $m, array &$cur): bool
    {
        $q = ['jornada' => $m['jornada'] ?? 1];
        if ($m['tipo'] === 'encerrados') { $q['ativo'] = 'false'; $q['criado_de'] = $l['periodo_de']; $q['criado_ate'] = $l['periodo_ate']; }
        elseif ($m['tipo'] === 'agendamento_vencido') { $q['ativo'] = 'true'; $q['stage'] = 2; }   // o período é da DATA do agendamento, vem no passo agenda
        else { $q['ativo'] = 'true'; $q['criado_de'] = $l['periodo_de']; $q['criado_ate'] = $l['periodo_ate']; }

        $etapas = array_map('intval', $m['etapas']);
        $ins = $this->pdo->prepare('INSERT IGNORE INTO l3c_itens (lista_id, atendimento_id, cliente_id, lead_id, stage, origin, atendente, criado_em, encerrado_em, obs, descarte)
                                    VALUES (?,?,?,?,?,?,?,?,?,?,?)');
        do {
            foreach ($this->pagina('/atendimentos', $q, $cur) as $a) {
                if (!in_array((int)$a['stage'], $etapas, true)) continue;   // Proposta/Negociado nunca chegam aqui
                // Regra inteira (caixa "incluir", encerrado liberado, janela de
                // dias) em L3cTratamento::seguraPorCorretor, para ter teste.
                $descarte = L3cTratamento::seguraPorCorretor($a, $m, time()) ? self::DESCARTE_CORRETOR : null;
                $atendente = '';
                foreach ((array)($a['atendentes_detalhes'] ?? []) as $d) if ((int)($d['posicao'] ?? 0) === 1) $atendente = (string)($d['nome'] ?? '');
                $ins->execute([$l['id'], (int)$a['id'], ($a['cliente'] ?? null) ?: null, ($a['lead'] ?? null) ?: null, (int)$a['stage'],
                    mb_substr((string)($a['origin'] ?? ''), 0, 120), mb_substr($atendente, 0, 120), $a['created_at'] ?? null,
                    $a['ended_at'] ?? null, $a['obs'] ?? null, $descarte]);
            }
        } while ($cur['page'] <= $cur['pages'] && !$this->estourou($this->orc));
        return $cur['page'] > $cur['pages'];
    }

    // ---- passo 2a: motivo de encerramento ---------------------------
    // O motivo não vem no atendimento: é a ação do andamento tipo=encerrado
    // ("Atendimento encerrado - Sem Entrada"). Varre os encerramentos entre
    // a primeira e a última data de encerramento da lista, página a página.
    private function passo_motivos(array $l, array $m, array &$cur): bool
    {
        if (!isset($cur['de'])) {
            $st = $this->pdo->prepare('SELECT MIN(LEFT(encerrado_em,10)), MAX(LEFT(encerrado_em,10)) FROM l3c_itens WHERE lista_id=? AND encerrado_em IS NOT NULL');
            $st->execute([$l['id']]);
            [$de, $ate] = $st->fetch(PDO::FETCH_NUM);
            if (!$de) return $this->finalizarMotivos($l, $m);
            $cur['de'] = $de;
            $cur['ate'] = date('Y-m-d', strtotime($ate . ' +1 day'));   // encerramento às 23:59 pode virar andamento de 00:00
        }
        $upd = $this->pdo->prepare('UPDATE l3c_itens SET motivo=?, motivo_em=? WHERE lista_id=? AND atendimento_id=? AND (motivo_em IS NULL OR motivo_em < ?)');
        do {
            foreach ($this->pagina('/andamentos', ['tipo' => 'encerrado', 'criado_de' => $cur['de'], 'criado_ate' => $cur['ate']], $cur) as $a) {
                $mot = mb_substr(L3cTratamento::motivoDaAcao((string)($a['acao'] ?? '')), 0, 190);
                $upd->execute([$mot, $a['created_at'], $l['id'], (int)$a['atendimento_id'], $a['created_at']]);
            }
        } while ($cur['page'] <= $cur['pages'] && !$this->estourou($this->orc));
        return $cur['page'] > $cur['pages'] ? $this->finalizarMotivos($l, $m) : false;
    }

    private function finalizarMotivos(array $l, array $m): bool
    {
        $st = $this->pdo->prepare('SELECT atendimento_id, motivo FROM l3c_itens WHERE lista_id=? AND descarte IS NULL');
        $st->execute([$l['id']]);
        $upd = $this->pdo->prepare('UPDATE l3c_itens SET descarte=? WHERE lista_id=? AND atendimento_id=?');
        $vistos = [];
        foreach ($st->fetchAll() as $r) {
            $d = L3cTratamento::descartePorMotivo($r['motivo'], (array)$m['motivos'], (array)$m['motivos_nunca']);
            if ($d) $upd->execute([$d, $l['id'], $r['atendimento_id']]);
            if ($r['motivo']) $vistos[$r['motivo']] = 1;
        }
        l3c_catalogo_acrescenta($this->pdo, array_keys($vistos));
        return true;
    }

    // ---- passo 2b: outro atendimento ativo ---------------------------
    // Encerrado recuperável mas que já voltou e está sendo atendido (às
    // vezes agendado, em visita) não deve receber ligação do SDR.
    private function passo_ativos(array $l, array $m, array &$cur): bool
    {
        do {
            $clientes = [];
            foreach ($this->pagina('/atendimentos', ['ativo' => 'true', 'jornada' => $m['jornada'] ?? 1], $cur) as $a) if (!empty($a['cliente'])) $clientes[] = (int)$a['cliente'];
            foreach (array_chunk(array_unique($clientes), 500) as $ch) {
                $in = implode(',', array_fill(0, count($ch), '?'));
                $this->pdo->prepare("UPDATE l3c_itens SET descarte='tem outro atendimento ativo' WHERE lista_id=? AND descarte IS NULL AND cliente_id IN ($in)")
                    ->execute(array_merge([$l['id']], $ch));
            }
        } while ($cur['page'] <= $cur['pages'] && !$this->estourou($this->orc));
        return $cur['page'] > $cur['pages'];
    }

    // ---- passo 2c: data do agendamento --------------------------------
    // A agenda do Robust liga o evento ao andamento que o gerou; o
    // andamento diz o atendimento. Guardamos o agendamento MAIS NOVO de cada
    // atendimento: se foi remarcado para o futuro, ainda não venceu.
    private function passo_agenda(array $l, array $m, array &$cur): bool
    {
        if (!isset($cur['de'])) {
            $cur['de'] = $l['periodo_de'];
            $max = date('Y-m-d', strtotime($l['periodo_de'] . ' +365 days'));
            $cur['ate'] = min($max, date('Y-m-d', strtotime('+120 days')));
        }
        $upd = $this->pdo->prepare('UPDATE l3c_itens SET agenda_em=? WHERE lista_id=? AND atendimento_id=? AND (agenda_em IS NULL OR agenda_em < ?)');
        do {
            $porAndamento = [];
            foreach ($this->pagina('/agenda', ['de' => $cur['de'], 'ate' => $cur['ate'], 'origem_crm' => 'true'], $cur) as $ev) {
                if (!empty($ev['andamento_id'])) {
                    $k = (int)$ev['andamento_id'];
                    if (!isset($porAndamento[$k]) || $porAndamento[$k] < $ev['inicio']) $porAndamento[$k] = (string)$ev['inicio'];
                }
            }
            foreach (array_chunk(array_keys($porAndamento), self::IDS_POR_CHAMADA) as $ids) {
                $j = $this->robust->get('/andamentos', ['ids' => implode(',', $ids), 'per_page' => 100]);
                foreach (($j['data'] ?? []) as $a) {
                    $ini = $porAndamento[(int)$a['id']] ?? null;
                    if ($ini && !empty($a['atendimento_id'])) $upd->execute([$ini, $l['id'], (int)$a['atendimento_id'], $ini]);
                }
            }
        } while ($cur['page'] <= $cur['pages'] && !$this->estourou($this->orc));
        if ($cur['page'] <= $cur['pages']) return false;

        // Mesmo fuso das datas do Robust (-03:00), para a comparação de texto ISO valer.
        $agora = (new DateTime('now', new DateTimeZone('America/Sao_Paulo')))->format('c');
        if ($m['tipo'] === 'agendamento_vencido') {
            $fim = $l['periodo_ate'] . 'T23:59:59';
            $this->pdo->prepare("UPDATE l3c_itens SET descarte = CASE
                    WHEN agenda_em IS NULL THEN 'sem agendamento no período'
                    WHEN agenda_em >= ? THEN 'agendamento ainda não passou'
                    WHEN LEFT(agenda_em,19) > ? THEN 'agendamento depois do período'
                    ELSE NULL END
                WHERE lista_id=? AND descarte IS NULL")->execute([$agora, $fim, $l['id']]);
        } else {
            // Primeiras etapas: quem tem agendamento marcado para a frente fica
            // fora ("eu nunca pego o lead que já está agendado"); Agendamento
            // com a data já passada entra ("a não ser que ele esteja em
            // agendamento mas desatualizado"). Vale para qualquer etapa: medido
            // em 28/09, havia atendimento em etapa Atendimento com visita futura.
            $this->pdo->prepare("UPDATE l3c_itens SET descarte='agendamento ainda não passou'
                WHERE lista_id=? AND descarte IS NULL AND agenda_em >= ?")->execute([$l['id'], $agora]);
        }
        return true;
    }

    // ---- passo 3: nome, e-mail e telefone -----------------------------
    private function passo_pessoas(array $l, array $m, array &$cur): bool
    {
        while (!$this->estourou($this->orc)) {
            $st = $this->pdo->prepare('SELECT DISTINCT cliente_id FROM l3c_itens WHERE lista_id=? AND descarte IS NULL AND pessoa_ok=0 AND cliente_id IS NOT NULL LIMIT ' . self::IDS_POR_CHAMADA);
            $st->execute([$l['id']]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!$ids) break;
            // per_page=100 SEMPRE com ids= (o padrão de 30 corta em silêncio, ver automacao/README).
            $j = $this->robust->get('/pessoas', ['ids' => implode(',', $ids), 'per_page' => 100]);
            $upd = $this->pdo->prepare('UPDATE l3c_itens SET nome_bruto=?, email_bruto=?, telefones=?, pessoa_ok=1 WHERE lista_id=? AND cliente_id=?');
            foreach (($j['data'] ?? []) as $p) {
                $tels = [];
                for ($k = 1; $k <= 5; $k++) if (!empty($p["tel_$k"])) $tels[] = (string)$p["tel_$k"];
                $email = '';
                for ($k = 1; $k <= 4; $k++) if (!$email && !empty($p["email_$k"])) $email = (string)$p["email_$k"];
                $upd->execute([mb_substr((string)($p['nome'] ?? ''), 0, 190), mb_substr($email, 0, 190), mb_substr(implode(',', $tels), 0, 190), $l['id'], (int)$p['id']]);
            }
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->pdo->prepare("UPDATE l3c_itens SET pessoa_ok=1 WHERE lista_id=? AND pessoa_ok=0 AND cliente_id IN ($in)")->execute(array_merge([$l['id']], $ids));
        }
        // Sem telefone ou sem nome de verdade no cadastro da pessoa (medido em
        // 28/09: há pessoa com nome "_", "." ou o próprio telefone): tenta o
        // lead que originou o atendimento.
        $precisaLead = "pessoa_ok<>2 AND lead_id IS NOT NULL AND (cliente_id IS NULL OR (pessoa_ok=1 AND (telefones IS NULL OR telefones=''
                        OR nome_bruto IS NULL OR nome_bruto NOT REGEXP '[[:alpha:]]{2}')))";
        while (!$this->estourou($this->orc)) {
            $st = $this->pdo->prepare("SELECT DISTINCT lead_id FROM l3c_itens WHERE lista_id=? AND descarte IS NULL AND $precisaLead LIMIT " . self::IDS_POR_CHAMADA);
            $st->execute([$l['id']]);
            $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
            if (!$ids) break;
            $j = $this->robust->get('/leads', ['ids' => implode(',', $ids), 'per_page' => 100]);
            // Só preenche o que falta: telefone e nome do cadastro da pessoa vencem os do lead.
            $upd = $this->pdo->prepare("UPDATE l3c_itens SET
                nome_bruto = IF(nome_bruto IS NULL OR nome_bruto NOT REGEXP '[[:alpha:]]{2}', ?, nome_bruto),
                email_bruto = COALESCE(NULLIF(email_bruto,''), ?),
                telefones = IF(telefones IS NULL OR telefones='', ?, telefones), pessoa_ok=2 WHERE lista_id=? AND lead_id=?");
            foreach (($j['data'] ?? []) as $ld) $upd->execute([mb_substr((string)($ld['name'] ?? ''), 0, 190), mb_substr((string)($ld['email'] ?? ''), 0, 190), mb_substr((string)($ld['phone'] ?? ''), 0, 190), $l['id'], (int)$ld['id']]);
            $in = implode(',', array_fill(0, count($ids), '?'));
            $this->pdo->prepare("UPDATE l3c_itens SET pessoa_ok=2 WHERE lista_id=? AND lead_id IN ($in)")->execute(array_merge([$l['id']], $ids));
        }
        $st = $this->pdo->prepare("SELECT COUNT(*) FROM l3c_itens WHERE lista_id=? AND descarte IS NULL AND ((pessoa_ok=0 AND cliente_id IS NOT NULL) OR ($precisaLead))");
        $st->execute([$l['id']]);
        return (int)$st->fetchColumn() === 0;
    }

    // ---- passo 4: tratar (sem rede) ----------------------------------
    private function passo_tratar(array $l, array $m, array &$cur): bool
    {
        $bloq = [];
        if (function_exists('blocklist_ativa') && blocklist_ativa('listas-3c')) $bloq = blocklist_set();
        $st = $this->pdo->prepare('SELECT * FROM l3c_itens WHERE lista_id=? AND descarte IS NULL ORDER BY criado_em DESC');
        $st->execute([$l['id']]);
        $upd = $this->pdo->prepare('UPDATE l3c_itens SET nome=?, email=?, telefone=?, canal=?, resumo=?, descarte=? WHERE lista_id=? AND atendimento_id=?');
        $jaVisto = [];
        $this->pdo->beginTransaction();
        foreach ($st->fetchAll() as $it) {
            $tel = null;
            foreach (explode(',', (string)$it['telefones']) as $t) if ($tel = L3cTratamento::telefone($t)) break;
            $email = L3cTratamento::email($it['email_bruto']);
            $descarte = null;
            if (!$tel) $descarte = 'sem telefone válido';
            elseif ($bloq && function_exists('fone_bloqueado') && fone_bloqueado($tel, $bloq)) $descarte = 'na lista de bloqueio do portal';
            elseif (isset($jaVisto[$tel])) $descarte = L3cTratamento::DUP_LISTA;
            if ($tel) $jaVisto[$tel] = 1;
            $upd->execute([
                mb_substr(L3cTratamento::nomeLimpo($it['nome_bruto'], $email), 0, 160), $email, (string)$tel,
                mb_substr(L3cTratamento::canal($it['origin']), 0, 120), L3cTratamento::resumo($it), $descarte, $l['id'], $it['atendimento_id'],
            ]);
        }
        $this->pdo->commit();
        return true;
    }

    // ---- envio para o 3C ---------------------------------------------
    private function enviar(array $l, float $orcamento): void
    {
        $id = (int)$l['id'];
        $campanha = (int)$l['campanha_id'];
        L3cTresC::exigePermitida($campanha);
        // Uma subida por campanha de cada vez. Duas listas iguais aprovadas na
        // mesma campanha (duas abas, ou a tela e o cron) conferiam ao mesmo
        // tempo, cada uma sem ver a outra, e as duas subiam: defeito do teste
        // do Jhony com o Guilherme em 30/09. Com a trava, a segunda só confere
        // depois que a primeira terminou o passo dela.
        $trava = 'l3c_campanha_' . $campanha;
        if ((int)$this->pdo->query('SELECT GET_LOCK(' . $this->pdo->quote($trava) . ', 0)')->fetchColumn() !== 1) {
            $this->pdo->prepare('UPDATE l3c_listas SET progresso_txt=? WHERE id=?')
                // Texto curto: a tela mostra numa linha (regra do João, 05/10).
                ->execute(['Na fila: outra lista subindo nesta campanha', $id]);
            return;
        }
        try {
            $this->enviarComTrava($l, $orcamento);
        } finally {
            $this->pdo->query('SELECT RELEASE_LOCK(' . $this->pdo->quote($trava) . ')');
        }
    }

    private function enviarComTrava(array $l, float $orcamento): void
    {
        $id = (int)$l['id'];
        $campanha = (int)$l['campanha_id'];
        // A conferência pode ter terminado num passo anterior (a tela trabalha
        // ~8 s por vez) e outra lista igual ter subido no intervalo: sem isto,
        // esta subia os mesmos contatos de novo (dup_ok não confere outra vez).
        // Custa uma consulta ao banco, sem chamar o 3C.
        $this->marcarJaSubidos($id, $campanha);
        // Antes de criar a lista no 3C: tira quem já está na campanha de
        // destino. Pedido do Jhony (29/09): na Campanha Padrão as listas são
        // feitas à mão e é lá que dá para conferir duplicata. Só roda uma vez
        // por lista (dup_ok no cursor) e nunca depois que a lista do 3C existe.
        if (!$l['tresc_lista_id']) {
            $cur = json_decode((string)$l['cursor_json'], true) ?: [];
            if (empty($cur['dup_ok']) && !$this->conferirDuplicatas($l, $cur, $orcamento)) return;
            $dups = $this->contarDuplicatas($id);
            $sobra = (int)$this->pdo->query("SELECT COUNT(*) FROM l3c_itens WHERE lista_id=$id AND descarte IS NULL")->fetchColumn();
            if ($sobra === 0) {
                // Lista vazia no 3C só confunde quem opera a campanha: não cria.
                $this->pdo->prepare("UPDATE l3c_listas SET status='enviada', progresso=100, progresso_txt=? WHERE id=?")
                    ->execute(["Nada subiu: $dups repetidos, nenhuma lista criada no 3C", $id]);
                return;
            }
        }
        if (!$l['tresc_lista_id']) {
            // Grava o id da lista NA HORA: o 3C não deduplica por nome, então
            // criar de novo depois de uma queda faria uma segunda lista.
            $listaId = $this->tresc->criarLista($campanha, $l['nome']);
            $this->pdo->prepare('UPDATE l3c_listas SET tresc_lista_id=? WHERE id=?')->execute([$listaId, $id]);
            $l['tresc_lista_id'] = $listaId;
        }
        $total = (int)$this->pdo->query("SELECT COUNT(*) FROM l3c_itens WHERE lista_id=$id AND descarte IS NULL")->fetchColumn();
        while (!$this->estourou($orcamento)) {
            $st = $this->pdo->prepare('SELECT * FROM l3c_itens WHERE lista_id=? AND descarte IS NULL AND enviado=0 ORDER BY atendimento_id LIMIT ' . self::CONTATOS_POR_POST);
            $st->execute([$id]);
            $lote = $st->fetchAll();
            if (!$lote) break;
            $ids = array_map(fn($r) => (int)$r['atendimento_id'], $lote);
            $in = implode(',', $ids);
            // "Em voo" antes do POST: se a requisição morrer no meio, esse lote
            // não é reenviado sozinho (evita contato duplicado na lista do 3C);
            // aparece como incerto na tela.
            $this->pdo->exec("UPDATE l3c_itens SET enviado=2 WHERE lista_id=$id AND atendimento_id IN ($in)");
            $linhas = array_map(fn($r) => [
                'identifier' => mb_substr('Robust: ' . $r['nome'] . ' | ' . $r['canal'] . ' | atd ' . $r['atendimento_id'], 0, 190),
                'phone'      => $r['telefone'],
                'nome'       => $r['nome'],
                'email'      => $r['email'],
                'contactid'  => 'robust-atd-' . $r['atendimento_id'],
                'mensagem'   => $r['resumo'],
                'origem'     => $r['canal'],
            ], $lote);
            $imp = $this->tresc->subirMailing($campanha, (int)$l['tresc_lista_id'], self::HEADER_3C, $linhas);
            $this->pdo->exec("UPDATE l3c_itens SET enviado=1 WHERE lista_id=$id AND atendimento_id IN ($in)");
            $this->pdo->prepare('UPDATE l3c_listas SET enviados=enviados+?, importados=importados+? WHERE id=?')->execute([count($lote), $imp, $id]);
            $env = (int)$this->pdo->query("SELECT enviados FROM l3c_listas WHERE id=$id")->fetchColumn();
            $this->pdo->prepare('UPDATE l3c_listas SET progresso=?, progresso_txt=? WHERE id=?')
                ->execute([min(99, 100 * $env / max(1, $total)), "Subindo no 3C: $env de $total", $id]);
        }
        $falta = (int)$this->pdo->query("SELECT COUNT(*) FROM l3c_itens WHERE lista_id=$id AND descarte IS NULL AND enviado=0")->fetchColumn();
        if ($falta === 0) {
            $incertos = (int)$this->pdo->query("SELECT COUNT(*) FROM l3c_itens WHERE lista_id=$id AND enviado=2")->fetchColumn();
            $l2 = $this->lista($id);
            // Os repetidos saíram desta frase: agora têm cartão próprio na tela,
            // sempre visível e com zero (pedido do Jhony, 05/10).
            $txt = 'No 3C: ' . $l2['importados'] . ' de ' . $l2['enviados'] . ' importados (lista ' . $l2['tresc_lista_id'] . ')'
                 . ($incertos ? "; $incertos incertos, confira no 3C" : '');
            $this->pdo->prepare("UPDATE l3c_listas SET status='enviada', progresso=100, progresso_txt=? WHERE id=?")->execute([$txt, $id]);
        }
    }

    // ---- duplicatas na campanha de destino -----------------------------
    public const DESCARTE_DUP_PORTAL = L3cTratamento::DUP_PORTAL;
    public const DESCARTE_DUP_3C     = L3cTratamento::DUP_3C;
    private const DUP_POR_CONSULTA = 25;
    // O 3C aceita no máximo 31 dias por consulta de ligações; duas janelas
    // de 30 dias cobrem os últimos 60 (as listas à mão da Campanha Padrão
    // mais antigas são de 25/09/2026, medido em 29/09).
    private const DUP_JANELAS = [[60, 31], [30, 0]];

    /**
     * Duas fontes, porque nenhuma sozinha enxerga tudo:
     *  1. o histórico do portal: telefone que este portal já subiu na mesma
     *     campanha (pega também quem ainda não foi discado);
     *  2. o 3C: telefone que já recebeu ligação nessa campanha (pega as
     *     listas feitas à mão). Ver L3cTresC::numerosJaLigados.
     * Quem ficou numa lista à mão e ainda não foi discado não aparece em
     * nenhuma das duas: a API do 3C não mostra o conteúdo de lista.
     * Trabalha por lotes e grava onde parou, como o montador.
     */
    private function conferirDuplicatas(array $l, array $cur, float $orcamento): bool
    {
        $id = (int)$l['id'];
        $campanha = (int)$l['campanha_id'];
        $ok = $this->conferirEm($id, $campanha, $cur, $orcamento, function (array $cur, int $feitos, int $total) use ($l, $id, $campanha) {
            $this->pdo->prepare('UPDATE l3c_listas SET cursor_json=?, progresso=?, progresso_txt=? WHERE id=?')
                ->execute([json_encode($cur), min(99, 100 * $feitos / max(1, $total)),
                           'Conferindo quem já está na campanha ' . ($l['campanha_nome'] ?? $campanha) . ": $feitos de $total", $id]);
        });
        $this->pdo->prepare('UPDATE l3c_listas SET cursor_json=? WHERE id=?')->execute([json_encode($cur), $id]);
        return $ok;
    }

    /**
     * O miolo da conferência, numa campanha: histórico do portal primeiro
     * (banco), depois as ligações no 3C, em lotes, guardando em $cur onde
     * parou. Serve ao envio (campanha aprovada) e à prévia (campanha que está
     * no seletor). $aoAndar recebe ($cur, feitos, total) a cada lote.
     */
    private function conferirEm(int $id, int $campanha, array &$cur, float $orcamento, callable $aoAndar): bool
    {
        if (empty($cur['dup_hist'])) {
            $this->marcarJaSubidos($id, $campanha);
            $cur['dup_hist'] = 1;
            $cur['dup'] = 0;
            $cur['dup_n'] = 0;
            // Guardado uma vez: o total muda à medida que as duplicatas saem.
            $cur['dup_total'] = (int)$this->pdo->query("SELECT COUNT(*) FROM l3c_itens WHERE lista_id=$id AND descarte IS NULL AND enviado=0")->fetchColumn();
        }
        $total = (int)($cur['dup_total'] ?? 0);
        $upd = $this->pdo->prepare('UPDATE l3c_itens SET descarte=? WHERE lista_id=? AND telefone=? AND descarte IS NULL AND enviado=0');
        while (!$this->estourou($orcamento)) {
            $st = $this->pdo->prepare('SELECT atendimento_id, telefone FROM l3c_itens WHERE lista_id=? AND descarte IS NULL AND enviado=0 AND atendimento_id > ?
                                       ORDER BY atendimento_id LIMIT ' . self::DUP_POR_CONSULTA);
            $st->execute([$id, (int)($cur['dup'] ?? 0)]);
            $lote = $st->fetchAll();
            if (!$lote) { $cur['dup_ok'] = 1; break; }
            $porNumero = [];
            foreach ($lote as $r) $porNumero[L3cTratamento::telefone3c((string)$r['telefone'])] = (string)$r['telefone'];
            $achados = [];
            foreach (self::DUP_JANELAS as [$desde, $ate]) {
                $faltam = array_diff(array_keys($porNumero), $achados);
                if (!$faltam) break;
                $achados = array_merge($achados, $this->tresc->numerosJaLigados($campanha,
                    array_values($faltam), date('Y-m-d', strtotime("-$desde days")), date('Y-m-d', strtotime("-$ate days"))));
            }
            foreach (array_unique($achados) as $n) if (isset($porNumero[$n])) $upd->execute([self::DESCARTE_DUP_3C, $id, $porNumero[$n]]);
            // De onde veio cada repetido do 3C (nome da lista e data da última
            // ligação), para o "ver quais" do cartão Repetidos. A API não dá
            // isso em outra rota; vem de graça na mesma consulta de ligações.
            if (self::temColunaRepetido($this->pdo) && $this->tresc->ultimaLigacao) {
                $ref = $this->pdo->prepare('UPDATE l3c_itens SET repetido_lista=?, repetido_em=? WHERE lista_id=? AND telefone=? AND descarte=?');
                foreach (array_unique($achados) as $n) {
                    $u = $this->tresc->ultimaLigacao[$n] ?? null;
                    if ($u && isset($porNumero[$n])) $ref->execute([mb_substr($u['lista'], 0, 160), $u['em'], $id, $porNumero[$n], self::DESCARTE_DUP_3C]);
                }
            }
            $cur['dup'] = (int)end($lote)['atendimento_id'];
            $cur['dup_n'] = (int)($cur['dup_n'] ?? 0) + count($lote);
            $aoAndar($cur, $cur['dup_n'], $total);
        }
        return !empty($cur['dup_ok']);
    }

    /**
     * Prévia que já desconta os repetidos da campanha que está no seletor.
     * Pedido do Jhony (30/09, 21:48): a prévia dizia "197 entram" e só um
     * aviso falava dos 97 repetidos; "se eu subir 2 listas iguais tem que
     * ficar 0 ali". Agora a lista pronta passa pela mesma conferência do
     * envio, para a campanha do seletor: os repetidos saem do "entram" e
     * aparecem em "Quem ficou de fora e por quê". Trocou a campanha, os
     * repetidos da anterior voltam e a conta é refeita. O aprovar continua
     * conferindo de novo (segunda trava). Em passos curtos, como o resto.
     * Devolve ['pronto' => bool, 'feitos' => n, 'total' => n].
     */
    public function preconferir(int $id, int $campanha, float $orcamento = 8.0): array
    {
        $this->inicio = microtime(true);
        $this->orc = $orcamento;
        $lock = 'l3c_lista_' . $id;
        if ((int)$this->pdo->query('SELECT GET_LOCK(' . $this->pdo->quote($lock) . ', 0)')->fetchColumn() !== 1) {
            return ['pronto' => false, 'feitos' => 0, 'total' => 0];
        }
        try {
            $l = $this->lista($id);
            if ($l['status'] !== 'pronta') return ['pronto' => true, 'feitos' => 0, 'total' => 0];
            $cursor = json_decode((string)$l['cursor_json'], true) ?: [];
            $prev = (array)($cursor['previa'] ?? []);
            if ((int)($prev['campanha'] ?? 0) !== $campanha) {
                self::desfazerPrevia($this->pdo, $id);
                $prev = ['campanha' => $campanha];
            }
            if (empty($prev['dup_ok'])) {
                $salvar = function (array $p) use ($id, &$cursor) {
                    $cursor['previa'] = $p;
                    $this->pdo->prepare('UPDATE l3c_listas SET cursor_json=? WHERE id=?')->execute([json_encode($cursor), $id]);
                };
                $this->conferirEm($id, $campanha, $prev, $orcamento, fn(array $p) => $salvar($p));
                $salvar($prev);
            }
            return ['pronto' => !empty($prev['dup_ok']), 'feitos' => (int)($prev['dup_n'] ?? 0), 'total' => (int)($prev['dup_total'] ?? 0)];
        } finally {
            $this->pdo->query('SELECT RELEASE_LOCK(' . $this->pdo->quote($lock) . ')');
        }
    }

    /**
     * Antes de aprovar: se a prévia foi de outra campanha, os repetidos dela
     * voltam (a conferência do envio refaz para a campanha certa); se foi
     * desta e não sobrou ninguém, recusa: "se eu subir 2 listas iguais tem que
     * ficar 0 ali" (Jhony, 30/09). Devolve a mensagem de recusa ou null.
     */
    public static function antesDeAprovar(PDO $pdo, int $id, int $campanha): ?string
    {
        $st = $pdo->prepare('SELECT * FROM l3c_listas WHERE id=?'); $st->execute([$id]);
        $l = $st->fetch();
        if (!$l || $l['status'] !== 'pronta') return null;   // o UPDATE do aprovar já recusa o resto
        if (self::campanhaDaPrevia($l) !== $campanha) { self::desfazerPrevia($pdo, $id); return null; }
        $st = $pdo->prepare('SELECT COUNT(*) FROM l3c_itens WHERE lista_id=? AND descarte IS NULL'); $st->execute([$id]);
        return (int)$st->fetchColumn() === 0 ? 'Nada para subir nesta campanha: todos estes contatos já estão nela.' : null;
    }

    /** Campanha em que a prévia já foi conferida por inteiro, ou null. */
    public static function campanhaDaPrevia(array $lista): ?int
    {
        $p = (array)((json_decode((string)($lista['cursor_json'] ?? ''), true) ?: [])['previa'] ?? []);
        return !empty($p['dup_ok']) ? (int)$p['campanha'] : null;
    }

    /** Os repetidos de uma campanha voltam para a lista (trocou a campanha no seletor ou no aprovar). */
    public static function desfazerPrevia(PDO $pdo, int $id): void
    {
        $limpa = self::temColunaRepetido($pdo) ? ', repetido_lista=NULL, repetido_em=NULL' : '';
        $pdo->prepare("UPDATE l3c_itens SET descarte=NULL$limpa WHERE lista_id=? AND enviado=0 AND descarte IN (?,?)")
            ->execute([$id, self::DESCARTE_DUP_PORTAL, self::DESCARTE_DUP_3C]);
    }

    /**
     * As colunas repetido_lista/repetido_em nascem na migração 2
     * (schema.php), que só roda quando um admin abre o portal ou no cron.
     * Até lá, um usuário comum pode montar e aprovar lista: sem esta
     * checagem o UPDATE com coluna inexistente derrubava a conferência.
     * Sem a coluna, o "ver quais" só não mostra a lista de origem do 3C.
     */
    public static function temColunaRepetido(PDO $pdo): bool
    {
        static $tem = null;
        return $tem ??= (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE()
                                          AND TABLE_NAME = 'l3c_itens' AND COLUMN_NAME = 'repetido_lista'")->fetchColumn() > 0;
    }

    /**
     * Os repetidos da lista, um por linha, para o "ver quais": telefone,
     * tipo, lista de origem e data. Origem por tipo:
     *  - já subiu pelo portal: a lista do portal mais recente que subiu o
     *    mesmo telefone nesta campanha, e quando foi aprovada;
     *  - ligado no 3C: o nome da lista do 3C e a data da última ligação,
     *    gravados na conferência;
     *  - repetido nesta lista: o próprio nome desta lista e a data de
     *    cadastro do atendimento que ficou de fora.
     */
    public static function repetidosDaLista(PDO $pdo, array $lista, ?int $campanha, int $limite = 500): array
    {
        $id = (int)$lista['id'];
        $ref = self::temColunaRepetido($pdo) ? 'i.repetido_lista, i.repetido_em' : 'NULL repetido_lista, NULL repetido_em';
        $st = $pdo->prepare("SELECT i.atendimento_id, i.telefone, i.descarte, i.criado_em, $ref,
                (SELECT CONCAT(y.nome, '\t', COALESCE(y.aprovado_em, y.criado_em)) FROM l3c_itens x JOIN l3c_listas y ON y.id = x.lista_id
                  WHERE x.telefone = i.telefone AND x.lista_id <> i.lista_id AND x.enviado IN (1,2) AND y.campanha_id = ?
                  ORDER BY y.id DESC LIMIT 1) portal_ref
            FROM l3c_itens i WHERE i.lista_id = ? AND i.descarte IN (?,?,?) ORDER BY i.descarte, i.telefone LIMIT " . (int)$limite);
        $st->execute([(int)$campanha, $id, L3cTratamento::DUP_PORTAL, L3cTratamento::DUP_3C, L3cTratamento::DUP_LISTA]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $origem = ''; $em = '';
            if ($r['descarte'] === L3cTratamento::DUP_PORTAL && $r['portal_ref']) [$origem, $em] = explode("\t", $r['portal_ref'], 2) + ['', ''];
            elseif ($r['descarte'] === L3cTratamento::DUP_3C) { $origem = (string)$r['repetido_lista']; $em = (string)$r['repetido_em']; }
            elseif ($r['descarte'] === L3cTratamento::DUP_LISTA) { $origem = (string)$lista['nome']; $em = (string)$r['criado_em']; }
            $out[] = ['telefone' => (string)$r['telefone'], 'tipo' => $r['descarte'], 'origem' => $origem, 'em' => $em];
        }
        return $out;
    }

    /**
     * Prévia da conferência, para a lista ainda pronta: dos contatos que
     * entram, quantos este portal já subiu, por campanha. Não descarta nada
     * (a campanha só é escolhida ao aprovar, e é lá que a conferência vale),
     * só avisa. Mesma fonte da conferência: l3c_itens com enviado 1 ou 2 de
     * outra lista, que é o banco do portal no servidor, o mesmo para todo
     * computador que abre o portal.
     */
    public static function jaSubiramPeloPortal(PDO $pdo, int $id): array
    {
        $st = $pdo->prepare("SELECT y.campanha_id, MAX(y.campanha_nome) campanha_nome, COUNT(DISTINCT i.telefone) n
            FROM l3c_itens i
            JOIN l3c_itens x ON x.telefone = i.telefone AND x.lista_id <> i.lista_id AND x.enviado IN (1,2)
            JOIN l3c_listas y ON y.id = x.lista_id AND y.campanha_id IS NOT NULL
            WHERE i.lista_id = ? AND i.descarte IS NULL AND i.telefone <> ''
            GROUP BY y.campanha_id ORDER BY n DESC");
        $st->execute([$id]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Tira da lista (ainda não enviada) quem este portal já subiu nesta campanha por outra lista. */
    private function marcarJaSubidos(int $id, int $campanha): void
    {
        $this->pdo->prepare("UPDATE l3c_itens i JOIN (
                SELECT DISTINCT x.telefone FROM l3c_itens x JOIN l3c_listas y ON y.id = x.lista_id
                WHERE y.campanha_id = ? AND y.id <> ? AND x.enviado IN (1,2) AND x.telefone <> '') h ON h.telefone = i.telefone
            SET i.descarte = ? WHERE i.lista_id = ? AND i.descarte IS NULL AND i.enviado = 0")
            ->execute([$campanha, $id, self::DESCARTE_DUP_PORTAL, $id]);
    }

    private function contarDuplicatas(int $id): int
    {
        $st = $this->pdo->prepare('SELECT COUNT(*) FROM l3c_itens WHERE lista_id=? AND descarte IN (?,?)');
        $st->execute([$id, self::DESCARTE_DUP_PORTAL, self::DESCARTE_DUP_3C]);
        return (int)$st->fetchColumn();
    }

    private function lista(int $id): array
    {
        $st = $this->pdo->prepare('SELECT * FROM l3c_listas WHERE id=?');
        $st->execute([$id]);
        $l = $st->fetch();
        if (!$l) throw new RuntimeException("Lista $id não existe.");
        return $l;
    }
}

/** Motivo novo visto no Robust entra no catálogo da tela de modelos. */
function l3c_catalogo_acrescenta(PDO $pdo, array $motivos): void
{
    if (!$motivos) return;
    $st = $pdo->query("SELECT valor FROM l3c_config WHERE chave='catalogo'");
    $atual = json_decode((string)($st->fetchColumn() ?: '[]'), true) ?: [];
    $conhecidos = [];
    foreach (array_merge(L3cModelos::CATALOGO, $atual) as $x) $conhecidos[L3cTratamento::chaveMotivo($x)] = 1;
    $novos = array_values(array_filter($motivos, fn($x) => !isset($conhecidos[L3cTratamento::chaveMotivo($x)])));
    if (!$novos) return;
    $pdo->prepare("INSERT INTO l3c_config (chave, valor) VALUES ('catalogo', ?) ON DUPLICATE KEY UPDATE valor=VALUES(valor)")
        ->execute([json_encode(array_values(array_merge($atual, $novos)), JSON_UNESCAPED_UNICODE)]);
}
