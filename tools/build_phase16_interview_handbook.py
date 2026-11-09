#!/usr/bin/env python3
"""
Generate docs/submission/07-Complete-Technical-Interview-Handbook.docx only.

Reuses styling and diagram helpers from tools/build_phase13_submission.py.
Does not modify documents 01–06 (validated at runtime).

Dependencies: python-docx, matplotlib, pillow (same as build_phase13_submission.py).
"""
from __future__ import annotations

import importlib.util
import json
import re
import subprocess
import zipfile
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[1]
OUT_DOCX = ROOT / "docs" / "submission" / "07-Complete-Technical-Interview-Handbook.docx"
ASSETS_GEN = ROOT / "docs" / "submission" / "assets" / "generated"


def _load_phase13():
    spec = importlib.util.spec_from_file_location("bp13", str(ROOT / "tools" / "build_phase13_submission.py"))
    m = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(m)
    return m


bp13 = _load_phase13()


# ---------------------------------------------------------------------------
# Git / shell helpers
# ---------------------------------------------------------------------------


def find_line(rel_path: str, needle: str) -> str:
    path = ROOT / rel_path
    if not path.is_file():
        return "—"
    for i, line in enumerate(path.read_text(encoding="utf-8", errors="replace").splitlines(), 1):
        if needle in line:
            return str(i)
    return "—"


def read_rel(rel: str) -> str:
    return (ROOT / rel).read_text(encoding="utf-8", errors="replace")


def git_head() -> str:
    try:
        return subprocess.check_output(["git", "-C", str(ROOT), "rev-parse", "HEAD"], text=True).strip()
    except (OSError, subprocess.CalledProcessError):
        return "(unavailable)"


def git_upstream() -> str | None:
    try:
        return subprocess.check_output(
            ["git", "-C", str(ROOT), "rev-parse", "--abbrev-ref", "@{u}"],
            text=True,
            stderr=subprocess.DEVNULL,
        ).strip()
    except (OSError, subprocess.CalledProcessError):
        return None


def git_ls_remote_origin_head() -> str | None:
    try:
        out = subprocess.check_output(
            ["git", "-C", str(ROOT), "ls-remote", "origin", "HEAD"],
            text=True,
            stderr=subprocess.DEVNULL,
        ).strip()
        if not out:
            return None
        # format: <hash>\tHEAD
        return out.split()[0]
    except (OSError, subprocess.CalledProcessError):
        return None


def git_status_short() -> str:
    try:
        return subprocess.check_output(["git", "-C", str(ROOT), "status", "-sb"], text=True).strip()
    except (OSError, subprocess.CalledProcessError):
        return "(unavailable)"


# ---------------------------------------------------------------------------
# File inventory
# ---------------------------------------------------------------------------

SKIP_DIR_NAMES = {"vendor", "node_modules", ".git", "storage", "bootstrap/cache"}

# Runtime / dependency caches — not counted as project source (Phase 16.4D).
INVENTORY_SKIP_PATH_PREFIXES = [
    "tools/composer/",
    "tools/mysql/",
]

INVENTORY_SKIP_NAME_PARTS = ("__pycache__",)


def inventory_path_excluded(rel: str) -> bool:
    rel_f = rel.replace("\\", "/").lower()
    for pref in INVENTORY_SKIP_PATH_PREFIXES:
        if rel_f.startswith(pref.lower()):
            return True
    parts = rel_f.split("/")
    for part in parts:
        if part in INVENTORY_SKIP_NAME_PARTS:
            return True
        if part.endswith(".pyc") or part.endswith(".pyo"):
            return True
    return False


INVENTORY_ROOTS = [
    ROOT / "app",
    ROOT / "bootstrap",
    ROOT / "config",
    ROOT / "database",
    ROOT / "docker",
    ROOT / "docs",
    ROOT / "public",
    ROOT / "resources",
    ROOT / "routes",
    ROOT / "scripts",
    ROOT / "tests",
    ROOT / "tools",
]

INVENTORY_EXTRA_FILES = [
    ROOT / "composer.json",
    ROOT / "package.json",
    ROOT / "package-lock.json",
    ROOT / "vite.config.js",
    ROOT / "docker-compose.yml",
    ROOT / "Dockerfile",
    ROOT / "README.md",
    ROOT / ".gitignore",
    ROOT / "phpunit.xml",
    ROOT / ".env.example",
]

TEXT_EXTENSIONS = {
    ".php",
    ".js",
    ".mjs",
    ".ts",
    ".css",
    ".scss",
    ".vue",
    ".json",
    ".yml",
    ".yaml",
    ".md",
    ".cmd",
    ".ps1",
    ".sh",
    ".sql",
    ".blade.php",
    ".xml",
}


def path_ok_under_root(p: Path) -> bool:
    try:
        rel = p.relative_to(ROOT)
    except ValueError:
        return False
    return not (set(rel.parts) & SKIP_DIR_NAMES)


def count_inventory_files() -> tuple[int, list[str]]:
    out: list[str] = []
    seen: set[str] = set()

    for base in INVENTORY_ROOTS:
        if not base.exists():
            continue
        for p in base.rglob("*"):
            if not p.is_file() or not path_ok_under_root(p):
                continue
            suf = p.suffix.lower()
            if suf in TEXT_EXTENSIONS or (suf == "" and p.name == "Dockerfile"):
                sp = str(p.relative_to(ROOT)).replace("\\", "/")
                if inventory_path_excluded(sp):
                    continue
                if sp not in seen:
                    seen.add(sp)
                    out.append(sp)

    for p in INVENTORY_EXTRA_FILES:
        if p.is_file():
            sp = str(p.relative_to(ROOT)).replace("\\", "/")
            if sp not in seen:
                seen.add(sp)
                out.append(sp)

    return len(out), sorted(out)


def file_kind(rel: str) -> str:
    if rel.startswith("app/Http/Controllers"):
        return "Controller"
    if rel.startswith("app/Http/Middleware"):
        return "Middleware"
    if rel.startswith("app/Http/Requests"):
        return "Form Request"
    if rel.startswith("app/Http/Resources"):
        return "API Resource"
    if rel.startswith("app/Models"):
        return "Model"
    if rel.startswith("app/Services"):
        return "Service"
    if rel.startswith("app/Providers"):
        return "Service Provider"
    if rel.startswith("app/Contracts"):
        return "Interface"
    if rel.startswith("app/Enums"):
        return "Enum"
    if rel.startswith("app/DTOs"):
        return "DTO"
    if rel.startswith("app/Auth"):
        return "Auth"
    if rel.startswith("database/migrations"):
        return "Migration"
    if rel.startswith("database/seeders") or rel.startswith("database/factories"):
        return "Database helper"
    if rel.startswith("tests/"):
        return "Test"
    if rel.startswith("routes/"):
        return "Routes"
    if rel.startswith("resources/js"):
        return "Frontend JS"
    if rel.startswith("resources/css"):
        return "CSS"
    if rel.startswith("resources/views"):
        return "Blade"
    if rel.startswith("config/"):
        return "Config"
    if rel.startswith("scripts/"):
        return "Script"
    if rel.startswith("docker/"):
        return "Docker config"
    if rel.endswith("Dockerfile"):
        return "Dockerfile"
    if rel == "docker-compose.yml":
        return "Compose"
    if rel.startswith("docs/"):
        return "Documentation"
    if rel.startswith("tools/") and rel.endswith(".py"):
        return "Tooling (Python)"
    if rel.startswith("public/"):
        return "Public asset"
    if rel in {"composer.json", "package.json", "package-lock.json", "vite.config.js", "phpunit.xml"}:
        return "Project manifest"
    return "Source"


def _first_line_preview(text: str, max_len: int = 200) -> str:
    for line in text.splitlines():
        s = line.strip()
        if s and not s.startswith("<?php"):
            return s[:max_len] + ("…" if len(s) > max_len else "")
    return "(empty)"


def php_file_summary(rel: str) -> str:
    path = ROOT / rel
    if not path.suffix == ".php" and not rel.endswith(".blade.php"):
        try:
            t = path.read_text(encoding="utf-8", errors="replace")
        except OSError:
            return "(unreadable)"
        return _first_line_preview(t, 180)

    text = path.read_text(encoding="utf-8", errors="replace")
    ns_m = re.search(r"^\s*namespace\s+([^;]+);", text, re.MULTILINE)
    class_m = re.search(r"^\s*(?:abstract\s+)?(?:final\s+)?class\s+(\w+)", text, re.MULTILINE)
    iface_m = re.search(r"^\s*interface\s+(\w+)", text, re.MULTILINE)
    enum_m = re.search(r"^\s*enum\s+(\w+)", text, re.MULTILINE)
    name = (class_m or iface_m or enum_m)
    bits: list[str] = []
    if ns_m:
        bits.append(ns_m.group(1).strip())
    if name:
        bits.append(name.group(1))
    if bits:
        return " — ".join(bits)[:220]
    return _first_line_preview(text, 180)


def summarize_file(rel: str) -> str:
    p = ROOT / rel
    if not p.is_file():
        return "(missing file)"
    if rel.endswith(".php") or rel.endswith(".blade.php"):
        return php_file_summary(rel)
    try:
        return _first_line_preview(p.read_text(encoding="utf-8", errors="replace"), 180)
    except OSError:
        return "(unreadable)"


def inventory_by_category(paths: list[str]) -> dict[str, list[str]]:
    buckets: dict[str, list[str]] = {}
    for rel in paths:
        cat = rel.split("/")[0] if "/" in rel else "(root)"
        buckets.setdefault(cat, []).append(rel)
    for k in buckets:
        buckets[k].sort()
    return dict(sorted(buckets.items()))


# ---------------------------------------------------------------------------
# Migration parser (line + Blueprint chain aware)
# ---------------------------------------------------------------------------


def _match_parens(s: str, open_idx: int) -> tuple[str, int]:
    """Extract contents of parens starting at open_idx; return (inner, index after closing)."""
    depth = 0
    start = None
    for i in range(open_idx, len(s)):
        ch = s[i]
        if ch == "(":
            if depth == 0:
                start = i + 1
            depth += 1
        elif ch == ")":
            depth -= 1
            if depth == 0 and start is not None:
                return s[start:i], i + 1
    return "", len(s)


def _next_calls_from_table_line(line: str) -> list[tuple[str, str]] | None:
    """Parse $table->id()->... chains into [(method, args_inner), ...]."""
    s = line.strip()
    if not s.startswith("$table->"):
        return None
    s = s[len("$table->") :]
    calls: list[tuple[str, str]] = []
    while True:
        m = re.match(r"([a-zA-Z_]\w*)\s*", s)
        if not m:
            break
        method = m.group(1)
        pos = m.end()
        args = ""
        if pos < len(s) and s[pos] == "(":
            inner, pos2 = _match_parens(s, pos)
            args = inner
            rest = s[pos2:]
        else:
            rest = s[pos:]
        calls.append((method, args))
        rest = rest.lstrip()
        if rest.startswith("->"):
            s = rest[2:]
            continue
        if rest.startswith(";"):
            break
        break
    return calls or None


def _strip_quotes(s: str) -> str:
    s = s.strip()
    if len(s) >= 2 and s[0] == s[-1] and s[0] in "'\"":
        return s[1:-1]
    return s


FIRST_ARG_RE = re.compile(r"^\s*['\"]([^'\"]+)['\"]")


def _first_string_arg(args: str) -> str | None:
    m = FIRST_ARG_RE.match(args.strip())
    return m.group(1) if m else None


DECIMAL_RE = re.compile(r"^\s*['\"](\w+)['\"]\s*,\s*(\d+)\s*,\s*(\d+)")


def _mysql_type_for_column(method: str, args: str, modifiers: str) -> str:
    """Interview-friendly MySQL column types derived from migration calls (not for SQLite)."""
    a = args.strip()
    if method == "id":
        return "BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY"
    if method == "foreignId":
        return "BIGINT UNSIGNED"
    if method == "foreignUuid":
        return "CHAR(36)"
    if method == "uuid":
        col = _first_string_arg(a) or "id"
        return "CHAR(36) BINARY" if col == "id" else "CHAR(36)"
    if method == "rememberToken":
        return "VARCHAR(100) NULL"
    if method == "string":
        m = re.match(r"^\s*['\"](\w+)['\"]\s*,\s*(\d+)", a)
        if m:
            return f"VARCHAR({m.group(2)})"
        return "VARCHAR(255)"
    if method == "text":
        return "TEXT"
    if method == "longText":
        return "LONGTEXT"
    if method == "mediumText":
        return "MEDIUMTEXT"
    if method == "json":
        return "JSON"
    if method == "boolean":
        return "TINYINT(1)"
    if method == "integer":
        return "INT"
        if method == "unsignedInteger":
            return "INT UNSIGNED"
        if method == "unsignedBigInteger":
            return "BIGINT UNSIGNED"
        if method == "unsignedSmallInteger":
            return "SMALLINT UNSIGNED"
        if method == "unsignedTinyInteger":
            return "TINYINT UNSIGNED"
        if method == "decimal":
            dm = DECIMAL_RE.match(a)
            if dm:
                return f"DECIMAL({dm.group(2)},{dm.group(3)})"
            return "DECIMAL"
        if method == "timestamp":
            if "->useCurrent()" in modifiers:
                return "TIMESTAMP DEFAULT CURRENT_TIMESTAMP"
            return "TIMESTAMP"
        if method == "date":
            return "DATE"
        if "implicit" in a:
            if method == "unsignedBigInteger":
                return "BIGINT UNSIGNED"
            if method == "string":
                return "VARCHAR(255)"
        return "—"


@dataclass
class ColumnRecord:
    table: str
    column: str
    line_no: int
    raw_line: str
    migration_file: str
    method: str
    args: str
    modifiers: str  # chained after column-def first call
    nullable: bool = False
    has_default: bool = False
    is_unique: bool = False
    indexed: bool = False
    is_primary_key: bool = False
    fk_to_table: str | None = None
    fk_on_delete: str | None = None
    check_sql: str | None = None
    notes: str = ""

    def db_type_label(self) -> str:
        a = self.args.strip()
        m = self.method
        if m == "id":
            return "bigint unsigned (auto-increment PK)"
        if m == "foreignId":
            col = _first_string_arg(a) or "?"
            return f"foreignId → bigint unsigned FK `{col}`"
        if m == "foreignUuid":
            col = _first_string_arg(a) or "?"
            return f"foreignUuid `{col}`"
        if m == "uuid":
            col = _first_string_arg(a) or "uuid"
            return f"uuid `{col}`"
        if m == "string":
            return f"string({a})" if a else "string"
        if m == "text":
            return "text"
        if m == "longText":
            return "longText"
        if m == "mediumText":
            return "mediumText"
        if m == "json":
            return "json"
        if m == "boolean":
            return "boolean"
        if m == "integer":
            return f"integer({a})" if a else "integer"
        if m == "unsignedInteger":
            return f"unsignedInteger({a})"
        if m == "unsignedBigInteger":
            return f"unsignedBigInteger({a})"
        if m == "unsignedSmallInteger":
            return f"unsignedSmallInteger({a})"
        if m == "unsignedTinyInteger":
            return f"unsignedTinyInteger({a})"
        if m == "decimal":
            return f"decimal({a})" if a else "decimal"
        if m == "timestamp":
            return f"timestamp({a})" if a else "timestamp"
        if m == "date":
            return "date"
        if m == "morphs":
            base = _first_string_arg(a) or "morph"
            return f"morphs `{base}` → {base}_id + {base}_type"
        if m == "rememberToken":
            return "string remember_token"
        if m == "unsignedBigInteger" and "implicit" in a:
            return "unsignedBigInteger (morph)"
        if m == "string" and "implicit" in a:
            return "string (morph type)"
        return f"{m}({a})"

    def mysql_type_label(self) -> str:
        return _mysql_type_for_column(self.method, self.args, self.modifiers)


def _analyze_modifiers(modifiers: str) -> tuple[bool, bool, bool, bool]:
    nullable = "->nullable(" in modifiers
    has_def = "->default(" in modifiers
    is_unique = "->unique(" in modifiers or "->unique()" in modifiers
    indexed = "->index(" in modifiers or "->index()" in modifiers
    return nullable, has_def, is_unique, indexed


def _extract_fk_target(modifiers: str) -> tuple[str | None, str | None]:
    """Parse ->constrained('table') and restrictOnDelete/nullOnDelete/cascadeOnDelete."""
    tm = re.search(r"->constrained\(\s*['\"](\w+)['\"]\s*\)", modifiers)
    tm_empty = re.search(r"->constrained\(\s*\)", modifiers)
    if tm:
        table = tm.group(1)
    elif tm_empty:
        table = None
    else:
        table = None
    od = None
    for token, label in [
        ("->cascadeOnDelete()", "CASCADE"),
        ("->restrictOnDelete()", "RESTRICT"),
        ("->nullOnDelete()", "SET NULL"),
    ]:
        if token in modifiers:
            od = label
            break
    return table, od


def _split_dollar_table_statements(merged_line: str) -> list[str]:
    """Split `$table->...; $table->...` — semicolons only at paren depth 0."""
    stmts: list[str] = []
    cur: list[str] = []
    depth = 0
    for ch in merged_line:
        if ch == "(":
            depth += 1
        elif ch == ")":
            depth -= 1
        if ch == ";" and depth == 0:
            s = "".join(cur).strip()
            if s.startswith("$table->"):
                stmts.append(s)
            cur = []
        else:
            cur.append(ch)
    return stmts


