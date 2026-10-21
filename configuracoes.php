<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/Usuario.php';
require_once __DIR__ . '/includes/funcoes.php';

$abas = [
    'perfil'   => 'Perfil',
    'maquinas' => 'Máquinas',
];
$abasEmBreve = ['Turnos', 'Notificações', 'Sistema'];

$aba = $_GET['aba'] ?? 'perfil';
if (!isset($abas[$aba])) {
    $aba = 'perfil';
}

/** Converte "85,5" ou "85.5" em número entre 0 e 100 (ou null se inválido). */
function lerMeta(mixed $valor): ?float
{
    $meta = filter_var(str_replace(',', '.', trim((string) $valor)), FILTER_VALIDATE_FLOAT);

    return $meta !== false && $meta >= 0 && $meta <= 100 ? round($meta, 2) : null;
}

/* ------------------------------------------------------------------
 * AÇÕES (POST) — sempre redireciona depois (padrão PRG)
 * ------------------------------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    $aba = $acao === 'trocar_senha' ? 'perfil' : 'maquinas';

    if (!csrfValido()) {
        definirAviso('erro', 'Sessão expirada. Recarregue a página e tente novamente.');
    } elseif ($acao === 'trocar_senha') {
        $atual = $_POST['senha_atual'] ?? '';
        $nova = $_POST['nova_senha'] ?? '';
        $usuario = Usuario::buscarPorId((int) $_SESSION['usuario_id']);

        if (!$usuario || !password_verify($atual, $usuario['senha'])) {
            definirAviso('erro', 'A senha atual está incorreta.');
        } elseif (mb_strlen($nova) < 6) {
            definirAviso('erro', 'A nova senha deve ter pelo menos 6 caracteres.');
        } elseif ($nova !== ($_POST['confirmar_senha'] ?? '')) {
            definirAviso('erro', 'A confirmação não confere com a nova senha.');
        } else {
            Usuario::alterarSenha((int) $usuario['id'], $nova);
            definirAviso('ok', 'Senha alterada com sucesso.');
        }
    } elseif ($acao === 'adicionar_maquina') {
        $nome = trim($_POST['nome'] ?? '');
        $meta = lerMeta($_POST['meta'] ?? '');

        if ($nome === '' || !mb_check_encoding($nome, 'UTF-8') || mb_strlen($nome) > 100) {
            definirAviso('erro', 'Informe um nome de máquina com até 100 caracteres.');
        } elseif ($meta === null) {
            definirAviso('erro', 'A meta de disponibilidade deve ser um número entre 0 e 100.');
        } else {
            try {
                $stmt = $pdo->prepare('INSERT INTO maquinas (nome, meta_disponibilidade) VALUES (:nome, :meta)');
                $stmt->execute([':nome' => $nome, ':meta' => $meta]);
                definirAviso('ok', "Máquina \"$nome\" cadastrada.");
            } catch (PDOException $ex) {
                // 1062 = nome duplicado (coluna UNIQUE)
                if (($ex->errorInfo[1] ?? null) === 1062 || $ex->getCode() === '23000') {
                    definirAviso('erro', "Já existe uma máquina com o nome \"$nome\".");
                } else {
                    throw $ex;
                }
            }
        }
    } elseif ($acao === 'editar_meta') {
        $id = (int) filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        $meta = lerMeta($_POST['meta'] ?? '');

        $stmt = $pdo->prepare('SELECT nome FROM maquinas WHERE id = :id');
        $stmt->execute([':id' => $id]);
        $nome = $stmt->fetchColumn();

        if ($nome === false) {
            definirAviso('erro', 'Máquina não encontrada.');
        } elseif ($meta === null) {
            definirAviso('erro', 'A meta de disponibilidade deve ser um número entre 0 e 100.');
        } else {
            $stmt = $pdo->prepare('UPDATE maquinas SET meta_disponibilidade = :meta WHERE id = :id');
            $stmt->execute([':meta' => $meta, ':id' => $id]);
            definirAviso('ok', "Meta de \"$nome\" atualizada para " . formatarPct($meta) . '.');
        }
    } elseif ($acao === 'alternar_ativa') {
        $id = filter_var($_POST['id'] ?? '', FILTER_VALIDATE_INT);
        $stmt = $pdo->prepare('UPDATE maquinas SET ativa = 1 - ativa WHERE id = :id');
        $stmt->execute([':id' => (int) $id]);
        definirAviso($stmt->rowCount() > 0 ? 'ok' : 'erro',
            $stmt->rowCount() > 0 ? 'Situação da máquina atualizada.' : 'Máquina não encontrada.');
    } else {
        definirAviso('erro', 'Ação inválida.');
    }

    header('Location: configuracoes.php?aba=' . $aba);
    exit;
}

/* ------------------------------------------------------------------
 * DADOS DA ABA
 * ------------------------------------------------------------------ */

