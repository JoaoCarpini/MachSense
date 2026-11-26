<?php

require_once __DIR__ . '/conexao.php';
require_once __DIR__ . '/protect.php';
require_once __DIR__ . '/includes/funcoes.php';

/* ------------------------------------------------------------------
 * FILTRO DE PERÍODO (GET)
 * ------------------------------------------------------------------ */

$periodos = [
    '7dias'  => 'Últimos 7 dias',
    '30dias' => 'Últimos 30 dias',
    'todos'  => 'Todo o período',
];

$periodo = $_GET['periodo'] ?? '30dias';
if (!isset($periodos[$periodo])) {
    $periodo = '30dias';
}

$desde = match ($periodo) {
    '7dias'  => date('Y-m-d 00:00:00', strtotime('-6 days')),
    '30dias' => date('Y-m-d 00:00:00', strtotime('-29 days')),
    default  => '1000-01-01 00:00:00',
};

/* ------------------------------------------------------------------
 * FILTRO POR ARTIGO (GET: artigo)
 *
 * As OPs ficam na tabela ordens_producao. Com um artigo escolhido, o
 * relatório conta só os dias em que a máquina tinha uma OP desse artigo que
 * de fato rodou (Em andamento, Pausada ou Concluída), pelas datas planejadas
 * da OP (início e término).
 *
 * O artigo vem da URL, então é conferido contra a lista de artigos do banco
 * (lista branca). Artigo desconhecido = relatório normal, sem filtro.
 * No SQL o artigo entra só como parâmetro (:artigo), nunca no texto.
 * ------------------------------------------------------------------ */

// Status que indicam que o artigo esteve de verdade na máquina
const STATUS_COM_PRODUCAO = "'em_andamento', 'pausada', 'concluida'";

$opsIndisponivel = false;
$artigos = [];
try {
    // A collation do banco não diferencia maiúsculas: "Tampa" e "tampa" viram um só
    $stmt = $pdo->prepare('SELECT artigo FROM ordens_producao GROUP BY artigo ORDER BY artigo');
    $stmt->execute();
    $artigos = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (PDOException $ex) {
    // Banco antigo, sem a tabela ordens_producao: o relatório funciona sem o filtro
    error_log('[MachSense] relatorios.php (ordens): ' . $ex->getMessage());
    $opsIndisponivel = true;
}

$artigoEscolhido = null;
$artigoGet = $_GET['artigo'] ?? '';
if (is_string($artigoGet) && trim($artigoGet) !== '' && mb_check_encoding($artigoGet, 'UTF-8')) {
    $procurado = mb_strtolower(trim($artigoGet));
    foreach ($artigos as $artigo) {
        if (mb_strtolower($artigo) === $procurado) {
            $artigoEscolhido = $artigo;
            break;
        }
    }
}

// $filtro = null significa relatório normal (sem artigo)
$filtro = null;
if ($artigoEscolhido !== null) {
    // Máquinas que tiveram alguma OP desse artigo (as outras aparecem com "—")
    $stmt = $pdo->prepare(
        'SELECT DISTINCT maquina_id FROM ordens_producao
          WHERE artigo = :artigo AND status IN (' . STATUS_COM_PRODUCAO . ')'
    );
    $stmt->execute([':artigo' => $artigoEscolhido]);
    $maquinasDoArtigo = array_fill_keys(array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);

    // Dias em que, na MESMA máquina, uma OP do artigo se sobrepõe à OP de
    // OUTRO artigo (dentro do período): os números desses dias misturam os dois.
    $stmt = $pdo->prepare(
        'SELECT DISTINCT x.maquina, x.inicio, x.fim
           FROM (SELECT m.nome AS maquina,
                        GREATEST(a.inicio, b.inicio, CAST(:desde AS DATE)) AS inicio,
                        LEAST(a.termino, b.termino) AS fim
                   FROM ordens_producao a
                   JOIN ordens_producao b
                     ON b.maquina_id = a.maquina_id
                    AND b.artigo <> a.artigo
                    AND b.inicio <= a.termino
                    AND a.inicio <= b.termino
                   JOIN maquinas m ON m.id = a.maquina_id
                  WHERE a.artigo = :artigo
                    AND a.status IN (' . STATUS_COM_PRODUCAO . ')
                    AND b.status IN (' . STATUS_COM_PRODUCAO . ')) x
          WHERE x.inicio <= x.fim
          ORDER BY x.maquina, x.inicio'
    );
    $stmt->execute([':desde' => substr($desde, 0, 10), ':artigo' => $artigoEscolhido]);

    $filtro = [
        'artigo'   => $artigoEscolhido,
        'maquinas' => $maquinasDoArtigo,
        'misturas' => $stmt->fetchAll(),
    ];
}

// Trecho extra das consultas: vazio sem filtro (consultas iguais às de antes).
// Com filtro, só entram os dias (d.dia) em que a máquina tinha uma OP do artigo.
$condicaoArtigo = '';
$paramsArtigo = [];
// Parâmetro extra do link "Exportar CSV": o CSV recebe o mesmo filtro da tela.
// http_build_query codifica o valor para a URL e, ao imprimir, e() escapa o "&".
$parametrosArtigoCsv = '';
if ($filtro) {
    $condicaoArtigo = ' AND EXISTS (SELECT 1 FROM ordens_producao o
                                     WHERE o.maquina_id = d.maquina_id
                                       AND o.artigo = :artigo
                                       AND o.status IN (' . STATUS_COM_PRODUCAO . ')
                                       AND d.dia BETWEEN o.inicio AND o.termino)';
    $paramsArtigo = [':artigo' => $filtro['artigo']];
    $parametrosArtigoCsv = '&' . http_build_query(['artigo' => $filtro['artigo']]);
}

