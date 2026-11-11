<?php
/* =====================================================================
   busca-bancos/schema.php — tabelas da ferramenta "Busca Bancos".
   Idempotente. Roda sozinho na primeira visita de um admin (como o
   Listas 3C) e também por admin/migrar.php.
   ===================================================================== */
declare(strict_types=1);
require_once __DIR__ . '/lib/Importador.php';   // BbTexto e BbImportador (usados na migração)

const BB_SCHEMA_VERSAO = 6;   // 2: bb_lanc.nota_busca · 3: bb_lanc.sem_par + regra nova de transferência interna · 4/5: cobertura limitada à data de geração do arquivo · 6: bb_envios (11/11/2026)

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
            periodo_ate DATE NOT NULL,                   -- até onde o arquivo cobre DE VERDADE (véspera da geração, se ele foi gerado antes do fim)
            periodo_declarado_ate DATE NULL,             -- o fim que o banco escreveu no arquivo (DTEND)
            gerado_em DATE NULL,                         -- data de geração informada pelo banco (DTSERVER)
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
            nota_busca VARCHAR(600) NOT NULL DEFAULT '',  -- anotação normalizada (entra na busca)
            sem_par TINYINT(1) NOT NULL DEFAULT 0,       -- alguém marcou que não é transferência interna nem par que se anula: nunca parear de novo
            UNIQUE KEY uq_bbl_fp (fp),
            KEY ix_bbl_conta_data (conta_id, data), KEY ix_bbl_data (data), KEY ix_bbl_valor (valor), KEY ix_bbl_doc (doc), KEY ix_bbl_arq (arquivo_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela bb_lanc';
    }
    $col = fn(string $t, string $c) => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t) . " AND COLUMN_NAME = " . $pdo->quote($c))->fetchColumn() > 0;
    if ($tem('bb_lanc') && !$col('bb_lanc', 'nota_busca')) {
        $pdo->exec("ALTER TABLE bb_lanc ADD COLUMN nota_busca VARCHAR(600) NOT NULL DEFAULT '' AFTER nota");
        if (class_exists('BbTexto')) { $up = $pdo->prepare('UPDATE bb_lanc SET nota_busca = ? WHERE id = ?'); foreach ($pdo->query("SELECT id, nota FROM bb_lanc WHERE nota <> ''") as $r) $up->execute([BbTexto::norm($r['nota']), $r['id']]); }
        $feitos[] = 'bb_lanc.nota_busca';
    }
    if (!$tem('bb_envios')) {
        $pdo->exec("CREATE TABLE bb_envios (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            inicio DATETIME NOT NULL,                    -- quando o processamento do arquivo começou
            segundos DECIMAL(8,2) NOT NULL DEFAULT 0,    -- quanto levou
            usuario INT UNSIGNED NULL,
            origem VARCHAR(10) NOT NULL DEFAULT 'upload',
            nome VARCHAR(200) NOT NULL DEFAULT '',
            sha1 CHAR(40) NOT NULL DEFAULT '',
            conta_id INT UNSIGNED NULL,
            arquivo_id INT UNSIGNED NULL,                -- registro em bb_arquivos (o original, quando repetido)
            status VARCHAR(10) NOT NULL,                 -- ok | repetido | erro
            novos INT UNSIGNED NOT NULL DEFAULT 0,
            dup INT UNSIGNED NOT NULL DEFAULT 0,
            erro VARCHAR(300) NULL,
            KEY ix_bbe (inicio)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela bb_envios';
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
    if ($tem('bb_lanc') && !$col('bb_lanc', 'sem_par')) {
        $pdo->exec("ALTER TABLE bb_lanc ADD COLUMN sem_par TINYINT(1) NOT NULL DEFAULT 0 AFTER par_id");
        $feitos[] = 'bb_lanc.sem_par';
    }
    if ($tem('bb_arquivos') && !$col('bb_arquivos', 'periodo_declarado_ate')) {
        $pdo->exec("ALTER TABLE bb_arquivos ADD COLUMN periodo_declarado_ate DATE NULL AFTER periodo_ate, ADD COLUMN gerado_em DATE NULL AFTER periodo_declarado_ate");
        $feitos[] = 'bb_arquivos.periodo_declarado_ate/gerado_em';
    }
    // arquivos já importados que diziam cobrir dias que ainda não tinham acabado quando foram gerados (ex.: extrato de
    // outubro baixado em 14/10 com DTEND 31/10): a cobertura passa a ir só até a véspera — uma vez
    if ($tem('bb_arquivos') && $tem('bb_config') && (int)BbImportador::config($pdo, 'corte_geracao', 0) < 1) {
        $pdo->exec("UPDATE bb_arquivos SET periodo_declarado_ate = periodo_ate WHERE periodo_declarado_ate IS NULL");
        // nunca além da véspera do dia em que o arquivo chegou aqui
        $n2 = $pdo->exec("UPDATE bb_arquivos SET periodo_ate = DATE_SUB(DATE(enviado_em), INTERVAL 1 DAY) WHERE periodo_ate >= DATE(enviado_em)");
        BbImportador::configSet($pdo, 'corte_geracao', 2);
        $feitos[] = "cobertura limitada à data de envio: $n2 arquivo(s)";
    }
    if ($tem('bb_arquivos') && $tem('bb_config') && (int)BbImportador::config($pdo, 'corte_geracao', 0) < 2) {
        // a versão 4 (11/11/2026, ~16h) usou a data do saldo como data de geração — errado: o Sicoob põe ali o dia do último
        // lançamento. Desfaz, mantendo só o limite da data de envio.
        $n1 = $pdo->exec("UPDATE bb_arquivos SET periodo_ate = LEAST(COALESCE(periodo_declarado_ate, periodo_ate), DATE_SUB(DATE(enviado_em), INTERVAL 1 DAY)), gerado_em = NULL
            WHERE gerado_em IS NOT NULL AND gerado_em = saldo_data AND enviado_em < '2026-11-11 15:50:00'");
        // arquivos da pasta do Drive exportados em 14/10/2026 (conferido no Drive: DTSERVER 14/10 nos OFX do Sicoob, modificação
        // 14/10 no do Asaas) que declaram ir além disso — Sicoob outubro (Imobiliária, MZ7, Vertical) e Asaas outubro
        $ids = ['159eA1McYlFIhAb8rW-nSuUjcs-vdZidk', '1HTq22y_UthCwYjjOi93zO5zdrYXTwUnK', '1MdWPZOl3vfpILo5Qk4wGMvorS8miGp5O', '1S9GwSGvj7-2ZJMCUC9vB3MYeVt_nDA1J'];
        $st = $pdo->prepare("UPDATE bb_arquivos SET gerado_em = '2026-10-14', periodo_ate = LEAST(periodo_ate, '2026-10-13')
            WHERE drive_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") AND COALESCE(periodo_declarado_ate, periodo_ate) >= '2026-10-14'");
        $st->execute($ids);
        BbImportador::configSet($pdo, 'corte_geracao', 2);
        $feitos[] = "cobertura: desfeito o corte pela data do saldo em $n1 arquivo(s); " . $st->rowCount() . ' arquivo(s) de outubro exportados em 14/10 limitados a 13/10';
    }
    // regra 2 de transferência interna (os dois lados precisam mostrar que são contas próprias): refaz as marcações uma vez
    if ($tem('bb_lanc') && $tem('bb_config') && (int)BbImportador::config($pdo, 'internas_regra', 1) < 2) {
        $antes = (int)$pdo->query('SELECT COUNT(*) FROM bb_lanc WHERE interna = 1')->fetchColumn();
        $n = BbImportador::refazerInternas($pdo);
        BbImportador::configSet($pdo, 'internas_regra', 2);
        $feitos[] = "transferências internas refeitas com a regra nova ($antes lançamentos marcados antes, " . (2 * $n) . " agora)";
    }
    if (!(int)$pdo->query("SELECT COUNT(*) FROM tools WHERE slug='busca-bancos'")->fetchColumn()) {
        $pdo->prepare("INSERT INTO tools (slug,nome,descricao,icone,caminho,ativo,ordem) VALUES ('busca-bancos','Busca Bancos','Busca unificada nos extratos de todas as contas (entradas e saídas)','🏦','/busca-bancos/',1,33)")->execute();
        $feitos[] = 'tool:busca-bancos';
    }
    return $feitos;
}