def _merge_continuation_block(lines: list[str], start_idx: int) -> tuple[str, int, int]:
    """Join lines until a terminating `;` for a `$table->...` statement; return merged, end_index, first_line_1based."""
    parts: list[str] = []
    j = start_idx
    first_line = start_idx + 1
    while j < len(lines):
        raw = lines[j]
        line = raw.strip()
        if line.startswith("//") or line.startswith("*") or line == "":
            j += 1
            continue
        parts.append(line.rstrip())
        if line.endswith(";"):
            return " ".join(parts), j + 1, first_line
        j += 1
    return " ".join(parts), j, first_line


def _parse_array_string_cols(args: str) -> list[str] | None:
    """Extract ['a','b'] column names from unique/index/primary args."""
    inner_m = re.search(r"\[(.*)\]", args, re.DOTALL)
    if not inner_m:
        return None
    inner = inner_m.group(1)
    return re.findall(r"['\"](\w+)['\"]", inner)


def _parse_foreign_columns(args: str) -> tuple[str | None, str | None]:
    """$table->foreign(['a'])->references('id')->on('tbl') — return (local_col, ref_col)."""
    m_local = re.search(r"\[\s*['\"](\w+)['\"]\s*\]", args)
    local = m_local.group(1) if m_local else None
    m_ref = re.search(r"references\(\s*['\"](\w+)['\"]\s*\)", args)
    ref = m_ref.group(1) if m_ref else None
    return local, ref


def _rebuild_modifier_chain(calls: list[tuple[str, str]]) -> str:
    parts: list[str] = []
    for m, a in calls[1:]:
        if a == "" and m not in {"nullable", "useCurrent"}:
            parts.append(f"->{m}()")
        else:
            parts.append(f"->{m}({a})")
    return "".join(parts)


def _parse_table_calls(
    table: str,
    calls: list[tuple[str, str]],
    line_no: int,
    raw_line: str,
    fname: str,
    out_cols: list[ColumnRecord],
    out_extra: list[dict[str, Any]],
) -> None:
    if not calls:
        return
    method, args = calls[0]
    mod = _rebuild_modifier_chain(calls)
    null, hdef, uniq, idx = _analyze_modifiers(mod)
    fk_table, fk_del = _extract_fk_target(mod)

    def push_col(col: str, meth: str, argstr: str, extra_notes: str = "") -> None:
        pk = meth == "id" or (
            meth in {"string", "uuid"}
            and (
                "->primary()" in mod
                or re.search(r"->primary\(\s*['\"]" + re.escape(col) + r"['\"]\s*\)", mod) is not None
            )
        )
        rec = ColumnRecord(
            table=table,
            column=col,
            line_no=line_no,
            raw_line=raw_line.strip(),
            migration_file=fname,
            method=meth,
            args=argstr,
            modifiers=mod,
            nullable=null,
            has_default=hdef or meth == "timestamps",
            is_unique=uniq,
            indexed=idx,
            is_primary_key=pk,
            fk_to_table=fk_table,
            fk_on_delete=fk_del,
            notes=extra_notes,
        )
        out_cols.append(rec)

    if method == "id":
        push_col("id", method, args)
    elif method == "foreignId":
        col = _first_string_arg(args)
        if not col:
            col = "foreign_id"
        push_col(col, method, args)
    elif method == "foreignUuid":
        col = _first_string_arg(args)
        if col:
            push_col(col, method, args)
    elif method in {
        "string",
        "text",
        "longText",
        "mediumText",
        "json",
        "boolean",
        "integer",
        "unsignedInteger",
        "unsignedBigInteger",
        "unsignedSmallInteger",
        "unsignedTinyInteger",
        "decimal",
        "timestamp",
        "date",
    }:
        col = _first_string_arg(args)
        if col:
            push_col(col, method, args)
    elif method == "uuid":
        col = _first_string_arg(args) or "id"
        push_col(col, method, args)
    elif method == "morphs":
        base = _first_string_arg(args) or "morph"
        push_col(f"{base}_id", "unsignedBigInteger", f"implicit `{base}_id`", extra_notes="morphs()")
        push_col(f"{base}_type", "string", f"implicit `{base}_type`", extra_notes="morphs()")
    elif method == "rememberToken":
        push_col("remember_token", method, args)
    elif method == "timestamps":
        push_col("created_at", "timestamp", "", extra_notes="timestamps()")
        push_col("updated_at", "timestamp", "", extra_notes="timestamps()")
    elif method in {"index", "unique", "primary"}:
        cols_in_args = _parse_array_string_cols(args)
        desc = ""
        if cols_in_args:
            desc = ", ".join(f"`{c}`" for c in cols_in_args)
            if method == "unique":
                desc = f"UNIQUE({desc})"
            elif method == "index":
                desc = f"INDEX({desc})"
            else:
                desc = f"PRIMARY({desc})"
        out_extra.append(
            {
                "table": table,
                "line_no": line_no,
                "kind": method,
                "args": args,
                "columns": cols_in_args or [],
                "summary": desc,
                "raw_line": raw_line.strip(),
                "file": fname,
            }
        )
    elif method == "foreign":
        local, refc = _parse_foreign_columns(args)
        on_m = re.search(r"->on\(\s*['\"](\w+)['\"]\s*\)", args)
        on_tbl = on_m.group(1) if on_m else None
        summ = "FOREIGN KEY"
        if local and refc and on_tbl:
            summ = f"FOREIGN KEY `{local}` → {on_tbl}.{refc}"
        out_extra.append(
            {
                "table": table,
                "line_no": line_no,
                "kind": "foreign",
                "args": args,
                "columns": [local] if local else [],
                "ref": refc,
                "ref_table": on_tbl,
                "summary": summ,
                "raw_line": raw_line.strip(),
                "file": fname,
            }
        )
    else:
        out_extra.append(
            {
                "table": table,
                "line_no": line_no,
                "kind": method,
                "args": args,
                "columns": [],
                "summary": "",
                "raw_line": raw_line.strip(),
                "file": fname,
            }
        )


def _parse_migration_up_body(
    table: str,
    lines: list[str],
    body_start: int,
    body_end: int,
    fname: str,
    out_cols: list[ColumnRecord],
    out_extra: list[dict[str, Any]],
) -> None:
    j = body_start + 1
    while j < body_end:
        bl = lines[j].strip()
        if bl.startswith("DB::statement"):
            out_extra.append(
                {
                    "table": table,
                    "line_no": j + 1,
                    "kind": "db_statement",
                    "args": "",
                    "columns": [],
                    "summary": "",
                    "raw_line": lines[j].strip(),
                    "file": fname,
                }
            )
            j += 1
            continue
        if not bl.startswith("$table->"):
            j += 1
            continue
        merged, next_j, line_no = _merge_continuation_block(lines, j)
        j = next_j
        for stmt in _split_dollar_table_statements(merged):
            calls = _next_calls_from_table_line(stmt)
            if calls:
                _parse_table_calls(table, calls, line_no, merged, fname, out_cols, out_extra)


def parse_migrations_detailed() -> tuple[list[ColumnRecord], list[dict[str, Any]]]:
    mig_dir = ROOT / "database" / "migrations"
    cols: list[ColumnRecord] = []
    extras: list[dict[str, Any]] = []

    create_re = re.compile(r"Schema::create\(\s*['\"](\w+)['\"]")
    table_re = re.compile(r"Schema::table\(\s*['\"](\w+)['\"]")

    def find_matching_brace(lines: list[str], start_idx: int) -> int:
        """Line index where brace depth returns to 0 (inclusive of start_idx line)."""
        depth = 0
        for k in range(start_idx, len(lines)):
            depth += lines[k].count("{") - lines[k].count("}")
            if depth == 0:
                return k
        return len(lines) - 1

    for path in sorted(mig_dir.glob("*.php")):
        fname = path.name
        lines = path.read_text(encoding="utf-8", errors="replace").splitlines()

        i = 0
        while i < len(lines):
            line = lines[i]
            mc = create_re.search(line)
            mt = table_re.search(line)
            if not (mc or mt):
                i += 1
                continue
            table = (mc or mt).group(1)
            # Blueprint callback body only (not all of `up()`), so multi-table migrations do not duplicate.
            if "function" not in line:
                i += 1
                continue
            body_start = None
            for j in range(i, min(i + 8, len(lines))):
                if "{" in lines[j]:
                    body_start = j
                    break
            if body_start is None:
                i += 1
                continue
            body_end = find_matching_brace(lines, body_start)
            if mc:
                _parse_migration_up_body(table, lines, body_start, body_end, fname, cols, extras)
            else:
                # Only additive Schema::table(...) in up(); skip down()-only alters.
                _parse_migration_up_body(table, lines, body_start, body_end, fname, cols, extras)
            i = body_end + 1

    # Attach MySQL CHECK constraint text to the referenced column row (up() only — migration guard in source).
    for ex in extras:
        if ex["kind"] != "db_statement":
            continue
        raw = ex["raw_line"]
        if "CHECK" in raw.upper() and "`" in raw:
            tbl_m = re.search(r"ALTER TABLE\s+`(\w+)`", raw, re.IGNORECASE)
            if tbl_m:
                ex["table"] = tbl_m.group(1)
            chk_m = re.search(r"CHECK\s*\([^)]+\)", raw, re.IGNORECASE)
            chk_sql = chk_m.group(0) if chk_m else raw
            for c in cols:
                if c.table == ex.get("table") and c.column == "slabs_on_hand":
                    c.check_sql = chk_sql

    return cols, extras


def column_record_to_row(c: ColumnRecord) -> list[str]:
    null = "Yes" if c.nullable else "No"
    keys: list[str] = []
    if c.is_primary_key:
        keys.append("PRIMARY KEY")
    if c.is_unique:
        keys.append("UNIQUE")
    if c.indexed:
        keys.append("INDEX")
    if c.fk_to_table:
        keys.append(f"FK→{c.fk_to_table}" + (f" ON DELETE {c.fk_on_delete}" if c.fk_on_delete else ""))
    if c.has_default:
        keys.append("DEFAULT")
    if c.check_sql:
        keys.append("CHECK (MySQL)")
    key_s = ", ".join(keys) if keys else "—"
    return [
        c.table,
        c.column,
        c.mysql_type_label(),
        c.db_type_label(),
        null,
        key_s,
        c.migration_file + ":" + str(c.line_no),
    ]


def tables_from_columns(cols: list[ColumnRecord]) -> list[str]:
    return sorted({c.table for c in cols})


# ---------------------------------------------------------------------------
# API routes (parse routes/api.php)
# ---------------------------------------------------------------------------


@dataclass
class ApiRouteRow:
    method: str
    uri: str
    controller: str
    action: str
    middleware: str
    name: str


def parse_api_routes() -> list[ApiRouteRow]:
    text = read_rel("routes/api.php")
    rows: list[ApiRouteRow] = []
    # State: unauthenticated routes vs auth:sanctum group
    in_sanctum = False
    sanctum_mw = "auth:sanctum"

    def resolve_mw(route_tail: str) -> str:
        parts: list[str] = []
        if in_sanctum:
            parts.append(sanctum_mw)
        parts.extend(re.findall(r"->middleware\(\s*'([^']+)'\s*\)", route_tail))
        return ", ".join(parts) if parts else "—"

    buffer = ""
    for raw_line in text.splitlines():
        line = raw_line.strip()
        if re.search(r"Route::middleware\(\s*['\"]auth:sanctum['\"]\s*\)\s*->\s*group", line):
            in_sanctum = True
            continue
        if in_sanctum and line.startswith("});"):
            in_sanctum = False
            continue
        if not line or line.startswith("//"):
            continue
        if buffer:
            buffer = (buffer + " " + line).strip()
        elif line.startswith("Route::"):
            buffer = line
        else:
            continue
        if not buffer.endswith(");"):
            continue
        merged = buffer
        buffer = ""
        m_route = re.match(
            r"Route::(get|post|delete|put|patch)\(\s*'([^']+)'\s*,\s*\[([\w\\]+)::class\s*,\s*'(\w+)'\s*\]\s*\)(.*)\s*;\s*$",
            merged,
        )
        if not m_route:
            continue
        verb, uri, controller, action, tail = m_route.groups()
        nm_match = re.search(r"->name\(\s*'([^']+)'\s*\)", merged)
        nm = nm_match.group(1) if nm_match else ""
        mw = resolve_mw(tail)
        rows.append(ApiRouteRow(verb.upper(), "/api/" + uri.lstrip("/"), controller, action, mw, nm))
    return rows


# ---------------------------------------------------------------------------
# Models (Eloquent)
# ---------------------------------------------------------------------------


@dataclass
class ModelInfo:
    path: str
    class_name: str
    table: str | None
    fillable: list[str]
    hidden: list[str]
    casts_keys: list[str]
    relation_methods: list[str]


def parse_model(rel_path: str) -> ModelInfo | None:
    text = read_rel(rel_path)
    cm = re.search(r"class\s+(\w+)\s+extends", text)
    if not cm:
        return None
    class_name = cm.group(1)
    tm = re.search(r"protected\s+\$table\s*=\s*'(\w+)'", text)
    table = tm.group(1) if tm else None
    fillable: list[str] = []
    hm = re.search(r"protected\s+\$fillable\s*=\s*\[(.*?)\];", text, re.DOTALL)
    if hm:
        fillable = re.findall(r"'(\w+)'", hm.group(1))
    hid: list[str] = []
    hdm = re.search(r"protected\s+\$hidden\s*=\s*\[(.*?)\];", text, re.DOTALL)
    if hdm:
        hid = re.findall(r"'(\w+)'", hdm.group(1))
    casts_keys: list[str] = []
    cfm = re.search(r"protected\s+function\s+casts\(\)\s*:\s*array\s*\{(.*?)\n\s*\}", text, re.DOTALL)
    if cfm:
        casts_keys = re.findall(r"'(\w+)'\s*=>", cfm.group(1))
    rels = re.findall(r"public\s+function\s+(\w+)\s*\([^)]*\)\s*:\s*\w+\s*\{", text)
    rel_methods = [r for r in rels if r not in {"casts", "boot"}]
    return ModelInfo(rel_path, class_name, table, fillable, hid, casts_keys, rel_methods)


def all_models() -> list[ModelInfo]:
    out: list[ModelInfo] = []
    for p in sorted((ROOT / "app" / "Models").glob("*.php")):
        rel = str(p.relative_to(ROOT)).replace("\\", "/")
        mi = parse_model(rel)
        if mi:
            out.append(mi)
    return out


# ---------------------------------------------------------------------------
# Diagram: API + conversation sequence (matplotlib)
# ---------------------------------------------------------------------------


def diagram_api_sequence() -> Path:
    """Sequence-style overview: Browser → Laravel → Provider → ToolExecutor → ERP (PNG)."""
    import matplotlib.pyplot as plt

    ASSETS_GEN.mkdir(parents=True, exist_ok=True)
    fig, ax = plt.subplots(figsize=(8.8, 9.2))
    ax.set_xlim(0, 10)
    ax.set_ylim(0, 11)
    ax.axis("off")

    def box(x, y, w, h, txt, fs=7.5):
        import matplotlib.patches as mpatches

        p = mpatches.FancyBboxPatch(
            (x, y), w, h, boxstyle="round,pad=0.02", linewidth=1.1, edgecolor="#1e3a5f", facecolor="#f1f5f9"
        )
        ax.add_patch(p)
        ax.text(x + w / 2, y + h / 2, txt, ha="center", va="center", fontsize=fs, color="#1e3a5f", fontweight="bold")

    y0 = 9.35
    box(0.5, y0, 2.2, 0.55, "Browser\nPOST /api/assistant", 7)
    box(3.0, y0, 2.4, 0.55, "AssistantController::store\nAssistantChatRequest", 7)
    box(5.8, y0, 2.4, 0.55, "AssistantRequestService\n(Ollama: 202+queue)", 7)
    ax.annotate("", xy=(3.0, y0 + 0.28), xytext=(2.7, y0 + 0.28), arrowprops=dict(arrowstyle="->", color="#2563eb", lw=1.1))
    ax.annotate("", xy=(5.8, y0 + 0.28), xytext=(5.45, y0 + 0.28), arrowprops=dict(arrowstyle="->", color="#2563eb", lw=1.1))

    y1 = 7.85
    box(0.5, y1, 2.2, 0.55, "Conversation +\nFollowUpQuestionResolver", 7)
    box(3.0, y1, 2.4, 0.55, "ConversationContextManager\n(role filter)", 7)
    box(5.8, y1, 2.4, 0.55, "AiAgentService /\nAiToolCallingService", 7)
    ax.annotate("", xy=(3.0, y1 + 0.28), xytext=(2.7, y1 + 0.28), arrowprops=dict(arrowstyle="->", color="#2563eb", lw=1.0))

    y2 = 6.35
    box(1.2, y2, 2.8, 0.55, "AiProviderInterface\n(OpenAI / Claude)", 7)
    box(4.6, y2, 2.8, 0.55, "Model returns\ntool call JSON", 7)
    ax.annotate("", xy=(4.6, y2 + 0.28), xytext=(4.0, y2 + 0.28), arrowprops=dict(arrowstyle="->", color="#2563eb", lw=1.0))
    ax.text(4.15, y2 + 0.7, "Provider proposes tool; Laravel executes it", ha="center", fontsize=6.8, color="#334155")

    y3 = 4.85
    box(1.2, y3, 2.8, 0.55, "ToolExecutor\n(allowlist + schema)", 7)
    box(4.6, y3, 2.8, 0.55, "AiToolInterface\n(CheckStockTool, …)", 7)
    ax.annotate("", xy=(4.6, y3 + 0.28), xytext=(4.0, y3 + 0.28), arrowprops=dict(arrowstyle="->", color="#2563eb", lw=1.0))

    y4 = 3.35
    box(1.2, y4, 3.2, 0.55, "Erp services\n(StockLookupService, …)", 7)
    box(5.0, y4, 3.0, 0.55, "MySQL via Eloquent\n(no model SQL execution)", 7)
    ax.annotate("", xy=(5.0, y4 + 0.28), xytext=(4.35, y4 + 0.28), arrowprops=dict(arrowstyle="->", color="#2563eb", lw=1.0))

    ax.text(
        5,
        2.15,
        "Figure — Assistant request sequence (OpenAI/Claude sync; Ollama branches to Redis worker)",
        ha="center",
        fontsize=9,
        fontweight="bold",
        color="#1e3a5f",
    )
    ax.text(
        5,
        1.72,
        "Ollama: ProcessOllamaAssistantRun + GET /api/assistant/runs/{id}. See fig11_async_ollama_lifecycle.png in submission assets.",
        ha="center",
        fontsize=7.0,
        color="#475569",
    )

    out = ASSETS_GEN / "fig_phase16b_api_sequence.png"
    fig.savefig(out, dpi=150, bbox_inches="tight", facecolor="white")
    plt.close(fig)
    return out


