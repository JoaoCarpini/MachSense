<?php
declare(strict_types=1);

/*
 * ordens_producao_api.php — API (JSON) do módulo Ordens de Produção.
 *
 * O módulo pi_ordem_producao/ (HTML, CSS e JavaScript) conversa com este
 * arquivo por fetch(). Os dados ficam na tabela ordens_producao, então todos
 * os usuários e computadores veem as mesmas OPs.
 *
 *   GET                       -> { ok, maquinas: [...], ordens: [...] }
 *   POST (JSON) { acao, ... } -> { ok, mensagem, erros: [{campo, texto}], ordens: [...] }
 *       acao = criar | atualizar | status | excluir | importar
 *
 * Segurança:
 *   - exige login (protect.php responde 401 em JSON se a sessão acabou);
 *   - POST só com o token CSRF no cabeçalho X-CSRF-Token e corpo JSON;
 *   - TODA regra é conferida aqui de novo (formato, datas, máquina, transições
 *     de status). A validação do JavaScript é só para a tela responder rápido;
 *   - consultas preparadas; nada que vem do navegador vira SQL.
 */

$protectJson = true;
require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/protect.php';

header('Content-Type: application/json; charset=utf-8');

/** Status possíveis e para onde cada um pode ir (mesma regra do script.js). */
const OP_TRANSICOES = [
    'planejada'    => ['em_andamento', 'cancelada'],
    'em_andamento' => ['pausada', 'concluida', 'cancelada'],
    'pausada'      => ['em_andamento', 'cancelada'],
    'concluida'    => [],
    'cancelada'    => [],
];

const OP_NOMES_STATUS = [
    'planejada'    => 'Planejada',
    'em_andamento' => 'Em andamento',
    'pausada'      => 'Pausada',
    'concluida'    => 'Concluída',
    'cancelada'    => 'Cancelada',
];

const OP_ARTIGO_MIN = 2;
const OP_ARTIGO_MAX = 60;
const OP_QTD_MAX = 4294967295; // limite do INT UNSIGNED
const OP_IMPORTAR_MAX = 500;

function responder(array $dados, int $codigo = 200): never
{
    http_response_code($codigo);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

function ehFinal(string $status): bool
{
    return (OP_TRANSICOES[$status] ?? []) === [];
}

function dataValida(mixed $valor): bool
{
    return is_string($valor)
        && preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $valor, $m) === 1
        && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
}

function listarOrdens(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        'SELECT o.id, o.numero, o.artigo, o.quantidade, o.maquina_id, m.nome AS maquina,
                o.inicio, o.termino, o.status
           FROM ordens_producao o
           JOIN maquinas m ON m.id = o.maquina_id
          ORDER BY o.id DESC'
    );
    $stmt->execute();

    return array_map(fn(array $o) => [
        'id'         => (int) $o['id'],
        'numero'     => $o['numero'],
        'artigo'     => $o['artigo'],
        'quantidade' => (int) $o['quantidade'],
        'maquina_id' => (int) $o['maquina_id'],
        'maquina'    => $o['maquina'],
        'inicio'     => $o['inicio'],
        'termino'    => $o['termino'],
        'status'     => $o['status'],
    ], $stmt->fetchAll());
}

function listarMaquinas(PDO $pdo): array
{
    $stmt = $pdo->prepare('SELECT id, nome, ativa FROM maquinas ORDER BY nome');
    $stmt->execute();

    return array_map(fn(array $m) => [
        'id'    => (int) $m['id'],
        'nome'  => $m['nome'],
        'ativa' => (int) $m['ativa'] === 1,
    ], $stmt->fetchAll());
}

function buscarOrdem(PDO $pdo, int $id): ?array
{
    $stmt = $pdo->prepare('SELECT id, numero, artigo, maquina_id, status FROM ordens_producao WHERE id = :id');
    $stmt->execute([':id' => $id]);

    return $stmt->fetch() ?: null;
}

/**
 * Confere os campos de uma OP. Devolve [dados limpos, erros].
 * $idAtual: na edição, a própria OP não conta como número repetido.
 * $maquinaAtual: na edição, a OP pode continuar numa máquina que foi desativada.
 */
