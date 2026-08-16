<?php
/* =====================================================================
   config.example.php — MODELO. Copie para a pasta de configuração FORA
   da área pública e renomeie para config.php:

       ~/portal-config/config.php     (recomendado — fora do public_html)

   Este arquivo guarda segredos (senha do banco, token do GHL) e por isso
   NUNCA entra no Git (veja o .gitignore) nem fica em pasta acessível pela
   web. Preencha os valores e não o exponha.
   ===================================================================== */

// --- Banco de dados (MySQL da Hostinger) ---
define('DB_HOST', 'localhost');
define('DB_NAME', 'COLE_O_NOME_DO_BANCO');
define('DB_USER', 'COLE_O_USUARIO');
define('DB_PASS', 'COLE_A_SENHA');

// --- Módulo Painel dos Corretores (GHL) ---
// Token de integração privada do GHL (Private Integration Token).
define('GHL_TOKEN',    'COLE_O_TOKEN_GHL');
define('GHL_LOCATION', '9o1WOaGvZNxhcdSgqAaG');
// Endereço do CRM para abrir a conversa direto (botão na Fila de atendimento).
// Use o domínio do WeSales que você acessa. Se não souber, deixe o padrão do
// GoHighLevel. Sem barra no final.
define('GHL_APP_URL',  'https://app.wesalescrm.com');

// --- Caminhos de dados (fora da área pública) ---
// Onde o coletor do painel grava o agregado diário.
define('PAINEL_DATA_DIR', dirname(__DIR__) . '/painel-dados');

// --- Sessão ---
// Nome do cookie de sessão compartilhado por todo o portal.
define('PORTAL_SESSION_NAME', 'camargo_portal');

// --- Segurança do login ---
define('MAX_TENTATIVAS', 6);
define('BLOQUEIO_MINUTOS', 15);
