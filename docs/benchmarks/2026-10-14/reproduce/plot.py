"""Regenerate the MVO-only chart from published CSV: requires matplotlib."""
from pathlib import Path
import csv
import json
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
from matplotlib.font_manager import FontProperties

DIRECTORY = Path(__file__).resolve().parents[1]
with (DIRECTORY / "process-samples.csv").open(encoding="utf-8", newline="") as source:
    rows = [{key: value if key == "phase" else float(value) for key, value in row.items()}
            for row in csv.DictReader(source)]
font_file = DIRECTORY.parents[1] / "assets/fonts/manrope-400.ttf"
if font_file.is_file():
    from matplotlib import font_manager
    for weight in (400, 600, 800):
        font_manager.fontManager.addfont(str(font_file.with_name(f"manrope-{weight}.ttf")))
    plt.rcParams["font.family"] = FontProperties(fname=font_file).get_name()
summary = json.loads((DIRECTORY / "summary.json").read_text(encoding="utf-8"))
window = summary["sampling"]["steady_process_window"]
plt.rcParams.update({"svg.fonttype": "path", "svg.hashsalt": "mvo-2026-10-14", "font.size": 13})
bg, ink, muted, line, blue = "#111923", "#f5f8fc", "#b4c1d0", "#293441", "#64adff"
fig, axes = plt.subplots(2, 1, figsize=(10.8, 6.8), sharex=True, facecolor=bg)
fig.subplots_adjust(left=.105, right=.97, top=.85, bottom=.20, hspace=.43)
fig.text(.105, .945, "Uma sessão MVO · Windows / PHP 8.4.25", color=ink, fontsize=17, weight=600)
fig.text(.105, .9, "14/10/2026 · sincronização e tráfego observado · processo PHP completo", color=muted, fontsize=12)
x = [row["elapsed_seconds"] for row in rows]
for ax in axes:
    ax.set_facecolor(bg)
    ax.tick_params(colors=muted, labelsize=12, length=0, pad=8)
    for spine in ax.spines.values():
        spine.set_color(line)
    ax.spines[["top", "right"]].set_visible(False)
    ax.grid(axis="y", color=line, linewidth=.7)
    ax.axvspan(window["from_seconds"], window["to_seconds"], color=blue, alpha=.08, linewidth=0)
    ax.set_xlim(0, 83)
axes[0].plot(x, [row["working_set_bytes"] / 1048576 for row in rows], color=blue, linewidth=2.1, label="RAM residente (working set)")
axes[0].plot(x, [row["private_bytes"] / 1048576 for row in rows], color="#b6c5d8", linewidth=1.7, linestyle="--", label="Commit privado")
axes[0].set_ylabel("Memória (MiB)", color=muted, labelpad=12)
axes[0].set_ylim(0, 50)
axes[0].legend(loc="lower right", facecolor=bg, edgecolor=line, labelcolor=ink, fontsize=11)
axes[0].text(.985, .92, "Faixa: janela OS analisada (45,305 s)", color=muted, fontsize=10, ha="right", transform=axes[0].transAxes)
cpu = [100 * (b["cpu_seconds"] - a["cpu_seconds"]) / (b["elapsed_seconds"] - a["elapsed_seconds"])
       for a, b in zip(rows, rows[1:])]
axes[1].step(x[1:], cpu, where="pre", color=blue, linewidth=1.6)
axes[1].set_ylabel("CPU (% de 1 núcleo)", color=muted, labelpad=12)
axes[1].set_ylim(0, 65)
axes[1].set_xlabel("Segundos no relógio do coletor Windows", color=muted, labelpad=12)
fig.text(.105, .035, "CPU = média por intervalo de ~1 s. Somente MVO foi medida; sem comparação com concorrentes.", color=muted, fontsize=10)
fig.savefig(DIRECTORY / "resource-usage.svg", facecolor=bg, metadata={"Date": "2026-10-14", "Creator": "MVO benchmark plot.py / matplotlib"})
plt.close(fig)
print("Generated resource-usage.svg from process-samples.csv")
