<?php
namespace CodeWorksLabs\DiscussionBridgeStatamic\Tests;

use Closure;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\{AttentionRecovery,CmsClient,CmsException,NativeAttentionInspector,NativePolicy,NativeWriteGuard,NativeWriter,OwnedFragments,PrivateState,PublicVerifier,ReplayJournal,StaticArtifact};
use CodeWorksLabs\DiscussionBridgeStatamic\Support\{Configuration,TemplateIntegration};
use CodeWorksLabs\DiscussionBridgeStatamic\Publication\{NativePublication,PublicationFreshness};
use CodeWorksLabs\DiscussionBridgeStatamic\Presentation\{HtmlSanitizer,PresentationChrome};
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Http\Client\Factory;
use Illuminate\Support\Facades\{Facade,Http};
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/** Real collector, native predicates/file hashes, artifact reader, anonymous verifier and native apply; controlled repository/commit/HTTP seams, no installed qualification. */
class AttentionWithdrawnBaselineTest extends TestCase
{
    private const EVIDENCE='C:/CodeProjects/Products/DiscussionBridge/planning/v4-attention-recovery-fixes-20261025';
    private string $root;private string $native;private string $generated;private string $retained;private array $created=[];
    private Configuration $configuration;private CmsClient $client;private WithdrawnState $store;private WithdrawnReader $writer;private NativeAttentionInspector $inspector;
    private stdClass $inspection;private stdClass $connection;private array $state;private Factory $http;private int $publicStatus=404;
    private string $tx='11111111-1111-4111-8111-000000000084';private string $baselineBytes;

