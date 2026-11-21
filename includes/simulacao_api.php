<?php

/*
 * Ponte entre o site (PHP) e a API do simulador (Python). Usada só pela
 * página simulacao.php.
 *
 * O PHP NÃO executa programas no computador: ele só faz chamadas HTTP para a
 * API que roda na própria máquina, e é a API (api.py) que liga e desliga o
 * simulador. Assim nada que vem do navegador vira comando do sistema.
 */

/**
 * Lê o .env.local (uma linha CHAVE=VALOR por vez) sem executar nada.
 * Devolve [] se o arquivo não existir.
 */
function lerEnvLocal(): array
{
    $arquivo = dirname(__DIR__) . '/.env.local';
    if (!is_file($arquivo)) {
        return [];
    }

    $linhas = file($arquivo, FILE_IGNORE_NEW_LINES);
    if ($linhas === false) {
        return [];
    }
    // Alguns editores gravam um BOM invisível no começo do arquivo
    if ($linhas && str_starts_with($linhas[0], "\xEF\xBB\xBF")) {
        $linhas[0] = substr($linhas[0], 3);
    }

    $valores = [];
    foreach ($linhas as $linha) {
        $linha = trim($linha);
        if ($linha === '' || $linha[0] === '#' || !str_contains($linha, '=')) {
            continue;
        }
        [$nome, $valor] = explode('=', $linha, 2);
        $valores[trim($nome)] = trim(trim($valor), "\"'");
    }

    return $valores;
}

/** O botão só funciona se MACHSENSE_SIMULACAO_NO_SITE=1 estiver no .env.local. */
function simulacaoHabilitada(array $env): bool
{
    return ($env['MACHSENSE_SIMULACAO_NO_SITE'] ?? '') === '1';
}

/** A página de simulação só abre quando o navegador está no mesmo computador do servidor. */
function requisicaoLocal(): bool
{
    return in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true);
}

/**
 * Chama a API do simulador. Devolve:
 *   ['ok' => bool, 'status' => int|null, 'dados' => array|null, 'erro' => string|null]
 * com erro igual a 'chave_ausente', 'api_fora', 'endereco_invalido', 'chave_recusada'
 * ou o texto de erro da própria API.
 */
function chamarApiSimulacao(array $env, string $metodo, string $caminho, ?array $corpo = null): array
{
    $falha = fn(string $erro, ?int $status = null) => ['ok' => false, 'status' => $status, 'dados' => null, 'erro' => $erro];

    $base = rtrim($env['MACHSENSE_API_URL'] ?? 'http://127.0.0.1:8000', '/');
    // A chave da API só pode viajar para a própria máquina
    $host = parse_url($base, PHP_URL_HOST);
    if (!in_array($host, ['127.0.0.1', 'localhost', '[::1]', '::1'], true)) {
        return $falha('endereco_invalido');
    }

    $chave = $env['MACHSENSE_API_KEY'] ?? '';
    if ($chave === '') {
        return $falha('chave_ausente');
    }

    $cabecalhos = "X-API-Key: $chave\r\nAccept: application/json\r\n";
    $opcoes = ['method' => $metodo, 'timeout' => 5, 'ignore_errors' => true];
    if ($corpo !== null) {
        $cabecalhos .= "Content-Type: application/json\r\n";
        $opcoes['content'] = json_encode($corpo);
    }
    $opcoes['header'] = $cabecalhos;

    $resposta = @file_get_contents($base . $caminho, false, stream_context_create(['http' => $opcoes]));
    if ($resposta === false) {
        return $falha('api_fora');
    }

    // $http_response_header é preenchido pelo próprio file_get_contents
    $status = null;
    if (!empty($http_response_header[0]) && preg_match('#\s(\d{3})\s#', $http_response_header[0], $m)) {
        $status = (int) $m[1];
    }

    $dados = json_decode($resposta, true);
    $dados = is_array($dados) ? $dados : null;

    if ($status === 401) {
        return $falha('chave_recusada', $status);
    }
    if ($status === null || $status < 200 || $status >= 300) {
        $detalhe = is_array($dados['detail'] ?? null) ? 'pedido inválido' : (string) ($dados['detail'] ?? 'erro na API');
        return $falha($detalhe, $status);
    }

    return ['ok' => true, 'status' => $status, 'dados' => $dados, 'erro' => null];
}

/** Texto em português para cada tipo de erro de chamarApiSimulacao(). */
function mensagemErroSimulacao(string $erro): string
{
    return match ($erro) {
        'api_fora' => 'A API não está ligada. Abra um terminal na pasta do projeto e rode: '
            . 'python -m uvicorn api:app --host 127.0.0.1 --port 8000 (ou dê duplo clique em iniciar_api.bat).',
        'chave_ausente' => 'Falta a chave da API. Crie o arquivo .env.local com MACHSENSE_API_KEY (veja o README).',
        'chave_recusada' => 'A API recusou a chave. Confira se o .env.local é o mesmo usado pela API e reinicie a API.',
        'endereco_invalido' => 'MACHSENSE_API_URL deve apontar para este computador (127.0.0.1 ou localhost).',
        default => 'A API respondeu com erro: ' . $erro,
    };
}
