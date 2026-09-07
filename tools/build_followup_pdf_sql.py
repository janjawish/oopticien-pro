from __future__ import annotations

import datetime as dt
import hashlib
import json
import re
import subprocess
import unicodedata
from collections import Counter, defaultdict
from dataclasses import dataclass
from decimal import Decimal, InvalidOperation
from pathlib import Path
from typing import Any

import pdfplumber


PDF_PATH = Path(r"C:\Users\a\Downloads\suivie - Copie de SUIVI CLIENT.pdf")
PROJECT_ROOT = Path(r"C:\Users\a\Desktop\oopticien\outil\oopticien-pro")
OUTPUT_DIR = PROJECT_ROOT / "outputs" / "oopticien-suivi-2026-08-04"
SQL_PATH = OUTPUT_DIR / "import_suivi_clients_20260804.sql"
REPORT_PATH = OUTPUT_DIR / "rapport_import_suivi_20260804.json"
MYSQL_PATH = Path(r"C:\xampp\mysql\bin\mysql.exe")
REFERENCE_DB = "oopticien_compare_20260804"
SOURCE_ORIGIN = "pdf_suivi_20260804"
SOURCE_FILE_LABEL = "suivie - Copie de SUIVI CLIENT.pdf"

TABLE_SETTINGS = {
    "vertical_strategy": "lines",
    "horizontal_strategy": "lines",
    "intersection_tolerance": 3,
    "snap_tolerance": 3,
    "join_tolerance": 3,
    "text_tolerance": 1,
}

COLOR_STATUS = {
    "FF00FF": "cloture",       # violet : facturé / terminé
    "00FF00": "a_facturer",    # vert : PEC acceptée, à facturer
    "FF0000": "bloque",        # rouge : dossier problématique
    "A2C4C9": "en_cours",      # gris : dossier en cours
}
LENS_COLORS = {"00FFFF", "00B0F0", "0000FF", "4A86E8", "3D85C6", "9FC5E8"}
BROWN_COLOR = "BF9000"
YELLOW_COLOR = "FFFF00"


@dataclass
class ParsedRow:
    page: int
    row: int
    cells: list[str]
    color: str
    year: int
    last_name: str
    first_name: str
    client_key: str
    scheme: str | None
    mutual: str | None
    dossier_type: str
    prescription_status: str
    mutual_status: str
    folder_status: str
    quote_date: str | None
    pec_sent_at: str | None
    pec_response_at: str | None
    invoice_date: str | None
    teletrans_status: str
    teletrans_date: str | None
    ro: Decimal
    rc: Decimal
    rac: Decimal
    total: Decimal
    total_warning: int
    comment: str | None
    next_action: str | None
    priority: str
    source_fingerprint: str
    payment_ro_date: str | None
    payment_rc_date: str | None
    payment_rac_date: str | None
    payment_ro_paid: bool
    payment_rc_paid: bool
    payment_rac_paid: bool
    payment_rac_method: str | None
    payment_rac_caution: bool


def clean_text(value: Any) -> str:
    if value is None:
        return ""
    return re.sub(r"\s+", " ", str(value).replace("\u00a0", " ")).strip()


def ascii_key(value: str) -> str:
    value = unicodedata.normalize("NFKD", value)
    value = "".join(char for char in value if not unicodedata.combining(char))
    return re.sub(r"[^A-Z0-9]+", " ", value.upper()).strip()


def compact_name(value: str) -> str:
    return re.sub(r"[^A-Z0-9]", "", ascii_key(value))


def normalize_person_name(value: str, upper: bool = False) -> str:
    value = clean_text(value)
    value = re.sub(r"\s+(?:OK|KO)$", "", value, flags=re.IGNORECASE)
    value = re.sub(r"\s+", " ", value).strip(" -")
    if upper:
        return value.upper()
    return " ".join(part.capitalize() if not part.isupper() else part.title() for part in value.split())


def sql_literal(value: Any) -> str:
    if value is None:
        return "NULL"
    if isinstance(value, bool):
        return "1" if value else "0"
    if isinstance(value, Decimal):
        return f"{value:.2f}"
    if isinstance(value, (int, float)):
        return str(value)
    text = str(value).replace("\\", "\\\\").replace("'", "''")
    return f"'{text}'"


def amount(value: str) -> Decimal:
    normalized = clean_text(value).replace("€", "").replace(" ", "").replace(",", ".")
    normalized = re.sub(r"[^0-9.\-]", "", normalized)
    if not normalized or normalized in {"-", ".", "-."}:
        return Decimal("0.00")
    try:
        return Decimal(normalized).quantize(Decimal("0.01"))
    except InvalidOperation:
        return Decimal("0.00")


