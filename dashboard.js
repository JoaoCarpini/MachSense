document.addEventListener('DOMContentLoaded', function () {
    // Relógio da planta
    const relogio = document.getElementById('plant-time');
    if (relogio) {
        setInterval(function () {
            relogio.textContent = new Date().toLocaleTimeString('pt-BR', { hour12: false });
        }, 1000);
    }

    aplicarLarguras();
    document.querySelectorAll('[data-grafico]').forEach(desenharGrafico);

    iniciarDetalheParadas();
    iniciarAtualizacaoDashboard();
});

/* ------------------------------------------------------------------
 * BARRAS DE PROGRESSO
 * A CSP do site (style-src 'self') bloqueia style="..." escrito no HTML.
 * Por isso o PHP manda a largura em data-largura (e a posição da marca de
 * meta em data-posicao), e o JavaScript aplica: style.width pelo JS é
 * permitido. Os valores são números de 0 a 100; qualquer outra coisa é ignorada.
 * ------------------------------------------------------------------ */

function porcentagemValida(texto) {
    const numero = Number(texto);
    return Number.isFinite(numero) ? Math.min(100, Math.max(0, numero)) : null;
}

function aplicarLarguras() {
    document.querySelectorAll('[data-largura]').forEach(function (el) {
        const pct = porcentagemValida(el.dataset.largura);
        if (pct !== null) {
            el.style.width = pct + '%';
        }
    });
    document.querySelectorAll('[data-posicao]').forEach(function (el) {
        const pct = porcentagemValida(el.dataset.posicao);
        if (pct !== null) {
            el.style.left = pct + '%';
        }
    });
}

/* ------------------------------------------------------------------
 * ATUALIZAÇÃO AUTOMÁTICA DO DASHBOARD
 * A cada 10 s busca dashboard_dados.php (JSON, somente leitura) e troca os
 * números na tela, sem recarregar a página. Tudo é escrito com textContent,
 * nunca com innerHTML, então nenhum texto vindo do banco vira HTML.
 * ------------------------------------------------------------------ */

const INTERVALO_ATUALIZACAO_MS = 10000;
const URL_DADOS_DASHBOARD = 'dashboard_dados.php';

function iniciarAtualizacaoDashboard() {
    // Este arquivo é carregado em todas as páginas; só o dashboard se atualiza
    if (!/\/dashboard\.php$/.test(window.location.pathname)) {
        return;
    }
    const cartoes = document.querySelectorAll('.kpis .kpi');
    if (cartoes.length === 0) {
        return;
    }

    // Como a página foi montada pelo PHP (com dados ou vazia). Se isso mudar,
    // por exemplo o primeiro pulso chegou, recarregamos para montar a estrutura certa.
    const estruturaInicial = estruturaDaPagina();
    const textoAtualizacao = criarTextoDeAtualizacao();
    let emAndamento = false;

    async function atualizar() {
        // Aba escondida não precisa de dados novos; ao voltar, atualiza na hora
        if (document.hidden || emAndamento) {
            return;
        }
        emAndamento = true;
        try {
            const resposta = await fetch(URL_DADOS_DASHBOARD, {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            });
            const tipo = resposta.headers.get('content-type') || '';

            // Sessão expirada: o servidor devolve a tela de login (HTML), não JSON
            if (resposta.redirected || !tipo.includes('application/json')) {
                window.location.reload();
                return;
            }
            if (!resposta.ok) {
                throw new Error('HTTP ' + resposta.status);
            }

            const dados = await resposta.json();
            if (dados.sem_pulsos !== estruturaInicial.semPulsos
                || (dados.qtd_maquinas === 0) !== estruturaInicial.semMaquinas) {
                window.location.reload();
                return;
            }

            aplicarDadosDashboard(dados, cartoes);
            textoAtualizacao.textContent = ' · atualizado às ' + dados.gerado_em;
        } catch (erro) {
            textoAtualizacao.textContent = ' · sem atualização (tentando de novo)';
        } finally {
            emAndamento = false;
        }
    }

    setInterval(atualizar, INTERVALO_ATUALIZACAO_MS);
    document.addEventListener('visibilitychange', atualizar);
}

/** Lê do HTML se a página nasceu sem pulsos e/ou sem máquinas ativas. */
function estruturaDaPagina() {
    const secaoMetas = document.getElementById('t-meta');
    return {
        semPulsos: !document.querySelector('[data-grafico]'),
        semMaquinas: !(secaoMetas && secaoMetas.closest('section').querySelector('ul.metas'))
    };
}

/** Texto discreto ao lado do subtítulo da página, mostrando a última atualização. */
function criarTextoDeAtualizacao() {
    const texto = document.createElement('span');
    const subtitulo = document.querySelector('.topbar p');
    if (subtitulo) {
        subtitulo.appendChild(texto);
    }
    return texto;
}

