import hashlib
import json
import os
import time
import sys
from dataclasses import dataclass
from datetime import date, datetime
from decimal import Decimal
from typing import Any, Dict, Iterable, List, Optional, Tuple

try:
    import pyodbc
except Exception:
    pyodbc = None
import requests
from requests import Response

try:
    from config import CONFIG
except Exception:
    from client.config import CONFIG

try:
    sys.stdout.reconfigure(line_buffering=True)
except Exception:
    pass

@dataclass(frozen=True)
class TableConfig:
    source: str
    target: str
    primary_key: str


def _now_iso() -> str:
    return datetime.utcnow().replace(microsecond=0).isoformat() + "Z"


def _json_safe(value: Any) -> Any:
    if value is None:
        return None
    if isinstance(value, (str, int, float, bool)):
        return value
    if isinstance(value, (datetime, date)):
        return value.isoformat()
    if isinstance(value, Decimal):
        return str(value)
    if isinstance(value, bytes):
        return value.hex()
    return str(value)


def _stable_row_hash(row: Dict[str, Any]) -> str:
    normalized = {k: _json_safe(v) for k, v in row.items()}
    encoded = json.dumps(normalized, sort_keys=True, ensure_ascii=False, separators=(",", ":")).encode("utf-8")
    return hashlib.sha256(encoded).hexdigest()


def _load_state(state_path: str) -> Dict[str, Any]:
    if not os.path.exists(state_path):
        return {"tables": {}}
    with open(state_path, "r", encoding="utf-8") as f:
        data = json.load(f)
    if not isinstance(data, dict):
        return {"tables": {}}
    if "tables" not in data or not isinstance(data["tables"], dict):
        data["tables"] = {}
    return data


def _save_state(state_path: str, state: Dict[str, Any]) -> None:
    tmp_path = state_path + ".tmp"
    with open(tmp_path, "w", encoding="utf-8") as f:
        json.dump(state, f, ensure_ascii=False, indent=2, sort_keys=True)
    os.replace(tmp_path, state_path)


def _build_access_conn_str(db_path: str, password: str) -> List[str]:
    driver = "{Microsoft Access Driver (*.mdb, *.accdb)}"
    return [
        f"DRIVER={driver};DBQ={db_path};PWD={password};",
        f"DRIVER={driver};DBQ={db_path};Jet OLEDB:Database Password={password};",
    ]


def connect_access(db_path: str, password: str) -> Any:
    last_err: Optional[Exception] = None
    for conn_str in _build_access_conn_str(db_path=db_path, password=password):
        try:
            if pyodbc is None:
                raise RuntimeError("pyodbc tidak tersedia. Install driver ODBC dan library pyodbc untuk akses MDB.")
            return pyodbc.connect(conn_str, autocommit=True)
        except Exception as e:
            last_err = e
    raise RuntimeError(
        "Gagal konek ke Access. Pastikan driver 'Microsoft Access Driver (*.mdb, *.accdb)' terpasang "
        "dan path DB benar."
    ) from last_err


def _fetch_table_rows(conn: Any, table_name: str) -> Tuple[List[str], List[Dict[str, Any]]]:
    cursor = conn.cursor()
    cursor.execute(f"SELECT * FROM [{table_name}]")
    columns = [col[0] for col in cursor.description]
    rows: List[Dict[str, Any]] = []
    for r in cursor.fetchall():
        row = dict(zip(columns, r))
        rows.append(row)
    return columns, rows


def _chunked(items: List[Dict[str, Any]], chunk_size: int) -> Iterable[List[Dict[str, Any]]]:
    for i in range(0, len(items), chunk_size):
        yield items[i : i + chunk_size]


def _post_rows(
    *,
    api_url: str,
    api_key: str,
    branch_id: int,
    branch_session: str,
    table: str,
    primary_key: str,
    rows: List[Dict[str, Any]],
    timeout_seconds: int = 45,
) -> Dict[str, Any]:
    payload = {
        "branch_id": branch_id,
        "branch_session": branch_session,
        "sent_at": _now_iso(),
        "table": table,
        "primary_key": primary_key,
        "rows": [{k: _json_safe(v) for k, v in row.items()} for row in rows],
    }
    started = time.time()
    try:
        resp: Response = requests.post(
            api_url,
            json=payload,
            headers={"X-API-Key": api_key, "Content-Type": "application/json"},
            timeout=timeout_seconds,
        )
    except requests.RequestException as e:
        raise RuntimeError(f"HTTP request failed: {e}") from e

    elapsed_ms = int((time.time() - started) * 1000)
    text = resp.text or ""
    resp_json: Any = None
    json_error: Optional[str] = None
    try:
        resp_json = resp.json()
    except Exception as e:
        json_error = str(e)

    result = {
        "status_code": int(resp.status_code),
        "elapsed_ms": elapsed_ms,
        "json": resp_json,
        "json_error": json_error,
        "text": text[:2000],
    }

    if resp.status_code >= 400:
        raise RuntimeError(f"HTTP {resp.status_code}: {result.get('text')}")

    return result


def _resolve_tables(cfg: Dict[str, Any]) -> List[TableConfig]:
    tables = cfg.get("tables", [])
    resolved: List[TableConfig] = []
    for t in tables:
        resolved.append(
            TableConfig(
                source=str(t["source"]),
                target=str(t.get("target") or t["source"]),
                primary_key=str(t.get("primary_key") or "id"),
            )
        )
    return resolved


