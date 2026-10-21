<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/funcoes.php';

/* ------------------------------------------------------------------
 * FILTRO DE PERÍODO (GET)
 * ------------------------------------------------------------------ */

$periodos = [
    '7dias'  => 'Últimos 7 dias',
    '30dias' => 'Últimos 30 dias',
    'todos'  => 'Todo o período',
];

$periodo = $_GET['periodo'] ?? '30dias';
if (!isset($periodos[$periodo])) {
    $periodo = '30dias';
}

$desde = match ($periodo) {
    '7dias'  => date('Y-m-d 00:00:00', strtotime('-6 days')),
    '30dias' => date('Y-m-d 00:00:00', strtotime('-29 days')),
    default  => '1000-01-01 00:00:00',
};

$intervalo = intervaloPulso($pdo);

/* ------------------------------------------------------------------
 * INDICADORES POR MÁQUINA NO PERÍODO
 * Máquinas inativas só aparecem se tiverem pulsos no período.
 * ------------------------------------------------------------------ */

$stmt = $pdo->prepare(
    "SELECT m.id, m.nome, m.meta_disponibilidade, m.ativa,
            COUNT(p.id)                          AS total_pulsos,
            COALESCE(SUM(p.estado = 'rodando'), 0) AS pulsos_rodando,
            COALESCE(SUM(p.estado = 'parado'), 0)  AS pulsos_parado
       FROM maquinas m
       LEFT JOIN pulsos p ON p.maquina_id = m.id AND p.`timestamp` >= :desde
      GROUP BY m.id, m.nome, m.meta_disponibilidade, m.ativa
     HAVING m.ativa = 1 OR total_pulsos > 0
      ORDER BY m.nome"
);
$stmt->execute([':desde' => $desde]);

$linhas = [];
foreach ($stmt->fetchAll() as $m) {
    $total = (int) $m['total_pulsos'];
    $pct = $total > 0 ? round(100 * $m['pulsos_rodando'] / $total, 2) : null;
    $meta = (float) $m['meta_disponibilidade'];

    $linhas[] = [
        'id'          => (int) $m['id'],
        'nome'        => $m['nome'],
        'ativa'       => (int) $m['ativa'] === 1,
        'meta'        => $meta,
        'produzindo'  => (int) $m['pulsos_rodando'] * $intervalo,
        'parado'      => (int) $m['pulsos_parado'] * $intervalo,
        'total'       => $total * $intervalo,
        'pct'         => $pct,
        'status'      => $pct === null ? 'Sem dados' : ($pct >= $meta ? 'Na meta' : 'Abaixo da meta'),
    ];
}

/* ------------------------------------------------------------------
 * EXPORTAÇÃO CSV (separador ; e BOM UTF-8 para abrir no Excel)
 * ------------------------------------------------------------------ */

if (($_GET['exportar'] ?? '') === 'csv') {
    $numero = fn(?float $v) => $v === null ? '' : number_format($v, 2, ',', '');

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="machsense_relatorio_' . $periodo . '_' . date('Y-m-d') . '.csv"');

    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");
    fputcsv($saida, ['Período', $periodos[$periodo]], ';');
    fputcsv($saida, ['Máquina', 'Situação', 'Meta (%)', 'Tempo produzindo (min)', 'Tempo parado (min)',
        'Tempo total (min)', 'Disponibilidade (%)', 'Status'], ';');

    foreach ($linhas as $l) {
        fputcsv($saida, [
            $l['nome'],
            $l['ativa'] ? 'Ativa' : 'Inativa',
            $numero($l['meta']),
            $l['produzindo'],
            $l['parado'],
            $l['total'],
            $numero($l['pct']),
            $l['status'],
        ], ';');
    }

    fclose($saida);
    exit;
}

/* ------------------------------------------------------------------
 * RESUMO E GRÁFICO
 * ------------------------------------------------------------------ */

$totalProduzindo = array_sum(array_column($linhas, 'produzindo'));
$totalParado = array_sum(array_column($linhas, 'parado'));
$totalMinutos = array_sum(array_column($linhas, 'total'));
$comDados = array_filter($linhas, fn($l) => $l['pct'] !== null);
$naMeta = count(array_filter($comDados, fn($l) => $l['status'] === 'Na meta'));
$disponibilidadeGeral = $totalMinutos > 0 ? 100 * $totalProduzindo / $totalMinutos : null;

$resumo = [
    ['titulo' => 'Disponibilidade', 'valor' => formatarPct($disponibilidadeGeral)],
    ['titulo' => 'Tempo produzindo', 'valor' => formatarMinutos($totalProduzindo)],
    ['titulo' => 'Tempo parado', 'valor' => formatarMinutos($totalParado)],
    ['titulo' => 'Máquinas na meta', 'valor' => $naMeta . ' de ' . count($comDados)],
];

