<?php
/* admin/migrar.php — migrações idempotentes do banco (só admin).
   Pode ser acessado quantas vezes quiser; só aplica o que falta. */
declare(strict_types=1);
require_once __DIR__ . '/comum.php';
$u = admin_guard();
$pdo = db();

function col_existe(PDO $pdo, string $tabela, string $coluna): bool {
    $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS
                         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
    $st->execute([$tabela, $coluna]);
    return (int)$st->fetchColumn() > 0;
}

$feitos = [];
try {
    if (!col_existe($pdo, 'users', 'broker_id')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN broker_id VARCHAR(40) NULL");
        $pdo->exec("ALTER TABLE users ADD INDEX ix_users_broker (broker_id)");
        $feitos[] = 'users.broker_id';
    }
    if (!col_existe($pdo, 'users', 'ativacao_token')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN ativacao_token VARCHAR(64) NULL");
        $feitos[] = 'users.ativacao_token';
    }
    $ok = true; $erro = '';
} catch (Throwable $e) { $ok = false; $erro = $e->getMessage(); }

admin_header('', $u);
echo '<h1 class="home-titulo">Migração do banco</h1>';
if (!$ok) echo '<div class="erro">Erro: ' . h($erro) . '</div>';
elseif ($feitos) echo '<div class="ok-box">Aplicado: ' . h(implode(', ', $feitos)) . '</div>';
else echo '<div class="ok-box">Banco já está atualizado. Nada a fazer.</div>';
echo '<p class="home-sub">Pode voltar para <a href="/admin/usuarios.php">Usuários</a>.</p>';
portal_footer();
