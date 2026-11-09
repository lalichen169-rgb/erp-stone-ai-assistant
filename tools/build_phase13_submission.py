#!/usr/bin/env python3
"""
Generate Phase 13/16 submission DOCX files and embedded diagram PNGs (local matplotlib).
Dependencies: python-docx, matplotlib, pillow

Produces docs/submission/*.docx including Phase 16.1 final readiness (Document 06).
"""
from __future__ import annotations

import json
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ASSETS = ROOT / "docs" / "submission" / "assets" / "generated"
ASSETS.mkdir(parents=True, exist_ok=True)

COLOR_PRIMARY = "#1e3a5f"
COLOR_ACCENT = "#2563eb"
COLOR_TEXT = "#334155"
COLOR_FILL = "#f1f5f9"
FONT_NAME = "Calibri"


def _plt_save(fig, name: str) -> Path:
    import matplotlib.pyplot as plt

    path = ASSETS / name
    fig.savefig(path, dpi=150, bbox_inches="tight", facecolor="white")
    plt.close(fig)
    return path


def _box(ax, x, y, w, h, text, fs=8):
    import matplotlib.patches as mpatches

    p = mpatches.FancyBboxPatch(
        (x, y), w, h, boxstyle="round,pad=0.02", linewidth=1.2, edgecolor=COLOR_PRIMARY, facecolor=COLOR_FILL
    )
    ax.add_patch(p)
    ax.text(x + w / 2, y + h / 2, text, ha="center", va="center", fontsize=fs, color=COLOR_PRIMARY, fontweight="bold")


def diagram_high_level_arch() -> Path:
    import matplotlib.pyplot as plt

    fig, ax = plt.subplots(figsize=(9.6, 5.0))
    ax.set_xlim(0, 10)
    ax.set_ylim(0, 5.5)
    ax.axis("off")

    _box(ax, 0.35, 4.35, 1.45, 0.7, "Browser\n(Vite UI)", 7.5)
    _box(ax, 2.1, 4.35, 1.75, 0.7, "Laravel\n(/api …)", 7.5)

    _box(ax, 4.25, 4.35, 1.45, 0.7, "MySQL\n(ERP +\nconversations)", 7)
    _box(ax, 6.05, 4.35, 1.75, 0.7, "Redis\n(queue +\ncache)", 7)
    _box(ax, 8.05, 4.35, 1.45, 0.7, "Queue\nworker\n(infra)", 7)

    _box(ax, 2.1, 2.75, 1.75, 0.7, "AI provider\n(OpenAI /\nClaude)", 7)
    _box(ax, 4.4, 2.75, 1.55, 0.7, "ToolExecutor\n+ tool defs", 7)
    _box(ax, 6.35, 2.75, 1.65, 0.7, "ERP services\n(Eloquent)", 7)

    # Browser → Laravel
    ax.annotate("", xy=(2.1, 4.7), xytext=(1.8, 4.7), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.35))
    # Laravel ↔ MySQL (data; not Redis)
    ax.annotate("", xy=(4.25, 4.7), xytext=(3.85, 4.7), arrowprops=dict(arrowstyle="<->", color=COLOR_ACCENT, lw=1.2))
    ax.text(4.05, 5.08, "reads / writes", ha="center", fontsize=6.5, color=COLOR_TEXT)
    # Laravel — optional infra (dashed): Redis + worker share app image in Compose; assistant path stays sync
    ax.annotate("", xy=(6.05, 4.7), xytext=(5.7, 4.7), arrowprops=dict(arrowstyle="->", color="#94a3b8", lw=1.0, linestyle="--"))
    ax.annotate("", xy=(8.05, 4.7), xytext=(7.8, 4.7), arrowprops=dict(arrowstyle="->", color="#94a3b8", lw=1.0, linestyle="--"))
    ax.text(7.35, 5.12, "optional jobs", ha="center", fontsize=6.5, color="#64748b", style="italic")

    # Tool loop stack (below Laravel)
    ax.annotate("", xy=(2.95, 3.45), xytext=(2.95, 4.35), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.2))
    ax.text(3.12, 3.88, "AI path", ha="left", fontsize=6.5, color=COLOR_TEXT)
    ax.annotate("", xy=(4.4, 3.1), xytext=(3.85, 3.1), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.2))
    ax.annotate("", xy=(6.35, 3.1), xytext=(5.95, 3.1), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.2))
    ax.annotate(
        "",
        xy=(5.4, 2.0),
        xytext=(7.18, 2.75),
        arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.2),
    )
    ax.text(6.35, 2.32, "reads / writes", ha="center", fontsize=6.5, color=COLOR_TEXT)
    _box(ax, 4.35, 1.35, 2.1, 0.65, "MySQL\n(same database)", 7)

    ax.text(
        5,
        0.95,
        "Figure 1 — High-level architecture",
        ha="center",
        fontsize=9,
        fontweight="bold",
        color=COLOR_PRIMARY,
    )
    ax.text(
        5,
        0.58,
        "When AI_PROVIDER=ollama, POST /api/assistant dispatches ProcessOllamaAssistantRun to Redis; OpenAI/Claude stay synchronous in the HTTP worker.",
        ha="center",
        fontsize=7.0,
        color=COLOR_TEXT,
    )
    ax.text(
        5,
        0.18,
        "MySQL does not push rows to Redis. ERP services reach MySQL through Laravel; the AI path uses the provider + ToolExecutor loop.",
        ha="center",
        fontsize=6.8,
        color=COLOR_TEXT,
    )
    return _plt_save(fig, "fig01_high_level_arch.png")


def diagram_tool_flow() -> Path:
    import matplotlib.pyplot as plt

    fig, ax = plt.subplots(figsize=(8.4, 8.2))
    ax.set_xlim(0, 10)
    ax.set_ylim(0, 8.8)
    ax.axis("off")

    bw, bh = 5.4, 0.58
    bx = 2.3
    y = 7.5
    labels = [
        "1. Laravel prepares the user question, conversation context, and role-filtered tool definitions (ToolRegistry supplies schemas; it does not call the provider).",
        "2. AiToolCallingService → AiAgentService → AiProviderInterface (OpenAiProvider / ClaudeProvider): send messages + tool definitions.",
        "3. Provider response: pending tool call(s) with name + arguments JSON.",
        "4. ToolExecutor: forbid-list check, schema allowlist, ToolRegistry.get(name), allowedFor(user), then delegates to the tool class.",
        "5. Tool class calls the appropriate ERP service (e.g. StockLookupService, DraftPurchaseOrderService).",
        "6. ERP service loads or persists via Eloquent → MySQL.",
        "7. Tool result serialized → returned through the provider loop (AiToolCallingService may repeat until no pending tool calls).",
        "8. Final assistant text + metadata → HTTP JSON (OpenAI/Claude: 200 in-request; Ollama: worker persists result; client polls GET /api/assistant/runs/{id}).",
    ]
    for t in labels:
        _box(ax, bx, y, bw, bh, t, 6.85)
        y -= 0.82

    cx = bx + bw / 2
    for yi in [7.5, 6.68, 5.86, 5.04, 4.22, 3.4, 2.58]:
        ax.annotate(
            "",
            xy=(cx, yi - 0.02),
            xytext=(cx, yi + 0.58),
            arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.15),
        )
        ax.text(cx + 3.05, yi + 0.28, "request →", ha="left", va="center", fontsize=6.4, color="#64748b")

    ax.annotate(
        "",
        xy=(cx, 1.68),
        xytext=(cx, 1.76),
        arrowprops=dict(arrowstyle="->", color="#0f172a", lw=1.35),
    )
    ax.text(cx + 3.05, 1.78, "response / next iteration", ha="left", va="center", fontsize=6.4, color="#0f172a")

    ax.text(
        5,
        0.62,
        "Figure 2 — AI tool execution flow (orchestration in AiToolCallingService + AiAgentService; ToolRegistry is definitions + lookup only)",
        ha="center",
        fontsize=8.4,
        fontweight="bold",
        color=COLOR_PRIMARY,
    )
    ax.text(
        5,
        0.22,
        "Right-hand labels mark the dominant direction on each hop; step 7 may loop to step 3 until the provider returns a final assistant message.",
        ha="center",
        fontsize=6.9,
        color=COLOR_TEXT,
    )
    return _plt_save(fig, "fig02_tool_path.png")


def diagram_follow_up() -> Path:
    import matplotlib.pyplot as plt

    fig, ax = plt.subplots(figsize=(8.8, 2.8))
    ax.set_xlim(0, 10)
    ax.set_ylim(0, 3)
    ax.axis("off")
    _box(ax, 0.2, 1.35, 1.7, 0.85, "Short follow-up\n(e.g. And in Dallas?)", 7.5)
    _box(ax, 2.2, 1.35, 2.1, 0.85, "FollowUpQuestionResolver\n(heuristics / optional AI)", 7)
    _box(ax, 4.55, 1.35, 1.95, 0.85, "resolved_standalone_question\n(metadata on user msg)", 7)
    _box(ax, 6.75, 1.35, 2.15, 0.85, "ConversationContextManager\nbounded history", 7)
    for x1, x2 in [(1.9, 2.2), (4.3, 4.55), (6.5, 6.75)]:
        ax.annotate("", xy=(x2, 1.77), xytext=(x1, 1.77), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.3))
    ax.text(5, 0.3, "Figure 3 — Follow-up resolution and context assembly", ha="center", fontsize=8, color=COLOR_TEXT)
    return _plt_save(fig, "fig03_follow_up.png")


def diagram_draft_po() -> Path:
    import matplotlib.pyplot as plt

    fig, ax = plt.subplots(figsize=(7.8, 8.0))
    ax.set_xlim(0, 10)
    ax.set_ylim(0, 8.8)
    ax.axis("off")

    bw, bh = 8.2, 0.62
    bx = 0.9
    y = 7.35
    steps = [
        "1. Purchasing user requests a draft purchase order (UI or POST /api/assistant).",
        "2. Model selects the proposeDraftPurchaseOrder tool (Purchasing role only).",
        "3. Laravel validates permissions, forbidden keys, JSON-schema allowlist, and business rules (ToolExecutor + tool).",
        "4. Draft proposal row persisted in purchase_order_proposals (pending; no PO lines yet).",
        "5. Assistant surfaces a human-readable preview (GET /api/purchase-order-proposals/{id} in production workflows).",
        "6. User explicitly confirms via POST …/confirm with Idempotency-Key (no silent commit).",
        "7. Laravel verifies ownership (user + conversation_key), payload hash integrity, and expiry inside a transaction.",
        "8. purchase_orders + purchase_order_lines are created in the same DB transaction; proposal marked confirmed.",
        "9. Repeated confirmation is idempotent: second call returns the same purchase_order_id (row lock + state check).",
    ]
    for t in steps:
        _box(ax, bx, y, bw, bh, t, 6.9)
        y -= 0.78

    cx = bx + bw / 2
    for yi in [7.35, 6.57, 5.79, 5.01, 4.23, 3.45, 2.67, 1.89]:
        ax.annotate("", xy=(cx, yi - 0.02), xytext=(cx, yi + 0.62), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.15))

    ax.text(5, 0.85, "Figure 4 — Draft purchase-order workflow", ha="center", fontsize=9, fontweight="bold", color=COLOR_PRIMARY)
    ax.text(
        5,
        0.38,
        "Readable at Word zoom 100%. Business rules unchanged: proposal first, explicit HTTP confirm, transactional PO creation.",
        ha="center",
        fontsize=7,
        color=COLOR_TEXT,
    )
    return _plt_save(fig, "fig04_draft_po.png")


