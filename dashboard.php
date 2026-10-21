<?php

session_start();

// Só entra quem passou pelo login_process.php
if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

date_default_timezone_set('America/Sao_Paulo');

$nomeUsuario = $_SESSION['usuario_nome'] ?? 'Usuário';

// Iniciais para o avatar (até 2 palavras do nome)
$iniciais = '';
foreach (array_slice(preg_split('/\s+/', trim($nomeUsuario)), 0, 2) as $parte) {
    $iniciais .= mb_strtoupper(mb_substr($parte, 0, 1));
}

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

function e(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

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

?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MachSense — Dashboard de Produção</title>
    <link rel="stylesheet" href="dashboard.css">
</head>
<body>

<div class="app">

    <!-- ============ MENU LATERAL ============ -->
    <aside class="sidebar">
        <div class="brand">
            <span class="brand-mark" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 3v18h18"/><path d="M18.7 8 12 14.7 8.5 11.2 3 16.7"/>
                </svg>
            </span>
            <div>
                <strong>MachSense</strong>
                <small>PRODUÇÃO INDUSTRIAL</small>
            </div>
        </div>

        <nav class="nav" aria-label="Navegação principal">
            <a href="dashboard.php" class="nav-link is-active" aria-current="page">Dashboard</a>
            <a href="#" class="nav-link">Máquinas</a>
            <a href="#" class="nav-link">Paradas</a>
            <a href="#" class="nav-link">Relatórios</a>
            <a href="#" class="nav-link">Configurações</a>
        </nav>

        <div class="sidebar-foot">
            <p class="line-status"><span class="dot"></span>LINHA_PROD #01 : OK</p>
            <div class="user">
                <span class="avatar" aria-hidden="true"><?= e($iniciais) ?></span>
                <div>
                    <strong><?= e($nomeUsuario) ?></strong>
                    <small>Supervisor de Produção</small>
                </div>
            </div>
        </div>
    </aside>

    <!-- ============ ÁREA PRINCIPAL ============ -->
    <main class="main">

        <header class="topbar">
            <div>
                <h1>Dashboard de Produção</h1>
                <p>Visão geral de produção em tempo real das máquinas da planta</p>
            </div>
            <div class="topbar-actions">
                <span class="plant-time">
                    <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/></svg>
                    PLANT_TIME: <span id="plant-time"><?= date('H:i:s') ?></span>
                </span>
                <a href="logout.php" class="btn-logout" title="Sair do sistema" aria-label="Sair do sistema">
                    <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path d="m15 9-6 6M9 9l6 6"/></svg>
                </a>
            </div>
        </header>

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
    </main>
</div>

<script src="dashboard.js"></script>
</body>
</html>