def sync_once(cfg: Dict[str, Any]) -> None:
    state_path = str(cfg.get("state_path") or ".\\sync_state.json")
    max_rows_per_request = int(cfg.get("max_rows_per_request") or 500)
    branch_id = int(cfg["branch_id"])
    branch_session = str(cfg["branch_session"])
    api_url = str(cfg["api_url"])
    api_key = str(cfg["api_key"])
    db_path = str(cfg["db_path"])
    db_password = str(cfg.get("db_password") or "eLock0103")

    state = _load_state(state_path)
    tables_state: Dict[str, Dict[str, str]] = state["tables"]
    api_responses: List[Dict[str, Any]] = state.get("api_responses", [])
    if not isinstance(api_responses, list):
        api_responses = []
        state["api_responses"] = api_responses

    conn = connect_access(db_path=db_path, password=db_password)
    try:
        for table_cfg in _resolve_tables(cfg):
            print(f"{_now_iso()} table {table_cfg.source} -> {table_cfg.target} fetch...", flush=True)
            _, rows = _fetch_table_rows(conn, table_cfg.source)
            table_key = table_cfg.target
            if table_key not in tables_state or not isinstance(tables_state[table_key], dict):
                tables_state[table_key] = {}
            known_hashes: Dict[str, str] = tables_state[table_key]

            changed_rows: List[Dict[str, Any]] = []
            for row in rows:
                if table_cfg.primary_key not in row:
                    continue
                pk_val = row[table_cfg.primary_key]
                if pk_val is None:
                    continue
                pk_str = str(pk_val)
                row_hash = _stable_row_hash(row)
                if known_hashes.get(pk_str) != row_hash:
                    changed_rows.append(row)
                    known_hashes[pk_str] = row_hash

            if not changed_rows:
                print(f"{_now_iso()} table {table_cfg.target} no changes ({len(rows)} rows scanned)", flush=True)
                continue
            print(
                f"{_now_iso()} table {table_cfg.target} changes={len(changed_rows)}/{len(rows)} posting...",
                flush=True,
            )

            total_chunks = (len(changed_rows) + max_rows_per_request - 1) // max_rows_per_request
            chunk_idx = 0
            for chunk in _chunked(changed_rows, max_rows_per_request):
                chunk_idx += 1
                print(
                    f"{_now_iso()} POST {table_cfg.target} chunk {chunk_idx}/{total_chunks} rows={len(chunk)} ...",
                    flush=True,
                )
                resp_info: Dict[str, Any] = {}
                err: Optional[str] = None
                try:
                    resp_info = _post_rows(
                        api_url=api_url,
                        api_key=api_key,
                        branch_id=branch_id,
                        branch_session=branch_session,
                        table=table_cfg.target,
                        primary_key=table_cfg.primary_key,
                        rows=chunk,
                    )
                except Exception as e:
                    err = str(e)
                    raise
                finally:
                    entry = {
                        "at": _now_iso(),
                        "table": table_cfg.target,
                        "primary_key": table_cfg.primary_key,
                        "rows_sent": len(chunk),
                        "error": err,
                        "response": resp_info,
                    }
                    api_responses.append(entry)
                    if len(api_responses) > 100:
                        del api_responses[:-100]
                    state["api_responses"] = api_responses
                    _save_state(state_path, state)

                processed = None
                if isinstance(resp_info.get("json"), dict):
                    processed = resp_info["json"].get("processed")
                print(
                    f"{_now_iso()} OK {table_cfg.target} chunk {chunk_idx}/{total_chunks} "
                    f"status={resp_info.get('status_code')} processed={processed} "
                    f"ms={resp_info.get('elapsed_ms')}",
                    flush=True,
                )
            print(f"{_now_iso()} synced {len(changed_rows)} rows -> {table_cfg.target}", flush=True)
    finally:
        try:
            conn.close()
        except Exception:
            pass

    _save_state(state_path, state)


def main() -> None:
    interval_seconds = int(CONFIG.get("interval_seconds") or 300)
    while True:
        started = time.time()
        try:
            sync_once(CONFIG)
        except Exception as e:
            print(f"{_now_iso()} error: {e}", flush=True)
        elapsed = time.time() - started
        sleep_for = max(1, interval_seconds - int(elapsed))
        print(f"{_now_iso()} sleep {sleep_for}s", flush=True)
        time.sleep(sleep_for)


def test_send() -> None:
    payload_rows = [{"emp_id": "TEST-CLIENT-001", "emp_name": "CLIENT TEST"}]
    print(f"{_now_iso()} test_send start url={CONFIG['api_url']}", flush=True)
    try:
        info = _post_rows(
            api_url=str(CONFIG["api_url"]),
            api_key=str(CONFIG["api_key"]),
            branch_id=int(CONFIG["branch_id"]),
            branch_session=str(CONFIG["branch_session"]),
            table="_employees",
            primary_key="emp_id",
            rows=payload_rows,
        )
        print(
            f"{_now_iso()} status={info.get('status_code')} processed={(info.get('json') or {}).get('processed')} ms={info.get('elapsed_ms')}",
            flush=True,
        )
    except Exception as e:
        print(f"{_now_iso()} error: {e}", flush=True)


if __name__ == "__main__":
    import argparse

    p = argparse.ArgumentParser()
    p.add_argument("--test", action="store_true", help="Kirim payload contoh tanpa membaca MDB")
    args = p.parse_args()
    if args.test:
        test_send()
    else:
        main()
