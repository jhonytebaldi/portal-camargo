<?php
/* =====================================================================
   recibos-omie/schema.php — tabelas da ferramenta "Recibos do Omie".
   Chamado por admin/migrar.php (idempotente).
   ===================================================================== */
declare(strict_types=1);

function ro_migrar(PDO $pdo): array
{
    $feitos = [];
    $tem = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))->fetchColumn() > 0;
    if (!$tem('ro_recibos')) {
        $pdo->exec("CREATE TABLE ro_recibos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conta VARCHAR(20) NOT NULL,                  -- vertical | camargo | mz7
            numero VARCHAR(120) NOT NULL,                -- nº impresso no recibo
            fingerprints JSON NOT NULL,                  -- identidade dos títulos (omie:conta:id ou fp:hash)
            omie_ids JSON NULL,
            fornecedor_doc VARCHAR(20) NOT NULL DEFAULT '',
            fornecedor_nome VARCHAR(160) NOT NULL DEFAULT '',
            pessoa_id INT UNSIGNED NULL,
            valor DECIMAL(14,2) NOT NULL,
            data_recibo DATE NOT NULL,
            itens JSON NOT NULL,                         -- títulos como foram impressos
            origem VARCHAR(10) NOT NULL DEFAULT 'xlsx',  -- xlsx | api
            validado VARCHAR(40) NULL,                   -- situação no Omie no momento da geração
            arquivo_nome VARCHAR(200) NOT NULL,
            arquivo_path VARCHAR(400) NOT NULL,
            status ENUM('atual','substituido') NOT NULL DEFAULT 'atual',
            gerado_por INT UNSIGNED NULL,
            gerado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY ix_ror_conta (conta), KEY ix_ror_doc (fornecedor_doc), KEY ix_ror_pessoa (pessoa_id), KEY ix_ror_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE ro_recibo_itens (
            recibo_id INT UNSIGNED NOT NULL,
            fingerprint VARCHAR(60) NOT NULL,
            PRIMARY KEY (recibo_id, fingerprint), KEY ix_roi_fp (fingerprint)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabelas ro_recibos/ro_recibo_itens';
    }
    if (!$tem('ro_fornecedores')) {
        $pdo->exec("CREATE TABLE ro_fornecedores (
            conta VARCHAR(20) NOT NULL, codigo_omie BIGINT UNSIGNED NOT NULL,
            razao_social VARCHAR(160) NOT NULL DEFAULT '', nome_fantasia VARCHAR(160) NOT NULL DEFAULT '', cnpj_cpf VARCHAR(20) NOT NULL DEFAULT '',
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (conta, codigo_omie)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE ro_categorias (
            conta VARCHAR(20) NOT NULL, codigo VARCHAR(20) NOT NULL, descricao VARCHAR(120) NOT NULL DEFAULT '',
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (conta, codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabelas ro_fornecedores/ro_categorias';
    }
    if (!(int)$pdo->query("SELECT COUNT(*) FROM tools WHERE slug='recibos-omie'")->fetchColumn()) {
        $pdo->prepare("INSERT INTO tools (slug,nome,descricao,icone,caminho,ativo,ordem) VALUES ('recibos-omie','Recibos do Omie','Recibos de comissão a partir das contas a pagar do Omie (relatório ou API)','🧾','/recibos-omie/',1,31)")->execute();
        $feitos[] = 'tool:recibos-omie';
    }
    return $feitos;
}
