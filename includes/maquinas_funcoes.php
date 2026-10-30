<?php

/*
 * Funções do CRUD de máquinas (maquinas_*.php).
 * Depende de includes/seguranca.php (já carregado pelo protect.php).
 *
 * Não inclua este arquivo junto com includes/funcoes.php: as duas bibliotecas
 * definem csrfValido() com assinaturas diferentes. Os avisos abaixo usam a
 * mesma chave de sessão ($_SESSION['aviso']) das outras páginas.
 */

const MAQUINA_NOME_MIN = 2;
const MAQUINA_NOME_MAX = 60;

/* ------------------------------------------------------------------
 * AVISOS (mensagem que sobrevive ao redirecionamento)
 * ------------------------------------------------------------------ */

if (!function_exists('definirAviso')) {
    function definirAviso(string $tipo, string $mensagem): void
    {
        $_SESSION['aviso'] = ['tipo' => $tipo, 'mensagem' => $mensagem];
    }
}

if (!function_exists('pegarAviso')) {
    function pegarAviso(): ?array
    {
        $aviso = $_SESSION['aviso'] ?? null;
        unset($_SESSION['aviso']);

        return $aviso;
    }
}

/* ------------------------------------------------------------------
 * REQUISIÇÃO
 * ------------------------------------------------------------------ */

/** Lê um id inteiro positivo (de GET ou POST). Devolve null se não for. */
function lerId(mixed $valor): ?int
{
    if (!is_string($valor) && !is_int($valor)) {
        return null;
    }

    $id = filter_var($valor, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

    return $id === false ? null : $id;
}

/** Encerra com 405 se o método não for o esperado. */
function exigirMetodo(string $metodo): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== $metodo) {
        http_response_code(405);
        header('Allow: ' . $metodo);
        exit('Método não permitido.');
    }
}

/** Encerra com 403 se o token CSRF do formulário não confere. */
function exigirCsrf(): void
{
    if (!csrfValido(campoPost('csrf'))) {
        http_response_code(403);
        exit('Requisição recusada: token CSRF ausente ou inválido. Volte e recarregue a página.');
    }
}

function redirecionar(string $destino): never
{
    header('Location: ' . $destino);
    exit;
}

/* ------------------------------------------------------------------
 * VALIDAÇÃO (no servidor)
 * ------------------------------------------------------------------ */

/**
 * Valida os campos do formulário de máquina.
 * Devolve [dados limpos, erros por campo]. Sem erros => pode gravar.
 *
 * @return array{0: array{nome: string, meta: string, ativa: string}, 1: array<string, string>}
 */
function validarMaquina(): array
{
    $nome = trim(campoPost('nome'));
    $meta = trim(campoPost('meta'));
    $ativa = campoPost('ativa');
    $erros = [];

    // Nome: 2 a 60 caracteres, UTF-8 válido, sem caracteres de controle
    if (!mb_check_encoding($nome, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $nome) === 1) {
        $erros['nome'] = 'O nome contém caracteres inválidos.';
    } elseif (mb_strlen($nome) < MAQUINA_NOME_MIN || mb_strlen($nome) > MAQUINA_NOME_MAX) {
        $erros['nome'] = 'O nome deve ter de ' . MAQUINA_NOME_MIN . ' a ' . MAQUINA_NOME_MAX . ' caracteres.';
    }

    // Meta: número de 0 a 100, com até 2 casas (aceita vírgula ou ponto)
    $metaNormalizada = str_replace(',', '.', $meta);
    if (preg_match('/^\d{1,3}(\.\d{1,2})?$/', $metaNormalizada) !== 1 || (float) $metaNormalizada > 100) {
        $erros['meta'] = 'A meta deve ser um número de 0 a 100, com no máximo 2 casas decimais.';
    }

    // Ativa: só "1" (sim) ou "0" (não)
    if ($ativa !== '0' && $ativa !== '1') {
        $erros['ativa'] = 'Escolha se a máquina está ativa.';
    }

    return [['nome' => $nome, 'meta' => $metaNormalizada, 'ativa' => $ativa], $erros];
}

/* ------------------------------------------------------------------
 * ERROS DE BANCO
 * ------------------------------------------------------------------ */

/** 1062 = violação de chave UNIQUE (nome duplicado). */
function ehNomeDuplicado(PDOException $e): bool
{
    return ($e->errorInfo[1] ?? null) === 1062;
}

/** Grava o detalhe técnico no log do servidor; o usuário não vê isso. */
function registrarErroBanco(string $contexto, PDOException $e): void
{
    error_log('[MachSense] ' . $contexto . ': ' . $e->getMessage());
}

const MSG_ERRO_GENERICO = 'Não foi possível concluir a operação. Tente novamente em instantes.';
