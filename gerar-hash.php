<?php
/* gerar-hash.php — utilitário para gerar o hash de uma senha (password_hash).
   Use uma vez para criar a senha do admin inicial e APAGUE este arquivo
   depois (mesmo cuidado da busca). Formulário via POST porque senhas com
   '#' são cortadas quando passam pela URL. */
declare(strict_types=1);
$hash = '';
$len = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $s = (string)($_POST['s'] ?? '');
    $len = strlen($s);
    if ($s !== '') $hash = password_hash($s, PASSWORD_DEFAULT);
}
?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Gerar hash</title>
<link rel="stylesheet" href="/assets/portal.css"></head>
<body class="tela-login">
<form class="cartao-login" method="post">
  <h1>Gerar hash de senha</h1>
  <p class="sub">Cole o resultado no seed.sql / no admin. Depois APAGUE este arquivo.</p>
  <label>Senha<input name="s" autofocus></label>
  <button type="submit">Gerar hash</button>
  <?php if ($hash !== ''): ?>
    <p class="sub" style="margin-top:16px">Leu <?= (int)$len ?> caractere(s). Hash:</p>
    <textarea readonly style="width:100%;height:70px;font:13px monospace;padding:8px"><?= htmlspecialchars($hash, ENT_QUOTES) ?></textarea>
  <?php endif; ?>
</form>
</body></html>