function aplicarDadosDashboard(dados, cartoes) {
    // Cartões do topo (mesma ordem do HTML)
    dados.kpis.forEach(function (kpi, i) {
        if (!cartoes[i]) {
            return;
        }
        cartoes[i].querySelector('.kpi-value').textContent = kpi.valor;
        cartoes[i].querySelector('.tag').className = 'tag tag-' + kpi.cor;
    });

    // Gráfico por hora: guarda os dados novos e desenha de novo
    const grafico = document.querySelector('[data-grafico]');
    if (grafico) {
        grafico.dataset.grafico = JSON.stringify(dados.grafico);
        grafico.textContent = '';
        desenharGrafico(grafico);
    }

    const legenda = document.getElementById('t-hora');
    const textoDia = legenda && legenda.parentElement.querySelector('p');
    if (textoDia && dados.dia) {
        textoDia.textContent = 'Dia do último pulso registrado: ' + dados.dia;
    }

    trocarItens('t-meta', dados.maquinas.map(criarItemMaquina));
    trocarItens('t-paradas', dados.paradas.map(criarItemParada));
}

function trocarItens(idTitulo, itens) {
    const titulo = document.getElementById(idTitulo);
    const lista = titulo && titulo.closest('section').querySelector('ul.metas');
    if (lista) {
        lista.replaceChildren(...itens);
    }
}

function criarElemento(tag, classe, texto) {
    const elemento = document.createElement(tag);
    if (classe) {
        elemento.className = classe;
    }
    if (texto !== undefined) {
        elemento.textContent = texto;
    }
    return elemento;
}

/** Uma linha de "Disponibilidade vs. Meta" (mesma estrutura do dashboard.php). */
function criarItemMaquina(maquina) {
    const nome = criarElemento('span', '', maquina.nome + ' ');
    nome.appendChild(criarElemento('small', '', '(meta ' + maquina.meta_texto + ')'));

    const topo = criarElemento('div', 'meta-top');
    topo.append(nome, criarElemento('strong', '', maquina.pct_texto));

    const barra = criarElemento('div', 'progress');
    barra.setAttribute('role', 'progressbar');
    barra.setAttribute('aria-valuenow', maquina.largura);
    barra.setAttribute('aria-valuemin', 0);
    barra.setAttribute('aria-valuemax', 100);

    // style.width pelo JavaScript é permitido mesmo com CSP; style="..." no HTML não é
    const preenchimento = criarElemento('span', 'bar bar-' + maquina.classe);
    preenchimento.style.width = maquina.largura + '%';
    const marcaMeta = criarElemento('span', 'meta-mark');
    marcaMeta.style.left = maquina.meta_pos + '%';
    barra.append(preenchimento, marcaMeta);

    const item = document.createElement('li');
    item.append(topo, barra);
    return item;
}

/** Uma linha de "Tempo Parado por Motivo". */
function criarItemParada(parada) {
    const topo = criarElemento('div', 'meta-top');
    topo.append(criarElemento('span', '', parada.motivo), criarElemento('strong', '', parada.texto));

    const barra = criarElemento('div', 'progress');
    barra.setAttribute('aria-hidden', 'true');
    const preenchimento = criarElemento('span', 'bar bar-warn');
    preenchimento.style.width = parada.largura + '%';
    barra.appendChild(preenchimento);

    const item = document.createElement('li');
    item.append(topo, barra);
    return item;
}

/** Painel lateral (somente leitura) com os dados da linha clicada. */
function iniciarDetalheParadas() {
    const painel = document.getElementById('detalhe-parada');
    if (!painel) {
        return;
    }

    const linhas = document.querySelectorAll('.tabela-clicavel tbody tr');
    const link = document.getElementById('detalhe-link-maquina');

    function abrir(linha) {
        linhas.forEach(function (l) { l.classList.remove('is-selected'); });
        linha.classList.add('is-selected');

        painel.querySelectorAll('[data-campo]').forEach(function (campo) {
            campo.textContent = linha.dataset[campo.dataset.campo];
        });
        link.href = 'maquina.php?id=' + encodeURIComponent(linha.dataset.maquinaId);

        // Formulário "Corrigir motivo" (só existe para a Manutenção e o admin):
        // leva a identificação da parada clicada. O servidor confere tudo de novo.
        const formMotivo = document.getElementById('form-motivo');
        if (formMotivo) {
            formMotivo.elements.maquina_id.value = linha.dataset.maquinaId;
            formMotivo.elements.motivo_atual.value = linha.dataset.motivoId;
            formMotivo.elements.inicio.value = linha.dataset.inicioBruto;
            formMotivo.elements.fim.value = linha.dataset.fimBruto;
            formMotivo.elements.motivo_novo.value = linha.dataset.motivoId;
        }

        painel.hidden = false;
        painel.parentElement.classList.add('com-detalhe');
    }

    linhas.forEach(function (linha) {
        linha.addEventListener('click', function () { abrir(linha); });
        linha.addEventListener('keydown', function (ev) {
            if (ev.key === 'Enter' || ev.key === ' ') {
                ev.preventDefault();
                abrir(linha);
            }
        });
    });

    document.getElementById('fechar-detalhe').addEventListener('click', function () {
        painel.hidden = true;
        painel.parentElement.classList.remove('com-detalhe');
        linhas.forEach(function (l) { l.classList.remove('is-selected'); });
    });
}