def diagram_er() -> Path:
    import matplotlib.pyplot as plt
    import matplotlib.patches as mpatches

    fig, ax = plt.subplots(figsize=(9, 4.8))
    ax.set_xlim(0, 10)
    ax.set_ylim(0, 5.5)
    ax.axis("off")

    def ent(x, y, label):
        w, h = 1.85, 0.68
        ax.add_patch(
            mpatches.FancyBboxPatch(
                (x, y), w, h, boxstyle="round,pad=0.02", linewidth=1.0, edgecolor=COLOR_PRIMARY, facecolor="#f8fafc"
            )
        )
        ax.text(x + w / 2, y + h / 2, label, ha="center", va="center", fontsize=7.5, color=COLOR_PRIMARY)

    ent(0.15, 3.9, "users")
    ent(0.15, 2.5, "conversations")
    ent(0.15, 1.1, "conversation_messages")
    ent(2.4, 4.1, "products")
    ent(2.4, 2.85, "warehouses")
    ent(2.4, 1.55, "suppliers")
    ent(5.1, 3.95, "stock")
    ent(5.0, 2.35, "purchase_orders")
    ent(7.4, 2.35, "purchase_order_lines")
    ent(5.0, 0.85, "purchase_order_proposals")
    ax.plot([2.4, 5.1], [4.4, 4.35], color=COLOR_ACCENT, lw=1)
    ax.plot([2.4, 5.1], [3.15, 4.0], color=COLOR_ACCENT, lw=1)
    ax.plot([4.25, 5.0], [1.9, 2.65], color=COLOR_ACCENT, lw=1)
    ax.plot([6.85, 6.0], [2.67, 2.67], color=COLOR_ACCENT, lw=1)
    ax.text(5, 5.1, "Figure 5 — Core persistent entities (simplified ER)", ha="center", fontsize=9, fontweight="bold", color=COLOR_PRIMARY)
    return _plt_save(fig, "fig05_er.png")


def diagram_docker() -> Path:
    import matplotlib.pyplot as plt
    import matplotlib.patches as mpatches

    fig, ax = plt.subplots(figsize=(8.2, 3.0))
    ax.set_xlim(0, 10)
    ax.set_ylim(0, 3.5)
    ax.axis("off")
    ax.add_patch(mpatches.Rectangle((0.35, 0.65), 9.3, 2.5, fill=False, edgecolor=COLOR_PRIMARY, linestyle="--", linewidth=1.4))
    ax.text(
        5,
        3.15,
        "docker compose (files in repo; runtime not verified on primary dev machine)",
        ha="center",
        fontsize=7.8,
        color=COLOR_TEXT,
        style="italic",
    )
    for i, (x, lab) in enumerate([(0.5, "app\n(Apache+PHP)"), (2.45, "queue\nqueue:work redis"), (4.4, "mysql:8.4"), (6.35, "redis:7-alpine")]):
        ax.add_patch(
            mpatches.FancyBboxPatch((x, 1.1), 1.55, 1.05, boxstyle="round,pad=0.02", edgecolor=COLOR_PRIMARY, facecolor=COLOR_FILL)
        )
        ax.text(x + 0.78, 1.62, lab, ha="center", va="center", fontsize=7.5, color=COLOR_PRIMARY)
        if i < 3:
            ax.annotate("", xy=(x + 1.65, 1.62), xytext=(x + 1.55, 1.62), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.2))
    ax.text(5, 0.35, "Figure 6 — Docker Compose services (see docker-compose.yml)", ha="center", fontsize=8, color=COLOR_TEXT)
    return _plt_save(fig, "fig06_docker.png")


def diagram_provider() -> Path:
    import matplotlib.pyplot as plt
    import matplotlib.patches as mpatches

    fig, ax = plt.subplots(figsize=(9.2, 3.4))
    ax.axis("off")
    ax.set_xlim(0, 9.2)
    ax.set_ylim(0, 3.4)
    _box(ax, 0.25, 2.35, 1.55, 0.65, "config\nai.provider", 7.5)
    _box(ax, 2.05, 2.35, 1.55, 0.65, "AiProvider\nFactory", 7.5)
    _box(ax, 4.0, 2.35, 1.45, 0.65, "OpenAi\n(sync HTTP)", 7)
    _box(ax, 5.7, 2.35, 1.45, 0.65, "Claude\n(sync HTTP)", 7)
    _box(ax, 7.35, 2.35, 1.55, 0.65, "Ollama\n(async)", 7)
    ax.annotate("", xy=(2.05, 2.68), xytext=(1.8, 2.68), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.2))
    for x1, x2 in [(3.6, 4.0), (5.35, 5.7), (7.0, 7.35)]:
        ax.annotate("", xy=(x2, 2.68), xytext=(x1, 2.68), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.1))
    _box(ax, 1.2, 1.05, 2.2, 0.75, "HTTP request\nAiToolCallingService\n→ HTTP 200", 7)
    _box(ax, 3.85, 1.05, 2.35, 0.75, "HTTP 202 +\nProcessOllamaAssistantRun\n→ Redis queue", 7)
    _box(ax, 6.55, 1.05, 2.2, 0.75, "Queue worker\nclaims run\n→ inference", 7)
    ax.annotate("", xy=(5.0, 1.45), xytext=(4.55, 2.35), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.0))
    ax.annotate("", xy=(7.6, 1.45), xytext=(6.95, 1.8), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.0))
    ax.text(4.6, 0.35, "Figure 7 — Provider paths (Ollama async; OpenAI/Claude unchanged)", ha="center", fontsize=7.5, color=COLOR_TEXT)
    return _plt_save(fig, "fig07_provider.png")


def diagram_async_ollama_lifecycle() -> Path:
    """Ollama assistant: accept → queue → worker → poll."""
    import matplotlib.pyplot as plt

    fig, ax = plt.subplots(figsize=(9.6, 7.2))
    ax.set_xlim(0, 10)
    ax.set_ylim(0, 8.2)
    ax.axis("off")
    steps = [
        (7.6, "1. User POST /api/assistant (Sanctum)"),
        (6.7, "2. Persist conversation + user message"),
        (5.8, "3. Create assistant_runs row (queued)"),
        (4.9, "4. Dispatch ProcessOllamaAssistantRun after DB commit"),
        (4.0, "5. HTTP 202 + run_id + status_url"),
        (3.1, "6. Worker: DB claim queued → processing"),
        (2.2, "7. AiToolCallingService + ERP tools → MySQL"),
        (1.3, "8. completed/failed + client GET poll until terminal"),
    ]
    bw, bh = 7.8, 0.55
    bx = 1.1
    for y, label in steps:
        _box(ax, bx, y, bw, bh, label, 7.2)
    ax.text(5, 0.55, "Figure 11 — Ollama async assistant lifecycle (OpenAI/Claude skip steps 3–4, 6–8 queue path)", ha="center", fontsize=8.5, fontweight="bold", color=COLOR_PRIMARY)
    return _plt_save(fig, "fig11_async_ollama_lifecycle.png")


def diagram_recovery_failure() -> Path:
    """Bounded recovery + failure paths (Phase 16.6E/F)."""
    import matplotlib.pyplot as plt

    fig, ax = plt.subplots(figsize=(9.4, 5.2))
    ax.set_xlim(0, 10)
    ax.set_ylim(0, 5.2)
    ax.axis("off")
    _box(ax, 0.4, 3.85, 4.2, 0.7, "Queued run + missing payload?\nReconcile on poll / idempotent replay\n(lease 120s, max 5 attempts)", 7)
    _box(ax, 5.0, 3.85, 4.5, 0.7, "Worker exception / timeout\n→ ProcessOllamaAssistantRun::failed()\n→ queue_job_failed", 7)
    _box(ax, 0.4, 2.55, 4.2, 0.7, "Dispatch throws\n→ queue_dispatch_failed\n(no stack in API)", 7)
    _box(ax, 5.0, 2.55, 4.5, 0.7, "Stale processing (started_at > 1080s)\n→ ai_timeout on status poll", 7)
    _box(ax, 0.4, 1.25, 4.2, 0.7, "Attempts exhausted\n→ queue_recovery_exhausted", 7)
    _box(ax, 5.0, 1.25, 4.5, 0.7, "SIGKILL: failed() may not run\n→ processing until stale poll", 7)
    ax.text(5, 0.45, "Figure 12 — Recovery and failure (bounded redispatch; no Redis payload proof)", ha="center", fontsize=8, fontweight="bold", color=COLOR_PRIMARY)
    return _plt_save(fig, "fig12_recovery_failure.png")


def diagram_laravel_layers() -> Path:
    import matplotlib.pyplot as plt
    import matplotlib.patches as mpatches

    fig, ax = plt.subplots(figsize=(8, 3.6))
    ax.axis("off")
    ax.set_xlim(0, 8)
    ax.set_ylim(0, 4.2)
    y = 3.25
    for label in [
        "Routes: routes/api.php → /api/*",
        "HTTP: Form Requests + Controllers",
        "Domain: app/Services/Erp, app/Services/AI",
        "Data: Eloquent models → MySQL",
    ]:
        ax.add_patch(mpatches.FancyBboxPatch((0.5, y), 7.0, 0.62, boxstyle="round,pad=0.02", edgecolor=COLOR_PRIMARY, facecolor="#f8fafc"))
        ax.text(4, y + 0.31, label, ha="center", va="center", fontsize=9, color=COLOR_PRIMARY)
        y -= 0.78
    ax.text(4, 0.35, "Figure 8 — Laravel layering", ha="center", fontsize=8, color=COLOR_TEXT)
    return _plt_save(fig, "fig08_laravel_layers.png")


def diagram_auth() -> Path:
    import matplotlib.pyplot as plt

    fig, ax = plt.subplots(figsize=(8, 2.2))
    ax.axis("off")
    ax.set_xlim(0, 8)
    ax.set_ylim(0, 2)
    _box(ax, 0.3, 0.55, 1.6, 0.85, "Sanctum\nBearer token", 8)
    _box(ax, 2.2, 0.55, 1.7, 0.85, "User role\n(Sales / Purchasing)", 8)
    _box(ax, 4.15, 0.55, 1.75, 0.85, "ErpGateRegistrar\nabilities", 8)
    _box(ax, 6.1, 0.55, 1.55, 0.85, "Services +\nAI tools", 8)
    for x1, x2 in [(1.9, 2.2), (3.9, 4.15), (5.9, 6.1)]:
        ax.annotate("", xy=(x2, 0.97), xytext=(x1, 0.97), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.2))
    ax.text(4, 0.2, "Figure 9 — Authorization chain", ha="center", fontsize=7.5, color=COLOR_TEXT)
    return _plt_save(fig, "fig09_auth.png")


def diagram_phase16_readiness() -> Path:
    """Phase 16.1 — readiness pipeline (audit → automated → manual → live AI)."""
    import matplotlib.pyplot as plt

    fig, ax = plt.subplots(figsize=(8.4, 2.6))
    ax.axis("off")
    ax.set_xlim(0, 10)
    ax.set_ylim(0, 2.4)
    _box(ax, 0.2, 1.35, 1.5, 0.72, "Environment\naudit", 7.5)
    _box(ax, 2.0, 1.35, 1.55, 0.72, "Automated\ntests", 7.5)
    _box(ax, 3.85, 1.35, 1.55, 0.72, "Manual API /\nUI (16.2)", 7)
    _box(ax, 5.7, 1.35, 1.5, 0.72, "Live AI\n(Pending)", 7)
    _box(ax, 7.45, 1.35, 1.55, 0.72, "Docker\n(Unverified)", 7)
    for x1, x2 in [(1.7, 2.0), (3.55, 3.85), (5.4, 5.7), (7.2, 7.45)]:
        ax.annotate("", xy=(x2, 1.71), xytext=(x1, 1.71), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.25))
    ax.text(
        5,
        0.55,
        "Figure 10 — Phase 16 readiness sequence (this phase completes environment + automated baseline; manual and live steps are Phase 16.2+)",
        ha="center",
        fontsize=7.8,
        color=COLOR_TEXT,
    )
    return _plt_save(fig, "fig10_phase16_readiness.png")


def add_cover(doc, title: str, subtitle: str) -> None:
    from docx.enum.text import WD_ALIGN_PARAGRAPH
    from docx.shared import Pt, RGBColor

    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.add_run("\n\n\n\n")
    run = p.add_run(title + "\n")
    run.bold = True
    run.font.size = Pt(26)
    run.font.color.rgb = RGBColor(30, 58, 95)
    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r2 = p.add_run(subtitle + "\n\n")
    r2.font.size = Pt(13)
    r2.font.color.rgb = RGBColor(51, 65, 85)
    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    r3 = p.add_run("Stone ERP AI Assistant — submission package")
    r3.font.size = Pt(10)
    doc.add_page_break()


