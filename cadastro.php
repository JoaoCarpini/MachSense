<?php
declare(strict_types=1);

/*
 * cadastro.php — Criar conta de usuário (MachSense)
 *
 * Fluxo: GET mostra o formulário; POST recebe, valida no servidor,
 * grava no MySQL com PDO + prepared statement e devolve a resposta na tela.
 * Depende de: includes/seguranca.php e db.php (que precisa definir $pdo).
 */

require_once __DIR__ . '/includes/seguranca.php';

// Segurança antes de qualquer saída: erros no log, sessão protegida, cabeçalhos.
configurarErros();
iniciarSessaoSegura();
enviarCabecalhosSeguranca();

// A conexão pode falhar (MySQL desligado, senha errada). Capturamos aqui para
// o usuário ver uma mensagem simples; o detalhe técnico vai só para o log.
$falhaConexao = false;
try {
    require_once __DIR__ . '/db.php';
} catch (Throwable $ex) {
    error_log('cadastro.php (conexão): ' . $ex->getMessage());
    $falhaConexao = true;
    http_response_code(503);
}

/**
 * Valida os dados no SERVIDOR (o navegador não é lugar de confiar em entrada).
 * Devolve um array campo => mensagem. Array vazio significa "tudo certo".
 */
function validarCadastro(array $d): array
{
    $erros = [];

    // Nome: obrigatório, tamanho (faixa) e só letras/espaço/pontuação de nome.
    if ($d['nome'] === '') {
        $erros['nome'] = 'Informe o nome.';
    } elseif (mb_strlen($d['nome']) < 3 || mb_strlen($d['nome']) > 100) {
        $erros['nome'] = 'O nome deve ter entre 3 e 100 caracteres.';
    } elseif (!preg_match('/^[\p{L}][\p{L} .\'-]*$/u', $d['nome'])) {
        $erros['nome'] = 'Use apenas letras, espaços, ponto, apóstrofo ou hífen.';
    }

    // Usuário: formato restrito (minúsculas, números, ponto, sublinhado).
    // Lista branca é mais segura que tentar bloquear caracteres perigosos.
    if ($d['usuario'] === '') {
        $erros['usuario'] = 'Informe o usuário.';
    } elseif (!preg_match('/^[a-z0-9._]{3,30}$/', $d['usuario'])) {
        $erros['usuario'] = 'De 3 a 30 caracteres: letras minúsculas, números, ponto ou sublinhado.';
    }

    // Cargo: opcional, mas se vier tem tamanho máximo (coluna é VARCHAR(80)).
    if ($d['cargo'] !== '') {
        if (mb_strlen($d['cargo']) > 80) {
            $erros['cargo'] = 'O cargo deve ter no máximo 80 caracteres.';
        } elseif (!preg_match('/^[\p{L}][\p{L}0-9 .\'\/-]*$/u', $d['cargo'])) {
            $erros['cargo'] = 'O cargo tem caracteres inválidos.';
        }
    }

    // Senha: mínimo 8; máximo 72 BYTES porque o bcrypt ignora o que passa disso.
    // strlen conta bytes de propósito. Não usamos trim() na senha.
    if ($d['senha'] === '') {
        $erros['senha'] = 'Informe a senha.';
    } elseif (strlen($d['senha']) < 8) {
        $erros['senha'] = 'A senha deve ter no mínimo 8 caracteres.';
    } elseif (strlen($d['senha']) > 72) {
        $erros['senha'] = 'A senha deve ter no máximo 72 caracteres.';
    } elseif (!preg_match('/[A-Za-z]/', $d['senha']) || !preg_match('/[0-9]/', $d['senha'])) {
        $erros['senha'] = 'A senha precisa ter pelo menos uma letra e um número.';
    }

    // Confirmação: comparação em tempo constante, como as demais com segredo.
    if (!isset($erros['senha']) && !hash_equals($d['senha'], $d['confirmacao'])) {
        $erros['confirmacao'] = 'A confirmação não é igual à senha.';
    }

    return $erros;
}

/** Gera o hash da senha. Cost 12: lento o bastante para dificultar força bruta. */
function gerarHashSenha(string $senha): string
{
    return password_hash($senha, PASSWORD_BCRYPT, ['cost' => 12]);
}

/** Verifica se o usuário já existe. Prepared statement: o dado nunca vira SQL. */
function usuarioExiste(PDO $pdo, string $usuario): bool
{
    $stmt = $pdo->prepare('SELECT 1 FROM usuarios WHERE usuario = :usuario LIMIT 1');
    $stmt->execute([':usuario' => $usuario]);

    return $stmt->fetchColumn() !== false;
}

/** Grava o usuário. Só o hash vai ao banco, nunca a senha em texto. */
function criarUsuario(PDO $pdo, array $d): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO usuarios (nome, usuario, senha, cargo)
         VALUES (:nome, :usuario, :senha, :cargo)'
    );
    $stmt->execute([
        ':nome'    => $d['nome'],
        ':usuario' => $d['usuario'],
        ':senha'   => gerarHashSenha($d['senha']),
        ':cargo'   => $d['cargo'] === '' ? null : $d['cargo'],
    ]);
}

// Esta página só aceita GET (mostrar o formulário) e POST (enviar).
$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($metodo !== 'GET' && $metodo !== 'POST') {
    http_response_code(405);
    header('Allow: GET, POST');
    exit('Método não permitido.');
}

