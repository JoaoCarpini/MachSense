<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/funcoes.php';

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$maquina = null;

if ($id) {
    $stmt = $pdo->prepare(
        'SELECT v.*, m.ativa,
                ult.estado AS estado_atual,
                mp.descricao AS motivo_atual
           FROM vw_indicadores_maquina v
           JOIN maquinas m ON m.id = v.machine_id
           LEFT JOIN pulsos ult ON ult.maquina_id = v.machine_id AND ult.`timestamp` = v.ultimo_pulso
           LEFT JOIN motivos_parada mp ON mp.id = ult.motivo_id
          WHERE v.machine_id = :id'
    );
    $stmt->execute([':id' => $id]);
    $maquina = $stmt->fetch() ?: null;
}

if ($maquina) {
    $pct = $maquina['disponibilidade_pct'] === null ? null : (float) $maquina['disponibilidade_pct'];
    $meta = (float) $maquina['meta_pct'];
    $semPulsos = (int) $maquina['total_pulsos'] === 0;

    $porHora = disponibilidadePorHora($pdo, $id);
    $grafico = [
        'rotulos' => $porHora['rotulos'],
        'series'  => [['nome' => $maquina['maquina'], 'valores' => $porHora['valores']]],
        'meta'    => $meta,
    ];

    $stmt = $pdo->prepare(
        'SELECT motivo, tempo_parado_min
           FROM vw_paradas_por_motivo
          WHERE machine_id = :id
          ORDER BY tempo_parado_min DESC, motivo'
    );
    $stmt->execute([':id' => $id]);
    $paradasPorMotivo = $stmt->fetchAll();
    $maiorParada = max(array_merge([1], array_map(fn($p) => (int) $p['tempo_parado_min'], $paradasPorMotivo)));

    $historico = buscarPeriodosParada($pdo, ['maquina_id' => $id, 'limite' => 10]);

    $tituloPagina = $maquina['maquina'];
    $subtituloPagina = 'Detalhes de disponibilidade e paradas da máquina';
} else {
    http_response_code(404);
    $tituloPagina = 'Máquina não encontrada';
    $subtituloPagina = 'Detalhes de disponibilidade e paradas da máquina';
}

$paginaAtiva = 'maquinas';

