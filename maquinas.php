<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/funcoes.php';

// Indicadores de cada máquina ativa + estado e motivo do ÚLTIMO pulso
$stmt = $pdo->prepare(
    'SELECT v.machine_id, v.maquina, v.meta_pct, v.tempo_produzindo_min, v.tempo_parado_min,
            v.disponibilidade_pct, v.ultimo_pulso,
            ult.estado AS estado_atual,
            mp.descricao AS motivo_atual
       FROM vw_indicadores_maquina v
       JOIN maquinas m ON m.id = v.machine_id
       LEFT JOIN pulsos ult ON ult.maquina_id = v.machine_id AND ult.`timestamp` = v.ultimo_pulso
       LEFT JOIN motivos_parada mp ON mp.id = ult.motivo_id
      WHERE m.ativa = 1
      ORDER BY v.maquina'
);
$stmt->execute();
$maquinas = $stmt->fetchAll();

$tituloPagina = 'Selecionar Máquina';
$subtituloPagina = 'Escolha uma máquina para visualizar os detalhes de produção';
$paginaAtiva = 'maquinas';

require __DIR__ . '/includes/layout_inicio.php';
?>

        <?php if (!$maquinas): ?>
            <p class="empty"><strong>Nenhuma máquina ativa.</strong>
                Cadastre ou ative máquinas em <a href="configuracoes.php?aba=maquinas">Configurações</a>.</p>
        <?php else: ?>
            <section class="cards-maquinas" aria-label="Máquinas ativas">
                <?php foreach ($maquinas as $m):
                    $pct = $m['disponibilidade_pct'] === null ? null : (float) $m['disponibilidade_pct'];
                    $meta = (float) $m['meta_pct']; ?>
                    <article class="card-maquina">
                        <div class="card-maquina-head">
                            <h2><?= e($m['maquina']) ?></h2>
                            <strong class="pct-<?= classeDisponibilidade($pct, $meta) ?>"><?= e(formatarPct($pct)) ?></strong>
                        </div>

                        <?php if ($m['estado_atual'] === 'rodando'): ?>
                            <span class="selo selo-ok">Produzindo</span>
                        <?php elseif ($m['estado_atual'] === 'parado'): ?>
                            <span class="selo selo-parada">Parada · <?= e(nomeMotivo($m['motivo_atual'] ?? '')) ?></span>
                        <?php else: ?>
                            <span class="selo selo-neutro">Sem dados</span>
                        <?php endif; ?>

                        <dl class="card-maquina-dados">
                            <div><dt>Disponibilidade</dt><dd><?= e(formatarPct($pct)) ?></dd></div>
                            <div><dt>Meta</dt><dd><?= e(formatarPct($meta)) ?></dd></div>
                            <div><dt>Tempo produzindo</dt><dd><?= e(formatarMinutos((int) $m['tempo_produzindo_min'])) ?></dd></div>
                            <div><dt>Tempo parado</dt><dd><?= e(formatarMinutos((int) $m['tempo_parado_min'])) ?></dd></div>
                        </dl>

                        <a class="link-mais" href="maquina.php?id=<?= (int) $m['machine_id'] ?>">VER DETALHES →</a>
                    </article>
                <?php endforeach; ?>
            </section>
        <?php endif; ?>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
