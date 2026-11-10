<?php
/* =====================================================================
   busca-bancos/schema.php — tabelas da ferramenta "Busca Bancos".
   Idempotente. Roda sozinho na primeira visita de um admin (como o
   Listas 3C) e também por admin/migrar.php.
   ===================================================================== */
declare(strict_types=1);

const BB_SCHEMA_VERSAO = 1;

function bb_instalar_se_preciso(): bool
{
    if (PHP_SAPI !== 'cli') {
        if (!is_admin()) return false;
        if (($_SESSION['bb_schema'] ?? 0) === BB_SCHEMA_VERSAO) return false;
    }
    try { $feitos = bb_migrar(db()); }
    catch (Throwable $e) { error_log('busca-bancos: instalação automática falhou: ' . $e->getMessage()); return false; }
    if (PHP_SAPI !== 'cli') $_SESSION['bb_schema'] = BB_SCHEMA_VERSAO;
    return (bool)$feitos;
}

function bb_migrar(PDO $pdo): array
{
    $feitos = [];
    $tem = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))->fetchColumn() > 0;

    if (!$tem('bb_contas')) {
        $pdo->exec("CREATE TABLE bb_contas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            banco VARCHAR(20) NOT NULL,                  -- sicoob | sicredi | caixa | asaas | inter | omie-cash | outro
            bank_id VARCHAR(20) NOT NULL DEFAULT '',     -- BANKID do OFX
            agencia VARCHAR(20) NOT NULL DEFAULT '',
            conta VARCHAR(40) NOT NULL,                  -- ACCTID do OFX
            empresa VARCHAR(20) NOT NULL DEFAULT '',     -- camargo | vertical | mz7 ('' = ainda não informada)
            apelido VARCHAR(80) NOT NULL DEFAULT '',
            omie_cc BIGINT UNSIGNED NULL,                -- nCodCC da conta corrente no Omie (cruzamento)
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_bbc (bank_id, conta)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela bb_contas';
    }
    if (!$tem('bb_arquivos')) {
        $pdo->exec("CREATE TABLE bb_arquivos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conta_id INT UNSIGNED NOT NULL,
            sha1 CHAR(40) NOT NULL,                      -- conteúdo do arquivo (+ conta): o mesmo arquivo nunca entra 2x
            nome VARCHAR(200) NOT NULL DEFAULT '',
            origem VARCHAR(10) NOT NULL DEFAULT 'upload', -- upload | drive | pdf
            formato VARCHAR(10) NOT NULL DEFAULT 'ofx',
            drive_id VARCHAR(80) NULL,
            drive_caminho VARCHAR(300) NULL,
            periodo_de DATE NOT NULL,
            periodo_ate DATE NOT NULL,
            saldo_final DECIMAL(14,2) NULL,
            saldo_data DATE NULL,
            n_total INT UNSIGNED NOT NULL DEFAULT 0,
            n_novos INT UNSIGNED NOT NULL DEFAULT 0,
            n_dup INT UNSIGNED NOT NULL DEFAULT 0,
            enviado_por INT UNSIGNED NULL,
            enviado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uq_bba (conta_id, sha1), KEY ix_bba_periodo (conta_id, periodo_de, periodo_ate)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela bb_arquivos';
    }
    if (!$tem('bb_lanc')) {
        $pdo->exec("CREATE TABLE bb_lanc (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conta_id INT UNSIGNED NOT NULL,
            arquivo_id INT UNSIGNED NOT NULL,
            data DATE NOT NULL,
            valor DECIMAL(14,2) NOT NULL,                -- positivo = entrada, negativo = saída
            tipo_ofx VARCHAR(20) NOT NULL DEFAULT '',
            categoria VARCHAR(16) NOT NULL DEFAULT 'outro', -- pix | ted | boleto | tarifa | cartao | outro
            fitid VARCHAR(80) NOT NULL DEFAULT '',
            checknum VARCHAR(40) NOT NULL DEFAULT '',
            refnum VARCHAR(40) NOT NULL DEFAULT '',
            memo VARCHAR(400) NOT NULL DEFAULT '',       -- como veio do banco
            nome VARCHAR(200) NOT NULL DEFAULT '',       -- como veio do banco
            doc VARCHAR(14) NOT NULL DEFAULT '',         -- CPF/CNPJ extraído (só dígitos), quando o banco informa
            contraparte VARCHAR(200) NOT NULL DEFAULT '',-- nome extraído
            busca VARCHAR(800) NOT NULL DEFAULT '',      -- texto normalizado para a busca
            fp CHAR(40) NOT NULL,                        -- impressão digital (anti-duplicado)
            anulada TINYINT(1) NOT NULL DEFAULT 0,       -- tarifa+desconto / pix+estorno que se anulam
            interna TINYINT(1) NOT NULL DEFAULT 0,       -- transferência entre contas próprias
            par_id INT UNSIGNED NULL,                    -- o outro lado (anulada/interna)
            conferido TINYINT(1) NOT NULL DEFAULT 0,
            conferido_por INT UNSIGNED NULL,
            conferido_em DATETIME NULL,
            nota VARCHAR(500) NOT NULL DEFAULT '',
            UNIQUE KEY uq_bbl_fp (fp),
            KEY ix_bbl_conta_data (conta_id, data), KEY ix_bbl_data (data), KEY ix_bbl_valor (valor), KEY ix_bbl_doc (doc), KEY ix_bbl_arq (arquivo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela bb_lanc';
    }
    if (!$tem('bb_config')) {
        $pdo->exec("CREATE TABLE bb_config (chave VARCHAR(60) PRIMARY KEY, valor MEDIUMTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela bb_config';
    }
    if (!$tem('bb_conf')) {
        $pdo->exec("CREATE TABLE bb_conf (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(200) NOT NULL,
            parametros TEXT NOT NULL,                    -- JSON: tolerâncias, colunas, filtros
            criado_por INT UNSIGNED NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE bb_conf_linhas (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            conf_id INT UNSIGNED NOT NULL,
            linha INT UNSIGNED NOT NULL,                 -- nº da linha na planilha
            data DATE NULL,
            valor DECIMAL(14,2) NOT NULL,
            texto VARCHAR(400) NOT NULL DEFAULT '',
            original TEXT NOT NULL,                      -- JSON da linha como veio
            grupo VARCHAR(160) NOT NULL DEFAULT '',      -- venda / cliente (coluna de agrupamento da planilha)
            banco_planilha VARCHAR(60) NOT NULL DEFAULT '', -- banco anotado na planilha (só informativo: pode estar errado)
            previsto TINYINT(1) NOT NULL DEFAULT 0,      -- 1 = na planilha estava só como a receber/a pagar
            status VARCHAR(12) NOT NULL,                 -- ok | ambigua | nao | sem_dados | pend_achou | pendente
            lanc_id INT UNSIGNED NULL,
            candidatos TEXT NULL,                        -- JSON [ids]
            motivo VARCHAR(200) NOT NULL DEFAULT '',
            KEY ix_bbcl (conf_id, status), KEY ix_bbcl_g (conf_id, grupo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabelas bb_conf/bb_conf_linhas';
    }
    if (!(int)$pdo->query("SELECT COUNT(*) FROM tools WHERE slug='busca-bancos'")->fetchColumn()) {
        $pdo->prepare("INSERT INTO tools (slug,nome,descricao,icone,caminho,ativo,ordem) VALUES ('busca-bancos','Busca Bancos','Busca unificada nos extratos de todas as contas (entradas e saídas)','🏦','/busca-bancos/',1,33)")->execute();
        $feitos[] = 'tool:busca-bancos';
    }
    return $feitos;
}
