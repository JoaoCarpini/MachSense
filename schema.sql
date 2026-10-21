CREATE TABLE IF NOT EXISTS usuarios (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome       VARCHAR(100) NOT NULL,
    usuario    VARCHAR(50)  NOT NULL UNIQUE,
    senha      VARCHAR(255) NOT NULL COMMENT 'Hash da senha gerado pelo PHP (password_hash com PASSWORD_BCRYPT), nunca a senha em texto puro',
    cargo      VARCHAR(80)  NULL,
    criado_em  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS maquinas (
    id                    BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    nome                  VARCHAR(100)  NOT NULL UNIQUE,
    meta_disponibilidade  DECIMAL(5,2)  NOT NULL DEFAULT 85.00,
    ativa                 TINYINT(1)    NOT NULL DEFAULT 1,
    criado_em             TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT ck_maquinas_meta CHECK (meta_disponibilidade BETWEEN 0 AND 100)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS motivos_parada (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    descricao  VARCHAR(50) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS parametros (
    chave  VARCHAR(50)  NOT NULL PRIMARY KEY,
    valor  VARCHAR(100) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS pulsos (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    maquina_id  BIGINT UNSIGNED NOT NULL,
    motivo_id   BIGINT UNSIGNED NULL,
    `timestamp` DATETIME    NOT NULL,
    estado      VARCHAR(10) NOT NULL,
    CONSTRAINT fk_pulsos_maquina FOREIGN KEY (maquina_id) REFERENCES maquinas(id) ON DELETE RESTRICT,
    CONSTRAINT fk_pulsos_motivo  FOREIGN KEY (motivo_id)  REFERENCES motivos_parada(id) ON DELETE RESTRICT,
    CONSTRAINT uq_pulsos_maquina_timestamp UNIQUE (maquina_id, `timestamp`),
    CONSTRAINT ck_pulsos_estado CHECK (estado IN ('rodando', 'parado')),
    CONSTRAINT ck_pulsos_motivo CHECK (
        (estado = 'rodando' AND motivo_id IS NULL) OR
        (estado = 'parado'  AND motivo_id IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE OR REPLACE VIEW vw_indicadores_maquina AS
SELECT
    m.id                                                  AS machine_id,
    m.nome                                                AS maquina,
    m.meta_disponibilidade                                AS meta_pct,
    COUNT(p.id)                                           AS total_pulsos,
    MIN(p.`timestamp`)                                    AS primeiro_pulso,
    MAX(p.`timestamp`)                                    AS ultimo_pulso,
    SUM(p.estado = 'rodando') * i.minutos                 AS tempo_produzindo_min,
    SUM(p.estado = 'parado')  * i.minutos                 AS tempo_parado_min,
    COUNT(p.id) * i.minutos                               AS tempo_total_min,
    ROUND(100.0 * SUM(p.estado = 'rodando') / NULLIF(COUNT(p.id), 0), 2) AS disponibilidade_pct,
    CASE
        WHEN COUNT(p.id) = 0 THEN NULL
        WHEN 100.0 * SUM(p.estado = 'rodando') / COUNT(p.id) >= m.meta_disponibilidade THEN 1
        ELSE 0
    END                                                   AS atingiu_meta
FROM maquinas m
CROSS JOIN (SELECT CAST(valor AS UNSIGNED) AS minutos FROM parametros WHERE chave = 'intervalo_pulso_min') i
LEFT JOIN pulsos p ON p.maquina_id = m.id
GROUP BY m.id, m.nome, m.meta_disponibilidade, i.minutos;

CREATE OR REPLACE VIEW vw_paradas_por_motivo AS
SELECT
    m.id                    AS machine_id,
    m.nome                  AS maquina,
    mp.descricao            AS motivo,
    COUNT(p.id) * i.minutos AS tempo_parado_min
FROM maquinas m
CROSS JOIN motivos_parada mp
CROSS JOIN (SELECT CAST(valor AS UNSIGNED) AS minutos FROM parametros WHERE chave = 'intervalo_pulso_min') i
LEFT JOIN pulsos p ON p.maquina_id = m.id AND p.motivo_id = mp.id AND p.estado = 'parado'
GROUP BY m.id, m.nome, mp.id, mp.descricao, i.minutos;

INSERT IGNORE INTO motivos_parada (descricao) VALUES
    ('almoco'),
    ('furo_processo'),
    ('manutencao'),
    ('setup');

INSERT IGNORE INTO parametros (chave, valor) VALUES
    ('intervalo_pulso_min', '5');

INSERT IGNORE INTO maquinas (nome, meta_disponibilidade) VALUES
    ('Máquina 1', 85.00),
    ('Máquina 2', 85.00),
    ('Máquina 3', 85.00);

INSERT IGNORE INTO usuarios (nome, usuario, senha) VALUES
    ('Administrador', 'admin', '$2b$10$osa8rWt9zcoUwoY9PBFRI.BqcSqfCUBiTrDjXkgt9.9q2CxF02AoW');