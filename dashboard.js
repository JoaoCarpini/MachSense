document.addEventListener('DOMContentLoaded', function () {
    // Relógio da planta
    const relogio = document.getElementById('plant-time');
    if (relogio) {
        setInterval(function () {
            relogio.textContent = new Date().toLocaleTimeString('pt-BR', { hour12: false });
        }, 1000);
    }

    document.querySelectorAll('[data-grafico]').forEach(desenharGrafico);

    iniciarDetalheParadas();
});

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

    const L = 640, A = 230;
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
                criar('polyline', { points: pontos.join(' '), class: 'line serie-' + s }, svg);
            }
        });

        serie.valores.forEach(function (v, i) {
            if (v === null) return;

            const ponto = criar('circle', { cx: x(i), cy: y(v), r: 3.5, class: 'ponto serie-' + s }, svg);

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
