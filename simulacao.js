// simulacao.js — atualiza a página simulacao.php sozinha, a cada 3 segundos,
// sem recarregar (o que muda: estado, contagem de pulsos, registro e botões).
// Tudo é escrito com textContent, nunca com innerHTML.

document.addEventListener('DOMContentLoaded', function () {
    const painel = document.getElementById('painel-simulacao');
    if (!painel) {
        return;
    }

    const elementos = {
        api: document.getElementById('sim-api'),
        estado: document.getElementById('sim-estado'),
        pulsos: document.getElementById('sim-pulsos'),
        ultimo: document.getElementById('sim-ultimo'),
        erroApi: document.getElementById('sim-api-erro'),
        saida: document.getElementById('sim-saida'),
        log: document.getElementById('sim-log'),
        iniciarDashboard: document.getElementById('botao-iniciar-dashboard'),
        iniciar: document.getElementById('botao-iniciar'),
        parar: document.getElementById('botao-parar')
    };
    let emAndamento = false;

    function aplicar(dados) {
        elementos.api.className = 'selo ' + (dados.api_ok ? 'selo-ok' : 'selo-parada');
        elementos.api.textContent = dados.api_ok ? 'Conectada' : 'Fora do ar';

        elementos.estado.className = 'selo ' + (dados.rodando ? 'selo-ok' : 'selo-neutro');
        elementos.estado.textContent = dados.rodando
            ? 'Rodando a ' + dados.fator + 'x desde ' + dados.iniciada_em
            : 'Parada';

        elementos.pulsos.textContent = dados.pulsos_total;
        elementos.ultimo.textContent = dados.ultimo_pulso ? 'último: ' + dados.ultimo_pulso : 'nenhum ainda';

        elementos.erroApi.hidden = dados.api_ok;
        elementos.erroApi.textContent = dados.api_erro || '';

        const terminouSozinho = dados.api_ok && !dados.rodando && dados.codigo_saida !== null;
        elementos.saida.hidden = !terminouSozinho;
        elementos.saida.textContent = 'O simulador terminou sozinho (código ' + dados.codigo_saida
            + '). Veja o registro abaixo.';

        elementos.log.textContent = dados.log.length > 0 ? dados.log.join('\n') : 'Nenhuma saída ainda.';

        elementos.iniciarDashboard.disabled = dados.rodando;
        elementos.iniciar.disabled = dados.rodando;
        elementos.parar.disabled = !dados.rodando;
    }

    async function atualizar() {
        if (document.hidden || emAndamento) {
            return;
        }
        emAndamento = true;
        try {
            const resposta = await fetch('simulacao.php?estado=1', {
                credentials: 'same-origin',
                cache: 'no-store',
                headers: { Accept: 'application/json' }
            });
            const tipo = resposta.headers.get('content-type') || '';

            // Sessão expirada (volta a tela de login) ou recurso desligado: recarrega
            if (resposta.redirected || !tipo.includes('application/json')) {
                window.location.reload();
                return;
            }
            const dados = await resposta.json();
            if (dados.desabilitada) {
                window.location.reload();
                return;
            }
            aplicar(dados);
        } catch (erro) {
            // sem rede por um instante: tenta de novo no próximo ciclo
        } finally {
            emAndamento = false;
        }
    }

    setInterval(atualizar, 3000);
});
