<?php
/* login.php — tela de senha própria da Busca (modo standalone).
   Quando a Busca é módulo do portal, o login é o do portal: aqui apenas
   redirecionamos para lá. */
require_once __DIR__ . '/_auth.php';
if (busca_portal_auth()) {
    header('Location: /login.php?next=' . rawurlencode('/busca/'));
    exit;
}

require_once __DIR__ . '/config.php';
session_start();

/** Identifica o visitante para o controle de tentativas. */
function chave_cliente(): string {
    return hash('sha256', $_SERVER['REMOTE_ADDR'] ?? 'sem-ip');
}

function tentativas_ler(): array {
    if (!file_exists(ARQ_TENTATIVAS)) return [];
    return json_decode(@file_get_contents(ARQ_TENTATIVAS), true) ?: [];
}

function tentativas_gravar(array $t): void {
    $limite = time() - BLOQUEIO_MINUTOS * 60;
    foreach ($t as $k => $v) {
        $visto = $v['visto'] ?? 0;
        $ate   = $v['ate'] ?? 0;
        if ($visto < $limite && $ate < time()) unset($t[$k]);
    }
    @file_put_contents(ARQ_TENTATIVAS, json_encode($t), LOCK_EX);
}

$erro = '';
$chave = chave_cliente();
$tent  = tentativas_ler();
$reg   = $tent[$chave] ?? ['n' => 0, 'ate' => 0];
$bloqueadoAte = $reg['ate'] ?? 0;
$bloqueado = $bloqueadoAte > time();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$bloqueado) {
    $senha = $_POST['senha'] ?? '';
    if (password_verify($senha, SENHA_HASH)) {
        session_regenerate_id(true);
        $_SESSION['autenticado'] = true;
        $_SESSION['desde'] = time();
        unset($tent[$chave]);
        tentativas_gravar($tent);
        header('Location: index.php');
        exit;
    }
    $reg['n'] = ($reg['n'] ?? 0) + 1;
    $reg['visto'] = time();
    if ($reg['n'] >= MAX_TENTATIVAS) {
        $reg['ate'] = time() + BLOQUEIO_MINUTOS * 60;
        $reg['n']   = 0;
        $bloqueado  = true;
        $bloqueadoAte = $reg['ate'];
        log_sync('login bloqueado por excesso de tentativas');
    }
    $tent[$chave] = $reg;
    tentativas_gravar($tent);
    $erro = $bloqueado
        ? 'Muitas tentativas. Tente de novo em ' . BLOQUEIO_MINUTOS . ' minutos.'
        : 'Senha incorreta.';
} elseif ($bloqueado) {
    $erro = 'Bloqueado temporariamente. Aguarde ' . max(1, ceil(($bloqueadoAte - time()) / 60)) . ' min.';
}
?><!DOCTYPE html>
<html lang="pt-BR"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Busca de Imóveis — Camargo</title>
<link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,700&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
:root{--ink:#12211c;--paper:#f2efe6;--card:#fbfaf6;--moss:#2f5d4f;--clay:#b4512f;--line:#d8d3c4;--mute:#6b7770}
*{box-sizing:border-box}
body{margin:0;background:var(--paper);color:var(--ink);font-family:Inter,system-ui,sans-serif;
 display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.box{background:var(--card);border:1px solid var(--line);border-top:4px solid var(--moss);
 padding:36px 34px;max-width:390px;width:100%;border-radius:3px}
h1{font-family:Fraunces,Georgia,serif;font-size:27px;margin:0 0 6px;letter-spacing:-.02em}
p.sub{color:var(--mute);font-size:14px;margin:0 0 24px}
label{display:block;font-size:13px;font-weight:600;margin-bottom:7px}
input{width:100%;padding:13px 15px;font:inherit;font-size:16px;border:2px solid var(--line);
 border-radius:2px;background:#fff}
input:focus{outline:none;border-color:var(--moss)}
button{width:100%;margin-top:16px;padding:14px;background:var(--moss);color:#fff;border:none;
 border-radius:2px;font:inherit;font-weight:600;font-size:15px;cursor:pointer}
button:hover{background:#264c40}
button:disabled{background:var(--mute);cursor:not-allowed}
.erro{background:rgba(180,81,47,.1);border-left:3px solid var(--clay);color:var(--clay);
 padding:11px 13px;font-size:13.5px;margin-bottom:18px;border-radius:2px}
</style></head>
<body>
<div class="box">
  <h1>Busca de imóveis</h1>
  <p class="sub">Imobiliária Camargo · acesso restrito</p>
  <?php if ($erro): ?><div class="erro"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
  <form method="post" autocomplete="off">
    <label for="senha">Senha</label>
    <input type="password" id="senha" name="senha" required autofocus <?= $bloqueado ? 'disabled' : '' ?>>
    <button type="submit" <?= $bloqueado ? 'disabled' : '' ?>>Entrar</button>
  </form>
</div>
</body></html>