# ---------------------------------------------------------------------------
# docx helpers
# ---------------------------------------------------------------------------


def section_heading(doc, title: str, level: int = 1) -> None:
    doc.add_heading(title, level=level)


def add_table(doc, headers: list[str], rows: list[list[str]]) -> None:
    t = doc.add_table(rows=1, cols=len(headers))
    hdr = t.rows[0].cells
    for i, h in enumerate(headers):
        hdr[i].text = str(h)[:500]
    for row in rows:
        c = t.add_row().cells
        for i, cell in enumerate(row):
            c[i].text = str(cell)[:500]
    doc.add_paragraph()


def excerpt_with_lines(rel: str, start: int, end: int) -> str:
    """Inclusive 1-based line numbers."""
    path = ROOT / rel
    lines = path.read_text(encoding="utf-8", errors="replace").splitlines()
    chunk = lines[start - 1 : end]
    out_lines = [f"{i+start}| {lines[i+start-1]}" for i in range(len(chunk))]
    return "\n".join(out_lines)


def validate_docx(path: Path) -> bool:
    if not path.is_file():
        return False
    with zipfile.ZipFile(path) as z:
        names = z.namelist()
    return "[Content_Types].xml" in names and any(n.startswith("word/") for n in names)


def assert_submission_docs_unchanged_count() -> list[str]:
    """Return list of missing docx among 01–06 (not including 07)."""
    missing: list[str] = []
    # explicit names
    expected = [
        "01-Project-Overview-and-Architecture.docx",
        "02-Business-Workflows-and-User-Guide.docx",
        "03-Installation-and-Developer-Setup.docx",
        "04-API-Security-and-Testing-Reference.docx",
        "05-Interview-and-Demonstration-Guide.docx",
        "06-Final-Acceptance-and-Interview-Preparation.docx",
    ]
    for name in expected:
        if not (ROOT / "docs" / "submission" / name).is_file():
            missing.append(name)
    return missing


def endpoint_reference_paragraphs(r: ApiRouteRow) -> list[str]:
    """Narrative for Section F — one sub-block per route (from source; verify tests for edge HTTP)."""
    handler = r.controller.split("\\")[-1] + "::" + r.action
    mw = r.middleware
    nm = r.name or "(unnamed — only named routes are the four proposal actions)"

    if r.method == "POST" and r.uri == "/api/auth/token":
        return [
            f"{r.method} `{r.uri}` — handler `{handler}`; middleware `{mw}`; route name {nm}.",
            "Authentication: none (public). Validation: IssueTokenRequest — email, password, device_name required.",
            "Success: HTTP 200 JSON `{ \"token\": <plain Bearer>, \"token_type\": \"Bearer\", \"user\": <UserResource> }` — TokenController::store; UserResource exposes id, name, email, role.",
            "Failure: invalid credentials throw AuthenticationException (typically 401 JSON from Laravel's exception handler).",
            "Rate limit: AppServiceProvider registers `api-login` limiter (5/minute keyed by email|IP); route uses `throttle:api-login`.",
        ]

    if r.method == "GET" and r.uri == "/api/user":
        return [
            f"{r.method} `{r.uri}` — `{handler}`; middleware `{mw}`; route name {nm}.",
            "Authentication: Sanctum (Bearer personal access token). No Form Request — resolves user from token.",
            "Success: HTTP 200 `UserResource` JSON: id, name, email, role — CurrentUserController::show.",
            "Failure: 401 if token missing/invalid (Sanctum).",
        ]

    if r.method == "DELETE" and r.uri == "/api/auth/token":
        return [
            f"{r.method} `{r.uri}` — `{handler}`; middleware `{mw}`; route name {nm}.",
            "Authentication: Sanctum. Revokes only the current access token: TokenController::destroy calls currentAccessToken()?->delete().",
            "Success: HTTP 204 No Content (Symfony Response) — no JSON body.",
            "Other device/session tokens for the same user remain valid.",
        ]

    if r.method == "POST" and r.uri == "/api/assistant":
        return [
            f"{r.method} `{r.uri}` — `{handler}`; middleware `{mw}`; route name {nm}.",
            "Body (AssistantChatRequest): `message` required string (max from config); `conversation_id` optional UUID. Unknown JSON keys rejected in withValidator.",
            "Header: optional `Idempotency-Key` — if present and non-empty, `conversation_id` is required (same validation hook).",
            "Auth: Sanctum. If user missing, AssistantController returns explicit 401 JSON (success false, code unauthenticated).",
            "Success path: AssistantRequestService builds AssistantRequestResult; JSON `{ success, data }`.",
            "Ollama (AI_PROVIDER=ollama): HTTP 202, data.async=true, data.run_id, data.status_url; inference in ProcessOllamaAssistantRun — not in this HTTP request.",
            "OpenAI/Claude: HTTP 200, data.message, no data.async; Queue must not receive ProcessOllamaAssistantRun (AssistantAsyncTest).",
            "Clarification: HTTP 200, data.clarification_required=true — no assistant_runs row (finalizeClarification).",
            "Idempotency errors: AssistantIdempotencyException — 409 body_mismatch, 503 in_progress; async replay returns same run (202).",
            "Other failures: ConversationAccessException → 404/403; run terminal errors via status poll use safe error.code/message.",
        ]

    if r.method == "GET" and "/assistant/runs/" in r.uri:
        return [
            f"{r.method} `{r.uri}` — `{handler}`; middleware `{mw}`.",
            "Auth: Sanctum. AssistantRunController::show loads run by public_id; 404 if missing or user_id mismatch (no cross-user leakage).",
            "AssistantRunCoordinator::statusPayload — reconciles orphaned queued runs and stale processing before response.",
            "Statuses: queued, processing, completed, failed. Terminal completed includes data.answer; failed includes data.error (no exception text).",
            "Client polls until completed or failed — resources/js/chat/assistantPolling.js; refresh recovery in main.js (run_id + user id + message text, no bearer token).",
        ]

    if r.method == "GET" and r.uri == "/api/products":
        return [
            f"{r.method} `{r.uri}` — `{handler}`; middleware `{mw}`.",
            "Form Request: ProductSearchRequest — optional filters sku, name, material, color, thickness_cm, limit (see rules()).",
            "Authorization: StockLookupService applies ERP Gate checks internally from the authenticated user.",
            "Success: HTTP 200 `ProductResource::collection` (top-level `data` array is Laravel's default for API Resources).",
        ]

    if r.method == "GET" and r.uri == "/api/stock":
        return [
            f"{r.method} `{r.uri}` — `{handler}`; middleware `{mw}`.",
            "Form Request: StockSearchRequest — supports product_id+warehouse_id for single-row mode, or attribute filters (sku/name/… + warehouse_name/city) per StockController::index.",
            "Success: single `StockLevelResource` object or collection (data wrapper depends on resource collection usage).",
        ]

    if r.method == "GET" and r.uri == "/api/purchase-orders":
        return [
            f"{r.method} `{r.uri}` — `{handler}`; middleware `{mw}`.",
            "Form Request: PurchaseOrderSearchRequest — filters supplier, status (enum), dates, product, pagination page/limit.",
            "Success: explicit JSON `{ \"data\": [...], \"meta\": {...} }` — PurchaseOrderController returns merged resource collection + pagination meta from PurchaseOrderLookupService::search.",
            "Sales-facing shape: PurchaseOrderResource lines omit `unit_cost` while still listing quantities — compare app/Http/Resources/Api/PurchaseOrderResource.php.",
        ]

    if r.method == "GET" and r.uri == "/api/purchase-orders/overdue":
        return [
            f"{r.method} `{r.uri}` — `{handler}`; middleware `{mw}`.",
            "Form Request: OverduePurchaseOrderRequest — optional `as_of` date and `limit`.",
            "Success: HTTP 200 OverduePurchaseOrderResource collection.",
        ]

    if r.method == "GET" and "/landed-cost" in r.uri:
        return [
            f"{r.method} `{r.uri}` — `{handler}`; middleware `{mw}`.",
            "Implicit route-model binding: `{purchaseOrder}` resolves PurchaseOrder by id (segment name matches parameter).",
            "Authorization + calculation: LandedCostService::calculateForPurchaseOrder calls authorizeLandedCosts (Purchasing-only); Sales receives 403 via Gate in tests.",
            "Success: HTTP 200 `LandedCostResource` wrapping calculateForPurchaseOrder result.",
            "Failure: LandedCostCalculationException caught in controller → abort(404, message) (missing PO or no lines — not necessarily a missing route).",
        ]

    if r.method == "POST" and r.uri == "/api/purchase-order-proposals":
        return [
            f"{r.method} `{r.uri}` — `{handler}`; middleware `{mw}`; route name `{nm}`.",
            "Form Request: CreatePurchaseOrderProposalRequest + AuthorizesPurchasingProposalAccess — requires Purchasing Gate createDraftPurchaseOrders.",
            "Headers/body: `Idempotency-Key` header merged to `idempotency_key` (required); JSON `conversation_key` plus supplier_id, order_date, eta, freight_cost, lines[...] per rules().",
            "Service: DraftPurchaseOrderService::createProposal — stores normalized JSON + payload_hash; TTL from config erp.purchase_order_proposal_ttl_minutes.",
            "Success: HTTP 201 for a brand-new row, HTTP 200 when the same user already had a row for that idempotency key (PurchaseOrderProposalController::store checks exists() before create).",
            "Failure: mapped PurchaseOrderProposalException → JSON `{ message }` with status from PurchaseOrderProposalHttpExceptionMapper (bootstrap/app.php).",
            "Replay: same (user_id, idempotency_key) with matching payload_hash returns existing proposal; hash mismatch throws idempotency conflict — DraftPurchaseOrderService::createProposal.",
        ]

    if r.method == "GET" and r.uri.startswith("/api/purchase-order-proposals/"):
        return [
            f"{r.method} `{r.uri}` — `{handler}`; middleware `{mw}`; route name `{nm}`.",
            "Form Request: ConversationScopedProposalRequest — requires `conversation_key` (query or JSON) and Purchasing authorization.",
            "Service: DraftPurchaseOrderService::getProposal — ownership + expiry handling.",
            "Success: HTTP 200 `PurchaseOrderProposalResource` (includes line unit_cost — purchasing snapshot).",
        ]

    if r.method == "POST" and "/confirm" in r.uri:
        return [
            f"{r.method} `{r.uri}` — `{handler}`; middleware `{mw}`; route name `{nm}`.",
            "Form Request: ConversationScopedProposalRequest — conversation_key + auth gate.",
            "Service: DraftPurchaseOrderService::confirmProposal — DB::transaction, lockForUpdate() on proposal row, assertPayloadIntegrity, then creates PurchaseOrder + PurchaseOrderLine rows with Draft status.",
            "Success: HTTP 200 `PurchaseOrderProposalConfirmationResource` (see resource for fields).",
            "Failure states: expired/cancelled/wrong state → PurchaseOrderProposalException (mapped). Idempotent confirm returns existing confirmed snapshot when already confirmed.",
        ]

    if r.method == "DELETE" and r.uri.startswith("/api/purchase-order-proposals/"):
        return [
            f"{r.method} `{r.uri}` — `{handler}`; middleware `{mw}`; route name `{nm}`.",
            "Form Request: ConversationScopedProposalRequest — conversation_key required.",
            "Service: DraftPurchaseOrderService::cancelProposal — transaction + lockForUpdate; cannot cancel confirmed proposals.",
            "Success: HTTP 200 `PurchaseOrderProposalResource` for pending→cancelled or already-cancelled replay.",
        ]

    return [f"{r.method} `{r.uri}` — `{handler}`; middleware `{mw}`; name `{nm}` (add narrative in generator — verify routes/api.php)."]


# ---------------------------------------------------------------------------
# Interview Q&A
# ---------------------------------------------------------------------------


def _qa_answer_parts(what: str, how: str, where: str, why: str, failure: str) -> str:
    return (
        f"What: {what}\n"
        f"How: {how}\n"
        f"Where: {where}\n"
        f"Why: {why}\n"
        f"Failure / limit: {failure}"
    )