$intervalo = intervaloPulso($pdo);

/* ------------------------------------------------------------------
 * INDICADORES POR MÁQUINA NO PERÍODO
 * Máquinas inativas só aparecem se tiverem pulsos no período.
 * Com filtro de artigo, $condicaoArtigo deixa entrar só os dias de cada
 * máquina que estão dentro de alguma janela dela.
 * ------------------------------------------------------------------ */

$stmt = $pdo->prepare(
    "SELECT m.id, m.nome, m.meta_disponibilidade, m.ativa,
            COALESCE(SUM(d.qtd_pulsos), 0)  AS total_pulsos,
            COALESCE(SUM(d.qtd_rodando), 0) AS pulsos_rodando,
            COALESCE(SUM(d.qtd_parado), 0)  AS pulsos_parado
       FROM maquinas m
       LEFT JOIN vw_disponibilidade_diaria d ON d.maquina_id = m.id AND d.dia >= :desde{$condicaoArtigo}
      GROUP BY m.id, m.nome, m.meta_disponibilidade, m.ativa
     HAVING m.ativa = 1 OR total_pulsos > 0
      ORDER BY m.nome"
);
$stmt->execute([':desde' => $desde] + $paramsArtigo);

$linhas = [];
foreach ($stmt->fetchAll() as $m) {
    $total = (int) $m['total_pulsos'];
    $pct = $total > 0 ? round(100 * $m['pulsos_rodando'] / $total, 2) : null;
    $meta = (float) $m['meta_disponibilidade'];
    // Com filtro de artigo, máquina sem nenhuma janela não tem o que mostrar
    $semJanela = $filtro !== null && !isset($filtro['maquinas'][(int) $m['id']]);

    $linhas[] = [
        'id'          => (int) $m['id'],
        'nome'        => $m['nome'],
        'ativa'       => (int) $m['ativa'] === 1,
        'meta'        => $meta,
        'produzindo'  => (int) $m['pulsos_rodando'] * $intervalo,
        'parado'      => (int) $m['pulsos_parado'] * $intervalo,
        'total'       => $total * $intervalo,
        'pct'         => $pct,
        'semJanela'   => $semJanela,
        'status'      => $semJanela ? 'Sem dados para este artigo'
                         : ($pct === null ? 'Sem dados' : ($pct >= $meta ? 'Na meta' : 'Abaixo da meta')),
    ];
}

/* ------------------------------------------------------------------
 * EXPORTAÇÃO CSV (separador ; e BOM UTF-8 para abrir no Excel)
 * ------------------------------------------------------------------ */

