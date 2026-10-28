<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\Cms;

/** Includes actual native cancellation and throwing callbacks in one witness. */
trait TracksToNativeOperations
{
    private ?array $discussionBridgeSourceOriginal = null;

    public function syncOriginal(): static
    {
        parent::syncOriginal();
        if ($this->id() === null || $this->get('discussionbridge_cms_owned') === true
            || ! \Illuminate\Support\Facades\Schema::hasTable('discussionbridge_deliveries')
            || ! \Illuminate\Support\Facades\DB::table('discussionbridge_deliveries')->where('entry_id', (string) $this->id())->exists()) {
            return $this;
        }
        if ($this instanceof \Statamic\Eloquent\Entries\Entry) {
            $model = $this->model();
            $this->discussionBridgeSourceOriginal = $model && $model->exists
                ? ['kind' => 'eloquent', 'connection' => $model->getConnectionName(), 'table' => $model->getTable(),
                    'key' => (string) $model->getKey(), 'sha256' => ToSourceSnapshot::digest($model->getRawOriginal())] : null;
        } else {
            $path = $this->initialPath() ?? $this->path();
            $this->discussionBridgeSourceOriginal = is_string($path) && ! is_link($path) && is_file($path)
                ? ['kind' => 'file', 'path' => $path, 'sha256' => hash_file('sha256', $path)] : null;
        }
        return $this;
    }

    public function discussionBridgeSourceOriginal(): ?array
    {
        return $this->discussionBridgeSourceOriginal;
    }

    public function save()
    {
        return app(ToClosures::class)->run($this, 'save', fn () => parent::save());
    }

    public function delete()
    {
        return app(ToClosures::class)->run($this, 'delete', fn () => parent::delete());
    }
}
