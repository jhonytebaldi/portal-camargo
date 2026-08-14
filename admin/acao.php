<?php
/* admin/acao.php — endpoint JSON para ações do admin (AJAX). Só admin + CSRF. */
declare(strict_types=1);
require_once __DIR__ . '/comum.php';
$u = admin_guard();
csrf_check();
header('Content-Type: application/json; charset=utf-8');
$pdo = db();
$action = $_POST['action'] ?? '';
function jout($x){ echo json_encode($x); exit; }

try {
    switch ($action) {
        case 'team_create':
            $nome = trim($_POST['nome'] ?? '');
            if ($nome === '') jout(['ok'=>false,'msg'=>'Informe um nome.']);
            $st = $pdo->prepare('INSERT INTO teams (nome) VALUES (?)');
            $st->execute([$nome]);
            jout(['ok'=>true, 'id'=>(int)$pdo->lastInsertId(), 'nome'=>$nome]);

        case 'team_rename':
            $pdo->prepare('UPDATE teams SET nome=? WHERE id=?')
                ->execute([trim($_POST['nome'] ?? ''), (int)($_POST['team_id'] ?? 0)]);
            jout(['ok'=>true]);

        case 'team_delete':
            $pdo->prepare('DELETE FROM teams WHERE id=?')->execute([(int)($_POST['team_id'] ?? 0)]);
            jout(['ok'=>true]);

        case 'tb_add':
            $pdo->prepare('INSERT IGNORE INTO team_brokers (team_id, broker_id) VALUES (?,?)')
                ->execute([(int)($_POST['team_id'] ?? 0), (string)($_POST['broker_id'] ?? '')]);
            jout(['ok'=>true]);

        case 'tb_remove':
            $pdo->prepare('DELETE FROM team_brokers WHERE team_id=? AND broker_id=?')
                ->execute([(int)($_POST['team_id'] ?? 0), (string)($_POST['broker_id'] ?? '')]);
            jout(['ok'=>true]);

        case 'user_tool':
            $on = ($_POST['on'] ?? '') === '1';
            if ($on) $pdo->prepare('INSERT IGNORE INTO user_tools (user_id, tool_id) VALUES (?,?)')
                        ->execute([(int)$_POST['user_id'], (int)$_POST['tool_id']]);
            else     $pdo->prepare('DELETE FROM user_tools WHERE user_id=? AND tool_id=?')
                        ->execute([(int)$_POST['user_id'], (int)$_POST['tool_id']]);
            jout(['ok'=>true]);

        case 'user_team':
            $on = ($_POST['on'] ?? '') === '1';
            if ($on) $pdo->prepare('INSERT IGNORE INTO user_teams (user_id, team_id) VALUES (?,?)')
                        ->execute([(int)$_POST['user_id'], (int)$_POST['team_id']]);
            else     $pdo->prepare('DELETE FROM user_teams WHERE user_id=? AND team_id=?')
                        ->execute([(int)$_POST['user_id'], (int)$_POST['team_id']]);
            jout(['ok'=>true]);

        case 'ghl_sync':
            require_once __DIR__ . '/../lib/ghl.php';
            jout(ghl_sync_brokers());

        default:
            jout(['ok'=>false, 'msg'=>'Ação desconhecida.']);
    }
} catch (Throwable $e) {
    jout(['ok'=>false, 'msg'=>$e->getMessage()]);
}
