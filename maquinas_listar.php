<?php

// READ — lista as máquinas cadastradas.

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/protect.php';
require_once __DIR__ . '/includes/maquinas_funcoes.php';

$maquinas = [];
$aviso = pegarAviso();

try {
    $stmt = $pdo->prepare(
        'SELECT m.id, m.nome, m.meta_disponibilidade, m.ativa,
                (SELECT COUNT(*) FROM pulsos p WHERE p.maquina_id = m.id) AS total_pulsos
           FROM maquinas m
          ORDER BY m.nome'
    );
    $stmt->execute();
    $maquinas = $stmt->fetchAll();
} catch (PDOException $ex) {
    registrarErroBanco('maquinas_listar', $ex);
    $aviso = ['tipo' => 'erro', 'mensagem' => MSG_ERRO_GENERICO];
}

$csrf = tokenCsrf();

$tituloPagina = 'Gerenciar Máquinas';
$subtituloPagina = 'Cadastro, edição e exclusão de máquinas';
$paginaAtiva = 'maquinas';

require __DIR__ . '/includes/layout_inicio.php';
?>

        <?php if ($aviso): ?>
            <p class="alerta alerta-<?= e($aviso['tipo']) ?>" role="status"><?= e($aviso['mensagem']) ?></p>
        <?php endif; ?>

        <div class="toolbar">
            <span class="contador"><?= count($maquinas) ?> máquina(s) cadastrada(s)</span>
            <div class="acoes-linha">
                <a href="maquinas.php" class="btn btn-secundario">Ver painel das máquinas</a>
                <a href="maquinas_criar.php" class="btn btn-primario">+ Nova máquina</a>
            </div>
        </div>

        <section class="panel panel-tabela" aria-label="Máquinas cadastradas">
            <?php if (!$maquinas): ?>
                <p class="empty"><strong>Nenhuma máquina cadastrada.</strong>
                    Use o botão "Nova máquina" para cadastrar a primeira.</p>
            <?php else: ?>
                <div class="tabela-wrap">
                    <table class="tabela">
                        <thead>
                            <tr>
                                <th>Nome</th>
                                <th class="num">Meta</th>
                                <th>Ativa</th>
                                <th class="num">Pulsos</th>
                                <th>Ações</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($maquinas as $m): ?>
                                <tr>
                                    <td><?= e($m['nome']) ?></td>
                                    <td class="num mono"><?= e(number_format((float) $m['meta_disponibilidade'], 2, ',', '.')) ?>%</td>
                                    <td>
                                        <?php if ((int) $m['ativa'] === 1): ?>
                                            <span class="selo selo-ok">Sim</span>
                                        <?php else: ?>
                                            <span class="selo selo-neutro">Não</span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="num mono"><?= (int) $m['total_pulsos'] ?></td>
                                    <td>
                                        <div class="acoes-linha">
                                            <a href="maquinas_editar.php?id=<?= (int) $m['id'] ?>"
                                               class="btn btn-secundario btn-pequeno"
                                               aria-label="Editar <?= e($m['nome']) ?>">Editar</a>

                                            <form method="post" action="maquinas_excluir.php" class="form-inline">
                                                <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                                <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                                <button type="submit" class="btn btn-perigo btn-pequeno"
                                                        aria-label="Excluir <?= e($m['nome']) ?>">Excluir</button>
                                            </form>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
