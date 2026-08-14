<?php
/* ativar.php — 1º acesso: o usuário define a própria senha via token. Público. */
declare(strict_types=1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$pdo = db();
$erro = ''; $ok = false; $user = null;

if ($token !== '') {
    $st = $pdo->prepare('SELECT id, nome, login FROM users WHERE ativacao_token = ? AND ativo = 1');
    $st->execute([$token]);
    $user = $st->fetch();
}
if ($token === '' || !$user) {
    $erro = 'Link de ativação inválido ou já utilizado.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $s1 = (string)($_POST['senha'] ?? '');
    $s2 = (string)($_POST['senha2'] ?? '');
    if (strlen($s1) < 6)      $erro = 'A senha precisa de pelo menos 6 caracteres.';
    elseif ($s1 !== $s2)      $erro = 'As senhas não conferem.';
    else {
        $pdo->prepare('UPDATE users SET senha_hash = ?, ativacao_token = NULL WHERE id = ?')
            ->execute([password_hash($s1, PASSWORD_DEFAULT), $user['id']]);
        $ok = true;
    }
}
?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">
<title>Definir senha — Camargo</title>
<link rel="stylesheet" href="/assets/portal.css?v=<?= @filemtime(__DIR__.'/assets/portal.css') ?>">
</head><body class="tela-login">
<div class="cartao-login">
  <h1>Portal Camargo</h1>
  <?php if ($ok): ?>
    <div class="ok-box">Senha definida! Agora é só <a href="/login.php">entrar</a> com o seu login.</div>
  <?php elseif (!$user): ?>
    <div class="erro"><?= h($erro) ?></div>
    <p class="sub">Peça um novo link ao administrador.</p>
  <?php else: ?>
    <p class="sub">Olá, <?= h($user['nome']) ?>. Defina sua senha de acesso (login: <b><?= h($user['login']) ?></b>).</p>
    <?php if ($erro): ?><div class="erro"><?= h($erro) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="token" value="<?= h($token) ?>">
      <label>Nova senha<input type="password" name="senha" autocomplete="new-password" autofocus></label>
      <label>Repita a senha<input type="password" name="senha2" autocomplete="new-password"></label>
      <button type="submit">Definir senha</button>
    </form>
  <?php endif; ?>
</div>
</body></html>
