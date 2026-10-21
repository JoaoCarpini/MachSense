-- ==================================================================
-- MachSense — DADOS DE DEMONSTRAÇÃO (MariaDB 10.2+)
--
-- Somente para apresentação/testes. NÃO faz parte do schema.sql.
-- Gera pulsos de 5 em 5 minutos, das 08:00 às 18:00, nos últimos
-- 7 dias (incluindo hoje, até o horário atual), para as 3 primeiras
-- máquinas ativas. Cada período de parada tem um único motivo.
-- Usa INSERT IGNORE: pode ser executado novamente sem duplicar.
--
-- Rode depois do schema.sql, no banco machsense.
-- ==================================================================

INSERT IGNORE INTO pulsos (maquina_id, motivo_id, `timestamp`, estado)
WITH RECURSIVE
    dias AS (
        SELECT 0 AS d
        UNION ALL
        SELECT d + 1 FROM dias WHERE d < 6
    ),
    -- 121 pulsos por dia: slot 0 = 08:00, slot 120 = 18:00
    slots AS (
        SELECT 0 AS s
        UNION ALL
        SELECT s + 1 FROM slots WHERE s < 120
    ),
    maqs AS (
        SELECT id, ROW_NUMBER() OVER (ORDER BY id) AS n
          FROM maquinas
         WHERE ativa = 1
         ORDER BY id
         LIMIT 3
    ),
    base AS (
        SELECT mq.id AS maquina_id,
               mq.n,
               dias.d,
               slots.s,
               CURDATE() - INTERVAL dias.d DAY + INTERVAL 8 HOUR + INTERVAL (slots.s * 5) MINUTE AS ts
          FROM maqs mq
         CROSS JOIN dias
         CROSS JOIN slots
    ),
    classificados AS (
        SELECT maquina_id, ts,
               CASE
                   -- almoço para todas: 12:00–12:55
                   WHEN s BETWEEN 48 AND 59 THEN 'almoco'
                   -- setup no início do turno em dias alternados (exceto máquina 1): 08:00–08:25
                   WHEN s BETWEEN 0 AND 5 AND n <> 1 AND (d + n) % 2 = 0 THEN 'setup'
                   -- furo de processo curto pela manhã (máquina 1 só em dias ímpares)
                   WHEN (n <> 1 OR d % 2 = 1)
                        AND s BETWEEN 18 + (d * 7 + n * 5) % 20
                              AND 18 + (d * 7 + n * 5) % 20 + 2 + (d + n) % 3 THEN 'furo_processo'
                   -- manutenção à tarde, mais longa na máquina 2
                   WHEN (d + n) % 3 = 0
                        AND s BETWEEN 72 AND 78 + IF(n = 2, 12, 0) THEN 'manutencao'
                   ELSE NULL
               END AS motivo
          FROM base
    )
SELECT c.maquina_id,
       mp.id,
       c.ts,
       IF(c.motivo IS NULL, 'rodando', 'parado')
  FROM classificados c
  LEFT JOIN motivos_parada mp ON mp.descricao = c.motivo
 WHERE c.ts <= NOW();
