<?php

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/conexao.php';
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
 * FILTRO POR ARTIGO (GET: artigo, janelas, outras)
 *
 * As OPs ficam no localStorage do navegador. Quando o usuário escolhe um
 * artigo, o ordens_por_artigo.js envia as "janelas" das OPs desse artigo
 * (Em andamento, Pausada ou Concluída):
 *     janelas = "Máquina 1|2026-11-10|2026-11-14;Máquina 2|2026-11-12|2026-11-13"
 * (máquina | início | término, separadas por ";"). O parâmetro "outras" tem
 * o mesmo formato e traz as OPs de OUTROS artigos que encostam nessas
 * janelas; ele serve só para o aviso de mistura.
 *
 * Isso vem do navegador, então é entrada NÃO confiável:
 *   - cada janela passa por regex + checkdate; no máximo 50; inválidas saem;
 *   - o nome da máquina vira id por consulta preparada;
 *   - no SQL só entram placeholders (:jm0, :ji0...), nunca os valores;
 *   - entrada malformada = relatório normal, sem filtro e sem erro na tela.
 * ------------------------------------------------------------------ */

const MAX_JANELAS = 50;

/**
 * Lê "máquina|AAAA-MM-DD|AAAA-MM-DD;...". Devolve as janelas válidas,
 * [] para texto vazio, ou null se a entrada for malformada (tipo errado,
 * grande demais, mais de 50 itens ou nenhum item válido).
 */
function lerJanelas(mixed $valor): ?array
{
    if (!is_string($valor) || strlen($valor) > 10000) {
        return null;
    }
    if ($valor === '') {
        return [];
    }

    $itens = explode(';', $valor);
    if (count($itens) > MAX_JANELAS) {
        return null;
    }

    $janelas = [];
    foreach ($itens as $item) {
        // Nome da máquina: 1 a 100 caracteres, sem "|", ";" nem caracteres de
        // controle. Depois duas datas AAAA-MM-DD. O /u trata o texto como UTF-8.
        $ok = preg_match(
            '/^([^|;\x00-\x1F\x7F]{1,100})\|(\d{4})-(\d{2})-(\d{2})\|(\d{4})-(\d{2})-(\d{2})$/u',
            $item,
            $m
        );
        // checkdate(mês, dia, ano) recusa datas que não existem, como 30/02
        if ($ok !== 1
            || !checkdate((int) $m[3], (int) $m[4], (int) $m[2])
            || !checkdate((int) $m[6], (int) $m[7], (int) $m[5])) {
            continue;
        }

        $inicio = "{$m[2]}-{$m[3]}-{$m[4]}";
        $fim = "{$m[5]}-{$m[6]}-{$m[7]}";
        if ($fim < $inicio) {
            continue; // término antes do início: janela inválida
        }
        $janelas[] = ['maquina' => $m[1], 'inicio' => $inicio, 'fim' => $fim];
    }

    return $janelas ? $janelas : null;
}

/**
 * Troca o nome da máquina de cada janela pelo id do banco (consulta
 * preparada). Janelas de máquinas que não existem são descartadas.
 */
function janelasComId(PDO $pdo, array $janelas): array
{
    $stmt = $pdo->prepare('SELECT id, nome FROM maquinas WHERE nome = :nome');
    $encontradas = []; // nome enviado => linha do banco (ou null)
    $resultado = [];

    foreach ($janelas as $j) {
        if (!array_key_exists($j['maquina'], $encontradas)) {
            $stmt->execute([':nome' => $j['maquina']]);
            $encontradas[$j['maquina']] = $stmt->fetch() ?: null;
        }
        $maquina = $encontradas[$j['maquina']];
        if ($maquina) {
            $resultado[] = [
                'maquina_id' => (int) $maquina['id'],
                'maquina'    => $maquina['nome'],
                'inicio'     => $j['inicio'],
                'fim'        => $j['fim'],
            ];
        }
    }

    return $resultado;
}

/**
 * Monta o trecho de SQL das janelas, por exemplo
 *     (d.maquina_id = :jm0 AND d.dia BETWEEN :ji0 AND :jf0) OR (...)
 * e a lista de valores. Só os NOMES dos placeholders (feitos com o número
 * da janela) entram no texto do SQL; os valores vão separados, no execute().
 */
function sqlDasJanelas(array $janelas): array
{
    if (!$janelas) {
        return ['sql' => '0 = 1', 'params' => []]; // nenhuma janela: nenhum dia entra
    }

    $partes = [];
    $params = [];
    foreach (array_values($janelas) as $n => $j) {
        $partes[] = "(d.maquina_id = :jm{$n} AND d.dia BETWEEN :ji{$n} AND :jf{$n})";
        $params[":jm{$n}"] = $j['maquina_id'];
        $params[":ji{$n}"] = $j['inicio'];
        $params[":jf{$n}"] = $j['fim'];
    }

    return ['sql' => '(' . implode(' OR ', $partes) . ')', 'params' => $params];
}

