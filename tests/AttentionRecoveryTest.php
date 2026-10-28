<?php
namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use Closure;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\{AttentionBaselineRestorer,AttentionRecovery,CmsClient,CmsException,DynamicWorker,LocalStaticHooks,NativeAttentionInspector,NativePolicy,NativeWriteGuard,NativeWriter,OwnedFragments,PrivateState,PublicVerifier,ReplayJournal,StaticArtifact,StaticCoordinator};
use CodeWorksLabs\DiscussionBridgeStatamic\Support\Configuration;
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\PublicationFreshness;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\{ConnectionException,Factory};
use Illuminate\Support\Facades\{Facade,Http};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Actual collector and writer observation predicates; native entry/revision/lock seams are synthetic, no native store or HTTP. */
class AttentionRecoveryTest extends TestCase
{
    private CmsClient $client;
    private RecoveryReadWriter $writer;
    private array $state;
    private stdClass $inspection;
    private RecoveryState $store;
    private array $fixtures;

    protected function setUp(): void
    {
        $container = new Container; Container::setInstance($container); Facade::setFacadeApplication($container);
        $container->instance('config', new Repository(['discussionbridge' => ['enabled' => true, 'forum_url' => 'https://forum.example',
            'site_origin' => 'https://publisher.example', 'connection_id' => 'dbc_111111111111111111111111', 'cms_profile' => 'statamic_flat']]));
        $configuration = new class extends Configuration { public function secret(): string { return str_repeat('a', 43); } };
        $this->client = new CmsClient($configuration);
        foreach (json_decode(ltrim(file_get_contents(__DIR__.'/fixtures/attention-author-fixtures.json'), "\xef\xbb\xbf"))->fixtures as $f) $this->fixtures[$f->id] = $f->value;
        $mapping = clone $this->fixtures['mapping']; $work = clone $this->fixtures['lease']->work;
        $work->state = 'attention'; $work->attempt = 3; $work->retry_at = null; $work->last_failure_code = 'public_verification_failed';
        $source = clone $this->fixtures['initial_feed']->items[0]->source;
        $mapping->mapping_id = $work->mapping_id; $mapping->source_id = $work->source_id;
        $mapping->destination_native_id = 'owned-entry'; $mapping->canonical_url = 'https://publisher.example/owned';
        $source->source_id = $work->source_id; $source->source_revision = $work->source_revision;
        $receipt = clone $this->fixtures['native_ownership_receipt'];
        foreach (['mapping_id','resource_id','connection_id','source_id','external_id','canonical_url'] as $key) $receipt->$key = $mapping->$key;
        $receipt->native_id = 'owned-entry'; $receipt->source_revision = $work->source_revision; $receipt->last_lease_generation = $work->lease_generation;
        $receipt->forum_origin = 'https://forum.example'; $receipt->move_id = null; $receipt->proof_nonce = null;
        $receiptHash = $this->client->codec()->nativeOwnershipReceiptSha256($receipt);
        $result = (object) ['kind' => 'publish_revision', 'native_id' => 'owned-entry', 'external_id' => $mapping->external_id,
            'canonical_url' => $mapping->canonical_url, 'native_revision' => 'sha256:'.str_repeat('c',64), 'ownership_receipt_sha256' => $receiptHash,
            'representation' => (object) ['kind' => 'full', 'source_content_sha256' => $source->content_sha256]];
        $this->inspection = (object) ['sequence' => 1, 'inspection_id' => '11111111-1111-4111-8111-000000000091', 'context_sha256' => str_repeat('a',64),
            'work' => $work, 'mapping' => $mapping, 'source' => $source, 'native_result' => $result,
            'verified_native_result' => null, 'verification' => null, 'prepared_transaction' => null, 'created_at' => '2026-10-25T00:00:00.000Z'];
        $this->state = ['version' => 1, 'binding' => null, 'events' => [], 'sources' => [], 'mutations' => [], 'work' => [], 'mappings' => [
            $mapping->mapping_id => ['mapping' => $this->client->codec()->encodeJson($mapping), 'native_id' => 'owned-entry',
            'native_revision' => $result->native_revision, 'receipt' => $this->client->codec()->encodeJson($receipt), 'generation' => $work->lease_generation,
            'work_id' => $work->work_id, 'source_revision' => $work->source_revision, 'result' => $this->client->codec()->encodeJson($result)]]];
        $this->store = new RecoveryState($this->state);
        $guard = new class extends NativeWriteGuard { public function __construct() {} public function locked(string $id, Closure $operation): mixed { return $operation(); } };
        $policy = new class extends NativePolicy { public function __construct() {} };
        $freshness = (new \ReflectionClass(PublicationFreshness::class))->newInstanceWithoutConstructor();
        $this->writer = new RecoveryReadWriter($configuration,$this->client,$this->store,$policy,$guard,$freshness);
        $this->writer->entry = new RecoveryEntry('owned-entry', $mapping->canonical_url, [
            'discussionbridge_cms_owned' => true, 'discussionbridge_cms_mapping_id' => $mapping->mapping_id,
            'discussionbridge_resource_id' => $mapping->resource_id, 'discussionbridge_cms_receipt_sha256' => $receiptHash,
            'discussionbridge_cms_generation' => $work->lease_generation]);
        \Statamic\Facades\Entry::swap(new class($this->writer){public function __construct(private RecoveryReadWriter $writer){}
            public function query(){return new class($this->writer){public function __construct(private RecoveryReadWriter $writer){}public function where($field,$id){return $this;}public function limit($n){return $this;}public function get(){return collect(array_filter([$this->writer->entry,...$this->writer->duplicates]));}};}
            public function findByUri($uri,$site=null){return null;}});
    }
    protected function tearDown(): void { Facade::clearResolvedInstances(); Facade::setFacadeApplication(null); Container::setInstance(null); }
    private function inspector(?StaticArtifact $artifacts=null, ?PublicVerifier $override=null): NativeAttentionInspector
    {
        $fragments = new class extends OwnedFragments { public function expected($entry): array { return ['article'=>'actual article','discussion'=>'actual discussion']; } };
        $verifier = new class($fragments) extends PublicVerifier {
            public function __construct(private OwnedFragments $fixtureFragments) {}
            public function inspect(stdClass $mapping, ?stdClass $source, stdClass $nativeResult, array $expected, string $notBefore): stdClass {
                return (object) ['kind'=>'published','url'=>$mapping->canonical_url,'native_id'=>$nativeResult->native_id,
                    'source_revision'=>$source->source_revision,'source_content_sha256'=>$source->content_sha256,
                    'native_publication_sha256'=>$this->fixtureFragments->sha256($expected),'verification_profile'=>OwnedFragments::PROFILE,
                    'checked_at'=>gmdate('Y-m-d\TH:i:s').'.000Z','representation'=>$nativeResult->representation];
            }
        };
        return new NativeAttentionInspector($this->client,$this->writer,$fragments,
            $override ?? $verifier,
            $artifacts ?? (new \ReflectionClass(StaticArtifact::class))->newInstanceWithoutConstructor());
    }
    public static function dynamicProfiles(): array { return [['statamic_flat','statamic-flat-1'],['statamic_db','statamic-db-1']]; }
    #[DataProvider('dynamicProfiles')]
    public function test_known_dynamic_candidate_uses_exact_same_object_and_does_not_settle_private_state(string $profile,string $wire): void
    {
        config(['discussionbridge.cms_profile'=>$profile]); $before=serialize($this->state);
        $observation=$this->inspector()->inspect($this->state,$this->inspection);
        $this->assertSame('candidate_applied',$observation->native_state); $this->assertSame($wire,$observation->profile);
        $this->assertSame('owned-entry',$observation->native_id); $this->assertSame($before,serialize($this->state)); $this->assertSame(0,$this->store->saves);
    }
    public static function blockers(): array { return [['foreign'],['revision'],['uncertain'],['move'],['static-candidate'],['duplicate']]; }
    #[DataProvider('blockers')]
    public function test_conflicts_and_uncertainty_are_facts_not_ready_or_mutating_probes(string $fault): void
    {
        if($fault==='foreign')$this->writer->entry->fields['discussionbridge_resource_id']='11111111-1111-4111-8111-000000000099';
        if($fault==='revision')$this->writer->nativeRevision='sha256:'.str_repeat('d',64);
        if($fault==='uncertain')$this->state['mutations']['unknown']=['route'=>'V4-16','path'=>['work_id'=>$this->inspection->work->work_id],'result'=>null];
        if($fault==='move')$this->inspection->mapping->mapping_state='url_move_attention';
        if($fault==='static-candidate')config(['discussionbridge.cms_profile'=>'statamic_ssg']);
        if($fault==='duplicate')$this->writer->duplicates=[new RecoveryEntry('other-entry','https://publisher.example/other',[])];
        $before=serialize($this->state);$observation=$this->inspector()->inspect($this->state,$this->inspection);
        $this->assertContains($observation->native_state,['conflict','unknown','unsupported']);$this->assertNotSame('none',$observation->issue);
        $this->assertSame($before,serialize($this->state));$this->assertSame(0,$this->store->saves);
    }
    public function test_missing_diagnostics_are_unknown_not_a_native_absence_claim(): void
    {
        unset($this->state['mappings'][$this->inspection->mapping->mapping_id]['receipt']);
        $o=$this->inspector()->inspect($this->state,$this->inspection);$this->assertSame('unknown',$o->native_state);$this->assertNull($o->ownership);
    }

