<?php

/*
 * simulacao.php — botão "Iniciar simulação" para a apresentação.
 *
 * O PHP não liga o simulador: ele pede para a API (Python, na mesma máquina)
 * fazer isso, usando a chave do .env.local. Três travas, nesta ordem:
 *   1) só abre no computador onde o servidor roda (127.0.0.1);
 *   2) só para o administrador ou o departamento Produção (conferido no banco a cada acesso);
 *   3) só se o .env.local tiver MACHSENSE_SIMULACAO_NO_SITE=1 (vem desligado).
 */

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/protect.php';
require_once __DIR__ . '/includes/maquinas_funcoes.php';
require_once __DIR__ . '/includes/simulacao_api.php';

if (!requisicaoLocal()) {
    http_response_code(403);
    exit('A página de simulação só abre no computador onde o sistema está rodando.');
}

try {
    $stmt = $pdo->prepare('SELECT perfil, cargo FROM usuarios WHERE id = ?');
    $stmt->execute([(int) $_SESSION['usuario_id']]);
    $conta = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $ex) {
    registrarErroBanco('simulacao (perfil)', $ex);
    http_response_code(503);
    exit('Banco de dados indisponível no momento.');
}

// Pode simular: o administrador e quem é do departamento Produção
$podeSimular = ($conta['perfil'] ?? '') === 'admin' || ($conta['cargo'] ?? '') === 'Produção';
if (!$podeSimular) {
    http_response_code(403);
    exit('Somente o administrador ou o departamento Produção pode controlar a simulação.');
}

$env = lerEnvLocal();
$habilitada = simulacaoHabilitada($env);

// Velocidades aceitas (as mesmas da API): fator => descrição
$velocidades = [
    30  => 'Lenta (30x): 1 pulso a cada 10 s',
    60  => 'Normal (60x): 1 pulso a cada 5 s',
    120 => 'Rápida (120x): 1 pulso a cada 2,5 s',
    300 => 'Muito rápida (300x): 1 pulso por segundo',
    600 => 'Máxima (600x): 2 pulsos por segundo',
];

/** Junta o que a API diz do simulador com a contagem de pulsos do banco. */
function estadoDaSimulacao(PDO $pdo, array $env): array
{
    $api = chamarApiSimulacao($env, 'GET', '/simulacao');
    $dados = $api['dados'] ?? [];

    $stmt = $pdo->prepare('SELECT COUNT(*) AS total, MAX(`timestamp`) AS ultimo FROM pulsos');
    $stmt->execute();
    $pulsos = $stmt->fetch();

    return [
        'api_ok'       => $api['ok'],
        'api_erro'     => $api['ok'] ? null : mensagemErroSimulacao((string) $api['erro']),
        'rodando'      => (bool) ($dados['rodando'] ?? false),
        'fator'        => $dados['fator'] ?? null,
        'iniciada_em'  => !empty($dados['iniciada_em']) ? date('H:i:s', strtotime($dados['iniciada_em'])) : null,
        'codigo_saida' => $dados['codigo_saida'] ?? null,
        'log'          => array_values(array_map('strval', $dados['log'] ?? [])),
        'pulsos_total' => number_format((int) $pulsos['total'], 0, ',', '.'),
        'ultimo_pulso' => $pulsos['ultimo'] ? date('d/m/Y H:i', strtotime($pulsos['ultimo'])) : null,
    ];
}

/* ------------------------------------------------------------------
 * JSON para a atualização automática (simulacao.js)
 * ------------------------------------------------------------------ */

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && isset($_GET['estado'])) {
    header('Content-Type: application/json; charset=utf-8');
    session_write_close();
    echo json_encode($habilitada ? estadoDaSimulacao($pdo, $env) : ['desabilitada' => true], JSON_THROW_ON_ERROR);
    exit;
}

/* ------------------------------------------------------------------
 * AÇÕES (POST): sempre redireciona depois
 * ------------------------------------------------------------------ */

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    exigirCsrf();

    $acao = campoPost('acao');
    $fator = lerId(campoPost('fator'));

    if (!$habilitada) {
        definirAviso('erro', 'A simulação pelo site está desligada. Veja como ligar nesta página.');
    } elseif ($acao === 'parar') {
        $resultado = chamarApiSimulacao($env, 'POST', '/simulacao/parar');
        if ($resultado['ok']) {
            definirAviso('ok', !empty($resultado['dados']['ja_parada']) ? 'A simulação já estava parada.' : 'Simulação parada.');
        } else {
            definirAviso('erro', mensagemErroSimulacao((string) $resultado['erro']));
        }
    } elseif ($acao === 'iniciar' || $acao === 'iniciar_dashboard') {
        if ($fator === null || !isset($velocidades[$fator])) {
            definirAviso('erro', 'Escolha uma das velocidades da lista.');
        } else {
            $resultado = chamarApiSimulacao($env, 'POST', '/simulacao/iniciar', ['fator' => $fator]);
            if ($resultado['ok']) {
                definirAviso('ok', !empty($resultado['dados']['ja_rodando'])
                    ? 'A simulação já estava rodando.'
                    : "Simulação iniciada na velocidade {$fator}x. Os primeiros pulsos chegam em instantes.");
                if ($acao === 'iniciar_dashboard') {
                    redirecionar('dashboard.php');
                }
            } else {
                definirAviso('erro', mensagemErroSimulacao((string) $resultado['erro']));
            }
        }
    } else {
        definirAviso('erro', 'Ação inválida.');
    }

    redirecionar('simulacao.php');
}

