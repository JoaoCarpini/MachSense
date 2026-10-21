<?php

require_once __DIR__ . '/includes/auth.php';

/* ------------------------------------------------------------------
 * DADOS DE EXEMPLO
 * Ainda não existem tabelas de produção no banco. Quando existirem,
 * troque estes arrays por consultas via PDO ($pdo vem do db.php).
 * ------------------------------------------------------------------ */

$kpis = [
    [
        'titulo' => 'Peças produzidas',
        'tag'    => 'Turno atual',
        'valor'  => number_format(1247, 0, ',', '.'),
        'cor'    => 'ok',
        'serie'  => [30, 45, 38, 60, 52, 70, 66, 80],
    ],
    [
        'titulo' => 'Peças refugadas',
        'tag'    => 'Turno atual',
        'valor'  => '38',
        'cor'    => 'ok',
        'serie'  => [10, 14, 12, 22, 18, 30, 26, 34],
    ],
    [
        'titulo' => 'Tempo parado',
        'tag'    => 'Acumulado hoje',
        'valor'  => sprintf('%02d:%02dh', intdiv(135, 60), 135 % 60),
        'cor'    => 'warn',
        'serie'  => [40, 52, 36, 44, 30, 50, 26, 38],
    ],
    [
        'titulo' => 'Meta atingida',
        'tag'    => '% da meta',
        'valor'  => '87%',
        'cor'    => 'ok',
        'serie'  => [20, 28, 24, 40, 36, 52, 48, 60],
    ],
];

// Produção por hora: [rótulo, peças]
$metaPorHora = 140;
$producaoHora = [
    ['14h00', 65],  ['16h00', 88],  ['18h00', 75],  ['20h00', 140],
    ['22h00', 112], ['00h00', 160], ['02h00', 170], ['04h00', 105],
    ['06h00', 128], ['08h00', 76],  ['10h00', 122], ['12h00', 148],
    ['14h00', 118],
];

// Máquinas: [nome, produzido, meta]
$maquinas = [
    ['Prensa Estampadora #02',  1530, 1500],
    ['Torno CNC #03',           1247, 1300],
    ['Bomba Hidráulica P1',     1056, 1200],
    ['Injetora Plásticos #05',   912, 1200],
    ['Compressor de Ar C3',      768, 1200],
];

/* ------------------------------------------------------------------
 * FUNÇÕES AUXILIARES
 * ------------------------------------------------------------------ */

/** Converte uma série de valores em pontos "x,y" para <polyline>. */
function pontosSparkline(array $serie, int $largura = 120, int $altura = 40): string
{
    $min = min($serie);
    $max = max($serie);
    $faixa = max($max - $min, 1);
    $passo = $largura / (count($serie) - 1);
    $pontos = [];

    foreach ($serie as $i => $v) {
        $x = round($i * $passo, 1);
        $y = round(($altura - 4) - (($v - $min) / $faixa) * ($altura - 8) + 2, 1);
        $pontos[] = "$x,$y";
    }

    return implode(' ', $pontos);
}

/** Cor da barra de meta conforme o percentual atingido. */
function classeMeta(int $pct): string
{
    if ($pct >= 90) return 'ok';
    if ($pct >= 70) return 'warn';
    return 'danger';
}

/* ------------------------------------------------------------------
 * GEOMETRIA DO GRÁFICO DE LINHA (SVG)
 * ------------------------------------------------------------------ */

$gW = 640; $gH = 230;
$padE = 38; $padD = 26; $padT = 14; $padB = 30;
$areaW = $gW - $padE - $padD;
$areaH = $gH - $padT - $padB;
$yMax = 200;
$n = count($producaoHora);

