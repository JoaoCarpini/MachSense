<?php

/*
 * dashboard_dados.php — os números do dashboard em JSON (somente leitura).
 *
 * O dashboard.js chama este arquivo a cada 10 s para atualizar a tela sem
 * recarregar a página. Os campos são os mesmos que o dashboard.php mostra.
 *
 * As funções dd_* abaixo repetem as regras do dashboard.php (formatação e
 * consultas). Se mudar uma regra lá, mude aqui também.
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/protect.php';

date_default_timezone_set('America/Sao_Paulo');

// Libera a sessão: o PHP trava o arquivo de sessão enquanto a requisição roda,
// e o dashboard faz uma chamada a cada 10 s junto com as outras páginas.
session_write_close();

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['erro' => 'Método não permitido.']);
    exit;
}

/* ------------------------------------------------------------------
 * FORMATAÇÃO (igual à do includes/funcoes.php)
 * ------------------------------------------------------------------ */

function dd_pct(?float $pct): string
{
    return $pct === null ? '—' : number_format($pct, 1, ',', '.') . '%';
}

function dd_minutos(int $minutos): string
{
    return sprintf('%02d:%02dh', intdiv($minutos, 60), $minutos % 60);
}

function dd_motivo(string $motivo): string
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
function dd_classe(?float $pct, float $meta): string
{
    if ($pct === null) return 'danger';
    if ($pct >= $meta) return 'ok';
    if ($pct >= $meta - 15) return 'warn';
    return 'danger';
}

/** Disponibilidade (%) hora a hora no dia do último pulso das máquinas ativas. */
function dd_por_hora(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT DATE(MAX(p.`timestamp`))
           FROM pulsos p
           JOIN maquinas m ON m.id = p.maquina_id
          WHERE m.ativa = 1'
    );
    $stmt->execute();
    $dia = $stmt->fetchColumn();

    if (!$dia) {
        return ['dia' => null, 'rotulos' => [], 'valores' => []];
    }

    $stmt = $pdo->prepare(
        "SELECT HOUR(p.`timestamp`) AS hora,
                ROUND(100 * SUM(p.estado = 'rodando') / COUNT(*), 1) AS pct
           FROM pulsos p
           JOIN maquinas m ON m.id = p.maquina_id
          WHERE m.ativa = 1 AND DATE(p.`timestamp`) = :dia
          GROUP BY hora
          ORDER BY hora"
    );
    $stmt->execute([':dia' => $dia]);

    $rotulos = [];
    $valores = [];
    foreach ($stmt->fetchAll() as $linha) {
        $rotulos[] = sprintf('%02dh', $linha['hora']);
        $valores[] = (float) $linha['pct'];
    }

    return ['dia' => $dia, 'rotulos' => $rotulos, 'valores' => $valores];
}

/* ------------------------------------------------------------------
 * DADOS (as mesmas consultas do dashboard.php)
 * ------------------------------------------------------------------ */

try {
    $stmt = $pdo->prepare(
        'SELECT v.maquina, v.meta_pct, v.total_pulsos, v.tempo_produzindo_min, v.tempo_parado_min,
                v.tempo_total_min, v.disponibilidade_pct, v.atingiu_meta
           FROM vw_indicadores_maquina v
           JOIN maquinas m ON m.id = v.machine_id
          WHERE m.ativa = 1
          ORDER BY v.maquina'
    );
    $stmt->execute();
    $maquinas = $stmt->fetchAll();

    $stmt = $pdo->prepare(
        'SELECT v.motivo, SUM(v.tempo_parado_min) AS tempo_parado_min
           FROM vw_paradas_por_motivo v
           JOIN maquinas m ON m.id = v.machine_id
          WHERE m.ativa = 1
          GROUP BY v.motivo
          ORDER BY tempo_parado_min DESC, v.motivo'
    );
    $stmt->execute();
    $paradasPorMotivo = $stmt->fetchAll();

    $porHora = dd_por_hora($pdo);
} catch (PDOException $ex) {
    // O detalhe técnico vai para o log; quem chamou recebe só um aviso genérico
    error_log('dashboard_dados.php: ' . $ex->getMessage());
    http_response_code(503);
    echo json_encode(['erro' => 'Dados indisponíveis no momento.']);
    exit;
}

$totalPulsos = 0;
$totalProduzindo = 0;
$totalParado = 0;
$totalMinutos = 0;
$somaMetas = 0.0;
$maquinasNaMeta = 0;

foreach ($maquinas as $m) {
    $totalPulsos += (int) $m['total_pulsos'];
    $totalProduzindo += (int) $m['tempo_produzindo_min'];
    $totalParado += (int) $m['tempo_parado_min'];
    $totalMinutos += (int) $m['tempo_total_min'];
    $somaMetas += (float) $m['meta_pct'];
    $maquinasNaMeta += (int) $m['atingiu_meta'];
}

$qtdMaquinas = count($maquinas);
$metaMedia = $qtdMaquinas > 0 ? $somaMetas / $qtdMaquinas : 0;
$disponibilidadeGeral = $totalMinutos > 0 ? 100 * $totalProduzindo / $totalMinutos : null;

// Mesma ordem dos 4 cartões do dashboard.php
$kpis = [
    [
        'valor' => dd_pct($disponibilidadeGeral),
        'cor'   => $disponibilidadeGeral !== null && $disponibilidadeGeral >= $metaMedia ? 'ok' : 'warn',
    ],
    ['valor' => dd_minutos($totalProduzindo), 'cor' => 'ok'],
    ['valor' => dd_minutos($totalParado), 'cor' => 'warn'],
    [
        'valor' => "$maquinasNaMeta de $qtdMaquinas",
        'cor'   => $qtdMaquinas > 0 && $maquinasNaMeta === $qtdMaquinas ? 'ok' : 'warn',
    ],
];

$listaMaquinas = [];
foreach ($maquinas as $m) {
    $pct = $m['disponibilidade_pct'] === null ? null : (float) $m['disponibilidade_pct'];
    $meta = (float) $m['meta_pct'];
    $listaMaquinas[] = [
        'nome'      => $m['maquina'],
        'meta_texto' => dd_pct($meta),
        'pct_texto' => $pct === null ? 'Sem dados' : dd_pct($pct),
        'largura'   => (int) round(min($pct ?? 0, 100)),
        'meta_pos'  => (int) round($meta),
        'classe'    => dd_classe($pct, $meta),
    ];
}

$maiorParada = max(array_merge([1], array_map(fn($p) => (int) $p['tempo_parado_min'], $paradasPorMotivo)));
$listaParadas = [];
foreach ($paradasPorMotivo as $p) {
    $minutos = (int) $p['tempo_parado_min'];
    $listaParadas[] = [
        'motivo'  => dd_motivo($p['motivo']),
        'texto'   => dd_minutos($minutos),
        'largura' => (int) round($minutos / $maiorParada * 100),
    ];
}

echo json_encode([
    'gerado_em'    => date('H:i:s'),
    'sem_pulsos'   => $totalPulsos === 0,
    'qtd_maquinas' => $qtdMaquinas,
    'kpis'         => $kpis,
    'dia'          => $porHora['dia'] ? date('d/m/Y', strtotime($porHora['dia'])) : null,
    'grafico'      => [
        'rotulos' => $porHora['rotulos'],
        'series'  => [['nome' => 'Disponibilidade', 'valores' => $porHora['valores']]],
        'meta'    => $qtdMaquinas > 0 ? round($metaMedia, 1) : null,
    ],
    'maquinas'     => $listaMaquinas,
    'paradas'      => $listaParadas,
], JSON_THROW_ON_ERROR);
