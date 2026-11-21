"""
Teste de integração da API do MachSense (fora do site).

Sobe a API, manda pulsos conhecidos e confere no MariaDB se foram gravados do
jeito certo. Não mexe nos dados reais: cria uma máquina temporária
("__teste_integracao__") e pulsos em 01/01/2000, e apaga tudo no final.

Antes de rodar:
  - MariaDB ligado, com o schema.sql importado
  - arquivo .env.local criado (banco e MACHSENSE_API_KEY)

Como rodar (na pasta do projeto):
    python tests/teste_integracao.py
"""

import os
import socket
import subprocess
import sys
import time
from datetime import datetime, timedelta
from pathlib import Path

import pymysql
import requests
from dotenv import load_dotenv

RAIZ = Path(__file__).resolve().parent.parent
load_dotenv(RAIZ / ".env.local")

NOME_TESTE = "__teste_integracao__"
INICIO = datetime(2000, 1, 1, 6, 0)  # data fictícia: não aparece junto dos dados reais

resultados = []


def verificar(descricao: str, passou: bool, detalhe: str = ""):
    resultados.append(passou)
    marca = "PASSOU" if passou else "FALHOU"
    print(f"{marca}  {descricao}" + (f"  -> {detalhe}" if detalhe and not passou else ""), flush=True)


def conectar():
    return pymysql.connect(
        host=os.getenv("MACHSENSE_DB_HOST", "127.0.0.1"),
        port=int(os.getenv("MACHSENSE_DB_PORT", "3306")),
        database=os.getenv("MACHSENSE_DB_NAME", "machsense"),
        user=os.getenv("MACHSENSE_DB_USER", "root"),
        password=os.getenv("MACHSENSE_DB_PASSWORD", ""),
        charset="utf8mb4",
        autocommit=True,
        cursorclass=pymysql.cursors.DictCursor,
    )


def porta_livre() -> int:
    with socket.socket() as s:
        s.bind(("127.0.0.1", 0))
        return s.getsockname()[1]


def esperar_api(url: str, processo, tentativas: int = 40):
    for _ in range(tentativas):
        if processo.poll() is not None:
            return False
        try:
            if requests.get(f"{url}/saude", timeout=1).status_code == 200:
                return True
        except requests.exceptions.RequestException:
            pass
        time.sleep(0.25)
    return False


def pulso(maquina_id, minutos, status, motivo=None, numero=1):
    return {
        "pulse_id": numero,
        "machine_id": maquina_id,
        "status": status,
        "motivo": motivo,
        "timestamp": (INICIO + timedelta(minutes=minutos)).isoformat(),
    }