$aviso = pegarAviso();
$csrf = csrfToken();

if ($aba === 'perfil') {
    $usuario = Usuario::buscarPorId((int) $_SESSION['usuario_id']);
} else {
    $stmt = $pdo->prepare(
        'SELECT m.id, m.nome, m.meta_disponibilidade, m.ativa,
                (SELECT COUNT(*) FROM pulsos p WHERE p.maquina_id = m.id) AS total_pulsos
           FROM maquinas m
          ORDER BY m.ativa DESC, m.nome'
    );
    $stmt->execute();
    $maquinas = $stmt->fetchAll();
    $intervalo = intervaloPulso($pdo);
}

$tituloPagina = 'Configurações do Sistema';
$subtituloPagina = 'Perfil do usuário e metas de disponibilidade das máquinas';
$paginaAtiva = 'configuracoes';

require __DIR__ . '/includes/layout_inicio.php';
?>

        <div class="config-layout">

            <nav class="abas" aria-label="Seções de configuração">
                <?php foreach ($abas as $chave => $rotulo): ?>
                    <a href="configuracoes.php?aba=<?= e($chave) ?>"
                       class="aba<?= $aba === $chave ? ' is-active' : '' ?>"
                       <?= $aba === $chave ? 'aria-current="page"' : '' ?>><?= e($rotulo) ?></a>
                <?php endforeach; ?>
                <?php foreach ($abasEmBreve as $rotulo): ?>
                    <span class="aba aba-desabilitada" aria-disabled="true"><?= e($rotulo) ?> <small>Em breve</small></span>
                <?php endforeach; ?>
            </nav>

            <div class="config-conteudo">

                <?php if ($aviso): ?>
                    <p class="alerta alerta-<?= e($aviso['tipo']) ?>" role="status"><?= e($aviso['mensagem']) ?></p>
                <?php endif; ?>

                <?php if ($aba === 'perfil'): ?>

                    <section class="panel" aria-labelledby="t-perfil">
                        <header class="panel-head">
                            <div>
                                <h2 id="t-perfil">Dados do Usuário</h2>
                                <p>Informações da conta conectada</p>
                            </div>
                        </header>

                        <dl class="indicadores">
                            <div><dt>Nome</dt><dd><?= e($usuario['nome'] ?? '') ?></dd></div>
                            <div><dt>Usuário</dt><dd><?= e($usuario['usuario'] ?? '') ?></dd></div>
                            <div><dt>Cargo</dt><dd><?= e($usuario['cargo'] ?? 'Não informado') ?></dd></div>
                            <div><dt>Cadastrado em</dt><dd><?= e(formatarDataHora($usuario['criado_em'] ?? null)) ?></dd></div>
                        </dl>
                    </section>

                    <section class="panel panel-gap" aria-labelledby="t-senha">
                        <header class="panel-head">
                            <div>
                                <h2 id="t-senha">Trocar Senha</h2>
                                <p>A nova senha deve ter pelo menos 6 caracteres</p>
                            </div>
                        </header>

                        <form method="post" action="configuracoes.php?aba=perfil" class="form-grid">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="acao" value="trocar_senha">

                            <label class="campo">
                                <span>Senha atual</span>
                                <input type="password" name="senha_atual" autocomplete="current-password" required>
                            </label>
                            <label class="campo">
                                <span>Nova senha</span>
                                <input type="password" name="nova_senha" autocomplete="new-password" minlength="6" required>
                            </label>
                            <label class="campo">
                                <span>Confirmar nova senha</span>
                                <input type="password" name="confirmar_senha" autocomplete="new-password" minlength="6" required>
                            </label>

                            <div class="form-acoes">
                                <button type="submit" class="btn btn-primario">Salvar nova senha</button>
                            </div>
                        </form>
                    </section>

                <?php else: ?>

                    <section class="panel" aria-labelledby="t-maquinas">
                        <header class="panel-head">
                            <div>
                                <h2 id="t-maquinas">Máquinas Cadastradas</h2>
                                <p>Metas de disponibilidade e situação de cada máquina.
                                    Cada pulso representa <?= (int) $intervalo ?> minutos (parâmetro intervalo_pulso_min).</p>
                            </div>
                        </header>

                        <form method="post" action="configuracoes.php?aba=maquinas" class="form-linha">
                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                            <input type="hidden" name="acao" value="adicionar_maquina">

                            <label class="campo campo-largo">
                                <span>Nome da nova máquina</span>
                                <input type="text" name="nome" maxlength="100" required>
                            </label>
                            <label class="campo">
                                <span>Meta (%)</span>
                                <input type="number" name="meta" min="0" max="100" step="0.01" value="85" required>
                            </label>
                            <button type="submit" class="btn btn-primario">+ Adicionar máquina</button>
                        </form>

                        <?php if (!$maquinas): ?>
                            <p class="empty"><strong>Nenhuma máquina cadastrada.</strong>
                                Use o formulário acima para adicionar a primeira.</p>
                        <?php else: ?>
                            <ul class="lista-maquinas">
                                <?php foreach ($maquinas as $m):
                                    $ativa = (int) $m['ativa'] === 1; ?>
                                    <li class="maq-linha<?= $ativa ? '' : ' is-inativa' ?>">
                                        <div class="maq-info">
                                            <span class="maq-codigo">MAQ-<?= sprintf('%02d', $m['id']) ?></span>
                                            <strong><?= e($m['nome']) ?></strong>
                                            <small><?= (int) $m['total_pulsos'] ?> pulsos registrados</small>
                                        </div>

                                        <form method="post" action="configuracoes.php?aba=maquinas" class="maq-meta">
                                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="acao" value="editar_meta">
                                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                            <label class="campo">
                                                <span>Meta disponibilidade (%)</span>
                                                <input type="number" name="meta" min="0" max="100" step="0.01"
                                                       value="<?= e((string) (float) $m['meta_disponibilidade']) ?>" required>
                                            </label>
                                            <button type="submit" class="btn btn-secundario btn-pequeno">Salvar</button>
                                        </form>

                                        <form method="post" action="configuracoes.php?aba=maquinas" class="maq-status">
                                            <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
                                            <input type="hidden" name="acao" value="alternar_ativa">
                                            <input type="hidden" name="id" value="<?= (int) $m['id'] ?>">
                                            <span><?= $ativa ? 'Ativa' : 'Inativa' ?></span>
                                            <button type="submit" class="switch<?= $ativa ? ' is-on' : '' ?>"
                                                    aria-pressed="<?= $ativa ? 'true' : 'false' ?>"
                                                    title="<?= $ativa ? 'Desativar' : 'Ativar' ?> máquina"
                                                    aria-label="<?= $ativa ? 'Desativar' : 'Ativar' ?> <?= e($m['nome']) ?>"></button>
                                        </form>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    </section>

                <?php endif; ?>

            </div>
        </div>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
