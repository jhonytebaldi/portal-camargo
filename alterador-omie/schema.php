<?php
/* =====================================================================
   alterador-omie/schema.php — tabelas da ferramenta "Alterador Omie" +
   botão na home. Idempotente: roda na primeira visita de um admin e
   também por admin/migrar.php.
   ===================================================================== */
declare(strict_types=1);

const AO_SCHEMA_VERSAO = 1;

function ao_instalar_se_preciso(): void
{
    if (PHP_SAPI !== 'cli') {
        if (!is_admin()) return;
        if (($_SESSION['ao_schema'] ?? 0) === AO_SCHEMA_VERSAO) return;
    }
    try { ao_migrar(db()); } catch (Throwable $e) { error_log('alterador-omie: instalação automática falhou: ' . $e->getMessage()); return; }
    if (PHP_SAPI !== 'cli') $_SESSION['ao_schema'] = AO_SCHEMA_VERSAO;
}

function ao_migrar(PDO $pdo): array
{
    $feitos = [];
    $tem = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))->fetchColumn() > 0;
    if (!(int)$pdo->query("SELECT COUNT(*) FROM tools WHERE slug = 'alterador-omie'")->fetchColumn()) {
        $pdo->prepare("INSERT INTO tools (slug, nome, descricao, icone, caminho, ativo, ordem) VALUES ('alterador-omie', 'Alterador Omie', 'Altera em massa campos de contas a pagar e a receber no Omie, com conferência', '🛠️', '/alterador-omie/', 1, 47)")->execute();
        $feitos[] = 'ferramenta alterador-omie';
    }
    if (!$tem('ao_rodadas')) {
        $pdo->exec("CREATE TABLE ao_rodadas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            empresa VARCHAR(20) NOT NULL,
            tipos VARCHAR(2) NOT NULL DEFAULT 'P',        -- P | R | PR
            resumo TEXT NOT NULL,
            ops MEDIUMTEXT NOT NULL,                     -- alterações escolhidas (JSON)
            opts MEDIUMTEXT NOT NULL,                    -- ordem/sequência (JSON)
            desfaz_id INT UNSIGNED NULL,                 -- rodada que esta desfaz
            status VARCHAR(15) NOT NULL DEFAULT 'executando',   -- executando | concluida | pausada
            n_itens INT UNSIGNED NOT NULL DEFAULT 0,
            criado_por INT UNSIGNED NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY ix_aor_emp (empresa), KEY ix_aor_em (criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE ao_itens (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            rodada_id INT UNSIGNED NOT NULL,
            tipo CHAR(1) NOT NULL,
            omie_id BIGINT UNSIGNED NOT NULL,
            ci VARCHAR(60) NOT NULL DEFAULT '',
            nome VARCHAR(200) NOT NULL DEFAULT '',
            pos INT NOT NULL DEFAULT 0,
            total INT NOT NULL DEFAULT 0,
            antes MEDIUMTEXT NULL,                       -- título lido do Omie logo antes de alterar (JSON normalizado)
            alvo MEDIUMTEXT NOT NULL,                    -- campos como devem ficar (aprovados na prévia)
            fixos MEDIUMTEXT NULL,                       -- desfazer: valores fixos por campo
            payload MEDIUMTEXT NULL,                     -- campos efetivamente enviados
            depois MEDIUMTEXT NULL,                      -- título lido na conferência
            status VARCHAR(15) NOT NULL DEFAULT 'pendente',
            msg TEXT NULL,
            passo TINYINT NOT NULL DEFAULT 0,
            enviado_em INT UNSIGNED NOT NULL DEFAULT 0,
            lido_em INT UNSIGNED NOT NULL DEFAULT 0,
            falhas TINYINT UNSIGNED NOT NULL DEFAULT 0,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            KEY ix_aoi_rod (rodada_id), KEY ix_aoi_tit (omie_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabelas ao_rodadas/ao_itens';
    }
    if (!$tem('ao_receitas')) {
        $pdo->exec("CREATE TABLE ao_receitas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(120) NOT NULL,
            ops MEDIUMTEXT NOT NULL,
            opts MEDIUMTEXT NOT NULL,
            criado_por INT UNSIGNED NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela ao_receitas';
    }
    return $feitos;
}
