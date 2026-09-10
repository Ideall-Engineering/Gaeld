<?php

namespace Tests\Traits;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Runs real OS-level concurrent PHP processes (via pcntl_fork) against the
 * shared test database and Redis, so a lock contract is proven by an actual
 * race instead of by asserting that `Cache::lock()` was merely *called*.
 *
 * RefreshDatabase wraps every test in an uncommitted transaction, which a
 * forked child (its own Postgres connection) cannot see. This trait commits
 * whatever fixtures exist at call time so children can read them, then opens
 * a fresh transaction afterward so RefreshDatabase's own rollback in
 * tearDown keeps working. Because the commit is real, callers MUST delete
 * anything they want gone in a `finally` block around the call — but a plain
 * delete there runs *inside* that freshly reopened transaction and is itself
 * silently undone by RefreshDatabase's own rollback at tearDown. Route any
 * cleanup through {@see withCommittedCleanup()} instead of calling delete
 * queries directly, or the "cleanup" is a no-op and rows leak into every
 * later test in the run.
 */
trait WithRealConcurrency
{
    /**
     * Run cleanup queries so they actually take effect, undoing the commit
     * {@see runConcurrently()} made for cross-process visibility. Must be
     * called from the same `finally` block that wraps `runConcurrently()`.
     */
    protected function withCommittedCleanup(callable $cleanup): void
    {
        DB::commit();
        $cleanup();
        DB::beginTransaction();
    }

    /**
     * @param  callable(int $index): mixed  $work  Runs inside a forked child; the return value is JSON-encoded, so keep it to scalars/arrays.
     * @return array<int, array{start: float, end: float, result: mixed, error: ?string}>
     */
    protected function runConcurrently(int $processes, callable $work): array
    {
        if (! function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension is not available for real concurrency tests.');
        }

        DB::commit();

        $resultDir = sys_get_temp_dir().'/gaeld-concurrency-'.uniqid();
        mkdir($resultDir, 0700, true);

        try {
            $pids = [];

            for ($i = 0; $i < $processes; $i++) {
                $pid = pcntl_fork();

                if ($pid === -1) {
                    $this->fail('Failed to fork a process for the concurrency test.');
                }

                if ($pid === 0) {
                    // Child: never share the parent's PDO/Redis handles.
                    DB::purge();

                    $start = microtime(true);
                    $result = null;
                    $error = null;

                    try {
                        $result = $work($i);
                    } catch (Throwable $e) {
                        $error = $e->getMessage();
                    }

                    file_put_contents("{$resultDir}/{$i}.json", json_encode([
                        'start' => $start,
                        'end' => microtime(true),
                        'result' => $result,
                        'error' => $error,
                    ]));

                    // A plain exit()/die() still runs PHP's normal shutdown
                    // sequence — register_shutdown_function callbacks,
                    // destructors, output buffer flushes — all duplicated
                    // from the parent's PHPUnit/Collision test-runner state
                    // by the fork. Terminate immediately instead, so the
                    // child never touches state it shares with the parent
                    // process after its own result is safely on disk.
                    posix_kill(posix_getpid(), SIGKILL);
                }

                $pids[] = $pid;
            }

            $exitCodes = [];
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                // Children terminate via SIGKILL (see above), not a normal
                // exit, so success is "killed", not "exited 0".
                $exitCodes[$pid] = pcntl_wifsignaled($status) && pcntl_wtermsig($status) === SIGKILL
                    ? 0
                    : -1;
            }

            $results = [];
            for ($i = 0; $i < $processes; $i++) {
                $file = "{$resultDir}/{$i}.json";
                $this->assertFileExists($file, "Child process #{$i} did not report a result.");
                $results[] = json_decode(file_get_contents($file), true);
            }

            foreach ($exitCodes as $pid => $code) {
                $this->assertSame(0, $code, "Child process {$pid} exited with status {$code}.");
            }

            foreach ($results as $index => $result) {
                $this->assertNull($result['error'], "Child process #{$index} threw: {$result['error']}");
            }

            return $results;
        } finally {
            foreach (glob("{$resultDir}/*.json") ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($resultDir);

            // Restore the transaction depth RefreshDatabase expects at tearDown.
            DB::beginTransaction();
        }
    }
}
