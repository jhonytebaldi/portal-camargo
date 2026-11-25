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
            VALUES ('raid-logs','WCLogs','','▦','/raid-logs/index.php',0,90)")
            ->execute();
        $feitos[] = 'tool:raid-logs';
    }
    // ferramenta pessoal: fica inativa em tools (fora da home e do admin); o acesso é por raid-logs/_acesso.php
    if ($pdo->exec("UPDATE tools SET ativo = 0, nome = 'WCLogs', descricao = '' WHERE slug = 'raid-logs' AND (ativo = 1 OR nome <> 'WCLogs')")) $feitos[] = 'raid-logs oculto';
    return $feitos;
}