function validarOrdem(PDO $pdo, array $d, ?int $idAtual = null, ?int $maquinaAtual = null): array
{
    $erros = [];

    $numero = is_string($d['numero'] ?? null) ? strtoupper(trim($d['numero'])) : '';
    if (preg_match('/^OP-\d{4}$/', $numero) !== 1) {
        $erros[] = ['campo' => 'numero', 'texto' => 'O número deve seguir o formato OP-0000 (ex.: OP-0042).'];
    } else {
        $stmt = $pdo->prepare('SELECT 1 FROM ordens_producao WHERE numero = :numero AND id <> :id');
        $stmt->execute([':numero' => $numero, ':id' => $idAtual ?? 0]);
        if ($stmt->fetchColumn()) {
            $erros[] = ['campo' => 'numero', 'texto' => "Já existe uma OP com o número $numero."];
        }
    }

    $artigo = is_string($d['artigo'] ?? null) ? trim($d['artigo']) : '';
    if (!mb_check_encoding($artigo, 'UTF-8') || preg_match('/[\x00-\x1F\x7F]/', $artigo) === 1
        || mb_strlen($artigo) < OP_ARTIGO_MIN || mb_strlen($artigo) > OP_ARTIGO_MAX) {
        $erros[] = ['campo' => 'produto', 'texto' => 'O artigo deve ter de ' . OP_ARTIGO_MIN . ' a ' . OP_ARTIGO_MAX . ' caracteres.'];
    }

    // Só aceita número inteiro ou texto (true/false do JSON não viram 1/0)
    $soInteiroOuTexto = fn(mixed $v) => is_int($v) || is_string($v) ? $v : null;

    $quantidade = filter_var($soInteiroOuTexto($d['quantidade'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => OP_QTD_MAX]]);
    if ($quantidade === false) {
        $erros[] = ['campo' => 'quantidade', 'texto' => 'A quantidade deve ser um número inteiro maior que zero.'];
    }

    $maquinaId = filter_var($soInteiroOuTexto($d['maquina_id'] ?? null), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    $maquinaOk = false;
    if ($maquinaId !== false) {
        $stmt = $pdo->prepare('SELECT ativa FROM maquinas WHERE id = :id');
        $stmt->execute([':id' => $maquinaId]);
        $ativa = $stmt->fetchColumn();
        $maquinaOk = $ativa !== false && ((int) $ativa === 1 || $maquinaId === $maquinaAtual);
    }
    if (!$maquinaOk) {
        $erros[] = ['campo' => 'maquina', 'texto' => 'Escolha uma das máquinas ativas da lista.'];
    }

    $inicio = $d['inicio'] ?? '';
    $termino = $d['termino'] ?? '';
    if (!dataValida($inicio)) {
        $erros[] = ['campo' => 'inicio', 'texto' => 'Informe uma data de início válida.'];
    }
    if (!dataValida($termino)) {
        $erros[] = ['campo' => 'termino', 'texto' => 'Informe uma data de término válida.'];
    } elseif (dataValida($inicio) && $termino < $inicio) {
        $erros[] = ['campo' => 'termino', 'texto' => 'O término não pode ser antes do início.'];
    }

    return [[
        'numero'     => $numero,
        'artigo'     => $artigo,
        'quantidade' => $quantidade === false ? 0 : $quantidade,
        'maquina_id' => $maquinaId === false ? 0 : $maquinaId,
        'inicio'     => is_string($inicio) ? $inicio : '',
        'termino'    => is_string($termino) ? $termino : '',
    ], $erros];
}

/** 1062 = chave UNIQUE repetida (duas pessoas gravando o mesmo número ao mesmo tempo). */
function ehNumeroRepetido(PDOException $ex): bool
{
    return (int) ($ex->errorInfo[1] ?? 0) === 1062;
}

/* ------------------------------------------------------------------
 * GET: lista
 * ------------------------------------------------------------------ */

$metodo = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($metodo === 'GET') {
        session_write_close(); // só leitura: libera a sessão para as outras abas
        responder(['ok' => true, 'maquinas' => listarMaquinas($pdo), 'ordens' => listarOrdens($pdo)]);
    }

    if ($metodo !== 'POST') {
        header('Allow: GET, POST');
        responder(['ok' => false, 'mensagem' => 'Método não permitido.'], 405);
    }

    /* --------------------------------------------------------------
     * POST: confere token e corpo
     * -------------------------------------------------------------- */

    $tipo = (string) ($_SERVER['CONTENT_TYPE'] ?? '');
    if (!str_starts_with(strtolower($tipo), 'application/json')) {
        responder(['ok' => false, 'mensagem' => 'Envie os dados em JSON.'], 415);
    }
    if (!csrfValido((string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
        responder(['ok' => false, 'mensagem' => 'Sessão expirada. Recarregue a página e tente de novo.'], 403);
    }

    $corpo = file_get_contents('php://input', false, null, 0, 1_000_000);
    $dados = json_decode((string) $corpo, true, 16);
    if (!is_array($dados) || !is_string($dados['acao'] ?? null)) {
        responder(['ok' => false, 'mensagem' => 'Requisição inválida.'], 400);
    }

    $acao = $dados['acao'];
    $id = is_int($dados['id'] ?? null) && $dados['id'] > 0 ? $dados['id'] : false;

    /* ---------------- CREATE ---------------- */
    if ($acao === 'criar') {
        [$op, $erros] = validarOrdem($pdo, $dados);
        if ($erros) {
            responder(['ok' => false, 'erros' => $erros], 422);
        }
        try {
            $stmt = $pdo->prepare(
                "INSERT INTO ordens_producao (numero, artigo, quantidade, maquina_id, inicio, termino, status)
                 VALUES (:numero, :artigo, :quantidade, :maquina_id, :inicio, :termino, 'planejada')"
            );
            $stmt->execute([
                ':numero' => $op['numero'], ':artigo' => $op['artigo'], ':quantidade' => $op['quantidade'],
                ':maquina_id' => $op['maquina_id'], ':inicio' => $op['inicio'], ':termino' => $op['termino'],
            ]);
        } catch (PDOException $ex) {
            if (ehNumeroRepetido($ex)) {
                responder(['ok' => false, 'erros' => [['campo' => 'numero', 'texto' => "Já existe uma OP com o número {$op['numero']}."]]], 422);
            }
            throw $ex;
        }
        responder(['ok' => true, 'mensagem' => "{$op['numero']} cadastrada como \"Planejada\".", 'ordens' => listarOrdens($pdo)]);
    }

    /* ---------------- UPDATE (dados) ---------------- */
    if ($acao === 'atualizar') {
        $atual = $id === false ? null : buscarOrdem($pdo, $id);
        if (!$atual) {
            responder(['ok' => false, 'mensagem' => 'A OP não foi encontrada. Ela pode ter sido excluída.', 'ordens' => listarOrdens($pdo)], 404);
        }
        if (ehFinal($atual['status'])) {
            responder(['ok' => false, 'mensagem' => 'Esta OP já foi finalizada e não pode mais ser editada.', 'ordens' => listarOrdens($pdo)], 409);
        }
        [$op, $erros] = validarOrdem($pdo, $dados, (int) $atual['id'], (int) $atual['maquina_id']);
        if ($erros) {
            responder(['ok' => false, 'erros' => $erros], 422);
        }
        try {
            // "AND status = :status": se outra pessoa finalizou a OP no meio do caminho, não grava
            $stmt = $pdo->prepare(
                'UPDATE ordens_producao
                    SET numero = :numero, artigo = :artigo, quantidade = :quantidade,
                        maquina_id = :maquina_id, inicio = :inicio, termino = :termino
                  WHERE id = :id AND status = :status'
            );
            $stmt->execute([
                ':numero' => $op['numero'], ':artigo' => $op['artigo'], ':quantidade' => $op['quantidade'],
                ':maquina_id' => $op['maquina_id'], ':inicio' => $op['inicio'], ':termino' => $op['termino'],
                ':id' => $atual['id'], ':status' => $atual['status'],
            ]);
        } catch (PDOException $ex) {
            if (ehNumeroRepetido($ex)) {
                responder(['ok' => false, 'erros' => [['campo' => 'numero', 'texto' => "Já existe uma OP com o número {$op['numero']}."]]], 422);
            }
            throw $ex;
        }
        responder(['ok' => true, 'mensagem' => "{$op['numero']} atualizada.", 'ordens' => listarOrdens($pdo)]);
    }

    /* ---------------- UPDATE (status) ---------------- */
    if ($acao === 'status') {
        $novo = is_string($dados['status'] ?? null) ? $dados['status'] : '';
        $atual = $id === false ? null : buscarOrdem($pdo, $id);
        if (!$atual) {
            responder(['ok' => false, 'mensagem' => 'A OP não foi encontrada. Ela pode ter sido excluída.', 'ordens' => listarOrdens($pdo)], 404);
        }
        if (!in_array($novo, OP_TRANSICOES[$atual['status']] ?? [], true)) {
            responder(['ok' => false, 'mensagem' => 'Mudança não permitida: ' . (OP_NOMES_STATUS[$atual['status']] ?? '?')
                . ' → ' . (OP_NOMES_STATUS[$novo] ?? 'status desconhecido') . '.', 'ordens' => listarOrdens($pdo)], 409);
        }
        $stmt = $pdo->prepare('UPDATE ordens_producao SET status = :novo WHERE id = :id AND status = :atual');
        $stmt->execute([':novo' => $novo, ':id' => $atual['id'], ':atual' => $atual['status']]);
        if ($stmt->rowCount() === 0) {
            responder(['ok' => false, 'mensagem' => 'A OP mudou enquanto você olhava. A lista foi atualizada.', 'ordens' => listarOrdens($pdo)], 409);
        }
        responder(['ok' => true, 'mensagem' => "{$atual['numero']} agora está \"" . OP_NOMES_STATUS[$novo] . '".', 'ordens' => listarOrdens($pdo)]);
    }

    /* ---------------- DELETE ---------------- */
    if ($acao === 'excluir') {
        $atual = $id === false ? null : buscarOrdem($pdo, $id);
        if ($atual) {
            $stmt = $pdo->prepare('DELETE FROM ordens_producao WHERE id = :id');
            $stmt->execute([':id' => $atual['id']]);
        }
        responder(['ok' => true, 'mensagem' => $atual ? "{$atual['numero']} excluída." : 'A OP já tinha sido excluída.', 'ordens' => listarOrdens($pdo)]);
    }

    /* ---------------- IMPORTAR (localStorage antigo -> banco) ----------------
     * Recebe as OPs que ficaram salvas no navegador na versão anterior do
     * módulo. Cada uma passa pela mesma validação; a máquina vem pelo NOME.
     * As inválidas ou com número já usado são ignoradas e contadas. */
    if ($acao === 'importar') {
        $lista = $dados['ordens'] ?? null;
        if (!is_array($lista) || !array_is_list($lista) || count($lista) > OP_IMPORTAR_MAX) {
            responder(['ok' => false, 'mensagem' => 'Lista de OPs inválida.'], 400);
        }

        $stmtMaquina = $pdo->prepare('SELECT id FROM maquinas WHERE nome = :nome');
        $stmtInserir = $pdo->prepare(
            'INSERT INTO ordens_producao (numero, artigo, quantidade, maquina_id, inicio, termino, status)
             VALUES (:numero, :artigo, :quantidade, :maquina_id, :inicio, :termino, :status)'
        );
        $importadas = 0;
        $ignoradas = 0;

        foreach ($lista as $item) {
            $status = is_array($item) && is_string($item['status'] ?? null) ? $item['status'] : '';
            $nomeMaquina = is_array($item) && is_string($item['maquina'] ?? null) ? trim($item['maquina']) : '';
            if (!isset(OP_TRANSICOES[$status]) || $nomeMaquina === '') {
                $ignoradas++;
                continue;
            }
            $stmtMaquina->execute([':nome' => $nomeMaquina]);
            $maquinaId = $stmtMaquina->fetchColumn();
            if ($maquinaId === false) {
                $ignoradas++;
                continue;
            }

            // A versão antiga chamava o artigo de "produto"
            [$op, $erros] = validarOrdem($pdo, [
                'numero'     => $item['numero'] ?? null,
                'artigo'     => $item['artigo'] ?? ($item['produto'] ?? null),
                'quantidade' => $item['quantidade'] ?? null,
                'maquina_id' => (int) $maquinaId,
                'inicio'     => $item['inicio'] ?? null,
                'termino'    => $item['termino'] ?? null,
            ], null, (int) $maquinaId);
            if ($erros) {
                $ignoradas++;
                continue;
            }

            try {
                $stmtInserir->execute([
                    ':numero' => $op['numero'], ':artigo' => $op['artigo'], ':quantidade' => $op['quantidade'],
                    ':maquina_id' => $op['maquina_id'], ':inicio' => $op['inicio'], ':termino' => $op['termino'],
                    ':status' => $status,
                ]);
                $importadas++;
            } catch (PDOException $ex) {
                if (!ehNumeroRepetido($ex)) {
                    throw $ex;
                }
                $ignoradas++;
            }
        }

        $mensagem = "$importadas OP(s) importada(s) para o banco.";
        if ($ignoradas > 0) {
            $mensagem .= " $ignoradas ignorada(s) (número já usado, máquina inexistente ou dados inválidos).";
        }
        responder(['ok' => true, 'mensagem' => $mensagem, 'importadas' => $importadas, 'ignoradas' => $ignoradas, 'ordens' => listarOrdens($pdo)]);
    }

    responder(['ok' => false, 'mensagem' => 'Ação inválida.'], 400);
} catch (PDOException $ex) {
    error_log('[MachSense] ordens_producao_api: ' . $ex->getMessage());
    // 42S02 = tabela não existe (banco antigo sem o schema.sql novo)
    $mensagem = ($ex->getCode() === '42S02')
        ? 'A tabela de ordens de produção ainda não existe. Importe o schema.sql novo no banco.'
        : 'Não foi possível concluir a operação. Tente novamente em instantes.';
    responder(['ok' => false, 'mensagem' => $mensagem], 503);
}
