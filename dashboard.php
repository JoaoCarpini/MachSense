<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/funcoes.php';

/* ------------------------------------------------------------------
 * DADOS DAS VIEWS (somente máquinas ativas)
 * ------------------------------------------------------------------ */

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

$porHora = disponibilidadePorHora($pdo);

/* ------------------------------------------------------------------
 * INDICADORES GERAIS
 * ------------------------------------------------------------------ */

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
$semPulsos = $totalPulsos === 0;

$kpis = [
    [
        'titulo' => 'Disponibilidade geral',
        'tag'    => 'Máquinas ativas',
        'valor'  => formatarPct($disponibilidadeGeral),
        'cor'    => $disponibilidadeGeral !== null && $disponibilidadeGeral >= $metaMedia ? 'ok' : 'warn',
    ],
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
        'titulo' => 'Metas atingidas',
        'tag'    => 'Disponibilidade',
        'valor'  => "$maquinasNaMeta de $qtdMaquinas",
        'cor'    => $qtdMaquinas > 0 && $maquinasNaMeta === $qtdMaquinas ? 'ok' : 'warn',
    ],
];

$grafico = [
    'rotulos' => $porHora['rotulos'],
    'series'  => [['nome' => 'Disponibilidade', 'valores' => $porHora['valores']]],
    'meta'    => $qtdMaquinas > 0 ? round($metaMedia, 1) : null,
];

$maiorParada = max(array_merge([1], array_map(fn($p) => (int) $p['tempo_parado_min'], $paradasPorMotivo)));

$tituloPagina = 'Dashboard de Produção';
$subtituloPagina = 'Visão geral de produção das máquinas da planta';
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

            <!-- Disponibilidade por hora -->
            <section class="panel" aria-labelledby="t-hora">
                <header class="panel-head">
                    <div>
                        <h2 id="t-hora">Disponibilidade por Hora</h2>
                        <p>
                            <?php if ($porHora['dia']): ?>
                                Dia do último pulso registrado: <?= e(date('d/m/Y', strtotime($porHora['dia']))) ?>
                            <?php else: ?>
                                Tempo produzindo em relação ao tempo total, hora a hora
                            <?php endif; ?>
                        </p>
                    </div>
                    <?php if (!$semPulsos): ?>
                        <div class="legends">
                            <span class="legend"><span class="swatch"></span>Disponibilidade</span>
                            <span class="legend"><span class="swatch swatch-meta"></span>Meta média</span>
                        </div>
                    <?php endif; ?>
                </header>

                <?php if ($semPulsos): ?>
                    <p class="empty"><strong>Nenhum pulso registrado ainda.</strong>
                        O gráfico aparece assim que as máquinas começarem a enviar dados.</p>
                <?php else: ?>
                    <div class="chart" data-grafico="<?= e(json_encode($grafico)) ?>"
                         data-titulo="Disponibilidade por hora"></div>
                <?php endif; ?>
            </section>

            <!-- Disponibilidade vs. meta por máquina -->
            <section class="panel" aria-labelledby="t-meta">
                <header class="panel-head">
                    <div>
                        <h2 id="t-meta">Disponibilidade vs. Meta</h2>
                        <p>Desempenho de cada máquina ativa (o traço marca a meta)</p>
                    </div>
                </header>

                <?php if ($qtdMaquinas === 0): ?>
                    <p class="empty"><strong>Nenhuma máquina ativa.</strong>
                        Cadastre ou ative máquinas em <a href="configuracoes.php?aba=maquinas">Configurações</a>.</p>
                <?php else: ?>
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
                                    <span class="meta-mark" style="left: <?= (int) round($meta) ?>%"></span>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </section>

        </div>

        <!-- Tempo parado por motivo -->
        <section class="panel panel-gap" aria-labelledby="t-paradas">
            <header class="panel-head">
                <div>
                    <h2 id="t-paradas">Tempo Parado por Motivo</h2>
                    <p>Soma das máquinas ativas</p>
                </div>
                <a href="paradas.php" class="link-mais">Ver paradas →</a>
            </header>

            <?php if ($semPulsos): ?>
                <p class="empty"><strong>Sem paradas registradas.</strong></p>
            <?php else: ?>
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
            <?php endif; ?>
        </section>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
