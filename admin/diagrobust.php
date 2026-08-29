<?php
declare(strict_types=1);
require_once __DIR__ . '/comum.php';
$u = admin_guard();
header('Content-Type: text/plain; charset=utf-8');
$rows = db()->query("SELECT id,nome,robust_user_id FROM brokers WHERE nome LIKE '%Jhony%' OR robust_user_id=49")->fetchAll();
foreach ($rows as $r) echo sprintf("%-24s robust_user_id=%s  (ghl %s)\n", $r['nome'], var_export($r['robust_user_id'],true), $r['id']);
$tot = db()->query("SELECT COUNT(*) FROM brokers WHERE robust_user_id IS NOT NULL")->fetchColumn();
echo "brokers com robust_user_id: $tot\n";