def main() -> int:
    chave = os.getenv("MACHSENSE_API_KEY", "")
    if not chave:
        print("MACHSENSE_API_KEY não definida. Crie o .env.local a partir do .env.example.")
        return 2

    try:
        con = conectar()
    except pymysql.MySQLError as erro:
        print(f"Não consegui conectar ao MariaDB ({erro.args[0] if erro.args else erro}). "
              "Confira o .env.local e se o banco está ligado.")
        return 2

    porta = porta_livre()
    url = f"http://127.0.0.1:{porta}"
    cabecalho = {"X-API-Key": chave}
    maquina_id = None
    api = None

    try:
        with con.cursor() as cur:
            cur.execute("DELETE FROM maquinas WHERE nome = %s", (NOME_TESTE,))  # sobra de teste anterior
            cur.execute("INSERT INTO maquinas (nome, meta_disponibilidade) VALUES (%s, 85)", (NOME_TESTE,))
            maquina_id = cur.lastrowid

        api = subprocess.Popen(
            [sys.executable, "-m", "uvicorn", "api:app", "--host", "127.0.0.1", "--port", str(porta),
             "--log-level", "warning"],
            cwd=RAIZ,
        )
        verificar("a API sobe e /saude responde (banco ok)", esperar_api(url, api))
        if not resultados[-1]:
            return 1

        # Linha do tempo conhecida (1 pulso a cada 5 min):
        #  06:00-06:15 produzindo (4) | 06:20-06:30 parado por setup (3) |
        #  06:35-06:40 produzindo (2) | 06:45-06:50 parado por almoco (2) | 06:55 produzindo (1)
        linha = (
            [pulso(maquina_id, 5 * i, "produzindo", numero=i + 1) for i in range(0, 4)]
            + [pulso(maquina_id, 5 * i, "parado", "setup", numero=i + 1) for i in range(4, 7)]
            + [pulso(maquina_id, 5 * i, "produzindo", numero=i + 1) for i in range(7, 9)]
            + [pulso(maquina_id, 5 * i, "parado", "almoco", numero=i + 1) for i in range(9, 11)]
            + [pulso(maquina_id, 5 * 11, "produzindo", numero=12)]
        )
        respostas = [requests.post(f"{url}/status", json=p, headers=cabecalho, timeout=5) for p in linha]
        verificar("12 pulsos aceitos (HTTP 200, duplicado=false)",
                  all(r.status_code == 200 and r.json() == {"ok": True, "duplicado": False} for r in respostas),
                  str([r.status_code for r in respostas]))

        with con.cursor() as cur:
            cur.execute(
                "SELECT p.estado, mp.descricao AS motivo FROM pulsos p "
                "LEFT JOIN motivos_parada mp ON mp.id = p.motivo_id "
                "WHERE p.maquina_id = %s ORDER BY p.`timestamp`", (maquina_id,))
            gravado = [(l["estado"], l["motivo"]) for l in cur.fetchall()]
        esperado = ([("rodando", None)] * 4 + [("parado", "setup")] * 3 + [("rodando", None)] * 2
                    + [("parado", "almoco")] * 2 + [("rodando", None)])
        verificar("o banco tem os 12 pulsos com estado e motivo traduzidos (produzindo->rodando)",
                  gravado == esperado, str(gravado))

        r = requests.post(f"{url}/status", json=linha[0], headers=cabecalho, timeout=5)
        with con.cursor() as cur:
            cur.execute("SELECT COUNT(*) AS n FROM pulsos WHERE maquina_id = %s", (maquina_id,))
            total = cur.fetchone()["n"]
        verificar("pulso repetido: HTTP 200, duplicado=true e nenhuma linha nova",
                  r.status_code == 200 and r.json().get("duplicado") is True and total == 12, f"{r.text} total={total}")

        r = requests.post(f"{url}/status", json=pulso(999999, 0, "produzindo"), headers=cabecalho, timeout=5)
        verificar("machine_id inexistente -> 404", r.status_code == 404, str(r.status_code))
        r = requests.post(f"{url}/status", json=pulso(maquina_id, 99, "parado"), headers=cabecalho, timeout=5)
        verificar("parado sem motivo -> 422", r.status_code == 422, str(r.status_code))
        r = requests.post(f"{url}/status", json=pulso(maquina_id, 99, "produzindo", "setup"), headers=cabecalho, timeout=5)
        verificar("produzindo com motivo -> 422", r.status_code == 422, str(r.status_code))
        r = requests.post(f"{url}/status", json=linha[0], timeout=5)
        verificar("sem X-API-Key -> 401", r.status_code == 401, str(r.status_code))
        r = requests.post(f"{url}/status", json=linha[0], headers={"X-API-Key": "chave-errada-qualquer"}, timeout=5)
        verificar("X-API-Key errada -> 401", r.status_code == 401, str(r.status_code))

        # Regra do resumo: o intervalo vai para o estado do pulso que o ABRE.
        # produzindo: 06:00->06:20 (20) + 06:35->06:45 (10) + 06:55 (sem intervalo) = 30
        # parado: setup 06:20->06:35 (15) + almoco 06:45->06:55 (10) = 25
        resumo = requests.get(f"{url}/maquinas/{maquina_id}/resumo", timeout=5).json()
        verificar("resumo bate com a regra (30 min produzindo, 25 parado, setup 15, almoco 10, 54,55%)",
                  resumo["tempo_produzindo_min"] == 30 and resumo["tempo_parado_min"] == 25
                  and resumo["parado_por_motivo"]["setup"] == 15 and resumo["parado_por_motivo"]["almoco"] == 10
                  and resumo["disponibilidade_pct"] == 54.55, str(resumo))

        cadastro = requests.get(f"{url}/maquinas", timeout=5).json()
        minha = next((m for m in cadastro["maquinas"] if m["id"] == maquina_id), None)
        verificar("GET /maquinas lista a máquina e o último pulso (06:55)",
                  minha is not None and minha["ultimo_pulso"] == (INICIO + timedelta(minutes=55)).isoformat()
                  and cadastro["intervalo_pulso_min"] == 5, str(minha))
    finally:
        if api is not None:
            api.terminate()
            try:
                api.wait(timeout=5)
            except subprocess.TimeoutExpired:
                api.kill()
        if maquina_id is not None:
            with con.cursor() as cur:
                cur.execute("DELETE FROM pulsos WHERE maquina_id = %s", (maquina_id,))
                cur.execute("DELETE FROM maquinas WHERE id = %s", (maquina_id,))
        con.close()

    faltaram = resultados.count(False)
    print(f"\n{len(resultados)} verificações, {faltaram} falha(s).")
    return 1 if faltaram else 0


if __name__ == "__main__":
    sys.exit(main())
