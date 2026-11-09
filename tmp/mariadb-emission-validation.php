<?php
// Temporary validation runner. No application environment, credentials or real transport are reused.
declare(strict_types=1);

chdir(dirname(__DIR__));
require getcwd().'/vendor/autoload.php';

use App\Models\ElectronicInvoice;
use App\Models\ElectronicInvoiceAttempt;
use App\Models\Invoice;
use App\Models\User;
use App\Services\InvoicePartySnapshotCapture;
use App\Services\SuperPdp\Emission\EmissionDocument;
use App\Services\SuperPdp\Emission\EmissionPrerequisites;
use App\Services\SuperPdp\Emission\EmissionTransport;
use App\Services\SuperPdp\Emission\OutgoingEmission;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\Support\CapturedSandboxCii;

const TEST_DB = 'fabyjulien_simpledevis_test';
const FIRST_MIGRATION = '2026_11_09_000001_add_invoice_emission_preparation_fields';
const SECOND_MIGRATION = '2026_11_09_000002_add_outgoing_emission_orchestration';

function safeOutput(array $value): void { echo json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), PHP_EOL; }
function check(bool $condition, string $label): void { if (!$condition) { throw new RuntimeException('CHECK:'.$label); } }
function envTest(string $key, string $value): void { putenv($key.'='.$value); $_ENV[$key]=$value; $_SERVER[$key]=$value; }
function safeFailure(Throwable $e): array {
    return ['class'=>get_class($e), 'sqlstate'=>(string)$e->getCode(),
        'driver_code'=>$e instanceof PDOException ? ($e->errorInfo[1]??null) : null,
        'sql_template'=>$e instanceof Illuminate\Database\QueryException ? $e->getSql() : null,
        'validation'=>$e instanceof Illuminate\Validation\ValidationException ? $e->errors() : null,
        'check'=>str_starts_with($e->getMessage(),'CHECK:') ? substr($e->getMessage(),6) : null];
}
function bootTest(): array {
    $c=Dotenv\Dotenv::parse(file_get_contents('.env.mariadb-test'));
    foreach (['DB_HOST','DB_DATABASE','DB_USERNAME','DB_PASSWORD'] as $key) { check(isset($c[$key]) && is_string($c[$key]) && $c[$key]!=='' && !str_contains($c[$key],'${'), 'dedicated_config_'.$key); }
    check($c['DB_DATABASE']===TEST_DB && $c['DB_USERNAME']==='fabyjulien_simpledevis_test', 'exact_database_and_dedicated_user');
    check(preg_match('/\A[A-Za-z0-9._-]+\z/D',$c['DB_HOST'])===1 && empty($c['DB_URL']), 'dedicated_host_no_url');
    $options=[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_EMULATE_PREPARES=>false,PDO::ATTR_TIMEOUT=>10];
    if (!empty($c['DB_SSL_CA'])) { $options[PDO::MYSQL_ATTR_SSL_CA]=$c['DB_SSL_CA']; $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT]=true; }
    $pdo=new PDO('mysql:host='.$c['DB_HOST'].';port='.($c['DB_PORT']??3306).';dbname='.TEST_DB.';charset=utf8mb4',$c['DB_USERNAME'],$c['DB_PASSWORD'],$options);
    $identity=$pdo->query('SELECT VERSION() AS version,DATABASE() AS db,CURRENT_USER() AS account,@@tx_isolation AS isolation_level')->fetch(PDO::FETCH_ASSOC);
    check($identity['db']===TEST_DB && $identity['account']==='fabyjulien_simpledevis_test@%' && str_contains($identity['version'],'MariaDB'), 'server_identity');
    $grants=$pdo->query('SHOW GRANTS')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($grants as $grant) {
        check(preg_match('/\AGRANT\s+(.+?)\s+ON\s+(.+?)\s+TO\s/is',$grant,$m)===1,'unresolved_role');
        check((strtoupper(trim($m[1]))==='USAGE' && trim($m[2])==='*.*') || trim($m[2])==='`fabyjulien\\_simpledevis\\_test`.*','exclusive_database_grant');
    }
    foreach ($pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN) as $db) { check(in_array($db,[TEST_DB,'information_schema','performance_schema'],true),'no_other_database_visible'); }
    $pdo=null;
    foreach (['APP_ENV'=>'testing','APP_DEBUG'=>'false','APP_KEY'=>'base64:'.base64_encode(str_repeat('T',32)),
        'APP_CONFIG_CACHE'=>getcwd().'/tmp/mariadb-validation-no-config-cache.php',
        'DB_CONNECTION'=>'mariadb_test','DB_URL'=>'','DB_SOCKET'=>'',
        'CACHE_STORE'=>'array','SESSION_DRIVER'=>'array','MAIL_MAILER'=>'array','QUEUE_CONNECTION'=>'sync',
        'LOG_CHANNEL'=>'null','BCRYPT_ROUNDS'=>'4','NIGHTWATCH_ENABLED'=>'false','TELESCOPE_ENABLED'=>'false','PULSE_ENABLED'=>'false'] as $key=>$value) { envTest($key,$value); }
    foreach (['DB_HOST','DB_DATABASE','DB_USERNAME','DB_PASSWORD'] as $key) { envTest($key,$c[$key]); }
    $app=require getcwd().'/bootstrap/app.php';
    $app->loadEnvironmentFrom('.env.mariadb-test');
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config(['database.default'=>'mariadb_test','database.connections'=>['mariadb_test'=>[
        'driver'=>'mysql','host'=>$c['DB_HOST'],'port'=>$c['DB_PORT']??3306,'database'=>TEST_DB,
        'username'=>$c['DB_USERNAME'],'password'=>$c['DB_PASSWORD'],'charset'=>'utf8mb4','collation'=>'utf8mb4_unicode_ci',
        'prefix'=>'','strict'=>true,'engine'=>'InnoDB','options'=>$options,
    ]]]);
    DB::setDefaultConnection('mariadb_test');
    Schema::clearResolvedInstance('db.schema');
    Http::preventStrayRequests(); Saloon\Config::preventStrayRequests();
    check(DB::selectOne('SELECT DATABASE() AS db')->db===TEST_DB,'laravel_selected_test_database');
    DB::statement('SET SESSION innodb_lock_wait_timeout=6');
    return $identity;
}
function directory(string $run): string { check(preg_match('/\A[a-f0-9]{16}\z/D',$run)===1,'valid_run_id'); $path=getcwd().'/tmp/mariadb-emission-'.$run; if(!is_dir($path)){mkdir($path,0700);} return $path; }
function stateFile(string $run): string { return directory($run).'/state.json'; }
function state(string $run): array { return json_decode(file_get_contents(stateFile($run)),true,64,JSON_THROW_ON_ERROR); }
function waitFor(string $path, int $seconds=25): void { $until=microtime(true)+$seconds; while(!is_file($path)) { check(microtime(true)<$until,'barrier_timeout_'.basename($path)); usleep(20000); } }
function documentReference(User $actor, Invoice $invoice): string { return 'invoice-cii/v1/validated/'.$actor->id.'/'.$invoice->id.'/'.hash('sha256','MARIADB-TEST:'.$invoice->id); }
function historyFingerprint(): string {
    $data=[]; foreach(['users','companies','clients','invoices','electronic_invoices','electronic_invoice_statuses'] as $table) {
        $rows=DB::table($table)->orderBy('id')->get()->map(fn($row)=>(array)$row)->all();
        foreach($rows as &$row) { foreach(['country_code','billing_electronic_address','emission_profile','emission_context','dispatch_key','local_reference','execution_mode','environment','provider_company_id','provider_invoice_id','provider_binding_key','document_reference','cii_sha256','business_status','business_event_id','event_cursor','event_key','provider_event_id','payload_sha256'] as $key) { if(array_key_exists($key,$row)&&$row[$key]===null){unset($row[$key]);} } ksort($row); } unset($row);
        $data[$table]=$rows;
    }
    return hash('sha256',json_encode($data,JSON_THROW_ON_ERROR));
}
function initialize(string $run,bool $resume=false): array {
    if(!$resume){check(count(DB::select('SHOW FULL TABLES'))===0,'initial_database_empty');}
    else{foreach(['users','companies','clients','invoices'] as $table){check(Schema::hasTable($table)&&DB::table($table)->count()===0,'resume_empty_base_tables');}}
    $migrator=app('migrator'); $migrator->setConnection('mariadb_test'); $repo=$migrator->getRepository(); if(!$repo->repositoryExists()){$repo->createRepository();}
    $paths=glob(database_path('migrations/*.php')); sort($paths,SORT_STRING);
    $base=array_values(array_filter($paths,fn($path)=>basename($path)<FIRST_MIGRATION.'.php'));
    $migrator->run($base);
    $legacy=User::factory()->create(['name'=>'MDB fictitious legacy','email'=>'mdb-legacy-'.$run.'@example.invalid']);
    $legacy->company()->create(['company_name'=>'Fictitious legacy seller']);
    $client=$legacy->clients()->create(['first_name'=>'Fictitious','last_name'=>'Legacy','client_type'=>'professional','country_code'=>'FR']);
    for($i=1;$i<=35;$i++){ Invoice::create(['user_id'=>$legacy->id,'client_id'=>$client->id,'invoice_number'=>'MDB-LEGACY-'.$run.'-'.$i,'date'=>'2025-01-01','document_status'=>'issued','status'=>'non_payee','subtotal_ht'=>100,'total_tva'=>20,'total_ttc'=>120,'amount_paid'=>0]); }
    $legacyElectronic=ElectronicInvoice::create(['invoice_id'=>Invoice::where('user_id',$legacy->id)->first()->id,'status'=>'legacy','iopole_invoice_id'=>'fictitious-legacy']);
    $legacyElectronic->statuses()->create(['status'=>'legacy','iopole_status_id'=>'fictitious-legacy-event','payload'=>['fictitious'=>true]]);
    $before=historyFingerprint();
    $first=database_path('migrations/'.FIRST_MIGRATION.'.php');$second=database_path('migrations/'.SECOND_MIGRATION.'.php');
    $migrator->run([$first]); check(Schema::hasColumn('companies','country_code')&&!Schema::hasTable('electronic_invoice_attempts'),'first_migration_only'); check(historyFingerprint()===$before,'first_migration_preserves_history');
    $migrator->run([$second]); check(Schema::hasTable('electronic_invoice_attempts'),'second_migration_applied');check(historyFingerprint()===$before,'second_migration_preserves_history');
    check(DB::table('invoices')->whereNull('party_snapshot')->whereNull('emission_context')->whereNull('emission_profile')->count()===35,'35_historical_invoices_unqualified');
    check($migrator->rollback([$second],['step'=>1])===[ $second ],'rollback_second_migration_only');
    check($migrator->rollback([$first],['step'=>1])===[ $first ],'rollback_first_migration_only');
    check(historyFingerprint()===$before,'rollback_preserves_existing_test_data');
    $migrator->run([$first,$second]);check(historyFingerprint()===$before,'reapply_preserves_existing_test_data');
    $owner=User::factory()->create(['name'=>'MDB fictitious emission','email'=>'mdb-emission-'.$run.'@example.invalid']);
    $owner->company()->create(['company_name'=>'Fictitious MDB seller','country_code'=>'FR','siret'=>'73282932000074','vat_number'=>'FR44732829320','vat_regime'=>'subject_to_vat',
        'address'=>'1 fictitious street','postal_code'=>'75001','city'=>'Paris','invoice_recovery_indemnity_text'=>'Fictitious PMT','invoice_late_payment_penalties_text'=>'Fictitious PMD','invoice_early_payment_discount_text'=>'Fictitious AAB','billing_electronic_address'=>['scheme'=>'0225','value'=>'732829320']]);
    $buyer=$owner->clients()->create(['company_name'=>'Fictitious MDB buyer','client_type'=>'professional','country_code'=>'FR','siret'=>'55210055400013','address'=>'2 fictitious street','postal_code'=>'69001','city'=>'Lyon','billing_electronic_address'=>['scheme'=>'0225','value'=>'552100554']]);
    Schema::create('mariadb_emission_test_probes',function(Blueprint $t){$t->id();$t->string('run_id',16);$t->unsignedBigInteger('invoice_id');$t->uuid('reference');$t->string('outcome');$t->unsignedInteger('worker_pid');});
    Schema::create('mariadb_emission_test_locks',function(Blueprint $t){$t->unsignedInteger('id')->primary();$t->unsignedInteger('value')->default(0);});
    for($i=1;$i<=100;$i++){DB::table('mariadb_emission_test_locks')->insert(['id'=>$i,'value'=>0]);}
    $s=['run'=>$run,'owner_id'=>$owner->id,'buyer_id'=>$buyer->id,'legacy_owner_id'=>$legacy->id,'migration_order'=>[FIRST_MIGRATION,SECOND_MIGRATION],'historical_rows'=>35,'migration_rollback_reapply'=>'passed'];
    file_put_contents(stateFile($run),json_encode($s,JSON_PRETTY_PRINT));
    return $s;
}
function fixture(string $run,string $case): Invoice {
    $s=state($run);$owner=User::findOrFail($s['owner_id']);
    return DB::transaction(function() use($owner,$s,$run,$case){
        User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
        $invoice=Invoice::create(['user_id'=>$owner->id,'client_id'=>$s['buyer_id'],'date'=>'2026-11-09','due_date'=>'2026-12-09','document_status'=>'draft','status'=>'non_payee',
            'calculation_version'=>'decimal-v1','french_business_process_type'=>'S1','emission_profile'=>'fr_b2b_380_eur','subtotal_ht'=>'100.00','total_tva'=>'20.00','total_ttc'=>'120.00','amount_paid'=>'0.00']);
        $invoice->items()->create(['description'=>'Fictitious MariaDB test','quantity'=>'1.00','unit_price_ht'=>'100.00','tva_rate'=>'20.00','line_total_ht'=>'100.00','vat_category'=>'S','unit_code'=>'C62']);
        app(InvoicePartySnapshotCapture::class)->captureForFinalization($invoice);
        $invoice->update(['document_status'=>'issued','invoice_number'=>'MDB-'.$run.'-'.$case.'-'.bin2hex(random_bytes(3))]);
        app(App\Services\SuperPdp\InvoiceEmissionPreparation::class)->assertCompleteForFinalization($invoice);
        return $invoice->fresh();
    });
}
function context(string $run,int $invoiceId,string $behavior='normal',string $barrier=''): array {
    $s=state($run);$actor=User::findOrFail($s['owner_id']);$invoice=Invoice::findOrFail($invoiceId);
    check($invoice->user_id===$actor->id,'fixture_owned');
    $ref=documentReference($actor,$invoice);
    $gate=new class($actor,$invoice,$ref,$behavior,$barrier) implements EmissionPrerequisites {
        public function __construct(private User $owner,private Invoice $fixture,private string $ref,private string $behavior,private string $barrier){}
        public function authorize(User $actor,Invoice $invoice,string $reference):EmissionDocument {
            check($actor->id===$this->owner->id&&$invoice->id===$this->fixture->id&&$reference===$this->ref&&$invoice->isIssued()&&$invoice->amount_paid==='0.00','fake_gate_scope');
            if(in_array($this->behavior,['deadlock-reserve','deadlock-claim'],true)) {
                waitFor($this->barrier.'.b_locked');file_put_contents($this->barrier.'.a_locked','1');usleep(250000);
                try{ DB::table('mariadb_emission_test_locks')->where('id',100)->lockForUpdate()->first(); }
                catch(Throwable $e){ file_put_contents($this->barrier.'.deadlock_a',json_encode(safeFailure($e)));throw $e; }
            }
            return new EmissionDocument($actor->id,$invoice->id,$reference,CapturedSandboxCii::xml(),123,'production','validated_private_document');
        }
    };
    $transport=new class($run,$behavior,$barrier) implements EmissionTransport {
        public function __construct(private string $run,private string $behavior,private string $barrier){}
        public function mode():string{return 'test';}
        public function send(EmissionDocument $doc,string $reference):array {
            check(DB::transactionLevel()===0&&!DB::getPdo()->inTransaction(),'commit_before_fake_transport');
            if($this->behavior==='interrupt-before'){file_put_contents($this->barrier.'.interrupt_ready','1');sleep(60);throw new RuntimeException('interrupt not triggered');}
            DB::table('mariadb_emission_test_probes')->insert(['run_id'=>$this->run,'invoice_id'=>$doc->invoiceId,'reference'=>$reference,'outcome'=>$this->behavior==='reject'?'rejected':'possibly_accepted','worker_pid'=>getmypid()]);
            if($this->behavior==='interrupt-after'){file_put_contents($this->barrier.'.interrupt_ready','1');sleep(60);throw new RuntimeException('interrupt not triggered');}
            if($this->behavior==='timeout'){throw new RuntimeException('simulated response loss');}
            if($this->behavior==='reject'){return ['status'=>400,'body'=>json_encode(['http_status_code'=>400,'code'=>10,'message'=>'Fictitious refusal'])];}
            usleep(200000);
            return ['status'=>200,'body'=>json_encode(['id'=>1000000+$doc->invoiceId,'company_id'=>123,'direction'=>'out','external_id'=>$reference,'created_at'=>now()->toIso8601String()])];
        }
        public function lookup(int $id):array {
            $p=DB::table('mariadb_emission_test_probes')->where('run_id',$this->run)->where('invoice_id',$id-1000000)->where('outcome','possibly_accepted')->orderBy('id')->first();
            return $p?['id'=>$id,'company_id'=>123,'direction'=>'out','external_id'=>$p->reference,'created_at'=>'2026-11-09T12:00:00+00:00']:[];
        }
        public function original(int $id):string{return $this->behavior==='wrong-original'?'different fictitious original':CapturedSandboxCii::xml();}
        public function events(int $id,?int $cursor):array{return ['data'=>[],'has_after'=>false];}
    };
    return [$actor,$invoice,$ref,new OutgoingEmission($gate,$transport)];
}
function worker(string $run,string $behavior,int $invoiceId,string $barrier):array {
    [$actor,$invoice,$ref,$service]=context($run,$invoiceId,$behavior,$barrier);
    if($behavior==='opponent') {
        DB::beginTransaction();
        try {
            DB::table('mariadb_emission_test_locks')->whereBetween('id',[1,100])->increment('value');
            file_put_contents($barrier.'.b_locked','1');waitFor($barrier.'.a_locked');
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();DB::commit();
            return ['status'=>'opponent_committed'];
        }catch(Throwable $e){if(DB::transactionLevel()>0){DB::rollBack();}return ['status'=>'opponent_error','error'=>safeFailure($e)];}
    }
    if($barrier!==''&&!str_starts_with($behavior,'deadlock-')&&!str_starts_with($behavior,'interrupt-')){file_put_contents($barrier.'.ready.'.getmypid(),'1');waitFor($barrier.'.go');}
    if($behavior==='deadlock-finalize') {
        Event::listen('eloquent.updating: '.ElectronicInvoice::class,function(ElectronicInvoice $record) use($barrier){
            if($record->status==='accepted') {
                waitFor($barrier.'.b_locked');file_put_contents($barrier.'.a_locked','1');usleep(250000);
                try{DB::table('mariadb_emission_test_locks')->where('id',100)->lockForUpdate()->first();}
                catch(Throwable $e){file_put_contents($barrier.'.deadlock_a',json_encode(safeFailure($e)));throw $e;}
            }
        });
    }
    try {
        if(in_array($behavior,['deadlock-finalize','deadlock-claim'],true)){$record=ElectronicInvoice::where('invoice_id',$invoiceId)->firstOrFail();$attempt=$record->attempts()->latest('number')->firstOrFail();}
        else{$attempt=$service->reserve($actor,$invoice,$ref,true);$record=ElectronicInvoice::findOrFail($attempt->electronic_invoice_id);}
        $out=$service->dispatch($actor,$record,$attempt->id);
        return ['status'=>$out->status,'attempt_id'=>$out->id,'number'=>$out->number,'error_code'=>$out->error_code,'connection_id'=>DB::selectOne('SELECT CONNECTION_ID() AS id')->id];
    }catch(Throwable $e){return ['status'=>'worker_error','error'=>safeFailure($e)];}
}

