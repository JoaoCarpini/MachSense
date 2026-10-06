<?php
session_start();
$erro = $_SESSION['login_erro'] ?? null;
unset($_SESSION['login_erro']);
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>MachSense — Login</title>
    <link rel="stylesheet" href="style.css">
</head>
<body>

    <div class="login-card">
        <div class="login-icon">
            <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <path d="M3 3v18h18" />
                <path d="M18.7 8 12 14.7 8.5 11.2 3 16.7" />
            </svg>
        </div>

        <h1 class="login-title">MachSense</h1>
        <p class="login-subtitle">CONTROLE DE PRODUÇÃO INDUSTRIAL</p>

        <hr class="login-divider">

        <?php if ($erro): ?>
            <p class="login-error"><?= htmlspecialchars($erro) ?></p>
        <?php endif; ?>

        <form action="login_process.php" method="POST" id="loginForm">
            <label for="usuario">USUÁRIO</label>
            <div class="input-group">
                <span class="input-icon">👤</span>
                <input type="text" id="usuario" name="usuario" placeholder="admin" autocomplete="username" required>
            </div>

            <label for="senha">SENHA</label>
            <div class="input-group">
                <span class="input-icon">🔒</span>
                <input type="password" id="senha" name="senha" placeholder="••••••••••••" required>
            </div>

            <button type="submit" id="btnAcessar">ACESSAR PRODUÇÃO</button>
        </form>

        <p class="login-footer">
            MachSense Production v2.1.0-Live. Para suporte contactar equipe<br>
            de TI Industrial.
        </p>
    </div>

    <script src="script.js"></script>
</body>
</html>
