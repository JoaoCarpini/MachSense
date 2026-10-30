<?php

// CREATE — cadastra uma máquina.

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/protect.php';
require_once __DIR__ . '/includes/maquinas_funcoes.php';

$valores = ['nome' => '', 'meta' => '85', 'ativa' => '1'];
$erros = [];
$avisoErro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    [$valores, $erros] = validarMaquina();

    if (!$erros) {
        try {
            $stmt = $pdo->prepare(
                'INSERT INTO maquinas (nome, meta_disponibilidade, ativa) VALUES (?, ?, ?)'
            );
            $stmt->execute([$valores['nome'], $valores['meta'], (int) $valores['ativa']]);

            renovarTokenCsrf();
            definirAviso('ok', 'Máquina "' . $valores['nome'] . '" cadastrada.');
            redirecionar('maquinas_listar.php');
        } catch (PDOException $ex) {
            if (ehNomeDuplicado($ex)) {
                $erros['nome'] = 'Já existe uma máquina com este nome. Escolha outro.';
            } else {
                registrarErroBanco('maquinas_criar', $ex);
                $avisoErro = MSG_ERRO_GENERICO;
            }
        }
    }
}

$tituloPagina = 'Nova Máquina';
$subtituloPagina = 'Cadastre uma máquina e a meta de disponibilidade dela';
$paginaAtiva = 'maquinas';

$urlAcao = 'maquinas_criar.php';
$textoBotao = 'Cadastrar máquina';

require __DIR__ . '/includes/layout_inicio.php';
?>

        <section class="panel" aria-labelledby="t-form">
            <header class="panel-head">
                <div>
                    <h2 id="t-form">Dados da máquina</h2>
                    <p>Todos os campos são obrigatórios</p>
                </div>
            </header>

            <?php if ($avisoErro): ?>
                <p class="alerta alerta-erro" role="alert"><?= e($avisoErro) ?></p>
            <?php endif; ?>

            <?php require __DIR__ . '/includes/maquinas_form.php'; ?>
        </section>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