// Disponibilidade por dia de cada máquina
$stmt = $pdo->prepare(
    "SELECT p.maquina_id, DATE(p.`timestamp`) AS dia,
            ROUND(100 * SUM(p.estado = 'rodando') / COUNT(*), 1) AS pct
       FROM pulsos p
      WHERE p.`timestamp` >= :desde
      GROUP BY p.maquina_id, dia
      ORDER BY dia"
);
$stmt->execute([':desde' => $desde]);

$porDia = [];
foreach ($stmt->fetchAll() as $d) {
    $porDia[$d['dia']][(int) $d['maquina_id']] = (float) $d['pct'];
}
$dias = array_keys($porDia);

$grafico = ['rotulos' => array_map(fn($d) => date('d/m', strtotime($d)), $dias), 'series' => [], 'meta' => null];
foreach ($comDados as $l) {
    $grafico['series'][] = [
        'nome'    => $l['nome'],
        'valores' => array_map(fn($d) => $porDia[$d][$l['id']] ?? null, $dias),
    ];
}

$tituloPagina = 'Relatórios de Produção';
$subtituloPagina = 'Relatórios consolidados de disponibilidade para a equipe de gestão';
$paginaAtiva = 'relatorios';

require __DIR__ . '/includes/layout_inicio.php';
?>

        <div class="toolbar">
            <form class="filtros" method="get" action="relatorios.php">
                <label>
                    <span>Período</span>
                    <select name="periodo">
                        <?php foreach ($periodos as $valor => $rotulo): ?>
                            <option value="<?= e($valor) ?>"<?= $periodo === $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <button type="submit" class="btn btn-secundario">Aplicar</button>
            </form>

            <a class="btn btn-primario" href="relatorios.php?periodo=<?= e($periodo) ?>&amp;exportar=csv">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
                Exportar CSV
            </a>
        </div>

        <section class="kpis" aria-label="Resumo do período">
            <?php foreach ($resumo as $r): ?>
                <article class="kpi">
                    <div class="kpi-head">
                        <h2><?= e($r['titulo']) ?></h2>
                        <span class="tag tag-ok"><?= e($periodos[$periodo]) ?></span>
                    </div>
                    <div class="kpi-body">
                        <p class="kpi-value"><?= e($r['valor']) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="panel" aria-labelledby="t-dia">
            <header class="panel-head">
                <div>
                    <h2 id="t-dia">Disponibilidade por Dia</h2>
                    <p>Evolução diária da disponibilidade de cada máquina</p>
                </div>
                <?php if ($grafico['series']): ?>
                    <div class="legends">
                        <?php foreach ($grafico['series'] as $i => $s): ?>
                            <span class="legend"><span class="swatch serie-<?= $i % 6 ?>"></span><?= e($s['nome']) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </header>

            <?php if (!$grafico['series']): ?>
                <p class="empty"><strong>Nenhum pulso no período selecionado.</strong>
                    Escolha um período maior ou aguarde novos dados das máquinas.</p>
            <?php else: ?>
                <div class="chart" data-grafico="<?= e(json_encode($grafico)) ?>"
                     data-titulo="Disponibilidade por dia"></div>
            <?php endif; ?>

            <?php if ($linhas): ?>
                <div class="tabela-wrap tabela-relatorio">
                    <table class="tabela">
                        <thead>
                            <tr>
                                <th>Máquina</th>
                                <th class="num">Tempo produzindo</th>
                                <th class="num">Tempo parado</th>
                                <th class="num">Disponibilidade</th>
                                <th class="num">Meta</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($linhas as $l): ?>
                                <tr>
                                    <td>
                                        <a href="maquina.php?id=<?= $l['id'] ?>"><?= e($l['nome']) ?></a>
                                        <?php if (!$l['ativa']): ?><small class="texto-fraco">(inativa)</small><?php endif; ?>
                                    </td>
                                    <td class="num mono"><?= e(formatarMinutos($l['produzindo'])) ?></td>
                                    <td class="num mono"><?= e(formatarMinutos($l['parado'])) ?></td>
                                    <td class="num mono pct-<?= classeDisponibilidade($l['pct'], $l['meta']) ?>"><?= e(formatarPct($l['pct'])) ?></td>
                                    <td class="num mono"><?= e(formatarPct($l['meta'])) ?></td>
                                    <td>
                                        <?php if ($l['pct'] === null): ?>
                                            <span class="selo selo-neutro">Sem dados</span>
                                        <?php elseif ($l['status'] === 'Na meta'): ?>
                                            <span class="selo selo-ok">Na meta</span>
                                        <?php else: ?>
                                            <span class="selo selo-parada">Abaixo da meta</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
