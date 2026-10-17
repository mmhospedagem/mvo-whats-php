"""Recalculate the published run from CSV only; never connects to WhatsApp.

Usage: python docs/benchmarks/2026-10-14/reproduce/recalculate.py
Standard library only. Preserve original window selection (two clocks whose
offset was not recorded). CPU is process time, not system-wide CPU utilization.
"""
from pathlib import Path
import csv
import hashlib
import json
import statistics

DIRECTORY = Path(__file__).resolve().parents[1]


def samples(name):
    with (DIRECTORY / name).open(encoding="utf-8", newline="") as source:
        return [{key: (None if value == "" else value if key == "phase" else float(value))
                 for key, value in row.items()} for row in csv.DictReader(source)]


def process_window(rows, logical):
    duration = rows[-1]["elapsed_seconds"] - rows[0]["elapsed_seconds"]
    cpu = rows[-1]["cpu_seconds"] - rows[0]["cpu_seconds"]

    def memory(key):
        values = [row[key] / 1048576 for row in rows]
        return {"min_mib": round(min(values), 2),
                "median_mib": round(statistics.median(values), 2),
                "max_mib": round(max(values), 2)}

    interval_cpu = [100 * (last["cpu_seconds"] - first["cpu_seconds"]) /
                    (last["elapsed_seconds"] - first["elapsed_seconds"])
                    for first, last in zip(rows, rows[1:])]
    return {"seconds": round(duration, 3), "samples": len(rows),
            "cpu_seconds": round(cpu, 5), "cpu_percent_one_core": round(100 * cpu / duration, 4),
            "cpu_percent_machine_32_logical": round(100 * cpu / duration / logical, 5),
            "peak_cpu_percent_one_second": round(max(interval_cpu), 3),
            "working_set": memory("working_set_bytes"), "private_commit": memory("private_bytes")}


def calculate():
    saved = json.loads((DIRECTORY / "summary.json").read_text(encoding="utf-8"))
    process = samples("process-samples.csv")
    php = samples("php-samples.csv")
    connected = next(row for row in php if row["phase"] == "connected")["elapsed_seconds"]
    logical = saved["environment"]["logical_processors"]
    initial = [row for row in process if row["elapsed_seconds"] <= connected + 15]
    steady = [row for row in process if row["elapsed_seconds"] >= connected + 35 and row["phase"] == "idle"]
    steady_php = [row for row in php if row["elapsed_seconds"] >= connected + 35 and row["phase"] == "idle"]
    result = {"startup": process_window(initial, logical),
              "steady_connected": process_window(steady, logical),
              "steady_php": {
                  "used_mib": {"min": min(row["php_used_mib"] for row in steady_php),
                               "max": max(row["php_used_mib"] for row in steady_php)},
                  "allocated_mib": sorted({row["php_allocated_mib"] for row in steady_php}),
                  "peak_mib_entire_probe": max(row["php_peak_mib"] for row in php),
                  "message_events_in_php_window": int(steady_php[-1]["messages"] - steady_php[0]["messages"]),
                  "node_events_in_php_window": int(steady_php[-1]["nodes"] - steady_php[0]["nodes"])}}
    # Raised exceptions work even when Python is invoked with -O.
    for key, value in result.items():
        if value != saved[key]:
            raise ValueError(f"Published summary differs from CSV: {key}")
    sampling = {
        "process_samples": len(process), "php_snapshots": len(php),
        "process_sample_span_seconds": process[-1]["elapsed_seconds"] - process[0]["elapsed_seconds"],
        "connected_event_php_clock_seconds": connected,
        "before_disconnect_connected_seconds": next(row for row in php if row["phase"] == "before_disconnect")["connected_seconds"],
        "steady_process_window": {"from_seconds": steady[0]["elapsed_seconds"], "to_seconds": steady[-1]["elapsed_seconds"], "samples": len(steady)},
        "steady_php_window": {"from_seconds": steady_php[0]["elapsed_seconds"], "to_seconds": steady_php[-1]["elapsed_seconds"],
                              "seconds": round(steady_php[-1]["elapsed_seconds"] - steady_php[0]["elapsed_seconds"], 3), "snapshots": len(steady_php)}
    }
    for key, value in sampling.items():
        if saved["sampling"][key] != value:
            raise ValueError(f"Sampling metadata differs from CSV: {key}")
    for key, value in php[-1].items():
        if saved["session_after_probe"][key] != value:
            raise ValueError(f"Final numeric snapshot differs from CSV: {key}")
    manifest = json.loads((DIRECTORY / "manifest.json").read_text(encoding="utf-8"))
    for key, value in {"connected_php_elapsed_seconds": connected,
                       "process_window": sampling["steady_process_window"],
                       "php_window": sampling["steady_php_window"]}.items():
        if manifest["window_selection"][key] != value:
            raise ValueError(f"Manifest window differs from CSV: {key}")
    for name, expected in manifest["sha256"].items():
        actual = hashlib.sha256((DIRECTORY / name).read_bytes()).hexdigest()
        if actual != expected:
            raise ValueError(f"SHA-256 mismatch: {name}")
    return {"aggregate_metrics_match_csv": True, "sampling_windows_match_csv": True,
            "final_numeric_snapshot_matches_csv": True, "published_hashes_match": True,
            "reported_not_recalculated": ["environment", "collector completion duration and exit code", "completed/registered/identity/noise flags", "error_class"],
            "process_samples": len(process), "php_snapshots": len(php),
            "windows": {"initial_process": [initial[0]["elapsed_seconds"], initial[-1]["elapsed_seconds"]],
                        "steady_process": [steady[0]["elapsed_seconds"], steady[-1]["elapsed_seconds"]],
                        "steady_php": [steady_php[0]["elapsed_seconds"], steady_php[-1]["elapsed_seconds"]]},
            "metrics": result}


if __name__ == "__main__":
    print(json.dumps(calculate(), ensure_ascii=False, indent=2))
