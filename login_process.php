<?php

session_start();
require_once __DIR__ . '/Usuario.php';

$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $login = trim($_POST['usuario'] ?? '');
    $senha = trim($_POST['senha'] ?? '');

    if ($login === '' || $senha === '') {
        $erro = 'Preencha usuário e senha.';
    } else {
        $usuario = Usuario::autenticar($login, $senha);

        if ($usuario) {
            $_SESSION['usuario_id'] = $usuario['id'];
            $_SESSION['usuario_nome'] = $usuario['nome'];

            header('Location: dashboard.php');
            exit;
        }

        $erro = 'Usuário ou senha inválidos.';
    }
}

$_SESSION['login_erro'] = $erro;
header('Location: index.php');
exit;
