/*
 * Camada de dados do MachSense (provisória).
 *
 * Enquanto o backend em PHP não existe, usuários e sessão ficam no
 * localStorage/sessionStorage do navegador. Todas as operações retornam
 * Promise e lançam Error com mensagem em português, para que a troca por
 * chamadas fetch() ao PHP não altere o script.js.
 */

const MachSenseStore = (function () {
    const CHAVE = 'machsense.usuarios';
    const ITERACOES = 100000;
    const SENHA_MIN = 6;

    function ler() {
        try {
            const lista = JSON.parse(localStorage.getItem(CHAVE));
            return Array.isArray(lista) ? lista : [];
        } catch (e) {
            return [];
        }
    }

    function gravar(lista) {
        localStorage.setItem(CHAVE, JSON.stringify(lista));
    }

    // Nunca devolve senha nem salt para a interface.
    function publico(u) {
        return {
            id: u.id,
            nome: u.nome,
            usuario: u.usuario,
            criadoEm: u.criadoEm,
            atualizadoEm: u.atualizadoEm
        };
    }

    function paraBase64(buffer) {
        return btoa(String.fromCharCode(...new Uint8Array(buffer)));
    }

    function deBase64(texto) {
        return Uint8Array.from(atob(texto), function (c) { return c.charCodeAt(0); });
    }

    async function derivar(senha, salt) {
        if (!window.crypto || !crypto.subtle) {
            throw new Error('Este navegador não suporta criptografia de senha. Abra o sistema por localhost ou https.');
        }

        const chave = await crypto.subtle.importKey(
            'raw', new TextEncoder().encode(senha), 'PBKDF2', false, ['deriveBits']
        );
        const bits = await crypto.subtle.deriveBits(
            { name: 'PBKDF2', hash: 'SHA-256', salt: salt, iterations: ITERACOES }, chave, 256
        );

        return paraBase64(bits);
    }

    async function gerarCredencial(senha) {
        const salt = crypto.getRandomValues(new Uint8Array(16));

        return { salt: paraBase64(salt), senha: await derivar(senha, salt) };
    }

    function validar(dados, lista, idAtual, senhaObrigatoria) {
        const nome = String(dados.nome || '').trim();
        const usuario = String(dados.usuario || '').trim().toLowerCase();
        const senha = String(dados.senha || '');

        if (nome === '') {
            throw new Error('Informe o nome.');
        }
        if (usuario === '') {
            throw new Error('Informe o usuário.');
        }
        if (/\s/.test(usuario)) {
            throw new Error('O usuário não pode conter espaços.');
        }
        if (lista.some(function (u) { return u.usuario === usuario && u.id !== idAtual; })) {
            throw new Error('Já existe um usuário com esse login.');
        }
        if ((senhaObrigatoria || senha !== '') && senha.length < SENHA_MIN) {
            throw new Error('A senha deve ter no mínimo ' + SENHA_MIN + ' caracteres.');
        }

        return { nome: nome, usuario: usuario, senha: senha };
    }

    async function inserir(nome, usuario, senha) {
        const credencial = await gerarCredencial(senha);
        const lista = ler();
        const agora = new Date().toISOString();
        const proximoId = lista.reduce(function (max, u) { return Math.max(max, u.id); }, 0) + 1;

        const novo = {
            id: proximoId,
            nome: nome,
            usuario: usuario,
            senha: credencial.senha,
            salt: credencial.salt,
            criadoEm: agora,
            atualizadoEm: agora
        };

        lista.push(novo);
        gravar(lista);

        return publico(novo);
    }

    /** Gera o admin (admin / admin) quando a base está vazia. */
    async function iniciar() {
        if (ler().length === 0) {
            await inserir('Administrador', 'admin', 'admin');
        }
    }

    async function listar() {
        return ler().map(publico);
    }

    async function buscar(id) {
        const u = ler().find(function (item) { return item.id === id; });

        return u ? publico(u) : null;
    }

    async function criar(dados) {
        const limpo = validar(dados, ler(), null, true);

        return inserir(limpo.nome, limpo.usuario, limpo.senha);
    }

    /** Senha em branco mantém a senha atual. */
    async function atualizar(id, dados) {
        const limpo = validar(dados, ler(), id, false);
        const credencial = limpo.senha !== '' ? await gerarCredencial(limpo.senha) : null;

        const lista = ler();
        const alvo = lista.find(function (u) { return u.id === id; });

        if (!alvo) {
            throw new Error('Usuário não encontrado.');
        }

        alvo.nome = limpo.nome;
        alvo.usuario = limpo.usuario;
        if (credencial) {
            alvo.senha = credencial.senha;
            alvo.salt = credencial.salt;
        }
        alvo.atualizadoEm = new Date().toISOString();

        gravar(lista);

        return publico(alvo);
    }

    async function remover(id) {
        const lista = ler();
        const restante = lista.filter(function (u) { return u.id !== id; });

        if (restante.length === lista.length) {
            throw new Error('Usuário não encontrado.');
        }

        gravar(restante);
    }

    /** Devolve o usuário se as credenciais conferem; senão, null. */
    async function autenticar(usuario, senha) {
        const login = String(usuario).trim().toLowerCase();
        const alvo = ler().find(function (u) { return u.usuario === login; });

        if (!alvo) {
            return null;
        }

        const hash = await derivar(senha, deBase64(alvo.salt));

        return hash === alvo.senha ? publico(alvo) : null;
    }

    return {
        iniciar: iniciar,
        listar: listar,
        buscar: buscar,
        criar: criar,
        atualizar: atualizar,
        remover: remover,
        autenticar: autenticar
    };
})();

/*
 * Sessão do usuário logado. "Manter conectado" grava no localStorage
 * (sobrevive ao fechar o navegador); sem ele, no sessionStorage.
 */
const MachSenseSessao = (function () {
    const CHAVE = 'machsense.sessao';

    function ler(armazenamento) {
        try {
            return JSON.parse(armazenamento.getItem(CHAVE));
        } catch (e) {
            return null;
        }
    }

    function encerrar() {
        sessionStorage.removeItem(CHAVE);
        localStorage.removeItem(CHAVE);
    }

    function iniciar(usuarioId, lembrar) {
        encerrar();
        (lembrar ? localStorage : sessionStorage).setItem(CHAVE, JSON.stringify({ usuarioId: usuarioId }));
    }

    function atual() {
        return ler(sessionStorage) || ler(localStorage);
    }

    return { iniciar: iniciar, atual: atual, encerrar: encerrar };
})();
