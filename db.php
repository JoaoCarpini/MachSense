<?php

$DB_HOST = 'localhost';
$DB_NAME = 'machsense';
$DB_USER = 'root';
$DB_PASS = '';

try {
    // Primeiro conecta sem especificar o banco, só pra poder criá-lo caso não exista.
    $pdoSetup = new PDO(
        "mysql:host={$DB_HOST};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );

    $pdoSetup->exec("CREATE DATABASE IF NOT EXISTS {$DB_NAME}");

    // Agora conecta de fato dentro do banco machsense.
    $pdo = new PDO(
        "mysql:host={$DB_HOST};dbname={$DB_NAME};charset=utf8mb4",
        $DB_USER,
        $DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );

    criarEstrutura($pdo);
} catch (PDOException $e) {
    die('Erro ao conectar ao banco de dados: ' . $e->getMessage());
}

/**
 * Cria as tabelas (se não existirem) e insere os dados de seed
 * na primeira vez que o sistema rodar. Substitui a necessidade
 * de importar um arquivo .sql manualmente.
 */
function criarEstrutura(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS usuarios (
            id      INT AUTO_INCREMENT PRIMARY KEY,
            nome    VARCHAR(100) NOT NULL,
            usuario VARCHAR(50) NOT NULL UNIQUE,
            senha   VARCHAR(255) NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS maquinas (
            id                    INT AUTO_INCREMENT PRIMARY KEY,
            nome                  VARCHAR(100) NOT NULL,
            meta_disponibilidade  DECIMAL(5,2) NOT NULL
        )
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS pulsos (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            maquina_id   INT NOT NULL,
            timestamp    DATETIME NOT NULL,
            estado       ENUM('rodando', 'parado') NOT NULL,
            motivo       VARCHAR(50) NULL,
            CONSTRAINT fk_pulsos_maquina FOREIGN KEY (maquina_id) REFERENCES maquinas(id)
        )
    ");

    try {
        $pdo->exec("
            CREATE INDEX idx_pulsos_maquina_timestamp
            ON pulsos (maquina_id, timestamp)
        ");
    } catch (PDOException $e) {
        // Ignora erro de "índice já existe" (código 1061) em execuções repetidas.
        if ($e->errorInfo[1] !== 1061) {
            throw $e;
        }
    }

    // Seed: só insere se as tabelas ainda estiverem vazias.
    $totalMaquinas = (int) $pdo->query('SELECT COUNT(*) FROM maquinas')->fetchColumn();

    if ($totalMaquinas === 0) {
        $stmt = $pdo->prepare(
            'INSERT INTO maquinas (nome, meta_disponibilidade) VALUES (:nome, :meta)'
        );
        foreach (['Máquina 1', 'Máquina 2', 'Máquina 3'] as $nome) {
            $stmt->execute([':nome' => $nome, ':meta' => 85.00]);
        }
    }

    $totalUsuarios = (int) $pdo->query('SELECT COUNT(*) FROM usuarios')->fetchColumn();

    if ($totalUsuarios === 0) {
        $hash = password_hash('admin', PASSWORD_BCRYPT);
        $stmt = $pdo->prepare(
            'INSERT INTO usuarios (nome, usuario, senha) VALUES (:nome, :usuario, :senha)'
        );
        $stmt->execute([
            ':nome'    => 'Administrador',
            ':usuario' => 'admin',
            ':senha'   => $hash,
        ]);
    }
}
