<?php

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/protect.php';
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

// Endereço desta página com os filtros atuais (para voltar a ela depois do POST)
$urlFiltros = 'paradas.php?' . http_build_query(array_filter([
    'periodo' => $periodo,
    'motivo'  => $motivoId,
    'maquina' => $maquinaId,
]));

/* ------------------------------------------------------------------
 * CORRIGIR O MOTIVO DE UMA PARADA (POST)
 *
 * Só o departamento Manutenção (e o administrador) pode trocar o motivo de
 * um período de parada, por exemplo quando o operador marcou "setup" e na
 * verdade foi manutenção. A permissão vem do BANCO a cada acesso.
 *
 * O período é identificado por máquina + motivo + início + fim, exatamente
 * como aparece na view vw_periodos_parada. Antes de gravar, conferimos que
 * esse período existe mesmo; depois trocamos o motivo de todos os pulsos
 * dele, numa única instrução UPDATE.
 * ------------------------------------------------------------------ */

$conta = contaAtual($pdo);
$podeEditarMotivo = podeEditarMotivoParada($conta);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $formatoDataHora = '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/';
    $idPositivo = fn(string $campo) => filter_var(campoPost($campo), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    $maquinaPost = $idPositivo('maquina_id');
    $motivoAtual = $idPositivo('motivo_atual');
    $motivoNovo = $idPositivo('motivo_novo');
    $inicio = campoPost('inicio');
    $fim = campoPost('fim');

    if (!csrfValido()) {
        definirAviso('erro', 'Sessão expirada. Recarregue a página e tente novamente.');
    } elseif (!$podeEditarMotivo) {
        definirAviso('erro', 'Somente o departamento Manutenção pode alterar o motivo de uma parada.');
    } elseif ($maquinaPost === false || $motivoAtual === false || $motivoNovo === false
        || preg_match($formatoDataHora, $inicio) !== 1 || preg_match($formatoDataHora, $fim) !== 1 || $fim <= $inicio) {
        definirAviso('erro', 'Dados da parada inválidos.');
    } elseif ($motivoNovo === $motivoAtual) {
        definirAviso('erro', 'Escolha um motivo diferente do atual.');
    } else {
        try {
            $stmt = $pdo->prepare('SELECT descricao FROM motivos_parada WHERE id = :id');
            $stmt->execute([':id' => $motivoNovo]);
            $descricaoNova = $stmt->fetchColumn();

            $stmt = $pdo->prepare(
                'SELECT 1 FROM vw_periodos_parada
                  WHERE maquina_id = :maquina AND motivo_id = :motivo AND inicio = :inicio AND fim = :fim'
            );
            $stmt->execute([':maquina' => $maquinaPost, ':motivo' => $motivoAtual, ':inicio' => $inicio, ':fim' => $fim]);
            $periodoExiste = (bool) $stmt->fetchColumn();

            if ($descricaoNova === false) {
                definirAviso('erro', 'Motivo inválido.');
            } elseif (!$periodoExiste) {
                definirAviso('erro', 'Esta parada não foi encontrada. Ela pode ter mudado; atualize a página.');
            } else {
                // O fim do período é o último pulso + 1 intervalo, por isso "<"
                $stmt = $pdo->prepare(
                    "UPDATE pulsos
                        SET motivo_id = :novo
                      WHERE maquina_id = :maquina
                        AND estado = 'parado'
                        AND motivo_id = :atual
                        AND `timestamp` >= :inicio
                        AND `timestamp` < :fim"
                );
                $stmt->execute([
                    ':novo' => $motivoNovo, ':maquina' => $maquinaPost, ':atual' => $motivoAtual,
                    ':inicio' => $inicio, ':fim' => $fim,
                ]);
                renovarTokenCsrf();
                definirAviso('ok', 'Motivo da parada alterado para "' . nomeMotivo((string) $descricaoNova)
                    . '" (' . $stmt->rowCount() . ' pulso(s) corrigido(s)).');
            }
        } catch (PDOException $ex) {
            error_log('[MachSense] paradas.php (alterar motivo): ' . $ex->getMessage());
            definirAviso('erro', 'Não foi possível alterar o motivo agora. Tente novamente em instantes.');
        }
    }

    header('Location: ' . $urlFiltros);
    exit;
}

$aviso = pegarAviso();

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

        <?php if ($aviso): ?>
            <p class="alerta alerta-<?= e($aviso['tipo']) ?>" role="status"><?= e($aviso['mensagem']) ?></p>
        <?php endif; ?>

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
                                        data-motivo-id="<?= (int) $p['motivo_id'] ?>"
                                        data-inicio-bruto="<?= e($p['inicio']) ?>"
                                        data-fim-bruto="<?= e($p['fim']) ?>"
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

                <?php if ($podeEditarMotivo): ?>
                    <?php /* Os campos escondidos são preenchidos pelo dashboard.js com a linha clicada.
                             O servidor confere tudo de novo (permissão, token e se a parada existe). */ ?>
                    <form method="post" action="<?= e($urlFiltros) ?>" class="form-crud panel-gap" id="form-motivo">
                        <input type="hidden" name="csrf" value="<?= e(csrfToken()) ?>">
                        <input type="hidden" name="maquina_id" value="">
                        <input type="hidden" name="motivo_atual" value="">
                        <input type="hidden" name="inicio" value="">
                        <input type="hidden" name="fim" value="">

                        <label class="campo">
                            <span>Corrigir motivo (Manutenção)</span>
                            <select name="motivo_novo">
                                <?php foreach ($motivos as $mot): ?>
                                    <option value="<?= (int) $mot['id'] ?>"><?= e(nomeMotivo($mot['descricao'])) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <div class="form-acoes">
                            <button type="submit" class="btn btn-secundario">Salvar motivo</button>
                        </div>
                    </form>
                <?php endif; ?>
            </aside>

        </div>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
