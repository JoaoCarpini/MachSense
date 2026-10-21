<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/includes/funcoes.php';

/* ------------------------------------------------------------------
 * FILTROS (GET)
 * ------------------------------------------------------------------ */

$periodos = [
    'hoje'  => 'Hoje',
    '7dias' => 'Últimos 7 dias',
    'todos' => 'Todo o período',
];

$periodo = $_GET['periodo'] ?? '7dias';
if (!isset($periodos[$periodo])) {
    $periodo = '7dias';
}

$motivoId = filter_input(INPUT_GET, 'motivo', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;
$maquinaId = filter_input(INPUT_GET, 'maquina', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) ?: null;

$desde = match ($periodo) {
    'hoje'  => date('Y-m-d 00:00:00'),
    '7dias' => date('Y-m-d 00:00:00', strtotime('-6 days')),
    default => null,
};

$stmt = $pdo->prepare('SELECT id, descricao FROM motivos_parada ORDER BY descricao');
$stmt->execute();
$motivos = $stmt->fetchAll();

$stmt = $pdo->prepare('SELECT id, nome FROM maquinas ORDER BY nome');
$stmt->execute();
$maquinas = $stmt->fetchAll();

$paradas = buscarPeriodosParada($pdo, [
    'maquina_id' => $maquinaId,
    'motivo_id'  => $motivoId,
    'desde'      => $desde,
]);
$totalGeral = count(buscarPeriodosParada($pdo));

$tituloPagina = 'Registro de Paradas';
$subtituloPagina = 'Períodos de parada das máquinas e seus motivos';
$paginaAtiva = 'paradas';

require __DIR__ . '/includes/layout_inicio.php';
?>

        <div class="toolbar">
            <form class="filtros" method="get" action="paradas.php">
                <label>
                    <span>Motivo</span>
                    <select name="motivo">
                        <option value="">Todos</option>
                        <?php foreach ($motivos as $mot): ?>
                            <option value="<?= (int) $mot['id'] ?>"<?= $motivoId === (int) $mot['id'] ? ' selected' : '' ?>><?= e(nomeMotivo($mot['descricao'])) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Período</span>
                    <select name="periodo">
                        <?php foreach ($periodos as $valor => $rotulo): ?>
                            <option value="<?= e($valor) ?>"<?= $periodo === $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <label>
                    <span>Máquina</span>
                    <select name="maquina">
                        <option value="">Todas</option>
                        <?php foreach ($maquinas as $maq): ?>
                            <option value="<?= (int) $maq['id'] ?>"<?= $maquinaId === (int) $maq['id'] ? ' selected' : '' ?>><?= e($maq['nome']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>

                <button type="submit" class="btn btn-secundario">Filtrar</button>
            </form>

            <p class="contador">Mostrando <?= count($paradas) ?> de <?= $totalGeral ?> ocorrências registradas</p>
        </div>

        <div class="paradas-layout">

            <section class="panel panel-tabela" aria-label="Períodos de parada">
                <?php if ($totalGeral === 0): ?>
                    <p class="empty"><strong>Nenhuma parada registrada ainda.</strong>
                        As paradas aparecem aqui quando as máquinas enviarem pulsos de parada.</p>
                <?php elseif (!$paradas): ?>
                    <p class="empty"><strong>Nenhuma parada encontrada com esses filtros.</strong>
                        <a href="paradas.php?periodo=todos">Ver todas as paradas</a></p>
                <?php else: ?>
                    <div class="tabela-wrap">
                        <table class="tabela tabela-clicavel">
                            <thead>
                                <tr>
                                    <th>Máquina</th>
                                    <th>Motivo</th>
                                    <th>Início</th>
                                    <th>Duração</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($paradas as $p): ?>
                                    <tr tabindex="0"
                                        data-maquina="<?= e($p['maquina']) ?>"
                                        data-maquina-id="<?= (int) $p['maquina_id'] ?>"
                                        data-motivo="<?= e(nomeMotivo($p['motivo'])) ?>"
                                        data-inicio="<?= e(formatarDataHora($p['inicio'])) ?>"
                                        data-fim="<?= e(formatarDataHora($p['fim'])) ?>"
                                        data-duracao="<?= e(formatarMinutos((int) $p['duracao_min'])) ?>">
                                        <td><?= e($p['maquina']) ?></td>
                                        <td><span class="dot dot-warn"></span><?= e(nomeMotivo($p['motivo'])) ?></td>
                                        <td class="mono"><?= e(formatarDataHora($p['inicio'])) ?></td>
                                        <td class="mono"><?= e(formatarMinutos((int) $p['duracao_min'])) ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </section>

            <!-- Detalhes da ocorrência (somente leitura) -->
            <aside class="panel painel-detalhe" id="detalhe-parada" hidden aria-labelledby="t-detalhe">
                <header class="panel-head">
                    <h2 id="t-detalhe">Detalhes da Ocorrência</h2>
                    <button type="button" class="btn-fechar" id="fechar-detalhe" aria-label="Fechar detalhes">×</button>
                </header>

                <p class="detalhe-rotulo">Ocorrência selecionada</p>
                <p class="detalhe-titulo"><span data-campo="maquina"></span> — <span data-campo="motivo"></span></p>

                <dl class="indicadores">
                    <div><dt>Início</dt><dd data-campo="inicio"></dd></div>
                    <div><dt>Fim</dt><dd data-campo="fim"></dd></div>
                    <div><dt>Duração</dt><dd data-campo="duracao"></dd></div>
                </dl>

                <a href="#" class="btn btn-primario" id="detalhe-link-maquina">Ver máquina</a>
            </aside>

        </div>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
