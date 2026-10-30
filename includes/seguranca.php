<?php
declare(strict_types=1);

/*
 * Funções de segurança compartilhadas do MachSense.
 * Cada função cita o item da Aula 09 que ela atende.
 *
 * Uso no topo de uma página:
 *   require_once __DIR__ . '/includes/seguranca.php';
 *   configurarErros();
 *   iniciarSessaoSegura();
 *   enviarCabecalhosSeguranca();
 */

/**
 * Detecta se a requisição chegou por HTTPS.
 * Por quê: o cookie com flag "secure" só é enviado pelo navegador em HTTPS.
 * Em http://localhost a flag precisa ficar desligada, senão a sessão nunca
 * é gravada (o slide 11 avisa que a IA costuma errar isso em localhost).
 */
function conexaoEhHttps(): bool
{
    return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
}

/**
 * Esconde erros do usuário e manda tudo para um arquivo de log.
 * Por quê: erro na tela expõe caminho de pasta, nome de tabela e stack trace
 * (slide 13: display_errors = Off, log_errors = On, error_log definido).
 * O ideal é também configurar isso no php.ini; aqui é a segunda camada.
 */
function configurarErros(): void
{
    ini_set('display_errors', '0');
    ini_set('display_startup_errors', '0');
    ini_set('log_errors', '1');
    error_reporting(E_ALL);

    $pasta = dirname(__DIR__) . '/logs';
    if (!is_dir($pasta)) {
        @mkdir($pasta, 0750, true);
    }
    if (is_dir($pasta) && is_writable($pasta)) {
        ini_set('error_log', $pasta . '/php-error.log');
    }
}

/**
 * Inicia a sessão com o cookie protegido.
 * Por quê (slide 11):
 *  - httponly: o JavaScript não lê o cookie (reduz o estrago de um XSS);
 *  - samesite Strict: o navegador não envia o cookie em requisição vinda de
 *    outro site (barra a maior parte dos ataques CSRF);
 *  - secure: só em HTTPS (ver conexaoEhHttps);
 *  - use_strict_mode: recusa ID de sessão que o servidor não criou
 *    (defesa contra session fixation);
 *  - use_only_cookies: o ID da sessão nunca vai na URL.
 */
function iniciarSessaoSegura(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');

    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'domain'   => '',
        'secure'   => conexaoEhHttps(),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);

    session_start();
}

/**
 * Cabeçalhos HTTP de segurança.
 * Por quê (slides 11 e 13):
 *  - HSTS: força HTTPS nas próximas visitas (só faz sentido em HTTPS);
 *  - CSP: o navegador só carrega CSS/JS do próprio site, o que limita XSS;
 *  - X-Frame-Options / frame-ancestors: impede a página dentro de iframe
 *    (clickjacking);
 *  - nosniff: o navegador não "adivinha" o tipo do arquivo;
 *  - no-store: página com token CSRF não fica em cache.
 * Atenção: esta CSP bloqueia atributos style="..." inline. Páginas que usam
 * style inline (como as barras do dashboard) precisam de classes CSS ou de
 * 'unsafe-inline' em style-src.
 */
function enviarCabecalhosSeguranca(): void
{
    if (headers_sent()) {
        return;
    }

    header('X-Frame-Options: DENY');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: no-referrer');
    header("Content-Security-Policy: default-src 'self'; style-src 'self'; script-src 'self'; img-src 'self' data:; form-action 'self'; frame-ancestors 'none'; base-uri 'self'");
    header('Cache-Control: no-store');

    if (conexaoEhHttps()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

/**
 * Escapa texto para sair no HTML (proteção contra XSS).
 * Por quê: tudo que o usuário digitou volta para a tela escapado
 * (regra obrigatória da atividade). ENT_QUOTES protege também atributos.
 * O if evita erro caso o projeto já tenha uma função e() em outro include.
 */
if (!function_exists('e')) {
    function e(?string $valor): string
    {
        return htmlspecialchars((string) $valor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

/**
 * Devolve o token CSRF da sessão, criando um se ainda não existir.
 * Por quê: random_bytes é criptograficamente seguro; o token fica na SESSÃO
 * (e não em cookie) porque o navegador manda cookies sozinho em qualquer
 * requisição, então um cookie não prova que o formulário veio da nossa página.
 */
function tokenCsrf(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

/**
 * Confere o token recebido no formulário contra o da sessão.
 * Por quê: hash_equals compara em tempo constante, sem vazar por tempo de
 * resposta quantos caracteres bateram (slide 14).
 */
function csrfValido(string $enviado): bool
{
    $esperado = $_SESSION['csrf_token'] ?? '';

    return is_string($esperado) && $esperado !== '' && $enviado !== '' && hash_equals($esperado, $enviado);
}

/** Troca o token depois de uma ação bem-sucedida (token de uso único). */
function renovarTokenCsrf(): void
{
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

/**
 * Lê um campo de $_POST sempre como texto.
 * Por quê: um atacante pode mandar nome[]=x e $_POST['nome'] vira array;
 * sem esta checagem o PHP daria warning ou comportamento inesperado.
 * Usamos só $_POST (nunca $_REQUEST, que mistura GET, POST e COOKIE).
 */
function campoPost(string $nome): string
{
    $valor = $_POST[$nome] ?? '';

    return is_string($valor) ? $valor : '';
}