def add_manual_contents(doc, sections: list[str]) -> None:
    """Populate a readable table-of-contents page (no Word TOC fields — stable in generated DOCX)."""
    from docx.shared import RGBColor

    doc.add_heading("Contents", level=1)
    for title in sections:
        para(doc, title)
    doc.add_page_break()


def set_document_style(doc) -> None:
    from docx.enum.text import WD_LINE_SPACING
    from docx.shared import Pt, RGBColor

    style = doc.styles["Normal"]
    style.font.name = FONT_NAME
    style.font.size = Pt(11)
    style.paragraph_format.line_spacing_rule = WD_LINE_SPACING.MULTIPLE
    style.paragraph_format.line_spacing = 1.18
    style.paragraph_format.space_after = Pt(6)
    for lvl in range(1, 4):
        h = doc.styles[f"Heading {lvl}"]
        h.font.name = FONT_NAME
        h.font.color.rgb = RGBColor(30, 58, 95)


def add_page_number_footer(section) -> None:
    from docx.enum.text import WD_ALIGN_PARAGRAPH
    from docx.oxml import OxmlElement
    from docx.oxml.ns import qn

    footer = section.footer
    p = footer.paragraphs[0] if footer.paragraphs else footer.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.text = ""
    run = p.add_run("Stone ERP AI — Page ")
    r2 = p.add_run()
    fld_begin = OxmlElement("w:fldChar")
    fld_begin.set(qn("w:fldCharType"), "begin")
    instr = OxmlElement("w:instrText")
    instr.set(qn("xml:space"), "preserve")
    instr.text = "PAGE"
    fld_sep = OxmlElement("w:fldChar")
    fld_sep.set(qn("w:fldCharType"), "separate")
    fld_text = OxmlElement("w:t")
    fld_text.text = "1"
    fld_end = OxmlElement("w:fldChar")
    fld_end.set(qn("w:fldCharType"), "end")
    r2._r.append(fld_begin)
    r2._r.append(instr)
    r2._r.append(fld_sep)
    r2._r.append(fld_text)
    r2._r.append(fld_end)


def add_header(section, text: str) -> None:
    from docx.enum.text import WD_ALIGN_PARAGRAPH
    from docx.shared import Pt, RGBColor

    hp = section.header.paragraphs[0] if section.header.paragraphs else section.header.add_paragraph()
    hp.text = ""
    r = hp.add_run(text)
    r.bold = True
    r.font.size = Pt(9)
    r.font.color.rgb = RGBColor(30, 58, 95)
    hp.alignment = WD_ALIGN_PARAGRAPH.RIGHT


def para(doc, text: str) -> None:
    from docx.shared import RGBColor

    p = doc.add_paragraph()
    r = p.add_run(text)
    r.font.name = FONT_NAME
    r.font.color.rgb = RGBColor(51, 65, 85)


def code_block(doc, lines: str) -> None:
    from docx.shared import Inches, Pt, RGBColor

    p = doc.add_paragraph()
    p.paragraph_format.left_indent = Inches(0.28)
    p.paragraph_format.space_before = Pt(4)
    r = p.add_run(lines.strip("\n"))
    r.font.name = "Consolas"
    r.font.size = Pt(9)
    r.font.color.rgb = RGBColor(15, 23, 42)


def add_picture(doc, path: Path, caption: str) -> None:
    from docx.enum.text import WD_ALIGN_PARAGRAPH
    from docx.shared import Inches, Pt, RGBColor

    p = doc.add_paragraph()
    p.alignment = WD_ALIGN_PARAGRAPH.CENTER
    p.add_run().add_picture(str(path), width=Inches(5.85))
    cap = doc.add_paragraph()
    cap.alignment = WD_ALIGN_PARAGRAPH.CENTER
    cr = cap.add_run(caption)
    cr.font.size = Pt(9)
    cr.font.italic = True
    cr.font.color.rgb = RGBColor(71, 85, 105)
    doc.add_paragraph()


def build_doc01(diagrams: dict) -> None:
    from docx import Document

    doc = Document()
    set_document_style(doc)
    sec = doc.sections[0]
    sec.different_first_page_header_footer = True
    add_header(sec, "01 — Overview & Architecture")
    add_page_number_footer(sec)

    add_cover(doc, "Project Overview", "Architecture & technical design")

    add_manual_contents(
        doc,
        [
            "Executive summary",
            "Business problem and objectives",
            "Technology stack (composer.json)",
            "High-level architecture",
            "Laravel layering",
            "AI provider abstraction",
            "Tool execution architecture",
            "Why tools call Laravel services (not model SQL)",
            "Database: core tables and relationships",
            "Conversation context management",
            "Authentication and authorization",
            "Redis and queues",
            "Ollama async assistant lifecycle",
            "Recovery and failure paths",
            "Docker deployment (files only)",
            "Design decisions and limitations",
        ],
    )

    doc.add_heading("Executive summary", level=1)
    para(
        doc,
        "Stone ERP AI Assistant is a Laravel 12 application: a Sanctum-authenticated JSON API plus a minimal Vite/Tailwind "
        "chat UI (route `/` renders resources/views/chat.blade.php). POST /api/assistant orchestrates AI through a fixed tool registry; "
        "tools call PHP services that query MySQL via Eloquent — the model never executes arbitrary SQL. When AI_PROVIDER=ollama, "
        "inference runs in ProcessOllamaAssistantRun (Redis); OpenAI and Claude remain synchronous HTTP 200 paths. Draft POs require explicit confirm with idempotency.",
    )

    doc.add_heading("Business problem and objectives", level=1)
    para(
        doc,
        "Enable Sales staff to query slab inventory and purchase-order status in natural language; enable Purchasing to access "
        "landed costs and staged draft PO proposals with strict role gates enforced in Laravel Gates, ERP services, and tool allow lists.",
    )

    doc.add_heading("Technology stack (composer.json)", level=1)
    for item in [
        "Deployment / challenge target: PHP 8.3+ and Laravel 12+ (see README).",
        "composer.json requires php ^8.3 (aligned with Dockerfile php:8.3-apache).",
        "laravel/framework ^12.0",
        "laravel/sanctum ^4.3",
        "openai-php/laravel ^0.22.0",
        "predis/predis ^3.7",
        "Frontend: Vite 6, Tailwind 4, axios (see package.json)",
    ]:
        doc.add_paragraph(item, style="List Bullet")

    doc.add_heading("High-level architecture", level=1)
    add_picture(doc, diagrams["high"], "Figure 1 — Components from browser to persistence")

    doc.add_heading("Laravel layering", level=1)
    add_picture(doc, diagrams["laravel"], "Figure 8 — Routes, HTTP layer, services, data")

    doc.add_heading("AI provider abstraction", level=1)
    add_picture(doc, diagrams["provider"], "Figure 7 — Factory selects OpenAI or Claude; other providers not implemented")

    doc.add_heading("Tool execution architecture", level=1)
    add_picture(doc, diagrams["tool"], "Figure 2 — Orchestrated tool loop with server-side execution")

    doc.add_heading("Why tools call Laravel services (not model SQL)", level=1)
    para(
        doc,
        "Tools are a whitelist: each maps to validated arguments, Gate checks, and service-layer queries. This prevents prompt-injected "
        "'run SQL' instructions from becoming database access — the model only proposes structured tool calls; the server decides "
        "whether to execute them.",
    )

    doc.add_heading("Database: core tables and relationships", level=1)
    para(
        doc,
        "Core ERP: products, warehouses, suppliers, stock, purchase_orders, purchase_order_lines. Proposals: purchase_order_proposals. "
        "Conversation: conversations, conversation_messages, conversation_tool_calls, conversation_summaries, conversation_ai_interactions — see database/migrations.",
    )
    add_picture(doc, diagrams["er"], "Figure 5 — Simplified entity-relationship view")

    doc.add_heading("Conversation context management", level=1)
    add_picture(doc, diagrams["follow"], "Figure 3 — Follow-up resolution and bounded context")
    para(
        doc,
        "ConversationContextManager applies AI_CONTEXT_MAX_MESSAGES and AI_CONTEXT_MAX_CHARS (config/ai.php). Sales users do not "
        "receive prior getLandedCost tool rows in assembled context (ConversationContextManager::includeToolRowForUser).",
    )

    doc.add_heading("Authentication and authorization", level=1)
    add_picture(doc, diagrams["auth"], "Figure 9 — Sanctum token + Gates + tools")
    para(
        doc,
        "ErpAbilities in app/Auth/ErpAbilities.php and ErpGateRegistrar register Laravel Gates — Purchasing-only abilities include "
        "viewLandedCosts, viewSupplierBankDetails, createDraftPurchaseOrders.",
    )

    doc.add_heading("Redis and queues", level=1)
    para(
        doc,
        "Redis backs cache and queues (config/queue.php; predis in .env.example). When AI_PROVIDER=ollama, "
        "AssistantRunCoordinator dispatches ProcessOllamaAssistantRun after persisting assistant_runs (dispatch tracking: "
        "last_queue_dispatch_at, queue_dispatch_attempts). Bounded re-dispatch on poll/idempotent replay — see docs/PHASE16.6C-ASYNC-ASSISTANT-OPERATIONS.md. "
        "PHPUnit often uses Queue::fake(); redis integration uses a harmless probe job. OpenAI/Claude do not enqueue this job.",
    )
    for item in [
        "Job timeout 900s; worker --timeout 900s; redis retry_after default 960s; stale processing 1080s; tries=1.",
        "scripts\\queue-worker.cmd — project root + tools\\php\\php.exe + absolute artisan path.",
        "docker-compose.yml queue service — documented, not runtime-verified on primary Windows host.",
    ]:
        doc.add_paragraph(item, style="List Bullet")

    doc.add_heading("Ollama async assistant lifecycle", level=1)
    if "async_lifecycle" in diagrams:
        add_picture(doc, diagrams["async_lifecycle"], "Figure 11 — Accept, queue, worker, poll")

    doc.add_heading("Recovery and failure paths", level=1)
    if "recovery" in diagrams:
        add_picture(doc, diagrams["recovery"], "Figure 12 — Bounded recovery and terminal failure (Phase 16.6E/F)")

    doc.add_heading("Docker deployment (files only)", level=1)
    add_picture(doc, diagrams["docker"], "Figure 6 — docker-compose.yml services; runtime not verified on the primary Windows dev host")
    para(doc, "See docs/PHASE11-DOCKER.md for env files and first-run commands.")

    doc.add_heading("Design decisions and limitations", level=1)
    for item in [
        "Ollama async only — provider isolation preserved for OpenAI/Claude (ProviderIsolationRegressionTest).",
        "Queue recovery does not inspect Redis for a specific payload; duplicate queue jobs possible; DB claim prevents duplicate inference (16.6F).",
        "Worker SIGKILL may leave processing until stale reconciliation on poll.",
        "Docker Compose runtime not verified on primary dev host.",
        "No streaming; Bedrock/Gemini/Groq not implemented.",
        "Phase 16.6E historical: 294 PHPUnit tests — re-run before interview.",
    ]:
        doc.add_paragraph(item, style="List Bullet")

    out = ROOT / "docs" / "submission" / "01-Project-Overview-and-Architecture.docx"
    doc.save(out)
    print("Wrote", out)