    private function wire(bool $recovery=true, bool $lostReport=false, bool $wrongLease=false, bool $editOnClaim=false): array
    {
        $connection=clone $this->fixtures['connection'];$connection->profile='statamic_flat';$connection->destination_origin='https://publisher.example';
        $connection->policy=clone $connection->policy;$connection->policy->enabled=false;
        $next=clone $this->inspection->work;$next->work_id='11111111-1111-4111-8111-000000000095';$next->attempt_cycle='11111111-1111-4111-8111-000000000096';$next->state='pending';$next->attempt=0;$next->last_failure_code=null;
        $event=(object)['sequence'=>1,'recovery_id'=>'11111111-1111-4111-8111-000000000097','inspection_id'=>$this->inspection->inspection_id,
            'context_sha256'=>$this->inspection->context_sha256,'predecessor_work_id'=>$this->inspection->work->work_id,'predecessor_attempt_cycle'=>$this->inspection->work->attempt_cycle,
            'work'=>$next,'mapping'=>$this->inspection->mapping,'source'=>$this->inspection->source,'mode'=>'retry','native_baseline'=>$this->inspection->native_result,'verification'=>null,'created_at'=>$this->inspection->created_at];
        $lease=clone $this->fixtures['lease'];$lease->work=clone $next;$lease->work->state='leased';$lease->work->attempt=1;$lease->lease_generation++;$lease->work->lease_generation=$lease->lease_generation;
        $lease->server_time=gmdate('Y-m-d\TH:i:s').'.000Z';$lease->expires_at=gmdate('Y-m-d\TH:i:s',time()+300).'.000Z';
        if($wrongLease)$lease->work->work_id='11111111-1111-4111-8111-000000000098';
        $status=clone $this->fixtures['work_status'];$status->work=clone $lease->work;$status->work->state='verified';$status->mapping=$event->mapping;$status->lease_expires_at=null;
        $http=new Factory;$http->preventStrayRequests();Http::swap($http);$reports=[];$fail=$lostReport;
        $http->fake(function($request)use($http,$event,$lease,$status,$connection,$recovery,$editOnClaim,&$reports,&$fail){
            $path=parse_url($request->url(),PHP_URL_PATH);$query=parse_url($request->url(),PHP_URL_QUERY);parse_str($query??'',$q);
            if(str_ends_with($path,'/connection.json'))$v=$connection;
            elseif(str_ends_with($path,'/policy.json'))$v=$connection->policy;
            elseif(str_ends_with($path,'/attention-recovery.json'))$v=(object)['protocol_revision'=>'attention-recovery-1','candidate_id'=>'discussionbridge-v4-core-20261025-r6','inspection_profiles'=>['statamic-flat-1','statamic-db-1','statamic-ssg-1']];
            elseif(str_ends_with($path,'/attention-inspections.json'))$v=(object)['items'=>(($q['after']??'0')==='0'?[$this->inspection]:[]),'next_after'=>1,'has_more'=>false];
            elseif(str_ends_with($path,'/report.json')){$reports[]=$request->body();$actual=json_decode($request->body())->observation;if($event->mode==='settle_restored_baseline')$event->verification=$actual->verification;if($fail){$fail=false;throw new ConnectionException('Synthetic lost report');}$v=(object)['inspection_id'=>$this->inspection->inspection_id,'context_sha256'=>$this->inspection->context_sha256,'status'=>'ready','issue'=>'none','observed_at'=>$actual->observed_at];}
            elseif(str_ends_with($path,'/work/recoveries.json'))$v=(object)['items'=>($recovery&&($q['after']??'0')==='0'?[$event]:[]),'next_after'=>$recovery?1:0,'has_more'=>false];
            elseif(str_ends_with($path,'/claim.json')&&str_contains($path,'/recoveries/')){if($editOnClaim)$this->writer->nativeRevision='sha256:'.str_repeat('d',64);$v=$lease;}
            elseif(preg_match('~/work/[a-f0-9-]+\.json$~',$path))$v=$status;
            else throw new \RuntimeException('Unexpected controlled route '.$path);
            return $http->response(json_encode($v),200,['Content-Type'=>'application/json']);
        });
        return [$http,$event,$lease,function()use(&$reports){return $reports;}];
    }
    private function recovery(?NativeAttentionInspector $inspector=null):AttentionRecovery
    {
        return new AttentionRecovery($this->client,$this->store,$inspector??$this->inspector(),new class extends NativePolicy{public function __construct(){}public function authorize(stdClass $c,stdClass $p):void{}});
    }
    public function test_report_only_has_no_claim_or_old_state_settlement():void
    {
        [$http]=$this->wire();$r=$this->recovery();$s=$r->check();$this->assertSame(1,$s['reported']);$this->assertSame(0,$s['discovered']);
        $http->assertNotSent(fn($q)=>str_contains($q->url(),'/claim.json')||str_contains($q->url(),'/recoveries.json'));
        $this->assertSame($this->state['mappings'],$this->store->read()['mappings']);
    }
    public function test_lost_report_replays_exact_body_and_mutation_before_cursor():void
    {
        [$http,$event,$lease,$bodies]=$this->wire(false,true);$r=$this->recovery();
        try{$r->check();$this->fail('Expected uncertainty');}catch(CmsException){}
        $this->assertArrayNotHasKey('attention_inspection_after',$this->store->read());$r->check();
        $this->assertCount(2,$bodies());$this->assertSame($bodies()[0],$bodies()[1]);$this->assertSame(1,$this->store->read()['attention_inspection_after']);
    }
    public function test_same_source_discovery_and_exact_target_claim_preserve_predecessor_identity():void
    {
        [$http,$event]=$this->wire();$r=$this->recovery();$state=$this->store->read();$r->poll($state);
        $this->assertSame($this->inspection->work->source_revision,$event->work->source_revision);
        $this->assertSame(1,$state['attention_recovery_after']);$lease=$r->claim($state,new ReplayJournal($this->client,$this->store));
        $this->assertSame($event->work->work_id,$lease->work->work_id);$this->assertSame('owned-entry',$state['mappings'][$event->mapping->mapping_id]['native_id']);
        $this->assertSame($this->state['mappings'][$event->mapping->mapping_id],$state['attention_recoveries'][$event->recovery_id]['predecessor_binding']);
        $http->assertNotSent(fn($q)=>str_contains($q->url(),'/sources.json')||str_contains($q->url(),'/work/claim.json'));
    }
    public function test_wrong_successor_lease_cannot_install_handoff():void
    {
        $this->wire(true,false,true);$r=$this->recovery();$state=$this->store->read();$r->poll($state);
        try{$r->claim($state,new ReplayJournal($this->client,$this->store));$this->fail('Wrong lease accepted');}catch(CmsException $e){$this->assertSame('identity_conflict',$e->failureCode);}
        $this->assertArrayNotHasKey('attention_active',$state);
    }
    public function test_native_edit_after_exact_lease_stops_before_handoff_or_write():void
    {
        $this->wire(true,false,false,true);$r=$this->recovery();$state=$this->store->read();$r->poll($state);
        try{$r->claim($state,new ReplayJournal($this->client,$this->store));$this->fail('Stale inspection accepted');}catch(CmsException $e){$this->assertSame('native_ownership_conflict',$e->failureCode);}
        $this->assertArrayNotHasKey('attention_active',$state);
    }
    public function test_durable_active_handoff_replays_same_lease_after_uncertain_successor_native_write():void
    {
        [$http]=$this->wire();$r=$this->recovery();$state=$this->store->read();$r->poll($state);$journal=new ReplayJournal($this->client,$this->store);
        $first=$r->claim($state,$journal);$this->writer->nativeRevision='sha256:'.str_repeat('e',64); // Subsequent ordinary write/readback owns this change; never reapply the old baseline gate.
        $again=$r->claim($state,$journal);$this->assertSame($first->work->work_id,$again->work->work_id);$this->assertSame($first->lease_token,$again->lease_token);
        $this->assertSame(1,count(array_filter($http->recorded()->all(),fn($p)=>str_contains($p[0]->url(),'/recoveries/')&&str_ends_with($p[0]->url(),'/claim.json'))));
    }
    public function test_actual_worker_uses_positive_recovery_before_normal_mapping_claim():void
    {
        [$http,$event]=$this->wire();Container::getInstance()->instance(\Statamic\Contracts\Entries\EntryRepository::class,new stdClass);
        $policy=new class extends NativePolicy{public function __construct(){}public function authorize(stdClass $c,stdClass $p):void{}};
        $static=(new \ReflectionClass(StaticCoordinator::class))->newInstanceWithoutConstructor();
        $worker=new DynamicWorker($this->client,$this->store,$policy,$this->writer,(new \ReflectionClass(OwnedFragments::class))->newInstanceWithoutConstructor(),
            (new \ReflectionClass(PublicVerifier::class))->newInstanceWithoutConstructor(),$static,$this->recovery());
        $worker->run(1);$this->assertSame('handed_off',$this->store->read()['attention_recoveries'][$event->recovery_id]['status']);
        $http->assertSent(fn($q)=>str_contains($q->url(),'/recoveries/'.$event->recovery_id.'/claim.json'));
        $http->assertNotSent(fn($q)=>str_contains($q->url(),'/work/claim.json'));
    }
    public function test_actual_container_resolution_includes_registered_recovery_and_command_observer():void
    {
        $app=Container::getInstance();$app->instance(CmsClient::class,$this->client);$app->instance(PrivateState::class,$this->store);$app->instance(NativeWriter::class,$this->writer);
        $app->instance(NativePolicy::class,new class extends NativePolicy{public function __construct(){}});
        foreach([OwnedFragments::class,PublicVerifier::class,StaticArtifact::class,StaticCoordinator::class]as$class)$app->instance($class,(new \ReflectionClass($class))->newInstanceWithoutConstructor());
        $provider=new \CodeWorksLabs\DiscussionBridgeStatamic\ServiceProvider($app);
        if(method_exists($provider,'registerRecoveryServices'))(new \ReflectionMethod($provider,'registerRecoveryServices'))->invoke($provider);
        $worker=$app->make(DynamicWorker::class);$recovery=(new \ReflectionProperty($worker,'recovery'))->getValue($worker);
        $this->assertInstanceOf(AttentionRecovery::class,$recovery);
        $inspector=(new \ReflectionProperty($recovery,'inspector'))->getValue($recovery);
        $this->assertInstanceOf(LocalStaticHooks::class,(new \ReflectionProperty($inspector,'hooks'))->getValue($inspector));
    }
    public function test_actual_pre_copy_r5_pending_body_and_uuid_replay_unchanged_with_r6_pair():void
    {
        $saved=json_decode(ltrim(file_get_contents(__DIR__.'/fixtures/attention-r5-pending.json'),"\xef\xbb\xbf"));$prior=json_decode(json_encode($saved->mutation),true);
        $state=$this->store->read();$state['mutations']['r5-existing']=$prior;$this->store->save($state);
        $f=json_decode(ltrim(file_get_contents(__DIR__.'/fixtures/retry-author-fixtures.json'),"\xef\xbb\xbf"));foreach($f->fixtures as $item)if($item->id==='resolve_created')$created=$item->value;
        $http=new Factory;$http->preventStrayRequests();Http::swap($http);$http->fake(fn()=> $http->response(json_encode($created),201,['Content-Type'=>'application/json']));
        $payload=$this->client->codec()->parseJson($prior['body']);unset($payload->mutation_id);
        (new ReplayJournal($this->client,$this->store))->mutate($state,'r5-existing','V4-02',[],$payload);
        $http->assertSent(fn($q)=>$q->body()===$prior['body']);$this->assertSame($prior['body'],$state['mutations']['r5-existing']['body']);
        $this->assertSame($prior['mutation_id'],$state['mutations']['r5-existing']['mutation_id']);
    }

