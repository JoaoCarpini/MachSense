<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/db.php';

/* ------------------------------------------------------------------
 * DADOS DAS VIEWS
 * ------------------------------------------------------------------ */

$stmt = $pdo->prepare(
    'SELECT maquina, meta_pct, tempo_produzindo_min, tempo_parado_min,
            tempo_total_min, disponibilidade_pct, atingiu_meta
       FROM vw_indicadores_maquina
      ORDER BY maquina'
);
$stmt->execute();
$maquinas = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT motivo, SUM(tempo_parado_min) AS tempo_parado_min
       FROM vw_paradas_por_motivo
      GROUP BY motivo
      ORDER BY tempo_parado_min DESC, motivo'
);
$stmt->execute();
$paradasPorMotivo = $stmt->fetchAll();

/* ------------------------------------------------------------------
 * FUNÇÕES AUXILIARES
 * ------------------------------------------------------------------ */

function formatarMinutos(int $minutos): string
{
    return sprintf('%02d:%02dh', intdiv($minutos, 60), $minutos % 60);
}

function formatarPct(?float $pct): string
{
    return $pct === null ? '—' : number_format($pct, 1, ',', '.') . '%';
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

/** Cor da barra de disponibilidade em relação à meta da máquina. */
function classeDisponibilidade(?float $pct, float $meta): string
{
    if ($pct === null) return 'danger';
    if ($pct >= $meta) return 'ok';
    if ($pct >= $meta - 15) return 'warn';
    return 'danger';
}

/* ------------------------------------------------------------------
 * INDICADORES GERAIS
 * ------------------------------------------------------------------ */

$totalProduzindo = 0;
$totalParado = 0;
$totalMinutos = 0;
$somaMetas = 0.0;
$maquinasNaMeta = 0;

foreach ($maquinas as $m) {
    $totalProduzindo += (int) $m['tempo_produzindo_min'];
    $totalParado += (int) $m['tempo_parado_min'];
    $totalMinutos += (int) $m['tempo_total_min'];
    $somaMetas += (float) $m['meta_pct'];
    $maquinasNaMeta += (int) $m['atingiu_meta'];
}

$qtdMaquinas = count($maquinas);
$metaMedia = $qtdMaquinas > 0 ? $somaMetas / $qtdMaquinas : 0;
$disponibilidadeGeral = $totalMinutos > 0 ? 100 * $totalProduzindo / $totalMinutos : null;

$kpis = [
    [
        'titulo' => 'Tempo produzindo',
        'tag'    => 'Acumulado',
        'valor'  => formatarMinutos($totalProduzindo),
        'cor'    => 'ok',
    ],
    [
        'titulo' => 'Tempo parado',
        'tag'    => 'Acumulado',
        'valor'  => formatarMinutos($totalParado),
        'cor'    => 'warn',
    ],
    [
        'titulo' => 'Disponibilidade',
        'tag'    => 'Todas as máquinas',
        'valor'  => formatarPct($disponibilidadeGeral),
        'cor'    => $disponibilidadeGeral !== null && $disponibilidadeGeral >= $metaMedia ? 'ok' : 'warn',
    ],
    [
        'titulo' => 'Máquinas na meta',
        'tag'    => 'Disponibilidade',
        'valor'  => "$maquinasNaMeta de $qtdMaquinas",
        'cor'    => $qtdMaquinas > 0 && $maquinasNaMeta === $qtdMaquinas ? 'ok' : 'warn',
    ],
];

$maiorParada = max(array_merge([1], array_map(fn($p) => (int) $p['tempo_parado_min'], $paradasPorMotivo)));

$tituloPagina = 'Dashboard de Produção';
$subtituloPagina = 'Visão geral de produção em tempo real das máquinas da planta';
$paginaAtiva = 'dashboard';

require __DIR__ . '/includes/layout_inicio.php';
?>

        <!-- KPIs -->
        <section class="kpis" aria-label="Indicadores principais">
            <?php foreach ($kpis as $kpi): ?>
                <article class="kpi">
                    <div class="kpi-head">
                        <h2><?= e($kpi['titulo']) ?></h2>
                        <span class="tag tag-<?= e($kpi['cor']) ?>"><?= e($kpi['tag']) ?></span>
                    </div>
                    <div class="kpi-body">
                        <p class="kpi-value"><?= e($kpi['valor']) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="row">

            <!-- Tempo parado por motivo -->
            <section class="panel" aria-labelledby="t-paradas">
                <header class="panel-head">
                    <div>
                        <h2 id="t-paradas">Tempo Parado por Motivo</h2>
                        <p>Soma de todas as máquinas</p>
                    </div>
                </header>

                <ul class="metas">
                    <?php foreach ($paradasPorMotivo as $p):
                        $minutos = (int) $p['tempo_parado_min'];
                        $largura = (int) round($minutos / $maiorParada * 100); ?>
                        <li>
                            <div class="meta-top">
                                <span><?= e(nomeMotivo($p['motivo'])) ?></span>
                                <strong><?= e(formatarMinutos($minutos)) ?></strong>
                            </div>
                            <div class="progress" aria-hidden="true">
                                <span class="bar bar-warn" style="width: <?= $largura ?>%"></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

            <!-- Disponibilidade por máquina -->
            <section class="panel" aria-labelledby="t-meta">
                <header class="panel-head">
                    <div>
                        <h2 id="t-meta">Disponibilidade por Máquina</h2>
                        <p>Tempo produzindo em relação ao tempo total, comparado à meta</p>
                    </div>
                </header>

                <ul class="metas">
                    <?php foreach ($maquinas as $m):
                        $pct = $m['disponibilidade_pct'] === null ? null : (float) $m['disponibilidade_pct'];
                        $meta = (float) $m['meta_pct'];
                        $largura = (int) round(min($pct ?? 0, 100)); ?>
                        <li>
                            <div class="meta-top">
                                <span><?= e($m['maquina']) ?> <small>(meta <?= e(formatarPct($meta)) ?>)</small></span>
                                <strong><?= e($pct === null ? 'Sem dados' : formatarPct($pct)) ?></strong>
                            </div>
                            <div class="progress" role="progressbar" aria-valuenow="<?= $largura ?>" aria-valuemin="0" aria-valuemax="100">
                                <span class="bar bar-<?= classeDisponibilidade($pct, $meta) ?>" style="width: <?= $largura ?>%"></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

        </div>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
