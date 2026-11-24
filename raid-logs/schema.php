<?php
/* raid-logs/schema.php — migração idempotente do módulo (chamada por admin/migrar.php). */
declare(strict_types=1);

function rl_migrar(PDO $pdo): array {
    $feitos = [];
    $tem = (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rl_kv'")->fetchColumn();
    if (!$tem) {
        $pdo->exec("CREATE TABLE IF NOT EXISTS rl_kv (
            nome VARCHAR(120) NOT NULL PRIMARY KEY,
            conteudo LONGBLOB NOT NULL,
            atualizado DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela rl_kv';
    }
    $existe = (int)$pdo->query("SELECT COUNT(*) FROM tools WHERE slug='raid-logs'")->fetchColumn();
    if (!$existe) {
        $pdo->prepare("INSERT INTO tools (slug,nome,descricao,icone,caminho,ativo,ordem)
            VALUES ('raid-logs','Análise de Logs da Raid','Warcraft Logs: wipes, cooldowns de cura e notes do NSRT','🐍','/raid-logs/index.php',1,90)")
            ->execute();
        $feitos[] = 'tool:raid-logs';
    }
    return $feitos;
}
