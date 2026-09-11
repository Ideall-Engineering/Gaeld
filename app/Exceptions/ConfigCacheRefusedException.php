<?php

namespace App\Exceptions;

use RuntimeException;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Thrown when a cache-writing command is invoked in a development tree.
 *
 * Once bootstrap/cache/config.php exists, Laravel stops loading .env, so a
 * test run never sees .env.testing: it targets the development database and
 * RefreshDatabase drops its tables. The same cache makes runningUnitTests()
 * false, which turns every POST test into an unexpected 419.
 */
class ConfigCacheRefusedException extends RuntimeException
{
    public static function forCommand(string $command, string $environment): self
    {
        return new self(sprintf(
            'Refusing to run `%s` in the \'%s\' environment.',
            $command,
            $environment,
        ));
    }

    /**
     * Print the actionable part before the exception surfaces.
     *
     * Laravel renders console exceptions with a stack trace and does not
     * consult the exception for its own formatting, so the guidance a reader
     * needs is written here, ahead of that noise.
     */
    public function explainTo(OutputInterface $output): void
    {
        $output->writeln('');
        $output->writeln('  <bg=red;fg=white> REFUSED </> '.$this->getMessage());
        $output->writeln('');
        $output->writeln('  A cached config makes Laravel skip .env entirely. The test suite would');
        $output->writeln('  then run against the development database and drop its tables.');
        $output->writeln('');
        $output->writeln('  If a cache is already present:  <fg=cyan>php artisan config:clear</>');
        $output->writeln('  To override anyway:             <fg=cyan>GAELD_ALLOW_CONFIG_CACHE=1</>');
        $output->writeln('');
    }
}
