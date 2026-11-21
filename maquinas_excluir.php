<?php

// DELETE — exclui uma máquina. Só aceita POST e pede confirmação.
//
// 1º POST (botão "Excluir" da lista): mostra a tela de confirmação.
// 2º POST (botão "Sim, excluir", com confirmar=1): apaga de fato.
//
// A tabela pulsos tem FK para maquinas com ON DELETE RESTRICT, então os pulsos
// da máquina precisam ser apagados ANTES dela. As duas exclusões acontecem na
// mesma transação: ou apaga tudo, ou não apaga nada (rollback).

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/protect.php';
require_once __DIR__ . '/includes/maquinas_funcoes.php';

exigirMetodo('POST');
exigirCsrf();

$id = lerId($_POST['id'] ?? null);
if ($id === null) {
    definirAviso('erro', 'Máquina inválida.');
    redirecionar('maquinas_listar.php');
}

// Carrega a máquina e quantos pulsos ela tem
try {
    $stmt = $pdo->prepare(
        'SELECT m.id, m.nome,
                (SELECT COUNT(*) FROM pulsos p WHERE p.maquina_id = m.id) AS total_pulsos
           FROM maquinas m
          WHERE m.id = ?'
    );
    $stmt->execute([$id]);
    $maquina = $stmt->fetch();
} catch (PDOException $ex) {
    registrarErroBanco('maquinas_excluir (carregar)', $ex);
    definirAviso('erro', MSG_ERRO_GENERICO);
    redirecionar('maquinas_listar.php');
}

if (!$maquina) {
    definirAviso('erro', 'Máquina não encontrada. Ela pode já ter sido excluída.');
    redirecionar('maquinas_listar.php');
}

// 2º passo: o usuário confirmou
if (campoPost('confirmar') === '1') {
    try {
        $pdo->beginTransaction();

        $stmt = $pdo->prepare('DELETE FROM pulsos WHERE maquina_id = ?');
        $stmt->execute([$id]);

        $stmt = $pdo->prepare('DELETE FROM maquinas WHERE id = ?');
        $stmt->execute([$id]);

        if ($stmt->rowCount() === 0) {
            $pdo->rollBack();
            definirAviso('erro', 'Máquina não encontrada. Ela pode já ter sido excluída.');
        } else {
            $pdo->commit();
            renovarTokenCsrf();
            definirAviso('ok', 'Máquina "' . $maquina['nome'] . '" excluída'
                . ((int) $maquina['total_pulsos'] > 0 ? ' junto com ' . (int) $maquina['total_pulsos'] . ' pulso(s).' : '.'));
        }
    } catch (PDOException $ex) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        registrarErroBanco('maquinas_excluir', $ex);
        definirAviso('erro', MSG_ERRO_GENERICO . ' Nada foi excluído.');
    }

    redirecionar('maquinas_listar.php');
}

// 1º passo: tela de confirmação
$totalPulsos = (int) $maquina['total_pulsos'];

$tituloPagina = 'Excluir Máquina';
$subtituloPagina = 'Confirme a exclusão antes de continuar';
$paginaAtiva = 'maquinas';

require __DIR__ . '/includes/layout_inicio.php';
?>

        <section class="panel" aria-labelledby="t-confirma">
            <header class="panel-head">
                <div>
                    <h2 id="t-confirma">Excluir "<?= e($maquina['nome']) ?>"?</h2>
                    <p>Esta ação não pode ser desfeita</p>
                </div>
            </header>

            <?php if ($totalPulsos > 0): ?>
                <p class="alerta alerta-erro" role="alert">
                    Esta máquina tem <strong><?= $totalPulsos ?></strong> pulso(s) registrado(s).
                    Eles serão apagados junto com a máquina e deixarão de aparecer no dashboard e nos relatórios.
                </p>
            <?php else: ?>
                <p class="texto-fraco">Esta máquina não tem pulsos registrados.</p>
            <?php endif; ?>

            <form method="post" action="maquinas_excluir.php" class="form-crud">
                <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">
                <input type="hidden" name="id" value="<?= (int) $maquina['id'] ?>">
                <input type="hidden" name="confirmar" value="1">

                <div class="form-acoes acoes-linha">
                    <button type="submit" class="btn btn-perigo">Sim, excluir</button>
                    <a href="maquinas_listar.php" class="btn btn-secundario">Cancelar</a>
                </div>
            </form>
        </section>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
