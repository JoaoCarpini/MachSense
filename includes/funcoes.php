<?php

/* ------------------------------------------------------------------
 * FORMATAÇÃO
 * ------------------------------------------------------------------ */

function formatarMinutos(int $minutos): string
{
    return sprintf('%02d:%02dh', intdiv($minutos, 60), $minutos % 60);
}

function formatarPct(?float $pct): string
{
    return $pct === null ? '—' : number_format($pct, 1, ',', '.') . '%';
}

function formatarDataHora(?string $dataHora): string
{
    return $dataHora === null ? '—' : date('d/m/Y H:i', strtotime($dataHora));
}

/** Os motivos são gravados como identificadores (ex.: furo_processo). */
function nomeMotivo(string $motivo): string
{
    $nomes = [
        'almoco'        => 'Almoço',
        'furo_processo' => 'Furo de processo',
        'manutencao'    => 'Manutenção',
        'setup'         => 'Setup',
    ];

    return $nomes[$motivo] ?? ucfirst(str_replace('_', ' ', $motivo));
}

/** Cor da disponibilidade em relação à meta da máquina. */
function classeDisponibilidade(?float $pct, float $meta): string
{
    if ($pct === null) return 'danger';
    if ($pct >= $meta) return 'ok';
    if ($pct >= $meta - 15) return 'warn';
    return 'danger';
}

/* ------------------------------------------------------------------
 * SEGURANÇA DE FORMULÁRIOS (CSRF) E AVISOS
 * ------------------------------------------------------------------ */

function csrfToken(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrfValido(): bool
{
    return is_string($_POST['csrf'] ?? null)
        && !empty($_SESSION['csrf'])
        && hash_equals($_SESSION['csrf'], $_POST['csrf']);
}

/** Guarda um aviso para ser mostrado depois do redirecionamento. */
function definirAviso(string $tipo, string $mensagem): void
{
    $_SESSION['aviso'] = ['tipo' => $tipo, 'mensagem' => $mensagem];
}

function pegarAviso(): ?array
{
    $aviso = $_SESSION['aviso'] ?? null;
    unset($_SESSION['aviso']);

    return $aviso;
}

/* ------------------------------------------------------------------
 * CONSULTAS COMPARTILHADAS
 * ------------------------------------------------------------------ */

function intervaloPulso(PDO $pdo): int
{
    $stmt = $pdo->prepare("SELECT valor FROM parametros WHERE chave = 'intervalo_pulso_min'");
    $stmt->execute();

    return (int) ($stmt->fetchColumn() ?: 5);
}

/**
 * Disponibilidade (%) hora a hora no dia do último pulso registrado.
 * Sem $maquinaId considera todas as máquinas ativas.
 */
function disponibilidadePorHora(PDO $pdo, ?int $maquinaId = null): array
{
    $filtro = $maquinaId === null ? 'm.ativa = 1' : 'p.maquina_id = :maquina';
    $params = $maquinaId === null ? [] : [':maquina' => $maquinaId];

    $stmt = $pdo->prepare(
        "SELECT DATE(MAX(p.`timestamp`))
           FROM pulsos p
           JOIN maquinas m ON m.id = p.maquina_id
          WHERE $filtro"
    );
    $stmt->execute($params);
    $dia = $stmt->fetchColumn();

    if (!$dia) {
        return ['dia' => null, 'rotulos' => [], 'valores' => []];
    }

    $stmt = $pdo->prepare(
        "SELECT HOUR(p.`timestamp`) AS hora,
                ROUND(100 * SUM(p.estado = 'rodando') / COUNT(*), 1) AS pct
           FROM pulsos p
           JOIN maquinas m ON m.id = p.maquina_id
          WHERE $filtro AND DATE(p.`timestamp`) = :dia
          GROUP BY hora
          ORDER BY hora"
    );
    $stmt->execute($params + [':dia' => $dia]);

    $rotulos = [];
    $valores = [];
    foreach ($stmt->fetchAll() as $linha) {
        $rotulos[] = sprintf('%02dh', $linha['hora']);
        $valores[] = (float) $linha['pct'];
    }

    return ['dia' => $dia, 'rotulos' => $rotulos, 'valores' => $valores];
}

/**
 * Períodos de parada: sequências de pulsos 'parado' consecutivos da mesma
 * máquina e com o mesmo motivo. Um novo período começa quando o pulso
 * anterior não era parada, tinha outro motivo ou ficou distante mais que
 * um intervalo de pulso.
 *
 * Filtros aceitos: maquina_id, motivo_id, desde (Y-m-d H:i:s), limite.
 */
function buscarPeriodosParada(PDO $pdo, array $filtros = []): array
{
    $condicoes = [];
    $params = [];

    if (!empty($filtros['maquina_id'])) {
        $condicoes[] = 'per.maquina_id = :maquina';
        $params[':maquina'] = (int) $filtros['maquina_id'];
    }
    if (!empty($filtros['motivo_id'])) {
        $condicoes[] = 'per.motivo_id = :motivo';
        $params[':motivo'] = (int) $filtros['motivo_id'];
    }
    if (!empty($filtros['desde'])) {
        $condicoes[] = 'per.inicio >= :desde';
        $params[':desde'] = $filtros['desde'];
    }

    $where = $condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '';
    $limite = !empty($filtros['limite']) ? 'LIMIT ' . (int) $filtros['limite'] : '';

    $stmt = $pdo->prepare(
        "WITH intervalo AS (
             SELECT CAST(valor AS UNSIGNED) AS minutos
               FROM parametros
              WHERE chave = 'intervalo_pulso_min'
         ),
         anteriores AS (
             SELECT p.maquina_id, p.motivo_id, p.`timestamp`, p.estado,
                    LAG(p.estado)      OVER (PARTITION BY p.maquina_id ORDER BY p.`timestamp`) AS estado_ant,
                    LAG(p.motivo_id)   OVER (PARTITION BY p.maquina_id ORDER BY p.`timestamp`) AS motivo_ant,
                    LAG(p.`timestamp`) OVER (PARTITION BY p.maquina_id ORDER BY p.`timestamp`) AS ts_ant
               FROM pulsos p
         ),
         grupos AS (
             SELECT a.*,
                    SUM(CASE
                            WHEN a.estado_ant = 'parado'
                             AND a.motivo_ant = a.motivo_id
                             AND TIMESTAMPDIFF(MINUTE, a.ts_ant, a.`timestamp`) <= i.minutos
                            THEN 0 ELSE 1
                        END) OVER (PARTITION BY a.maquina_id ORDER BY a.`timestamp`) AS grupo,
                    i.minutos
               FROM anteriores a
              CROSS JOIN intervalo i
              WHERE a.estado = 'parado'
         ),
         periodos AS (
             SELECT maquina_id, motivo_id,
                    MIN(`timestamp`)                                  AS inicio,
                    MAX(`timestamp`) + INTERVAL MAX(minutos) MINUTE   AS fim,
                    COUNT(*) * MAX(minutos)                           AS duracao_min
               FROM grupos
              GROUP BY maquina_id, grupo, motivo_id
         )
         SELECT per.maquina_id, m.nome AS maquina, per.motivo_id, mp.descricao AS motivo,
                per.inicio, per.fim, per.duracao_min
           FROM periodos per
           JOIN maquinas m        ON m.id = per.maquina_id
           JOIN motivos_parada mp ON mp.id = per.motivo_id
           $where
          ORDER BY per.inicio DESC, m.nome
          $limite"
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}