    protected function setUp():void
    {
        $this->root=self::EVIDENCE.'/STATAMIC_WITHDRAWN_FIXTURE_'.bin2hex(random_bytes(8));
        if(file_exists($this->root)||!mkdir($this->root,0700))throw new \RuntimeException('Exclusive fixture creation failed');
        $this->created[]=$this->root;$this->generated=$this->root.'/generated';$this->retained=$this->root.'/retained';
        foreach([$this->generated,$this->retained]as$dir){if(!mkdir($dir,0700))throw new \RuntimeException('Fixture directory failed');$this->created[]=$dir;}
        foreach([$this->generated.'/index.html',$this->retained.'/index.html']as$file)$this->writeOwned($file,'<p>Unrelated generated homepage.</p>');
        $app=new Container;Container::setInstance($app);Facade::setFacadeApplication($app);
        $app->instance('config',new Repository(['discussionbridge'=>['enabled'=>true,'cms_profile'=>'statamic_ssg','forum_url'=>'https://forum.example','site_origin'=>'https://publisher.example','connection_id'=>'dbc_111111111111111111111111'],
            'statamic'=>['ssg'=>['destination'=>$this->generated]]]));
        $this->configuration=new class extends Configuration{public function secret():string{return str_repeat('a',43);}};$this->client=new CmsClient($this->configuration);$c=$this->client->codec();
        $f=[];foreach(json_decode(ltrim(file_get_contents(__DIR__.'/fixtures/attention-author-fixtures.json'),"\xef\xbb\xbf"))->fixtures as$item)$f[$item->id]=$item->value;
        $work=clone$f['lease']->work;$work->state='attention';$work->attempt=3;$work->lease_generation=4;$work->retry_at=null;$work->last_failure_code='public_verification_failed';
        $source=clone$f['initial_feed']->items[0]->source;$source->source_id=$work->source_id;$source->source_revision=$work->source_revision;
        $source->topic_url='https://forum.example/t/'.$source->topic_id;
        $body='<p>New restored-baseline continuation.</p>';$source->content_bytes=strlen($body);$source->content_sha256=hash('sha256',$body);
        $this->writeOwned($this->root.'/new-source.html',$body);
        $mapping=clone$f['mapping'];$mapping->mapping_id=$work->mapping_id;$mapping->source_id=$work->source_id;$mapping->canonical_url='https://publisher.example/owned';$mapping->destination_native_id='withdrawn-entry';
        $mapping->presentation_mode='full';$mapping->mapping_state='attention';$mapping->publicly_verified_source_revision=null;$mapping->synchronized_source_revision=$work->source_revision;
        $receipt=clone$f['native_ownership_receipt'];foreach(['mapping_id','resource_id','connection_id','source_id','external_id','canonical_url']as$key)$receipt->$key=$mapping->$key;
        $receipt->native_id='withdrawn-entry';$receipt->source_revision='prior-published-r1';$receipt->last_lease_generation=2;$receipt->forum_origin='https://forum.example';$receipt->move_id=null;$receipt->proof_nonce=null;
        $receiptHash=$c->nativeOwnershipReceiptSha256($receipt);
        $fields=['published'=>false,'content'=>'<p>Retained private prior publication.</p>','discussionbridge_cms_discussion_html'=>'<div>Retained discussion</div>',
            'discussionbridge_cms_owned'=>true,'discussionbridge_cms_mapping_id'=>$mapping->mapping_id,'discussionbridge_resource_id'=>$mapping->resource_id,
            'discussionbridge_cms_receipt_sha256'=>$receiptHash,'discussionbridge_cms_generation'=>2,'discussionbridge_source_revision'=>'prior-published-r1',
            'discussionbridge_source_status'=>'withdrawn','discussionbridge_mode'=>'full'];
        $this->native=$this->root.'/owned-entry.json';$this->baselineBytes=json_encode($fields,JSON_THROW_ON_ERROR);$this->writeOwned($this->native,$this->baselineBytes);
        $baseline=(object)['kind'=>'withdraw','native_id'=>'withdrawn-entry','external_id'=>$mapping->external_id,'canonical_url'=>$mapping->canonical_url,
            'native_revision'=>'sha256:'.hash_file('sha256',$this->native),'ownership_receipt_sha256'=>$receiptHash];
        $proof=(object)['kind'=>'absent','url'=>$mapping->canonical_url,'native_id'=>'withdrawn-entry','checked_at'=>gmdate('Y-m-d\TH:i:s',time()-3600).'.000Z','http_status'=>404];
        $candidate=(object)['kind'=>'publish_revision','native_id'=>'withdrawn-entry','external_id'=>$mapping->external_id,'canonical_url'=>$mapping->canonical_url,
            'native_revision'=>'candidate-r2','ownership_receipt_sha256'=>str_repeat('d',64),'representation'=>(object)['kind'=>'full','source_content_sha256'=>$source->content_sha256]];
        $mapping->destination_native_revision=$candidate->native_revision;
        $binding=['mapping'=>$c->encodeJson($mapping),'native_id'=>'withdrawn-entry','native_revision'=>$baseline->native_revision,'receipt'=>$c->encodeJson($receipt),'generation'=>2,'seen_generation'=>4,
            'work_id'=>'11111111-1111-4111-8111-000000000085','source_revision'=>null,'result'=>$c->encodeJson($baseline),'last_verified'=>$c->encodeJson($proof)];
        $this->store=new WithdrawnState;$policy=new class extends NativePolicy{public function __construct(){}public function authorize(stdClass $c,stdClass $p):void{}};
        $freshness=new class extends PublicationFreshness{public function __construct(){}public function refresh($entry,string $resourceId):void{}};
        $guard=new class extends NativeWriteGuard{public function __construct(){}public function locked(string $id,Closure $op):mixed{return $op();}public function assertRepositoryFence():void{}public function capture($entry):void{}public function stage($entry):void{}};
        $this->writer=new WithdrawnReader($this->configuration,$this->client,$this->store,$policy,$guard,$freshness,$this->native,$mapping->canonical_url,$this->root);
        \Statamic\Facades\Entry::swap(new class($this->writer){public function __construct(private WithdrawnReader $reader){}public function query(){return new WithdrawnQuery($this->reader);}public function findByUri($uri,$site=null){return $this->reader->freshEntry('withdrawn-entry');}});
        $fragments=new class extends OwnedFragments{public function expected($entry):array{return ['article'=>$entry->get('content'),'discussion'=>$entry->get('discussionbridge_cms_discussion_html')];}};
        $verifier=new PublicVerifier($this->configuration,$fragments);$artifact=new StaticArtifact($this->store,$verifier);
        $this->inspector=new NativeAttentionInspector($this->client,$this->writer,$fragments,$verifier,$artifact);
        $this->inspection=(object)['sequence'=>1,'inspection_id'=>'11111111-1111-4111-8111-000000000086','context_sha256'=>str_repeat('a',64),'work'=>$work,'mapping'=>$mapping,'source'=>$source,
            'native_result'=>$candidate,'verified_native_result'=>$baseline,'verification'=>$proof,'prepared_transaction'=>(object)['transaction_id'=>$this->tx,'work_id'=>$work->work_id,'lease_generation'=>4,'action'=>$work->action,
                'source_revision'=>$work->source_revision,'policy_revision'=>$work->policy_revision,'native_result'=>$candidate,'prepared_at'=>gmdate('Y-m-d\TH:i:s',time()-30).'.000Z','deployment_id'=>null,'deployed_at'=>null],
            'created_at'=>gmdate('Y-m-d\TH:i:s',time()-20).'.000Z'];
        $tx=['state'=>'restore_required','work_id'=>$work->work_id,'mapping_id'=>$mapping->mapping_id,'source_id'=>$work->source_id,'source_revision'=>$work->source_revision,'policy_revision'=>$work->policy_revision,'action'=>$work->action,
            'prepared_at'=>$this->inspection->prepared_transaction->prepared_at,'native_result'=>$c->encodeJson($candidate),'commands'=>[],
            'snapshot'=>['exists'=>true,'binding'=>$binding,'path'=>$this->native,'bytes'=>base64_encode($this->baselineBytes),'revision'=>$baseline->native_revision], 'previous_artifact'=>$artifact->inspect($this->retained)];
        $binding['static_transaction']=$this->tx;
        $authority=(object)['work_id'=>$work->work_id,'lease_token'=>$f['lease']->lease_token,'lease_generation'=>4,'source_revision'=>$work->source_revision,'policy_revision'=>$work->policy_revision];
        $input=(object)['mutation_id'=>'11111111-1111-4111-8111-000000000087','authority'=>$authority,'transaction_id'=>$this->tx,'native_result'=>$candidate,'prepared_at'=>$tx['prepared_at']];
        $ack=(object)['work'=>clone$work,'mapping'=>$mapping,'lease_expires_at'=>$f['lease']->expires_at];$ack->work->state='synchronized';
        $this->state=['version'=>1,'binding'=>null,'events'=>[],'sources'=>[],'mappings'=>[$mapping->mapping_id=>$binding],'work'=>[],'static'=>[$this->tx=>$tx],
            'mutations'=>['static-prepare:'.$this->tx.':4'=>['route'=>'V4-18','path'=>['work_id'=>$work->work_id],'body'=>$c->encodeJson($input),'result'=>$c->encodeJson($ack),'http_status'=>200]]];
        $this->store->save($this->state);
        $this->connection=clone$f['connection'];$this->connection->profile='statamic_ssg';$this->connection->destination_origin='https://publisher.example';$this->connection->policy->publication_kind='static';
        $this->connection->policy->presentation_mode='full';$this->connection->policy->author_ids=[];$this->connection->policy->taxonomy_ids=[];$this->connection->policy->policy_revision=$work->policy_revision;
        $this->connection->native_capabilities->publication_kind='static';
        $app->instance(NativePublication::class,new NativePublication($this->configuration));$app->instance(HtmlSanitizer::class,new HtmlSanitizer);$app->instance(PresentationChrome::class,new PresentationChrome);
        $app->instance(TemplateIntegration::class,new class extends TemplateIntegration{public function verify(string $template,string $direction,string $mode):void{if($template!=='fixture-owned'||$direction!=='from_discourse'||$mode!=='full')throw new \RuntimeException('Unexpected native template selection');}});
        $this->http=new Factory;$this->http->preventStrayRequests();Http::swap($this->http);
        $this->http->fake(fn($q)=>$this->http->response('Not found',$this->publicStatus,['Content-Type'=>'text/html; charset=UTF-8']));
    }
    private function writeOwned(string $path,string $bytes):void{if(!str_starts_with(str_replace('\\','/',$path),$this->root.'/'))throw new \RuntimeException('Fixture path escaped');if(!file_exists($path))$this->created[]=$path;file_put_contents($path,$bytes);}
    protected function tearDown():void
    {
        Facade::clearResolvedInstances();Facade::setFacadeApplication(null);Container::setInstance(null);
        $resolved=realpath($this->root);$base=realpath(self::EVIDENCE);
        if($resolved===false||$base===false||!str_starts_with(str_replace('\\','/',$resolved),str_replace('\\','/',$base).'/STATAMIC_WITHDRAWN_FIXTURE_'))throw new \RuntimeException('Refuse unverified fixture cleanup');
        foreach(array_reverse($this->created)as$path){if(is_link($path))throw new \RuntimeException('Unexpected fixture link');if(!file_exists($path))continue;$actual=realpath($path);
            if($actual===false||($actual!==$resolved&&!str_starts_with(str_replace('\\','/',$actual),str_replace('\\','/',$resolved).'/')))throw new \RuntimeException('Cleanup escaped owned root');if(is_dir($path)){if(!rmdir($path))throw new \RuntimeException('Owned fixture residue');}else{if(!unlink($path))throw new \RuntimeException('Owned fixture file residue');}}
        $log=self::EVIDENCE.'/STATAMIC_WITHDRAWN_FIXTURE_CLEANUP.json';$records=is_file($log)?json_decode(file_get_contents($log),true):[];$records[]=['root'=>$resolved,'test'=>$this->name(),'created_members'=>count($this->created),'removed'=>!file_exists($this->root)];file_put_contents($log,json_encode($records,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
    }
    private function observe(?stdClass $i=null,?array $state=null):stdClass{return $this->inspector->inspect($state??$this->state,$i??$this->inspection);}
    private function baselineCase():array{$i=unserialize(serialize($this->inspection));$s=$this->state;$i->prepared_transaction=null;$i->native_result=clone$i->verified_native_result;$i->mapping->synchronized_source_revision=null;$i->mapping->destination_native_revision=$i->verified_native_result->native_revision;$s['static'][$this->tx]['state']='aborted';$s['static'][$this->tx]['native_restored']=true;return[$i,$s];}
    public function test_retained_withdrawal_baseline_and_restored_case_keep_receipt_and_fresh_absence_facts():void
    {
        foreach([[$this->inspection,$this->state,'restored_baseline'],[...$this->baselineCase(),'baseline']]as[$i,$s,$expected]){
            $before=serialize($s);$o=$this->observe($i,$s);$this->client->codec()->validate('AttentionInspection',$i);$this->client->codec()->validate('AttentionObservation',$o);
            $this->assertSame($expected,$o->native_state);$this->assertTrue($o->object_exists);$this->assertSame('withdrawn-entry',$o->native_id);$this->assertFalse($this->writer->freshEntry('withdrawn-entry')->published());
            $this->assertSame('withdraw',$o->native_result->kind);$this->assertSame('absent',$o->verification->kind);$this->assertSame(404,$o->verification->http_status);
            $this->assertSame('prior-published-r1',$o->ownership->source_revision);$this->assertSame('prior-published-r1',$o->source_revision);$this->assertNull($i->mapping->publicly_verified_source_revision);
            $this->assertSame($o->current_sha256,$o->baseline_sha256);$this->assertFileDoesNotExist($this->generated.'/owned/index.html');$this->assertSame($before,serialize($s));
        }
        $this->http->assertSent(fn($q)=>$q->method()==='GET'&&$q->url()==='https://publisher.example/owned'&&!$q->hasHeader('X-DiscussionBridge-Secret'));
    }
    public static function failures():array{return [['missing-proof'],['foreign-entry'],['deleted-entry']];}
    public function test_fresh_actual_absence_read_does_not_require_native_clock_after_core_created_at():void
    {
        $i=unserialize(serialize($this->inspection));$i->created_at=gmdate('Y-m-d\TH:i:s',time()+60).'.000Z';$o=$this->observe($i);
        $this->assertSame('restored_baseline',$o->native_state);$this->assertSame('absent',$o->verification->kind);$this->assertLessThan($i->created_at,$o->verification->checked_at);
        $this->http->assertSent(fn($q)=>$q->method()==='GET'&&$q->url()===$i->mapping->canonical_url);
    }
    public function test_ordinary_leased_verification_keeps_its_existing_clock_floor():void
    {
        $verifier=(new \ReflectionProperty($this->inspector,'verifier'))->getValue($this->inspector);
        $this->expectException(CmsException::class);$verifier->verify($this->inspection->mapping,null,$this->inspection->verified_native_result,[],gmdate('Y-m-d\TH:i:s',time()+60).'.000Z',gmdate('Y-m-d\TH:i:s').'.000Z');
    }
    #[DataProvider('failures')]
    public function test_missing_proof_foreign_and_deleted_prior_entry_never_become_initial_absence(string $fault):void
    {
        if($fault==='missing-proof')$this->publicStatus=503;
        if($fault==='foreign-entry'){$fields=json_decode($this->baselineBytes,true);$fields['discussionbridge_resource_id']='11111111-1111-4111-8111-000000000099';$this->writeOwned($this->native,json_encode($fields));}
        if($fault==='deleted-entry')unlink($this->native);
        $o=$this->observe();$this->assertContains($o->native_state,['unknown','conflict']);$this->assertNotSame('never_applied',$o->native_state);$this->assertNotSame('none',$o->issue);$this->client->codec()->validate('AttentionObservation',$o);
    }
    public static function dynamicProfiles():array{return [['statamic_flat'],['statamic_db']];}
    #[DataProvider('dynamicProfiles')]
    public function test_shared_dynamic_withdrawn_baseline_preserves_receipt_and_optional_public_proof(string $profile):void
    {
        config(['discussionbridge.cms_profile'=>$profile]);[$i,$s]=$this->baselineCase();$o=$this->observe($i,$s);
        $this->assertSame('baseline',$o->native_state);$this->assertTrue($o->object_exists);$this->assertSame('prior-published-r1',$o->ownership->source_revision);$this->assertSame('absent',$o->verification->kind);
        $this->publicStatus=503;$o=$this->observe($i,$s);$this->assertSame('baseline',$o->native_state);$this->assertNull($o->verification);$this->assertSame('none',$o->issue);
        $foreign=json_decode($this->baselineBytes,true);$foreign['discussionbridge_resource_id']='11111111-1111-4111-8111-000000000099';$this->writeOwned($this->native,json_encode($foreign));$o=$this->observe($i,$s);$this->assertSame('conflict',$o->native_state);
    }
    public function test_exact_successor_handoff_prepares_same_owned_object_new_source_and_higher_generation():void
    {
        $event=(object)['sequence'=>1,'recovery_id'=>'11111111-1111-4111-8111-000000000088','inspection_id'=>$this->inspection->inspection_id,'context_sha256'=>$this->inspection->context_sha256,
            'predecessor_work_id'=>$this->inspection->work->work_id,'predecessor_attempt_cycle'=>$this->inspection->work->attempt_cycle,'work'=>clone$this->inspection->work,'mapping'=>$this->inspection->mapping,
            'source'=>$this->inspection->source,'mode'=>'settle_restored_baseline','native_baseline'=>$this->inspection->verified_native_result,'verification'=>null,'created_at'=>$this->inspection->created_at];
        $event->work->work_id='11111111-1111-4111-8111-000000000089';$event->work->attempt_cycle='11111111-1111-4111-8111-000000000090';$event->work->state='pending';$event->work->attempt=0;$event->work->last_failure_code=null;
        $lease=(object)['work'=>clone$event->work,'lease_token'=>rtrim(strtr(base64_encode(str_repeat("\x02",32)),'+/','-_'),'='),'lease_generation'=>5,'expires_at'=>gmdate('Y-m-d\TH:i:s',time()+300).'.000Z','server_time'=>gmdate('Y-m-d\TH:i:s').'.000Z'];$lease->work->state='leased';$lease->work->attempt=1;$lease->work->lease_generation=5;
        $this->http=new Factory;$this->http->preventStrayRequests();Http::swap($this->http);$this->http->fake(function($q)use($event,$lease){$path=parse_url($q->url(),PHP_URL_PATH);$host=parse_url($q->url(),PHP_URL_HOST);
            if($host==='publisher.example')return $this->http->response('Not found',404,['Content-Type'=>'text/html']);
            if(str_ends_with($path,'/attention-recovery.json'))$v=(object)['protocol_revision'=>'attention-recovery-1','candidate_id'=>'discussionbridge-v4-core-20261025-r6','inspection_profiles'=>['statamic-ssg-1']];
            elseif(str_ends_with($path,'/attention-inspections.json'))$v=(object)['items'=>[$this->inspection],'next_after'=>1,'has_more'=>false];
            elseif(str_ends_with($path,'/report.json')){$o=json_decode($q->body())->observation;$event->verification=$o->verification;$v=(object)['inspection_id'=>$this->inspection->inspection_id,'context_sha256'=>$this->inspection->context_sha256,'status'=>'ready','issue'=>'none','observed_at'=>$o->observed_at];}
            elseif(str_ends_with($path,'/recoveries.json'))$v=(object)['items'=>[$event],'next_after'=>1,'has_more'=>false];
            elseif(str_ends_with($path,'/claim.json'))$v=$lease;else throw new \RuntimeException('Unexpected controlled request');return $this->http->response(json_encode($v),200,['Content-Type'=>'application/json']);});
        $policy=new class extends NativePolicy{public function __construct(){}public function authorize(stdClass $c,stdClass $p):void{}};
        $service=new AttentionRecovery($this->client,$this->store,$this->inspector,$policy);$state=$this->store->read();$service->poll($state);$actual=$service->claim($state,new ReplayJournal($this->client,$this->store));
        $result=$this->writer->apply($state,$this->connection,$this->connection->policy,$event->mapping,$actual,$event->source,$this->root.'/new-source.html');
        $entry=$this->writer->freshEntry('withdrawn-entry');$this->assertTrue($entry->published());$this->assertSame('withdrawn-entry',$result->native_id);$this->assertSame($event->source->source_revision,$entry->get('discussionbridge_source_revision'));
        $this->assertSame(5,$entry->get('discussionbridge_cms_generation'));$this->assertSame(5,$state['mappings'][$event->mapping->mapping_id]['generation']);$this->assertSame(4,$state['mappings'][$event->mapping->mapping_id]['seen_generation']);
        $this->assertStringContainsString('New restored-baseline continuation.',$entry->get('content'));$this->assertSame($this->baselineBytes,base64_decode($state['attention_recoveries'][$event->recovery_id]['predecessor_static']['snapshot']['bytes']));
        $this->assertFileDoesNotExist($this->generated.'/owned/index.html'); // Native preparation is not a build/deploy/public verification.
    }
    public function test_export_actual_withdrawn_core_cases():void
    {
        $cases=[];[$baseline,$baselineState]=$this->baselineCase();foreach([['withdrawn-baseline',$baseline,$baselineState,'ready'],['withdrawn-restored',$this->inspection,$this->state,'ready']]as[$id,$i,$s,$expected])$cases[]=['id'=>'statamic-ssg-'.$id,'profile'=>'statamic_ssg','publication_kind'=>'static','inspection'=>$i,'observation'=>$this->observe($i,$s),'expected_status'=>$expected];
        $this->publicStatus=503;$cases[]=['id'=>'statamic-ssg-withdrawn-no-public-proof','profile'=>'statamic_ssg','publication_kind'=>'static','inspection'=>$this->inspection,'observation'=>$this->observe(),'expected_status'=>'not_ready'];$this->publicStatus=404;
        $foreign=json_decode($this->baselineBytes,true);$foreign['discussionbridge_resource_id']='11111111-1111-4111-8111-000000000099';$this->writeOwned($this->native,json_encode($foreign));$cases[]=['id'=>'statamic-ssg-withdrawn-foreign','profile'=>'statamic_ssg','publication_kind'=>'static','inspection'=>$this->inspection,'observation'=>$this->observe(),'expected_status'=>'not_ready'];
        unlink($this->native);$cases[]=['id'=>'statamic-ssg-withdrawn-deleted','profile'=>'statamic_ssg','publication_kind'=>'static','inspection'=>$this->inspection,'observation'=>$this->observe(),'expected_status'=>'not_ready'];
        foreach(['statamic_flat','statamic_db']as$profile){config(['discussionbridge.cms_profile'=>$profile]);$this->writeOwned($this->native,$this->baselineBytes);[$i,$s]=$this->baselineCase();
            $cases[]=['id'=>$profile.'-withdrawn-baseline','profile'=>$profile,'publication_kind'=>'dynamic','inspection'=>$i,'observation'=>$this->observe($i,$s),'expected_status'=>'ready'];
            $this->publicStatus=503;$cases[]=['id'=>$profile.'-withdrawn-optional-proof-unavailable','profile'=>$profile,'publication_kind'=>'dynamic','inspection'=>$i,'observation'=>$this->observe($i,$s),'expected_status'=>'ready'];$this->publicStatus=404;
            $foreign=json_decode($this->baselineBytes,true);$foreign['discussionbridge_resource_id']='11111111-1111-4111-8111-000000000099';$this->writeOwned($this->native,json_encode($foreign));$cases[]=['id'=>$profile.'-withdrawn-foreign','profile'=>$profile,'publication_kind'=>'dynamic','inspection'=>$i,'observation'=>$this->observe($i,$s),'expected_status'=>'not_ready'];
            unlink($this->native);$cases[]=['id'=>$profile.'-withdrawn-deleted','profile'=>$profile,'publication_kind'=>'dynamic','inspection'=>$i,'observation'=>$this->observe($i,$s),'expected_status'=>'not_ready'];}
        config(['discussionbridge.cms_profile'=>'statamic_ssg']);$this->writeOwned($this->native,$this->baselineBytes);$future=unserialize(serialize($this->inspection));$future->created_at=gmdate('Y-m-d\TH:i:s',time()+60).'.000Z';$cases[]=['id'=>'statamic-ssg-withdrawn-native-clock-behind','profile'=>'statamic_ssg','publication_kind'=>'static','inspection'=>$future,'observation'=>$this->observe($future),'expected_status'=>'ready'];
        foreach($cases as$c){$this->client->codec()->validate('AttentionInspection',$c['inspection']);$this->client->codec()->validate('AttentionObservation',$c['observation']);$this->assertNotEmpty($c['observation']->journal_sha256);}
        $export=getenv('DISCUSSIONBRIDGE_STATAMIC_WITHDRAWN_EXPORT');if($export){$expected=self::EVIDENCE.'/STATAMIC_WITHDRAWN_CORE_INTEROP.json';$this->assertSame($expected,str_replace('\\','/',$export));file_put_contents($export,json_encode($cases,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
            $previous=json_decode(file_get_contents(self::EVIDENCE.'/STATAMIC_PRE_WITHDRAWN_CORE_INTEROP.json'));file_put_contents(self::EVIDENCE.'/STATAMIC_CORE_INTEROP.json',json_encode([...$previous,...$cases],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));}
    }
}
class WithdrawnState extends PrivateState{private array $state=[];public function __construct(){}public function locked(Closure $op):mixed{return $op();}public function read():array{return unserialize(serialize($this->state));}public function save(array $s):void{$this->state=unserialize(serialize($s));}}
class WithdrawnReader extends NativeWriter
{
    public function __construct($configuration,$client,$store,$policy,$guard,$freshness,private string $path,private string $url,private string $root){parent::__construct($configuration,$client,$store,$policy,$guard,$freshness);}
    public function freshEntry(string $id){return is_file($this->path)?new WithdrawnEntry($this->path,$id,$this->url,$this->root):null;}
}
class WithdrawnQuery{private array $where=[];public function __construct(private WithdrawnReader $reader){}public function where($field,$id){$this->where[$field]=$id;return $this;}public function limit($n){return $this;}public function get(){$e=$this->reader->freshEntry('withdrawn-entry');foreach($this->where as$f=>$id)if(!$e||$e->get($f)!==$id)return collect([]);return collect($e?[$e]:[]);}}
class WithdrawnEntry
{
    private array $fields;
    public function __construct(private string $path,private string $id,private string $url,private string $root){$this->fields=json_decode(file_get_contents($path),true,512,JSON_THROW_ON_ERROR);}
    public function id(){return $this->id;}public function path(){return $this->path;}public function absoluteUrl(){return $this->url;}public function uri(){return '/owned';}public function locale(){return 'default';}
    public function template(){return 'fixture-owned';}
    public function get($key){return $this->fields[$key]??null;}public function data(?array $value=null){if($value===null)return collect($this->fields);$this->fields=$value;return $this;}
    public function published(?bool $value=null){if($value===null)return $this->fields['published'];$this->fields['published']=$value;return $this;}
    public function collection(){return new class{public function dated(){return false;}};}public function blueprint(){return new class{public function handle(){return 'fixture-owned';}};}
    public function getCurrentDirtyStateAttributes(){return $this->fields;}
    public function save(){if(!str_starts_with(str_replace('\\','/',realpath($this->path)),str_replace('\\','/',realpath($this->root)).'/'))throw new \RuntimeException('Owned native write escaped');return file_put_contents($this->path,json_encode($this->fields,JSON_THROW_ON_ERROR))!==false;}
}
