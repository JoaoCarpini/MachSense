<?php

/*
 * protect.php — controle de acesso das páginas protegidas.
 *
 * Inclua logo depois de conexao.php, no topo da página:
 *   require_once __DIR__ . '/conexao.php';
 *   require_once __DIR__ . '/protect.php';
 *
 * Faz: erros só no log, sessão com cookie protegido, cabeçalhos de segurança
 * (CSP etc.) e redireciona para o login quem não estiver logado.
 * Deixa prontas as variáveis que o layout usa (nome, cargo e iniciais).
 */

require_once __DIR__ . '/includes/seguranca.php';

configurarErros();
iniciarSessaoSegura();
enviarCabecalhosSeguranca();

if (empty($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

$nomeUsuario = (string) ($_SESSION['usuario_nome'] ?? 'Usuário');
$cargoUsuario = $_SESSION['usuario_cargo'] ?? null;

// Iniciais para o avatar (até 2 palavras do nome)
$iniciaisUsuario = '';
foreach (array_slice(preg_split('/\s+/', trim($nomeUsuario)), 0, 2) as $parte) {
    $iniciaisUsuario .= mb_strtoupper(mb_substr($parte, 0, 1));
}
