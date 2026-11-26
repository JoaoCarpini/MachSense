<?php

/*
 * logout.php — encerra a sessão. Só aceita POST com token CSRF: assim um link
 * ou imagem em outro site não consegue deslogar o usuário.
 * Um GET (ex.: endereço digitado) só volta para o painel.
 */

require_once __DIR__ . '/includes/seguranca.php';

configurarErros();
iniciarSessaoSegura();
enviarCabecalhosSeguranca();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || !csrfValido()) {
    header('Location: dashboard.php');
    exit;
}

$_SESSION = [];

// Apaga também o cookie da sessão no navegador
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires'  => time() - 42000,
        'path'     => $p['path'],
        'domain'   => $p['domain'],
        'secure'   => $p['secure'],
        'httponly' => $p['httponly'],
        'samesite' => $p['samesite'] ?? 'Strict',
    ]);
}

session_destroy();

header('Location: index.php');
exit;
