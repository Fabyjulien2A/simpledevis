"""Read-only comparison with the pinned, successfully restored production backup.

No Laravel boot, migration, maintenance command, business write or token access.
Remote result data remain in process memory; only aggregate diagnostics are printed.
"""
import collections
import hashlib
import importlib.util
import json
from pathlib import Path
import re
import subprocess

ROOT = Path(__file__).resolve().parent.parent
spec = importlib.util.spec_from_file_location("backup_verifier", ROOT / "tools/verify-production-backup-local.py")
verifier = importlib.util.module_from_spec(spec)
spec.loader.exec_module(verifier)

PHP = r'''<?php
ini_set('display_errors','0'); ini_set('log_errors','0'); ini_set('zend.exception_ignore_args','1');
set_error_handler(static function () { throw new RuntimeException('read_failed'); });
try {
 require getcwd().'/vendor/autoload.php';
 $s=Dotenv\Dotenv::parse(file_get_contents(getcwd().'/.env'));
 if (($s['DB_DATABASE'] ?? '')!=='fabyjulien_simpledevis' || ($s['DB_CONNECTION'] ?? '')!=='mysql'
  || !empty($s['DB_URL']) || !empty($s['DB_SOCKET']) || !preg_match('/\A[A-Za-z0-9.-]+\z/D',$s['DB_HOST'] ?? '')) {
  throw new RuntimeException('scope_refused');
 }
 $port=filter_var($s['DB_PORT'] ?? '3306',FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>65535]]);
 if (!$port) { throw new RuntimeException('scope_refused'); }
 $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,
  PDO::ATTR_STRINGIFY_FETCHES=>true,PDO::ATTR_TIMEOUT=>10];
 if (!empty($s['MYSQL_ATTR_SSL_CA'])) { $options[PDO::MYSQL_ATTR_SSL_CA]=$s['MYSQL_ATTR_SSL_CA']; }
 $pdo=new PDO('mysql:host='.$s['DB_HOST'].';port='.$port.';dbname=fabyjulien_simpledevis;charset=utf8mb4',$s['DB_USERNAME'],$s['DB_PASSWORD'],$options);
 if ($pdo->query('SELECT DATABASE()')->fetchColumn()!=='fabyjulien_simpledevis') { throw new RuntimeException('scope_refused'); }
 $pdo->exec("SET SESSION time_zone = '+00:00'");
 $pdo->exec('START TRANSACTION READ ONLY');
 $names=$pdo->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME")->fetchAll(PDO::FETCH_COLUMN);
 $out=['database_confirmed'=>true,'tables'=>[]];
 foreach ($names as $name) {
  if (!preg_match('/\A[A-Za-z0-9_]+\z/D',$name)) { throw new RuntimeException('scope_refused'); }
  $ddl=$pdo->query('SHOW CREATE TABLE `'.$name.'`')->fetch(PDO::FETCH_NUM)[1];
  $rows=[];
  $q=$pdo->query('SELECT * FROM `'.$name.'`');
  while ($row=$q->fetch(PDO::FETCH_NUM)) {
   $ctx=hash_init('sha256');
   foreach ($row as $value) { hash_update($ctx,$value===null ? 'N;' : 'V'.strlen($value).':'.$value.';'); }
   $hash=hash_final($ctx); $rows[$hash]=($rows[$hash] ?? 0)+1;
  }
  $out['tables'][$name]=['ddl'=>$ddl,'rows'=>(object)$rows];
 }
 $out['migrations']=$pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
 echo json_encode($out,JSON_THROW_ON_ERROR);
} catch(Throwable $e) { echo '{"read_only_inspection_failed":true}'; exit(1); }
'''


def main():
    data = verifier.BACKUP.read_bytes()
    verifier.check(hashlib.sha256(data).hexdigest() == verifier.EXPECTED_SHA, "backup_hash_mismatch")
    manifest = json.loads((verifier.ROOT / "restore-verification.json").read_text(encoding="utf8"))
    verifier.check(manifest.get("status") == "restore_verified" and manifest.get("tables_verified") == 29
                   and manifest.get("rows_verified") == 476 and manifest.get("backup_sha256_confirmed") is True,
                   "restoration_evidence_missing")
    expected, definitions = {}, {}
    for statement in verifier.statements(data.decode("latin1")):
        create = re.match(r"CREATE TABLE `([A-Za-z0-9_]+)`\s*\(", statement)
        insert = re.fullmatch(r"INSERT INTO `([A-Za-z0-9_]+)` VALUES\s*(.*)", statement, re.S)
        if create:
            name = create.group(1)
            definitions[name] = verifier.canonical_ddl(statement.encode("latin1").decode("utf8"))
            expected[name] = collections.Counter()
        elif insert:
            for row in verifier.literal_rows(insert.group(2)):
                expected[insert.group(1)][verifier.row_digest(row)] += 1
    verifier.check(len(expected) == 29, "backup_schema_incomplete")
    result = subprocess.run(["C:/xampp/php/php.exe", "-d", "allow_url_fopen=0", "-d",
        "disable_functions=curl_exec,curl_multi_exec,fsockopen,pfsockopen,stream_socket_client,socket_connect,mail"],
        input=PHP.encode("ascii"), cwd=ROOT, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
        timeout=60, creationflags=subprocess.CREATE_NO_WINDOW)
    verifier.check(result.returncode == 0 and not result.stderr, "read_only_database_inspection_failed")
    current = json.loads(result.stdout)
    verifier.check(current.get("database_confirmed") is True, "effective_database_refused")
    actual = current["tables"]
    changed_structures = sorted(name for name in set(expected) | set(actual)
        if name not in expected or name not in actual or verifier.canonical_ddl(actual[name]["ddl"]) != definitions[name])
    changed_data = sorted(name for name in set(expected) | set(actual)
        if name not in expected or name not in actual or collections.Counter(actual[name]["rows"]) != expected[name])
    local = {path.stem for path in (ROOT / "database/migrations").glob("*.php")}
    target = "2026_11_09_000001_add_invoice_emission_preparation_fields"
    present = {}
    for table, columns in {"companies": ["country_code", "billing_electronic_address"],
                           "clients": ["billing_electronic_address"],
                           "invoices": ["emission_profile", "emission_context"]}.items():
        for column in columns:
            present[table + "." + column] = bool(re.search(r"^\s*`" + column + r"`\s", actual[table]["ddl"], re.M))
    return {"database_confirmed": True, "backup_available_and_hash_confirmed": True,
            "verified_restoration_evidence_available": True, "table_count": len(actual),
            "current_rows": sum(sum(table["rows"].values()) for table in actual.values()),
            "changed_structures_since_backup": changed_structures, "changed_data_since_backup": changed_data,
            "migration_recorded": target in current["migrations"], "target_columns_present": present,
            "pending_migrations": sorted(local - set(current["migrations"])),
            "production_writes": 0, "migration_applied": False}


if __name__ == "__main__":
    try:
        print(json.dumps(main(), indent=2))
    except Exception as error:
        reason = str(error) if isinstance(error, verifier.Refused) else type(error).__name__
        print(json.dumps({"status": "stopped", "reason": reason, "production_writes": 0, "migration_applied": False}))
        raise SystemExit(1)
