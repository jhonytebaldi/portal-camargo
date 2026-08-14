<?php
/* login.php — a Busca agora usa o LOGIN DO PORTAL.
   Este arquivo só redireciona para a tela de login do portal, mantendo o
   destino para voltar à Busca depois de autenticar. */
header('Location: /login.php?next=' . rawurlencode('/busca/'));
exit;