def date_from_text(value: str, fallback_year: int | None = None) -> str | None:
    text = clean_text(value)
    matches = list(re.finditer(r"(?<!\d)(\d{1,2})[\s./-]+(\d{1,2})(?:[\s./-]+(\d{2,4}))?(?!\d)", text))
    for match in matches:
        day, month = int(match.group(1)), int(match.group(2))
        year_raw = match.group(3)
        if year_raw:
            year = int(year_raw)
            if year < 100:
                year += 2000
        elif fallback_year:
            year = fallback_year
        else:
            continue
        try:
            return dt.date(year, month, day).isoformat()
        except ValueError:
            continue
    return None


def evidence_truthy(value: str) -> bool:
    key = ascii_key(value)
    return bool(re.match(r"^(?:1|OUI|YES|OK|FAIT|ENCAISS)", key))


def payment_method(value: str) -> tuple[str | None, bool]:
    key = ascii_key(value)
    caution = "CAUTION" in key
    if "CB" in key or "CARTE" in key:
        return "cb", caution
    if "CHEQ" in key:
        return "cheque", caution
    if "ESPE" in key:
        return "especes", caution
    if "VIREMENT" in key:
        return "virement", caution
    if key:
        return "autre", caution
    return None, caution


def color_hex(color: Any) -> str:
    if not isinstance(color, (list, tuple)) or len(color) < 3:
        return "FFFFFF"
    values = []
    for component in color[:3]:
        number = float(component)
        if number <= 1:
            number *= 255
        values.append(max(0, min(255, round(number))))
    return "".join(f"{component:02X}" for component in values)


def row_color(page: Any, table_row: Any) -> str:
    cells = [cell for cell in table_row.cells if cell]
    if not cells:
        return "FFFFFF"
    first = cells[0]
    x = min(first[2] - 0.5, first[0] + 8)
    y = (first[1] + first[3]) / 2
    candidates = []
    for rect in page.rects:
        if rect.get("x0", 0) <= x <= rect.get("x1", 0) and rect.get("top", 0) <= y <= rect.get("bottom", 0):
            color = rect.get("non_stroking_color")
            if color is not None:
                area = (rect.get("x1", 0) - rect.get("x0", 0)) * (rect.get("bottom", 0) - rect.get("top", 0))
                candidates.append((area, color_hex(color)))
    if not candidates:
        return "FFFFFF"
    return max(candidates, key=lambda item: item[0])[1]


def is_year_separator(cells: list[str]) -> int | None:
    nonempty = [clean_text(value) for value in cells if clean_text(value)]
    if not nonempty:
        return None
    if len(set(nonempty)) == 1 and nonempty[0] in {"2024", "2025", "2026"}:
        return int(nonempty[0])
    return None


def row_year(cells: list[str], section_year: int) -> int:
    detected = None
    for index in [5, 10, 11, 12, 13, 14, 16]:
        years = re.findall(r"(?:19|20)\d{2}", cells[index])
        if years:
            candidate = int(years[-1])
            if candidate in {2024, 2025, 2026}:
                detected = candidate
                break
        short_years = re.findall(r"\b\d{1,2}[/. -]\d{1,2}[/. -](2[456])\b", cells[index])
        if short_years:
            detected = 2000 + int(short_years[-1])
            break
    if detected in {2024, 2025}:
        return detected
    if section_year in {2024, 2025}:
        return section_year
    return detected or section_year


def prescription_status(value: str) -> str:
    key = ascii_key(value)
    if re.search(r"\bNON\b", key):
        return "non"
    if re.search(r"\bOUI\b", key) or "LENTILL" in key:
        return "oui"
    return "attente"


def mutual_status(devis: str, comment: str, mutual: str) -> str:
    evidence = ascii_key(f"{devis} {comment}")
    mutual_key = ascii_key(mutual)
    if re.search(r"\bPEC\b.*\b(?:OK|ACCEPT|ACCORD)", evidence) or re.search(r"\b(?:OK|ACCEPT|ACCORD)\b.*\bPEC\b", evidence):
        return "pec_acceptee"
    if "REFUS" in evidence:
        return "pec_refusee"
    if re.search(r"\bPEC\b.*(?:INCOMPL|MANQU|PJ|PIECE)", evidence) or "ATT DE PJ" in evidence:
        return "incomplete"
    if re.search(r"\bPEC\b.*(?:ATT|EN COURS|ENVOY|CONTROLE)", evidence) or re.search(r"(?:ATT|EN COURS|ENVOY|CONTROLE).*\bPEC\b", evidence):
        return "en_attente"
    if not mutual_key or "PAS DE TP" in mutual_key or "PAS DE TPP" in mutual_key:
        return "non_envoyee"
    return "non_envoyee"


def infer_status(cells: list[str], mutual_state: str, is_invoice: bool, is_teletrans: bool) -> str:
    if is_teletrans:
        return "teletransmis"
    if is_invoice:
        return "cloture"
    if mutual_state == "pec_acceptee":
        return "a_facturer"
    if mutual_state in {"envoyee", "en_attente", "pec_refusee", "incomplete"}:
        return "demande_mutuelle"
    if clean_text(cells[5]):
        return "devis"
    return "brouillon"