def build_interview_qa(
    api_routes: list[ApiRouteRow],
    n_tables: int,
    n_cols: int,
    n_files: int,
) -> list[tuple[str, str, str]]:
    """Substantive interview Q&A only (≥100). File-path index is Appendix, not counted here."""
    qa: list[tuple[str, str, str]] = []
    seen_q: set[str] = set()

    def add(cat: str, q: str, a: str) -> None:
        if q in seen_q:
            return
        seen_q.add(q)
        qa.append((cat, q, a))

    # --- Reviewer topics (12) + cross-cutting themes ---
    add(
        "AI factory",
        "Why does AiProviderFactory name Bedrock, Gemini, and Groq if they are not implemented?",
        _qa_answer_parts(
            "Explicit unsupported-provider errors instead of silent misconfiguration.",
            "match on provider string throws AiProviderException with CODE_UNSUPPORTED_PROVIDER for bedrock/gemini/groq.",
            "app/Services/AI/AiProviderFactory.php::make().",
            "Fail fast when .env names a provider the codebase does not ship.",
            "A typo in AI_PROVIDER still throws — operator must fix config.",
        ),
    )
    add(
        "Tools",
        "Why are ToolRegistry and ToolExecutor separate classes?",
        _qa_answer_parts(
            "Registry holds allowlisted tool implementations; Executor validates and dispatches.",
            "ToolRegistry::get / definitionsFor; ToolExecutor::execute normalizes args, strips forbidden keys, checks schema, calls AiToolInterface::execute.",
            "app/Services/AI/Tools/ToolRegistry.php; ToolExecutor.php.",
            "Separation keeps registration (DI construction) separate from per-call security and validation.",
            "Executor returns unknown_tool if name is not registered — registry must stay in sync with provider tool list.",
        ),
    )
    add(
        "Tools",
        "What do tool error codes unknown_tool, forbidden_arguments, and permission_denied mean?",
        _qa_answer_parts(
            "Three distinct failure classes after the model proposes a tool call.",
            "unknown_tool: registry.get returned null. forbidden_arguments: model sent a key in FORBIDDEN_ARGUMENT_KEYS. permission_denied: allowedFor false or AuthorizationException from a tool.",
            "ToolExecutor::execute() return branches.",
            "Distinguish misconfiguration / attack surface from role policy.",
            "Schema validation failures use validation_error — different from these three.",
        ),
    )
    add(
        "Tools",
        "Why are conversation_key and idempotency_key rejected when sent as tool arguments?",
        _qa_answer_parts(
            "Those values must come only from trusted server context for proposals.",
            "FORBIDDEN_ARGUMENT_KEYS list in ToolExecutor::stripForbiddenKeys.",
            "ToolExecutor.php constants.",
            "Prevent prompt-injection from elevating privileges or replaying another user's proposal scope.",
            "If omitted server-side, proposeDraftPurchaseOrder fails with missing_trusted_context — by design.",
        ),
    )
    add(
        "Proposals",
        "What does lockForUpdate() protect in draft purchase-order confirmation?",
        _qa_answer_parts(
            "Row-level lock on the proposal row inside a DB transaction during confirm/cancel.",
            "PurchaseOrderProposal::query()->...->lockForUpdate()->first() before state checks and PO creation.",
            "DraftPurchaseOrderService::confirmProposal and ::cancelProposal.",
            "Reduce lost updates if two confirm requests race (MySQL/InnoDB semantics).",
            "Sequential feature tests do not prove concurrent safety — not claimed as fully verified.",
        ),
    )
    add(
        "Proposals",
        "Why can different Idempotency-Key values create different purchase_order_proposals rows?",
        _qa_answer_parts(
            "Proposal idempotency is per (user_id, idempotency_key), not global.",
            "DraftPurchaseOrderService::createProposal looks up existing by user + idempotency_key; new key → new row.",
            "DraftPurchaseOrderService.php; unique index purchase_order_proposals (user_id, idempotency_key).",
            "Allow multiple pending drafts per user for different intents.",
            "Collision only if client reuses key with different payload → payload_hash conflict exception.",
        ),
    )
    add(
        "Proposals",
        "Why is a proposal not the same as a purchase order?",
        _qa_answer_parts(
            "Proposal is a pending snapshot + hash; PO is created only after HTTP confirm.",
            "confirmProposal creates PurchaseOrder + lines with Draft status after payload integrity checks.",
            "DraftPurchaseOrderService::confirmProposal vs ::createProposal.",
            "Explicit human/API confirmation boundary — matches product rule not to claim PO creation from tool alone.",
            "Expired or cancelled proposals cannot confirm — PurchaseOrderProposalException.",
        ),
    )
    add(
        "Proposals",
        "What happens when payload_hash does not match stored hash at confirmation?",
        _qa_answer_parts(
            "Integrity failure before creating purchase_orders rows.",
            "assertPayloadIntegrity compares hash of JSON payload; throws on mismatch.",
            "DraftPurchaseOrderService::assertPayloadIntegrity (called from confirmProposal).",
            "Detect manual DB tampering or corrupted JSON.",
            "User sees mapped JSON error from PurchaseOrderProposalHttpExceptionMapper — see bootstrap/app.php.",
        ),
    )
    add(
        "Landed cost",
        "How does freight remainder allocation work after half-up rounding?",
        _qa_answer_parts(
            "Rounded per-line shares may not sum to total freight — remainder goes to the largest line value.",
            "Sum roundedShares; remainder = freight - allocated; add to indexOfLargestLineValue.",
            "LandedCostService::calculateForPurchaseOrder; ErpMoney for decimal half-up.",
            "Standard currency reconciliation pattern.",
            "If total line value is zero, calculation aborts — cannot allocate.",
        ),
    )
    add(
        "Context",
        "How does ConversationContextManager filter tool rows for Sales vs Purchasing?",
        _qa_answer_parts(
            "Sales must not see prior getLandedCost tool payloads in chat history.",
            "includeToolRowForUser returns false for tool_name getLandedCost when user is not purchasing.",
            "ConversationContextManager::buildAiMessages → includeToolRowForUser.",
            "Defense in depth with ToolResultRedactor; DB-stored rows could still leak without this filter.",
            "Purchasing sees all tool rows — broader context surface.",
        ),
    )
    add(
        "Infra",
        "Why is Ollama asynchronous while OpenAI and Claude stay synchronous?",
        _qa_answer_parts(
            "Ollama local inference can exceed HTTP timeouts; OpenAI/Claude challenge paths remain unchanged synchronous 200.",
            "usesAsyncAssistant() → AiProviderProfile::isOllama(); enqueueAfterUserMessage dispatches ProcessOllamaAssistantRun.",
            "AssistantRequestService.php; AssistantRunCoordinator.php; ProcessOllamaAssistantRun.php.",
            "Isolate long work to a worker with 900s timeout without changing OpenAI/Claude drivers.",
            "Moving OpenAI async later would need new UX, idempotency over full job, and provider test split — not done here.",
        ),
    )
    add(
        "Redis",
        "Why does .env.example set REDIS_CLIENT=predis while config defaults to phpredis?",
        _qa_answer_parts(
            "Laravel default favors phpredis C extension; project examples target pure-PHP Predis for portability.",
            "config/database.php redis client default env('REDIS_CLIENT', 'phpredis'); .env.example and docker/compose.env.example set predis.",
            "config/database.php lines around redis client; .env.example.",
            "Windows/demo setups may lack phpredis — Predis avoids extension install.",
            "Performance: phpredis is typically faster when available.",
        ),
    )

    # --- Sanctum, RBAC, API ---
    add(
        "Auth",
        "How does Sanctum token lifecycle work for this API?",
        _qa_answer_parts(
            "Login creates a personal_access_tokens row; API uses Bearer token; DELETE revokes current token only.",
            "TokenController::store createToken; destroy calls currentAccessToken()?->delete().",
            "TokenController.php; IssueTokenRequest validates credentials.",
            "Standard SPA/API token pattern.",
            "Other device tokens remain after destroy — not a global logout.",
        ),
    )
    add(
        "Roles",
        "How does role-based access differ between Sales and Purchasing?",
        _qa_answer_parts(
            "UserRole enum on users; Gates split ERP read vs cost/supplier/proposal capabilities.",
            "ErpGateRegistrar: Sales + Purchasing share viewProducts/viewStock/viewPurchaseOrderStatus; Purchasing-only VIEW_COSTS, VIEW_FREIGHT_COSTS, VIEW_LANDED_COSTS, VIEW_SUPPLIER_BANK_DETAILS, CREATE_DRAFT_PURCHASE_ORDERS.",
            "app/Auth/ErpGateRegistrar.php; ErpAbilities.php.",
            "Central ability names — controllers and tools must use Gate, not prompts alone.",
            "Sales cannot call landed-cost API — LandedCostService::authorizeLandedCosts.",
        ),
    )
    add(
        "Validation",
        "How does API validation defense work beyond Form Requests?",
        _qa_answer_parts(
            "AssistantChatRequest strips unknown body keys; ToolExecutor validates tool args against JSON schema.",
            "AssistantChatRequest::withValidator loops keys; ToolExecutor::validateArgumentsAgainstToolSchema.",
            "Per-request classes under app/Http/Requests/Api/.",
            "Close open object schemas where safe to reject prompt injections.",
            "Open additionalProperties tools skip strict key check — documented in ToolExecutor.",
        ),
    )

    # --- Routes snapshot (dynamic) ---
    route_by_uri = {r.uri: r for r in api_routes}
    for label, uri, extra in [
        ("POST /api/auth/token", "/api/auth/token", "IssueTokenRequest; 200 { token, token_type, user }."),
        ("GET /api/user", "/api/user", "UserResource: id, name, email, role."),
        ("DELETE /api/auth/token", "/api/auth/token", "204 no content."),
        ("POST /api/assistant", "/api/assistant", "AssistantChatRequest; Idempotency-Key needs conversation_id."),
        ("POST /api/purchase-order-proposals", "/api/purchase-order-proposals", "CreatePurchaseOrderProposalRequest; 201 vs 200 replay."),
    ]:
        r = route_by_uri.get(uri)
        mw = r.middleware if r else "—"
        add("API", f"What middleware applies to {label}?", f"Inherited + route-specific: {mw}. {extra} Source: routes/api.php.")

    add(
        "Schema",
        f"How many tables and columns does the handbook migration parser report?",
        _qa_answer_parts(
            f"{n_tables} application tables from migrations; {n_cols} column rows (including morph columns).",
            "parse_migrations_detailed walks Schema::create/table up() callbacks only.",
            "tools/build_phase16_interview_handbook.py; database/migrations/*.php.",
            "Reconcile handbook tables with live DB after migrate.",
            "Runtime `migrations` table is not in repo migrations — excluded from count.",
        ),
    )
    add(
        "Project",
        f"What is the source file inventory count and what is excluded?",
        _qa_answer_parts(
            f"{n_files} text/source paths under app, config, database, routes, tests, tools (filtered), etc.",
            "Excludes vendor, node_modules, storage, tools/composer/**, tools/mysql/**, __pycache__, *.pyc.",
            "count_inventory_files() in generator.",
            "Count deliverable source, not Composer or MySQL data dirs.",
            "Diagram binary outputs under docs/submission/assets may exist separately from this list.",
        ),
    )

    # --- Pad to ≥100 with repository-specific items (abbreviated answers) ---
    extras: list[tuple[str, str, str]] = [
        (
            "AI",
            "What does AiAgentService::complete do?",
            _qa_answer_parts(
                "Single entry point for a non-tool 'plain' completion (e.g. follow-up JSON resolver).",
                "Delegates to AiProviderFactory::make()->complete($request).",
                "app/Services/AI/AiAgentService.php.",
                "Keeps callers independent of OpenAI vs Claude.",
                "Unsupported provider throws AiProviderException — not caught as success.",
            ),
        ),
        (
            "AI",
            "What is AiProviderInterface responsible for?",
            _qa_answer_parts(
                "Abstract complete(AiRequest): AiResponse for any vendor.",
                "Implemented by OpenAiProvider and ClaudeProvider only in this repo.",
                "app/Contracts/AiProviderInterface.php.",
                "Enables swapping vendors without changing FollowUpQuestionResolver beyond factory.",
                "No Bedrock/Gemini/Groq implementations — factory throws if configured.",
            ),
        ),
        (
            "Follow-up",
            "How does deterministic_city_swap work with Dallas?",
            _qa_answer_parts(
                "Short follow-up like 'in Dallas?' replaces Houston/Dallas/Austin in the prior user question when that prior question contained one of those cities.",
                "tryDeterministic regex + preg_replace on baseQuestion (metadata resolved_standalone_question or previous user content).",
                "FollowUpQuestionResolver::tryDeterministic.",
                "Avoids LLM for common warehouse city swaps.",
                "If prior question had no recognized city, resolver falls through to AI or clarification.",
            ),
        ),
        (
            "Follow-up",
            "Where does resolved_standalone_question metadata get set for the model?",
            _qa_answer_parts(
                "Merged onto the user message row after resolution; mapMessage prefers it over raw content for User role.",
                "ConversationService::mergeUserMessageResolution; ConversationContextManager::mapMessage.",
                "Not the same as deterministic_city_swap — swap produces resolvedStandaloneQuestion in ContextResolutionResult first.",
                "Keeps provider context aligned with expanded question.",
                "Clarification turns skip full AI pass — metadata reflects strategy only.",
            ),
        ),
        (
            "Assistant",
            "How does assistant Idempotency-Key replay work?",
            _qa_answer_parts(
                "Same user + key + conversation returns stored idempotency_response; body mismatch → 409.",
                "Cache lock assistant:idem:{user}:{key}; DB unique (conversation_id, client_idempotency_key) where key non-null.",
                "AssistantRequestService::handleWithClientIdempotency; migration 2026_11_06_220000.",
                "Safe retries after network failures.",
                "503 if lock not acquired or prior row never finished storing response.",
            ),
        ),
        (
            "Tools",
            "What limits tool-call iteration and repeat detection?",
            _qa_answer_parts(
                "Hard stop when iteration count or identical tool-call signature repeats exceed config thresholds.",
                "AiToolLoopException thrown from AiToolCallingService::run loop; mapped to HTTP 422 in AssistantRequestService::mapOutcomeFailure.",
                "app/Services/AI/AiToolCallingService.php; config/ai.php (`tool_loop`).",
                "Prevent infinite loops from a misbehaving model.",
                "Limits are environment-specific — read config/ai.php; not re-printed as fixed numbers here.",
            ),
        ),
        (
            "Security",
            "What prompt-injection protections exist besides forbidden keys?",
            _qa_answer_parts(
                "System prompt forbids inventing data; tools only; role line restricts cost tools for Sales.",
                "AssistantRequestService::buildSystemInstruction; ToolExecutor schema closure.",
                "Same files as above.",
                "LLM can still try — server validation is authoritative.",
                "Not a formal security audit — defense in depth, not proof.",
            ),
        ),
        (
            "Security",
            "How is sensitive supplier data redacted from tool results?",
            _qa_answer_parts(
                "Recursive removal of bank/token-like keys before persisting successful tool payloads.",
                "ToolResultRedactor::redactExecutionResult strips FORBIDDEN_RESULT_KEYS (bank_details, iban, swift, …) from nested arrays.",
                "app/Services/AI/Conversations/ToolResultRedactor.php.",
                "Defense in depth alongside encrypted Supplier model and Sales context filtering.",
                "Redactor only runs on success payloads — errors may still need careful handling if they echo input.",
            ),
        ),
        (
            "Persistence",
            "How are conversation messages stored?",
            _qa_answer_parts(
                "conversation_messages rows with role, content, metadata JSON, sequence ordering.",
                "ConversationService append/merge methods called from AssistantRequestService.",
                "app/Models/ConversationMessage.php; migrations create table + idempotency columns.",
                "Auditable trail separate from provider transcript.",
                "Large content still bounded by assistant max_message_chars config.",
            ),
        ),
        (
            "Summaries",
            "How are conversation summaries used?",
            _qa_answer_parts(
                "Latest summary prepended as system message prefix in buildAiMessages.",
                "ConversationContextManager loads conversation->latestSummary.",
                "conversation_summaries table; Conversation model relation.",
                "Compress history for token budget.",
                "Summary may omit details — comment in code warns the model.",
            ),
        ),
        (
            "Transactions",
            "Where are DB transaction boundaries for assistant turns?",
            _qa_answer_parts(
                "Per-step transactions: lock conversation, append user message, merge resolution, append assistant.",
                "Multiple DB::transaction blocks in AssistantRequestService::continueAfterUserMessagePersisted and finalizeClarification.",
                "AssistantRequestService.php.",
                "Short transactions reduce lock time.",
                "AI call happens outside some locks — deliberate to avoid holding DB during provider latency.",
            ),
        ),
        (
            "Proposals",
            "How does proposal expiry work?",
            _qa_answer_parts(
                "expires_at set from config erp.purchase_order_proposal_ttl_minutes; expireIfNeeded transitions status.",
                "DraftPurchaseOrderService::createProposal sets expiresAt; confirm checks isPast.",
                "DraftPurchaseOrderService.php.",
                "Bound how long a draft snapshot stays confirmable.",
                "TTL change affects only new proposals.",
            ),
        ),
        (
            "Money",
            "How does ErpMoney enforce precision for landed cost?",
            _qa_answer_parts(
                "String decimal math with explicit roundCurrency / half-up style operations.",
                "LandedCostService uses ErpMoney::mul/div/add/sub/roundCurrency.",
                "app/Services/Erp/ErpMoney.php.",
                "Avoid float drift in monetary lines.",
                "Invalid quantity ≤0 aborts before allocation.",
            ),
        ),
        (
            "Worked example",
            "Walk through landed cost numbers: 10×$100 and 20×$200 with $500 freight.",
            _qa_answer_parts(
                "Line values $1,000 and $4,000; total $5,000; shares $100 and $400; landed unit $110 and $220.",
                "freight_share = freight × line_value / total; freight_per_unit = share/qty; landed = unit_cost + per_unit.",
                "LandedCostService::calculateForPurchaseOrder.",
                "Value-proportional allocation per README/design.",
                "Remainder cents may adjust largest line — check freight_reconciliation in JSON.",
            ),
        ),
        (
            "Testing",
            "What is the honest testing posture for this handbook?",
            _qa_answer_parts(
                "php artisan test should be re-run before interviews; counts in doc are not claimed as current.",
                "Feature tests under tests/Feature verify API gates and flows.",
                "phpunit.xml; tests/Feature/Api/ErpReadApiTest.php etc.",
                "Do not claim live AI or Docker runtime verification from doc generation alone.",
                "UI acceptance UI-01–UI-09 remain user-verified pending.",
            ),
        ),
        (
            "Portability",
            "What would change for another AI provider?",
            _qa_answer_parts(
                "New class implementing AiProviderInterface, register in AiProviderFactory match, config ai.provider.",
                "OpenAiProvider / ClaudeProvider as templates.",
                "app/Services/AI/Providers/.",
                "Factory already centralizes selection.",
                "Streaming, billing, and latency not implemented — would be new work.",
            ),
        ),
        (
            "Limitations",
            "What major limitations should you state honestly?",
            _qa_answer_parts(
                "No claim of full concurrency testing; live provider calls not verified in handbook build; Docker runtime unverified; some test counts stale.",
                "Documented in Section Y and config comments.",
                "This generator; README.",
                "Interview integrity.",
                "Replace with fresh php artisan test and CI logs when needed.",
            ),
        ),
        (
            "Schema",
            "What unique constraint applies to conversation_tool_calls for provider_tool_call_id?",
            _qa_answer_parts(
                "Composite unique (conversation_id, user_message_id, provider_tool_call_id) after migration 210000.",
                "Same provider id can repeat across turns — scoping fixed in up().",
                "2026_11_06_210000_update_conversation_tool_calls_unique_key.php.",
                "Match OpenAI tool call id semantics across multi-turn chats.",
                "down() in migration is documentation-only for handbook — we do not document dropped keys as active.",
            ),
        ),
        (
            "Schema",
            "conversation_messages: what is the relationship between public_id and turn_idempotency_key?",
            _qa_answer_parts(
                "public_id is client-facing UUID for the message row; turn_idempotency_key is optional server-side uniqueness for a user turn (nullable unique).",
                "Both columns in create + comments in migration; different purposes — public correlation vs internal turn dedupe.",
                "2026_11_06_200001_create_conversation_messages_table.php.",
                "Do not conflate — neither replaces conversation-scoped client Idempotency-Key.",
                "client_idempotency_key adds composite unique with conversation_id for HTTP retries — migration 220000.",
            ),
        ),
    ]
    for cat, q, a in extras:
        add(cat, q, a)

    phase166 = [
        (
            "Queue",
            "What happens if Redis is unavailable when Ollama accepts a request?",
            _qa_answer_parts(
                "Dispatch exception marks the run failed with queue_dispatch_failed — user sees safe message, not Redis internals.",
                "dispatchRunToRedisQueue catch → markFailedIfActive.",
                "AssistantRunCoordinator.php.",
                "Fail fast instead of infinite queued with no worker.",
                "Transient blip: client may retry a new message — idempotency key replays same run when row exists.",
            ),
        ),
        (
            "Queue",
            "What happens if a worker dies while processing?",
            _qa_answer_parts(
                "Laravel may invoke failed() on timeout/exception → queue_job_failed; SIGKILL may skip failed() until stale poll.",
                "reconcileStaleProcessing at 1080s; failed() in ProcessOllamaAssistantRun.",
                "AssistantRunCoordinator; ProcessOllamaAssistantRun.php.",
                "Bounded recovery without claiming perfect crash detection.",
                "No poll → run may stay processing until next GET status.",
            ),
        ),
        (
            "Queue",
            "Why does the API return HTTP 202 instead of 200 for Ollama?",
            _qa_answer_parts(
                "Accept work quickly; long inference runs in queue worker.",
                "acceptanceResult returns httpStatus 202 with async payload.",
                "AssistantRunCoordinator::acceptanceResult.",
                "Avoid gateway timeouts on slow local models.",
                "OpenAI/Claude still 200 — provider gate in usesAsyncAssistant.",
            ),
        ),
        (
            "Queue",
            "How do you avoid duplicate AI execution for the same run?",
            _qa_answer_parts(
                "Worker claims queued→processing under lockForUpdate; second worker no-ops.",
                "processQueuedAssistantRun claim transaction.",
                "AssistantRequestService.php.",
                "Primary safeguard — not ShouldBeUnique on job.",
                "Duplicate Redis payloads possible under concurrent polls — claim still single-winner.",
            ),
        ),
        (
            "Queue",
            "What if a queue payload disappears but the run stays queued?",
            _qa_answer_parts(
                "Bounded re-dispatch on poll, idempotent replay, and 202 acceptance after grace+lease.",
                "reconcileOrphanedQueuedRun; max 5 attempts.",
                "AssistantRunCoordinator.",
                "No Redis payload inspection — lease/attempt policy.",
                "Exhaustion → queue_recovery_exhausted; may fail a still-delayed job (16.6F).",
            ),
        ),
        (
            "Queue",
            "Why use afterCommit() when dispatching the assistant job?",
            _qa_answer_parts(
                "Enqueue only after assistant_runs row is committed so worker never sees a missing row.",
                "dispatchRunToRedisQueue uses afterCommit when DB::transactionLevel() > 0.",
                "AssistantRunCoordinator.php.",
                "Prevent race where worker starts before commit.",
                "Tests without open transaction dispatch immediately — conditional afterCommit.",
            ),
        ),
        (
            "Queue",
            "Why is Redis retry_after longer than worker timeout?",
            _qa_answer_parts(
                "Avoid Redis reserving a job while worker still processing within --timeout.",
                "Default retry_after 960s vs job 900s in config.",
                "config/queue.php; ProcessOllamaAssistantRun::timeout.",
                "Standard Laravel queue timing discipline.",
                "Misconfiguration can cause duplicate worker attempts — document ops table.",
            ),
        ),
        (
            "Frontend",
            "How does the frontend recover after a page refresh during Ollama polling?",
            _qa_answer_parts(
                "sessionStorage keeps run_id, user id, pending message text — not bearer token.",
                "tryResumePendingAsyncRun; shouldResumePendingRun.",
                "resources/js/chat/main.js; assistantPolling.js.",
                "Same-tab UX without persisting full transcript.",
                "New conversation clears storage but server job may continue (16.6F).",
            ),
        ),
        (
            "Providers",
            "Why does OpenAI remain synchronous?",
            _qa_answer_parts(
                "Challenge baseline: preserve OpenAI driver, prompts, and HTTP 200 contract.",
                "usesAsyncAssistant false unless isOllama.",
                "AiProviderProfile; AssistantAsyncTest assertNothingPushed.",
                "Avoid regressing provider isolation and fakes.",
                "Async OpenAI would need polling UX and extended idempotency design.",
            ),
        ),
        (
            "Providers",
            "How would you move OpenAI to async processing later?",
            _qa_answer_parts(
                "Same pattern as Ollama: run row, job class, 202 contract, provider-specific job or shared job with provider flag.",
                "Mirror AssistantRunCoordinator + claim semantics.",
                "Would touch AssistantRequestService only behind profile gate.",
                "Keep OpenAI prompts/drivers unchanged until explicitly migrated.",
                "Must add observability and clarify idempotency over full job duration.",
            ),
        ),
        (
            "Queue",
            "What are the limitations of the current recovery strategy?",
            _qa_answer_parts(
                "No proof a specific Redis job exists; lease not atomic with dispatch; in-method cache dedupe only.",
                "Documented Phase 16.6F medium findings.",
                "docs/PHASE16.6C; README limitations.",
                "Honest ops expectations for challenge vs production.",
                "Acceptable for demo; production needs metrics and optional queue inspection.",
            ),
        ),
        (
            "Ops",
            "How would you monitor stuck jobs in production?",
            _qa_answer_parts(
                "Query assistant_runs by status and started_at; alert on processing > stale threshold; queue:failed.",
                "No built-in scheduler — recovery is poll-driven today.",
                "assistant_runs table; artisan queue:failed.",
                "Close gap left by unverified SIGKILL path.",
                "Would add horizon/metrics in real deployment.",
            ),
        ),
        (
            "Queue",
            "What does ProcessOllamaAssistantRun::failed() do?",
            _qa_answer_parts(
                "Marks run queue_job_failed if still active; swallows handler errors.",
                "markFailedIfActive; empty catch in failed().",
                "ProcessOllamaAssistantRun.php.",
                "Safe terminal state without exception text in DB/API.",
                "Does not run if job 'succeeds' after in-handle catch marks failed.",
            ),
        ),
        (
            "Queue",
            "Difference between in-handle catch and Laravel failed()?",
            _qa_answer_parts(
                "Catch in processQueuedAssistantRun → assistant_execution_failed, job completes; failed() → queue_job_failed on worker terminal failure.",
                "Two paths in AssistantRequestService vs job failed().",
                "Tests in ProcessOllamaAssistantRunFailedTest.",
                "Clarify observability for interview.",
                "SIGKILL may use neither until stale reconciliation.",
            ),
        ),
        (
            "Queue",
            "What triggers queue_recovery_exhausted?",
            _qa_answer_parts(
                "queue_dispatch_attempts >= max (default 5) on reconcile.",
                "reconcileOrphanedQueuedRun early check.",
                "AssistantRunCoordinator.",
                "Prevent infinite queued without operator action.",
                "May fail while delayed job still in Redis — bounded trade-off.",
            ),
        ),
        (
            "Security",
            "Who can poll GET /api/assistant/runs/{runId}?",
            _qa_answer_parts(
                "Only owning user — 404 for others.",
                "AssistantRunController user_id check.",
                "AssistantRunController.php.",
                "Prevent cross-user run enumeration.",
                "404 also used for missing id — no existence leak distinction.",
            ),
        ),
        (
            "Idempotency",
            "Does the HTTP idempotency lock cover the full Ollama job?",
            _qa_answer_parts(
                "No — lock released after HTTP 202; job may run minutes.",
                "handleWithClientIdempotency finally releases lock.",
                "AssistantRequestService.",
                "DB claim + single try prevent duplicate inference.",
                "Concurrent idempotency not stress-tested.",
            ),
        ),
        (
            "Clarification",
            "When is no assistant_runs row created?",
            _qa_answer_parts(
                "Clarification path returns 200 before async enqueue.",
                "finalizeClarification in orchestrateTurnAfterUserMessagePersisted.",
                "AssistantRequestService.php.",
                "User answers clarification synchronously.",
                "Ollama still async only after resolution proceeds to inference.",
            ),
        ),
        (
            "Testing",
            "Does Queue::fake prove production Redis recovery?",
            _qa_answer_parts(
                "No — asserts dispatch counts only.",
                "AssistantRunRecoveryTest uses Queue::fake.",
                "tests/Feature/Api/AssistantRunRecoveryTest.php.",
                "Separate Redis probe test for worker consumption.",
                "Ollama E2E still unverified.",
            ),
        ),
        (
            "Workers",
            "Why must queue-worker.ps1 Set-Location to project root?",
            _qa_answer_parts(
                "Artisan and config:show must resolve regardless of caller cwd.",
                "Resolve-Path $PSScriptRoot\\..; absolute artisan in argv.",
                "scripts/queue-worker.ps1.",
                "Fix H3 from 16.6D audit.",
                "QueueWorkerScriptTest dry-run from %TEMP%.",
            ),
        ),
        (
            "Frontend",
            "What happens on 401 during run polling?",
            _qa_answer_parts(
                "Clear pending storage and session without writing to stale message index.",
                "pollAsyncRunToTerminal unauthenticated branch.",
                "main.js.",
                "Prevent JS errors after logout mid-poll.",
                "Send-path 401 still differs slightly — clears messages via clearSession.",
            ),
        ),
        (
            "Audit",
            "Is Phase 16.6 queue implementation production-perfect?",
            _qa_answer_parts(
                "No — Phase 16.6F PASS WITH FINDINGS: duplicate payloads, no Redis proof, poll-driven recovery.",
                "Documented limitations Section Y.",
                "Independent audit 16.6F.",
                "Acceptable for challenge; hardening for prod.",
                "Do not overclaim in interviews.",
            ),
        ),
    ]
    for cat, q, a in phase166:
        add(cat, q, a)

    # More short high-value items to reach ≥100
    fillers: list[tuple[str, str, str]] = []
    topics = [
        ("HTTP", "What returns 401 on assistant?", "AssistantController::store returns JSON unauthenticated if $request->user() null — before service."),
        ("HTTP", "What status for ConversationAccessException not found?", "404 — AssistantController maps CODE_NOT_FOUND."),
        ("ERP", "Does PurchaseOrderResource expose unit_cost to Sales?", "No — lines omit unit_cost by design in PurchaseOrderResource::toArray."),
        ("ERP", "Which service searches products?", "StockLookupService::searchProducts from ProductController::index."),
        ("ERP", "Single stock row response path?", "StockController passes product_id+warehouse_id to stockForProductAtWarehouse."),
        ("Proposal", "HTTP 201 vs 200 on proposal create?", "PurchaseOrderProposalController::store sets 201 new, 200 idempotent replay when row pre-existed."),
        ("Proposal", "Who authorizes proposal routes?", "AuthorizesPurchasingProposalAccess trait on Form Requests — Gate createDraftPurchaseOrders."),
        ("Cache", "What is cache table primary key?", "cache.key string primary — migration 0001 cache."),
        ("Jobs", "What type is jobs.attempts?", "unsignedTinyInteger — TINYINT UNSIGNED in MySQL; column row in Section D."),
        ("Stock", "What is the MySQL check constraint name and predicate?", "stock_slabs_on_hand_non_negative CHECK (slabs_on_hand >= 0) — only on mysql driver in migration."),
        ("Tokens", "personal_access_tokens morph index?", "morphs('tokenable') adds tokenable_id BIGINT UNSIGNED + tokenable_type VARCHAR(255) INDEX."),
        ("failed_jobs", "Default for failed_at?", "timestamp->useCurrent() — CURRENT_TIMESTAMP default in MySQL."),
        ("Rate limit", "api-login limiter", "RateLimiter::for('api-login') → Limit::perMinute(5)->by(email|ip) — app/Providers/AppServiceProvider.php."),
        ("Config", "Default QUEUE_CONNECTION", "config/queue.php default env('QUEUE_CONNECTION', 'database')."),
        ("Config", "Default REDIS client", "config/database.php redis client env('REDIS_CLIENT', 'phpredis')."),
        ("Compose", "How is the queue worker started in Docker?", "docker-compose service queue: php artisan queue:work redis ..."),
        ("Compose", "Redis service image", "redis:7-alpine in docker-compose.yml."),
        ("Web", "Where is chat UI entry?", "resources/views/chat.blade.php; Vite resources/js/chat-app.js."),
        ("Bootstrap", "Where is api routes path?", "bootstrap/app.php withRouting api: routes/api.php — line " + find_line("bootstrap/app.php", "routes/api.php") + "."),
        ("Health", "Liveness URL", "health: '/up' in bootstrap/app.php."),
        ("Landed", "Who authorizes landed cost API?", "LandedCostService::authorizeLandedCosts via calculateForPurchaseOrder entry."),
        ("Landed", "404 vs 403 for landed cost", "404 for calculation errors (missing PO/lines); 403 when Gate denies — verify feature tests."),
        ("Assistant", "Max message length config", "config('ai.assistant.max_message_chars') — AssistantChatRequest rules."),
        ("Assistant", "Idempotency lock TTL", "config ai.assistant.idempotency_lock_seconds — AssistantRequestService."),
        ("Tools", "CheckStockTool name()", "returns checkStock — registered in ToolRegistry DI."),
        ("Tools", "getLandedCost allowed roles", "Purchasing only in GetLandedCostTool::allowedFor."),
        ("Models", "User hidden fields?", "Verify User::$hidden — tokens/secrets not in array JSON."),
        ("Supplier", "bank_details storage", "encrypted cast; hidden from array — Supplier model."),
        ("Proposal", "cancelConfirmed guard", "confirmProposal rejects cancelled; cancelProposal rejects confirmed."),
        ("Proposal", "payload hash algorithm", "DraftPurchaseOrderService::hashPayload — SHA-256 of normalized JSON (normalizePayloadForHash + json_encode)."),
        ("Session", "sessions table PK", "sessions.id string primary — migration users file."),
        ("Password reset", "password_reset_tokens PK", "email string primary."),
        ("Migrations", "Additive migration for client idempotency", "2026_11_06_220000 adds nullable client_idempotency_key + unique composite."),
        ("AI", "FollowUpQuestionResolver AI path", "resolveWithAi uses AiAgentService::complete with strict JSON system prompt."),
        ("AI", "Ambiguous follow-up without AI", "config ai.conversation.follow_up.use_ai_when_ambiguous false returns clarification text."),
        ("Observability", "Conversation AI interaction logging", "conversation_ai_interactions table stores provider, model, tokens."),
        ("ERP", "Overdue PO endpoint", "OverduePurchaseOrderController::index — OverduePurchaseOrderRequest."),
        ("ERP", "Landed cost route model binding", "LandedCostController::show(PurchaseOrder $purchaseOrder) — implicit binding."),
        ("Security", "Proposal exception mapping", "PurchaseOrderProposalHttpExceptionMapper in bootstrap/app.php for api/*."),
        ("Design", "Why Gate + Tool allowedFor?", "HTTP and AI paths both enforce — prompts are not security."),
        ("Design", "Why explicit confirmation API for PO?", "Separation of proposal snapshot from financial commit."),
        ("Concurrency", "Why sequential tests insufficient", "Race conditions need stress/integration tests — not in scope of claim."),
        ("Redis", "Queue connection in .env.example", "QUEUE_CONNECTION=redis in example vs database default in config — document both."),
        ("Windows", "Memurai scripts", "scripts/memurai-*.cmd referenced historically — local Redis substitute."),
        ("Schema", "Products sku unique", "Migration unique on products.sku."),
        ("Schema", "Stock composite unique", "unique(product_id, warehouse_id) in stock table."),
        ("API", "GET /api/purchase-orders shape", "JSON { data: PurchaseOrderResource[], meta } — pagination meta from lookup service."),
        ("API", "Current user fields", "UserResource toArray — id, name, email, role only."),
        ("Auth", "Token response shape", "TokenController::store — token, token_type Bearer, user UserResource."),
        ("Purchasing", "VIEW_FREIGHT_COSTS vs VIEW_LANDED_COSTS", "Both purchasing-only in ErpGateRegistrar; freight visible separately from landed tool."),
        ("Sales", "System prompt cost mention", "buildSystemInstruction tells model not to surface costs for Sales."),
        ("Tools", "validation_error vs forbidden_arguments", "Unknown keys in closed schema vs disallowed security keys list."),
        ("Tools", "internal_error", "Throwable in tool.execute — generic message to client."),
        ("Context", "max_messages / max_chars", "ConversationContextManager reads config ai.conversation.context."),
        ("Idempotency", "Proposal duplicate key same payload", "Returns existing formatted proposal — no duplicate row."),
        ("Idempotency", "Proposal duplicate key different payload", "PurchaseOrderProposalException idempotency conflict."),
        ("Money", "Zero freight handling", "Each line freight share 0; landed unit equals unit_cost."),
        ("Money", "Zero quantity line", "Rejected before allocation — LandedCostCalculationException."),
        ("Purchase orders", "PO status on confirm", "PurchaseOrderStatus::Draft on create from proposal."),
        ("Seeder", "Demo stock split Houston/Dallas", "StockSeeder DEMO-STQ-CAL-30 — 42 Houston / 18 Dallas."),
        ("Git", "How to verify commit without embedding status?", "Run git rev-parse HEAD; git ls-remote origin HEAD; git status --short locally — Section S."),
        ("Doc", "What is Phase 16.4D deliverable?", "Corrected generator + regenerated 07 handbook only; docs 01–06 unchanged."),
        ("Migrations", "personal_access_tokens token column", "string(64)->unique() — VARCHAR(64) UNIQUE."),
        ("Exceptions", "AssistantIdempotencyException codes", "CODE_BODY_MISMATCH 409; CODE_IN_PROGRESS 503."),
        ("Traits", "AuthorizesErpUser", "LandedCostService and DraftPurchaseOrderService use for Gate checks."),
        ("Requests", "ConversationScopedProposalRequest", "Requires conversation_key for show/confirm/delete proposal."),
        ("Resources", "PurchaseOrderProposalResource lines", "Includes unit_cost — purchasing-facing proposal snapshot."),
        ("AI", "Unsupported provider user experience", "AiProviderException maps to HTTP via mapOutcomeFailure in AssistantRequestService."),
        ("Config", "ai.provider env", "AI_PROVIDER in .env.example — maps to config('ai.provider')."),
        ("Database", "SQLite vs MySQL in dev", "config default sqlite for DB; .env.example mysql — both supported for tests."),
        ("Migrations", "job_batches id", "string id primary() — non-auto UUID-like batch id."),
        ("Migrations", "purchase_order_proposals id", "uuid primary — not bigint id()."),
        ("Handbook", "Section D extras rows", "Indexes, uniques, foreign() calls, DB::statement CHECK — summarized separately from column grid."),
        ("Handbook", "Inventory rules paragraph", "Section B intro lists exclusion patterns — not just a raw count."),
        ("Interview", "STAR for proposal flow", "User story: propose → confirm → Draft PO — cite DraftPurchaseOrderService."),
        ("Risk", "What if Redis down with AI_PROVIDER=ollama", "Dispatch catch marks queue_dispatch_failed; OpenAI/Claude unaffected."),
        ("Privacy", "Conversation retention", "No automatic purge in migrations — policy decision."),
        ("Accessibility", "Chat UI", "Not audited — limitation."),
    ]
    for cat, q, detail in topics:
        what_short = q.split("?", 1)[0].strip()
        add(
            cat,
            q,
            _qa_answer_parts(
                what_short + ".",
                detail,
                "Trace from routes/api.php, app/Http/Controllers, app/Services, or database/migrations per the detail line.",
                "Keeps transport, domain rules, and AI orchestration explicit in this codebase.",
                "Numbers (tests, limits) go stale — verify from source before interviews.",
            ),
        )

    phase1625 = [
        (
            "Providers",
            "How would you add Gemini without rewriting ERP tools?",
            _qa_answer_parts(
                "New GeminiProvider implements AiProviderInterface; map function calls to AiToolCall DTOs; register in AiProviderFactory.",
                "Mirror OpenAiProvider tool-loop mapping; add config keys and feature tests with Http::fake.",
                "app/Contracts/AiProviderInterface.php; app/Services/AI/AiProviderFactory.php.",
                "ERP tools stay provider-agnostic — only adapter layer changes.",
                "Streaming, caching, and billing hooks would be additional work.",
            ),
        ),
        (
            "RBAC",
            "How does supplier-specific delayed PO analysis differ between Sales and Purchasing?",
            _qa_answer_parts(
                "Purchasing gets ERP-backed overdue lists with supplier filter; Sales gets deterministic soft denial for supplier-specific delayed/overdue analysis.",
                "OverduePurchaseOrderSearchReply + FindOverduePurchaseOrdersTool supplier filters; SalesSupplierOverduePurchaseOrderAnalysisRefusal in AssistantRequestService.",
                "Phase 16.22 classes under app/Services/AI/Grounding/.",
                "Business policy enforced in PHP, not prompts.",
                "Sales general overdue may still hit live model if no deterministic handler matches.",
            ),
        ),
        (
            "ERP",
            "How are overdue purchase orders calculated?",
            _qa_answer_parts(
                "Ordered status, non-null ETA, ETA date strictly before reference date (default today).",
                "OverduePurchaseOrderService::listOverdue query scopes.",
                "app/Services/Erp/OverduePurchaseOrderService.php.",
                "Aligns with GET /api/purchase-orders/overdue and findOverduePurchaseOrders tool.",
                "Null ETA and non-ordered statuses excluded.",
            ),
        ),
        (
            "Procurement",
            "Why does creating or confirming a PO not increase stock?",
            _qa_answer_parts(
                "Demo ERP models procurement separately from on-hand inventory; no goods-receipt workflow.",
                "Confirm creates purchase_orders/lines only — StockSeeder unchanged in tests.",
                "DraftPurchaseOrderService::confirmProposal; Stock model.",
                "Real ERP would add GRN/receipt posting — future work.",
                "Documented limitation in README future improvements.",
            ),
        ),
        (
            "Security",
            "How do you reduce ERP hallucinations for stock and PO answers?",
            _qa_answer_parts(
                "Deterministic grounding enforcers and dedicated search replies bypass or re-validate model text.",
                "StockCountGroundingEnforcer; PurchaseOrderSearchReply; OverduePurchaseOrderSearchReply; ToolExecutor on tools.",
                "AssistantRequestService preflight paths.",
                "Model proposes tools; Laravel returns authoritative rows.",
                "Not all question types are deterministic — live model still used when no rule matches.",
            ),
        ),
    ]
    for cat, q, a in phase1625:
        add(cat, q, a)

    phase1626 = [
        (
            "Providers",
            "How would you add Gemini support without changing your existing ERP functionality?",
            _qa_answer_parts(
                "Add GeminiProvider implementing AiProviderInterface; reuse AiToolCallingService and ToolExecutor.",
                "Map AiRequest messages and AiToolDefinition schemas to Gemini function declarations; map responses to AiToolCall; after ToolExecutor, map results into continuation request; register gemini in AiProviderFactory; config keys; Http::fake contract tests.",
                "app/Contracts/AiProviderInterface.php; app/Services/AI/Providers/; AiProviderFactory.php; AiToolCallingService.php.",
                "ERP tools and Gates unchanged — only adapter + factory arm.",
                "Gemini is not implemented today — factory throws if AI_PROVIDER=gemini.",
            ),
        ),
        (
            "Providers",
            "Why don't you create a separate AI agent for Gemini?",
            _qa_answer_parts(
                "One orchestration path; vendor differences belong in provider adapters.",
                "AiToolCallingService runs the tool loop; AiAgentService is a thin complete() facade; GeminiProvider would plug into the same loop as OpenAiProvider.",
                "AiToolCallingService vs AiAgentService.",
                "Avoid duplicating iteration limits, grounding, and ToolExecutor wiring.",
                "Provider-specific tool schema presentation may still differ via ToolRegistry — not duplicate agents.",
            ),
        ),
        (
            "Providers",
            "If you switch from OpenAI to Gemini, what parts of the application should remain unchanged?",
            _qa_answer_parts(
                "Controllers, ERP services, tool business logic, Gates, database schema, proposal confirm flow.",
                "Only AI_PROVIDER, adapter class, config, and provider-specific schema mapping in registry.",
                "routes/api.php; app/Services/Erp/*; app/Services/AI/Tools/Definitions/*.",
                "Separation of transport (adapter) from domain (ERP).",
                "Async queue semantics apply only to Ollama — Gemini would follow OpenAI sync pattern unless designed otherwise.",
            ),
        ),
        (
            "Providers",
            "Why use an interface instead of calling OpenAI directly from the controller?",
            _qa_answer_parts(
                "Controllers stay HTTP-only; provider selection and HTTP details live behind AiProviderInterface.",
                "AssistantController → AssistantRequestService → AiToolCallingService → AiAgentService → factory.",
                "No OpenAI SDK imports in controllers.",
                "Interview portability and testability with fakes.",
                "Misconfigured provider still fails at factory — not at runtime SQL.",
            ),
        ),
        (
            "Providers",
            "What is the difference between Claude direct API and Amazon Bedrock?",
            _qa_answer_parts(
                "This repo implements ClaudeProvider against Anthropic Messages API only.",
                "Bedrock uses AWS SDK, IAM, regional inference profiles — separate adapter not present; factory throws bedrock.",
                "AiProviderFactory match arms.",
                "Same model name on paper does not mean same integration code.",
                "Do not claim Bedrock works because Claude works.",
            ),
        ),
    ]
    for cat, q, a in phase1626:
        add(cat, q, a)

    assert len(qa) >= 100, f"expected >=100 substantive Q&A, got {len(qa)}"
    assert len(seen_q) == len(qa), "duplicate questions in Q&A bank"
    return qa