if (($_GET['exportar'] ?? '') === 'csv') {
    $numero = fn(?float $v) => $v === null ? '' : number_format($v, 2, ',', '');

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="machsense_relatorio_' . $periodo . '_' . date('Y-m-d') . '.csv"');

    $saida = fopen('php://output', 'w');
    fwrite($saida, "\xEF\xBB\xBF");
    fputcsv($saida, ['Período', $periodos[$periodo]], ';');
    if ($filtro) {
        // O CSV respeita o mesmo filtro da tela e avisa qual é
        fputcsv($saida, ['Artigo', $filtro['artigo'] . ' (dias estimados pelas datas planejadas das OPs)'], ';');
    }
    fputcsv($saida, ['Máquina', 'Situação', 'Meta (%)', 'Tempo produzindo (min)', 'Tempo parado (min)',
        'Tempo total (min)', 'Disponibilidade (%)', 'Status'], ';');

    foreach ($linhas as $l) {
        fputcsv($saida, [
            $l['nome'],
            $l['ativa'] ? 'Ativa' : 'Inativa',
            $numero($l['meta']),
            $l['semJanela'] ? '' : $l['produzindo'],
            $l['semJanela'] ? '' : $l['parado'],
            $l['semJanela'] ? '' : $l['total'],
            $numero($l['pct']),
            $l['status'],
        ], ';');
    }

    fclose($saida);
    exit;
}

/* ------------------------------------------------------------------
 * RESUMO E GRÁFICO
 * ------------------------------------------------------------------ */

$totalProduzindo = array_sum(array_column($linhas, 'produzindo'));
$totalParado = array_sum(array_column($linhas, 'parado'));
$totalMinutos = array_sum(array_column($linhas, 'total'));
$comDados = array_filter($linhas, fn($l) => $l['pct'] !== null);
$naMeta = count(array_filter($comDados, fn($l) => $l['status'] === 'Na meta'));
$disponibilidadeGeral = $totalMinutos > 0 ? 100 * $totalProduzindo / $totalMinutos : null;

$resumo = [
    ['titulo' => 'Disponibilidade', 'valor' => formatarPct($disponibilidadeGeral)],
    ['titulo' => 'Tempo produzindo', 'valor' => formatarMinutos($totalProduzindo)],
    ['titulo' => 'Tempo parado', 'valor' => formatarMinutos($totalParado)],
    ['titulo' => 'Máquinas na meta', 'valor' => $naMeta . ' de ' . count($comDados)],
];

// Disponibilidade por dia de cada máquina (vem da view vw_disponibilidade_diaria)
$stmt = $pdo->prepare(
    "SELECT d.maquina_id, d.dia,
            ROUND(100 * d.qtd_rodando / d.qtd_pulsos, 1) AS pct
       FROM vw_disponibilidade_diaria d
      WHERE d.dia >= :desde{$condicaoArtigo}
      ORDER BY d.dia"
);
$stmt->execute([':desde' => $desde] + $paramsArtigo);

$porDia = [];
foreach ($stmt->fetchAll() as $d) {
    $porDia[$d['dia']][(int) $d['maquina_id']] = (float) $d['pct'];
}
$dias = array_keys($porDia);

$grafico = ['rotulos' => array_map(fn($d) => date('d/m', strtotime($d)), $dias), 'series' => [], 'meta' => null];
foreach ($comDados as $l) {
    $grafico['series'][] = [
        'nome'    => $l['nome'],
        'valores' => array_map(fn($d) => $porDia[$d][$l['id']] ?? null, $dias),
    ];
}

$tituloPagina = 'Relatórios de Produção';
$subtituloPagina = 'Relatórios consolidados de disponibilidade para a equipe de gestão';
$paginaAtiva = 'relatorios';

require __DIR__ . '/includes/layout_inicio.php';
?>

