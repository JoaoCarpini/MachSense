document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('loginForm');
    const botao = document.getElementById('btnAcessar');

    if (!form || !botao) {
        return;
    }

    form.addEventListener('submit', function () {
        botao.disabled = true;
        botao.textContent = 'ACESSANDO...';
    });
});