/**
 * Desenha um gráfico de linha em SVG (eixo Y de 0 a 100%).
 * O elemento recebe em data-grafico um JSON no formato:
 * { rotulos: [...], series: [{ nome, valores: [...] }], meta: 85 | null }
 * Valores null deixam um buraco na linha.
 */
function desenharGrafico(container) {
    const dados = JSON.parse(container.dataset.grafico);
    const NS = 'http://www.w3.org/2000/svg';

    // largura do desenho = largura real do container, para o texto não escalar
    const L = Math.max(320, Math.round(container.clientWidth) || 640), A = 230;
    const esq = 40, dir = 14, topo = 12, base = 28;
    const largura = L - esq - dir;
    const altura = A - topo - base;
    const n = dados.rotulos.length;

    const x = function (i) { return esq + (n === 1 ? largura / 2 : i * largura / (n - 1)); };
    const y = function (v) { return topo + altura - (v / 100) * altura; };

    function criar(tag, attrs, pai) {
        const el = document.createElementNS(NS, tag);
        Object.keys(attrs).forEach(function (k) { el.setAttribute(k, attrs[k]); });
        pai.appendChild(el);
        return el;
    }

    const svg = criar('svg', { viewBox: '0 0 ' + L + ' ' + A, role: 'img' }, container);
    if (container.dataset.titulo) {
        svg.setAttribute('aria-label', container.dataset.titulo);
    }

    // Grade horizontal e rótulos do eixo Y
    [0, 25, 50, 75, 100].forEach(function (v) {
        criar('line', { x1: esq, x2: L - dir, y1: y(v), y2: y(v), class: 'grid' }, svg);
        criar('text', { x: esq - 8, y: y(v) + 3, 'text-anchor': 'end', class: 'axis' }, svg).textContent = v + '%';
    });

    // Rótulos do eixo X (no máximo ~8 para não encavalar)
    const passo = Math.max(1, Math.ceil(n / 8));
    dados.rotulos.forEach(function (rotulo, i) {
        if (i % passo === 0 || i === n - 1) {
            criar('text', { x: x(i), y: A - 8, 'text-anchor': 'middle', class: 'axis' }, svg).textContent = rotulo;
        }
    });

    // Linha da meta
    if (dados.meta !== null && dados.meta !== undefined) {
        criar('line', { x1: esq, x2: L - dir, y1: y(dados.meta), y2: y(dados.meta), class: 'meta-line' }, svg);
    }

    const tooltip = document.createElement('div');
    tooltip.className = 'tooltip';
    tooltip.hidden = true;
    container.appendChild(tooltip);

    dados.series.forEach(function (serie, s) {
        // Quebra a linha em trechos contínuos (sem valores nulos)
        let trecho = [];
        const trechos = [trecho];
        serie.valores.forEach(function (v, i) {
            if (v === null) {
                trecho = [];
                trechos.push(trecho);
            } else {
                trecho.push(x(i) + ',' + y(v));
            }
        });
        trechos.forEach(function (pontos) {
            if (pontos.length > 1) {
                criar('polyline', { points: pontos.join(' '), class: 'line serie-' + (s % 6) }, svg);
            }
        });

        serie.valores.forEach(function (v, i) {
            if (v === null) return;

            const ponto = criar('circle', { cx: x(i), cy: y(v), r: 3.5, class: 'ponto serie-' + (s % 6) }, svg);

            ponto.addEventListener('mouseenter', function () {
                tooltip.textContent = '';
                const rotulo = document.createElement('span');
                rotulo.textContent = dados.rotulos[i] + (dados.series.length > 1 ? ' · ' + serie.nome : '');
                const valor = document.createElement('strong');
                valor.textContent = v.toLocaleString('pt-BR', { minimumFractionDigits: 1, maximumFractionDigits: 1 }) + '%';
                tooltip.append(rotulo, valor);

                if (dados.meta !== null && dados.meta !== undefined) {
                    const status = document.createElement('em');
                    status.className = v >= dados.meta ? 'ok' : 'abaixo';
                    status.textContent = v >= dados.meta ? 'Dentro da meta' : 'Abaixo da meta';
                    tooltip.append(status);
                }

                // coordenadas do SVG -> pixels reais (o SVG escala com a largura)
                const escala = svg.clientWidth / L;
                tooltip.style.left = (x(i) * escala) + 'px';
                tooltip.style.top = (y(v) * escala) + 'px';
                tooltip.hidden = false;
                ponto.classList.add('is-active');
            });

            ponto.addEventListener('mouseleave', function () {
                tooltip.hidden = true;
                ponto.classList.remove('is-active');
            });
        });
    });
}