$pontosGrafico = [];
foreach ($producaoHora as $i => [$rotulo, $pecas]) {
    $pontosGrafico[] = [
        'x'      => round($padE + $i * $areaW / ($n - 1), 1),
        'y'      => round($padT + $areaH * (1 - $pecas / $yMax), 1),
        'rotulo' => $rotulo,
        'pecas'  => $pecas,
    ];
}
$linhaGrafico = implode(' ', array_map(fn($p) => "{$p['x']},{$p['y']}", $pontosGrafico));
$yMetaLinha = round($padT + $areaH * (1 - $metaPorHora / $yMax), 1);
$passoX = $areaW / ($n - 1);

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
                        <svg class="spark spark-<?= e($kpi['cor']) ?>" viewBox="0 0 120 40" preserveAspectRatio="none" aria-hidden="true">
                            <polyline points="<?= pontosSparkline($kpi['serie']) ?>"/>
                        </svg>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <div class="row">

            <!-- Gráfico de produção por hora -->
            <section class="panel" aria-labelledby="t-hora">
                <header class="panel-head">
                    <div>
                        <h2 id="t-hora">Produção por Hora (Últimas 24h)</h2>
                        <p>Quantidade de peças produzidas por hora</p>
                    </div>
                    <span class="legend"><span class="swatch"></span>Peças/Hora</span>
                </header>

                <div class="chart" id="chart">
                    <svg viewBox="0 0 <?= $gW ?> <?= $gH ?>" role="img" aria-label="Gráfico de linha da produção por hora nas últimas 24 horas">

                        <?php for ($v = 0; $v <= $yMax; $v += 40):
                            $y = round($padT + $areaH * (1 - $v / $yMax), 1); ?>
                            <line class="grid" x1="<?= $padE ?>" x2="<?= $gW - $padD ?>" y1="<?= $y ?>" y2="<?= $y ?>"/>
                            <text class="axis" x="<?= $padE - 8 ?>" y="<?= $y + 3 ?>" text-anchor="end"><?= $v ?></text>
                        <?php endfor; ?>

                        <line class="meta-line" x1="<?= $padE ?>" x2="<?= $gW - $padD ?>" y1="<?= $yMetaLinha ?>" y2="<?= $yMetaLinha ?>"/>

                        <?php foreach ($pontosGrafico as $i => $p): if ($i % 2 === 0): ?>
                            <text class="axis" x="<?= $p['x'] ?>" y="<?= $gH - 10 ?>" text-anchor="middle"><?= e($p['rotulo']) ?></text>
                        <?php endif; endforeach; ?>

                        <polyline class="line" points="<?= $linhaGrafico ?>"/>

                        <?php foreach ($pontosGrafico as $p): ?>
                            <g class="ponto"
                               data-x="<?= $p['x'] ?>" data-y="<?= $p['y'] ?>"
                               data-rotulo="<?= e($p['rotulo']) ?>" data-pecas="<?= $p['pecas'] ?>"
                               data-meta="<?= $p['pecas'] >= $metaPorHora ? 'ok' : 'abaixo' ?>">
                                <rect x="<?= round($p['x'] - $passoX / 2, 1) ?>" y="<?= $padT ?>" width="<?= round($passoX, 1) ?>" height="<?= $areaH ?>" fill="transparent"/>
                                <circle cx="<?= $p['x'] ?>" cy="<?= $p['y'] ?>" r="3.5"/>
                            </g>
                        <?php endforeach; ?>
                    </svg>
                    <div class="tooltip" id="tooltip" hidden></div>
                </div>
            </section>

            <!-- % da meta por máquina -->
            <section class="panel" aria-labelledby="t-meta">
                <header class="panel-head">
                    <div>
                        <h2 id="t-meta">% da Meta por Máquina</h2>
                        <p>Desempenho de produção no turno atual</p>
                    </div>
                </header>

                <ul class="metas">
                    <?php foreach ($maquinas as [$nome, $produzido, $meta]):
                        $pct = (int) round($produzido / $meta * 100); ?>
                        <li>
                            <div class="meta-top">
                                <span><?= e($nome) ?></span>
                                <strong><?= $pct ?>%</strong>
                            </div>
                            <div class="progress" role="progressbar" aria-valuenow="<?= min($pct, 100) ?>" aria-valuemin="0" aria-valuemax="100">
                                <span class="bar bar-<?= classeMeta($pct) ?>" style="width: <?= min($pct, 100) ?>%"></span>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </section>

        </div>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
