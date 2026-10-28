<?php
namespace CodeWorksLabs\DiscussionBridgeStatamic\Console;
use CodeWorksLabs\DiscussionBridgeStatamic\Cms\AttentionRecovery;
use Illuminate\Console\Command;
class CheckNativeAttention extends Command
{
    protected $signature = 'discussionbridge:check-native-attention';
    protected $description = 'Observe and report requested native state without claims, writes, builds or recovery';
    public function handle(AttentionRecovery $recovery): int
    {
        try { $this->line(json_encode($recovery->check(), JSON_THROW_ON_ERROR)); return self::SUCCESS; }
        catch (\Throwable) { $this->error('Native inspection is unavailable; no retry was authorized.'); return self::FAILURE; }
    }
}