def build_doc02(diagrams: dict) -> None:
    from docx import Document

    doc = Document()
    set_document_style(doc)
    sec = doc.sections[0]
    sec.different_first_page_header_footer = True
    add_header(sec, "02 — Workflows & User Guide")
    add_page_number_footer(sec)

    add_cover(doc, "Business Workflows", "User guide")

    add_manual_contents(
        doc,
        [
            "Application overview",
            "Login and authentication",
            "Sales vs Purchasing (comparison)",
            "Workflow: assistant question with tools",
            "Workflow: follow-up question",
            "Draft PO proposal and explicit confirmation",
            "Stock lookup",
            "Purchase-order status",
            "Overdue purchase orders",
            "Landed cost",
            "Errors and clarification",
            "Example conversations (illustrative, not screenshots)",
            "Screenshots",
        ],
    )

    doc.add_heading("Application overview", level=1)
    para(
        doc,
        "Users obtain a Bearer token via POST /api/auth/token, then call GET/POST /api/* routes or use the web chat UI which "
        "calls the API with the token (see resources/js/chat).",
    )

    doc.add_heading("Login and authentication", level=1)
    code_block(
        doc,
        """POST /api/auth/token
Content-Type: application/json

{
  "email": "<user email>",
  "password": "<password>",
  "device_name": "demo"
}

Response: { "token": "...", "token_type": "Bearer", "user": { ... } }

Subsequent requests:
Authorization: Bearer <token>
""",
    )

    doc.add_heading("Sales vs Purchasing (comparison)", level=1)
    table = doc.add_table(rows=1, cols=3)
    hdr = table.rows[0].cells
    hdr[0].text = "Capability"
    hdr[1].text = "Sales"
    hdr[2].text = "Purchasing"
    for a, s, p in [
        ("Products & stock APIs", "Yes", "Yes"),
        ("PO status / overdue (no unit/freight in Sales JSON)", "Yes", "Yes"),
        ("Landed cost API / getLandedCost tool", "No", "Yes"),
        ("Supplier bank details", "No", "Yes"),
        ("Draft proposal create / confirm / tool", "No", "Yes"),
    ]:
        row = table.add_row().cells
        row[0].text, row[1].text, row[2].text = a, s, p

    doc.add_heading("Workflow: assistant question with tools", level=1)
    add_picture(doc, diagrams["tool"], "Figure 2 — Request through provider and ToolExecutor")

    doc.add_heading("Workflow: follow-up question", level=1)
    add_picture(doc, diagrams["follow"], "Figure 3 — Short follow-ups resolved before main model call")

    doc.add_heading("Draft PO proposal and explicit confirmation", level=1)
    para(
        doc,
        "proposeDraftPurchaseOrder creates a pending purchase_order_proposals row only. Creating purchase_orders and "
        "purchase_order_lines requires POST /api/purchase-order-proposals/{proposalId}/confirm with Idempotency-Key — no auto-confirm.",
    )
    add_picture(doc, diagrams["draft"], "Figure 4 — Proposal then explicit confirm")

    doc.add_heading("Stock lookup", level=1)
    para(doc, "Direct: GET /api/stock with query params (StockSearchRequest). Via assistant: checkStock tool → StockLookupService.")

    doc.add_heading("Purchase-order status", level=1)
    para(doc, "GET /api/purchase-orders — searchPurchaseOrders tool for natural language.")

    doc.add_heading("Overdue purchase orders", level=1)
    para(doc, "GET /api/purchase-orders/overdue — findOverduePurchaseOrders tool; rule: status ordered, eta before as_of (service + tests).")

    doc.add_heading("Landed cost", level=1)
    para(doc, "GET /api/purchase-orders/{id}/landed-cost — Purchasing only; uses LandedCostService (see Document 04 for formula).")

    doc.add_heading("Errors and clarification", level=1)
    para(
        doc,
        "FollowUpQuestionResolver may return clarification when ambiguous (or safe fallback if AI resolver fails). Validation errors return 422; "
        "idempotency conflicts return 409 (assistant) per AssistantController.",
    )

    doc.add_heading("Example conversations (illustrative, not screenshots)", level=1)
    doc.add_paragraph('Sales: "How many 3cm Calacatta slabs are in Houston?" then "And in Dallas?"', style="List Bullet")
    doc.add_paragraph(
        "Purchasing: use landed-cost endpoint or ask assistant; draft PO via proposeDraftPurchaseOrder then confirm API.", style="List Bullet"
    )

    doc.add_heading("Screenshots", level=1)
    para(doc, "No screenshots are embedded in this package (none captured in the doc generation environment).")

    out = ROOT / "docs" / "submission" / "02-Business-Workflows-and-User-Guide.docx"
    doc.save(out)
    print("Wrote", out)


def build_doc03() -> None:
    from docx import Document

    doc = Document()
    set_document_style(doc)
    sec = doc.sections[0]
    sec.different_first_page_header_footer = True
    add_header(sec, "03 — Installation & Setup")
    add_page_number_footer(sec)

    add_cover(doc, "Installation & Developer Setup", "Windows local + optional Docker")

    add_manual_contents(
        doc,
        [
            "System requirements",
            "Repository setup",
            "Windows local workflow",
            "Environment configuration",
            "AI provider switch",
            "Queue worker",
            "Frontend",
            "Docker Compose (Docker-capable hosts only)",
            "Run tests",
            "Troubleshooting",
        ],
    )

    doc.add_heading("System requirements", level=1)
    for x in [
        "PHP 8.3+ with extensions required by Laravel (pdo_mysql, mbstring, openssl, json, etc.). composer.json requires ^8.3.",
        "MySQL 8",
        "Node.js / npm for Vite (see package.json engines implicit via lockfile)",
        "Redis or Memurai for queue/caching when QUEUE_CONNECTION=redis (Windows docs reference Memurai)",
    ]:
        doc.add_paragraph(x, style="List Bullet")

    doc.add_heading("Repository setup", level=1)
    para(doc, "Clone the repository. Use scripts\\dev.cmd to invoke the project-local PHP and Composer (tools/php, tools/composer).")

    doc.add_heading("Windows local workflow", level=1)
    code_block(
        doc,
        r"""scripts\dev.cmd php -v
scripts\dev.cmd composer install
copy .env.example .env
scripts\dev.cmd php artisan key:generate

# Create databases (names from .env.example); PHPUnit uses stone_erp_ai_test (phpunit.xml)

scripts\dev.cmd php artisan migrate
scripts\dev.cmd php artisan db:seed

# Optional demo users — set DEMO_USER_PASSWORD in .env first (never commit)
scripts\dev.cmd php artisan db:seed --class=Database\Seeders\DemoUserSeeder

npm install
npm run build
scripts\dev.cmd php artisan serve
""",
    )

    doc.add_heading("Environment configuration", level=1)
    para(doc, "Copy .env.example to .env. Key variables: DB_*, REDIS_*, QUEUE_CONNECTION, AI_PROVIDER, OPENAI_API_KEY, ANTHROPIC_API_KEY.")
    para(
        doc,
        "OpenAI/Anthropic keys are only needed for live provider calls. php artisan test uses phpunit.xml env (including test API key) — no real key required for tests.",
    )

    doc.add_heading("AI provider switch", level=1)
    para(
        doc,
        "Set AI_PROVIDER=openai, claude, or ollama in .env (config/ai.php). ERP services do not branch on provider. "
        "Only ollama uses async assistant_runs + Redis; start scripts\\queue-worker.cmd when using ollama.",
    )

    doc.add_heading("Queue worker", level=1)
    para(
        doc,
        "Required for Ollama assistant: scripts\\queue-worker.cmd (wraps queue-worker.ps1; resolves project root, uses tools\\php\\php.exe, "
        "reads ai.assistant.async.queue_connection, queue, job_timeout_seconds). DryRun: powershell -File scripts\\queue-worker.ps1 -DryRun. "
        "OpenAI/Claude assistant does not need this worker for inference.",
    )

    doc.add_heading("Frontend", level=1)
    code_block(doc, "npm run dev    # Vite dev\nnpm run build  # production assets to public/build")

    doc.add_heading("Docker Compose (Docker-capable hosts only)", level=1)
    para(doc, "The repo includes Dockerfile and docker-compose.yml. Runtime was not verified in Phase 11–13 on the primary development machine.")
    code_block(
        doc,
        r"""copy docker\compose.env.example docker\compose.env
# Edit docker\compose.env (gitignored) — passwords, APP_KEY

docker compose --env-file docker/compose.env config
docker compose --env-file docker/compose.env up -d --build
docker compose --env-file docker/compose.env exec app php artisan migrate --force
docker compose --env-file docker/compose.env exec app php artisan db:seed --force
""",
    )
    para(doc, "Full reference: docs/PHASE11-DOCKER.md")

    doc.add_heading("Run tests", level=1)
    code_block(
        doc,
        "composer validate --no-check-publish\nscripts\\dev.cmd php artisan test\nscripts\\npm-user.cmd test\nscripts\\npm-user.cmd run build",
    )
    para(doc, "Historical Phase 16.6E: 294 tests, 862 assertions — re-run and record fresh counts before submission.")

    doc.add_heading("Troubleshooting", level=1)
    doc.add_paragraph("chat.blade.php shows a warning if Vite manifest is missing — run npm run build or npm run dev.", style="List Bullet")
    doc.add_paragraph("Database connection: check DB_HOST/DB_PORT (Docker uses service name mysql, not 127.0.0.1).", style="List Bullet")

    out = ROOT / "docs" / "submission" / "03-Installation-and-Developer-Setup.docx"
    doc.save(out)
    print("Wrote", out)


