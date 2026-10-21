document.addEventListener('DOMContentLoaded', function () {
    // Relógio da planta
    const relogio = document.getElementById('plant-time');
    if (relogio) {
        setInterval(function () {
            relogio.textContent = new Date().toLocaleTimeString('pt-BR', { hour12: false });
        }, 1000);
    }

    // Tooltip do gráfico de produção por hora
    const chart = document.getElementById('chart');
    const tooltip = document.getElementById('tooltip');
    if (!chart || !tooltip) {
        return;
    }

    const svg = chart.querySelector('svg');

    chart.querySelectorAll('.ponto').forEach(function (ponto) {
        ponto.addEventListener('mouseenter', function () {
            const status = ponto.dataset.meta === 'ok' ? 'Dentro da meta' : 'Abaixo da meta';

            tooltip.innerHTML =
                '<span>' + ponto.dataset.rotulo + '</span>' +
                '<strong>' + ponto.dataset.pecas + ' peças</strong>' +
                '<em class="' + ponto.dataset.meta + '">(' + status + ')</em>';
            tooltip.hidden = false;

            // coordenadas do SVG -> pixels reais (o SVG escala com a largura)
            const escala = svg.clientWidth / svg.viewBox.baseVal.width;
            tooltip.style.left = (ponto.dataset.x * escala) + 'px';
            tooltip.style.top = (ponto.dataset.y * escala) + 'px';

            ponto.classList.add('is-active');
        });

        ponto.addEventListener('mouseleave', function () {
            tooltip.hidden = true;
            ponto.classList.remove('is-active');
        });
    });
});
