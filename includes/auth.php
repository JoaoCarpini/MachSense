<?php

session_start();

if (!isset($_SESSION['usuario_id'])) {
    header('Location: index.php');
    exit;
}

function e(string $texto): string
{
    return htmlspecialchars($texto, ENT_QUOTES, 'UTF-8');
}

$nomeUsuario = $_SESSION['usuario_nome'] ?? 'Usuário';
$cargoUsuario = $_SESSION['usuario_cargo'] ?? null;

// Iniciais para o avatar (até 2 palavras do nome)
$iniciaisUsuario = '';
foreach (array_slice(preg_split('/\s+/', trim($nomeUsuario)), 0, 2) as $parte) {
    $iniciaisUsuario .= mb_strtoupper(mb_substr($parte, 0, 1));
}