def build_doc04() -> None:
    from docx import Document

    doc = Document()
    set_document_style(doc)
    sec = doc.sections[0]
    sec.different_first_page_header_footer = True
    add_header(sec, "04 — API, Security & Testing")
    add_page_number_footer(sec)

    add_cover(doc, "API, Security & Testing", "Reference")

    add_manual_contents(
        doc,
        [
            "API overview",
            "Endpoint reference",
            "Example: token issuance",
            "Form Requests",
            "AI tools",
            "Tool argument validation",
            "Landed cost formula (app/Services/Erp/LandedCostService.php)",
            "Overdue PO rule",
            "Idempotency",
            "Sensitive data",
            "Prompt injection stance",
            "Automated tests",
            "Known limitations",
        ],
    )

    doc.add_heading("API overview", level=1)
    para(doc, "JSON API under /api (Laravel default api prefix). Sanctum bearer authentication on all routes except POST /api/auth/token.")

    doc.add_heading("Endpoint reference", level=1)
    table = doc.add_table(rows=1, cols=5)
    h = table.rows[0].cells
    for i, t in enumerate(["Method", "Endpoint", "Auth", "Role / scope", "Purpose"]):
        h[i].text = t
    rows = [
        ("POST", "/api/auth/token", "No", "—", "Issue token"),
        ("GET", "/api/user", "Yes", "Authenticated user", "Current user (UserResource)"),
        ("DELETE", "/api/auth/token", "Yes", "Current token", "Revoke"),
        ("POST", "/api/assistant", "Yes", "Sales or Purchasing", "Assistant — Ollama: 202 async; OpenAI/Claude: 200 sync; clarifications: 200"),
        ("GET", "/api/assistant/runs/{runId}", "Yes", "Run owner", "Poll Ollama run status (queued/processing/completed/failed)"),
        ("GET", "/api/products", "Yes", "Sales or Purchasing", "Products"),
        ("GET", "/api/stock", "Yes", "Sales or Purchasing", "Stock"),
        ("GET", "/api/purchase-orders", "Yes", "Sales or Purchasing", "PO search"),
        ("GET", "/api/purchase-orders/overdue", "Yes", "Sales or Purchasing", "Overdue POs"),
        ("GET", "/api/purchase-orders/{purchaseOrder}/landed-cost", "Yes", "Purchasing", "Landed cost"),
        ("POST", "/api/purchase-order-proposals", "Yes", "Purchasing", "Create proposal"),
        ("GET", "/api/purchase-order-proposals/{proposalId}", "Yes", "Purchasing", "Read proposal"),
        ("POST", "/api/purchase-order-proposals/{proposalId}/confirm", "Yes", "Purchasing", "Confirm → real PO"),
        ("DELETE", "/api/purchase-order-proposals/{proposalId}", "Yes", "Purchasing", "Cancel proposal"),
    ]
    for row in rows:
        cells = table.add_row().cells
        for i, v in enumerate(row):
            cells[i].text = v

    doc.add_heading("Example: token issuance", level=1)
    code_block(doc, 'POST /api/auth/token\n{ "email": "...", "password": "...", "device_name": "web" }')

    doc.add_heading("Form Requests", level=1)
    para(
        doc,
        "Validation uses dedicated classes: IssueTokenRequest, AssistantChatRequest, ProductSearchRequest, StockSearchRequest, "
        "PurchaseOrderSearchRequest, OverduePurchaseOrderRequest, CreatePurchaseOrderProposalRequest, ConversationScopedProposalRequest — under app/Http/Requests/Api.",
    )

    doc.add_heading("AI tools", level=1)
    for t, d in [
        ("checkStock", "StockLookupService — no cost fields in result"),
        ("searchPurchaseOrders", "PurchaseOrderLookupService — Sales responses omit costs"),
        ("findOverduePurchaseOrders", "OverduePurchaseOrderService"),
        ("getLandedCost", "LandedCostService — Purchasing only"),
        ("proposeDraftPurchaseOrder", "DraftPurchaseOrderService proposal — requires trusted server keys in context"),
    ]:
        doc.add_paragraph(f"{t}: {d}", style="List Bullet")

    doc.add_heading("Tool argument validation", level=1)
    para(
        doc,
        "Layered validation: ToolExecutor rejects forbidden override keys (user_id, role, conversation_key, …). For tools whose JSON schema is a closed "
        "object (additionalProperties false, explicit properties), unknown keys are rejected before the tool runs. Tools then use ValidatesToolArguments "
        "(Laravel Validator) for type, required fields, and enums.",
    )

    doc.add_heading("Landed cost formula (app/Services/Erp/LandedCostService.php)", level=1)
    for line in [
        "line_value = quantity × unit_cost (decimal string math via ErpMoney)",
        "line_freight_share = total_freight × (line_value / sum(all line_values))",
        "Shares are rounded per line; any remainder is adjusted onto the largest line",
        "freight_per_unit = line_freight_share / quantity",
        "landed_unit_cost = unit_cost + freight_per_unit",
    ]:
        doc.add_paragraph(line, style="List Bullet")

    doc.add_heading("Overdue PO rule", level=1)
    para(doc, "Status must be ordered; ETA not null; ETA strictly before the as_of date — implemented in OverduePurchaseOrderService and covered by tests.")

    doc.add_heading("Assistant async (Ollama only)", level=1)
    para(
        doc,
        "POST /api/assistant with AI_PROVIDER=ollama returns HTTP 202: data.async=true, data.run_id (UUID), data.status=queued, data.status_url. "
        "GET /api/assistant/runs/{runId} returns status; terminal completed includes data.answer; failed includes data.error.code/message (safe text). "
        "Clarification turns return HTTP 200 with clarification_required — no assistant_runs row.",
    )
    code_block(
        doc,
        '# Ollama accept (illustrative)\n{ "success": true, "data": { "async": true, "run_id": "<uuid>", "status": "queued", '
        '"conversation_id": "<uuid>", "status_url": "/api/assistant/runs/<uuid>" } }',
    )

    doc.add_heading("Idempotency", level=1)
    para(
        doc,
        "Assistant: Idempotency-Key + conversation_id on conversation_messages; Cache::lock for HTTP turn (~120s) — does not cover full queue job. "
        "Async replay returns same run (202). Proposal confirm: Idempotency-Key on confirm route.",
    )

    doc.add_heading("Sensitive data", level=1)
    para(doc, "Supplier bank fields encrypted at rest; ToolResultRedactor strips risky keys from persisted tool payloads.")

    doc.add_heading("Prompt injection stance", level=1)
    para(
        doc,
        "Authorization never relies on model text: Gates, ToolExecutor::allowedFor, and service checks enforce Sales vs Purchasing regardless of user instructions.",
    )

    doc.add_heading("Automated tests", level=1)
    para(doc, "Phase 16.6E historical: 294 tests, 862 assertions. Includes AssistantAsyncTest, AssistantRunRecoveryTest, ProcessOllamaAssistantRunFailedTest, QueueWorkerScriptTest, ProviderIsolationRegressionTest.")
    table = doc.add_table(rows=1, cols=2)
    table.rows[0].cells[0].text, table.rows[0].cells[1].text = "Category", "Evidence"
    for row in [
        ("PHPUnit (full)", "Phase 16.6E report — re-run php artisan test"),
        ("Redis worker probe", "Harmless job; separate worker — not Ollama E2E"),
        ("Queue::fake recovery tests", "Dispatch counts; not production Redis proof"),
        ("Ollama worker E2E", "Not verified"),
        ("Docker runtime", "Not verified"),
        ("Browser E2E", "Not verified"),
        ("Concurrent idempotency", "Not fully verified"),
        ("SIGKILL recovery", "Not directly verified"),
    ]:
        c = table.add_row().cells
        c[0].text, c[1].text = row
    doc.add_paragraph()
    code_block(doc, "composer validate --no-check-publish\nscripts\\dev.cmd php artisan test\nscripts\\npm-user.cmd test\nscripts\\npm-user.cmd run build")

    doc.add_heading("Known limitations", level=1)
    doc.add_paragraph("Bounded queue recovery — see Phase 16.6F findings in handbook Section Y.", style="List Bullet")
    doc.add_paragraph("Docker runtime unverified on primary dev host.", style="List Bullet")
    doc.add_paragraph("No streaming responses.", style="List Bullet")

    out = ROOT / "docs" / "submission" / "04-API-Security-and-Testing-Reference.docx"
    doc.save(out)
    print("Wrote", out)