/**
 * Dias em que, na MESMA máquina, uma janela do artigo se sobrepõe à janela
 * de OUTRO artigo, dentro do período. Datas AAAA-MM-DD comparam como texto.
 */
function diasMisturados(array $janelas, array $outras, string $desdeDia): array
{
    $misturas = [];
    foreach ($janelas as $j) {
        foreach ($outras as $o) {
            if ($j['maquina_id'] !== $o['maquina_id']) {
                continue;
            }
            $inicio = max($j['inicio'], $o['inicio'], $desdeDia);
            $fim = min($j['fim'], $o['fim']);
            if ($inicio <= $fim) {
                // a chave evita repetir o mesmo trecho na lista
                $misturas[$j['maquina'] . $inicio . $fim] = ['maquina' => $j['maquina'], 'inicio' => $inicio, 'fim' => $fim];
            }
        }
    }

    return array_values($misturas);
}

// $filtro = null significa relatório normal (sem artigo)
$filtro = null;
$artigoGet = $_GET['artigo'] ?? null;

if (is_string($artigoGet) && array_key_exists('janelas', $_GET)) {
    $artigo = trim($artigoGet);
    $artigoOk = $artigo !== '' && mb_check_encoding($artigo, 'UTF-8') && mb_strlen($artigo) <= 60;
    $janelas = lerJanelas($_GET['janelas']);

    if ($artigoOk && $janelas !== null) {
        $janelasComId = janelasComId($pdo, $janelas);

        // Vieram janelas, mas nenhuma máquina existe: entrada malformada.
        // Sem janelas ("") é válido: o artigo não tem OP iniciada.
        if (!$janelas || $janelasComId) {
            $outras = janelasComId($pdo, lerJanelas($_GET['outras'] ?? '') ?? []);
            $filtro = [
                'artigo'   => $artigo,
                'janelas'  => $janelasComId,
                // máquinas que têm janela (as outras aparecem com "—")
                'maquinas' => array_fill_keys(array_column($janelasComId, 'maquina_id'), true),
                'misturas' => diasMisturados($janelasComId, $outras, substr($desde, 0, 10)),
            ];
        }
    }
}

// Trecho extra das consultas: vazio sem filtro (consultas iguais às de antes)
$condicaoArtigo = '';
$paramsArtigo = [];
// Parâmetros extras do link "Exportar CSV": com artigo, o CSV recebe o mesmo
// filtro da tela. http_build_query codifica os valores para a URL e, na hora
// de imprimir, e() escapa o "&" para o HTML.
$parametrosArtigoCsv = '';
if ($filtro) {
    $sqlJanelas = sqlDasJanelas($filtro['janelas']);
    $condicaoArtigo = ' AND ' . $sqlJanelas['sql'];
    $paramsArtigo = $sqlJanelas['params'];
    $parametrosArtigoCsv = '&' . http_build_query([
        'artigo'  => $filtro['artigo'],
        'janelas' => $_GET['janelas'],
        'outras'  => is_string($_GET['outras'] ?? null) ? $_GET['outras'] : '',
    ]);
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
        // Período do filtro em AAAA-MM-DD para o cartão de ordens (só leitura
        // de variáveis já calculadas acima; nenhuma consulta muda).
        // "Todo o período" = sem filtro = atributos vazios. Nos outros, o
        // período vai de $desde (o mesmo das consultas) até hoje.
        $opsDesde = $periodo === 'todos' ? '' : substr($desde, 0, 10);
        $opsAte = $periodo === 'todos' ? '' : date('Y-m-d');
        ?>

        <!-- Ordens de produção por artigo. As OPs ficam no localStorage do
             navegador (módulo pi_ordem_producao), que o PHP não enxerga: aqui o
             PHP só entrega o período em data-desde/data-ate, e o
             ordens_por_artigo.js faz a conta no navegador. -->
        <section class="panel panel-gap" id="ops-artigo"
                 data-desde="<?= e($opsDesde) ?>" data-ate="<?= e($opsAte) ?>"
                 aria-labelledby="t-ops-artigo">
            <header class="panel-head">
                <div>
                    <h2 id="t-ops-artigo">Ordens de Produção por Artigo</h2>
                    <p>Dados deste navegador (módulo Ordem de Produção)</p>
                </div>
                <div class="filtros">
                    <label>
                        <span>Artigo</span>
                        <!-- As opções são criadas pelo script com os artigos do período -->
                        <select id="ops-artigo-filtro">
                            <option value="">Todos</option>
                        </select>
                    </label>
                </div>
            </header>
            <div id="ops-artigo-conteudo">
                <p class="empty"><strong>Nenhuma ordem de produção no período.</strong></p>
            </div>
        </section>

        <script src="ordens_por_artigo.js"></script>

<?php require __DIR__ . '/includes/layout_fim.php'; ?>