function spawnWorker(string $run,string $behavior,int $invoiceId,string $barrier):array {
    $name=directory($run).'/worker-'.$behavior.'-'.bin2hex(random_bytes(4));
    $process=proc_open([PHP_BINARY,__FILE__,'worker',$run,$behavior,(string)$invoiceId,$barrier],
        [0=>['pipe','r'],1=>['file',$name.'.out','w'],2=>['file',$name.'.err','w']],$pipes,getcwd(),null,['bypass_shell'=>true]);
    check(is_resource($process),'worker_started');fclose($pipes[0]);
    return ['process'=>$process,'out'=>$name.'.out','err'=>$name.'.err'];
}
function collectWorker(array $worker,int $seconds=25):array {
    $until=microtime(true)+$seconds;
    while(proc_get_status($worker['process'])['running']){
        if(microtime(true)>$until){proc_terminate($worker['process']);throw new RuntimeException('CHECK:worker_deadline');}usleep(20000);
    }
    proc_close($worker['process']);
    $body=file_get_contents($worker['out']);$result=json_decode(trim($body),true);
    check(is_array($result),'worker_json_result');return $result;
}
function race(string $run,Invoice $invoice,string $label,int $count=2):array {
    $barrier=directory($run).'/race-'.$label.'-'.bin2hex(random_bytes(3));$workers=[];
    for($i=0;$i<$count;$i++){$workers[]=spawnWorker($run,'normal',$invoice->id,$barrier);}
    $until=microtime(true)+25;while(count(glob($barrier.'.ready.*'))<$count){check(microtime(true)<$until,'workers_ready');usleep(20000);}
    file_put_contents($barrier.'.go','1');$results=[];foreach($workers as $w){$results[]=collectWorker($w);}
    check(count(array_unique(array_column($results,'connection_id')))===$count,'distinct_parallel_connections');
    check(count(array_unique(array_column($results,'attempt_id')))===1,'same_attempt_across_workers');
    foreach($results as $r){check(in_array($r['status'],['pending','accepted'],true),'parallel_worker_status');}
    $record=ElectronicInvoice::where('invoice_id',$invoice->id)->firstOrFail();
    check($record->status==='accepted'&&$record->attempts()->count()===1,'parallel_single_accepted_attempt');
    check(DB::table('mariadb_emission_test_probes')->where('invoice_id',$invoice->id)->count()===1,'one_fake_transport_call');
    return ['status'=>'passed','workers'=>$results,'mock_deposits'=>1];
}
function deadlockCase(string $run,string $kind):array {
    $invoice=fixture($run,$kind);$barrier=directory($run).'/'.$kind.'-'.bin2hex(random_bytes(3));
    [$actor,$stored,$ref,$service]=context($run,$invoice->id);
    if($kind!=='deadlock-reserve'){$service->reserve($actor,$stored,$ref);}
    $b=spawnWorker($run,'opponent',$invoice->id,$barrier);waitFor($barrier.'.b_locked');
    $a=spawnWorker($run,$kind,$invoice->id,$barrier);
    $ar=collectWorker($a);$br=collectWorker($b);
    $observed=is_file($barrier.'.deadlock_a')?json_decode(file_get_contents($barrier.'.deadlock_a'),true):null;
    check(($observed['driver_code']??null)===1213,'real_deadlock_1213_'.$kind);
    check($br['status']==='opponent_committed','opponent_finishes_'.$kind);
    $calls=DB::table('mariadb_emission_test_probes')->where('invoice_id',$invoice->id)->count();
    $record=ElectronicInvoice::where('invoice_id',$invoice->id)->first();
    if($kind==='deadlock-reserve') {
        check($ar['status']==='worker_error'&&($ar['error']['driver_code']??null)===1213,'reservation_victim_rolled_back');
        check($record===null&&$calls===0,'no_reservation_or_deposit_after_deadlock');
        $attempt=$service->reserve($actor,$stored,$ref);$record=ElectronicInvoice::findOrFail($attempt->electronic_invoice_id);
        check($service->dispatch($actor,$record,$attempt->id)->status==='accepted','safe_manual_retry_before_claim');
    } elseif($kind==='deadlock-finalize') {
        check($ar['status']==='unknown'&&$ar['error_code']==='result_persistence_uncertain','post_deposit_deadlock_stays_unknown');
        check($calls===1&&$record->attempts()->count()===1,'post_deposit_deadlock_one_call');
        $attempt=$service->reserve($actor,$stored,$ref,true);$service->dispatch($actor,$record,$attempt->id);
        check(DB::table('mariadb_emission_test_probes')->where('invoice_id',$invoice->id)->count()===1,'deadlock_no_automatic_resend');
        check($service->reconcile($actor,$record,$attempt->id,1000000+$invoice->id)->status==='accepted','deadlock_positive_reconciliation');
    } else {
        check($ar['status']==='worker_error'&&($ar['error']['driver_code']??null)===1213,'claim_deadlock_propagates_without_writes');
        check($record->fresh()->status==='pending'&&$record->attempts()->sole()->transport_started_at===null&&$calls===0,'claim_deadlock_transaction_rollback');
        $attempt=$service->reserve($actor,$stored,$ref);check($service->dispatch($actor,$record,$attempt->id)->status==='accepted','safe_manual_claim_retry');
    }
    return ['status'=>'passed','application_worker'=>$ar,'opponent'=>$br,'observed_deadlock'=>$observed,
        'final_mock_deposits'=>DB::table('mariadb_emission_test_probes')->where('invoice_id',$invoice->id)->count()];
}
function interruptedCase(string $run,string $kind):array {
    $invoice=fixture($run,$kind);$barrier=directory($run).'/'.$kind.'-'.bin2hex(random_bytes(3));
    $w=spawnWorker($run,$kind,$invoice->id,$barrier);waitFor($barrier.'.interrupt_ready');
    proc_terminate($w['process']);$until=microtime(true)+10;while(proc_get_status($w['process'])['running']){check(microtime(true)<$until,'child_terminated');usleep(20000);}proc_close($w['process']);
    [$actor,$stored,$ref,$service]=context($run,$invoice->id);$record=ElectronicInvoice::where('invoice_id',$invoice->id)->firstOrFail();$attempt=$record->attempts()->sole();
    check($attempt->status==='pending'&&$attempt->transport_started_at!==null,'claim_survives_process_kill');
    Illuminate\Support\Carbon::setTestNow(now()->addMinutes(6));
    try{check($service->recoverInterrupted($actor,$record,$attempt->id)->status==='unknown','interrupted_unknown');}finally{Illuminate\Support\Carbon::setTestNow();}
    $again=$service->reserve($actor,$stored,$ref,true);$service->dispatch($actor,$record,$again->id);
    $calls=DB::table('mariadb_emission_test_probes')->where('invoice_id',$invoice->id)->count();
    check($again->id===$attempt->id&&$calls===($kind==='interrupt-after'?1:0),'interrupted_never_resent');
    $resolved=$service->reconcile($actor,$record,$attempt->id,1000000+$invoice->id);
    check($resolved->status===($kind==='interrupt-after'?'accepted':'unknown'),'only_positive_interruption_reconciliation');
    return ['status'=>'passed','real_child_terminated'=>true,'mock_deposits'=>$calls,'final_status'=>$resolved->status];
}
function runCases(string $run):array {
    $results=[];$s=state($run);
    foreach(['users','invoices','electronic_invoices','electronic_invoice_attempts','electronic_invoice_statuses'] as $table){check(DB::selectOne('SELECT ENGINE AS engine FROM information_schema.TABLES WHERE TABLE_SCHEMA=? AND TABLE_NAME=?',[TEST_DB,$table])->engine==='InnoDB','innodb_'.$table);}
    $observer=config('database.connections.mariadb_test');config(['database.connections.observer_test'=>$observer]);
    $secondary=DB::connection('observer_test');check($secondary->selectOne('SELECT DATABASE() AS db')->db===TEST_DB,'observer_test_database');
    check($secondary->selectOne('SELECT CONNECTION_ID() AS id')->id!==DB::selectOne('SELECT CONNECTION_ID() AS id')->id,'independent_observer_connection');
    $old=(int)DB::table('mariadb_emission_test_locks')->where('id',1)->value('value');
    DB::beginTransaction();DB::table('mariadb_emission_test_locks')->where('id',1)->increment('value');
    check((int)$secondary->table('mariadb_emission_test_locks')->where('id',1)->value('value')===$old,'uncommitted_change_invisible');DB::rollBack();
    check((int)$secondary->table('mariadb_emission_test_locks')->where('id',1)->value('value')===$old,'transaction_rollback');
    DB::transaction(fn()=>DB::table('mariadb_emission_test_locks')->where('id',1)->increment('value'));
    check((int)$secondary->table('mariadb_emission_test_locks')->where('id',1)->value('value')===$old+1,'transaction_committed_visible');DB::purge('observer_test');
    $results['transactions']=['status'=>'passed','separate_connections'=>2,'engine'=>'InnoDB'];
    for($i=1;$i<=3;$i++){ $invoice=fixture($run,'race-'.$i);$results['parallel_'.$i]=race($run,$invoice,'normal-'.$i); }
    $invoice=fixture($run,'timeout');$result=worker($run,'timeout',$invoice->id,'');check($result['status']==='unknown','timeout_unknown');
    [$actor,$stored,$ref,$service]=context($run,$invoice->id);$record=ElectronicInvoice::where('invoice_id',$invoice->id)->firstOrFail();$attempt=$service->reserve($actor,$stored,$ref,true);$service->dispatch($actor,$record,$attempt->id);
    check(DB::table('mariadb_emission_test_probes')->where('invoice_id',$invoice->id)->count()===1,'timeout_no_resend');
    check($service->reconcile($actor,$record,$attempt->id,1000000+$invoice->id)->status==='accepted','timeout_reconciled');
    $results['timeout']=['status'=>'passed','mock_deposits'=>1,'reconciled'=>'accepted'];
    $negative=fixture($run,'negative-reconciliation');check(worker($run,'timeout',$negative->id,'')['status']==='unknown','negative_case_unknown');
    [$negativeActor,$negativeInvoice,$negativeRef,$negativeService]=context($run,$negative->id,'wrong-original');$negativeRecord=ElectronicInvoice::where('invoice_id',$negative->id)->firstOrFail();$negativeAttempt=$negativeRecord->attempts()->sole();
    check($negativeService->reconcile($negativeActor,$negativeRecord,$negativeAttempt->id,1000000+$negative->id)->status==='unknown','wrong_original_remains_unknown');
    $negativeService->dispatch($negativeActor,$negativeRecord,$negativeService->reserve($negativeActor,$negativeInvoice,$negativeRef,true)->id);
    check(DB::table('mariadb_emission_test_probes')->where('invoice_id',$negative->id)->count()===1,'negative_reconciliation_no_resend');
    $results['negative_reconciliation']=['status'=>'passed','mock_deposits'=>1,'final_status'=>'unknown'];
    $invoice=fixture($run,'unique');[$actor,$stored,$ref,$service]=context($run,$invoice->id);$attempt=$service->reserve($actor,$stored,$ref);$record=ElectronicInvoice::findOrFail($attempt->electronic_invoice_id);
    $unique=[];
    foreach([
        'dispatch_key'=>fn()=>DB::table('electronic_invoices')->insert(['invoice_id'=>$invoice->id,'dispatch_key'=>$record->dispatch_key]),
        'local_reference'=>fn()=>DB::table('electronic_invoices')->insert(['invoice_id'=>$invoice->id,'local_reference'=>$record->local_reference]),
        'attempt_number'=>fn()=>$record->attempts()->create(['number'=>1,'reference'=>(string)Illuminate\Support\Str::uuid(),'status'=>'pending','cii_sha256'=>$attempt->cii_sha256,'history'=>[]]),
        'attempt_reference'=>fn()=>$record->attempts()->create(['number'=>2,'reference'=>$attempt->reference,'status'=>'pending','cii_sha256'=>$attempt->cii_sha256,'history'=>[]]),
    ] as $name=>$operation){try{$operation();throw new RuntimeException('CHECK:unique_not_enforced_'.$name);}catch(Illuminate\Database\QueryException $e){check(($e->errorInfo[1]??null)===1062,'unique_1062_'.$name);$unique[]=$name;}}
    check($service->dispatch($actor,$record,$attempt->id)->status==='accepted','unique_case_deposit');
    try{DB::table('electronic_invoices')->insert(['invoice_id'=>$invoice->id,'provider_binding_key'=>$record->fresh()->provider_binding_key]);throw new RuntimeException('CHECK:remote_binding_unique_not_enforced');}catch(Illuminate\Database\QueryException $e){check(($e->errorInfo[1]??null)===1062,'remote_binding_unique_1062');$unique[]='provider_binding_key';}
    $event=['id'=>2000000+$invoice->id,'invoice_id'=>1000000+$invoice->id,'status_code'=>'fr:212','status_text'=>'Fictitious payment event','created_at'=>'2026-11-09T12:00:00+00:00'];
    check($service->applyEvents($actor,$record,[$event])===1&&$service->applyEvents($actor,$record,[$event])===0,'events_idempotent');
    $existing=$record->statuses()->sole();try{DB::table('electronic_invoice_statuses')->insert(['electronic_invoice_id'=>$record->id,'event_key'=>$existing->event_key]);throw new RuntimeException('CHECK:event_unique_not_enforced');}catch(Illuminate\Database\QueryException $e){check(($e->errorInfo[1]??null)===1062,'event_unique_1062');$unique[]='event_key';}
    check($invoice->fresh()->amount_paid==='0.00'&&$invoice->fresh()->status==='non_payee'&&$invoice->payments()->count()===0,'event_not_payment');
    $results['unique_constraints_and_events']=['status'=>'passed','constraints'=>$unique,'payment_unchanged'=>true];
    $lockedInvoice=fixture($run,'lock-wait');$lockBarrier=directory($run).'/lock-wait-'.bin2hex(random_bytes(3));
    DB::beginTransaction();User::whereKey($s['owner_id'])->lockForUpdate()->firstOrFail();
    try{
        $blocked=spawnWorker($run,'normal',$lockedInvoice->id,$lockBarrier);$until=microtime(true)+25;
        while(count(glob($lockBarrier.'.ready.*'))!==1){check(microtime(true)<$until,'lock_wait_worker_ready');usleep(20000);}file_put_contents($lockBarrier.'.go','1');
        $lockResult=collectWorker($blocked);check($lockResult['status']==='worker_error'&&($lockResult['error']['driver_code']??null)===1205,'real_lock_wait_timeout_1205');
    }finally{DB::rollBack();}
    check(ElectronicInvoice::where('invoice_id',$lockedInvoice->id)->count()===0,'lock_wait_no_partial_reservation');
    check(worker($run,'normal',$lockedInvoice->id,'')['status']==='accepted','manual_retry_after_lock_wait');
    check(DB::table('mariadb_emission_test_probes')->where('invoice_id',$lockedInvoice->id)->count()===1,'lock_wait_one_fake_deposit');
    $results['lock_wait']=['status'=>'passed','driver_code'=>1205,'mock_deposits'=>1];
    foreach(['interrupt-before','interrupt-after'] as $case){$results[$case]=interruptedCase($run,$case);file_put_contents(directory($run).'/partial-results.json',json_encode($results,JSON_PRETTY_PRINT));}
    foreach(['deadlock-reserve','deadlock-finalize','deadlock-claim'] as $case){$results[$case]=deadlockCase($run,$case);file_put_contents(directory($run).'/partial-results.json',json_encode($results,JSON_PRETTY_PRINT));}
    check(Invoice::where('user_id',$s['legacy_owner_id'])->whereNull('party_snapshot')->whereNull('emission_context')->count()===35,'legacy_test_data_preserved_at_end');
    check(!app(App\Services\SuperPdp\SuperPdpEmissionEligibilityDiagnostic::class)->diagnose($invoice)['eligible'],'global_diagnostic_still_closed');
    check(app(EmissionTransport::class) instanceof App\Services\SuperPdp\Emission\DisabledEmissionTransport,'application_transport_still_disabled');
    check(DB::table('mariadb_emission_test_probes')->where('run_id',$run)->select('invoice_id')->groupBy('invoice_id')->havingRaw('COUNT(*) > 1')->get()->count()===0,'no_double_deposit_in_any_run_fixture');
    return $results;
}