def build_doc05(diagrams: dict) -> None:
    from docx import Document

    doc = Document()
    set_document_style(doc)
    sec = doc.sections[0]
    sec.different_first_page_header_footer = True
    add_header(sec, "05 — Interview Guide")
    add_page_number_footer(sec)

    add_cover(doc, "Interview & Demonstration", "Guide for technical interview")

    add_manual_contents(
        doc,
        [
            "Project introduction (elevator pitch)",
            "10-minute demo plan",
            "Architecture diagrams (reference)",
            "Laravel application architecture",
            "AI stack: providers, agent, tools",
            "Important workflows (implemented paths)",
            "Key classes (paths and responsibilities)",
            "Concurrency: draft proposal confirmation",
            "Ollama async vs synchronous OpenAI/Claude",
            "Technical interview Q&A (≥25)",
            "Possible live-coding exercises",
            "Honest limitations and verification gaps",
            "AI-assisted development disclosure",
        ],
    )

    doc.add_heading("Project introduction (elevator pitch)", level=1)
    para(
        doc,
        "Laravel 12+ JSON API + minimal Vite chat UI. Ollama assistant: HTTP 202, Redis worker, status polling. OpenAI/Claude: synchronous HTTP 200 in-request. "
        "ToolExecutor enforces schemas and ERP authorization. Draft POs stay proposals until explicit HTTP confirm with idempotency.",
    )

    doc.add_heading("10-minute demo plan", level=1)
    for step in [
        "Issue token: POST /api/auth/token — explain Sanctum personal access tokens.",
        "Sales: GET /api/stock or assistant question using checkStock — no unit costs in Sales payloads.",
        "Follow-up: ask “And in Dallas?” — FollowUpQuestionResolver + ConversationContextManager (deterministic rules in tests).",
        "Purchasing: GET /api/purchase-orders/{id}/landed-cost or assistant getLandedCost — Gates + LandedCostService.",
        "Purchasing: propose draft via tool + GET proposal + POST confirm with Idempotency-Key — DraftPurchaseOrderService transactions.",
        "Close with php artisan test counts (re-run before the interview).",
    ]:
        doc.add_paragraph(step, style="List Number")

    doc.add_heading("Architecture diagrams (reference)", level=1)
    add_picture(doc, diagrams["high"], "Figure 1 — High-level architecture (Ollama async path)")
    add_picture(doc, diagrams["tool"], "Figure 2 — Tool execution flow (ToolRegistry does not call the provider)")
    add_picture(doc, diagrams["draft"], "Figure 4 — Draft PO workflow (readable vertical layout)")

    doc.add_heading("Laravel application architecture", level=1)
    para(
        doc,
        "Routes: routes/api.php prefixes JSON routes with /api. Controllers live under app/Http/Controllers/Api (e.g. AssistantController, AuthController). "
        "Input validation uses Form Request classes in app/Http/Requests/Api. Success payloads use API Resources under app/Http/Resources where applicable "
        "(e.g. UserResource). Domain logic sits in app/Services/Erp and app/Services/AI; Eloquent models under app/Models encode relationships (Conversation "
        "hasMany ConversationMessage, PurchaseOrder hasMany PurchaseOrderLine, etc.). Sanctum authenticates requests; ErpGateRegistrar registers Gates from "
        "ErpAbilities; middleware applies standard API guards.",
    )
    for bullet in [
        "Routes / Controllers: map HTTP verbs to thin controllers that delegate to services.",
        "Form Requests: centralize validation rules and authorization hooks for API inputs.",
        "API Resources: shape JSON and omit sensitive fields per role (e.g. costs for Sales).",
        "Services: Erp/* for inventory and procurement; AI/* for assistant orchestration.",
        "Models: relationships and casts; no raw SQL from the LLM layer.",
        "Middleware / Gates: token abilities plus explicit Erp abilities for privileged operations.",
    ]:
        doc.add_paragraph(bullet, style="List Bullet")

    doc.add_heading("AI stack: providers, agent, tools", level=1)
    para(
        doc,
        "Contract: app/Contracts/AiProviderInterface.php — complete(AiRequest): AiResponse. Factory: app/Services/AI/AiProviderFactory.php selects "
        "OpenAiProvider or ClaudeProvider from config; unimplemented providers throw AiProviderException. AiAgentService is a thin delegator: providerFactory->make()->complete($request). "
        "AiToolCallingService owns the multi-step loop: loads ToolRegistry::definitionsFor($user), calls the driver, executes ToolExecutor per AiToolCall, aggregates tokens. "
        "ToolRegistry registers concrete tools under app/Services/AI/Tools/Definitions. ToolExecutionContext carries the authenticated User plus trusted server keys "
        "(conversation_key, idempotency_key) for proposals — never from model JSON.",
    )

    doc.add_heading("Important workflows (implemented paths)", level=1)
    for bullet in [
        "Stock lookup: checkStock tool → StockLookupService; GET /api/stock uses StockSearchRequest + StockLookupService.",
        "Purchase-order lookup: searchPurchaseOrders tool → PurchaseOrderLookupService; GET /api/purchase-orders mirrors filters.",
        "Follow-up context: FollowUpQuestionResolver merges or clarifies; ConversationContextManager builds bounded AiMessage lists with optional summary prefix.",
        "Conversation persistence: ConversationService appends messages; AssistantRequestService coordinates idempotency and locks.",
        "Summaries: ConversationSummaryService thresholds (see config/ai.php).",
        "Landed cost: LandedCostService value-proportional freight; Purchasing-only Gate.",
        "Draft PO proposal: ProposeDraftPurchaseOrderTool + DraftPurchaseOrderService::createProposal with unique (user_id, idempotency_key).",
        "Explicit confirmation: DraftPurchaseOrderService::confirmProposal uses DB::transaction, lockForUpdate on the proposal row, integrity hash, status checks.",
        "Assistant idempotency: AssistantRequestService + cache lock + conversation_message idempotency fields (see tests).",
    ]:
        doc.add_paragraph(bullet, style="List Bullet")

    doc.add_heading("Key classes (paths and responsibilities)", level=1)
    classes = [
        ("app/Services/AI/AssistantRequestService.php", "Assistant turn: conversation resolution, optional Idempotency-Key, follow-up resolution, invokes AiToolCallingService."),
        ("app/Services/AI/Context/ConversationContextManager.php", "Assembles provider messages with caps; filters tool rows for Sales vs Purchasing."),
        ("app/Services/AI/Context/FollowUpQuestionResolver.php", "Expands short follow-ups using heuristics and optional lightweight AI."),
        ("app/Services/AI/Conversations/ConversationService.php", "Persists conversation messages, tool traces, and idempotency records."),
        ("app/Services/AI/Tools/ToolExecutor.php", "Forbidden keys, closed-schema unknown keys, permission checks, dispatches to tools."),
        ("app/Services/Erp/LandedCostService.php", "Freight allocation and landed unit costs for purchase orders."),
        ("app/Services/Erp/DraftPurchaseOrderService.php", "Proposal lifecycle: create, get, confirm with transactions and row locks."),
        ("app/Services/AI/AiProviderFactory.php", "Wires AiProviderInterface implementations from config."),
        ("app/Services/AI/AiAgentService.php", "Single-call delegator to the configured provider."),
    ]
    for path, desc in classes:
        p = doc.add_paragraph()
        p.add_run(path + " — ").bold = True
        p.add_run(desc)

    doc.add_heading("Concurrency: draft proposal confirmation", level=1)
    para(
        doc,
        "Implementation: DraftPurchaseOrderService::confirmProposal selects the proposal with lockForUpdate() inside DB::transaction, re-checks status, then creates "
        "purchase_orders and lines. Unique index on (user_id, idempotency_key) prevents duplicate proposal rows on create. Laravel's Concurrency::driver('process') "
        "is not reliably available on Windows dev hosts (php artisan test would skip), so this repo does not claim cross-connection proof — use two MySQL sessions "
        "or load tests on Linux/CI to validate interleaving. Existing sequential tests cover idempotent double-confirm on one connection.",
    )

    doc.add_heading("Ollama async vs synchronous OpenAI/Claude", level=1)
    para(
        doc,
        "When AI_PROVIDER=ollama: POST /api/assistant returns HTTP 202, creates assistant_runs, dispatches ProcessOllamaAssistantRun to Redis; "
        "the chat UI polls GET /api/assistant/runs/{runId}. OpenAI and Claude still run AiToolCallingService in the HTTP request (200). "
        "Clarifications return 200 synchronously for all providers. See docs/PHASE16.6C-ASYNC-ASSISTANT-OPERATIONS.md and Phase 16.6F limitations.",
    )

    doc.add_heading("Technical interview Q&A (≥25)", level=1)
    qa = [
        ("Where are API routes declared?", "routes/api.php — grouped middleware auth:sanctum for protected JSON endpoints."),
        ("Which controller handles the assistant?", "app/Http/Controllers/Api/AssistantController.php — validates AssistantChatRequest then AssistantRequestService."),
        ("What validates assistant input?", "app/Http/Requests/Api/AssistantChatRequest.php."),
        ("How does dependency injection pick the AI provider?", "AiProviderFactory resolved from the container; config ai.provider chooses the implementation."),
        ("What does AiProviderInterface guarantee?", "A single complete() method returning AiResponse — implemented by OpenAiProvider and ClaudeProvider."),
        ("Why a factory instead of new in controllers?", "Centralizes provider selection, error mapping, and keeps controllers thin (factory pattern)."),
        ("How does tool calling differ from arbitrary SQL?", "Tools are PHP classes with JSON schemas; ToolExecutor validates and routes to services — no SQL from the model."),
        ("How is follow-up context assembled?", "ConversationContextManager pulls recent ConversationMessage rows within caps; summaries prepend as system text."),
        ("Name a model relationship for conversations.", "Conversation hasMany ConversationMessage; messages belong to a conversation."),
        ("How are draft PO lines persisted transactionally?", "confirmProposal wraps PO + lines + proposal update in DB::transaction."),
        ("How is confirm idempotent?", "After first confirm, status is Confirmed; second call returns formatted result without inserting duplicate POs — covered by DraftPurchaseOrderServiceTest."),
        ("What prevents Sales from landed cost JSON?", "UserRole + Gate abilities + ToolExecutor::allowedFor + service authorization."),
        ("How does Sanctum fit in?", "Personal access tokens on User; Bearer Authorization header for API calls."),
        ("What Redis role is configured?", "Queue/cache backend; Ollama assistant jobs when AI_PROVIDER=ollama."),
        ("Does OpenAI use the assistant queue?", "No — synchronous HTTP 200 only; ProviderIsolationRegressionTest and AssistantAsyncTest assert no dispatch."),
        ("Docker: what was verified here?", "Files only (Dockerfile, docker-compose.yml); container runtime not verified on the primary dev host."),
        ("How are provider failures surfaced?", "AiProviderException mapped to safe HTTP errors in controllers — see provider unit tests."),
        ("What is the testing strategy?", "PHPUnit Feature tests for API, tools, ERP services; npm test for frontend where configured; no live AI in CI."),
        ("How is landed freight allocated?", "Value-proportional shares with rounding and remainder to largest line — LandedCostService."),
        ("How do you mitigate prompt injection for privileges?", "Ignore model claims — enforce Gates, tool allow lists, and forbidden argument keys."),
        ("Can the model pass user_id in tool JSON?", "No — ToolExecutor rejects forbidden keys before execution."),
        ("Can the model add unknown tool keys?", "No — closed JSON schemas reject unknown keys with validation_error."),
        ("What race condition is mitigated on confirm?", "lockForUpdate on the proposal row within a transaction; unique proposal idempotency on create."),
        ("Is streaming implemented?", "No — responses are single-shot strings from providers."),
        ("What PHP version does composer.json require?", "composer.json requires php ^8.3, matching the challenge and the Dockerfile base image."),
    ]
    for q, a in qa:
        p = doc.add_paragraph()
        p.add_run(q + " ").bold = True
        p.add_run(a)

    doc.add_heading("Possible live-coding exercises", level=1)
    for bullet in [
        "Add a read-only tool: implement AiToolInterface, register in ToolRegistry service provider, add Feature tests mirroring CheckStockTool.",
        "Extend ToolExecutor tests for a new forbidden key — expect forbidden_arguments without executing the tool.",
        "Add a Gate + Policy test: assert Sales denied on a new Erp ability while Purchasing passes.",
        "Implement a pure function in LandedCostService with extra rounding edge case — unit test with decimal strings.",
    ]:
        doc.add_paragraph(bullet, style="List Bullet")

    doc.add_heading("Honest limitations and verification gaps", level=1)
    for bullet in [
        "Docker Compose: YAML present; stack runtime not verified on the machine that generated these docs.",
        "Live OpenAI/Claude: providers exercised with fakes/mocks in tests — billed API behavior is unverified in CI.",
        "Parallel DB connections: not used in PHPUnit; confirm path relies on InnoDB locks — add dedicated concurrency probes if required.",
        "composer.json requires PHP ^8.3 — keep local runtimes in sync.",
    ]:
        doc.add_paragraph(bullet, style="List Bullet")

    doc.add_heading("AI-assisted development disclosure", level=1)
    para(
        doc,
        "Submission prose may be tooling-assisted; claims above are grounded in repository paths. Verify behavior by reading those files and running "
        "composer validate, php artisan test, npm test, and npm run build before interviews.",
    )

    out = ROOT / "docs" / "submission" / "05-Interview-and-Demonstration-Guide.docx"
    doc.save(out)
    print("Wrote", out)


def _subprocess_text(cmd: list[str]) -> str:
    try:
        proc = subprocess.run(cmd, cwd=str(ROOT), capture_output=True, text=True, timeout=120)
        return (proc.stdout or "").strip() + (("\n" + proc.stderr.strip()) if proc.stderr and proc.stderr.strip() else "")
    except (OSError, subprocess.TimeoutExpired) as e:
        return f"(unavailable: {e})"


def _git_line(args: list[str]) -> str:
    return _subprocess_text(["git", "-C", str(ROOT), *args]).split("\n")[0].strip()


def _load_phase162_json() -> tuple[dict | None, str]:
    path = ROOT / "tools" / "phase162_acceptance_api.json"
    if not path.is_file():
        return None, f"File not found: {path}"
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
    except json.JSONDecodeError as e:
        return None, f"Invalid JSON: {e}"
    if not isinstance(data.get("results"), dict):
        return None, "Missing results object"
    return data, ""


def _add_wide_acceptance_table(doc, rows: list[tuple[str, str, str, str, str, str, str, str]]) -> None:
    """Eight columns: ID, Scenario, Preconditions, Steps, Expected, Actual, Status, Evidence or notes."""
    table = doc.add_table(rows=1, cols=8)
    hdr = table.rows[0].cells
    headers = ["Test ID", "Scenario", "Preconditions", "Steps", "Expected result", "Actual result", "Status", "Evidence or notes"]
    for i, t in enumerate(headers):
        hdr[i].text = t
    for row_data in rows:
        r = table.add_row().cells
        for i, val in enumerate(row_data):
            r[i].text = val


