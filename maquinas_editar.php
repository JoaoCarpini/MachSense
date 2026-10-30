<?php

// UPDATE — edita uma máquina. O id vem da URL (?id=), o salvamento é por POST.

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/protect.php';
require_once __DIR__ . '/includes/maquinas_funcoes.php';

$id = lerId($_GET['id'] ?? null);
if ($id === null) {
    definirAviso('erro', 'Máquina inválida.');
    redirecionar('maquinas_listar.php');
}

// Carrega a máquina
try {
    $stmt = $pdo->prepare('SELECT id, nome, meta_disponibilidade, ativa FROM maquinas WHERE id = ?');
    $stmt->execute([$id]);
    $maquina = $stmt->fetch();
} catch (PDOException $ex) {
    registrarErroBanco('maquinas_editar (carregar)', $ex);
    definirAviso('erro', MSG_ERRO_GENERICO);
    redirecionar('maquinas_listar.php');
}

if (!$maquina) {
    definirAviso('erro', 'Máquina não encontrada.');
    redirecionar('maquinas_listar.php');
}

$valores = [
    'nome'  => $maquina['nome'],
    'meta'  => rtrim(rtrim(number_format((float) $maquina['meta_disponibilidade'], 2, '.', ''), '0'), '.'),
    'ativa' => (string) (int) $maquina['ativa'],
];
$erros = [];
$avisoErro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    [$valores, $erros] = validarMaquina();

    if (!$erros) {
        try {
            $stmt = $pdo->prepare(
                'UPDATE maquinas SET nome = ?, meta_disponibilidade = ?, ativa = ? WHERE id = ?'
            );
            $stmt->execute([$valores['nome'], $valores['meta'], (int) $valores['ativa'], $id]);

            renovarTokenCsrf();
            definirAviso('ok', 'Máquina "' . $valores['nome'] . '" atualizada.');
            redirecionar('maquinas_listar.php');
        } catch (PDOException $ex) {
            if (ehNomeDuplicado($ex)) {
                $erros['nome'] = 'Já existe outra máquina com este nome. Escolha outro.';
            } else {
                registrarErroBanco('maquinas_editar (salvar)', $ex);
                $avisoErro = MSG_ERRO_GENERICO;
            }
        }
    }
}

$tituloPagina = 'Editar Máquina';
$subtituloPagina = 'Altere os dados da máquina';
$paginaAtiva = 'maquinas';

$urlAcao = 'maquinas_editar.php?id=' . $id;
$textoBotao = 'Salvar alterações';

require __DIR__ . '/includes/layout_inicio.php';
?>

        <section class="panel" aria-labelledby="t-form">
            <header class="panel-head">
                <div>
                    <h2 id="t-form">Dados da máquina</h2>
                    <p>Editando a máquina de código MAQ-<?= sprintf('%02d', $id) ?></p>
                </div>
            </header>

            <?php if ($avisoErro): ?>
                <p class="alerta alerta-erro" role="alert"><?= e($avisoErro) ?></p>
            <?php endif; ?>

            <?php require __DIR__ . '/includes/maquinas_form.php'; ?>
        </section>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
