<?php
/* construtor/schema.php — migração idempotente da Área do Construtor
   (chamada por admin/migrar.php). Cada construtora é uma "página" (slug)
   com o próprio painel em construtor/paginas/<slug>.html; os DADOS ficam
   só no banco (co_paginas.dados), nunca no repositório. */
declare(strict_types=1);

function co_migrar(PDO $pdo): array {
    $feitos = [];
    $tem = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'co_paginas'")->fetchColumn();
    if (!$tem) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS co_paginas (
            slug          VARCHAR(40)  NOT NULL PRIMARY KEY,
            nome          VARCHAR(120) NOT NULL,
            descricao     VARCHAR(200) NOT NULL DEFAULT '',
            ativo         TINYINT(1)   NOT NULL DEFAULT 1,
            ordem         INT          NOT NULL DEFAULT 0,
            dados         LONGTEXT     NULL,
            atualizado_em DATETIME     NULL,
            atualizado_por INT UNSIGNED NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela co_paginas';
    }
    $tem = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'co_user_paginas'")->fetchColumn();
    if (!$tem) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS co_user_paginas (
            user_id INT UNSIGNED NOT NULL,
            slug    VARCHAR(40)  NOT NULL,
            PRIMARY KEY (user_id, slug)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela co_user_paginas';
    }
    $col = function (string $tb, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $st->execute([$tb, $c]); return (int)$st->fetchColumn() > 0;
    };
    if (!$col('co_paginas', 'pedido_em')) {
        $pdo->exec("ALTER TABLE co_paginas ADD COLUMN pedido_em DATETIME NULL, ADD COLUMN pedido_por INT UNSIGNED NULL");
        $feitos[] = 'co_paginas.pedido_em';
    }
    $tem = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'co_config'")->fetchColumn();
    if (!$tem) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS co_config (
            k VARCHAR(60) NOT NULL PRIMARY KEY,
            v VARCHAR(255) NOT NULL,
            atualizado_em DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela co_config';
    }
    $st = $pdo->prepare("SELECT COUNT(*) FROM co_paginas WHERE slug='gbrac'"); $st->execute();
    if (!(int)$st->fetchColumn()) {
        $pdo->prepare("INSERT INTO co_paginas (slug,nome,descricao,ativo,ordem) VALUES ('gbrac','GBRAC','Monteluce Residence — fluxo de recebíveis',1,10)")->execute();
        $feitos[] = 'página gbrac';
    }
    $existe = (int)$pdo->query("SELECT COUNT(*) FROM tools WHERE slug='area-construtor'")->fetchColumn();
    if (!$existe) {
        $pdo->prepare("INSERT INTO tools (slug,nome,descricao,icone,caminho,ativo,ordem)
            VALUES ('area-construtor','Área do Construtor','Recebíveis, vendas e distratos das obras de cada construtora','🏗️','/construtor/',1,40)")
            ->execute();
        $feitos[] = 'tool:area-construtor';
    }
    return $feitos;
}
