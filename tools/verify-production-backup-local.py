"""Restore one pinned backup into a private, disposable MariaDB named-pipe instance.

Never reads .env, starts Laravel, contacts Alwaysdata, or runs migrations.
Native output and SQL values are captured privately and never printed.
"""
import collections
import hashlib
import json
import os
from pathlib import Path
import re
import secrets
import shutil
import subprocess
import time
import traceback

EXPECTED_SHA = "585836fcaeb71221dd873b453b44a0124e7a7d8ad96a257f0112365c59675d77"
EXPECTED_BYTES = 396514
DB = "fabyjulien_simpledevis"
BIN = Path("C:/xampp/mysql/bin")
ROOT = Path(os.environ["LOCALAPPDATA"]) / "SimpleDevis/backups/schema-20261111-091000"
BACKUP = ROOT / "before-invoice-emission-20261111-081001-31ce1043.sql"


class Refused(Exception):
    pass


def check(condition, reason):
    if not condition:
        raise Refused(reason)


def statements(text):
    """Split native SQL dump outside strings/comments, preserving literal bytes."""
    buf, pos, quote = [], 0, None
    while pos < len(text):
        char = text[pos]
        if quote:
            buf.append(char)
            if char == "\\" and quote != "`" and pos + 1 < len(text):
                pos += 1
                buf.append(text[pos])
            elif char == quote:
                if pos + 1 < len(text) and text[pos + 1] == quote:
                    pos += 1
                    buf.append(text[pos])
                else:
                    quote = None
        elif char in "'\"`":
            quote = char
            buf.append(char)
        elif text.startswith("/*", pos):
            end = text.find("*/", pos + 2)
            check(end >= 0, "unterminated_comment")
            pos = end + 1
            buf.append(" ")
        elif char == "#" or (text.startswith("--", pos) and (pos + 2 == len(text) or text[pos + 2].isspace())):
            end = text.find("\n", pos)
            pos = len(text) if end < 0 else end
            buf.append(" ")
        elif char == ";":
            value = "".join(buf).strip()
            if value:
                yield value
            buf = []
        else:
            buf.append(char)
        pos += 1
    check(quote is None and not "".join(buf).strip(), "unterminated_statement")


def row_digest(values):
    digest = hashlib.sha256()
    for value in values:
        if value is None:
            digest.update(b"N;")
        else:
            digest.update(b"V" + str(len(value)).encode("ascii") + b":" + value + b";")
    return digest.hexdigest()


def literal_rows(text):
    """Decode mysqldump VALUES exactly; unsupported syntax fails closed."""
    pos, length = 0, len(text)
    escapes = {"0": b"\0", "n": b"\n", "r": b"\r", "t": b"\t", "b": b"\b", "Z": b"\x1a"}
    while pos < length:
        while pos < length and (text[pos].isspace() or text[pos] == ","):
            pos += 1
        check(pos < length and text[pos] == "(", "invalid_values_tuple")
        pos += 1
        values = []
        while True:
            while pos < length and text[pos].isspace():
                pos += 1
            check(pos < length, "truncated_value")
            if text[pos] == "'":
                pos += 1
                value = bytearray()
                while pos < length:
                    char = text[pos]
                    pos += 1
                    if char == "\\":
                        check(pos < length, "truncated_escape")
                        escaped = text[pos]
                        pos += 1
                        value.extend(escapes.get(escaped, escaped.encode("latin1")))
                    elif char == "'":
                        if pos < length and text[pos] == "'":
                            value.extend(b"'")
                            pos += 1
                        else:
                            break
                    else:
                        value.extend(char.encode("latin1"))
                else:
                    raise Refused("unterminated_literal")
                values.append(bytes(value))
            else:
                start = pos
                while pos < length and text[pos] not in ",)":
                    pos += 1
                token = text[start:pos].strip()
                if token.upper() == "NULL":
                    values.append(None)
                elif re.fullmatch(r"0x[0-9A-Fa-f]*", token):
                    values.append(bytes.fromhex(token[2:]))
                elif re.fullmatch(r"-?\d+(?:\.\d+)?", token):
                    values.append(token.encode("ascii"))
                else:
                    raise Refused("unsupported_dump_literal")
            while pos < length and text[pos].isspace():
                pos += 1
            check(pos < length and text[pos] in ",)", "invalid_values_separator")
            delimiter = text[pos]
            pos += 1
            if delimiter == ")":
                yield values
                break


