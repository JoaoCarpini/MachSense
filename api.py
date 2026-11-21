"""
MachSense - API (peça 2)

Recebe os pulsos do simulador, grava no MariaDB (tabela pulsos) e calcula a
disponibilidade de cada máquina (quanto tempo produziu, quanto tempo ficou
parada e por quê). É essa gravação que alimenta o site em PHP.

Autor: Guilherme Somera Barboza - RA 118763

Como rodar:
    pip install -r requirements.txt
    (crie o arquivo .env.local a partir do .env.example)
    uvicorn api:app --host 127.0.0.1 --port 8000

Documentação interativa (dá pra testar os endpoints clicando):
    http://127.0.0.1:8000/docs
"""

import logging
import os
import secrets
from collections import namedtuple
from contextlib import asynccontextmanager, contextmanager
from datetime import datetime
from pathlib import Path
from typing import Literal, Optional

import pymysql
import pymysql.cursors
from dotenv import load_dotenv
from fastapi import Depends, FastAPI, HTTPException, Security
from fastapi.responses import JSONResponse
from fastapi.security import APIKeyHeader
from pydantic import BaseModel, model_validator

# As configurações (acesso ao banco e chave da API) ficam em .env.local, que
# NÃO vai para o Git. Variáveis já definidas no ambiente têm prioridade.
load_dotenv(Path(__file__).resolve().parent / ".env.local")

MOTIVOS = ["almoco", "furo_processo", "setup", "manutencao"]

# Uma chave curta ou o texto de exemplo do .env.example seriam adivinhados
# facilmente (o repositório é público), então a API se recusa a subir com elas.
TAMANHO_MINIMO_CHAVE = 16

log = logging.getLogger("machsense.api")
if not log.handlers:
    _saida = logging.StreamHandler()
    _saida.setFormatter(logging.Formatter("%(asctime)s %(levelname)s %(message)s"))
    log.addHandler(_saida)
    log.setLevel(logging.INFO)
    log.propagate = False

# Preenchida na subida da API (ver ciclo_de_vida)
_chave_api: str = ""

# Cache dos motivos de parada: {"setup": 3, ...}. Evita um SELECT por pulso.
_motivos_cache: dict = {}


# ----------------------------------------------------------------------------
# BANCO DE DADOS
# ----------------------------------------------------------------------------
class BancoIndisponivel(Exception):
    """O MariaDB não respondeu (desligado, reiniciando, rede...)."""


def configuracao_banco() -> dict:
    """Lê as variáveis de ambiente a cada conexão (sem senha escrita no código)."""
    return {
        "host": os.getenv("MACHSENSE_DB_HOST", "127.0.0.1"),
        "port": int(os.getenv("MACHSENSE_DB_PORT", "3306")),
        "database": os.getenv("MACHSENSE_DB_NAME", "machsense"),
        "user": os.getenv("MACHSENSE_DB_USER", "root"),
        "password": os.getenv("MACHSENSE_DB_PASSWORD", ""),
        "charset": "utf8mb4",
        "connect_timeout": 3,
        "read_timeout": 10,
        "write_timeout": 10,
        "autocommit": True,
        "cursorclass": pymysql.cursors.DictCursor,
    }


@contextmanager
def conexao():
    """
    Abre uma conexão nova para cada requisição e fecha no final.

    Por quê: os pulsos chegam poucos por segundo, então o custo de conectar é
    irrelevante, e assim a API nunca fica presa a uma conexão morta. Se o banco
    cair e voltar, a próxima requisição simplesmente conecta de novo.
    """
    try:
        con = pymysql.connect(**configuracao_banco())
    except pymysql.MySQLError as erro:
        # O detalhe (que pode citar usuário e host) vai só para o log do servidor
        log.error("Não foi possível conectar ao MariaDB: %s", erro)
        raise BancoIndisponivel() from erro

    try:
        yield con
    except (pymysql.err.OperationalError, pymysql.err.InterfaceError) as erro:
        # Conexão perdida no meio da consulta (o banco caiu durante a requisição)
        log.error("Conexão com o MariaDB perdida durante a consulta: %s", erro)
        raise BancoIndisponivel() from erro
    finally:
        try:
            con.close()
        except pymysql.MySQLError:
            pass


