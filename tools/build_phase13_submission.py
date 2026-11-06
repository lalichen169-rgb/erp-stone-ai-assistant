#!/usr/bin/env python3
"""
Generate Phase 13 submission DOCX files and embedded diagram PNGs (local matplotlib).
Dependencies: python-docx, matplotlib, pillow
"""
from __future__ import annotations

import sys
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
        "Redis and the queue worker are configured infrastructure. The current assistant request is synchronous and does not dispatch an assistant job.",
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
        "8. Final assistant text + metadata → HTTP JSON response (same worker process — synchronous; no assistant job dispatched).",
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

    fig, ax = plt.subplots(figsize=(8, 2.4))
    ax.axis("off")
    ax.set_xlim(0, 8)
    ax.set_ylim(0, 2.2)
    boxes = [(0.2, 0.55, 1.9, "config\nai.provider"), (2.35, 0.55, 1.85, "AiProvider\nFactory"), (4.35, 0.55, 1.75, "OpenAi\nProvider"), (6.2, 0.55, 1.65, "Claude\nProvider")]
    for x, y, w, t in boxes:
        ax.add_patch(mpatches.FancyBboxPatch((x, y), w, 1.0, boxstyle="round,pad=0.02", edgecolor=COLOR_PRIMARY, facecolor="white"))
        ax.text(x + w / 2, y + 0.5, t, ha="center", va="center", fontsize=8, color=COLOR_PRIMARY)
    ax.annotate("", xy=(2.35, 1.05), xytext=(2.1, 1.05), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.2))
    ax.annotate("", xy=(4.35, 1.05), xytext=(4.2, 1.05), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.2))
    ax.annotate("", xy=(6.2, 1.05), xytext=(6.1, 1.05), arrowprops=dict(arrowstyle="->", color=COLOR_ACCENT, lw=1.2))
    ax.text(4, 0.15, "Figure 7 — Provider abstraction (no Bedrock / Gemini / Groq in codebase)", ha="center", fontsize=7.5, color=COLOR_TEXT)
    return _plt_save(fig, "fig07_provider.png")


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
            "Docker deployment (files only)",
            "Design decisions and limitations",
        ],
    )

    doc.add_heading("Executive summary", level=1)
    para(
        doc,
        "Stone ERP AI Assistant is a Laravel 12 application: a Sanctum-authenticated JSON API plus a minimal Vite/Tailwind "
        "chat UI (route `/` renders resources/views/chat.blade.php). The POST /api/assistant endpoint orchestrates OpenAI or Claude "
        "through a fixed tool registry; tools call PHP services that query MySQL via Eloquent — the model never executes arbitrary SQL. "
        "Draft purchase orders are stored as proposals and require an explicit confirm HTTP call with idempotency.",
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
        "Redis is separate infrastructure: cache and queue backends (config/queue.php, predis client). PHPUnit forces QUEUE_CONNECTION=sync "
        "(phpunit.xml). A queue worker may run in Docker Compose or locally for other jobs — it is not on the critical path for POST /api/assistant: "
        "that endpoint runs synchronously in the PHP request; no ShouldQueue assistant job exists under app/. A future implementation could dispatch "
        "assistant work to Redis — that is not the current behavior.",
    )

    doc.add_heading("Docker deployment (files only)", level=1)
    add_picture(doc, diagrams["docker"], "Figure 6 — docker-compose.yml services; runtime not verified on the primary Windows dev host")
    para(doc, "See docs/PHASE11-DOCKER.md for env files and first-run commands.")

    doc.add_heading("Design decisions and limitations", level=1)
    for item in [
        "OpenAI and Claude drivers exist; live billed API calls were not used for this documentation build.",
        "Docker Compose and images exist; container runtime not verified here.",
        "Response streaming is not implemented.",
        "Bedrock, Gemini, Groq are noted as future in config/ai.php — not implemented.",
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
    para(doc, "Set AI_PROVIDER=openai or claude in .env. ERP services do not branch on provider; only the AI layer switches (config/ai.php).")

    doc.add_heading("Queue worker", level=1)
    para(
        doc,
        "composer.json script dev runs php artisan queue:listen with redis. Manual: scripts\\dev.cmd php artisan queue:work redis. "
        "The assistant endpoint itself does not enqueue a job (no dispatch under app/).",
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
    code_block(doc, "composer validate --no-check-publish\nphp artisan test\nnpm test\nnpm run build")

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
        ("POST", "/api/assistant", "Yes", "Sales or Purchasing", "Assistant chat"),
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

    doc.add_heading("Idempotency", level=1)
    para(doc, "Assistant: Idempotency-Key + conversation_id stored on conversation_messages — see AssistantRequestService and AssistantApiTest.")
    para(doc, "Proposal confirm: Idempotency-Key on confirm route — PurchaseOrderProposalApiTest.")

    doc.add_heading("Sensitive data", level=1)
    para(doc, "Supplier bank fields encrypted at rest; ToolResultRedactor strips risky keys from persisted tool payloads.")

    doc.add_heading("Prompt injection stance", level=1)
    para(
        doc,
        "Authorization never relies on model text: Gates, ToolExecutor::allowedFor, and service checks enforce Sales vs Purchasing regardless of user instructions.",
    )

    doc.add_heading("Automated tests", level=1)
    para(
        doc,
        "Latest suite run (Phase 14.1): php artisan test — 182 tests passed, 545 assertions. Re-run before interview; do not cite stale numbers if the suite changes.",
    )
    code_block(doc, "composer validate --no-check-publish\nphp artisan test\nnpm test\nnpm run build")

    doc.add_heading("Known limitations", level=1)
    doc.add_paragraph("Docker runtime unverified on primary dev host.", style="List Bullet")
    doc.add_paragraph("No streaming responses.", style="List Bullet")
    doc.add_paragraph("Assistant not queue-backed (no queued job in app/).", style="List Bullet")

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
            "Synchronous assistant vs queue-backed future",
            "Technical interview Q&A (≥25)",
            "Possible live-coding exercises",
            "Honest limitations and verification gaps",
            "AI-assisted development disclosure",
        ],
    )

    doc.add_heading("Project introduction (elevator pitch)", level=1)
    para(
        doc,
        "Laravel 12+ JSON API + minimal Vite chat UI. POST /api/assistant runs synchronously: AiToolCallingService loops with AiAgentService and an "
        "AiProviderInterface implementation while ToolExecutor enforces tool schemas and ERP authorization. Draft POs stay proposals until an explicit "
        "HTTP confirm with idempotency.",
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
    add_picture(doc, diagrams["high"], "Figure 1 — High-level architecture (Redis separate; assistant synchronous)")
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

    doc.add_heading("Synchronous assistant vs queue-backed future", level=1)
    para(
        doc,
        "Today: POST /api/assistant completes in the web worker; AiToolCallingService loops in-process; phpunit.xml sets QUEUE_CONNECTION=sync. "
        "Redis and docker-compose queue services exist for configured queue drivers and non-assistant jobs — they do not make the assistant asynchronous by themselves. "
        "Future: a queued job could enqueue assistant work; that would require explicit dispatch, payload contracts, and UX for progress.",
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
        ("What Redis role is configured?", "Queue/cache backend via config/queue.php — assistant still synchronous."),
        ("Does the repo implement queue-based assistant responses?", "No dispatched assistant job under app/; queue worker is infrastructure only today."),
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
    }

    build_doc01(diagrams)
    build_doc02(diagrams)
    build_doc03()
    build_doc04()
    build_doc05(diagrams)
    return 0


if __name__ == "__main__":
    sys.exit(main())
