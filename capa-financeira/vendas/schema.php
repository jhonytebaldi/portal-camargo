<?php
/* =====================================================================
   capa-financeira/vendas/schema.php — tabelas do formulário da capa de
   venda (preenchimento em etapas). Chamado por admin/migrar.php
   (idempotente). Retorna a lista do que aplicou.

   cv_vendas       uma venda (capa) — dados da etapa 1 + status das 4 etapas
   cv_compradores  compradores da venda (CPF/CNPJ, e-mail, nascimento, vínculo Omie)
   cv_historico    toda alteração: quem, quando, etapa, campo, antes → depois
   ===================================================================== */
declare(strict_types=1);

function cv_migrar(PDO $pdo): array
{
    $feitos = [];
    $tem = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))->fetchColumn() > 0;

    if (!$tem('cv_vendas')) {
        $etapa = fn(int $n) => "e{$n}_status ENUM('rascunho','concluida','conferida') NOT NULL DEFAULT 'rascunho',
            e{$n}_em DATETIME NULL, e{$n}_por INT UNSIGNED NULL,";
        $pdo->exec("CREATE TABLE cv_vendas (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            empresa VARCHAR(20) NOT NULL DEFAULT '',          -- camargo | vertical (contas da venda)
            status ENUM('andamento','concluida','cancelada') NOT NULL DEFAULT 'andamento',
            " . $etapa(1) . $etapa(2) . $etapa(3) . $etapa(4) . "
            construtora VARCHAR(160) NOT NULL DEFAULT '',
            endereco VARCHAR(200) NOT NULL DEFAULT '',
            bairro VARCHAR(100) NOT NULL DEFAULT '',
            unidade VARCHAR(80) NOT NULL DEFAULT '',
            cod_imovel VARCHAR(20) NOT NULL DEFAULT '',
            status_imovel ENUM('PLANTA','PRONTO') NULL,
            data_venda DATE NULL,
            valor_contrato DECIMAL(14,2) NULL,
            valor_bonus DECIMAL(14,2) NULL,                    -- \"venda maior\": sai do contrato e vira o bônus
            documentacao DECIMAL(14,2) NULL,
            descontos DECIMAL(14,2) NULL,                      -- descontos adicionais
            pct_corretores DECIMAL(8,5) NOT NULL DEFAULT 0.05000,
            pct_fundo DECIMAL(8,5) NOT NULL DEFAULT 0.00500,
            pct_nf DECIMAL(8,5) NOT NULL DEFAULT 0.14000,
            pct_nf_bonus DECIMAL(8,5) NOT NULL DEFAULT 0.14000,
            brinde DECIMAL(10,2) NOT NULL DEFAULT 300.00,
            cesta DECIMAL(10,2) NOT NULL DEFAULT 120.00,
            obs TEXT NULL,
            capa_id INT UNSIGNED NULL,                          -- capa gerada em cf_capas (fases seguintes)
            rev INT UNSIGNED NOT NULL DEFAULT 1,                -- trava otimista: muda a cada gravação
            criado_por INT UNSIGNED NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_por INT UNSIGNED NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_cvv_cod (cod_imovel), KEY ix_cvv_status (status), KEY ix_cvv_construtora (construtora)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela cv_vendas';
    }
    if (!$tem('cv_compradores')) {
        $pdo->exec("CREATE TABLE cv_compradores (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            venda_id INT UNSIGNED NOT NULL,
            ordem TINYINT UNSIGNED NOT NULL DEFAULT 1,
            nome VARCHAR(160) NOT NULL DEFAULT '',
            documento VARCHAR(14) NOT NULL DEFAULT '',         -- CPF (11) ou CNPJ (14), sem pontuação
            email VARCHAR(160) NOT NULL DEFAULT '',
            nascimento DATE NULL,
            omie_codigo BIGINT UNSIGNED NULL,                  -- codigo_cliente_omie na VERTICAL
            omie_nome VARCHAR(160) NULL,
            omie_status VARCHAR(20) NULL,                      -- ok | cadastrado (pelo portal)
            omie_em DATETIME NULL,
            PRIMARY KEY (id),
            KEY ix_cvc_venda (venda_id), KEY ix_cvc_doc (documento),
            CONSTRAINT fk_cvc_venda FOREIGN KEY (venda_id) REFERENCES cv_vendas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela cv_compradores';
    }
    if (!$tem('cv_historico')) {
        $pdo->exec("CREATE TABLE cv_historico (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            venda_id INT UNSIGNED NOT NULL,
            etapa TINYINT UNSIGNED NOT NULL DEFAULT 1,
            acao VARCHAR(30) NOT NULL,                         -- criar | alterar | concluir | omie_cadastro | xlsx ...
            campo VARCHAR(80) NULL,
            antes TEXT NULL,
            depois TEXT NULL,
            user_id INT UNSIGNED NULL,
            user_nome VARCHAR(120) NOT NULL DEFAULT '',
            quando DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_cvh_venda (venda_id, id),
            CONSTRAINT fk_cvh_venda FOREIGN KEY (venda_id) REFERENCES cv_vendas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela cv_historico';
    }
    return $feitos;
}
