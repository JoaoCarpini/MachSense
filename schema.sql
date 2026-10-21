-- Schema do banco MachSense para importar via phpMyAdmin (aba "Importar").

CREATE DATABASE IF NOT EXISTS machsense
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE machsense;

CREATE TABLE IF NOT EXISTS usuarios (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    nome VARCHAR(100) NOT NULL,
    usuario VARCHAR(50) NOT NULL,
    senha VARCHAR(255) NOT NULL,
    cargo VARCHAR(80) NULL,
    criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuarios_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exemplo de como inserir o primeiro usuário (NÃO use senha/hash reais aqui):
-- 1. Gere o hash da senha: php -r "echo password_hash('SENHA', PASSWORD_BCRYPT);"
-- 2. Insira o usuário com o hash gerado:
-- INSERT INTO usuarios (nome, usuario, senha, cargo)
-- VALUES ('Nome Completo', 'usuario', '<hash_gerado_aqui>', 'Cargo do usuário');
