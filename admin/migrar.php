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
    // Garante a ferramenta 'calculadora' registrada (idempotente; não existe
    // no seed de instalações antigas). require_tool depende dela existir.
    $existe = (int)$pdo->query("SELECT COUNT(*) FROM tools WHERE slug='calculadora'")->fetchColumn();
    if (!$existe) {
        $pdo->prepare("INSERT INTO tools (slug,nome,descricao,icone,caminho,ativo,ordem)
            VALUES ('calculadora','Calculadora de Ganho e Funil','Quanto o corretor precisa produzir para ganhar o que quer','💰','/calculadora/',1,25)")
            ->execute();
        $feitos[] = 'tool:calculadora';
    }
    if (!col_existe($pdo, 'brokers', 'desligado_em')) {
        $pdo->exec("ALTER TABLE brokers ADD COLUMN desligado_em DATETIME NULL");
        $feitos[] = 'brokers.desligado_em';
    }
    // Garante a ferramenta 'busca' registrada (login único da Busca de Imóveis
    // rodando como subpasta /busca do portal). require_tool('busca') depende dela.
    $existeBusca = (int)$pdo->query("SELECT COUNT(*) FROM tools WHERE slug='busca'")->fetchColumn();
    if (!$existeBusca) {
        $pdo->prepare("INSERT INTO tools (slug,nome,descricao,icone,caminho,ativo,ordem)
            VALUES ('busca','Busca de Imóveis','Busca e auditoria do acervo (Robust CRM)','🏠','/busca/',1,10)")
            ->execute();
        $feitos[] = 'tool:busca';
    }
    // ---- Módulo Plano de Ação Diário ----------------------------------
    // De-para com o Robust: cada corretor (GHL) ganha o id de usuário dele
    // no Robust — é o que traduz o escopo do portal para o funil.
    if (!col_existe($pdo, 'brokers', 'robust_user_id')) {
        $pdo->exec("ALTER TABLE brokers ADD COLUMN robust_user_id INT UNSIGNED NULL");
        $pdo->exec("ALTER TABLE brokers ADD INDEX ix_brokers_robust (robust_user_id)");
        $feitos[] = 'brokers.robust_user_id';
    }
    $existePA = (int)$pdo->query("SELECT COUNT(*) FROM tools WHERE slug='plano-acao'")->fetchColumn();
    if (!$existePA) {
        $pdo->prepare("INSERT INTO tools (slug,nome,descricao,icone,caminho,ativo,ordem)
            VALUES ('plano-acao','Plano de Ação Diário','O que fazer hoje com cada cliente ativo do funil','🎯','/plano-acao/',1,15)")
            ->execute();
        $feitos[] = 'tool:plano-acao';
    }
    // Tabelas do módulo (idempotente). Estado do módulo mora aqui: o
    // gerador (tarefa agendada) zera o ambiente entre execuções e lê/grava
    // tudo via api-estado.php / api-importar.php.
    $temPA = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pa_planos'")->fetchColumn();
    if (!$temPA) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS pa_clientes (
            atendimento_id INT UNSIGNED NOT NULL,
            cliente_id     INT UNSIGNED NOT NULL DEFAULT 0,
            nome           VARCHAR(160) NOT NULL DEFAULT '',
            telefones      VARCHAR(120) NOT NULL DEFAULT '',
            robust_atendente INT UNSIGNED NOT NULL DEFAULT 0,
            broker_id      VARCHAR(40) NULL,
            stage          TINYINT NOT NULL DEFAULT 0,
            ghl_contact_id VARCHAR(40) NULL,
            ghl_conv_id    VARCHAR(40) NULL,
            last_msg_at    BIGINT NULL,
            last_analise_at VARCHAR(40) NULL,
            resumo         TEXT NULL,
            atualizado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (atendimento_id),
            KEY ix_pac_atendente (robust_atendente)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS pa_planos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            data DATE NOT NULL,
            robust_atendente INT UNSIGNED NOT NULL,
            broker_id VARCHAR(40) NULL,
            corretor_nome VARCHAR(160) NOT NULL DEFAULT '',
            texto_whatsapp MEDIUMTEXT NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_pap (data, robust_atendente)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE IF NOT EXISTS pa_itens (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            plano_id INT UNSIGNED NOT NULL,
            atendimento_id INT UNSIGNED NOT NULL,
            cliente_nome VARCHAR(160) NOT NULL DEFAULT '',
            telefones VARCHAR(120) NOT NULL DEFAULT '',
            stage TINYINT NOT NULL DEFAULT 0,
            acao VARCHAR(40) NOT NULL DEFAULT '',
            titulo VARCHAR(255) NOT NULL DEFAULT '',
            justificativa TEXT NULL,
            msg_sugerida TEXT NULL,
            score TINYINT UNSIGNED NOT NULL DEFAULT 0,
            faixa ENUM('vermelho','amarelo','azul','branco') NOT NULL DEFAULT 'branco',
            origem ENUM('conversa','andamentos') NOT NULL DEFAULT 'conversa',
            feito TINYINT(1) NOT NULL DEFAULT 0,
            feito_em DATETIME NULL,
            feito_por INT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY ix_pai_plano (plano_id),
            CONSTRAINT fk_pai_plano FOREIGN KEY (plano_id) REFERENCES pa_planos(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabelas pa_clientes/pa_planos/pa_itens';
    }
    // Plano de Ação v2: nome sugerido pela conversa + check automático.
    if (!col_existe($pdo, 'pa_itens', 'nome_sugerido')) {
        $pdo->exec("ALTER TABLE pa_itens ADD COLUMN nome_sugerido VARCHAR(120) NULL");
        $feitos[] = 'pa_itens.nome_sugerido';
    }
    if (!col_existe($pdo, 'pa_itens', 'feito_auto')) {
        $pdo->exec("ALTER TABLE pa_itens ADD COLUMN feito_auto TINYINT(1) NOT NULL DEFAULT 0");
        $feitos[] = 'pa_itens.feito_auto';
    }
    if (!col_existe($pdo, 'pa_clientes', 'ghl_assigned')) {
        $pdo->exec("ALTER TABLE pa_clientes ADD COLUMN ghl_assigned VARCHAR(40) NULL");
        $feitos[] = 'pa_clientes.ghl_assigned';
    }
    // Aplica o de-para GHL ↔ Robust conferido (plano-acao/depara-seed.php).
    // Idempotente e conservador: só preenche robust_user_id quando NULL —
    // o que o admin ajustar depois na mão nunca é sobrescrito.
    $seedFile = dirname(__DIR__) . '/plano-acao/depara-seed.php';
    if (is_readable($seedFile) && col_existe($pdo, 'brokers', 'robust_user_id')) {
        $seed = require $seedFile;
        $upd = $pdo->prepare('UPDATE brokers SET robust_user_id = ? WHERE id = ? AND robust_user_id IS NULL');
        $ins = $pdo->prepare('INSERT IGNORE INTO brokers (id, nome, email, ativo, robust_user_id) VALUES (?,?,?,1,?)');
        $n = 0;
        foreach ($seed as $ghlId => $d) {
            $upd->execute([(int)$d['robust'], (string)$ghlId]);
            if ($upd->rowCount() > 0) { $n++; continue; }
            $existe = $pdo->prepare('SELECT COUNT(*) FROM brokers WHERE id = ?');
            $existe->execute([(string)$ghlId]);
            if (!(int)$existe->fetchColumn()) {
                $ins->execute([(string)$ghlId, (string)($d['nome'] ?? ''), (string)($d['email'] ?? ''), (int)$d['robust']]);
                if ($ins->rowCount() > 0) $n++;
            }
        }
        if ($n) $feitos[] = "de-para robust aplicado ($n corretores)";
    }

    // ---- Lista de Bloqueio (a nível de portal) ----------------------
    $temBL = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'blocklist'")->fetchColumn();
    if (!$temBL) {
        $pdo->exec("CREATE TABLE blocklist (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            phone_raw   VARCHAR(40)  NOT NULL,
            phone_canon VARCHAR(20)  NOT NULL,
            motivo      VARCHAR(160) NOT NULL DEFAULT '',
            criado_em   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            criado_por  INT UNSIGNED NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_bl_canon (phone_canon)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela blocklist';
    }
    // interruptor por ferramenta: respeita a lista de bloqueio?
    if (!col_existe($pdo, 'tools', 'usa_blocklist')) {
        $pdo->exec("ALTER TABLE tools ADD COLUMN usa_blocklist TINYINT(1) NOT NULL DEFAULT 0");
        // já liga para as duas ferramentas de análise pedidas
        $pdo->exec("UPDATE tools SET usa_blocklist=1 WHERE slug IN ('painel-corretores','plano-acao')");
        $feitos[] = 'tools.usa_blocklist (on: painel + plano-acao)';
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
