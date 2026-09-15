<?php

namespace CodeWorksLabs\DiscussionBridgeStatamic\StaticSite;

use RuntimeException;
use Symfony\Component\Process\Process;

class PleaseCommandRunner
{
    public function run(string $command): void
    {
        $phpBinary = (string) config('discussionbridge.php_binary');
        $please = base_path('please');

        if ($phpBinary === '' || ! is_file($phpBinary) || ! is_executable($phpBinary)) {
            throw new RuntimeException('The configured PHP CLI binary is unavailable.');
        }

        if (! is_file($please)) {
            throw new RuntimeException('The Statamic please entry point is unavailable.');
        }

        $process = new Process([
            $phpBinary,
            $please,
            $command,
            '--no-interaction',
        ], base_path());
        $process->setTimeout(900);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new RuntimeException(sprintf(
                'The %s command failed with exit code %d.',
                $command,
                $process->getExitCode() ?? 1,
            ));
        }
    }
}
