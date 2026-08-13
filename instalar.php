<?php
/* instalar.php — instalador web de uso ÚNICO.
   Cria as tabelas (schema.sql), registra as ferramentas e cria o 1º admin.
   APAGUE este arquivo depois de usar. Só permite criar admin se ainda não
   existir nenhum (trava de segurança). */
declare(strict_types=1);
require_once __DIR__ . '/lib/db.php';
require_once __DIR__ . '/lib/auth.php';
portal_load_config();
$pdo = db();

$log = [];
$erro = '';

/* 1) Aplica o schema (dividido em comandos) */
$schema = @file_get_contents(__DIR__ . '/schema.sql');
if ($schema) {
    foreach (array_filter(array_map('trim', explode(';', $schema))) as $stmt) {
        if ($stmt === '' || str_starts_with($stmt, '--')) continue;
        try { $pdo->exec($stmt); } catch (Throwable $e) { /* ignora "já existe" */ }
    }
    $log[] = 'Tabelas verificadas/criadas.';
}

/* 2) Registra as ferramentas */
try {
    $pdo->exec("INSERT INTO tools (slug, nome, descricao, icone, caminho, ativo, ordem) VALUES
      ('busca','Busca de Imóveis','Busca e auditoria do acervo (Robust CRM)','🏠','/busca/',1,10),
      ('painel-corretores','Painel dos Corretores','Presença aproximada da equipe (GHL)','📊','/painel-corretores/',1,20),
      ('funil','Funil diário','Funil de vendas da Camargo','📈','/funil/',0,30),
      ('conciliador','Conciliador','Conciliação (em breve)','🧮','/conciliador/',0,40)
      ON DUPLICATE KEY UPDATE nome=VALUES(nome), descricao=VALUES(descricao), icone=VALUES(icone), caminho=VALUES(caminho)");
    $log[] = 'Ferramentas registradas.';
} catch (Throwable $e) { $erro = 'Falha ao registrar ferramentas: ' . $e->getMessage(); }

$temAdmin = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE papel='admin' AND ativo=1")->fetchColumn();

/* 3) Cria o admin (só se ainda não houver) */
$feito = false;
if (!$temAdmin && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $nome  = trim($_POST['nome'] ?? '');
    $login = trim($_POST['login'] ?? '');
    $senha = (string)($_POST['senha'] ?? '');
    if ($nome === '' || $login === '' || strlen($senha) < 6) {
        $erro = 'Preencha nome, login e uma senha de pelo menos 6 caracteres.';
    } else {
        $hash = password_hash($senha, PASSWORD_DEFAULT);
        $st = $pdo->prepare("INSERT INTO users (nome, login, senha_hash, papel, ativo) VALUES (?,?,?,'admin',1)");
        try { $st->execute([$nome, $login, $hash]); $feito = true; $temAdmin = 1; }
        catch (Throwable $e) { $erro = 'Falha ao criar admin (login já existe?): ' . $e->getMessage(); }
    }
}
?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Instalar portal</title>
<link rel="stylesheet" href="/assets/portal.css"></head>
<body class="tela-login">
<div class="cartao-login">
  <h1>Instalação do portal</h1>
  <?php foreach ($log as $l): ?><div class="sub" style="color:#2f5d4f">✓ <?= h($l) ?></div><?php endforeach; ?>
  <?php if ($erro): ?><div class="erro" style="margin-top:12px"><?= h($erro) ?></div><?php endif; ?>

  <?php if ($feito): ?>
    <div class="erro" style="background:rgba(47,93,79,.12);border-color:#2f5d4f;color:#2f5d4f;margin-top:14px">
      Admin criado! Agora <b>apague o arquivo instalar.php</b> e acesse
      <a href="/">a página inicial</a> para entrar.</div>
  <?php elseif ($temAdmin): ?>
    <p class="sub" style="margin-top:14px">Já existe um administrador. Nada a fazer aqui —
    <b>apague o arquivo instalar.php</b> e vá para <a href="/">a página inicial</a>.</p>
  <?php else: ?>
    <p class="sub" style="margin-top:14px">Crie o administrador do portal:</p>
    <form method="post">
      <label>Nome<input name="nome" value="Jhony Tebaldi"></label>
      <label>Login<input name="login" value="jhony" autocomplete="username"></label>
      <label>Senha (mín. 6)<input type="password" name="senha" autocomplete="new-password"></label>
      <button type="submit">Criar admin</button>
    </form>
  <?php endif; ?>
</div>
</body></html>