<?php /* Sem filtro de artigo nada deste bloco é impresso (página igual à de antes). */ ?>
<?php if ($filtro): ?>
        <p class="alerta alerta-info">
            Filtrado por artigo: <strong><?= e($filtro['artigo']) ?></strong> (dias estimados pelas datas planejadas das OPs)
        </p>
        <?php if ($filtro['misturas']): ?>
            <div class="alerta alerta-aviso" role="alert">
                <strong>Atenção: números podem estar misturados.</strong>
                Nas máquinas e dias abaixo havia mais de uma ordem de produção ao mesmo
                tempo (de artigos diferentes). Os números desses dias incluem a produção
                das duas OPs, porque o sistema não sabe qual artigo estava na máquina em
                cada momento.
                <ul>
                    <?php foreach ($filtro['misturas'] as $mistura): ?>
                        <li><?= e($mistura['maquina']) ?>: de <?= e(date('d/m/Y', strtotime($mistura['inicio']))) ?> a <?= e(date('d/m/Y', strtotime($mistura['fim']))) ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>
<?php endif; ?>
        <div class="toolbar">
            <form class="filtros" method="get" action="relatorios.php">
                <label>
                    <span>Período</span>
                    <select name="periodo">
                        <?php foreach ($periodos as $valor => $rotulo): ?>
                            <option value="<?= e($valor) ?>"<?= $periodo === $valor ? ' selected' : '' ?>><?= e($rotulo) ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <?php if ($artigos): ?>
                    <label class="filtro-artigo">
                        <span>Artigo</span>
                        <select name="artigo">
                            <option value="">Todos</option>
                            <?php foreach ($artigos as $artigo): ?>
                                <option value="<?= e($artigo) ?>"<?= $filtro && $filtro['artigo'] === $artigo ? ' selected' : '' ?>><?= e($artigo) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
                <button type="submit" class="btn btn-secundario">Aplicar</button>
            </form>

            <a class="btn btn-primario" href="relatorios.php?periodo=<?= e($periodo) ?><?= e($parametrosArtigoCsv) ?>&amp;exportar=csv">
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 3v12M7 10l5 5 5-5M5 21h14"/></svg>
                Exportar CSV
            </a>
        </div>

        <section class="kpis" aria-label="Resumo do período">
            <?php foreach ($resumo as $r): ?>
                <article class="kpi">
                    <div class="kpi-head">
                        <h2><?= e($r['titulo']) ?></h2>
                        <span class="tag tag-ok"><?= e($periodos[$periodo]) ?></span>
                    </div>
                    <div class="kpi-body">
                        <p class="kpi-value"><?= e($r['valor']) ?></p>
                    </div>
                </article>
            <?php endforeach; ?>
        </section>

        <section class="panel" aria-labelledby="t-dia">
            <header class="panel-head">
                <div>
                    <h2 id="t-dia">Disponibilidade por Dia</h2>
                    <p>Evolução diária da disponibilidade de cada máquina</p>
                </div>
                <?php if ($grafico['series']): ?>
                    <div class="legends">
                        <?php foreach ($grafico['series'] as $i => $s): ?>
                            <span class="legend"><span class="swatch serie-<?= $i % 6 ?>"></span><?= e($s['nome']) ?></span>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </header>

            <?php if (!$grafico['series']): ?>
                <p class="empty"><strong>Nenhum pulso no período selecionado.</strong>
                    Escolha um período maior ou aguarde novos dados das máquinas.</p>
            <?php else: ?>
                <div class="chart" data-grafico="<?= e(json_encode($grafico)) ?>"
                     data-titulo="Disponibilidade por dia"></div>
            <?php endif; ?>

            <?php if ($linhas): ?>
                <div class="tabela-wrap tabela-relatorio">
                    <table class="tabela">
                        <thead>
                            <tr>
                                <th>Máquina</th>
                                <th class="num">Tempo produzindo</th>
                                <th class="num">Tempo parado</th>
                                <th class="num">Disponibilidade</th>
                                <th class="num">Meta</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($linhas as $l): ?>
                                <tr>
                                    <td>
                                        <a href="maquina.php?id=<?= $l['id'] ?>"><?= e($l['nome']) ?></a>
                                        <?php if (!$l['ativa']): ?><small class="texto-fraco">(inativa)</small><?php endif; ?>
                                    </td>
                                    <td class="num mono"><?= e($l['semJanela'] ? '—' : formatarMinutos($l['produzindo'])) ?></td>
                                    <td class="num mono"><?= e($l['semJanela'] ? '—' : formatarMinutos($l['parado'])) ?></td>
                                    <td class="num mono pct-<?= $l['semJanela'] ? 'neutro' : classeDisponibilidade($l['pct'], $l['meta']) ?>"><?= e(formatarPct($l['pct'])) ?></td>
                                    <td class="num mono"><?= e(formatarPct($l['meta'])) ?></td>
                                    <td>
                                        <?php if ($l['semJanela']): ?>
                                            <span class="selo selo-neutro">Sem dados para este artigo</span>
                                        <?php elseif ($l['pct'] === null): ?>
                                            <span class="selo selo-neutro">Sem dados</span>
                                        <?php elseif ($l['status'] === 'Na meta'): ?>
                                            <span class="selo selo-ok">Na meta</span>
                                        <?php else: ?>
                                            <span class="selo selo-parada">Abaixo da meta</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

        <?php
        /*
         * Ordens de produção por artigo, no mesmo período do relatório.
         * Uma OP entra se o intervalo [início, término] dela encostar no
         * período. "Todo o período" = todas as OPs. Com filtro de artigo,
         * mostra só aquele artigo.
         */
        $opsPorArtigo = [];
        if (!$opsIndisponivel) {
            $condicoes = [];
            $params = [];
            if ($periodo !== 'todos') {
                $condicoes[] = 'o.inicio <= :ate AND o.termino >= :desde_dia';
                $params[':ate'] = date('Y-m-d');
                $params[':desde_dia'] = substr($desde, 0, 10);
            }
            if ($filtro) {
                $condicoes[] = 'o.artigo = :artigo';
                $params[':artigo'] = $filtro['artigo'];
            }
            $stmt = $pdo->prepare(
                "SELECT o.artigo,
                        COUNT(*)                       AS ops,
                        SUM(o.quantidade)              AS quantidade,
                        SUM(o.status = 'planejada')    AS planejada,
                        SUM(o.status = 'em_andamento') AS em_andamento,
                        SUM(o.status = 'pausada')      AS pausada,
                        SUM(o.status = 'concluida')    AS concluida,
                        SUM(o.status = 'cancelada')    AS cancelada
                   FROM ordens_producao o
                  " . ($condicoes ? 'WHERE ' . implode(' AND ', $condicoes) : '') . "
                  GROUP BY o.artigo
                  ORDER BY o.artigo"
            );
            $stmt->execute($params);
            $opsPorArtigo = $stmt->fetchAll();
        }
        $colunasStatus = [
            'planejada' => 'Planejada', 'em_andamento' => 'Em andamento', 'pausada' => 'Pausada',
            'concluida' => 'Concluída', 'cancelada' => 'Cancelada',
        ];
        $numeroBr = fn($v) => number_format((int) $v, 0, ',', '.');
        ?>

        <section class="panel panel-gap" aria-labelledby="t-ops-artigo">
            <header class="panel-head">
                <div>
                    <h2 id="t-ops-artigo">Ordens de Produção por Artigo</h2>
                    <p>OPs do módulo Ordens de Produção no período selecionado</p>
                </div>
            </header>

            <?php if ($opsIndisponivel): ?>
                <p class="empty"><strong>Ordens de produção indisponíveis.</strong>
                    Importe o schema.sql novo no banco para criar a tabela ordens_producao.</p>
            <?php elseif (!$opsPorArtigo): ?>
                <p class="empty"><strong>Nenhuma ordem de produção no período.</strong></p>
            <?php else: ?>
                <div class="tabela-wrap tabela-relatorio">
                    <table class="tabela">
                        <thead>
                            <tr>
                                <th>Artigo</th>
                                <th class="num">OPs</th>
                                <th class="num">Qtd. planejada</th>
                                <?php foreach ($colunasStatus as $rotulo): ?>
                                    <th class="num"><?= e($rotulo) ?></th>
                                <?php endforeach; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($opsPorArtigo as $linha): ?>
                                <tr>
                                    <td><?= e($linha['artigo']) ?></td>
                                    <td class="num mono"><?= e($numeroBr($linha['ops'])) ?></td>
                                    <td class="num mono"><?= e($numeroBr($linha['quantidade'])) ?></td>
                                    <?php foreach (array_keys($colunasStatus) as $status): ?>
                                        <td class="num mono"><?= e($numeroBr($linha[$status])) ?></td>
                                    <?php endforeach; ?>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