def native(args, *, input_data=None, input_file=None, timeout=60):
    with open(input_file, "rb") if input_file else open(os.devnull, "rb") as source:
        result = subprocess.run(args, input=input_data, stdin=source if input_data is None else None,
                                stdout=subprocess.PIPE, stderr=subprocess.PIPE, timeout=timeout,
                                creationflags=subprocess.CREATE_NO_WINDOW)
    check(result.returncode == 0, "native_command_failed")
    check(not result.stderr.strip(), "native_command_warning")
    return result.stdout


def canonical_ddl(value):
    return re.sub(r"\s+", " ", value.strip().rstrip(";")).strip()


def verify():
    check(ROOT.resolve().is_relative_to(Path(os.environ["LOCALAPPDATA"]).resolve()), "private_path_refused")
    raw = BACKUP.read_bytes()
    check(len(raw) == EXPECTED_BYTES and hashlib.sha256(raw).hexdigest() == EXPECTED_SHA, "backup_hash_mismatch")
    check(b"-- Dump completed on " in raw and b"USE `fabyjulien_simpledevis`;" in raw, "backup_incomplete")
    tables, expected = {}, {}
    for statement in statements(raw.decode("latin1")):
        create = re.match(r"CREATE TABLE `([A-Za-z0-9_]+)`\s*\(", statement)
        insert = re.fullmatch(r"INSERT INTO `([A-Za-z0-9_]+)` VALUES\s*(.*)", statement, re.S)
        if create:
            name = create.group(1)
            check(name not in tables, "duplicate_table_definition")
            tables[name] = statement
            expected[name] = collections.Counter()
        elif insert:
            name = insert.group(1)
            check(name in tables, "insert_without_definition")
            for values in literal_rows(insert.group(2)):
                expected[name][row_digest(values)] += 1
        elif re.search(r"\b(?:PROCEDURE|FUNCTION|TRIGGER|EVENT|VIEW)\b", statement, re.I) and not statement.startswith("SET "):
            raise Refused("unsupported_additional_object")
    check(len(tables) == 29, "unexpected_table_count")

    scratch = ROOT / ("restore-check-" + secrets.token_hex(10))
    scratch.mkdir()
    datadir = scratch / "data"
    pipe = "sd_restore_" + secrets.token_hex(12)
    password = secrets.token_hex(32)
    initfile = scratch / "initial-auth.sql"
    options = scratch / "local-client.cnf"
    initfile.write_text("ALTER USER 'root'@'localhost' IDENTIFIED BY '" + password + "';\n", encoding="ascii")
    options.write_text("[client]\nuser=root\npassword=" + password + "\nprotocol=PIPE\nsocket=" + pipe + "\n", encoding="ascii")
    client = [str(BIN / "mysql.exe"), "--defaults-file=" + str(options), "--protocol=PIPE", "--socket=" + pipe,
              "--batch", "--raw", "--skip-column-names", "--binary-mode", "--default-character-set=utf8mb4"]
    server = None
    log = None
    try:
        native([str(BIN / "mysql_install_db.exe"), "--datadir=" + str(datadir), "--skip-networking", "--socket=" + pipe, "--silent"])
        log = (scratch / "server-output.log").open("wb")
        server = subprocess.Popen([str(BIN / "mysqld.exe"), "--no-defaults", "--basedir=" + str(BIN.parent),
            "--datadir=" + str(datadir), "--skip-networking", "--enable-named-pipe", "--socket=" + pipe,
            "--event-scheduler=OFF", "--skip-log-bin", "--skip-name-resolve", "--default-time-zone=+00:00", "--innodb-buffer-pool-size=32M",
            "--init-file=" + str(initfile), "--log-error=" + str(scratch / "server-error.log"),
            "--pid-file=" + str(scratch / "server.pid")], stdin=subprocess.DEVNULL,
            stdout=log, stderr=log, creationflags=subprocess.CREATE_NO_WINDOW)
        ready = False
        for _ in range(40):
            check(server.poll() is None, "isolated_server_start_failed")
            try:
                identity = native(client + ["--execute=SELECT @@skip_networking,@@socket,@@datadir,@@event_scheduler,@@version"], timeout=3).decode("utf8").strip().split("\t")
                check(len(identity) == 5 and identity[0] == "1" and identity[1] == pipe
                      and Path(identity[2]).resolve() == datadir.resolve() and identity[3] == "OFF", "isolated_server_identity_refused")
                ready = True
                break
            except Refused:
                time.sleep(0.25)
        check(ready, "isolated_server_unavailable")
        native(client, input_file=BACKUP, timeout=90)
        query = lambda sql: native(client + ["--execute=" + sql]).decode("utf8").rstrip("\r\n")
        restored = query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA='" + DB + "' AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME").splitlines()
        check(set(restored) == set(tables), "restored_tables_mismatch")
        total = 0
        for name in restored:
            ddl = query("SHOW CREATE TABLE `" + DB + "`.`" + name + "`").split("\t", 1)[1]
            check(canonical_ddl(ddl) == canonical_ddl(tables[name].encode("latin1").decode("utf8")), "restored_structure_mismatch")
            columns = query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='" + DB + "' AND TABLE_NAME='" + name + "' ORDER BY ORDINAL_POSITION").splitlines()
            check(all(re.fullmatch(r"[A-Za-z0-9_]+", col) for col in columns), "unsafe_column_name")
            # HEX(integer) encodes its number; cast first to compare stored textual bytes.
            expression = ",".join("IF(`" + col + "` IS NULL,'N',CONCAT('V',HEX(CAST(`" + col + "` AS BINARY))))" for col in columns)
            rows = query("SELECT CONCAT_WS('|'," + expression + ") FROM `" + DB + "`.`" + name + "`")
            actual = collections.Counter()
            for row in rows.splitlines() if rows else []:
                values = [None if token == "N" else bytes.fromhex(token[1:]) for token in row.split("|")]
                actual[row_digest(values)] += 1
            check(actual == expected[name], "restored_data_mismatch")
            total += sum(actual.values())
        report = {"status": "restore_verified", "backup_sha256_confirmed": True, "backup_bytes": len(raw),
                  "backup_sha256": EXPECTED_SHA, "backup_name": BACKUP.name,
                  "tables_verified": len(tables), "structures_verified": len(tables), "rows_verified": total,
                  "row_comparison": "all_columns_exact_bytes_and_nulls_with_duplicate_counts",
                  "local_mariadb_version": identity[4], "tcp_disabled": True, "events_disabled": True,
                  "alwaysdata_connections": 0, "migrations_executed": 0}
        (ROOT / "restore-verification.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf8")
    finally:
        if server is not None and server.poll() is None:
            try:
                native([str(BIN / "mysqladmin.exe"), "--defaults-file=" + str(options), "--protocol=PIPE", "--socket=" + pipe, "shutdown"], timeout=10)
                server.wait(timeout=10)
            except Exception:
                server.terminate()
                try:
                    server.wait(timeout=10)
                except subprocess.TimeoutExpired:
                    server.kill()
                    server.wait(timeout=10)
        if log is not None:
            log.close()
        # Only the unique directory created by this invocation can be removed.
        check(scratch.resolve().parent == ROOT.resolve() and re.fullmatch(r"restore-check-[a-f0-9]{20}", scratch.name)
              and not BACKUP.resolve().is_relative_to(scratch.resolve()), "temporary_cleanup_path_refused")
        shutil.rmtree(scratch)
    check(hashlib.sha256(BACKUP.read_bytes()).hexdigest() == EXPECTED_SHA, "original_backup_changed")
    report["temporary_server_stopped"] = True
    report["temporary_data_removed"] = True
    (ROOT / "restore-verification.json").write_text(json.dumps(report, indent=2) + "\n", encoding="utf8")
    return report


if __name__ == "__main__":
    try:
        print(json.dumps(verify(), indent=2))
    except Exception as error:
        reason = str(error) if isinstance(error, Refused) else type(error).__name__
        frames = [{"function": frame.name, "line": frame.lineno} for frame in traceback.extract_tb(error.__traceback__)]
        print(json.dumps({"status": "stopped", "reason": reason, "location": frames,
                          "alwaysdata_connections": 0, "migrations_executed": 0}))
        raise SystemExit(1)
