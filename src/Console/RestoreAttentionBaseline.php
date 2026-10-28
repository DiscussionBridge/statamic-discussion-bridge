<?php
namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\AttentionBaselineRestorer;
use Illuminate\Console\Command;
class RestoreAttentionBaseline extends Command
{
    protected $signature = 'discussionbridge:restore-recorded-baseline {inspection_id} {--apply} {--deploy-baseline}';
    protected $description = 'Preview the exact retained SSG baseline; explicit apply and deploy-baseline restore it without retrying Work';
    public function handle(AttentionBaselineRestorer $restorer): int
    {
        try { $this->line(json_encode($restorer->restore((string) $this->argument('inspection_id'), (bool) $this->option('apply'), (bool) $this->option('deploy-baseline')), JSON_THROW_ON_ERROR)); return self::SUCCESS; }
        catch (\Throwable) { $this->error('The exact retained baseline needs native reconciliation. No Work retry was authorized.'); return self::FAILURE; }
    }
}
