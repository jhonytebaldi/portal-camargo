-- =====================================================================
-- Portal dados.imobcamargo.com.br — esquema do banco (MySQL)
-- Cuida de ACESSO (usuários, equipes, ferramentas, permissões).
-- Os dados operacionais de cada ferramenta continuam nos arquivos de cada
-- módulo (base da busca, presenca.json do painel, etc.).
-- Charset utf8mb4 para acentos/emojis.
-- =====================================================================
SET NAMES utf8mb4;
SET time_zone = '-03:00';

-- --- Usuários do portal -------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome        VARCHAR(120) NOT NULL,
  login       VARCHAR(120) NOT NULL,           -- e-mail ou usuário
  senha_hash  VARCHAR(255) NOT NULL,           -- password_hash()
  papel       ENUM('admin','gestor','viewer') NOT NULL DEFAULT 'viewer',
  ativo       TINYINT(1)   NOT NULL DEFAULT 1,
  broker_id   VARCHAR(40)  NULL,               -- corretor (GHL) vinculado a este usuário
  ativacao_token VARCHAR(64) NULL,             -- token para definir senha no 1º acesso
  criado_em   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_login (login),
  KEY ix_users_broker (broker_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Ferramentas (registro de módulos do portal) ------------------------
CREATE TABLE IF NOT EXISTS tools (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  slug        VARCHAR(60)  NOT NULL,           -- ex.: 'busca', 'painel-corretores'
  nome        VARCHAR(120) NOT NULL,
  descricao   VARCHAR(255) NOT NULL DEFAULT '',
  icone       VARCHAR(40)  NOT NULL DEFAULT '',-- nome de ícone/emoji
  caminho     VARCHAR(120) NOT NULL,           -- ex.: '/busca/' (relativo à raiz do portal)
  ativo       TINYINT(1)   NOT NULL DEFAULT 1,
  ordem       INT          NOT NULL DEFAULT 100,
  PRIMARY KEY (id),
  UNIQUE KEY uq_tools_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Nível 1: quais ferramentas cada usuário pode abrir -----------------
CREATE TABLE IF NOT EXISTS user_tools (
  user_id INT UNSIGNED NOT NULL,
  tool_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, tool_id),
  CONSTRAINT fk_ut_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_ut_tool FOREIGN KEY (tool_id) REFERENCES tools(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- =====================================================================
-- Escopo interno (Nível 2) — específico do módulo Painel dos Corretores
-- =====================================================================

-- Corretores (espelho dos usuários do GHL; sincronizável)
CREATE TABLE IF NOT EXISTS brokers (
  id     VARCHAR(40)  NOT NULL,               -- userId do GHL
  nome   VARCHAR(160) NOT NULL,
  email  VARCHAR(190) NOT NULL DEFAULT '',
  ativo  TINYINT(1)   NOT NULL DEFAULT 1,
  PRIMARY KEY (id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Equipes
CREATE TABLE IF NOT EXISTS teams (
  id        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  nome      VARCHAR(120) NOT NULL,
  criado_em DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_teams_nome (nome)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Corretor em equipe (N:N — um corretor pode estar em várias equipes)
CREATE TABLE IF NOT EXISTS team_brokers (
  team_id   INT UNSIGNED NOT NULL,
  broker_id VARCHAR(40)  NOT NULL,
  PRIMARY KEY (team_id, broker_id),
  CONSTRAINT fk_tb_team   FOREIGN KEY (team_id)   REFERENCES teams(id)   ON DELETE CASCADE,
  CONSTRAINT fk_tb_broker FOREIGN KEY (broker_id) REFERENCES brokers(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- Quais equipes cada usuário pode VER no painel (Nível 2)
CREATE TABLE IF NOT EXISTS user_teams (
  user_id INT UNSIGNED NOT NULL,
  team_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (user_id, team_id),
  CONSTRAINT fk_uteam_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_uteam_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- --- Controle de tentativas de login (anti força-bruta) -----------------
CREATE TABLE IF NOT EXISTS login_attempts (
  ip_hash CHAR(64)     NOT NULL,
  n       INT          NOT NULL DEFAULT 0,
  ate     INT          NOT NULL DEFAULT 0,     -- bloqueado até (epoch)
  visto   INT          NOT NULL DEFAULT 0,     -- último acesso (epoch)
  PRIMARY KEY (ip_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