def carregar_motivos(con) -> dict:
    """Lê motivos_parada e atualiza o cache em memória."""
    global _motivos_cache
    with con.cursor() as cur:
        cur.execute("SELECT id, descricao FROM motivos_parada")
        _motivos_cache = {linha["descricao"]: linha["id"] for linha in cur.fetchall()}
    return _motivos_cache


def obter_id_motivo(con, motivo: str) -> int:
    """Traduz o texto do motivo (ex.: 'setup') para o id de motivos_parada."""
    if motivo not in _motivos_cache:
        # Cache vazio (o banco estava fora do ar na subida) ou motivo novo: relê uma vez
        carregar_motivos(con)
    if motivo not in _motivos_cache:
        raise HTTPException(
            status_code=422, detail=f"O motivo '{motivo}' não está cadastrado em motivos_parada."
        )
    return _motivos_cache[motivo]


# ----------------------------------------------------------------------------
# SEGURANÇA
# ----------------------------------------------------------------------------
cabecalho_chave = APIKeyHeader(name="X-API-Key", auto_error=False)


def exigir_chave(chave: Optional[str] = Security(cabecalho_chave)):
    """Só quem conhece a chave (o simulador) pode gravar pulsos."""
    # compare_digest compara em tempo constante: não revela, pelo tempo de
    # resposta, quantos caracteres da chave estavam certos.
    if chave is None or not secrets.compare_digest(chave.encode("utf-8"), _chave_api.encode("utf-8")):
        raise HTTPException(
            status_code=401,
            detail="Chave de API ausente ou inválida (cabeçalho X-API-Key).",
        )


@asynccontextmanager
async def ciclo_de_vida(app: FastAPI):
    """Roda na subida da API: confere a chave e aquece o cache de motivos."""
    global _chave_api

    chave = os.getenv("MACHSENSE_API_KEY", "")
    if len(chave) < TAMANHO_MINIMO_CHAVE:
        # Falha clara em vez de subir "aberta por engano"
        raise RuntimeError(
            "MACHSENSE_API_KEY não definida (ou curta demais, mínimo "
            f"{TAMANHO_MINIMO_CHAVE} caracteres). Crie o arquivo .env.local a partir do "
            ".env.example e defina uma chave longa e aleatória."
        )
    _chave_api = chave

    try:
        with conexao() as con:
            motivos = carregar_motivos(con)
        log.info("API pronta. Motivos de parada carregados: %s", ", ".join(sorted(motivos)))
    except BancoIndisponivel:
        # Não derruba a API: o cache é recarregado quando o banco voltar
        log.warning("MariaDB fora do ar na subida. A API responde 503 até ele voltar.")

    yield


app = FastAPI(title="MachSense API", lifespan=ciclo_de_vida)
# Sem CORS de propósito: só o simulador (que não é um navegador) chama a API.


@app.exception_handler(BancoIndisponivel)
async def tratar_banco_indisponivel(request, erro):
    # Mensagem genérica para quem chamou; o detalhe já foi para o log
    return JSONResponse(
        status_code=503,
        content={"detail": "Banco de dados indisponível no momento. Tente novamente em instantes."},
    )


# ----------------------------------------------------------------------------
# MODELO DO PULSO
# ----------------------------------------------------------------------------
class Pulso(BaseModel):
    """O formato exato que o simulador manda. Se vier diferente, a API recusa (erro 422)."""

    pulse_id: int
    machine_id: int
    status: Literal["produzindo", "parado"]
    motivo: Optional[Literal["almoco", "furo_processo", "setup", "manutencao"]] = None
    timestamp: datetime  # horário SIMULADO, não o horário real

    @model_validator(mode="after")
    def motivo_combina_com_status(self):
        # Produzindo não tem motivo; parado precisa ter.
        if self.status == "produzindo" and self.motivo is not None:
            raise ValueError("motivo deve ser null quando status é 'produzindo'")
        if self.status == "parado" and self.motivo is None:
            raise ValueError("motivo é obrigatório quando status é 'parado'")
        return self