def load_reference_names() -> list[tuple[str, str]]:
    if not MYSQL_PATH.exists():
        return []
    query = f"SELECT DISTINCT last_name,first_name FROM {REFERENCE_DB}.clients WHERE last_name<>'' AND first_name<>''"
    try:
        result = subprocess.run(
            [str(MYSQL_PATH), "-u", "root", "--default-character-set=utf8mb4", "-N", "-B", "-e", query],
            check=True,
            capture_output=True,
        )
    except (subprocess.SubprocessError, OSError):
        return []
    decoded = result.stdout.decode("utf-8", errors="replace")
    names = []
    for line in decoded.splitlines():
        parts = line.split("\t", 1)
        if len(parts) == 2:
            names.append((clean_text(parts[0]), clean_text(parts[1])))
    return names


def reference_name_maps(names: list[tuple[str, str]]) -> tuple[dict[str, list[tuple[str, str]]], dict[str, list[tuple[str, str]]]]:
    direct: dict[str, list[tuple[str, str]]] = defaultdict(list)
    reversed_map: dict[str, list[tuple[str, str]]] = defaultdict(list)
    for last, first in names:
        direct[compact_name(last + first)].append((last, first))
        reversed_map[compact_name(first + last)].append((last, first))
    return direct, reversed_map


def correct_name(last: str, first: str, direct: dict[str, list[tuple[str, str]]], reversed_map: dict[str, list[tuple[str, str]]]) -> tuple[str, str, str]:
    raw_key = compact_name(last + first)
    candidates = direct.get(raw_key, [])
    if len(candidates) == 1:
        return candidates[0][0], candidates[0][1], "reference_exacte"
    reversed_candidates = reversed_map.get(raw_key, [])
    if len(reversed_candidates) == 1:
        return reversed_candidates[0][0], reversed_candidates[0][1], "reference_inversee"

    # Corrige les mots coupés entre NOM et PRÉNOM dans le PDF, par exemple
    # « ONCALVES DE OLIVEIR » + « A DOMINIQUE ».
    all_candidates = []
    for key, entries in direct.items():
        if not raw_key or abs(len(key) - len(raw_key)) > 2:
            continue
        if raw_key in key or key in raw_key:
            all_candidates.extend(entries)
    if len(all_candidates) == 1:
        return all_candidates[0][0], all_candidates[0][1], "reference_prefixe"

    return normalize_person_name(last, upper=True), normalize_person_name(first), "pdf"


