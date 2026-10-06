<?php

require_once __DIR__ . '/db.php';

/**
 * Estrutura de cadastro do usuário do sistema MachSense.
 *
 * O usuário é fixo/pré-cadastrado (seed) — não há tela pública
 * de cadastro. Esta classe define a entidade e as operações
 * necessárias para autenticação e manutenção do cadastro.
 */
class Usuario
{
    public ?int $id;
    public string $nome;
    public string $usuario;
    public string $senha; // hash (password_hash)

    public function __construct(string $nome, string $usuario, string $senha, ?int $id = null)
    {
        $this->id = $id;
        $this->nome = $nome;
        $this->usuario = $usuario;
        $this->senha = $senha;
    }

    public static function cadastrar(string $nome, string $usuario, string $senhaTextoPuro): bool
    {
        global $pdo;

        $hash = password_hash($senhaTextoPuro, PASSWORD_BCRYPT);

        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nome, usuario, senha) VALUES (:nome, :usuario, :senha)'
        );

        return $stmt->execute([
            ':nome'    => $nome,
            ':usuario' => $usuario,
            ':senha'   => $hash,
        ]);
    }

    public static function buscarPorUsuario(string $login): ?array
    {
        global $pdo;

        $stmt = $pdo->prepare('SELECT * FROM usuarios WHERE usuario = :usuario LIMIT 1');
        $stmt->execute([':usuario' => $login]);
        $usuario = $stmt->fetch();

        return $usuario ?: null;
    }

    public static function autenticar(string $login, string $senhaTextoPuro): ?array
    {
        $usuario = self::buscarPorUsuario($login);

        if (!$usuario) {
            return null;
        }

        if (!password_verify($senhaTextoPuro, $usuario['senha'])) {
            return null;
        }

        return $usuario;
    }
}