# Nomes do banco x nomes do simulador (o banco diz 'rodando', o simulador 'produzindo')
ESTADO_DO_BANCO = {"produzindo": "rodando", "parado": "parado"}
STATUS_DA_API = {"rodando": "produzindo", "parado": "parado"}

# Um pulso lido do banco, já com os nomes da API (só o que o cálculo precisa)
PulsoLido = namedtuple("PulsoLido", ["timestamp", "status", "motivo"])


# ----------------------------------------------------------------------------
# CÁLCULO DO RESUMO
# ----------------------------------------------------------------------------
def calcular_resumo(pulsos: list) -> dict:
    """
    Cada intervalo entre dois pulsos seguidos é creditado ao estado do pulso
    que ABRE o intervalo.

    Exemplo: pulso às 06:00 'produzindo' e pulso às 06:05 'parado'
    -> os 5 minutos entre os dois contam como produzindo.
    O último pulso não abre intervalo nenhum (ainda não sabemos quanto tempo vai durar).
    """
    ordenados = sorted(pulsos, key=lambda p: p.timestamp)

    produzindo_min = 0.0
    parado_por_motivo = {motivo: 0.0 for motivo in MOTIVOS}

    for atual, proximo in zip(ordenados, ordenados[1:]):
        minutos = (proximo.timestamp - atual.timestamp).total_seconds() / 60
        if atual.status == "produzindo":
            produzindo_min += minutos
        else:
            parado_por_motivo[atual.motivo] += minutos

    parado_min = sum(parado_por_motivo.values())
    total = produzindo_min + parado_min

    return {
        "tempo_produzindo_min": round(produzindo_min, 1),
        "tempo_parado_min": round(parado_min, 1),
        "disponibilidade_pct": round(produzindo_min / total * 100, 2) if total > 0 else None,
        "parado_por_motivo": {m: round(v, 1) for m, v in parado_por_motivo.items()},
    }


def ler_pulsos(con, machine_id: Optional[int] = None) -> dict:
    """Lê os pulsos do banco e devolve {machine_id: [PulsoLido, ...]}."""
    sql = (
        "SELECT p.maquina_id, p.`timestamp`, p.estado, mp.descricao AS motivo "
        "FROM pulsos p LEFT JOIN motivos_parada mp ON mp.id = p.motivo_id "
    )
    parametros: tuple = ()
    if machine_id is not None:
        sql += "WHERE p.maquina_id = %s "
        parametros = (machine_id,)
    sql += "ORDER BY p.maquina_id, p.`timestamp`"

    por_maquina: dict = {}
    # SSDictCursor lê linha a linha, sem carregar a tabela inteira na memória
    with con.cursor(pymysql.cursors.SSDictCursor) as cur:
        cur.execute(sql, parametros)
        for linha in cur:
            por_maquina.setdefault(linha["maquina_id"], []).append(
                PulsoLido(linha["timestamp"], STATUS_DA_API[linha["estado"]], linha["motivo"])
            )
    return por_maquina


# ----------------------------------------------------------------------------
# ENDPOINTS
# ----------------------------------------------------------------------------
@app.get("/saude")
def saude():
    """Confere se a API está de pé e se o banco responde."""
    with conexao() as con:
        with con.cursor() as cur:
            cur.execute("SELECT 1 AS ok")
            cur.fetchone()
    return {"status": "ok", "banco": "ok"}


