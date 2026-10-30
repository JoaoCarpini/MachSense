<?php

/*
 * conexao.php — conexão PDO com o MySQL/MariaDB.
 * Define a variável $pdo. Os dados vêm de config.local.php (não versionado).
 *
 * Se a conexão falhar, o detalhe técnico vai para o error_log do servidor e o
 * usuário vê só uma mensagem genérica (a mensagem do PDO pode conter nome de
 * usuário e host do banco).
 */

// Erros só no log (nunca na tela), mesmo se a página falhar antes do protect.php.
// Não use includes/seguranca.php aqui: ele define csrfValido() e conflita com
// includes/funcoes.php, que as páginas antigas carregam depois deste arquivo.
ini_set('display_errors', '0');
ini_set('log_errors', '1');
$pastaLogs = __DIR__ . '/logs';
if (!is_dir($pastaLogs)) {
    @mkdir($pastaLogs, 0750, true);
}
if (is_dir($pastaLogs) && is_writable($pastaLogs)) {
    ini_set('error_log', $pastaLogs . '/php-error.log');
}
unset($pastaLogs);

$arquivoConfig = __DIR__ . '/config.local.php';

if (!is_file($arquivoConfig)) {
    error_log('[MachSense] config.local.php não encontrado. Copie config.example.php para config.local.php.');
    http_response_code(500);
    exit('Serviço temporariamente indisponível. Tente novamente mais tarde.');
}

$config = require $arquivoConfig;

try {
    $pdo = new PDO(
        "mysql:host={$config['host']};port={$config['port']};dbname={$config['dbname']};charset=utf8mb4",
        $config['user'],
        $config['password'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );
} catch (PDOException $e) {
    error_log('[MachSense] Falha ao conectar ao banco: ' . $e->getMessage());
    http_response_code(500);
    exit('Serviço temporariamente indisponível. Tente novamente mais tarde.');
}

unset($config);
