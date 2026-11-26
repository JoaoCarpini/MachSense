<?php

/*
 * protect.php — controle de acesso de TODAS as páginas que exigem login.
 *
 * Inclua logo depois de conexao.php, no topo da página:
 *   require_once __DIR__ . '/conexao.php';
 *   require_once __DIR__ . '/protect.php';
 *
 * Faz: erros só no log, sessão com cookie protegido, cabeçalhos de segurança
 * (CSP etc.) e barra quem não estiver logado.
 * Deixa prontas as variáveis que o layout usa (nome, cargo e iniciais).
 *
 * Opções (defina ANTES do require):
 *   $urlLogin     = '../index.php';  página fora da raiz do projeto
 *   $protectJson  = true;            API: responde 401 em JSON em vez de redirecionar
 */

require_once __DIR__ . '/includes/seguranca.php';
require_once __DIR__ . '/includes/pagina_erro.php';

date_default_timezone_set('America/Sao_Paulo');

configurarErros();
iniciarSessaoSegura();
enviarCabecalhosSeguranca();

// Qualquer erro não tratado vira a tela de erro (e não uma página em branco).
// O detalhe técnico vai só para o log.
set_exception_handler(function (Throwable $ex) use (&$protectJson): void {
    error_log('[MachSense] erro não tratado: ' . $ex);
    if (!empty($protectJson)) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['ok' => false, 'mensagem' => 'Erro interno. Tente novamente em instantes.']);
        return;
    }
    mostrarPaginaErro(500, 'Algo deu errado', 'Não foi possível carregar esta página agora. Tente novamente em instantes.');
});

/** Sem login (ou conta que não existe mais): manda para o login. */
function exigirNovoLogin(?string $mensagem = null): never
{
    global $protectJson, $urlLogin;

    if ($mensagem !== null) {
        $_SESSION = ['login_erro' => $mensagem];
        session_regenerate_id(true);
    }
    if (!empty($protectJson)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'mensagem' => 'Sessão expirada. Entre novamente.']);
        exit;
    }
    header('Location: ' . ($urlLogin ?? 'index.php'));
    exit;
}

if (empty($_SESSION['usuario_id'])) {
    exigirNovoLogin();
}

/*
 * A conta da sessão ainda existe? Nome, departamento e perfil são relidos do
 * BANCO a cada acesso: se o admin apagou a conta, a sessão é encerrada; se
 * mudou o departamento, o menu já mostra o novo sem precisar sair e entrar.
 */
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $stmt = $pdo->prepare('SELECT nome, cargo, perfil FROM usuarios WHERE id = :id');
        $stmt->execute([':id' => (int) $_SESSION['usuario_id']]);
        $contaSessao = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $ex) {
        error_log('[MachSense] protect.php: ' . $ex->getMessage());
        if (!empty($protectJson)) {
            http_response_code(503);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'mensagem' => 'Banco de dados indisponível no momento.']);
            exit;
        }
        mostrarPaginaErro(503, 'Banco de dados indisponível', 'Não foi possível falar com o banco de dados. Confira se o MySQL está ligado no XAMPP e tente de novo.', ['Tentar de novo', 'dashboard.php']);
    }

    if (!$contaSessao) {
        exigirNovoLogin('Sua conta não existe mais. Entre com outro usuário.');
    }

    $_SESSION['usuario_nome'] = $contaSessao['nome'];
    $_SESSION['usuario_cargo'] = $contaSessao['cargo'];
    $_SESSION['usuario_perfil'] = $contaSessao['perfil'];
}

$nomeUsuario = (string) ($_SESSION['usuario_nome'] ?? 'Usuário');
$cargoUsuario = $_SESSION['usuario_cargo'] ?? null;

// Iniciais para o avatar (até 2 palavras do nome)
$iniciaisUsuario = '';
foreach (array_slice(preg_split('/\s+/', trim($nomeUsuario)), 0, 2) as $parte) {
    $iniciaisUsuario .= mb_strtoupper(mb_substr($parte, 0, 1));
}