# ---------------------------------------------------------------------------
# Main document build
# ---------------------------------------------------------------------------


def build_doc() -> dict[str, Any]:
    from docx import Document

    assert OUT_DOCX.name == "07-Complete-Technical-Interview-Handbook.docx"

    missing_sub = assert_submission_docs_unchanged_count()
    if missing_sub:
        print("WARNING: Missing submission docs 01–06:", ", ".join(missing_sub), file=__import__("sys").stderr)

    n_files, all_paths = count_inventory_files()
    cols, extras = parse_migrations_detailed()
    tbl_names = tables_from_columns(cols)
    n_tables = len(tbl_names)
    api_routes = parse_api_routes()
    models = all_models()
    qa_list = build_interview_qa(api_routes, n_tables, len(cols), n_files)

    diagrams = {
        "high": bp13.diagram_high_level_arch(),
        "tool": bp13.diagram_tool_flow(),
        "draft": bp13.diagram_draft_po(),
        "follow": bp13.diagram_follow_up(),
        "auth": bp13.diagram_auth(),
        "er": bp13.diagram_er(),
        "docker": bp13.diagram_docker(),
        "laravel": bp13.diagram_laravel_layers(),
        "provider": bp13.diagram_provider(),
        "phase16": bp13.diagram_phase16_readiness(),
        "sequence": diagram_api_sequence(),
        "async_lifecycle": bp13.diagram_async_ollama_lifecycle(),
        "recovery": bp13.diagram_recovery_failure(),
    }
    diagram_count = len(diagrams)

    doc = Document()
    bp13.set_document_style(doc)
    sec = doc.sections[0]
    sec.different_first_page_header_footer = True
    bp13.add_header(sec, "07 — Complete Technical Interview Handbook (Phase 16.26)")
    bp13.add_page_number_footer(sec)

    bp13.add_cover(
        doc,
        "Complete Technical Interview Preparation Handbook",
        "Stone ERP AI Assistant — source-backed interview reference (Phase 16.26; AI provider abstraction + procurement through 16.25)",
    )

    toc = [
        "How to use this handbook",
        "Section A — Project overview",
        "Section B — Source file inventory (all paths)",
        "Section C — Architecture and diagrams",
        "Section D — Database (migrations)",
        "Section E — Eloquent models",
        "Section F — API routes and endpoint matrix",
        "Section G — Form Requests (source excerpts)",
        "Section H — Authentication and roles",
        "Section I — AI architecture (provider factory and agents)",
        "Section I1 — AI provider abstraction and vendor independence",
        "Section J — Tool definitions (schemas)",
        "Section K — Conversation context and follow-up resolution",
        "Section K2 — Procurement, warehouse destination, and supplier catalogue",
        "Section K3 — Supplier-specific overdue PO search and Sales soft denial",
        "Section L — Tool architecture and ToolExecutor",
        "Section M — Landed cost (LandedCostService)",
        "Section N — Draft purchase-order proposals and idempotency",
        "Section O — Redis, queues, and Ollama async assistant",
        "Section P — Docker (files only)",
        "Section Q — Frontend",
        "Section R — Testing (reported status)",
        "Section S — Git / GitHub (verified + historical)",
        "Section T — Design trade-offs",
        "Section T2 — Integrating into an existing Laravel ERP",
        "Section T3 — Estimated AI cost (per 1,000 questions)",
        "Section T4 — Demo users and local setup",
        f"Section U — Interview Q&A ({len(qa_list)} items)",
        "Section V — Demo scripts",
        "Section W — Troubleshooting",
        "Section X — Glossary",
        "Section Y — Limitations",
        "Appendix — File path index (reference only; not counted as interview Q&A)",
        "Revision checklist",
    ]
    bp13.add_manual_contents(doc, toc)

    section_heading(doc, "How to use this handbook during interview preparation", 1)
    bp13.para(
        doc,
        "Use repository-relative paths and class/method names as primary references. Line numbers in excerpts were captured when the generator ran and may drift — re-run `python tools/build_phase16_interview_handbook.py` after edits.",
    )
    bp13.para(doc, f"Generated from local HEAD: {git_head()}.")
    up = git_upstream()
    if up:
        bp13.para(doc, f"Upstream tracking branch: {up}.")
    lr = git_ls_remote_origin_head()
    if lr:
        bp13.para(doc, f"Verified `git ls-remote origin HEAD` at generation time: {lr}.")
    bp13.para(doc, "Historical initial commit hash 86f17a8b… and message remain user-reported — verify with `git log` if needed.")

    # A
    section_heading(doc, "Section A — Project overview and challenge context", 1)
    bp13.para(
        doc,
        "This application combines JSON ERP endpoints (products, stock, purchase orders) with a Sanctum-protected assistant that uses tool calling so the model never executes raw SQL. Purchasing-only capabilities include landed cost calculation and draft purchase-order proposals that require explicit HTTP confirmation to create real purchase_orders rows.",
    )
    bp13.para(
        doc,
        "Demonstration stock: SKU DEMO-STQ-CAL-30 — Houston 42 slabs, Dallas 18 slabs — database/seeders/StockSeeder.php; regression assertion tests/Feature/Seeders/ErpSeedersTest.php.",
    )

    # B Inventory tables by category
    section_heading(doc, "Section B — Source file inventory (complete)", 1)
    bp13.para(
        doc,
        "Inclusion: text/source paths under app/, bootstrap/, config/, database/, docker/, docs/, public/, resources/, routes/, scripts/, tests/, tools/ (excluding dependency trees), plus selected project roots (composer.json, docker-compose.yml, .env.example, README, etc.). "
        "Exclusions: vendor/, node_modules/, .git/, storage/, bootstrap/cache/; also tools/composer/** and tools/mysql/** (local tooling caches); Python __pycache__/ and *.pyc.",
    )
    bp13.para(doc, f"Total indexed paths after exclusions: {n_files}. Categories below list every path with a one-line summary derived from the file header / namespace.")
    buckets = inventory_by_category(all_paths)
    for cat, paths in buckets.items():
        section_heading(doc, f"Section B — {cat}/ ({len(paths)} files)", 2)
        chunk = 35
        for i in range(0, len(paths), chunk):
            rows = []
            for rel in paths[i : i + chunk]:
                rows.append([rel, file_kind(rel), summarize_file(rel)])
            add_table(doc, ["Path", "Kind", "Summary (from source)"], rows)

    # C Architecture
    section_heading(doc, "Section C — Application architecture", 1)
    for key, cap in [
        ("high", "Figure 1 — High-level components"),
        ("sequence", "Figure — Assistant sequence (synchronous HTTP)"),
        ("laravel", "Figure — Laravel layering"),
        ("auth", "Figure — Auth chain"),
        ("tool", "Figure — Tool execution"),
        ("draft", "Figure — Draft PO workflow"),
        ("follow", "Figure — Follow-up resolution"),
        ("provider", "Figure — Provider factory"),
        ("docker", "Figure — Docker Compose (definition only)"),
        ("phase16", "Figure — Readiness pipeline"),
        ("er", "Figure — ER overview"),
    ]:
        bp13.add_picture(doc, diagrams[key], cap)
    bp13.code_block(
        doc,
        excerpt_with_lines("bootstrap/app.php", 10, 19),
    )
    bp13.para(doc, "Application::configure registers web, api, console routes — api file routes/api.php (HTTP prefix /api).")

    # D Database
    section_heading(doc, "Section D — Database reference (from migrations)", 1)
    bp13.para(
        doc,
        f"Parser output: {n_tables} application tables from migration `up()` (create + additive `Schema::table` only); {len(cols)} column rows; foreign/unique/index lines and `DB::statement` entries in the second table. "
        "The stock non-negative rule is applied only when the driver is MySQL (`Schema::getConnection()->getDriverName() === 'mysql'`) — it is not created on SQLite. "
        "Laravel's runtime `migrations` table is not in this repository. Primary keys: `$table->id()` yields BIGINT UNSIGNED AUTO_INCREMENT; UUID/string `->primary()` is documented explicitly in the Keys column. ",
    )
    col_rows = [column_record_to_row(c) for c in cols]
    chunk = 40
    for i in range(0, len(col_rows), chunk):
        section_heading(doc, f"Section D (columns) — rows {i + 1}-{min(i + chunk, len(col_rows))} of {len(col_rows)}", 2)
        add_table(doc, ["Table", "Column", "MySQL type", "Laravel / constraint", "Nullable", "Keys / FK / CHECK", "Source"], col_rows[i : i + chunk])

    if extras:
        section_heading(doc, "Section D — Indexes, uniques, foreign keys, DB::statement", 2)
        erows = []
        for e in extras:
            summary = (e.get("summary") or "").strip()
            linebits = (e.get("raw_line") or "")[:280]
            erows.append(
                [
                    e.get("table", ""),
                    e.get("kind", ""),
                    summary or "—",
                    linebits,
                    e.get("file", "") + ":" + str(e.get("line_no", "")),
                ]
            )
        for i in range(0, len(erows), 45):
            add_table(doc, ["Table", "Kind", "Summary", "Line (truncated)", "Source"], erows[i : i + 45])

    # E Models
    section_heading(doc, "Section E — Eloquent models", 1)
    for m in models:
        tbl = m.table or f"(implicit `{re.sub(r'(?<!^)(?=[A-Z])', '_', m.class_name).lower()}s` — verify)"
        lines = [
            f"File: {m.path}",
            f"Class: {m.class_name}; table override: {tbl}",
            f"Fillable: {', '.join(m.fillable) if m.fillable else '—'}",
            f"Hidden: {', '.join(m.hidden) if m.hidden else '—'}",
            f"Casts keys: {', '.join(m.casts_keys) if m.casts_keys else '—'}",
            f"Relationships / helpers: {', '.join(m.relation_methods[:20])}{'…' if len(m.relation_methods)>20 else ''}",
        ]
        bp13.para(doc, " ".join(lines))

    bp13.para(
        doc,
        "Supplier bank_details: cast `encrypted` in casts() and listed in $hidden — values encrypted with APP_KEY via Laravel; persisted as ciphertext in DB; decrypted on model access; excluded from array/json unless explicitly exposed.",
    )

    # F API matrix
    section_heading(doc, "Section F — API reference (endpoint matrix)", 1)
    bp13.para(doc, "Exact route definitions from routes/api.php (continued lines merged so `->middleware()` and `->name()` are attributed to the correct route). Group middleware `auth:sanctum` is inherited; route-specific middleware is listed additively.")
    bp13.code_block(doc, read_rel("routes/api.php"))
    mat = [[r.method, r.uri, r.controller.split("\\")[-1] + "::" + r.action, r.middleware, r.name or "—"] for r in api_routes]
    add_table(doc, ["Method", "URI", "Handler", "Middleware (group + route)", "Route name"], mat)
    bp13.para(doc, "Route count (parsed): " + str(len(api_routes)) + ".")
    section_heading(doc, "Section F — Endpoint reference (interviewer notes)", 2)
    for r in api_routes:
        section_heading(doc, f"{r.method} {r.uri}", 3)
        for para in endpoint_reference_paragraphs(r):
            bp13.para(doc, para)

    # G Form requests — excerpts
    section_heading(doc, "Section G — Form Requests (full source excerpts)", 1)
    for rel in sorted([p for p in all_paths if p.startswith("app/Http/Requests/") and p.endswith(".php")]):
        section_heading(doc, rel, 3)
        text = read_rel(rel)
        nlines = len(text.splitlines())
        bp13.code_block(doc, text if nlines < 120 else "\n".join(text.splitlines()[:120]) + "\n/* … truncated — see repository … */")

    # H Auth
    section_heading(doc, "Section H — Authentication, roles, and permissions (ErpGateRegistrar)", 1)
    bp13.para(
        doc,
        "ErpAbilities lists canonical Laravel Gate names. ErpGateRegistrar::register binds each to a closure on App\\Models\\User. "
        "VIEW_PRODUCTS, VIEW_STOCK, VIEW_PURCHASE_ORDER_STATUS use hasErpRole (Sales or Purchasing). "
        "VIEW_COSTS, VIEW_FREIGHT_COSTS, VIEW_LANDED_COSTS, VIEW_SUPPLIER_BANK_DETAILS, and CREATE_DRAFT_PURCHASE_ORDERS require isPurchasing() — Purchasing-only.",
    )
    add_table(
        doc,
        ["Ability (ErpAbilities)", "Sales", "Purchasing", "Typical enforcement surface"],
        [
            ["viewProducts", "Yes", "Yes", "StockLookupService / ProductController"],
            ["viewStock", "Yes", "Yes", "StockLookupService / StockController"],
            ["viewPurchaseOrderStatus", "Yes", "Yes", "PurchaseOrderLookupService"],
            ["viewCosts", "No", "Yes", "API resources and tools that surface unit costs"],
            ["viewFreightCosts", "No", "Yes", "Freight-bearing data / tools"],
            ["viewLandedCosts", "No", "Yes", "LandedCostService::authorizeLandedCosts"],
            ["viewSupplierBankDetails", "No", "Yes", "Supplier model; tool payloads"],
            ["createDraftPurchaseOrders", "No", "Yes", "AuthorizesPurchasingProposalAccess trait on proposal Form Requests"],
        ],
    )
    bp13.para(
        doc,
        "API authorization: Sanctum guards the `auth:sanctum` group (see routes/api.php). Proposal routes are authorized via Form Request `authorize()` (AuthorizesPurchasingProposalAccess) — there is no separate route middleware name for purchasing beyond the Gate itself. "
        "AI tools: ToolExecutor checks each tool's `allowedFor(User)`; ToolRegistry builds the model-visible tool list. "
        "UI or JSON field omission alone is not sufficient — server-side Gates and tool checks are authoritative.",
    )
    bp13.code_block(doc, excerpt_with_lines("app/Auth/ErpGateRegistrar.php", 1, 33))

    # I AI providers
    section_heading(doc, "Section I — AI architecture (call chain and providers)", 1)
    bp13.para(
        doc,
        "Main assistant path: AssistantRequestService::continueAfterUserMessagePersisted builds an AiRequest from ConversationContextManager, then AiToolCallingService::run orchestrates provider tool loops until completion or AiToolLoopException / AiProviderException. "
        "Plain completion path (e.g. FollowUpQuestionResolver::resolveWithAi): AiAgentService::complete(AiRequest) calls AiProviderFactory::make()->complete($request).",
    )
    bp13.para(
        doc,
        "AiProviderInterface defines `complete(AiRequest): AiResponse` — the common response contract. AiProviderFactory::make() normalizes `config('ai.provider')` and resolves OpenAiProvider or ClaudeProvider. "
        "Names `bedrock`, `gemini`, `groq` throw AiProviderException with CODE_UNSUPPORTED_PROVIDER and message 'not implemented yet' — they are explicitly rejected, not alternative backends in this repository. "
        "Unsupported default provider throws 'not supported'.",
    )
    bp13.para(
        doc,
        "Implemented providers: OpenAiProvider, ClaudeProvider (direct Anthropic Messages API), and OllamaProvider (app/Services/AI/Providers/). "
        "Amazon Bedrock, Google Gemini, and Groq are named in AiProviderFactory but throw AiProviderException — they are not separate adapter classes in this repository. "
        "Bedrock would be a different HTTP/SDK surface than ClaudeProvider even if both run Claude-family models. Live provider calls are not invoked by this handbook generator.",
    )
    bp13.code_block(doc, excerpt_with_lines("app/Services/AI/AiAgentService.php", 9, 20))
    bp13.code_block(doc, excerpt_with_lines("app/Services/AI/AiProviderFactory.php", 11, 31))
    bp13.code_block(doc, excerpt_with_lines("app/Services/AI/AiToolCallingService.php", 36, 100))

    section_heading(doc, "Section I1 — AI provider abstraction and vendor independence", 1)
    bp13.para(
        doc,
        "Design goal: vendor swappability via AiProviderInterface + AiProviderFactory (AI_PROVIDER), with ERP tools and authorization unchanged. "
        "AiRequest and AiResponse DTOs isolate orchestration from OpenAI Responses, Anthropic Messages API, and Ollama chat formats — including continuation fields after tool results. "
        "AiToolCallingService owns the assistant tool loop (registry schemas → provider complete → ToolExecutor → append results → repeat). "
        "AiAgentService is a thin single-round facade (factory.make()->complete), not a separate agent implementation per vendor.",
    )
    add_table(
        doc,
        ["Provider", "Status in this repository"],
        [
            ["OpenAI", "Implemented — OpenAiProvider"],
            ["Anthropic Claude direct API", "Implemented — ClaudeProvider"],
            ["Ollama", "Implemented — OllamaProvider"],
            ["Amazon Bedrock", "Not implemented — factory throws; different integration than ClaudeProvider"],
            ["Google Gemini", "Not implemented — factory throws"],
            ["Groq", "Not implemented — factory throws"],
        ],
    )
    bp13.para(
        doc,
        "ToolRegistry may present different JSON schemas per provider (AiProviderProfile, OllamaExclusiveTool). ToolExecutor and ERP services are shared. "
        "Direct Anthropic API ≠ Amazon Bedrock: same model family possible, different auth, endpoints, and adapter code.",
    )
    bp13.para(
        doc,
        "Interview answer sketch — How would you add Gemini without changing ERP functionality? "
        "Implement GeminiProvider for AiProviderInterface; map AiRequest/tools to Gemini function calling; map function calls to AiToolCall; "
        "reuse AiToolCallingService loop and ToolExecutor; register in AiProviderFactory; config + Http::fake tests; do not fork controllers or StockLookupService.",
    )
    bp13.para(
        doc,
        "Why not a separate Gemini agent? One orchestration workflow (AiToolCallingService); only the adapter translating HTTP differs.",
    )
    bp13.para(
        doc,
        "If you switch OpenAI → Gemini: unchanged — routes, controllers, AssistantRequestService policy, ToolRegistry tool classes, ToolExecutor, ERP services, Gates, migrations, and business rules; only provider adapter, factory match arm, config, and provider-specific schema presentation if needed.",
    )

    # J Tools definitions excerpt
    section_heading(doc, "Section J — AI tool definitions (JSON schemas)", 1)
    bp13.para(
        doc,
        "Business logic lives in tool classes under app/Services/AI/Tools/Definitions/; ToolExecutor enforces schemas and forbidden keys (Section L).",
    )
    for rel in [
        "app/Services/AI/Tools/Definitions/CheckStockTool.php",
        "app/Services/AI/Tools/Definitions/SearchPurchaseOrdersTool.php",
        "app/Services/AI/Tools/Definitions/FindOverduePurchaseOrdersTool.php",
        "app/Services/AI/Tools/Definitions/GetLandedCostTool.php",
        "app/Services/AI/Tools/Definitions/ProposeDraftPurchaseOrderTool.php",
    ]:
        section_heading(doc, rel, 3)
        bp13.code_block(doc, read_rel(rel))

    # K Conversations
    section_heading(doc, "Section K — Conversation context and follow-up resolution", 1)
    bp13.para(
        doc,
        "Storage: ConversationService appends rows to conversation_messages (user / assistant / tool roles, JSON metadata, sequence). conversation_summaries stores compressed history; ConversationContextManager prepends the latest summary as a system-line prefix when present.",
    )
    bp13.para(
        doc,
        "Recent context: ConversationContextManager::buildAiMessages loads recent non-internal messages ordered by sequence descending, enforces max_messages and max_chars, then reverses to chronological order for the provider. Tool rows for Sales omit prior getLandedCost payloads via includeToolRowForUser (defense in depth with ToolResultRedactor).",
    )
    bp13.para(
        doc,
        "Follow-ups: FollowUpQuestionResolver::resolve — deterministic_city_swap rewrites a short `in <city>?` follow-up only when the prior user question matches Houston, Dallas, or Austin (regex on `$baseQuestion`); Dallas replacement does not come from metadata['resolved_standalone_question'] by itself — that metadata is merged later onto the user row for the model. "
        "If deterministic rules fail and config permits, resolveWithAi uses AiAgentService::complete JSON instructions; on exception, strategy ai_resolver_failed asks for a full standalone question.",
    )
    bp13.code_block(doc, excerpt_with_lines("app/Services/AI/Context/ConversationContextManager.php", 11, 146))
    bp13.code_block(doc, excerpt_with_lines("app/Services/AI/Context/FollowUpQuestionResolver.php", 18, 160))
    bp13.para(
        doc,
        "Deterministic follow-ups: DeterministicFollowUpResolver expands supplier swaps in overdue threads (e.g. Brazos → Gulf Stone Imports) and must not treat PO-status follow-ups as supplier names (Phase 16.23 fix).",
    )

    # K2 Procurement
    section_heading(doc, "Section K2 — Procurement, warehouse destination, and supplier catalogue", 1)
    bp13.para(
        doc,
        "Draft PO creation is Purchasing-only. proposeDraftPurchaseOrder validates supplier–product catalogue rules, quantities, pricing, and warehouse destination on lines. "
        "When the warehouse is missing, pending_procurement_clarifications stores server-side state; AssistantRequestService returns clarification_required before a proposal is returned. "
        "Sales receives an early deterministic refusal for draft-PO creation (SalesPurchaseOrderCreationRefusal). Confirming a proposal creates purchase_orders and lines but does not increase slabs_on_hand — goods receipt is out of scope.",
    )
    bp13.para(doc, "Key classes: ProductResolutionService, SupplierProductCatalogueService, ProcurementWarehouseClarificationService, DraftPurchaseOrderService, ErpDeterministicPreflight, AssistantRequestService.")

    # K3 Supplier overdue
    section_heading(doc, "Section K3 — Supplier-specific overdue PO search and Sales soft denial (Phase 16.22+)", 1)
    bp13.para(
        doc,
        "Overdue rule (unchanged): status ordered, non-null ETA, ETA strictly before reference date; received/cancelled/draft excluded. "
        "Purchasing: OverduePurchaseOrderSearchReply + FindOverduePurchaseOrdersTool accept optional supplier_id / supplier_name (SupplierFilterResolution). "
        "Sales: supplier-specific delayed/overdue analysis receives SalesSupplierOverduePurchaseOrderAnalysisRefusal message; individual PO status and general PO listing remain available. "
        "General overdue without a supplier filter remains available to Sales via tools/API where configured; deterministic ERP text for global overdue disclosure is Purchasing-oriented (config ai.assistant.enforce_overdue_purchase_order_disclosure).",
    )

    # L Tool architecture
    section_heading(doc, "Section L — Tool architecture, ToolExecutor, and errors", 1)
    bp13.para(
        doc,
        "ToolRegistry: constructed with all AiToolInterface services; rejects duplicate names; definitionsFor(User) filters by allowedFor — app/Services/AI/Tools/ToolRegistry.php.",
    )
    bp13.para(
        doc,
        "ToolExecutor: validates tool calls — normalize raw arguments, strip FORBIDDEN_ARGUMENT_KEYS (including conversation_key / idempotency_key), resolve AiToolInterface from registry, enforce allowedFor, validate JSON schema closed objects, call execute with ToolExecutionContext built by AiToolCallingService (trusted server keys only).",
    )
    bp13.para(
        doc,
        "Errors: unknown_tool (missing name); forbidden_arguments (disallowed keys); permission_denied (allowedFor false or AuthorizationException); validation_error (extra keys vs schema); invalid_arguments (malformed JSON envelope); internal_error (uncaught Throwable).",
    )
    bp13.para(
        doc,
        "Why Laravel controls identity: User in ToolExecutionContext comes from authenticated HTTP layer; the model must not override user_id/role/token — TOOL FORBIDDEN list plus server-built context enforce that.",
    )
    bp13.code_block(doc, excerpt_with_lines("app/Services/AI/Tools/ToolExecutor.php", 11, 169))

    # M Landed cost
    section_heading(doc, "Section M — Landed cost (LandedCostService + ErpMoney)", 1)
    bp13.para(
        doc,
        "Formula: line_value = quantity × unit_cost (ErpMoney::mul + roundCurrency). freight_share = freight_cost × (line_value_raw / sum(line_value_raw)); half-up rounding per line; remainder = freight − sum(rounded shares); remainder added to the largest line's rounded share. "
        "landed unit = unit_cost + roundCurrency(freight_share / quantity). ErpMoney uses BCMath with `roundCurrency` half-up (see ErpMoney docblock).",
    )
    bp13.para(
        doc,
        "Worked example (value-based): Line A 10 × $100 = $1,000; Line B 20 × $200 = $4,000; freight $500 → shares $100 / $400 → landed units $110 / $220 — matches proportional split in calculateForPurchaseOrder.",
    )
    bp13.para(
        doc,
        "Edges: freight zero → shares zero. total line value ≤ 0 → LandedCostCalculationException. quantity ≤ 0 on a line → exception. Authorization: LandedCostService::authorizeLandedCosts (Purchasing).",
    )
    bp13.code_block(doc, excerpt_with_lines("app/Services/Erp/LandedCostService.php", 19, 151))
    bp13.code_block(doc, excerpt_with_lines("app/Services/Erp/ErpMoney.php", 1, 86))

    # N Proposals + idempotency
    section_heading(doc, "Section N — Draft purchase-order proposals, confirmation, and idempotency", 1)
    bp13.para(
        doc,
        "Proposal vs purchase order: purchase_order_proposals stores pending JSON + payload_hash + expires_at; PurchaseOrder rows are created inside DraftPurchaseOrderService::confirmProposal after explicit POST …/confirm — never treated as committed from the assistant tool alone.",
    )
    bp13.para(
        doc,
        "Creation + expiry: DraftPurchaseOrderService::createProposal — authorize Gate, normalize conversation_key and Idempotency-Key header, buildValidatedPayload, hashPayload (SHA-256), set expires_at from erp.purchase_order_proposal_ttl_minutes. "
        "Duplicates: same (user_id, idempotency_key) with same payload_hash returns formatted proposal; hash mismatch throws. "
        "Confirmation: DB::transaction, lockForUpdate on proposal row, assertPayloadIntegrity, create PurchaseOrder + lines with Draft status.",
    )
    bp13.para(
        doc,
        "Assistant request replay: conversation_messages.client_idempotency_key (nullable) + composite unique with conversation_id; idempotency_response JSON; Cache::lock in AssistantRequestService — 409 on body mismatch, 503 when prior response incomplete. "
        "Proposal replay: header-scoped per user as above. Sequential tests do not prove concurrent safety under contention.",
    )
    bp13.code_block(doc, excerpt_with_lines("app/Services/Erp/DraftPurchaseOrderService.php", 34, 183))

    # O Redis / queue
    section_heading(doc, "Section O — Redis, queues, and Ollama async assistant", 1)
    bp13.para(
        doc,
        "When AI_PROVIDER=ollama, POST /api/assistant returns HTTP 202, persists assistant_runs, and dispatches ProcessOllamaAssistantRun to Redis "
        "(config ai.assistant.async.*). OpenAI and Claude bypass the queue — full AiToolCallingService in the HTTP worker (HTTP 200). "
        "Worker: scripts\\queue-worker.cmd or docker-compose `queue` service (documented; Docker runtime unverified on primary dev host).",
    )
    bp13.para(
        doc,
        "Timing matrix (defaults): job timeout 900s; worker --timeout 900s; redis retry_after 960s; stale processing 1080s; tries=1; "
        "queued grace 15s; dispatch lease 120s; max dispatch attempts 5. Recovery on poll — does not inspect Redis payloads (Phase 16.6F).",
    )
    if "async_lifecycle" in diagrams:
        bp13.add_picture(doc, diagrams["async_lifecycle"], "Ollama async lifecycle")
    if "recovery" in diagrams:
        bp13.add_picture(doc, diagrams["recovery"], "Recovery and failure paths")
    bp13.code_block(doc, excerpt_with_lines("app/Jobs/ProcessOllamaAssistantRun.php", 1, 62))
    bp13.code_block(doc, excerpt_with_lines("config/queue.php", 66, 76))

    # P Docker
    section_heading(doc, "Section P — Docker and Compose (unverified runtime)", 1)
    bp13.para(doc, "Dockerfile and docker-compose.yml document intended deployment; successful build/run not verified in this environment.")

    # Q Frontend
    section_heading(doc, "Section Q — Frontend", 1)
    bp13.para(doc, "Vite entry resources/js/chat-app.js; Blade view resources/views/chat.blade.php. Browser acceptance checks UI-01–UI-09 remain pending user verification.")

    # R Testing
    section_heading(doc, "Section R — Testing (reported)", 1)
    add_table(
        doc,
        ["Category", "Evidence (re-run `scripts\\test.ps1` before interviews)"],
        [
            ["PHPUnit full suite (latest reported)", "436 tests, 1,351 assertions (Phase 16.23 regression — not re-run during doc-only 16.25)"],
            ["PHPUnit (Phase 16.6E baseline)", "294 tests, 862 assertions — superseded count, kept for history"],
            ["Redis worker probe", "Harmless job + separate worker — not Ollama E2E"],
            ["Queue::fake recovery tests", "Dispatch counts — not production Redis proof"],
            ["npm test + recovery self-check", "Passed in 16.6E report"],
            ["Vite build", "Passed in 16.6E report"],
            ["composer validate", "Passed in 16.6E report"],
            ["Ollama worker E2E", "Not verified"],
            ["Docker runtime", "Not verified"],
            ["Browser E2E", "Not verified"],
            ["Concurrent idempotency", "Not fully verified"],
            ["SIGKILL recovery", "Not directly verified"],
            ["Phase 16.3 live AI", "Skipped / deferred"],
        ],
    )

    # S Git
    section_heading(doc, "Section S — Git and GitHub", 1)
    bp13.para(
        doc,
        "Repository https://github.com/lalichen169-rgb/stone-erp-ai-assistant (private). A reviewer with access can verify the published tree locally — never embed a stale `git status` snapshot in a long-lived submission PDF/DOCX.",
    )
    bp13.para(
        doc,
        "Suggested verification commands (run from the project root): `git rev-parse HEAD` (current commit), `git ls-remote origin HEAD` (remote tip if you have network + credentials), `git status --short` (local changes). "
        f"HEAD at generation: {git_head()}. Optional remote tip: {git_ls_remote_origin_head() or '— (ls-remote failed or no origin)'}.",
    )

    # T Design
    section_heading(doc, "Section T — Technical decisions", 1)
    bp13.para(doc, "Service layer + Form Requests + API Resources separate transport from ERP rules; Sanctum tokens; tool calling instead of free-text SQL; explicit proposal confirmation.")

    section_heading(doc, "Section T2 — Integrating into an existing Laravel ERP", 1)
    bp13.para(
        doc,
        "Reuse: keep your production Eloquent models, existing domain services, and user roles; add Sanctum (or your existing API auth), conversation tables, assistant_runs (if async), and AI interaction audit tables alongside the ERP schema — do not replace the ERP database. "
        "Expose approved operations only through Laravel AI tools that call your existing services (same pattern as StockLookupService, PurchaseOrderLookupService). "
        "Map Sales/Purchasing (or your roles) to ErpGateRegistrar-style Gates and ToolRegistry::allowedFor. "
        "Writes: proposal snapshot + explicit HTTP confirm with idempotency; queue long-running provider paths; never let the model execute SQL. "
        "This repository's seed data and demo suppliers are illustrative — production would point tools at your real catalogue tables.",
    )

    section_heading(doc, "Section T3 — Estimated AI cost (per 1,000 questions)", 1)
    bp13.para(
        doc,
        "Default OpenAI model: config ai.openai.model = OPENAI_MODEL default gpt-4o-mini. Pricing source: OpenAI API pricing documentation (https://developers.openai.com/api/docs/models/gpt-4o-mini) — verified 2026-11-09: USD $0.15 / 1M input tokens, $0.60 / 1M output tokens. "
        "Formula: cost = (input_tokens/1e6 × input_price) + (output_tokens/1e6 × output_price). Tool execution against MySQL does not incur provider token charges; each additional model round trip after tool results does. "
        "Assumptions below are estimates, not measured production logs. Deterministic preflight (stock grounding, PO status, Purchasing supplier-overdue, Sales refusals) can skip provider calls entirely.",
    )
    add_table(
        doc,
        ["Scenario", "Avg input tokens / question", "Avg output tokens / question", "Model rounds (incl. tools)", "Est. cost / 1,000 questions (USD)"],
        [
            ["Low", "500", "150", "1", "~$0.17"],
            ["Typical", "1,200", "300", "2", "~$0.36"],
            ["High", "3,500", "600", "4", "~$0.89"],
        ],
    )
    bp13.para(
        doc,
        "Ollama (self-hosted): no per-token hosted API fee; operational cost is GPU/CPU, RAM, Redis, and worker uptime — not comparable to OpenAI per-token billing.",
    )

    section_heading(doc, "Section T4 — Demo users and authentication", 1)
    bp13.para(
        doc,
        "DemoUserSeeder creates sales@example.test (Sales) and purchasing@example.test (Purchasing). Password comes from DEMO_USER_PASSWORD in .env (required; never commit). "
        "On firstOrNew: new users get the password hash; existing users update name/role only — re-seeding does not reset an existing user's password. "
        "API: POST /api/auth/token with email, password, device_name. Browser chat: same credentials on / login form; token stored in sessionStorage for the tab.",
    )

    # U Q&A
    section_heading(doc, f"Section U — Substantive interview Q&A ({len(qa_list)} questions)", 1)
    bp13.para(
        doc,
        "Questions below are repository-specific (no per-file label spam). The file path index is Appendix — it does not count toward interview question totals.",
    )
    for cat, q, a in qa_list:
        p = doc.add_paragraph()
        r = p.add_run(f"[{cat}] ")
        r.bold = True
        p.add_run(q)
        bp13.para(doc, f"A: {a}")

    # V Demo
    section_heading(doc, "Section V — Demonstration and interview practice scripts", 1)
    section_heading(doc, "V.A — 10-minute walkthrough (timed)", 2)
    bp13.para(
        doc,
        "0–2 min — Open bootstrap/app.php (lines withRouting) and routes/api.php: narrate 13 authenticated vs public routes, mention Sanctum group + throttle names parsed in Section F.",
    )
    bp13.para(
        doc,
        "2–5 min — Demo ERP read path: GET /api/stock with Houston + DEMO-STQ-CAL-30 (seeded StockSeeder). Point to StockController → StockLookupService → gates in StockLookupService. Show JSON hides costs appropriately for Sales.",
    )
    bp13.para(
        doc,
        "5–8 min — Open AssistantController, AssistantRequestService, AssistantRunCoordinator: Ollama 202 + ProcessOllamaAssistantRun vs OpenAI sync 200; GET /api/assistant/runs/{id}; ToolExecutor forbidden keys.",
    )
    bp13.para(
        doc,
        "6–8 min — Purchasing supplier overdue: “Which POs from Brazos Marble LLC are delayed?” (deterministic ERP). Sales: same question → soft denial; then PO-507 status still works.",
    )
    bp13.para(
        doc,
        "8–10 min — Purchasing draft PO: warehouse clarification → proposal sidebar → POST .../confirm with Idempotency-Key; emphasize proposal ≠ PO until confirm; stock unchanged.",
    )
    section_heading(doc, "V.D — 15-minute design discussion (extended prompts)", 2)
    for t in [
        "How would you add GeminiProvider behind AiProviderInterface?",
        "Claude direct API vs Amazon Bedrock — same model family, different integration surface.",
        "Why Laravel executes tools instead of the model (authorization + SQL injection surface).",
        "Supplier-specific overdue for Purchasing vs Sales soft denial.",
        "Why PO confirmation does not increase stock.",
    ]:
        doc.add_paragraph(t, style="List Bullet")
    section_heading(doc, "V.B — 20-minute live coding exercise (no live AI)", 2)
    bp13.para(
        doc,
        "Requirement: add an optional query parameter `product_sku_prefix` to GET /api/products that matches SKUs starting with the given prefix (case-sensitive or case-insensitive — choose one and document).",
    )
    bp13.para(
        doc,
        "Files: app/Http/Requests/Api/Erp/ProductSearchRequest.php (rules + filters), app/Services/Erp/StockLookupService.php (apply prefix scope to the query), app/Http/Controllers/Api/Erp/ProductController.php (pass filters), tests/Feature/Api/… new or extended feature test asserting both Sales and Purchasing can call the route.",
    )
    bp13.para(
        doc,
        "Steps: (1) add validation rule optional string max 255, (2) merge into filters() array, (3) implement SQL where sku LIKE concat(prefix, '%') or equivalent via query builder, (4) assert Gate still runs, (5) run `php artisan test --filter=YourTestName`. Expected: 200 with narrowed list; 401 without token.",
    )
    bp13.para(
        doc,
        "Discuss: why Form Request centralizes validation; how StockLookupService stays the single ERP gate entry; why tests belong under Feature for HTTP contracts.",
    )
    section_heading(doc, "V.C — 15-minute architecture discussion (prompts + defensible answers)", 2)
    topics = [
        "Provider portability — cite AiProviderInterface + AiProviderFactory unsupported names.",
        "Tool architecture — ToolRegistry vs ToolExecutor separation of concerns.",
        "Context management — ConversationContextManager budgets + Sales tool-row filter.",
        "RBAC — ErpGateRegistrar abilities vs Tool allowedFor.",
        "Freight allocation — LandedCostService remainder line + ErpMoney half-up.",
        "Proposal confirmation — lockForUpdate + payload_hash integrity.",
        "Assistant vs proposal idempotency — two mechanisms from Section N.",
        "Redis/queue — Ollama async only; OpenAI/Claude synchronous; recovery limitations (16.6F).",
        "Unverified — Docker runtime, Ollama worker E2E, browser E2E, concurrent idempotency, SIGKILL.",
        "Production hardening — metrics for stuck runs, optional Redis inspection, not claiming perfect queue.",
    ]
    for t in topics:
        doc.add_paragraph(t, style="List Bullet")

    # W Troubleshooting
    section_heading(doc, "Section W — Troubleshooting", 1)
    add_table(
        doc,
        ["Symptom", "Where to look"],
        [
            ["401 API", "Token missing — TokenController"],
            ["403 proposal", "AuthorizesPurchasingProposalAccess"],
            ["409 assistant", "AssistantIdempotencyException mismatch"],
            ["404 landed cost", "LandedCostCalculationException → abort(404)"],
        ],
    )

    # X Glossary
    section_heading(doc, "Section X — Glossary", 1)
    for term, defin in [
        ("Conversation ID", "Assistant JSON field `conversation_id` — maps to conversations.public_id UUID (server-owned)."),
        ("Idempotency key (HTTP)", "Client-supplied key (header or field) so retries replay the same logical operation safely."),
        ("Assistant request replay", "conversation_messages.client_idempotency_key + idempotency_response JSON + cache lock — AssistantRequestService."),
        ("Proposal replay", "User-scoped Idempotency-Key header on POST /api/purchase-order-proposals; unique (user_id, idempotency_key)."),
        ("Proposal vs purchase order", "Proposal is pending JSON + hash; PO rows are created on confirmProposal only."),
        ("Predis", "Pure-PHP Redis client — used in project .env examples when phpredis is unavailable."),
        ("phpredis", "PHP Redis extension client — Laravel config default when REDIS_CLIENT is unset."),
        ("Tool registry", "ToolRegistry — allowlisted AiToolInterface instances and schema definitions."),
        ("Tool executor", "ToolExecutor — argument hygiene, schema validation, and dispatch to a tool."),
        ("Landed cost", "Unit cost plus value-proportional freight allocation from LandedCostService."),
        ("Freight allocation", "Value-weighted shares with half-up rounding and remainder to largest line."),
        ("Sanctum", "API token auth — personal_access_tokens stores hashed secrets."),
        ("Queue worker", "Long-running `php artisan queue:work` process — docker-compose `queue` service."),
        ("lockForUpdate()", "SELECT … FOR UPDATE in confirmProposal/cancelProposal — row lock under transaction."),
        ("Payload hash", "SHA-256 over normalized proposal JSON — DraftPurchaseOrderService::hashPayload."),
    ]:
        p = doc.add_paragraph()
        r = p.add_run(f"{term} — ")
        r.bold = True
        p.add_run(defin)

    # Y Limitations
    section_heading(doc, "Section Y — Honest limitations (incl. Phase 16.6F)", 1)
    for item in [
        "Dispatch lease and Redis push are not one atomic transaction — duplicate queue payloads possible; DB claim prevents duplicate inference.",
        "Recovery does not inspect Redis for a specific job; bounded re-dispatch may fail a still-delayed job (queue_recovery_exhausted).",
        "In-request cache dedupe for dispatch is not a guaranteed long cross-process mutex.",
        "Worker SIGKILL may leave processing until stale reconciliation on a later poll; failed() not guaranteed.",
        "Recovery is poll/replay driven — no scheduler.",
        "New conversation clears client recovery state; server-side job may continue.",
        "Concurrent idempotency and full Ollama worker E2E not proven; Queue::fake tests are not Redis evidence.",
        "Docker runtime and browser E2E not verified on primary dev host.",
        "Phase 16.6 is acceptable for the challenge — not a claim of production-perfect queue hardening.",
        "Re-run php artisan test before interviews; Section R counts are historical Phase 16.6E unless refreshed.",
    ]:
        doc.add_paragraph(item, style="List Bullet")

    # Appendix paths
    section_heading(doc, "Appendix — File path index (reference only)", 1)
    bp13.para(doc, "Bulleted paths mirror Section B — use for quick lookup; not counted as substantive interview questions in Section U.")
    for rel in all_paths:
        doc.add_paragraph(rel, style="List Bullet")

    section_heading(doc, "Final interview revision checklist", 1)
    for item in [
        "Re-run php artisan test and npm test before interviews.",
        "Regenerate this DOCX after meaningful code changes.",
        "Verify git remote and working tree clean.",
        "Complete UI checklist when possible.",
    ]:
        doc.add_paragraph(item, style="List Bullet")

    OUT_DOCX.parent.mkdir(parents=True, exist_ok=True)
    doc.save(str(OUT_DOCX))

    return {
        "files": n_files,
        "tables": n_tables,
        "columns": len(cols),
        "extras": len(extras),
        "api_routes": len(api_routes),
        "models": len(models),
        "qa_pairs": len(qa_list),
        "diagrams": diagram_count,
        "missing_submission_docs": missing_sub,
    }


def main() -> int:
    try:
        import docx  # noqa: F401
    except ImportError:
        print("pip install python-docx matplotlib pillow", file=__import__("sys").stderr)
        return 1
    stats = build_doc()
    ok = validate_docx(OUT_DOCX)
    print("Wrote", OUT_DOCX)
    print("STATS", stats)
    return 0 if ok else 1


if __name__ == "__main__":
    raise SystemExit(main())