try {
    $identity=bootTest();$command=$argv[1]??'inspect';$run=$argv[2]??bin2hex(random_bytes(8));
    if($command==='init'){safeOutput(['status'=>'initialized','identity'=>$identity,'state'=>initialize($run)]);}
    elseif($command==='resume-init'){safeOutput(['status'=>'initialized','identity'=>$identity,'state'=>initialize($run,true)]);}
    elseif($command==='fixture'){safeOutput(['invoice_id'=>fixture($run,$argv[3]??'case')->id]);}
    elseif($command==='worker'){safeOutput(worker($run,$argv[3],(int)$argv[4],$argv[5]??''));}
    elseif($command==='run'){$results=runCases($run);file_put_contents(directory($run).'/results.json',json_encode($results,JSON_PRETTY_PRINT));safeOutput(['status'=>'passed','identity'=>$identity,'results'=>$results]);}
    elseif($command==='verify-rollback-guards'){
        $fingerprint=function(){
            $rows=[];
            foreach(DB::select('SHOW TABLES') as $entry){
                $table=array_values((array)$entry)[0];$records=[];
                foreach(DB::table($table)->get() as $record){$values=(array)$record;ksort($values);$records[]=json_encode($values,JSON_THROW_ON_ERROR);}
                sort($records,SORT_STRING);$rows[$table]=$records;
            }
            ksort($rows);return hash('sha256',json_encode($rows,JSON_THROW_ON_ERROR));
        };
        $before=$fingerprint();$refusals=[];
        foreach([SECOND_MIGRATION,FIRST_MIGRATION] as $name){
            try{(require database_path('migrations/'.$name.'.php'))->down();throw new RuntimeException('CHECK:destructive_rollback_not_blocked');}
            catch(RuntimeException $e){check(str_starts_with($e->getMessage(),'Rollback refus'),'rollback_guard_reason');$refusals[]=$name;}
            check($fingerprint()===$before,'all_test_data_unchanged_after_refused_rollback');
        }
        safeOutput(['status'=>'passed','refused_rollbacks'=>$refusals,'all_test_data_preserved'=>true]);
    }
    elseif($command==='final-audit'){
        $s=state($run);$duplicates=DB::table('mariadb_emission_test_probes')->where('run_id',$run)->select('invoice_id')->groupBy('invoice_id')->havingRaw('COUNT(*) > 1')->get()->count();
        check($duplicates===0,'no_duplicate_fake_deposits_across_all_executions');
        check(DB::table('users')->where('email','not like','%@example.invalid')->count()===0,'only_fictitious_accounts');
        check(DB::table('electronic_invoices')->whereNotNull('dispatch_key')->where('execution_mode','!=','test')->count()===0,'only_test_mode_emissions');
        check(Invoice::where('user_id',$s['legacy_owner_id'])->whereNull('party_snapshot')->whereNull('emission_context')->whereNull('emission_profile')->count()===35,'legacy_preserved');
        $counts=[];foreach(['users','companies','clients','invoices','electronic_invoices','electronic_invoice_attempts','electronic_invoice_statuses','payments','mariadb_emission_test_probes'] as $table){$counts[$table]=DB::table($table)->count();}
        safeOutput(['status'=>'passed','identity'=>$identity,'counts'=>$counts,'double_fake_deposits'=>$duplicates,
            'migration_order'=>DB::table('migrations')->whereIn('migration',[FIRST_MIGRATION,SECOND_MIGRATION])->orderBy('id')->pluck('migration')->all(),
            'base_retained'=>true]);
    }
    elseif($command==='claim-state'){
        $invoice=Invoice::where('invoice_number','MDB-'.$run.'-deadlock-claim')->firstOrFail();$r=ElectronicInvoice::where('invoice_id',$invoice->id)->firstOrFail();
        safeOutput(['status'=>$r->status,'attempts'=>$r->attempts()->get(['id','status','transport_started_at','error_code'])->toArray(),'mock_deposits'=>DB::table('mariadb_emission_test_probes')->where('invoice_id',$invoice->id)->count()]);
    }
    elseif($command==='inspect'){
        $counts=[];foreach(['users','companies','clients','invoices','electronic_invoices','electronic_invoice_attempts'] as $table){if(Schema::hasTable($table)){$counts[$table]=DB::table($table)->count();}}
        safeOutput(['status'=>'isolated','identity'=>$identity,'tables'=>count(DB::select('SHOW TABLES')),'row_counts'=>$counts,
            'last_migrations'=>Schema::hasTable('migrations')?DB::table('migrations')->orderByDesc('id')->limit(6)->pluck('migration')->all():[]]);
    }
    else{throw new RuntimeException('CHECK:unknown_command');}
}catch(Throwable $e){safeOutput(['status'=>'failed','error'=>safeFailure($e)]);exit(1);}
