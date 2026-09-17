<?php
/* =====================================================================
   capa-financeira/schema.php — tabelas do módulo Capa Financeira.
   Chamado por admin/migrar.php (idempotente: CREATE TABLE IF NOT EXISTS
   + colunas conferidas). Retorna a lista do que aplicou.
   ===================================================================== */
declare(strict_types=1);

function cf_migrar(PDO $pdo): array
{
    $feitos = [];
    $tem = fn(string $t) => (int)$pdo->query("SELECT COUNT(*) FROM information_schema.TABLES
        WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($t))->fetchColumn() > 0;

    if (!$tem('cf_capas')) {
        $pdo->exec("CREATE TABLE cf_capas (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            cod VARCHAR(20) NULL,                       -- código do imóvel (Robust), B6 da capa
            versao INT UNSIGNED NOT NULL DEFAULT 1,     -- reenvios da mesma capa (mesmo cod)
            empresa VARCHAR(20) NOT NULL DEFAULT '',    -- vertical | camargo (escolhida no envio)
            cliente VARCHAR(255) NOT NULL DEFAULT '',
            construtora VARCHAR(255) NOT NULL DEFAULT '',
            bairro VARCHAR(120) NOT NULL DEFAULT '',
            unidade VARCHAR(255) NOT NULL DEFAULT '',
            data_venda DATE NULL,
            valor_contrato DECIMAL(14,2) NULL,
            vgv DECIMAL(14,2) NULL,
            valor_bonus DECIMAL(14,2) NULL,
            obs_capa VARCHAR(255) NULL,
            arquivo_nome VARCHAR(255) NOT NULL,
            arquivo_path VARCHAR(255) NOT NULL,          -- fora do public_html
            sha256 CHAR(16) NOT NULL,
            capa_flags JSON NULL,
            total_pagar DECIMAL(14,2) NOT NULL DEFAULT 0,
            total_receber DECIMAL(14,2) NOT NULL DEFAULT 0,
            status ENUM('revisao','confirmada','substituida','descartada') NOT NULL DEFAULT 'revisao',
            capa_anterior_id INT UNSIGNED NULL,          -- versão anterior (mesmo cod) que esta substitui
            enviado_por INT UNSIGNED NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            confirmado_em DATETIME NULL,
            confirmado_por INT UNSIGNED NULL,
            PRIMARY KEY (id),
            KEY ix_cfc_cod (cod), KEY ix_cfc_sha (sha256), KEY ix_cfc_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela cf_capas';
    }
    if (!$tem('cf_lancamentos')) {
        $pdo->exec("CREATE TABLE cf_lancamentos (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            capa_id INT UNSIGNED NOT NULL,
            linha_xlsx INT UNSIGNED NOT NULL,
            tipo ENUM('P','R') NOT NULL,                 -- P = a pagar, R = a receber
            ordinal INT UNSIGNED NOT NULL DEFAULT 1,     -- posição dentro do grupo (favorecido+função+natureza)
            chave_estavel CHAR(12) NOT NULL,
            chave_exata CHAR(12) NOT NULL,
            -- extraído da capa (nunca alterado; serve de auditoria)
            data_raw VARCHAR(40) NULL,
            cf_raw VARCHAR(160) NOT NULL DEFAULT '',
            favorecido_hist VARCHAR(160) NULL,
            funcao_raw VARCHAR(40) NULL,
            natureza_raw VARCHAR(20) NULL,
            clientes_hist VARCHAR(255) NULL,
            construtora_hist VARCHAR(255) NULL,
            unidade VARCHAR(255) NULL,
            status_imovel VARCHAR(10) NULL,
            data_venda_hist VARCHAR(20) NULL,
            col_g VARCHAR(120) NULL,
            prefixo VARCHAR(120) NULL,
            valor DECIMAL(14,2) NOT NULL,
            parcela SMALLINT UNSIGNED NULL,
            total_parcelas SMALLINT UNSIGNED NULL,
            rotulo_fluxo VARCHAR(40) NULL,
            flags JSON NULL,
            -- decidido na revisão (o que vai para o Omie)
            data_prevista DATE NULL,
            pessoa_id INT UNSIGNED NULL,
            funcao VARCHAR(40) NULL,
            natureza VARCHAR(20) NULL,
            categoria VARCHAR(70) NULL,
            nota_fiscal VARCHAR(20) NULL,
            condicao VARCHAR(120) NULL,
            revisado TINYINT(1) NOT NULL DEFAULT 0,      -- alertas graves conferidos pelo usuário
            status ENUM('revisao','confirmado','removido','substituido','exportado') NOT NULL DEFAULT 'revisao',
            codigo_integracao VARCHAR(20) NULL,
            dup_tipo VARCHAR(30) NULL,                    -- IGUAL | ALTERADA | NOVA | POSSIVEL_DUPLICATA
            dup_de INT UNSIGNED NULL,                     -- lançamento anterior relacionado
            alteracao_pos_exportacao TINYINT(1) NOT NULL DEFAULT 0,
            exportacao_id INT UNSIGNED NULL,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY ix_cfl_capa (capa_id), KEY ix_cfl_estavel (chave_estavel), KEY ix_cfl_exata (chave_exata),
            KEY ix_cfl_status (status), KEY ix_cfl_pessoa (pessoa_id), KEY ix_cfl_codint (codigo_integracao),
            CONSTRAINT fk_cfl_capa FOREIGN KEY (capa_id) REFERENCES cf_capas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela cf_lancamentos';
    }
    if (!$tem('cf_pessoas')) {
        $pdo->exec("CREATE TABLE cf_pessoas (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            nome VARCHAR(160) NOT NULL,                  -- nome como deve aparecer (recibo)
            nome_key VARCHAR(160) NOT NULL,              -- normalizado (sem acento, maiúsculas)
            razao_social VARCHAR(160) NULL,              -- empresa do corretor (fornecedor no Omie)
            cnpj VARCHAR(20) NULL,
            cpf VARCHAR(14) NULL,
            pagar_por ENUM('CNPJ','CPF') NOT NULL DEFAULT 'CNPJ',
            departamento_omie VARCHAR(100) NULL,
            conta_vertical VARCHAR(60) NULL,             -- conta corrente padrão no Omie (por empresa)
            conta_camargo VARCHAR(60) NULL,
            chave_pix VARCHAR(120) NULL,                 -- chave Pix padrão de pagamento
            broker_id VARCHAR(40) NULL,                  -- vínculo com brokers (portal) p/ o corretor baixar o recibo
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_cfp_key (nome_key)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $pdo->exec("CREATE TABLE cf_pessoa_aliases (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            pessoa_id INT UNSIGNED NOT NULL,
            alias VARCHAR(160) NOT NULL,
            alias_key VARCHAR(160) NOT NULL,
            PRIMARY KEY (id),
            UNIQUE KEY uq_cfa_key (alias_key),
            CONSTRAINT fk_cfa_pessoa FOREIGN KEY (pessoa_id) REFERENCES cf_pessoas(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabelas cf_pessoas/cf_pessoa_aliases';
    }
    if (!$tem('cf_config')) {
        $pdo->exec("CREATE TABLE cf_config (
            chave VARCHAR(60) NOT NULL,
            valor MEDIUMTEXT NOT NULL,
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (chave)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela cf_config';
    }
    if (!$tem('cf_sequencias')) {
        $pdo->exec("CREATE TABLE cf_sequencias (
            cod VARCHAR(20) NOT NULL,
            tipo CHAR(1) NOT NULL,
            ultimo INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY (cod, tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela cf_sequencias';
    }
    // colunas acrescentadas depois da 1ª versão
    $colExiste = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?");
        $st->execute([$t, $c]); return (int)$st->fetchColumn() > 0;
    };
    if (!$colExiste('cf_lancamentos', 'chave_pix')) {
        $pdo->exec("ALTER TABLE cf_lancamentos ADD COLUMN chave_pix VARCHAR(120) NULL AFTER condicao");
        $feitos[] = 'cf_lancamentos.chave_pix';
        // capas já em revisão herdam a chave padrão da pessoa
        $pdo->exec("UPDATE cf_lancamentos l JOIN cf_pessoas p ON p.id = l.pessoa_id SET l.chave_pix = p.chave_pix
                    WHERE l.status = 'revisao' AND (l.chave_pix IS NULL OR l.chave_pix = '') AND p.chave_pix IS NOT NULL AND p.chave_pix <> ''");
    }
    // fase 2 — exportação para o Omie
    if (!$colExiste('cf_lancamentos', 'conta_corrente')) {
        $pdo->exec("ALTER TABLE cf_lancamentos ADD COLUMN conta_corrente VARCHAR(60) NULL AFTER chave_pix, ADD COLUMN cliente_omie VARCHAR(60) NULL AFTER conta_corrente");
        $feitos[] = 'cf_lancamentos.conta_corrente/cliente_omie';
    }
    if (!$colExiste('cf_lancamentos', 'snapshot_exp')) {
        $pdo->exec("ALTER TABLE cf_lancamentos ADD COLUMN snapshot_exp JSON NULL AFTER exportacao_id");
        $feitos[] = 'cf_lancamentos.snapshot_exp';
    }
    if (!$tem('cf_exportacoes')) {
        $pdo->exec("CREATE TABLE cf_exportacoes (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            tipo CHAR(1) NOT NULL,
            empresa VARCHAR(20) NOT NULL,
            arquivo_nome VARCHAR(200) NOT NULL,
            arquivo_path VARCHAR(400) NOT NULL,
            n_linhas INT UNSIGNED NOT NULL DEFAULT 0,
            total DECIMAL(14,2) NOT NULL DEFAULT 0,
            data_registro DATE NULL,
            avisos JSON NULL,
            status ENUM('gerada','desfeita') NOT NULL DEFAULT 'gerada',
            gerado_por INT UNSIGNED NULL,
            gerado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY ix_cfe_emp (empresa, tipo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela cf_exportacoes';
    }
    // fase 3 — recibos
    if (!$tem('cf_recibos')) {
        $pdo->exec("CREATE TABLE cf_recibos (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            numero VARCHAR(120) NOT NULL,                 -- código(s) de integração impresso(s)
            pessoa_id INT UNSIGNED NOT NULL,
            empresa VARCHAR(20) NOT NULL,
            lancamento_ids JSON NOT NULL,
            valor DECIMAL(14,2) NOT NULL,
            data_recibo DATE NOT NULL,
            arquivo_nome VARCHAR(200) NOT NULL,
            arquivo_path VARCHAR(400) NOT NULL,
            status ENUM('atual','substituido') NOT NULL DEFAULT 'atual',
            gerado_por INT UNSIGNED NULL,
            gerado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY ix_cfr_pessoa (pessoa_id), KEY ix_cfr_status (status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $feitos[] = 'tabela cf_recibos';
    }
    if (!$colExiste('cf_lancamentos', 'recibo_id')) {
        $pdo->exec("ALTER TABLE cf_lancamentos ADD COLUMN recibo_id INT UNSIGNED NULL AFTER exportacao_id");
        $feitos[] = 'cf_lancamentos.recibo_id';
    }
    // normaliza chaves Pix importadas cruas (uma vez; só as válidas)
    if (!$tem('cf_config') || (int)$pdo->query("SELECT COUNT(*) FROM cf_config WHERE chave='pix_normalizado'")->fetchColumn() === 0) {
        require_once __DIR__ . '/lib/Pix.php';
        $n = 0;
        foreach ($pdo->query("SELECT id, chave_pix FROM cf_pessoas WHERE chave_pix IS NOT NULL AND chave_pix <> ''")->fetchAll() as $r) {
            $a = Pix::analisar((string)$r['chave_pix']);
            if ($a['valido'] && $a['valor'] !== null && $a['valor'] !== $r['chave_pix']) { $pdo->prepare('UPDATE cf_pessoas SET chave_pix = ? WHERE id = ?')->execute([$a['valor'], $r['id']]); $n++; }
        }
        $pdo->prepare("INSERT IGNORE INTO cf_config (chave, valor) VALUES ('pix_normalizado', '1')")->execute();
        if ($n) $feitos[] = "chaves pix normalizadas ($n)";
    }
    // registro da ferramenta
    $existe = (int)$pdo->query("SELECT COUNT(*) FROM tools WHERE slug='capa-financeira'")->fetchColumn();
    if (!$existe) {
        $pdo->prepare("INSERT INTO tools (slug,nome,descricao,icone,caminho,ativo,ordem)
            VALUES ('capa-financeira','Capa Financeira → Omie','Contas a pagar/receber e recibos a partir das capas financeiras','🧾','/capa-financeira/',1,30)")
            ->execute();
        $feitos[] = 'tool:capa-financeira';
    }
    // configurações padrão (só cria se não existir; nunca sobrescreve)
    $ins = $pdo->prepare('INSERT IGNORE INTO cf_config (chave, valor) VALUES (?, ?)');
    $padroes = [
        'categorias' => json_encode([
            'CORRETOR' => 'Comissao de Corretor', 'CAPTADOR' => 'Comissao de Captador', 'DIRETOR' => 'Comissao de Diretor',
            'COORDENADOR' => 'Comissao de Gerente', 'INTEGRACAO' => 'Comissao de Gerente', 'PRE VENDA' => 'Comissao de Pre-venda',
            'PRE-VENDA' => 'Comissao de Pre-venda', 'FINANCEIRO' => 'Ajuda de Custo', 'SAC' => 'Ajuda de Custo',
            'BONUS' => 'Repasse de bonificaçao',
            'RECEBER_NOVO' => 'Comissao sobre venda de imovel novo', 'RECEBER_USADO' => 'Comissao sobre venda de imovel usado',
            'RECEBER_BONUS' => 'Recebidos de bonificaçao para repasse',
        ], JSON_UNESCAPED_UNICODE),
        'nf_dict' => json_encode(CapaParser::NF_DICT_PADRAO, JSON_UNESCAPED_UNICODE),
        'empresas' => json_encode([
            ['id' => 'vertical', 'nome' => 'VERTICAL GESTAO DE IMOVEIS', 'razao' => 'MICHAEL BRUNO DA CRUZ CAMARGO GESTAO ADMINISTRATIVA', 'cnpj' => '41.679.475/0001-33', 'conta_padrao' => ''],
            ['id' => 'camargo',  'nome' => 'IMOBILIARIA CAMARGO', 'razao' => 'IMOBILIARIA CAMARGO', 'cnpj' => '28.987.418/0001-53', 'conta_padrao' => ''],
        ], JSON_UNESCAPED_UNICODE),
        'corretor_baixa_recibo' => '1',
        // nomes das contas correntes cadastradas no Omie (sugestões na exportação; edite em Configurações)
        'contas_omie' => json_encode(['vertical' => ['Sicredi', 'Banco Inter', 'Sicoob', 'Caixinha', 'Omie.CASH'], 'camargo' => ['Sicredi', 'Banco Inter', 'Caixa Econômica Federal', 'Caixinha', 'Omie.CASH']], JSON_UNESCAPED_UNICODE),
    ];
    foreach ($padroes as $k => $v) { $ins->execute([$k, $v]); if ($ins->rowCount()) $feitos[] = "config:$k"; }
    return $feitos;
}
