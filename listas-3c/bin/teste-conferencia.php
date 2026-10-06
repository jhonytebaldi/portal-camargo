<?php
/* =====================================================================
   listas-3c/bin/teste-conferencia.php: prova, com banco de verdade, a
   conferência de repetidos antes de subir no 3C.

   Por que existe: o Jhony (30/09/2026) montou a mesma lista duas vezes e
   perguntou se o portal barra repetido e se isso é "local" (por
   computador) ou no servidor. A resposta é: no servidor. O histórico é a
   tabela l3c_itens do banco do portal, a mesma para qualquer computador.
   Este teste sobe a lista A, monta a lista B com os mesmos telefones e
   confere que (1) a lista pronta já avisa quantos repetidos tem e (2) ao
   aprovar na mesma campanha eles saem e não sobem de novo; em outra
   campanha, sobem (a conferência é por campanha, como ele pediu em 29/09).

   NÃO fala com o 3C de verdade: sobe um 3C falso (php -S numa porta local)
   que só responde criar lista, subir contatos e ligações vazias.

   Rodar (precisa de um MySQL de TESTE com schema.sql do portal carregado):
     PORTAL_CONFIG=/caminho/config-de-teste.php php listas-3c/bin/teste-conferencia.php
   O config de teste precisa ter L3C_CAMPANHAS_PERMITIDAS = [316173, 999].
   ===================================================================== */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
require_once __DIR__ . '/../../lib/db.php';
require_once __DIR__ . '/../schema.php';
require_once __DIR__ . '/../lib/Montador.php';

$falhas = 0; $n = 0;
function igual($obtido, $esperado, string $nome): void {
    global $falhas, $n; $n++;
    if ($obtido === $esperado) { echo "ok   $nome\n"; return; }
    $falhas++; echo "FALHA $nome\n      esperado: " . var_export($esperado, true) . "\n      obtido:   " . var_export($obtido, true) . "\n";
}

$pdo = db();
if (!str_contains((string)$pdo->query('SELECT DATABASE()')->fetchColumn(), 'teste')) {
    fwrite(STDERR, "Recuso rodar: o banco precisa ter 'teste' no nome (este teste apaga as listas).\n");
    exit(2);
}
l3c_migrar($pdo);
$pdo->exec('DELETE FROM l3c_listas');