def parse_pdf() -> tuple[list[ParsedRow], dict[str, Any]]:
    references = load_reference_names()
    direct_names, reversed_names = reference_name_maps(references)
    source_hash = hashlib.sha256(PDF_PATH.read_bytes()).hexdigest()
    section_year = 2024
    parsed: list[ParsedRow] = []
    skipped: list[dict[str, Any]] = []
    excluded_yellow: list[dict[str, Any]] = []
    incomplete_clients: list[dict[str, Any]] = []
    corrections = Counter()

    with pdfplumber.open(PDF_PATH) as pdf:
        for page_number, page in enumerate(pdf.pages, start=1):
            tables = page.find_tables(TABLE_SETTINGS)
            if not tables:
                skipped.append({"page": page_number, "reason": "aucun_tableau"})
                continue
            table = max(tables, key=lambda item: len(item.rows))
            values = table.extract()
            for row_index, (table_row, raw_cells) in enumerate(zip(table.rows, values), start=1):
                cells = [clean_text(value) for value in (raw_cells[:17] + [""] * 17)[:17]]
                if row_index == 1 and ascii_key(cells[0]) == "NOM":
                    continue
                separator = is_year_separator(cells)
                if separator:
                    section_year = separator
                    continue
                raw_last, raw_first = cells[0], cells[1]
                if not raw_last or not any(char.isalpha() for char in raw_last + raw_first):
                    if any(cells):
                        skipped.append({"page": page_number, "row": row_index, "reason": "ligne_non_client", "cells": cells})
                    continue
                year = row_year(cells, section_year)
                color = row_color(page, table_row)
                row_text = " ".join(cells)
                is_derogation = color == YELLOW_COLOR and "DEROG" in ascii_key(row_text)
                if color == YELLOW_COLOR and not is_derogation:
                    excluded_yellow.append({
                        "page": page_number,
                        "row": row_index,
                        "name": f"{raw_last} {raw_first}",
                        "devis": cells[5],
                        "comment": cells[16],
                    })
                    continue
                if not raw_first:
                    last, first, correction = normalize_person_name(raw_last, upper=True), "Non renseigné", "prenom_manquant"
                    incomplete_clients.append({"page": page_number, "row": row_index, "name": raw_last})
                elif re.fullmatch(r"[\d\s./-]+", raw_last):
                    last, first, correction = "INCONNU", normalize_person_name(raw_first), "nom_ecrase_par_date"
                    incomplete_clients.append({"page": page_number, "row": row_index, "name": f"{raw_last} {raw_first}"})
                elif re.search(r"LEN\s*TILLES\s+PROG", ascii_key(f"{raw_last} {raw_first}")):
                    last, first, correction = "INCONNU", "Samira", "nom_ecrase_par_commentaire"
                    incomplete_clients.append({"page": page_number, "row": row_index, "name": f"{raw_last} {raw_first}"})
                else:
                    last, first, correction = correct_name(raw_last, raw_first, direct_names, reversed_names)
                corrections[correction] += 1
                dossier_type = "lentilles" if color in LENS_COLORS or "LENTILL" in ascii_key(row_text) else "lunettes"
                mutual_state = mutual_status(cells[5], cells[16], cells[4])

                quote_date = date_from_text(cells[5], year)
                invoice_date = date_from_text(cells[10], year)
                invoice_evidence = ascii_key(f"{cells[10]} {cells[5]} {cells[16]}")
                is_invoice = bool(invoice_date or "FACTUR" in invoice_evidence)
                if is_invoice and not invoice_date:
                    invoice_date = date_from_text(f"{cells[10]} {cells[5]} {cells[16]}", year)

                teletrans_date = date_from_text(cells[11], year)
                is_teletrans = bool(teletrans_date or evidence_truthy(cells[11]))
                if is_derogation:
                    folder_status = "derogation"
                elif color == BROWN_COLOR:
                    folder_status = "en_cours"
                    mutual_state = "non_envoyee"
                else:
                    folder_status = COLOR_STATUS.get(color) or infer_status(cells, mutual_state, is_invoice, is_teletrans)
                if year in {2024, 2025} and color != BROWN_COLOR and not is_derogation:
                    folder_status = "cloture"

                pec_date = date_from_text(f"{cells[5]} {cells[16]}", year)
                pec_sent_at = None
                pec_response_at = None
                if mutual_state == "pec_acceptee":
                    pec_response_at = f"{pec_date} 00:00:00" if pec_date else None
                elif mutual_state in {"envoyee", "en_attente", "incomplete", "pec_refusee"}:
                    pec_sent_at = f"{pec_date} 00:00:00" if pec_date else None

                ro, rc, rac, total = (amount(cells[index]) for index in [6, 7, 8, 9])
                warning = int(abs((ro + rc + rac) - total) > Decimal("0.01"))
                next_action = {
                    "a_facturer": "Facturer le dossier",
                    "demande_mutuelle": "Vérifier la réponse mutuelle",
                    "bloque": "Traiter le dossier problématique",
                    "en_cours": "Faire la demande PEC" if color == BROWN_COLOR else "Poursuivre le traitement du dossier",
                    "derogation": "Faire la dérogation",
                    "devis": "Poursuivre le devis",
                }.get(folder_status)
                priority = "urgente" if folder_status == "bloque" else ("haute" if warning or folder_status == "derogation" else "normale")

                pay_ro_date = date_from_text(cells[12], year)
                pay_rc_date = date_from_text(cells[13], year)
                pay_rac_date = date_from_text(cells[14], year)
                pay_rac_method, pay_rac_caution = payment_method(cells[15])
                client_key = hashlib.sha256(f"{compact_name(last)}|{compact_name(first)}".encode("utf-8")).hexdigest()
                fingerprint_payload = json.dumps(
                    {"pdf": source_hash, "page": page_number, "row": row_index, "cells": cells},
                    ensure_ascii=False,
                    sort_keys=True,
                )
                fingerprint = hashlib.sha256(fingerprint_payload.encode("utf-8")).hexdigest()

                parsed.append(ParsedRow(
                    page=page_number,
                    row=row_index,
                    cells=cells,
                    color=color,
                    year=year,
                    last_name=last[:100],
                    first_name=first[:100],
                    client_key=client_key,
                    scheme=cells[3][:100] or None,
                    mutual=cells[4][:160] or None,
                    dossier_type=dossier_type,
                    prescription_status=prescription_status(cells[2]),
                    mutual_status=mutual_state,
                    folder_status=folder_status,
                    quote_date=quote_date,
                    pec_sent_at=pec_sent_at,
                    pec_response_at=pec_response_at,
                    invoice_date=invoice_date,
                    teletrans_status="oui" if is_teletrans else "non",
                    teletrans_date=teletrans_date,
                    ro=ro,
                    rc=rc,
                    rac=rac,
                    total=total,
                    total_warning=warning,
                    comment=cells[16] or None,
                    next_action=next_action,
                    priority=priority,
                    source_fingerprint=fingerprint,
                    payment_ro_date=pay_ro_date,
                    payment_rc_date=pay_rc_date,
                    payment_rac_date=pay_rac_date,
                    payment_ro_paid=bool(pay_ro_date or evidence_truthy(cells[12])),
                    payment_rc_paid=bool(pay_rc_date or evidence_truthy(cells[13])),
                    payment_rac_paid=bool(pay_rac_date or evidence_truthy(cells[14])),
                    payment_rac_method=pay_rac_method,
                    payment_rac_caution=pay_rac_caution,
                ))

    known_colors = set(COLOR_STATUS) | LENS_COLORS | {BROWN_COLOR, YELLOW_COLOR}
    unusual_rows = [
        {
            "page": row.page,
            "row": row.row,
            "name": f"{row.last_name} {row.first_name}",
            "color": row.color,
            "year": row.year,
            "status_inferred": row.folder_status,
            "devis": row.cells[5],
            "comment": row.cells[16],
        }
        for row in parsed if row.color not in known_colors
    ]
    report = {
        "source": str(PDF_PATH),
        "source_sha256": source_hash,
        "reference_names_loaded": len(references),
        "rows": len(parsed),
        "unique_clients": len({row.client_key for row in parsed}),
        "colors": dict(Counter(row.color for row in parsed)),
        "years": dict(Counter(str(row.year) for row in parsed)),
        "statuses": dict(Counter(row.folder_status for row in parsed)),
        "types": dict(Counter(row.dossier_type for row in parsed)),
        "mutual_statuses": dict(Counter(row.mutual_status for row in parsed)),
        "total_warnings": sum(row.total_warning for row in parsed),
        "name_sources": dict(corrections),
        "excluded_yellow_count": len(excluded_yellow),
        "excluded_yellow": excluded_yellow,
        "incomplete_clients": incomplete_clients,
        "unusual_color_rows": unusual_rows,
        "skipped": skipped,
    }
    return parsed, report