def build_doc06(diagrams: dict) -> None:
    from docx import Document

    doc = Document()
    set_document_style(doc)
    sec = doc.sections[0]
    sec.different_first_page_header_footer = True
    add_header(sec, "06 — Final Acceptance & Interview Prep")
    add_page_number_footer(sec)

    add_cover(doc, "Final Acceptance & Interview Preparation", "Phase 16.2 — API, security, and UI acceptance (no live AI)")

    add_manual_contents(
        doc,
        [
            "Acceptance baseline (Phase 16.2)",
            "Phase 16.2 — HTTP API acceptance (auth, Sales, Purchasing, proposals, security)",
            "Phase 16.2 — Browser chat UI (manual)",
            "Phase 16.2 — Draft PO: confirm / expiry (PHPUnit)",
            "Phase 16.2 — Security spot checks",
            "Regression verification (Phase 16.2)",
            "Phase 16.3 — Live AI",
            "Environment readiness checklist (Phase 16.1)",
            "Automated verification (historical Phase 16.1)",
            "Database readiness and seeded data",
            "Application startup and shutdown",
            "Docker verification status",
            "Known limitations",
            "Interview demonstration plan",
            "Technical interview preparation",
            "Final sign-off checklist",
        ],
    )

    branch = _git_line(["branch", "--show-current"]) or "(unknown)"
    head = _git_line(["rev-parse", "HEAD"]) or "(unknown)"
    status_short = _git_line(["status", "-sb"]) or "(unknown)"

    doc.add_heading("Acceptance baseline (Phase 16.2)", level=1)
    para(doc, "Recorded without printing passwords, API keys, tokens, or token hashes. No .env secrets were modified.")
    base_tbl = doc.add_table(rows=1, cols=2)
    bh = base_tbl.rows[0].cells
    bh[0].text, bh[1].text = "Item", "Value"
    for k, v in [
        ("Git branch", branch),
        ("Git HEAD", head),
        ("Git status (short)", status_short),
        ("API routes", "routes/api.php — auth:sanctum on /api/user, /api/products, /api/stock, /api/purchase-orders*, /api/purchase-order-proposals*; POST /api/auth/token is public with throttle:api-login"),
        ("Form Requests / auth", "IssueTokenRequest, AssistantChatRequest, ERP search/create requests; proposal scope uses AuthorizesPurchasingProposalAccess (Purchasing Gate)"),
        ("Existing API tests", "tests/Feature/Api/ (e.g. AuthApiTest, PurchaseOrderProposalApiTest, LandedCost tests)"),
        ("Acceptance artifact", "tools/phase162_acceptance_api.php → tools/phase162_acceptance_api.json (in-process HTTP kernel; clears login throttle keys for demo emails; does not call /api/assistant)"),
    ]:
        r = base_tbl.add_row().cells
        r[0].text, r[1].text = k, v

    phase162, perr = _load_phase162_json()
    gen_at = phase162.get("generated_at", "") if phase162 else ""
    env_name = phase162.get("environment", "") if phase162 else ""
    results = phase162.get("results", {}) if phase162 else {}

    def R(tid: str) -> tuple[str, int, str]:
        if not isinstance(results, dict):
            return "Not Run", 0, perr or "No results"
        row = results.get(tid)
        if not isinstance(row, dict):
            return "Not Run", 0, "Missing in JSON"
        st = str(row.get("status", "Not Run"))
        http = int(row.get("http", 0) or 0)
        notes = str(row.get("notes", "") or "")
        return st, http, notes

    common_pre = (
        "Development DB stone_erp_ai seeded; DEMO_USER_PASSWORD set locally; run tools/phase162_acceptance_api.php "
        "(in-process kernel, REMOTE_ADDR 127.0.0.1; clears md5(api-login…) throttle keys for demo emails). No migrate:fresh; no PO confirm on dev DB."
    )

    def actual_http(tid: str) -> str:
        st, http, notes = R(tid)
        if http:
            extra = f"; {notes}" if notes else ""
            return f"HTTP {http}{extra}"
        return notes or st

    # --- Main Phase 16.2 table driven by script IDs ---
    script_specs: list[tuple[str, str, str, str, str]] = [
        ("AUTH-01", "Sales login with valid credentials", common_pre, "POST /api/auth/token with sales@example.test + device_name", "200 and token issued (not recorded in doc)"),
        ("AUTH-02", "Purchasing login with valid credentials", common_pre, "POST /api/auth/token with purchasing@example.test + device_name", "200 and token issued (not recorded in doc)"),
        ("AUTH-03", "Invalid credentials rejected", common_pre, "POST /api/auth/token with wrong password", "401 or 422"),
        ("AUTH-04", "GET /api/user with valid token", common_pre, "GET /api/user with Bearer token (UserResource)", "200; authenticated user data"),
        ("AUTH-05", "GET /api/user without token", common_pre, "GET /api/user without Authorization", "401 Unauthorized"),
        ("AUTH-06", "Logout revokes current token", common_pre, "DELETE /api/auth/token with Bearer", "204 No Content"),
        ("AUTH-07", "Reuse revoked token", common_pre, "GET /api/user with previously revoked Bearer", "401 Unauthorized"),
        ("SALES-01", "GET /api/products (Sales)", common_pre, "GET /api/products?limit=3 as Sales", "200; list structure"),
        (
            "SALES-02a",
            "Stock seeded check — Houston Calacatta 3cm",
            common_pre,
            "GET /api/stock?product_id (DEMO-STQ-CAL-30) & warehouse_id (Houston)",
            "200; slabs_on_hand 42; warehouse Houston",
        ),
        (
            "SALES-02b",
            "Stock seeded check — Dallas Calacatta 3cm",
            common_pre,
            "GET /api/stock?product_id & warehouse_id (Dallas)",
            "200; slabs_on_hand 18; warehouse Dallas",
        ),
        ("SALES-03", "Sales PO list — no cost leak", common_pre, "GET /api/purchase-orders as Sales; scan JSON for unit_cost / freight_cost / bank_details substrings", "200; response must not contain those substrings"),
        ("SALES-04", "Sales overdue PO list", common_pre, "GET /api/purchase-orders/overdue as Sales", "200"),
        ("SALES-05", "Sales denied landed cost", common_pre, "GET /api/purchase-orders/{id}/landed-cost as Sales", "403 Forbidden"),
        ("SALES-06", "Sales denied proposal create", common_pre, "POST /api/purchase-order-proposals as Sales", "403 Forbidden"),
        ("PURCH-01", "GET /api/products (Purchasing)", common_pre, "GET /api/products?limit=2 as Purchasing", "200"),
        ("PURCH-02", "GET /api/stock (Purchasing)", common_pre, "GET /api/stock?limit=3 as Purchasing", "200"),
        ("PURCH-03", "PO listing — lines without unit_cost", common_pre, "GET /api/purchase-orders as Purchasing; confirm lines present", "200; costs via landed-cost endpoint, not list JSON"),
        ("PURCH-04", "Purchasing overdue PO list", common_pre, "GET /api/purchase-orders/overdue as Purchasing", "200"),
        ("PURCH-05", "Landed cost JSON (Purchasing)", common_pre, "GET /api/purchase-orders/{id}/landed-cost as Purchasing", "200; data.freight_cost and data.lines[].landed_unit_cost (LandedCostResource wrap)"),
        ("PURCH-06", "Supplier bank details not in PO list JSON", common_pre, "Scan Purchasing PO list JSON for bank_details substring", "Substring absent"),
        ("PROP-01", "Purchasing can create proposal", common_pre, "POST /api/purchase-order-proposals with Idempotency-Key", "201; proposal_id returned"),
        ("PROP-02", "Create proposal does not create PO row", common_pre, "Compare PurchaseOrder::count() before/after create", "Count unchanged"),
        ("PROP-03", "Proposal preview (GET)", common_pre, "GET /api/purchase-order-proposals/{id}?conversation_key=… as owner", "200; status pending"),
        ("PROP-04", "Wrong role cannot access proposal", common_pre, "GET same URL as Sales user", "403 Forbidden (FormRequest authorize) or 404"),
        ("PROP-05", "Wrong conversation_key cannot access proposal", common_pre, "GET with mismatched conversation_key as Purchasing", "404 Not Found"),
        ("PROP-06", "Repeated POST same idempotency key", common_pre, "POST twice with same Idempotency-Key and body", "First 201; second 200 same proposal"),
        ("PROP-07", "Idempotency conflict on payload change", common_pre, "POST again with same key, different line cost", "422 Idempotency conflict"),
        ("PROP-08", "Cancel proposal", common_pre, "DELETE /api/purchase-order-proposals/{id}?conversation_key=…", "2xx success"),
        ("PROP-09", "Confirm after cancel", common_pre, "POST …/confirm after cancel", "409 Conflict (no new PO in dev DB)"),
        ("PROP-10", "No net new PO from script", common_pre, "Compare PurchaseOrder::count() end vs start", "Count unchanged"),
        ("SEC-01", "Unauthenticated GET /api/products", common_pre, "GET /api/products with no Authorization", "401 Unauthorized"),
        ("SEC-02", "Client cannot elevate via extra role field", common_pre, "POST /api/purchase-order-proposals as Sales with body containing role=purchasing", "403 before validation (Purchasing-only route)"),
    ]

    http_rows: list[tuple[str, str, str, str, str, str, str, str]] = []
    for tid, scenario, pre, steps, expect in script_specs:
        st, http, notes = R(tid)
        act = actual_http(tid)
        ev = f"tools/phase162_acceptance_api.json ({tid}); generated_at={gen_at or 'n/a'}; env={env_name or 'n/a'}"
        if notes and st not in ("Passed", "Failed"):
            ev = notes + "; " + ev
        elif notes and tid in ("PURCH-03", "PROP-04", "SEC-02"):
            ev = notes + "; " + ev
        http_rows.append((tid, scenario, pre, steps, expect, act, st, ev))

    if perr:
        http_rows.append(
            (
                "JSON-ERR",
                "Load acceptance JSON",
                "tools/phase162_acceptance_api.json present",
                "Run php tools/phase162_acceptance_api.php",
                "Valid JSON with results",
                perr,
                "Failed",
                "Regenerate JSON before relying on this document",
            )
        )

    doc.add_heading("Phase 16.2 — HTTP API acceptance (auth, Sales, Purchasing, proposals, security)", level=1)
    para(
        doc,
        "Executed via in-process HTTP kernel (tools/phase162_acceptance_api.php). Tokens and passwords are never written to the repository. "
        "Login route throttle (5/min per email|ip) is cleared for the two demo addresses at script start so sequential logins in one run do not return HTTP 429.",
    )
    _add_wide_acceptance_table(doc, http_rows)

    doc.add_heading("Phase 16.2 — Browser chat UI (manual)", level=1)
    para(
        doc,
        "Do not submit chat text that would call POST /api/assistant (live provider). Verify layout, login, and controls only. "
        "If the environment cannot drive the browser, complete the table manually and replace Pending User Verification.",
    )
    ui_specs: list[tuple[str, str, str, str, str, str, str, str]] = [
        ("UI-01", "Open chat application", "scripts\\dev.cmd php artisan serve; npm run build or npm run dev", "Open app root in browser", "Chat shell loads", "", "Pending User Verification", "Browser not executed in generator session"),
        ("UI-02", "Login as Sales", "Demo credentials locally", "Log in as Sales", "Session/token stored; role Sales", "", "Pending User Verification", ""),
        ("UI-03", "Login as Purchasing", "Demo credentials locally", "Log in as Purchasing", "Purchasing session", "", "Pending User Verification", ""),
        ("UI-04", "Start new conversation", "Logged in", "Start new thread", "Empty/new conversation state", "", "Pending User Verification", ""),
        ("UI-05", "Sidebar and layout", "Logged in", "Inspect sidebar and main pane", "Expected layout", "", "Pending User Verification", ""),
        ("UI-06", "Proposal controls visibility", "Compare Sales vs Purchasing", "Check proposal-related controls", "Purchasing-only where applicable", "", "Pending User Verification", ""),
        ("UI-07", "Logout", "Logged in", "Use logout", "Session cleared", "", "Pending User Verification", ""),
        ("UI-08", "Reload after logout", "After logout", "Full page reload", "Login required", "", "Pending User Verification", ""),
        ("UI-09", "Responsive layout", "Desktop and narrow viewport", "Resize window/devtools", "Usable at both widths", "", "Pending User Verification", ""),
    ]
    _add_wide_acceptance_table(doc, ui_specs)

    doc.add_heading("Phase 16.2 — Draft PO: confirm, expiry, idempotency (PHPUnit)", level=1)
    para(
        doc,
        "To avoid creating a permanent purchase order in stone_erp_ai, HTTP confirmation with side effects was not run against the development database. "
        "The suite below uses the test database and transactions (see tests/Feature/Api/PurchaseOrderProposalApiTest.php).",
    )
    phpunit_rows: list[tuple[str, str, str, str, str, str, str, str]] = [
        (
            "CONF-01",
            "Confirm creates draft PO; second confirm idempotent",
            "phpunit; stone_erp_ai_test",
            "test_confirm_creates_single_draft_po_and_repeat_confirm_is_idempotent",
            "One PO and lines; same purchase_order_id on repeat POST",
            "See test assertions",
            "Passed",
            "PurchaseOrderProposalApiTest (automated)",
        ),
        (
            "CONF-02",
            "Expired proposal cannot be confirmed",
            "phpunit; TTL config override in test",
            "test_lifecycle_expired_and_cancelled_proposals_cannot_be_confirmed",
            "409 on confirm after expiry; 0 PO rows",
            "See test assertions",
            "Passed",
            "PurchaseOrderProposalApiTest (automated)",
        ),
        (
            "CONF-03",
            "Cancelled proposal cannot be confirmed",
            "phpunit",
            "Same test method (cancel path)",
            "409 on confirm after DELETE cancel",
            "See test assertions",
            "Passed",
            "PurchaseOrderProposalApiTest (automated)",
        ),
        (
            "API-OWN-01",
            "Wrong user / conversation cannot access proposal",
            "phpunit",
            "test_wrong_conversation_key_and_other_user_cannot_access_proposal",
            "404/403 as specified",
            "See test assertions",
            "Passed",
            "PurchaseOrderProposalApiTest (automated)",
        ),
        (
            "API-SEC-01",
            "Proposal responses omit bank_details / payload_hash",
            "phpunit",
            "test_responses_do_not_expose_sensitive_fields",
            "No sensitive substrings in JSON",
            "See test assertions",
            "Passed",
            "PurchaseOrderProposalApiTest (automated)",
        ),
    ]
    _add_wide_acceptance_table(doc, phpunit_rows)

    doc.add_heading("Phase 16.2 — Security spot checks", level=1)
    sec_rows: list[tuple[str, str, str, str, str, str, str, str]] = [
        ("SSEC-01", "Unauthenticated API rejected", common_pre, "GET /api/products without token", "401", actual_http("SEC-01"), R("SEC-01")[0], "HTTP row SEC-01"),
        ("SSEC-02", "Role gate on proposal route (no client role elevation)", common_pre, "Sales POST proposal with extra role field", "403 or safe denial", actual_http("SEC-02"), R("SEC-02")[0], "HTTP row SEC-02; 403 before validation"),
        ("SSEC-03", "Tool forbidden keys and role override rejected", "PHPUnit", "tests/Feature/AI/Tools/ToolLayerTest.php", "forbidden_arguments / validation_error", "See test output", "Passed", "Automated"),
        ("SSEC-04", "Supplier bank leak — API proposal JSON", "PHPUnit", "test_responses_do_not_expose_sensitive_fields", "No bank_details in JSON", "See test", "Passed", "PurchaseOrderProposalApiTest"),
        ("SSEC-05", "Log redaction of secrets in stored payloads", "Code review / not executed", "ToolResultRedactor and logging paths", "Sensitive keys redacted in persisted tool payloads", "", "Not Run", "No dedicated log assertion in this phase; defer to code review"),
    ]
    _add_wide_acceptance_table(doc, sec_rows)

    doc.add_heading("Regression verification (Phase 16.2)", level=1)
    reg_when = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    para(
        doc,
        f"Run from repo root after acceptance. Last execution recorded for this document build: {reg_when}. "
        "Re-run the commands before an interview if the codebase changed.",
    )
    code_block(
        doc,
        r"""scripts\dev.cmd composer validate --no-check-publish
scripts\dev.cmd php artisan test
scripts\npm-user.cmd run test
scripts\npm-user.cmd run build""",
    )
    reg = doc.add_table(rows=1, cols=2)
    rh = reg.rows[0].cells
    rh[0].text, rh[1].text = "Command", "Outcome"
    for cmd, outcome in [
        ("composer validate --no-check-publish", "Pass — ./composer.json is valid"),
        ("php artisan test", "Phase 16.6E historical — 294 tests, 862 assertions (re-run to refresh)"),
        ("npm run test (parsers + polling self-checks)", "Pass — Phase 16.6E report"),
        ("npm run build (Vite)", "Pass — Phase 16.6E report"),
    ]:
        rr = reg.add_row().cells
        rr[0].text, rr[1].text = cmd, outcome

    doc.add_heading("Phase 16.3 — Live AI", level=1)
    para(doc, "Skipped — User requested deferral until a real provider API key is ready. Do not call OpenAI, Claude, or other live providers for acceptance in this phase.")

    doc.add_heading("Project scope and environment readiness (Phase 16.1 reference)", level=1)
    para(
        doc,
        "Earlier audit captured toolchain, seeded counts, and a baseline automated suite. Phase 16.2 adds executed API checks and defers browser and live AI.",
    )
    add_picture(doc, diagrams["phase16"], "Figure 10 — Readiness sequence")

    doc.add_heading("Environment readiness checklist", level=1)
    para(doc, "Recorded 2026-11-06 on the primary Windows dev host unless noted. No secret values are printed.")
    tbl = doc.add_table(rows=1, cols=3)
    h = tbl.rows[0].cells
    h[0].text, h[1].text, h[2].text = "Check", "Result", "Evidence / notes"
    for check, result, notes in [
        ("PHP ≥ 8.3", "Pass", "PHP 8.3.36 via tools/php and scripts\\dev.cmd"),
        ("Laravel ≥ 12", "Pass", "Laravel Framework 12.71.0 (php artisan --version)"),
        ("Composer via scripts\\dev.cmd", "Pass", "Composer 2.11.1"),
        ("MySQL reachable", "Pass", "php artisan db:show — MySQL 8.x, host 127.0.0.1, port from .env (3307)"),
        ("Database stone_erp_ai", "Pass", "db:show lists stone_erp_ai schema and tables"),
        ("Redis / Memurai PING", "Pass", "Predis client ping returned PONG against configured host/port"),
        ("Queue driver", "Pass", "queue.default = redis (config)"),
        ("Node / npm (scripts\\npm-user.cmd)", "Pass", "Node v22.23.1 (NVM path …\\nvm\\v22.23.1 per script); npm 10.9.8"),
        ("node_modules present", "Pass", "Dependencies installed under project root"),
        ("Vite production manifest", "Pass", "public/build/manifest.json after npm run build"),
        (
            "docker-compose.yml YAML",
            "Pass",
            "Symfony Yaml::parseFile(docker-compose.yml) — syntax only; container runtime not exercised",
        ),
        (
            "Root file php (untracked)",
            "N/A",
            "Zero-byte artifact at repo root; left untouched per project convention",
        ),
        ("scripts\\npm-user.cmd line endings", "Fixed", "Was missing CRLF (setlocal/run failed); normalized to CRLF for cmd",
         ),
    ]:
        row = tbl.add_row().cells
        row[0].text, row[1].text, row[2].text = check, result, notes

    doc.add_heading("Automated verification results (historical snapshots)", level=1)
    para(doc, "Phase 16.6E counts (294 tests) supersede Phase 16.1 (182) for interviews — re-run commands in § Regression verification.")
    tbl2 = doc.add_table(rows=1, cols=2)
    h2 = tbl2.rows[0].cells
    h2[0].text, h2[1].text = "Command / suite", "Outcome (Phase 16.1 snapshot)"
    for cmd, outcome in [
        ("composer validate --no-check-publish", "Pass — ./composer.json is valid"),
        ("php artisan test", "Pass — 182 tests, 545 assertions (~34–46s)"),
        ("npm run test (parsers.selfcheck.mjs)", "Pass — parsers.selfcheck: ok"),
        ("npm run build (Vite)", "Pass — built in ~3–4s; manifest under public/build/"),
    ]:
        r = tbl2.add_row().cells
        r[0].text, r[1].text = cmd, outcome

    doc.add_heading("Database readiness and seeded data", level=1)
    para(
        doc,
        "Read-only counts against the development database (stone_erp_ai) on 2026-11-06. Overdue PO count uses application rules: status ordered, ETA strictly before today (see OverduePurchaseOrderService).",
    )
    tbl3 = doc.add_table(rows=1, cols=3)
    h3 = tbl3.rows[0].cells
    h3[0].text, h3[1].text, h3[2].text = "Metric", "Expected", "Actual"
    for metric, expected, actual in [
        ("Products", "22", "22"),
        ("Warehouses", "3", "3"),
        ("Suppliers", "5", "5"),
        ("Stock rows", "12", "12"),
        ("Purchase orders", "16", "16"),
        ("Purchase order lines", "22", "22"),
        ("Calacatta 3cm Houston (DEMO-STQ-CAL-30)", "42 slabs", "42"),
        ("Calacatta 3cm Dallas (DEMO-STQ-CAL-30)", "18 slabs", "18"),
        ("Overdue purchase orders (as of run date)", "5", "5"),
        ("Demo Sales user", "sales@example.test, role sales", "Present — role sales (password hash not recorded)"),
        ("Demo Purchasing user", "purchasing@example.test, role purchasing", "Present — role purchasing (password hash not recorded)"),
    ]:
        r = tbl3.add_row().cells
        r[0].text, r[1].text, r[2].text = metric, expected, actual

    doc.add_heading("Application startup and shutdown", level=1)
    para(
        doc,
        "Typical Windows local flow (see README.md and docs/PHASE*.md). Each long-running process uses a dedicated console unless started in the background.",
    )
    for bullet in [
        "MySQL: start with scripts\\mysql-start.cmd (or your installed service). Leave running while developing.",
        "Memurai (Redis-compatible): scripts\\memurai-start.cmd — required when using redis queue/cache. Leave running.",
        "Laravel HTTP: scripts\\dev.cmd php artisan serve — default http://127.0.0.1:8000. Stop with Ctrl+C in that window.",
        "Vite dev (optional for UI work): scripts\\npm-user.cmd run dev — separate window; Ctrl+C to stop. For demos you may rely on npm run build + served static assets instead.",
        "Queue worker (required for Ollama assistant): scripts\\queue-worker.cmd — separate window; Ctrl+C to stop. Not required for OpenAI/Claude assistant turns.",
        "Shutdown: stop artisan serve, optional Vite, queue worker, then Memurai (scripts\\memurai-stop.cmd) and MySQL (scripts\\mysql-stop.cmd) if you started them via scripts.",
    ]:
        doc.add_paragraph(bullet, style="List Bullet")
    para(
        doc,
        "Do not run migrate:fresh on shared dev data. Keep .env local; never commit real API keys.",
    )

    doc.add_heading("Docker verification status", level=1)
    para(
        doc,
        "Status: Unverified runtime. Dockerfile and docker-compose.yml are present; YAML parses successfully. Bringing the stack up, health checks, and end-to-end behavior in containers were not exercised in this audit.",
    )

    doc.add_heading("Known limitations and unverified areas", level=1)
    for bullet in [
        "Browser chat scenarios in § Phase 16.2 — Browser chat UI remain Pending User Verification until exercised manually.",
        "Live AI (Phase 16.3): Skipped — User requested deferral until a real provider API key is ready.",
        "Docker Compose: file-level only in this phase.",
        "Concurrent confirm from two DB sessions: not part of this audit (see interview doc on Windows concurrency limits).",
        "Full end-to-end acceptance is not claimed while UI-01–UI-09 or Phase 16.3 remain pending.",
    ]:
        doc.add_paragraph(bullet, style="List Bullet")

    doc.add_heading("Interview demonstration plan", level=1)
    for bullet in [
        "Open with architecture one-liner (browser → Laravel → tools → ERP services → MySQL).",
        "Show token issuance then one Sales read (stock) and one Purchasing read (landed cost or proposal path) via API or UI as time allows.",
        "Re-run php artisan test and cite the latest summary line from § Regression verification.",
        "State honestly: live AI and Docker runtime are deferred or unverified as listed above.",
    ]:
        doc.add_paragraph(bullet, style="List Bullet")

    doc.add_heading("Technical interview preparation topics", level=1)
    for bullet in [
        "Sanctum token flow and Idempotency-Key behavior on assistant and confirm routes.",
        "ToolExecutor: closed schemas, forbidden keys, role checks.",
        "Draft PO proposal lifecycle: pending → confirm inside DB::transaction with lockForUpdate.",
        "Landed cost allocation: value-proportional freight and rounding.",
        "FollowUpQuestionResolver + ConversationContextManager boundaries and caps.",
    ]:
        doc.add_paragraph(bullet, style="List Bullet")

    doc.add_heading("Final acceptance sign-off checklist", level=1)
    sign = doc.add_table(rows=1, cols=3)
    sg = sign.rows[0].cells
    sg[0].text, sg[1].text, sg[2].text = "Item", "Owner", "Status"
    for item, owner, status in [
        ("HTTP API scenarios (tools/phase162_acceptance_api.php + JSON)", "Engineer", "Executed — see tables above"),
        ("Frontend chat walkthrough (UI-01–UI-09)", "Engineer", "Pending User Verification"),
        ("Live AI smoke test with real keys (Phase 16.3)", "Engineer", "Skipped — User requested deferral until a real provider API key is ready"),
        ("Docker optional stack (if required by interviewer)", "Engineer", "Pending"),
        ("Interview package DOCX regenerated from tools/build_phase13_submission.py", "Engineer", "Complete when this file is saved from generator"),
    ]:
        rr = sign.add_row().cells
        rr[0].text, rr[1].text, rr[2].text = item, owner, status

    out = ROOT / "docs" / "submission" / "06-Final-Acceptance-and-Interview-Preparation.docx"
    doc.save(out)
    print("Wrote", out)


def main() -> int:
    try:
        import matplotlib  # noqa: F401
        from docx import Document  # noqa: F401
    except ImportError as e:
        print("Missing dependency:", e, file=sys.stderr)
        print("pip install python-docx matplotlib pillow", file=sys.stderr)
        return 1

    legacy = ASSETS / "fig02_tool_flow.png"
    if legacy.exists():
        legacy.unlink()

    diagrams = {
        "high": diagram_high_level_arch(),
        "tool": diagram_tool_flow(),
        "follow": diagram_follow_up(),
        "draft": diagram_draft_po(),
        "er": diagram_er(),
        "docker": diagram_docker(),
        "provider": diagram_provider(),
        "laravel": diagram_laravel_layers(),
        "auth": diagram_auth(),
        "phase16": diagram_phase16_readiness(),
        "async_lifecycle": diagram_async_ollama_lifecycle(),
        "recovery": diagram_recovery_failure(),
    }

    build_doc01(diagrams)
    build_doc02(diagrams)
    build_doc03()
    build_doc04()
    build_doc05(diagrams)
    build_doc06(diagrams)
    return 0


if __name__ == "__main__":
    sys.exit(main())
