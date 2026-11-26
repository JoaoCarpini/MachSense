<?php

/*
 * pagina_erro.php — tela de erro do MachSense, no mesmo visual do sistema.
 *
 * Uso:
 *   require_once __DIR__ . '/includes/pagina_erro.php';
 *   mostrarPaginaErro(403, 'Acesso restrito', 'Você não tem permissão...');
 *
 * Não depende de nenhum outro arquivo (nem da conexão com o banco), então
 * pode ser usada até quando o banco está fora do ar. O estilo fica em
 * erro.css (arquivo externo, porque a CSP do site bloqueia CSS embutido).
 */

/**
 * Caminho da raiz do site em relação à página aberta: '' na raiz,
 * '../' numa subpasta (ex.: pi_ordem_producao/). Serve para achar o
 * erro.css e montar os links de volta.
 */
function raizRelativa(): string
{
    // Sobe a partir da pasta da página até achar o erro.css (que fica na raiz).
    // Comparar arquivos, e não caminhos, funciona mesmo com atalhos de pasta
    // (junction/symlink) como o htdocs\machsense do XAMPP.
    $pasta = dirname((string) ($_SERVER['SCRIPT_FILENAME'] ?? ''));
    $prefixo = '';
    for ($nivel = 0; $nivel < 4; $nivel++) {
        if (is_file($pasta . '/' . $prefixo . 'erro.css')) {
            return $prefixo;
        }
        $prefixo .= '../';
    }

    return '';
}

/**
 * Mostra a tela de erro e encerra.
 * $link: [texto, endereço relativo à raiz do site]. Padrão: voltar ao painel.
 */
function mostrarPaginaErro(int $codigo, string $titulo, string $mensagem, ?array $link = null): never
{
    if (!headers_sent()) {
        http_response_code($codigo);
        header('Content-Type: text/html; charset=UTF-8');
    }

    $raiz = raizRelativa();
    $link ??= ['Voltar ao painel', 'dashboard.php'];
    $esc = fn(string $t) => htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $versaoCss = (string) @filemtime(dirname(__DIR__) . '/erro.css');
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MachSense — <?= $esc($titulo) ?></title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="<?= $esc($raiz . 'erro.css?v=' . $versaoCss) ?>">
</head>
<body>
    <main class="erro-cartao" role="alert">
        <div class="erro-marca">
            <span class="erro-icone" aria-hidden="true">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <path d="M3 3v18h18"/><path d="M18.7 8 12 14.7 8.5 11.2 3 16.7"/>
                </svg>
            </span>
            <strong>MachSense</strong>
        </div>

        <p class="erro-codigo">Erro <?= (int) $codigo ?></p>
        <h1><?= $esc($titulo) ?></h1>
        <p class="erro-mensagem"><?= $esc($mensagem) ?></p>

        <a class="erro-botao" href="<?= $esc($raiz . $link[1]) ?>"><?= $esc($link[0]) ?></a>
    </main>
</body>
</html>
<?php
    exit;
}
