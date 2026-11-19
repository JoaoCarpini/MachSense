"""
MachSense - API (peça 2)

Recebe os pulsos do simulador, guarda em memória e calcula a disponibilidade
de cada máquina (quanto tempo produziu, quanto tempo ficou parada e por quê).

Autor: Guilherme Somera Barboza - RA 118763

Como rodar:
    pip install -r requirements.txt
    uvicorn api:app --reload --port 8000

Documentação interativa (dá pra testar os endpoints clicando):
    http://localhost:8000/docs
"""

from datetime import datetime
from typing import Literal, Optional

from fastapi import FastAPI, HTTPException
from pydantic import BaseModel, model_validator

app = FastAPI(title="MachSense API")

MOTIVOS = ["almoco", "furo_processo", "setup", "manutencao"]

# MVP: tudo fica em memória. Se reiniciar a API, perde os dados.
# Formato: {machine_id: [pulso, pulso, ...]}
pulsos_por_maquina: dict[int, list] = {}


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


@app.post("/status")
def receber_pulso(pulso: Pulso):
    """Recebe um pulso e guarda. Só isso — o cálculo fica nos endpoints de resumo."""
    pulsos_por_maquina.setdefault(pulso.machine_id, []).append(pulso)
    return {"ok": True}


# Esta rota vem ANTES da de {machine_id} só por clareza de leitura.
@app.get("/maquinas/resumo")
def resumo_todas():
    """Resumo de todas as máquinas de uma vez."""
    return {
        machine_id: calcular_resumo(pulsos)
        for machine_id, pulsos in sorted(pulsos_por_maquina.items())
    }


@app.get("/maquinas/{machine_id}/resumo")
def resumo_maquina(machine_id: int):
    """Resumo de uma máquina."""
    if machine_id not in pulsos_por_maquina:
        raise HTTPException(status_code=404, detail=f"Máquina {machine_id} não tem pulsos ainda")
    return calcular_resumo(pulsos_por_maquina[machine_id])
