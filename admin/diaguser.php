<?php
/* admin/diaguser.php — diagnóstico de permissões de um usuário (só admin).
   ?uid=N  → mostra papel, vínculos e o que a lógica de RBAC devolve. Temporário. */
declare(strict_types=1);
require_once __DIR__ . '/comum.php';
$u = admin_guard();
header('Content-Type: text/plain; charset=utf-8');
$pdo = db();

$uid = (int)($_GET['uid'] ?? 0);
$st = $pdo->prepare('SELECT id,nome,login,papel,ativo,broker_id FROM users WHERE id=?');
$st->execute([$uid]);
$row = $st->fetch();
if (!$row) { echo "usuário $uid não existe\n"; exit; }

echo "=== users.row (id=$uid) ===\n";
foreach ($row as $k=>$v) echo sprintf("  %-10s = %s\n", $k, var_export($v, true));
echo "  papel bytes = " . bin2hex((string)$row['papel']) . " (len " . strlen((string)$row['papel']) . ")\n";

echo "\n=== is_admin() ===\n";
echo "  is_admin(row) = " . var_export(is_admin($row), true) . "\n";
echo "  papel==='admin' = " . var_export($row['papel']==='admin', true) . "\n";

echo "\n=== user_tools ===\n";
$st=$pdo->prepare('SELECT t.slug FROM tools t JOIN user_tools ut ON ut.tool_id=t.id WHERE ut.user_id=?');
$st->execute([$uid]);
echo "  user_tools (tabela) = " . implode(', ', $st->fetchAll(PDO::FETCH_COLUMN) ?: ['(nenhuma)']) . "\n";
echo "  user_tools() slugs  = " . implode(', ', array_map(fn($t)=>$t['slug'], user_tools($row)) ?: ['(nenhuma)']) . "\n";

echo "\n=== escopo (equipes/corretores) ===\n";
$st=$pdo->prepare('SELECT team_id FROM user_teams WHERE user_id=?'); $st->execute([$uid]);
echo "  user_teams (tabela) = " . implode(', ', $st->fetchAll(PDO::FETCH_COLUMN) ?: ['(nenhuma)']) . "\n";
echo "  allowed_team_ids()   = " . implode(', ', allowed_team_ids($row) ?: ['(nenhuma)']) . "\n";
$abi = allowed_broker_ids($row);
echo "  allowed_broker_ids() = " . count($abi) . " corretor(es): " . implode(', ', $abi ?: ['(nenhum)']) . "\n";

echo "\n=== total de corretores ativos (referência) ===\n";
echo "  brokers ativos = " . (int)$pdo->query('SELECT COUNT(*) FROM brokers WHERE ativo=1')->fetchColumn() . "\n";
