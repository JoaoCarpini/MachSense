<?php

/*
 * Formulário de máquina, usado por maquinas_criar.php e maquinas_editar.php.
 * Espera: $valores (nome, meta, ativa), $erros, $urlAcao, $textoBotao.
 */
?>
        <form method="post" action="<?= e($urlAcao) ?>" class="form-crud" novalidate>
            <input type="hidden" name="csrf" value="<?= e(tokenCsrf()) ?>">

            <label class="campo">
                <span>Nome da máquina</span>
                <input type="text" name="nome" maxlength="60" required
                       value="<?= e($valores['nome']) ?>" autocomplete="off">
                <?php if (isset($erros['nome'])): ?>
                    <small class="erro-campo"><?= e($erros['nome']) ?></small>
                <?php else: ?>
                    <small class="dica-campo">De 2 a 60 caracteres. Não pode repetir o nome de outra máquina.</small>
                <?php endif; ?>
            </label>

            <label class="campo">
                <span>Meta de disponibilidade (%)</span>
                <input type="text" name="meta" inputmode="decimal" required
                       value="<?= e($valores['meta']) ?>" autocomplete="off">
                <?php if (isset($erros['meta'])): ?>
                    <small class="erro-campo"><?= e($erros['meta']) ?></small>
                <?php else: ?>
                    <small class="dica-campo">Número de 0 a 100 (ex.: 85 ou 87,5).</small>
                <?php endif; ?>
            </label>

            <fieldset class="campo campo-opcoes">
                <legend>Máquina ativa?</legend>
                <label><input type="radio" name="ativa" value="1"<?= $valores['ativa'] === '1' ? ' checked' : '' ?>> Sim</label>
                <label><input type="radio" name="ativa" value="0"<?= $valores['ativa'] === '0' ? ' checked' : '' ?>> Não</label>
                <?php if (isset($erros['ativa'])): ?>
                    <small class="erro-campo"><?= e($erros['ativa']) ?></small>
                <?php endif; ?>
            </fieldset>

            <div class="form-acoes acoes-linha">
                <button type="submit" class="btn btn-primario"><?= e($textoBotao) ?></button>
                <a href="maquinas_listar.php" class="btn btn-secundario">Cancelar</a>
            </div>
        </form>