def chunked(values: list[str], size: int = 200) -> list[list[str]]:
    return [values[index:index + size] for index in range(0, len(values), size)]


def payment_state(expected: Decimal, paid: bool, caution: bool = False) -> str:
    if expected <= 0:
        return "non_applicable"
    if caution:
        return "cheque_caution"
    return "encaisse" if paid else "attendu"


def build_sql(rows: list[ParsedRow], report: dict[str, Any]) -> str:
    clients: dict[str, dict[str, Any]] = {}
    for row in rows:
        record = clients.setdefault(row.client_key, {
            "client_key": row.client_key,
            "last_name": row.last_name,
            "first_name": row.first_name,
            "scheme": None,
            "mutual": None,
            "rank": (-1, -1, -1),
        })
        rank = (row.year, row.page, row.row)
        if rank >= record["rank"]:
            if row.scheme:
                record["scheme"] = row.scheme
            if row.mutual:
                record["mutual"] = row.mutual
            record["rank"] = rank

    lines = [
        "-- Oopticien Pro - import complet de la fiche de suivi du 04/08/2026",
        f"-- Source : {SOURCE_FILE_LABEL}",
        "-- Règles : violet=clôturé, vert=à facturer, gris=en cours, rouge=problématique, bleu=lentilles.",
        "-- Jaune avec la mention dérog=dérogation; autres jaunes exclus; marron=en cours / PEC à faire.",
        "-- Tous les dossiers 2024 et 2025 sont clôturés, sauf les lignes marron explicitement signalées en cours.",
        "-- Sélectionnez la base oopticien_pro dans phpMyAdmin avant d'importer ce fichier.",
        f"-- Résultat attendu : {report['unique_clients']} clients uniques, {report['rows']} dossiers, {report['rows'] * 3} lignes de paiement.",
        "",
        "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci;",
        "ALTER TABLE dossiers MODIFY folder_status ENUM(",
        "  'brouillon','devis','en_cours','demande_mutuelle','pec_acceptee','a_facturer','facture','teletransmis',",
        "  'commande_a_faire','commande_envoyee','verres_recus','montage','pret','client_prevenu','remis',",
        "  'cloture','bloque','sav','derogation','annule') NOT NULL DEFAULT 'brouillon';",
        "SET @oopticien_import_user := (SELECT MIN(id) FROM users WHERE is_active=1);",
        "START TRANSACTION;",
        "",
        "DROP TEMPORARY TABLE IF EXISTS tmp_suivi_clients_20260804;",
        "DROP TEMPORARY TABLE IF EXISTS tmp_suivi_rows_20260804;",
        "DROP TEMPORARY TABLE IF EXISTS tmp_suivi_client_map_20260804;",
        "",
        "CREATE TEMPORARY TABLE tmp_suivi_clients_20260804 (",
        "  client_key CHAR(64) NOT NULL PRIMARY KEY,",
        "  last_name VARCHAR(100) NOT NULL,",
        "  first_name VARCHAR(100) NOT NULL,",
        "  social_security_scheme VARCHAR(100) NULL,",
        "  mutual_name VARCHAR(160) NULL",
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        "",
    ]

    client_values = [
        "(" + ",".join(sql_literal(record[key]) for key in ["client_key", "last_name", "first_name", "scheme", "mutual"]) + ")"
        for record in clients.values()
    ]
    for chunk in chunked(client_values):
        lines.append("INSERT INTO tmp_suivi_clients_20260804 (client_key,last_name,first_name,social_security_scheme,mutual_name) VALUES")
        lines.append(",\n".join(chunk) + ";")
        lines.append("")

    lines.extend([
        "CREATE TEMPORARY TABLE tmp_suivi_rows_20260804 (",
        "  source_fingerprint CHAR(64) NOT NULL PRIMARY KEY,",
        "  client_key CHAR(64) NOT NULL,",
        "  source_page SMALLINT UNSIGNED NOT NULL,",
        "  source_row SMALLINT UNSIGNED NOT NULL,",
        "  source_year SMALLINT UNSIGNED NOT NULL,",
        "  source_color CHAR(6) NOT NULL,",
        "  dossier_type VARCHAR(20) NOT NULL,",
        "  prescription_status VARCHAR(20) NOT NULL,",
        "  mutual_status VARCHAR(30) NOT NULL,",
        "  folder_status VARCHAR(30) NOT NULL,",
        "  quote_date DATE NULL,",
        "  pec_sent_at DATETIME NULL,",
        "  pec_response_at DATETIME NULL,",
        "  invoice_date DATE NULL,",
        "  teletrans_status VARCHAR(3) NOT NULL,",
        "  teletrans_date DATE NULL,",
        "  ro_amount DECIMAL(10,2) NOT NULL,",
        "  rc_amount DECIMAL(10,2) NOT NULL,",
        "  rac_amount DECIMAL(10,2) NOT NULL,",
        "  total_amount DECIMAL(10,2) NOT NULL,",
        "  total_warning TINYINT(1) NOT NULL,",
        "  optician_comment TEXT NULL,",
        "  next_action VARCHAR(255) NULL,",
        "  priority VARCHAR(20) NOT NULL,",
        "  payment_ro_date DATE NULL,",
        "  payment_ro_paid TINYINT(1) NOT NULL,",
        "  payment_rc_date DATE NULL,",
        "  payment_rc_paid TINYINT(1) NOT NULL,",
        "  payment_rac_date DATE NULL,",
        "  payment_rac_paid TINYINT(1) NOT NULL,",
        "  payment_rac_method VARCHAR(20) NULL,",
        "  payment_rac_caution TINYINT(1) NOT NULL,",
        "  KEY idx_tmp_suivi_client_key (client_key)",
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        "",
    ])

    row_values = []
    for row in rows:
        values = [
            row.source_fingerprint, row.client_key, row.page, row.row, row.year, row.color,
            row.dossier_type, row.prescription_status, row.mutual_status, row.folder_status,
            row.quote_date, row.pec_sent_at, row.pec_response_at, row.invoice_date,
            row.teletrans_status, row.teletrans_date, row.ro, row.rc, row.rac, row.total,
            row.total_warning, row.comment, row.next_action, row.priority,
            row.payment_ro_date, row.payment_ro_paid, row.payment_rc_date, row.payment_rc_paid,
            row.payment_rac_date, row.payment_rac_paid, row.payment_rac_method, row.payment_rac_caution,
        ]
        row_values.append("(" + ",".join(sql_literal(value) for value in values) + ")")
    row_columns = (
        "source_fingerprint,client_key,source_page,source_row,source_year,source_color,"
        "dossier_type,prescription_status,mutual_status,folder_status,quote_date,pec_sent_at,pec_response_at,invoice_date,"
        "teletrans_status,teletrans_date,ro_amount,rc_amount,rac_amount,total_amount,total_warning,optician_comment,next_action,priority,"
        "payment_ro_date,payment_ro_paid,payment_rc_date,payment_rc_paid,payment_rac_date,payment_rac_paid,payment_rac_method,payment_rac_caution"
    )
    for chunk in chunked(row_values):
        lines.append(f"INSERT INTO tmp_suivi_rows_20260804 ({row_columns}) VALUES")
        lines.append(",\n".join(chunk) + ";")
        lines.append("")

    lines.extend([
        "-- Création des clients absents (un même nom/prénom reste un seul client avec plusieurs dossiers).",
        "INSERT INTO clients (first_name,last_name,social_security_scheme,mutual_name,created_by)",
        "SELECT t.first_name,t.last_name,t.social_security_scheme,t.mutual_name,@oopticien_import_user",
        "FROM tmp_suivi_clients_20260804 t",
        "WHERE NOT EXISTS (",
        "  SELECT 1 FROM clients c",
        "  WHERE TRIM(c.last_name)=TRIM(t.last_name) AND TRIM(c.first_name)=TRIM(t.first_name)",
        ");",
        "",
        "CREATE TEMPORARY TABLE tmp_suivi_client_map_20260804 (",
        "  client_key CHAR(64) NOT NULL PRIMARY KEY,",
        "  client_id INT UNSIGNED NOT NULL",
        ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;",
        "",
        "INSERT INTO tmp_suivi_client_map_20260804 (client_key,client_id)",
        "SELECT t.client_key,MIN(c.id)",
        "FROM tmp_suivi_clients_20260804 t",
        "JOIN clients c ON TRIM(c.last_name)=TRIM(t.last_name) AND TRIM(c.first_name)=TRIM(t.first_name)",
        "GROUP BY t.client_key;",
        "",
        "-- Création idempotente des dossiers : le fingerprint empêche les doublons si le SQL est relancé.",
        "INSERT INTO dossiers (",
        "  client_id,dossier_type,prescription_status,mutual_status,folder_status,status_changed_at,quote_date,",
        "  pec_sent_at,pec_response_at,invoice_date,teletrans_status,teletrans_date,",
        "  ro_amount,rc_amount,rac_amount,total_amount,total_warning,optician_comment,next_action,priority,",
        "  source_origin,source_fingerprint,created_by",
        ")",
        "SELECT m.client_id,r.dossier_type,r.prescription_status,r.mutual_status,r.folder_status,NOW(),r.quote_date,",
        "       r.pec_sent_at,r.pec_response_at,r.invoice_date,r.teletrans_status,r.teletrans_date,",
        "       r.ro_amount,r.rc_amount,r.rac_amount,r.total_amount,r.total_warning,r.optician_comment,r.next_action,r.priority,",
        f"       {sql_literal(SOURCE_ORIGIN)},r.source_fingerprint,@oopticien_import_user",
        "FROM tmp_suivi_rows_20260804 r",
        "JOIN tmp_suivi_client_map_20260804 m ON m.client_key=r.client_key",
        "WHERE NOT EXISTS (SELECT 1 FROM dossiers d WHERE d.source_fingerprint=r.source_fingerprint);",
        "",
        "-- Trois lignes de paiement par dossier (RO, RC, client/RAC).",
        "INSERT INTO payments (dossier_id,payer,expected_amount,paid_amount,payment_date,payment_method,status,created_by)",
        "SELECT d.id,'ro',r.ro_amount,IF(r.payment_ro_paid=1,r.ro_amount,0),r.payment_ro_date,",
        "       IF(r.payment_ro_paid=1,'virement',NULL),",
        "       IF(r.ro_amount<=0,'non_applicable',IF(r.payment_ro_paid=1,'encaisse','attendu')),@oopticien_import_user",
        "FROM tmp_suivi_rows_20260804 r JOIN dossiers d ON d.source_fingerprint=r.source_fingerprint",
        "WHERE NOT EXISTS (SELECT 1 FROM payments p WHERE p.dossier_id=d.id AND p.payer='ro');",
        "",
        "INSERT INTO payments (dossier_id,payer,expected_amount,paid_amount,payment_date,payment_method,status,created_by)",
        "SELECT d.id,'rc',r.rc_amount,IF(r.payment_rc_paid=1,r.rc_amount,0),r.payment_rc_date,",
        "       IF(r.payment_rc_paid=1,'virement',NULL),",
        "       IF(r.rc_amount<=0,'non_applicable',IF(r.payment_rc_paid=1,'encaisse','attendu')),@oopticien_import_user",
        "FROM tmp_suivi_rows_20260804 r JOIN dossiers d ON d.source_fingerprint=r.source_fingerprint",
        "WHERE NOT EXISTS (SELECT 1 FROM payments p WHERE p.dossier_id=d.id AND p.payer='rc');",
        "",
        "INSERT INTO payments (dossier_id,payer,expected_amount,paid_amount,payment_date,payment_method,status,created_by)",
        "SELECT d.id,'client',r.rac_amount,IF(r.payment_rac_paid=1,r.rac_amount,0),r.payment_rac_date,r.payment_rac_method,",
        "       IF(r.rac_amount<=0,'non_applicable',IF(r.payment_rac_caution=1,'cheque_caution',IF(r.payment_rac_paid=1,'encaisse','attendu'))),",
        "       @oopticien_import_user",
        "FROM tmp_suivi_rows_20260804 r JOIN dossiers d ON d.source_fingerprint=r.source_fingerprint",
        "WHERE NOT EXISTS (SELECT 1 FROM payments p WHERE p.dossier_id=d.id AND p.payer='client');",
        "",
        "-- Demandes mutuelles actives seulement, pour ne pas encombrer la page avec les dossiers clôturés.",
        "INSERT INTO mutual_requests (",
        "  dossier_id,status,requested_amount,ro_amount,rc_amount,rac_amount,approved_amount,accepted_amount,",
        "  sent_at,response_due_at,response_received_at,response_at,notes,created_by",
        ")",
        "SELECT d.id,",
        "       CASE r.mutual_status",
        "         WHEN 'pec_acceptee' THEN 'acceptee' WHEN 'pec_refusee' THEN 'refusee'",
        "         WHEN 'incomplete' THEN 'a_completer' WHEN 'envoyee' THEN 'envoyee' ELSE 'en_attente' END,",
        "       r.total_amount,r.ro_amount,r.rc_amount,r.rac_amount,",
        "       IF(r.mutual_status='pec_acceptee',r.total_amount,NULL),",
        "       IF(r.mutual_status='pec_acceptee',r.total_amount,NULL),",
        "       r.pec_sent_at,IF(r.pec_sent_at IS NULL,NULL,DATE_ADD(r.pec_sent_at,INTERVAL 48 HOUR)),",
        "       r.pec_response_at,r.pec_response_at,",
        "       CONCAT('Import PDF page ',r.source_page,', ligne ',r.source_row),@oopticien_import_user",
        "FROM tmp_suivi_rows_20260804 r",
        "JOIN dossiers d ON d.source_fingerprint=r.source_fingerprint",
        "WHERE r.folder_status NOT IN ('cloture','annule')",
        "  AND r.mutual_status<>'non_envoyee'",
        "  AND NOT EXISTS (SELECT 1 FROM mutual_requests mr WHERE mr.dossier_id=d.id);",
        "",
        "-- Une tâche visible dans l’assistant pour chaque dossier vert à facturer.",
        "INSERT INTO tasks (dossier_id,client_id,title,description,task_type,due_at,priority,status)",
        "SELECT d.id,d.client_id,'Facturer le dossier','PEC acceptée dans la fiche de suivi','facturation',NOW(),'haute','a_faire'",
        "FROM dossiers d",
        "WHERE d.source_origin=" + sql_literal(SOURCE_ORIGIN) + " AND d.folder_status='a_facturer'",
        "  AND NOT EXISTS (",
        "    SELECT 1 FROM tasks t WHERE t.dossier_id=d.id AND t.task_type='facturation'",
        "      AND t.status IN ('a_faire','en_cours','reportee')",
        "  );",
        "",
        "INSERT INTO imports (file_name,import_type,total_rows,success_rows,error_rows,report,created_by)",
        "SELECT " + sql_literal(SOURCE_FILE_LABEL) + ",'manuel'," + str(report["rows"]) + "," + str(report["rows"]) + ",0,",
        "       " + sql_literal("Import PDF contrôlé : couleurs et années appliquées; 2024/2025 clôturés.") + ",@oopticien_import_user",
        "WHERE NOT EXISTS (",
        "  SELECT 1 FROM imports WHERE file_name=" + sql_literal(SOURCE_FILE_LABEL) + " AND total_rows=" + str(report["rows"]),
        ");",
        "",
        "INSERT INTO action_history (user_id,entity_type,entity_id,action,details,ip_address)",
        "SELECT @oopticien_import_user,'database',NULL,'import_pdf',",
        "       " + sql_literal(f"Import de la nouvelle fiche de suivi : {report['rows']} dossiers et {report['unique_clients']} clients uniques.") + ",'phpMyAdmin'",
        "WHERE NOT EXISTS (",
        "  SELECT 1 FROM action_history WHERE action='import_pdf' AND details=" + sql_literal(f"Import de la nouvelle fiche de suivi : {report['rows']} dossiers et {report['unique_clients']} clients uniques.") ,
        ");",
        "",
        "COMMIT;",
        "",
        "-- Contrôles affichés à la fin dans phpMyAdmin.",
        "SELECT COUNT(*) AS dossiers_importes FROM dossiers WHERE source_origin=" + sql_literal(SOURCE_ORIGIN) + ";",
        "SELECT folder_status,COUNT(*) AS nombre FROM dossiers WHERE source_origin=" + sql_literal(SOURCE_ORIGIN) + " GROUP BY folder_status ORDER BY nombre DESC;",
        "SELECT dossier_type,COUNT(*) AS nombre FROM dossiers WHERE source_origin=" + sql_literal(SOURCE_ORIGIN) + " GROUP BY dossier_type;",
        "SELECT COUNT(*) AS paiements_importes FROM payments p JOIN dossiers d ON d.id=p.dossier_id WHERE d.source_origin=" + sql_literal(SOURCE_ORIGIN) + ";",
        "",
        "DROP TEMPORARY TABLE IF EXISTS tmp_suivi_client_map_20260804;",
        "DROP TEMPORARY TABLE IF EXISTS tmp_suivi_rows_20260804;",
        "DROP TEMPORARY TABLE IF EXISTS tmp_suivi_clients_20260804;",
        "",
    ])
    return "\n".join(lines)


def main() -> None:
    if not PDF_PATH.is_file():
        raise FileNotFoundError(PDF_PATH)
    OUTPUT_DIR.mkdir(parents=True, exist_ok=True)
    rows, report = parse_pdf()
    if len(rows) < 2200:
        raise RuntimeError(f"Nombre de dossiers anormalement faible : {len(rows)}")
    SQL_PATH.write_text(build_sql(rows, report), encoding="utf-8-sig", newline="\n")
    REPORT_PATH.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8", newline="\n")
    print(json.dumps({"sql": str(SQL_PATH), "report": str(REPORT_PATH), **report}, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
