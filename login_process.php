<?php

/*
 * login_process.php — recebe o formulário de login (só POST).
 *
 * Proteções:
 *   - token CSRF (o formulário precisa ter vindo da nossa página);
 *   - limite de tentativas: 5 erros do mesmo usuário no mesmo IP em 15 minutos
 *     bloqueiam novas tentativas até a janela passar (tabela tentativas_login);
 *   - novo ID de sessão no login (evita session fixation);
 *   - a senha NÃO passa por trim(): espaço pode fazer parte dela (igual ao cadastro).
 *
 * Se a tabela tentativas_login ainda não existir (banco antigo, sem importar o
 * schema.sql novo), o login continua funcionando, só sem o limite, e o
 * problema fica registrado no log.
 */

require_once __DIR__ . '/includes/seguranca.php';

configurarErros();
iniciarSessaoSegura();
enviarCabecalhosSeguranca();

require_once __DIR__ . '/Usuario.php';

const LOGIN_MAX_TENTATIVAS = 5;
const LOGIN_JANELA_MINUTOS = 15;

function voltarAoLogin(?string $erro): never
{
    $_SESSION['login_erro'] = $erro;
    header('Location: index.php');
    exit;
}

/** Erros deste usuário, neste IP, dentro da janela de tempo. */
function tentativasRecentes(PDO $pdo, string $login, string $ip): int
{
    try {
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM tentativas_login
              WHERE usuario = :usuario AND ip = :ip
                AND criado_em > NOW() - INTERVAL ' . LOGIN_JANELA_MINUTOS . ' MINUTE'
        );
        $stmt->execute([':usuario' => $login, ':ip' => $ip]);

        return (int) $stmt->fetchColumn();
    } catch (PDOException $ex) {
        error_log('[MachSense] login (limite de tentativas): ' . $ex->getMessage());

        return 0;
    }
}

function registrarTentativaErrada(PDO $pdo, string $login, string $ip): void
{
    try {
        $stmt = $pdo->prepare('INSERT INTO tentativas_login (usuario, ip) VALUES (:usuario, :ip)');
        $stmt->execute([':usuario' => $login, ':ip' => $ip]);
        // Limpeza: tentativas com mais de 1 dia não servem para mais nada
        $pdo->exec('DELETE FROM tentativas_login WHERE criado_em < NOW() - INTERVAL 1 DAY');
    } catch (PDOException $ex) {
        error_log('[MachSense] login (registrar tentativa): ' . $ex->getMessage());
    }
}

function limparTentativas(PDO $pdo, string $login, string $ip): void
{
    try {
        $stmt = $pdo->prepare('DELETE FROM tentativas_login WHERE usuario = :usuario AND ip = :ip');
        $stmt->execute([':usuario' => $login, ':ip' => $ip]);
    } catch (PDOException $ex) {
        error_log('[MachSense] login (limpar tentativas): ' . $ex->getMessage());
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    voltarAoLogin(null);
}

if (!csrfValido()) {
    voltarAoLogin('Sessão expirada. Tente entrar novamente.');
}

$login = mb_strtolower(trim(campoPost('usuario')));
$senha = campoPost('senha');
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '');

if ($login === '' || $senha === '' || mb_strlen($login) > 50 || strlen($senha) > 72) {
    voltarAoLogin('Preencha usuário e senha.');
}

if (tentativasRecentes($pdo, $login, $ip) >= LOGIN_MAX_TENTATIVAS) {
    voltarAoLogin('Muitas tentativas erradas. Aguarde ' . LOGIN_JANELA_MINUTOS . ' minutos e tente de novo.');
}

try {
    $usuario = Usuario::autenticar($login, $senha);
} catch (PDOException $ex) {
    error_log('[MachSense] login: ' . $ex->getMessage());
    voltarAoLogin('Sistema indisponível no momento. Tente novamente mais tarde.');
}

if (!$usuario) {
    registrarTentativaErrada($pdo, $login, $ip);
    voltarAoLogin('Usuário ou senha inválidos.');
}

limparTentativas($pdo, $login, $ip);

session_regenerate_id(true);
renovarTokenCsrf();

$_SESSION['usuario_id'] = (int) $usuario['id'];
$_SESSION['usuario_nome'] = $usuario['nome'];
$_SESSION['usuario_cargo'] = $usuario['cargo'] ?? null;
$_SESSION['usuario_perfil'] = $usuario['perfil'] ?? 'usuario';

header('Location: dashboard.php');
exit;
