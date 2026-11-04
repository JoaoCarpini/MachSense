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
 * A consulta em si está na view vw_periodos_parada.
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

    // A lógica dos períodos fica na view vw_periodos_parada (veja schema.sql).
    $stmt = $pdo->prepare(
        "SELECT per.maquina_id, per.maquina, per.motivo_id, per.motivo,
                per.inicio, per.fim, per.duracao_min
           FROM vw_periodos_parada per
           $where
          ORDER BY per.inicio DESC, per.maquina
          $limite"
    );
    $stmt->execute($params);

    return $stmt->fetchAll();
}