    private function staticBaseline():StaticArtifact
    {
        config(['discussionbridge.cms_profile'=>'statamic_ssg','statamic.ssg.destination'=>'/synthetic/ssg']);
        $id='11111111-1111-4111-8111-000000000081';$map=$this->inspection->mapping;
        $map->publicly_verified_source_revision=$this->inspection->source->source_revision;
        $map->synchronized_source_revision=$this->inspection->source->source_revision;
        $this->inspection->verified_native_result=clone $this->inspection->native_result;
        $binding=$this->state['mappings'][$map->mapping_id];$binding['last_verified']='retained full old proof';
        $artifact=['directory'=>'/synthetic/retained','files'=>['owned/index.html'=>['bytes'=>8,'sha256'=>hash('sha256','baseline')]],'sha256'=>hash('sha256','manifest')];
        $this->state['mappings'][$map->mapping_id]['static_transaction']=$id;
        $this->state['static'][$id]=['state'=>'restore_required','work_id'=>$this->inspection->work->work_id,
            'snapshot'=>['exists'=>true,'binding'=>$binding,'revision'=>$this->writer->nativeRevision,'path'=>'/synthetic/entry.md','bytes'=>base64_encode('baseline')],
            'previous_artifact'=>$artifact,'commands'=>[]];
        foreach(['mapping_id','source_id','source_revision','policy_revision','action']as$field)$this->state['static'][$id][$field]=$this->inspection->work->$field;
        $this->state['static'][$id]['prepared_at']=$this->inspection->created_at;$this->state['static'][$id]['native_result']=$this->client->codec()->encodeJson($this->inspection->native_result);
        $this->inspection->prepared_transaction=(object)['transaction_id'=>$id,'work_id'=>$this->inspection->work->work_id,
            'lease_generation'=>$this->inspection->work->lease_generation,'action'=>$this->inspection->work->action,'source_revision'=>$this->inspection->work->source_revision,
            'policy_revision'=>$this->inspection->work->policy_revision,'native_result'=>$this->inspection->native_result,'prepared_at'=>$this->inspection->created_at,'deployment_id'=>null,'deployed_at'=>null];
        $authority=(object)['work_id'=>$this->inspection->work->work_id,'lease_token'=>$this->fixtures['lease']->lease_token,
            'lease_generation'=>$this->inspection->work->lease_generation,'source_revision'=>$this->inspection->work->source_revision,'policy_revision'=>$this->inspection->work->policy_revision];
        $body=(object)['mutation_id'=>'11111111-1111-4111-8111-000000000083','authority'=>$authority,'transaction_id'=>$id,'native_result'=>$this->inspection->native_result,'prepared_at'=>$this->inspection->created_at];
        $ack=(object)['work'=>clone $this->inspection->work,'mapping'=>$this->inspection->mapping,'lease_expires_at'=>$this->fixtures['lease']->expires_at];$ack->work->state='synchronized';
        $this->state['mutations']['static-prepare:'.$id.':'.$this->inspection->work->lease_generation]=['route'=>'V4-18','path'=>['work_id'=>$this->inspection->work->work_id],
            'body'=>$this->client->codec()->encodeJson($body),'result'=>$this->client->codec()->encodeJson($ack),'http_status'=>200];
        $key=hash('sha256',$map->source_id."\0".$this->inspection->source->source_revision);$this->state['sources'][$key]=$this->client->codec()->encodeJson($this->inspection->source);
        $this->state['attention_restorations']['11111111-1111-4111-8111-000000000082']=['transaction_id'=>$id,'state'=>'restored'];
        return new class($artifact) extends StaticArtifact {
            public function __construct(private array $fixture){}public function assertCapture(array $capture):void{if($capture!==$this->fixture)throw new CmsException('native_ownership_conflict');}
            public function inspect(string $directory):array{return $this->fixture;}
        };
    }
    private function neverApplied():void
    {
        $mapping=$this->inspection->mapping;$mapping->destination_native_id=null;
        $this->inspection->native_result=null;$this->inspection->verified_native_result=null;$this->inspection->prepared_transaction=null;
        $this->writer->entry=null;$this->state['mappings'][$mapping->mapping_id]=['mapping'=>$this->client->codec()->encodeJson($mapping),'native_id'=>'owned-entry'];
        \Statamic\Facades\Entry::swap(new class{public function findByUri($uri,$site=null){return null;}public function query(){return new class{public function where($f,$id){return $this;}public function limit($n){return $this;}public function get(){return collect([]);}};}});
        \Statamic\Facades\Site::swap(new class{public function default(){return new class{public function handle(){return 'default';}};}});
    }
    public function test_initial_never_applied_is_positive_locked_absence_and_does_not_invent_native_id():void
    {
        $this->neverApplied();$o=$this->inspector()->inspect($this->state,$this->inspection);
        $this->assertSame('never_applied',$o->native_state);$this->assertFalse($o->object_exists);$this->assertNull($o->native_id);$this->assertNotNull($o->current_sha256);
        unset($this->state['mappings'][$this->inspection->mapping->mapping_id]['native_id']);
        $o=$this->inspector()->inspect($this->state,$this->inspection);$this->assertSame('never_applied',$o->native_state);$this->assertNull($o->native_id);
        $this->state['mappings'][$this->inspection->mapping->mapping_id]['receipt']='old-known-receipt';
        $o=$this->inspector()->inspect($this->state,$this->inspection);$this->assertSame('conflict',$o->native_state);
    }
    public function test_static_restored_baseline_needs_actual_native_artifact_and_fresh_public_comparison():void
    {
        $artifact=$this->staticBaseline();$before=serialize($this->state);$o=$this->inspector($artifact)->inspect($this->state,$this->inspection);
        $this->client->codec()->validate('AttentionObservation',$o);$this->assertSame('restored_baseline',$o->native_state);$this->assertNotNull($o->verification);
        $this->assertSame($o->current_sha256,$o->baseline_sha256);$this->assertSame($before,serialize($this->state));
        $bad=new class extends PublicVerifier{public function __construct(){}public function inspect(stdClass $m,?stdClass $s,stdClass $r,array $e,string $n):stdClass{throw new CmsException('public_verification_failed');}};
        $o=$this->inspector($artifact,$bad)->inspect($this->state,$this->inspection);$this->assertSame('unknown',$o->native_state);$this->assertSame('public_proof_unavailable',$o->issue);
        unset($this->state['mutations']['static-prepare:'.$this->inspection->prepared_transaction->transaction_id.':'.$this->inspection->work->lease_generation]);
        $o=$this->inspector($artifact)->inspect($this->state,$this->inspection);$this->assertSame('unknown',$o->native_state);$this->assertSame('baseline_missing',$o->issue);
    }
    public function test_static_active_or_uncertain_command_cannot_be_a_readiness_probe():void
    {
        $artifact=$this->staticBaseline();$id=$this->inspection->prepared_transaction->transaction_id;
        $this->state['static'][$id]['commands']['deploy']=['state'=>'uncertain'];$o=$this->inspector($artifact)->inspect($this->state,$this->inspection);
        $this->assertSame('uncertain',$o->native_command);$this->assertSame('unknown',$o->native_state);$this->assertSame('uncertain_command',$o->issue);
    }
    public function test_static_exact_settlement_handoff_preserves_old_transaction_snapshot():void
    {
        $artifact=$this->staticBaseline();$this->store->save($this->state);[$http,$event]=$this->wire();
        $event->mode='settle_restored_baseline';$event->native_baseline=$this->inspection->verified_native_result;
        $r=$this->recovery($this->inspector($artifact));$state=$this->store->read();$r->poll($state);
        $old=$state['static'][$this->inspection->prepared_transaction->transaction_id];$lease=$r->claim($state,new ReplayJournal($this->client,$this->store));
        $this->assertSame($event->work->work_id,$lease->work->work_id);
        $this->assertSame('settled_for_retry',$state['static'][$this->inspection->prepared_transaction->transaction_id]['state']);
        $this->assertSame($old,$state['attention_recoveries'][$event->recovery_id]['predecessor_static']);
        $this->assertSame($old['snapshot'],$state['static'][$this->inspection->prepared_transaction->transaction_id]['snapshot']);
    }
    public function test_actual_collector_exports_closed_core_interop_cases():void
    {
        $cases=[];$original=$this->state;$inspection=unserialize(serialize($this->inspection));
        foreach(['statamic_flat','statamic_db']as$profile){
            config(['discussionbridge.cms_profile'=>$profile]);$this->state=$original;$this->inspection=unserialize(serialize($inspection));
            $o=$this->inspector()->inspect($this->state,$this->inspection);$cases[]=['id'=>$profile.'-candidate','profile'=>$profile,'publication_kind'=>'dynamic','inspection'=>$this->inspection,'observation'=>$o,'expected_status'=>'ready'];
            $this->inspection=unserialize(serialize($inspection));$this->inspection->verified_native_result=clone $this->inspection->native_result;
            $this->inspection->mapping->synchronized_source_revision=$this->inspection->source->source_revision;
            $this->inspection->mapping->publicly_verified_source_revision=$this->inspection->source->source_revision;
            $o=$this->inspector()->inspect($this->state,$this->inspection);$cases[]=['id'=>$profile.'-baseline','profile'=>$profile,'publication_kind'=>'dynamic','inspection'=>$this->inspection,'observation'=>$o,'expected_status'=>'ready'];
            $this->state['mutations']['unknown']=['route'=>'V4-16','path'=>['work_id'=>$this->inspection->work->work_id],'result'=>null];
            $o=$this->inspector()->inspect($this->state,$this->inspection);$cases[]=['id'=>$profile.'-uncertain','profile'=>$profile,'publication_kind'=>'dynamic','inspection'=>$this->inspection,'observation'=>$o,'expected_status'=>'not_ready'];
        }
        $this->state=$original;$this->inspection=unserialize(serialize($inspection));$artifact=$this->staticBaseline();
        $o=$this->inspector($artifact)->inspect($this->state,$this->inspection);$cases[]=['id'=>'statamic-ssg-restored','profile'=>'statamic_ssg','publication_kind'=>'static','inspection'=>$this->inspection,'observation'=>$o,'expected_status'=>'ready'];
        $this->state['static'][$this->inspection->prepared_transaction->transaction_id]['commands']['deploy']=['state'=>'uncertain'];
        $o=$this->inspector($artifact)->inspect($this->state,$this->inspection);$cases[]=['id'=>'statamic-ssg-uncertain','profile'=>'statamic_ssg','publication_kind'=>'static','inspection'=>$this->inspection,'observation'=>$o,'expected_status'=>'not_ready'];
        foreach(['statamic_flat','statamic_db','statamic_ssg']as$profile){config(['discussionbridge.cms_profile'=>$profile]);$this->state=$original;$this->inspection=unserialize(serialize($inspection));$this->neverApplied();
            $o=$this->inspector()->inspect($this->state,$this->inspection);$cases[]=['id'=>$profile.'-never-applied','profile'=>$profile,'publication_kind'=>$profile==='statamic_ssg'?'static':'dynamic','inspection'=>$this->inspection,'observation'=>$o,'expected_status'=>'ready'];}
        foreach($cases as$c){$this->client->codec()->validate('AttentionInspection',$c['inspection']);$this->client->codec()->validate('AttentionObservation',$c['observation']);$this->assertNotEmpty($c['observation']->journal_sha256);}
        $export=getenv('DISCUSSIONBRIDGE_STATAMIC_CORE_EXPORT');
        if($export){$expected='C:/CodeProjects/Products/DiscussionBridge/planning/v4-attention-recovery-fixes-20261025/STATAMIC_CORE_INTEROP.json';
            $this->assertSame($expected,str_replace('\\','/',$export));file_put_contents($export,json_encode($cases,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}
    }
    public function test_native_owner_restore_requires_explicit_apply_and_deploy_and_keeps_old_work():void
    {
        $this->writer->nativeRevision='sha256:'.hash('sha256','baseline');$this->inspection->native_result->native_revision=$this->writer->nativeRevision;
        $binding=&$this->state['mappings'][$this->inspection->mapping->mapping_id];$binding['native_revision']=$this->writer->nativeRevision;$binding['result']=$this->client->codec()->encodeJson($this->inspection->native_result);unset($binding);
        $artifact=$this->staticBaseline();unset($this->state['attention_restorations']);
        $this->state['attention_inspections'][$this->inspection->inspection_id]=['request'=>$this->client->codec()->encodeJson($this->inspection)];$this->store->save($this->state);
        $connection=clone $this->fixtures['connection'];$connection->profile='statamic_ssg';$connection->destination_origin='https://publisher.example';
        $status=(object)['work'=>$this->inspection->work,'mapping'=>$this->inspection->mapping,'lease_expires_at'=>null];
        $http=new Factory;$http->preventStrayRequests();Http::swap($http);$http->fake(function($q)use($http,$connection,$status){$path=parse_url($q->url(),PHP_URL_PATH);
            $v=str_ends_with($path,'/connection.json')?$connection:(str_ends_with($path,'/policy.json')?$connection->policy:$status);return $http->response(json_encode($v),200,['Content-Type'=>'application/json']);});
        $hooks=new class extends LocalStaticHooks{public int $calls=0;public function __construct(){}public function observe(string $id,array $tx):string{return empty($tx['commands'])?'none':'settled';}
            public function restoreFor(string $id,array &$tx,callable $persist,callable $heartbeat):array{$this->calls++;$tx['commands']['restore']=['state'=>'reconciled'];$persist();return ['deployment_id'=>'actual-controlled-restore','deployed_at'=>gmdate('Y-m-d\TH:i:s').'.000Z'];}};
        $policy=new class extends NativePolicy{public function __construct(){}public function authorize(stdClass $c,stdClass $p):void{}};
        $restorer=new AttentionBaselineRestorer($this->client,$this->store,$this->writer,$this->inspector($artifact),$hooks,$artifact,$policy);
        $preview=$restorer->restore($this->inspection->inspection_id);$this->assertSame('preview',$preview['state']);$this->assertSame(0,$hooks->calls);
        try{$restorer->restore($this->inspection->inspection_id,true,false);$this->fail('Implicit deploy permitted');}catch(CmsException){}
        $result=$restorer->restore($this->inspection->inspection_id,true,true);$this->assertSame('restored',$result['state']);$this->assertSame(1,$hooks->calls);
        $again=$restorer->restore($this->inspection->inspection_id,true,true);$this->assertSame($result['restoration_operation_id'],$again['restoration_operation_id']);$this->assertSame(1,$hooks->calls);
        $this->assertSame('restore_required',$this->store->read()['static'][$this->inspection->prepared_transaction->transaction_id]['state']);
        $http->assertNotSent(fn($q)=>$q->method()==='POST');
    }
}
class RecoveryState extends PrivateState
{
    public int $saves=0;
    public function __construct(private array $fixture) {}
    public function locked(Closure $operation): mixed { return $operation(); }
    public function read(): array { return unserialize(serialize($this->fixture)); }
    public function save(array $state): void {$this->saves++;$this->fixture=unserialize(serialize($state));}
}
class RecoveryReadWriter extends NativeWriter
{
    public array $duplicates=[];public ?RecoveryEntry $entry=null;public string $nativeRevision='sha256:cccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccccc';
    public function freshEntry(string $id){return $this->entry;}
    public function revision($entry):string{return $this->nativeRevision;}
    public function stateDigest($entry):string{return hash('sha256',json_encode($entry->fields));}
    public function restoreStatic(array &$state,string $mappingId,array $snapshot):bool{$before=$state['mappings'][$mappingId];$state['mappings'][$mappingId]=[...$snapshot['binding'],'static_transaction'=>$before['static_transaction']];return true;}
}
class RecoveryEntry
{
    public function __construct(private string $identity,private string $url,public array $fields){}
    public function id(){return $this->identity;}public function absoluteUrl(){return $this->url;}public function get($key){return $this->fields[$key]??null;}
}