// 3C falso: cada lista criada ganha um id; contatos voltam como importados.
$dir = sys_get_temp_dir() . '/l3c-fake-' . getmypid();
@mkdir($dir);
file_put_contents("$dir/r.php", '<?php
header("Content-Type: application/json");
$p = parse_url($_SERVER["REQUEST_URI"], PHP_URL_PATH);
if ($_SERVER["REQUEST_METHOD"] === "POST" && preg_match("#^/campaigns/\d+/lists$#", $p)) { echo json_encode(["data" => ["id" => random_int(100000, 999999)]]); return; }
if ($_SERVER["REQUEST_METHOD"] === "POST" && preg_match("#/mailing$#", $p)) { $j = json_decode(file_get_contents("php://input"), true); echo json_encode(["imported_lines" => count($j["mailing"] ?? [])]); return; }
if ($_SERVER["REQUEST_METHOD"] === "GET" && $p === "/calls") {
    // Um único número "já ligado" numa lista feita à mão, com duas ligações
    // (a mais nova é a que o cartão Repetidos mostra).
    $d = [];
    // Só na campanha 316173: em outra campanha ele não é repetido.
    $c = (int)($_GET["campaigns"][0] ?? 0);
    if ($c === 316173 && in_array("5547988880099", (array)($_GET["numbers"] ?? []), true)) {
        $d[] = ["number" => "5547988880099", "campaign_id" => $c, "list" => "[01-09 a 10-09] L.A.A ENCERRADOS MOTIVOS", "call_date" => "2026-09-20 10:00:00"];
        $d[] = ["number" => "5547988880099", "campaign_id" => $c, "list" => "[11-09 a 20-09] L.A.A ENCERRADOS MOTIVOS", "call_date" => "2026-09-28 15:30:00"];
    }
    echo json_encode(["data" => $d]); return;
}
http_response_code(404); echo "{}";');
$porta = 18000 + getmypid() % 1000;
$proc = proc_open([PHP_BINARY, '-S', "127.0.0.1:$porta", "$dir/r.php"], [1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
for ($i = 0; $i < 50 && !@fsockopen('127.0.0.1', $porta); $i++) usleep(100000);
$tresc = new L3cTresC("http://127.0.0.1:$porta", 'falso');

try {
    $modelo = L3cModelos::padroes()['encerrados_recentes'];
    $nova = function (string $nome, array $tels) use ($pdo, $modelo): int {
        $id = L3cMontador::criar($pdo, 'encerrados_recentes', $modelo, '2026-09-15', '2026-09-25', $nome, null);
        $ins = $pdo->prepare('INSERT INTO l3c_itens (lista_id, atendimento_id, telefone, nome, resumo, canal) VALUES (?,?,?,?,?,?)');
        foreach ($tels as $k => $t) $ins->execute([$id, 1000 + $k, $t, "Contato $k", 'Etapa Lead', 'Site']);
        $pdo->prepare("UPDATE l3c_listas SET status='pronta' WHERE id=?")->execute([$id]);
        return $id;
    };
    // Aprovar = o mesmo UPDATE do acao.php; subir = os passos que a tela ou o cron pedem.
    $marcar = function (int $id, int $campanha) use ($pdo): void {
        $pdo->prepare("UPDATE l3c_listas SET status='enviando', campanha_id=?, campanha_nome=?, cursor_json='{}' WHERE id=?")
            ->execute([$campanha, "Campanha $campanha", $id]);
    };
    $subir = function (int $id, int $vezes = 10) use ($pdo, $tresc): array {
        $m = new L3cMontador($pdo, null, $tresc);
        for ($i = 0; $i < $vezes; $i++) { $e = $m->avancar($id, 20.0); if ($e['status'] !== 'enviando') break; }
        return $e;
    };
    $aprovar = function (int $id, int $campanha) use ($marcar, $subir): array { $marcar($id, $campanha); return $subir($id); };

    $A = $nova('A', ['47988880001', '47988880002', '47988880003']);
    igual(L3cMontador::jaSubiramPeloPortal($pdo, $A), [], 'primeira lista do portal: nada repetido');
    $ea = $aprovar($A, 316173);
    igual([$ea['status'], $ea['enviados'], $ea['importados']], ['enviada', 3, 3], 'primeira lista sobe inteira e fica no histórico do servidor');

    // A mesma lista de novo (o teste do Jhony), mais um telefone novo.
    $B = $nova('B', ['47988880001', '47988880002', '47988880003', '47988880009']);
    $aviso = L3cMontador::jaSubiramPeloPortal($pdo, $B);
    igual([(int)$aviso[0]['campanha_id'], (int)$aviso[0]['n']], [316173, 3], 'segunda igual: a lista pronta já avisa 3 repetidos');
    $eb = $aprovar($B, 316173);
    igual([$eb['status'], $eb['enviados']], ['enviada', 1], 'segunda igual na mesma campanha: só o novo sobe');
    $st = $pdo->prepare('SELECT COUNT(*) FROM l3c_itens WHERE lista_id=? AND descarte=?');
    $st->execute([$B, L3cMontador::DESCARTE_DUP_PORTAL]);
    igual((int)$st->fetchColumn(), 3, 'segunda igual: os 3 repetidos ficam de fora com o motivo na tela');

    // Terceira igual numa campanha diferente: a conferência é por campanha.
    $C = $nova('C', ['47988880001']);
    $ec = $aprovar($C, 999);
    igual([$ec['status'], $ec['enviados']], ['enviada', 1], 'outra campanha: não é repetido lá, sobe');

    // Tudo sem nada subido: a lista vazia nem é criada no 3C.
    $D = $nova('D', ['47988880002']);
    $ed = $aprovar($D, 316173);
    $st = $pdo->prepare('SELECT tresc_lista_id FROM l3c_listas WHERE id=?'); $st->execute([$D]);
    igual([$ed['status'], $st->fetchColumn()], ['enviada', null], 'só repetidos: nenhuma lista vazia é criada no 3C');
    // Defeito de 30/09 (teste do Jhony com o Guilherme), a sequência que
    // deixa passar repetido: a conferência da lista E terminou num passo e a
    // subida ficou para o próximo (a tela trabalha ~8 s por vez; fechar a aba
    // ou a hospedagem cortar deixa assim). Nesse intervalo a lista F, igual,
    // conferiu e subiu. Quando E voltou, não conferia de novo e subia tudo.
    $pdo->exec('DELETE FROM l3c_listas');
    $E = $nova('E', ['47988880011', '47988880012', '47988880013']);
    $F = $nova('F', ['47988880011', '47988880012', '47988880013']);
    $marcar($E, 316173);
    // Estado exato em que E fica depois do passo que só conferiu (nada era repetido ainda).
    $pdo->prepare("UPDATE l3c_listas SET cursor_json=? WHERE id=?")->execute([json_encode(['dup_hist' => 1, 'dup_ok' => 1, 'dup' => 1002, 'dup_n' => 3, 'dup_total' => 3]), $E]);
    $marcar($F, 316173);
    $ef = $subir($F);
    igual([$ef['status'], $ef['enviados']], ['enviada', 3], 'F confere e sobe os 3');
    $ee = $subir($E);
    igual([$ee['status'], $ee['enviados']], ['enviada', 0], 'E volta depois de F e não sobe nenhum repetido');
    $st = $pdo->prepare('SELECT tresc_lista_id FROM l3c_listas WHERE id=?'); $st->execute([$E]);
    igual($st->fetchColumn(), null, 'E não cria lista vazia no 3C');
    $st = $pdo->prepare('SELECT COUNT(*) FROM l3c_itens WHERE lista_id=? AND descarte=?'); $st->execute([$E, L3cMontador::DESCARTE_DUP_PORTAL]);
    igual((int)$st->fetchColumn(), 3, 'E mostra os 3 como já subidos por este portal');

    // Duas subidas na mesma campanha ao mesmo tempo (duas abas, ou a tela e o
    // cron): uma espera a outra, para a conferência e o envio não se cruzarem.
    $outra = new PDO('mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4', DB_USER, DB_PASS);
    $outra->query("SELECT GET_LOCK('l3c_campanha_316173', 0)");
    $G = $nova('G', ['47988880021']); $marcar($G, 316173);
    $eg = $subir($G, 1);
    igual([$eg['status'], $eg['enviados']], ['enviando', 0], 'com outra subida em andamento na campanha, espera');
    igual(str_contains((string)$eg['texto'], 'outra lista subindo'), true, 'e diz por que está esperando');
    $outra->query("SELECT RELEASE_LOCK('l3c_campanha_316173')");
    igual($subir($G)['enviados'], 1, 'liberou, sobe');
    // Caso do Jhony (30/09, 21:48): subiu a lista, montou a mesma de novo e
    // a prévia dizia "197 entram". Agora a prévia já confere a campanha do
    // seletor: lista idêntica dá 0, e aprovar recusa.
    $pdo->exec('DELETE FROM l3c_listas');
    $entram = fn(int $id) => (int)$pdo->query("SELECT COUNT(*) FROM l3c_itens WHERE lista_id=$id AND descarte IS NULL")->fetchColumn();
    $foraPor = fn(int $id, string $d) => (int)$pdo->query("SELECT COUNT(*) FROM l3c_itens WHERE lista_id=$id AND descarte=" . $pdo->quote($d))->fetchColumn();
    $P1 = $nova('P1', ['47988880031', '47988880032', '47988880033']);
    $aprovar($P1, 316173);
    $P2 = $nova('P2', ['47988880031', '47988880032', '47988880033']);
    $mp = new L3cMontador($pdo, null, $tresc);
    for ($i = 0; $i < 5 && !($r = $mp->preconferir($P2, 316173))['pronto']; $i++);
    igual($entram($P2), 0, 'prévia da lista idêntica: 0 entram');
    igual($foraPor($P2, L3cMontador::DESCARTE_DUP_PORTAL), 3, 'prévia: os 3 aparecem em quem ficou de fora');
    igual(is_string(L3cMontador::antesDeAprovar($pdo, $P2, 316173)), true, 'lista idêntica não deixa aprovar');
    $st = $pdo->prepare('SELECT status FROM l3c_listas WHERE id=?'); $st->execute([$P2]);
    igual($st->fetchColumn(), 'pronta', 'e continua pronta, sem nada no 3C');
    // Trocou a campanha no seletor: a conta refaz para a nova.
    for ($i = 0; $i < 5 && !$mp->preconferir($P2, 999)['pronto']; $i++);
    igual($entram($P2), 3, 'outra campanha no seletor: os 3 voltam a entrar');
    for ($i = 0; $i < 5 && !$mp->preconferir($P2, 316173)['pronto']; $i++);
    igual($entram($P2), 0, 'voltou para a campanha de antes: 0 de novo');
    // Lista com 1 novo: a prévia mostra só ele, e aprovar sobe só ele.
    $P3 = $nova('P3', ['47988880031', '47988880032', '47988880039']);
    for ($i = 0; $i < 5 && !$mp->preconferir($P3, 316173)['pronto']; $i++);
    igual($entram($P3), 1, 'prévia com um contato novo: 1 entra');
    igual(L3cMontador::antesDeAprovar($pdo, $P3, 316173), null, 'com alguém novo, aprovar segue');
    igual($aprovar($P3, 316173)['enviados'], 1, 'e sobe só o novo (a conferência do aprovar é a segunda trava)');

    // Cartão Repetidos (Jhony, 05/10): os três tipos com quais são, de onde
    // vieram e quando. P4 tem 1 já subido pelo portal (P1), 1 já ligado no
    // 3C numa lista feita à mão e 1 repetido dentro dela mesma.
    $P4 = $nova('P4', ['47988880031', '47988880099', '47988880041', '47988880041', '47988880042']);
    $pdo->prepare("UPDATE l3c_itens SET descarte=? WHERE lista_id=? AND atendimento_id=1003")->execute([L3cTratamento::DUP_LISTA, $P4]);
    for ($i = 0; $i < 5 && !$mp->preconferir($P4, 316173)['pronto']; $i++);
    $g = $pdo->prepare('SELECT descarte, COUNT(*) n FROM l3c_itens WHERE lista_id=? GROUP BY descarte'); $g->execute([$P4]);
    $rep = L3cTratamento::repetidosPorTipo($g->fetchAll());
    igual([$rep['total'], $rep['tipos'][L3cTratamento::DUP_PORTAL], $rep['tipos'][L3cTratamento::DUP_3C], $rep['tipos'][L3cTratamento::DUP_LISTA]], [3, 1, 1, 1], 'repetidos: 1 de cada tipo, total 3');
    igual($entram($P4), 2, 'repetidos: o "entram" já está sem os 3');
    $st = $pdo->prepare('SELECT * FROM l3c_listas WHERE id=?'); $st->execute([$P4]); $l4 = $st->fetch();
    $q = [];
    foreach (L3cMontador::repetidosDaLista($pdo, $l4, 316173) as $r) $q[$r['tipo']] = $r;
    igual($q[L3cTratamento::DUP_PORTAL]['origem'] ?? null, 'P1', 'ver quais: já subiu pelo portal mostra a lista de origem');
    igual(($q[L3cTratamento::DUP_PORTAL]['em'] ?? '') !== '', true, 'ver quais: e a data');
    igual([$q[L3cTratamento::DUP_3C]['origem'] ?? null, substr($q[L3cTratamento::DUP_3C]['em'] ?? '', 0, 10)],
          ['[11-09 a 20-09] L.A.A ENCERRADOS MOTIVOS', '2026-09-28'], 'ver quais: ligado no 3C mostra a lista e a última ligação');
    igual($q[L3cTratamento::DUP_LISTA]['origem'] ?? null, 'P4', 'ver quais: repetido na lista aponta ela mesma');
    // Trocou a campanha: o repetido do 3C volta e perde a origem antiga.
    for ($i = 0; $i < 5 && !$mp->preconferir($P4, 999)['pronto']; $i++);
    $st = $pdo->prepare('SELECT repetido_lista FROM l3c_itens WHERE lista_id=? AND telefone=?'); $st->execute([$P4, '47988880099']);
    igual($st->fetchColumn(), null, 'trocar a campanha limpa a origem do repetido do 3C');
    // Nome igual ganha #2 (mesma consulta do acao.php).
    $cab = '[15-09 a 25-09]';
    $pdo->exec('DELETE FROM l3c_listas');
    $nova("$cab L.A.A ENCERRADOS MOTIVOS PORTAL", ['47988880051']);
    $st = $pdo->prepare('SELECT nome FROM l3c_listas WHERE LEFT(nome, ?) = ?'); $st->execute([mb_strlen($cab), $cab]);
    igual(L3cTratamento::nomeComNumero("$cab L.A.A ENCERRADOS MOTIVOS PORTAL", $st->fetchAll(PDO::FETCH_COLUMN)), "$cab #2 L.A.A ENCERRADOS MOTIVOS PORTAL", 'nome igual no banco: a segunda vira #2');
} finally {
    $pid = proc_get_status($proc)['pid'];
    proc_terminate($proc);
    proc_close($proc);
    @unlink("$dir/r.php"); @rmdir($dir);
    $pdo->exec('DELETE FROM l3c_listas');
    echo "(3C falso na porta $porta, pid $pid, encerrado)\n";
}

echo "\n$n testes, $falhas falhas\n";
exit($falhas ? 1 : 0);