$erros = [];
$erroGeral = '';
$sucesso = false;
$valores = ['nome' => '', 'usuario' => '', 'cargo' => ''];

// Mensagem de sucesso vem de uma "flash" na sessão (padrão Post/Redirect/Get:
// atualizar a página depois do cadastro não reenvia o formulário).
if (!empty($_SESSION['flash_cadastro_ok'])) {
    $sucesso = true;
    unset($_SESSION['flash_cadastro_ok']);
}

if ($falhaConexao) {
    $erroGeral = 'Sistema indisponível no momento. Tente novamente mais tarde.';
} elseif ($metodo === 'POST') {
    // Dados do formulário só via $_POST (nunca $_GET nem $_REQUEST).
    // Nome, usuário e cargo passam por trim; a SENHA não, porque espaço
    // pode fazer parte dela.
    $dados = [
        'nome'        => trim(campoPost('nome')),
        'usuario'     => mb_strtolower(trim(campoPost('usuario'))),
        'cargo'       => trim(campoPost('cargo')),
        'senha'       => campoPost('senha'),
        'confirmacao' => campoPost('confirmacao'),
    ];
    // Valores que voltam ao formulário. A senha NUNCA volta.
    $valores = ['nome' => $dados['nome'], 'usuario' => $dados['usuario'], 'cargo' => $dados['cargo']];

    if (!csrfValido(campoPost('csrf_token'))) {
        // Formulário não veio da nossa página (ou a sessão expirou).
        http_response_code(403);
        $erroGeral = 'Sessão expirada ou requisição inválida. Recarregue a página e tente de novo.';
    } else {
        $erros = validarCadastro($dados);

        if (!$erros) {
            try {
                if (usuarioExiste($pdo, $dados['usuario'])) {
                    $erros['usuario'] = 'Este usuário já existe.';
                } else {
                    criarUsuario($pdo, $dados);
                    renovarTokenCsrf();
                    $_SESSION['flash_cadastro_ok'] = true;
                    header('Location: cadastro.php');
                    exit;
                }
            } catch (PDOException $ex) {
                // 1062 = chave duplicada: dois cadastros simultâneos do mesmo
                // usuário passam pela checagem acima; o índice UNIQUE barra o segundo.
                if ((int) ($ex->errorInfo[1] ?? 0) === 1062) {
                    $erros['usuario'] = 'Este usuário já existe.';
                } else {
                    // Detalhe técnico só no log; o usuário vê mensagem genérica.
                    error_log('cadastro.php: ' . $ex->getMessage());
                    http_response_code(500);
                    $erroGeral = 'Não foi possível concluir o cadastro agora. Tente novamente mais tarde.';
                }
            }
        }
    }
}

$token = tokenCsrf();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MachSense | Criar conta</title>
    <link rel="stylesheet" href="cadastro.css">
</head>
<body>
<main class="cartao">
    <h1>Criar conta</h1>
    <p class="sub">Controle de produção industrial</p>

    <?php if ($sucesso): ?>
        <div class="aviso aviso-ok" role="status">
            Conta criada com sucesso. <a href="index.php">Entrar</a>
        </div>
    <?php endif; ?>

    <?php if ($erroGeral !== ''): ?>
        <div class="aviso aviso-erro" role="alert"><?= e($erroGeral) ?></div>
    <?php endif; ?>

    <?php /* method="POST": senha não pode aparecer na URL. novalidate: a validação que vale é a do servidor. */ ?>
    <form method="POST" action="cadastro.php" novalidate autocomplete="off">
        <input type="hidden" name="csrf_token" value="<?= e($token) ?>">

        <label for="nome">Nome completo</label>
        <input type="text" id="nome" name="nome" maxlength="100" value="<?= e($valores['nome']) ?>" autocomplete="name">
        <?php if (isset($erros['nome'])): ?><span class="erro"><?= e($erros['nome']) ?></span><?php endif; ?>

        <label for="usuario">Usuário</label>
        <input type="text" id="usuario" name="usuario" maxlength="30" value="<?= e($valores['usuario']) ?>" autocomplete="username">
        <?php if (isset($erros['usuario'])): ?><span class="erro"><?= e($erros['usuario']) ?></span><?php endif; ?>

        <label for="cargo">Cargo <small>(opcional)</small></label>
        <input type="text" id="cargo" name="cargo" maxlength="80" value="<?= e($valores['cargo']) ?>">
        <?php if (isset($erros['cargo'])): ?><span class="erro"><?= e($erros['cargo']) ?></span><?php endif; ?>

        <label for="senha">Senha</label>
        <input type="password" id="senha" name="senha" maxlength="72" autocomplete="new-password">
        <?php if (isset($erros['senha'])): ?><span class="erro"><?= e($erros['senha']) ?></span><?php endif; ?>

        <label for="confirmacao">Confirmar senha</label>
        <input type="password" id="confirmacao" name="confirmacao" maxlength="72" autocomplete="new-password">
        <?php if (isset($erros['confirmacao'])): ?><span class="erro"><?= e($erros['confirmacao']) ?></span><?php endif; ?>

        <button type="submit">Criar conta</button>
    </form>

    <p class="rodape"><a href="index.php">Já tenho conta</a></p>
</main>
</body>
</html>