require __DIR__ . '/includes/layout_inicio.php';
?>

        <?php if (!$maquina): ?>
            <p class="empty"><strong>A máquina solicitada não existe.</strong>
                Verifique o endereço ou <a href="maquinas.php">volte para a lista de máquinas</a>.</p>
        <?php else: ?>

            <div class="detalhe-topo">
                <a href="maquinas.php" class="migalha">Máquinas</a>
                <span class="migalha-sep">/</span>
                <span class="migalha-atual"><?= e($maquina['maquina']) ?></span>

                <?php if ($maquina['estado_atual'] === 'rodando'): ?>
                    <span class="selo selo-ok">Produzindo</span>
                <?php elseif ($maquina['estado_atual'] === 'parado'): ?>
                    <span class="selo selo-parada">Parada · <?= e(nomeMotivo($maquina['motivo_atual'] ?? '')) ?></span>
                <?php else: ?>
                    <span class="selo selo-neutro">Sem dados</span>
                <?php endif; ?>

                <?php if (!(int) $maquina['ativa']): ?>
                    <span class="selo selo-neutro">Inativa</span>
                <?php endif; ?>

                <span class="detalhe-atualizacao">Último pulso: <?= e(formatarDataHora($maquina['ultimo_pulso'])) ?></span>
            </div>

            <div class="row">

                <!-- Disponibilidade por hora -->
                <section class="panel" aria-labelledby="t-hora">
                    <header class="panel-head">
                        <div>
                            <h2 id="t-hora">Disponibilidade por Hora</h2>
                            <p>
                                <?php if ($porHora['dia']): ?>
                                    Dia do último pulso: <?= e(date('d/m/Y', strtotime($porHora['dia']))) ?>
                                <?php else: ?>
                                    Tempo produzindo em relação ao tempo total, hora a hora
                                <?php endif; ?>
                            </p>
                        </div>
                        <?php if (!$semPulsos): ?>
                            <div class="legends">
                                <span class="legend"><span class="swatch"></span>Disponibilidade</span>
                                <span class="legend"><span class="swatch swatch-meta"></span>Meta</span>
                            </div>
                        <?php endif; ?>
                    </header>

                    <?php if ($semPulsos): ?>
                        <p class="empty"><strong>Nenhum pulso registrado para esta máquina.</strong>
                            O gráfico aparece assim que ela começar a enviar dados.</p>
                    <?php else: ?>
                        <div class="chart" data-grafico="<?= e(json_encode($grafico)) ?>"
                             data-titulo="Disponibilidade por hora"></div>
                    <?php endif; ?>
                </section>

                <!-- Indicadores -->
                <section class="panel" aria-labelledby="t-ind">
                    <header class="panel-head">
                        <div>
                            <h2 id="t-ind">Indicadores da Máquina</h2>
                            <p>Acumulado de todos os pulsos registrados</p>
                        </div>
                    </header>

                    <dl class="indicadores">
                        <div>
                            <dt>Disponibilidade</dt>
                            <dd class="pct-<?= classeDisponibilidade($pct, $meta) ?>"><?= e(formatarPct($pct)) ?></dd>
                        </div>
                        <div><dt>Meta de disponibilidade</dt><dd><?= e(formatarPct($meta)) ?></dd></div>
                        <div>
                            <dt>Situação</dt>
                            <dd><?= e($maquina['atingiu_meta'] === null ? 'Sem dados' : ((int) $maquina['atingiu_meta'] ? 'Na meta' : 'Abaixo da meta')) ?></dd>
                        </div>
                        <div><dt>Tempo produzindo</dt><dd><?= e(formatarMinutos((int) $maquina['tempo_produzindo_min'])) ?></dd></div>
                        <div><dt>Tempo parado</dt><dd><?= e(formatarMinutos((int) $maquina['tempo_parado_min'])) ?></dd></div>
                        <div><dt>Tempo total monitorado</dt><dd><?= e(formatarMinutos((int) $maquina['tempo_total_min'])) ?></dd></div>
                        <div><dt>Primeiro pulso</dt><dd><?= e(formatarDataHora($maquina['primeiro_pulso'])) ?></dd></div>
                    </dl>
                </section>

            </div>

            <div class="row row-gap">

                <!-- Tempo parado por motivo -->
                <section class="panel" aria-labelledby="t-motivos">
                    <header class="panel-head">
                        <div>
                            <h2 id="t-motivos">Tempo Parado por Motivo</h2>
                            <p>Acumulado desta máquina</p>
                        </div>
                    </header>

                    <?php if ((int) $maquina['tempo_parado_min'] === 0): ?>
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

                <!-- Histórico de paradas -->
                <section class="panel" aria-labelledby="t-hist">
                    <header class="panel-head">
                        <div>
                            <h2 id="t-hist">Histórico de Paradas</h2>
                            <p>Últimos 10 períodos de parada</p>
                        </div>
                        <?php if ($historico): ?>
                            <a href="paradas.php?maquina=<?= (int) $id ?>" class="link-mais">Ver todas →</a>
                        <?php endif; ?>
                    </header>

                    <?php if (!$historico): ?>
                        <p class="empty"><strong>Nenhum período de parada.</strong></p>
                    <?php else: ?>
                        <ol class="timeline">
                            <?php foreach ($historico as $h): ?>
                                <li>
                                    <time datetime="<?= e(date('c', strtotime($h['inicio']))) ?>"><?= e(date('d/m/Y', strtotime($h['inicio']))) ?></time>
                                    <div>
                                        <strong><?= e(nomeMotivo($h['motivo'])) ?></strong>
                                        <p>
                                            <?= e(date('H:i', strtotime($h['inicio']))) ?> às <?= e(date('H:i', strtotime($h['fim']))) ?>
                                            · <?= e(formatarMinutos((int) $h['duracao_min'])) ?>
                                        </p>
                                    </div>
                                </li>
                            <?php endforeach; ?>
                        </ol>
                    <?php endif; ?>
                </section>

            </div>

        <?php endif; ?>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