@app.post("/status", dependencies=[Depends(exigir_chave)])
def receber_pulso(pulso: Pulso):
    """Valida o pulso e grava em pulsos. O cálculo fica nos endpoints de resumo."""
    estado = ESTADO_DO_BANCO[pulso.status]
    # O banco não guarda fração de segundo; o simulador já manda horários exatos
    horario = pulso.timestamp.replace(tzinfo=None, microsecond=0)

    with conexao() as con:
        with con.cursor() as cur:
            cur.execute("SELECT 1 FROM maquinas WHERE id = %s", (pulso.machine_id,))
            if cur.fetchone() is None:
                raise HTTPException(
                    status_code=404,
                    detail=f"A máquina {pulso.machine_id} não existe. Cadastre-a no site antes.",
                )

            motivo_id = obter_id_motivo(con, pulso.motivo) if pulso.motivo else None

            try:
                # Sempre parametrizado (%s): nada do pulso entra no texto do SQL
                cur.execute(
                    "INSERT INTO pulsos (maquina_id, motivo_id, `timestamp`, estado) "
                    "VALUES (%s, %s, %s, %s)",
                    (pulso.machine_id, motivo_id, horario, estado),
                )
            except pymysql.err.IntegrityError as erro:
                codigo = erro.args[0] if erro.args else None
                if codigo == 1062:
                    # Mesma máquina no mesmo horário (UNIQUE): pulso repetido, não é erro
                    return {"ok": True, "duplicado": True}
                if codigo == 1452:
                    # A máquina foi apagada entre o SELECT e o INSERT
                    raise HTTPException(
                        status_code=404, detail=f"A máquina {pulso.machine_id} não existe."
                    )
                raise

    return {"ok": True, "duplicado": False}


@app.get("/maquinas")
def listar_maquinas():
    """
    Máquinas cadastradas e o último pulso de cada uma. O simulador usa isto para
    saber quais máquinas existem e de onde continuar.
    """
    with conexao() as con:
        with con.cursor() as cur:
            cur.execute(
                "SELECT m.id, m.nome, m.ativa, MAX(p.`timestamp`) AS ultimo_pulso "
                "FROM maquinas m LEFT JOIN pulsos p ON p.maquina_id = m.id "
                "GROUP BY m.id, m.nome, m.ativa ORDER BY m.id"
            )
            linhas = cur.fetchall()
            cur.execute("SELECT valor FROM parametros WHERE chave = 'intervalo_pulso_min'")
            parametro = cur.fetchone()

    return {
        "intervalo_pulso_min": int(parametro["valor"]) if parametro else 5,
        "maquinas": [
            {
                "id": linha["id"],
                "nome": linha["nome"],
                "ativa": bool(linha["ativa"]),
                "ultimo_pulso": linha["ultimo_pulso"].isoformat() if linha["ultimo_pulso"] else None,
            }
            for linha in linhas
        ],
    }


# Esta rota vem ANTES da de {machine_id} só por clareza de leitura.
@app.get("/maquinas/resumo")
def resumo_todas():
    """Resumo de todas as máquinas que já têm pulsos."""
    with conexao() as con:
        por_maquina = ler_pulsos(con)
    return {machine_id: calcular_resumo(pulsos) for machine_id, pulsos in sorted(por_maquina.items())}


@app.get("/maquinas/{machine_id}/resumo")
def resumo_maquina(machine_id: int):
    """Resumo de uma máquina."""
    with conexao() as con:
        por_maquina = ler_pulsos(con, machine_id)
    if machine_id not in por_maquina:
        raise HTTPException(status_code=404, detail=f"Máquina {machine_id} não tem pulsos ainda")
    return calcular_resumo(por_maquina[machine_id])


if __name__ == "__main__":
    # Atalho para rodar com "python api.py". Sobe só em 127.0.0.1 (a própria
    # máquina): a API não deve ficar aberta para a rede.
    import uvicorn

    uvicorn.run(app, host="127.0.0.1", port=int(os.getenv("MACHSENSE_API_PORT", "8000")))
