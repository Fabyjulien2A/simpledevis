"""Compare live read-only SQL state to a restored backup; save private row hashes."""
import collections
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess
import sys


def load_module(name, path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return module


def main():
    project = Path(__file__).resolve().parent.parent
    comparison = load_module("readonly_comparison", project / "tools/compare-production-to-verified-backup.py")
    verifier = comparison.verifier
    verifier.check(len(sys.argv) == 2, "arguments_refused")
    directory = Path(sys.argv[1]).resolve()
    verifier.check(directory.is_relative_to(Path(os.environ["LOCALAPPDATA"]).resolve()), "private_path_refused")
    proof = json.loads((directory / "restore-verification.json").read_text(encoding="utf8"))
    backup = (directory / proof["backup_name"]).resolve()
    verifier.check(backup.parent == directory and proof["status"] == "restore_verified"
                   and proof["tables_verified"] == 29 and proof["temporary_server_stopped"] is True,
                   "restoration_proof_refused")
    data = backup.read_bytes()
    verifier.check(hashlib.sha256(data).hexdigest() == proof["backup_sha256"], "backup_hash_mismatch")
    expected, definitions = {}, {}
    for statement in verifier.statements(data.decode("latin1")):
        create = re.match(r"CREATE TABLE `([A-Za-z0-9_]+)`\s*\(", statement)
        insert = re.fullmatch(r"INSERT INTO `([A-Za-z0-9_]+)` VALUES\s*(.*)", statement, re.S)
        if create:
            name = create.group(1)
            definitions[name] = statement.encode("latin1").decode("utf8")
            expected[name] = collections.Counter()
        elif insert:
            for row in verifier.literal_rows(insert.group(2)):
                expected[insert.group(1)][verifier.row_digest(row)] += 1
    result = subprocess.run(["C:/xampp/php/php.exe", "-d", "allow_url_fopen=0", "-d",
        "disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,mail"],
        input=comparison.PHP.encode("ascii"), cwd=project, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        timeout=60, creationflags=subprocess.CREATE_NO_WINDOW)
    verifier.check(result.returncode == 0 and not result.stderr, "read_only_comparison_failed")
    actual = json.loads(result.stdout)
    verifier.check(actual.get("database_confirmed") is True and set(actual["tables"]) == set(expected), "table_inventory_mismatch")
    baseline = {"backup_sha256": proof["backup_sha256"], "tables": {}}
    for name, saved in actual["tables"].items():
        verifier.check(verifier.canonical_ddl(saved["ddl"]) == verifier.canonical_ddl(definitions[name]), "schema_changed_since_backup")
        verifier.check(collections.Counter(saved["rows"]) == expected[name], "data_changed_since_backup")
        columns = re.findall(r"^\s*`([A-Za-z0-9_]+)`\s", definitions[name], re.M)
        verifier.check(bool(columns), "column_inventory_invalid")
        baseline["tables"][name] = {"columns": columns, "rows": saved["rows"]}
    migration = project / "database/migrations/2026_11_09_000001_add_invoice_emission_preparation_fields.php"
    verifier.check(migration.stem not in actual["migrations"], "migration_already_recorded")
    baseline["migration_sha256"] = hashlib.sha256(migration.read_bytes()).hexdigest()
    target = directory / "pre-migration-baseline.json"
    target.write_text(json.dumps(baseline, indent=2) + "\n", encoding="utf8")
    return {"status": "baseline_verified", "tables": len(expected), "rows": sum(sum(rows.values()) for rows in expected.values()),
            "schema_and_data_match_restored_backup": True, "baseline_path": str(target), "production_writes": 0}


if __name__ == "__main__":
    try:
        print(json.dumps(main(), indent=2))
    except Exception:
        print(json.dumps({"status": "stopped", "reason": "baseline_preparation_failed", "production_writes": 0}))
        raise SystemExit(1)
