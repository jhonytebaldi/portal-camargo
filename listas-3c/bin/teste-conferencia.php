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
if ($_SERVER["REQUEST_METHOD"] === "GET" && $p === "/calls") { echo json_encode(["data" => []]); return; }
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
    $aprovar = function (int $id, int $campanha) use ($pdo, $tresc): array {
        $pdo->prepare("UPDATE l3c_listas SET status='enviando', campanha_id=?, campanha_nome=?, cursor_json='{}' WHERE id=?")
            ->execute([$campanha, "Campanha $campanha", $id]);
        $m = new L3cMontador($pdo, null, $tresc);
        for ($i = 0; $i < 10; $i++) { $e = $m->avancar($id, 20.0); if ($e['status'] !== 'enviando') break; }
        return $e;
    };

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
