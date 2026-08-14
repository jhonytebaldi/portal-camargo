<?php
/* login.php — autenticação multiusuário do portal (com bloqueio por
   tentativas). Substitui o login próprio da busca. */
declare(strict_types=1);
require_once __DIR__ . '/lib/auth.php';
portal_session_start();

// Já logado? vai para o destino (ou home).
if (current_user()) {
    header('Location: ' . seguro_next($_GET['next'] ?? '/'));
    exit;
}

/** Só aceita caminhos internos como destino de redirecionamento. */
function seguro_next(string $n): string {
    return (str_starts_with($n, '/') && !str_starts_with($n, '//')) ? $n : '/';
}

function ip_hash(): string {
    return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'sem-ip');
}

$erro = '';
$chave = ip_hash();
$max = defined('MAX_TENTATIVAS') ? MAX_TENTATIVAS : 6;
$bloqMin = defined('BLOQUEIO_MINUTOS') ? BLOQUEIO_MINUTOS : 15;

$st = db()->prepare('SELECT n, ate FROM login_attempts WHERE ip_hash = ?');
$st->execute([$chave]);
$reg = $st->fetch() ?: ['n' => 0, 'ate' => 0];
$bloqueado = ((int)$reg['ate']) > time();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloqueado) {
    $login = trim($_POST['login'] ?? '');
    $senha = $_POST['senha'] ?? '';
    $u = null;
    if ($login !== '') {
        $q = db()->prepare('SELECT * FROM users WHERE login = ? AND ativo = 1');
        $q->execute([$login]);
        $u = $q->fetch();
    }
    if ($u && password_verify($senha, $u['senha_hash'])) {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int)$u['id'];
        $_SESSION['desde'] = time();
        db()->prepare('DELETE FROM login_attempts WHERE ip_hash = ?')->execute([$chave]);
        header('Location: ' . seguro_next($_GET['next'] ?? '/'));
        exit;
    }
    // Falhou: incrementa tentativas.
    $n = ((int)$reg['n']) + 1;
    $ate = 0;
    if ($n >= $max) { $ate = time() + $bloqMin * 60; $n = 0; $bloqueado = true; }
    db()->prepare(
        'INSERT INTO login_attempts (ip_hash, n, ate, visto) VALUES (?,?,?,?)
         ON DUPLICATE KEY UPDATE n=VALUES(n), ate=VALUES(ate), visto=VALUES(visto)'
    )->execute([$chave, $n, $ate, time()]);
    $erro = $bloqueado
        ? "Muitas tentativas. Tente novamente em {$bloqMin} minutos."
        : 'Login ou senha incorretos.';
} elseif ($bloqueado) {
    $faltam = max(1, (int)ceil(((int)$reg['ate'] - time()) / 60));
    $erro = "Bloqueado temporariamente. Aguarde {$faltam} min.";
}
$next = h($_GET['next'] ?? '');
?><!DOCTYPE html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Entrar — Camargo</title>
<link rel="stylesheet" href="/assets/portal.css?v=<?= @filemtime(__DIR__.'/assets/portal.css') ?>">
</head><body class="tela-login">
<form class="cartao-login" method="post" action="login.php<?= $next ? '?next=' . $next : '' ?>">
  <h1>Portal Camargo</h1>
  <p class="sub">Acesso interno</p>
  <?php if ($erro): ?><div class="erro"><?= h($erro) ?></div><?php endif; ?>
  <label>Usuário<input name="login" autocomplete="username" autofocus></label>
  <label>Senha<input type="password" name="senha" autocomplete="current-password"></label>
  <button type="submit"<?= $bloqueado ? ' disabled' : '' ?>>Entrar</button>
</form>
</body></html>