/* ------------------------------------------------------------------
 * PÁGINA
 * ------------------------------------------------------------------ */

$aviso = pegarAviso();
$estado = $habilitada ? estadoDaSimulacao($pdo, $env) : null;
$csrf = tokenCsrf();

$tituloPagina = 'Simulação das Máquinas';
$subtituloPagina = 'Liga o simulador de sensores para alimentar o sistema em tempo real';
$paginaAtiva = 'simulacao';

require __DIR__ . '/includes/layout_inicio.php';
?>

        <?php if ($aviso): ?>
            <p class="alerta alerta-<?= e($aviso['tipo']) ?>" role="status"><?= e($aviso['mensagem']) ?></p>
        <?php endif; ?>

        <?php if (!$habilitada): ?>

            <section class="panel" aria-labelledby="t-desligada">
                <header class="panel-head">
                    <div>
                        <h2 id="t-desligada">Simulação pelo site desligada</h2>
                        <p>Por segurança, o botão vem desativado.</p>
                    </div>
                </header>
                <ol class="passos-simulacao">
                    <li>Abra o arquivo <strong>.env.local</strong> na pasta do projeto.</li>
                    <li>Acrescente a linha <strong>MACHSENSE_SIMULACAO_NO_SITE=1</strong> e salve.</li>
                    <li>Deixe a API rodando (<strong>iniciar_api.bat</strong>) e recarregue esta página.</li>
                </ol>
            </section>

        <?php else: ?>

            <section class="panel" id="painel-simulacao" aria-labelledby="t-estado">
                <header class="panel-head">
                    <div>
                        <h2 id="t-estado">Estado da simulação</h2>
                        <p>Atualiza sozinho a cada 3 segundos</p>
                    </div>
                </header>

                <div class="sim-estado">
                    <div class="sim-item">
                        <span class="sim-rotulo">API do simulador</span>
                        <span id="sim-api" class="selo <?= $estado['api_ok'] ? 'selo-ok' : 'selo-parada' ?>"><?= $estado['api_ok'] ? 'Conectada' : 'Fora do ar' ?></span>
                    </div>
                    <div class="sim-item">
                        <span class="sim-rotulo">Simulação</span>
                        <span id="sim-estado" class="selo <?= $estado['rodando'] ? 'selo-ok' : 'selo-neutro' ?>"><?= $estado['rodando']
                            ? e('Rodando a ' . $estado['fator'] . 'x desde ' . $estado['iniciada_em'])
                            : 'Parada' ?></span>
                    </div>
                    <div class="sim-item">
                        <span class="sim-rotulo">Pulsos gravados</span>
                        <strong id="sim-pulsos"><?= e($estado['pulsos_total']) ?></strong>
                        <small id="sim-ultimo"><?= $estado['ultimo_pulso'] ? e('último: ' . $estado['ultimo_pulso']) : 'nenhum ainda' ?></small>
                    </div>
                </div>

                <p id="sim-api-erro" class="alerta alerta-erro" role="alert"<?= $estado['api_ok'] ? ' hidden' : '' ?>><?= e((string) $estado['api_erro']) ?></p>
                <p id="sim-saida" class="alerta alerta-erro" role="alert"<?= ($estado['api_ok'] && !$estado['rodando'] && $estado['codigo_saida'] !== null) ? '' : ' hidden' ?>>O simulador terminou sozinho (código <?= e((string) $estado['codigo_saida']) ?>). Veja o registro abaixo.</p>
            </section>

            <section class="panel panel-gap" aria-labelledby="t-controles">
                <header class="panel-head">
                    <div>
                        <h2 id="t-controles">Controles</h2>
                        <p>A API precisa estar ligada (iniciar_api.bat). Não rode o simulador no terminal ao mesmo tempo.</p>
                    </div>
                </header>

                <form method="post" action="simulacao.php" class="sim-controles">
                    <input type="hidden" name="csrf" value="<?= e($csrf) ?>">

                    <label class="campo">
                        <span>Velocidade</span>
                        <select name="fator">
                            <?php foreach ($velocidades as $valor => $descricao): ?>
                                <option value="<?= (int) $valor ?>"<?= $valor === (int) ($estado['fator'] ?? 60) ? ' selected' : '' ?>><?= e($descricao) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <button type="submit" name="acao" value="iniciar_dashboard" id="botao-iniciar-dashboard"
                            class="btn btn-primario"<?= $estado['rodando'] ? ' disabled' : '' ?>>Iniciar e abrir o dashboard</button>
                    <button type="submit" name="acao" value="iniciar" id="botao-iniciar"
                            class="btn btn-secundario"<?= $estado['rodando'] ? ' disabled' : '' ?>>Só iniciar</button>
                    <button type="submit" name="acao" value="parar" id="botao-parar"
                            class="btn btn-perigo"<?= $estado['rodando'] ? '' : ' disabled' ?>>Parar</button>
                    <a href="dashboard.php" class="btn btn-secundario">Abrir o dashboard</a>
                </form>
            </section>

            <section class="panel panel-gap" aria-labelledby="t-log">
                <header class="panel-head">
                    <div>
                        <h2 id="t-log">O que o simulador está fazendo</h2>
                        <p>Últimas linhas do registro (paradas e retomadas das máquinas)</p>
                    </div>
                </header>
                <pre id="sim-log" class="log-simulacao"><?= e($estado['log'] ? implode("\n", $estado['log']) : 'Nenhuma saída ainda.') ?></pre>
            </section>

            <script src="simulacao.js"></script>

        <?php endif; ?>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
