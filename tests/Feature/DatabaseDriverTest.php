<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * That the engine under the suite is the one that was asked for.
 *
 * This test exists because of how the `claims` CI job could fail: not by going
 * red, but by going green having proved nothing. The whole point of that job is
 * that two money paths are guarded by a unique index rather than a row lock —
 * `lockForUpdate()` compiles to an empty string on SQLite — so the guard has to
 * be seen working on an engine that behaves differently.
 *
 * If `DB_CONNECTION` ever stopped reaching the suite (a `<env>` added to
 * phpunit.xml, a rename in testbench's config), every one of those tests would
 * quietly run on SQLite again and still pass. A job like that is worse than no
 * job: it reports a property nobody is checking any more.
 *
 * So the suite says out loud which driver it is on, and disagreeing with the
 * environment is a failure.
 */
class DatabaseDriverTest extends TestCase
{
    #[Test]
    public function the_suite_runs_on_the_driver_it_was_told_to(): void
    {
        $wanted = (string) (getenv('DB_CONNECTION') ?: '');
        $driver = DB::connection()->getDriverName();

        if ($wanted === '' || $wanted === 'testing') {
            // The ordinary local run. Nothing was asked for, and in-memory
            // SQLite is the right default.
            $this->assertSame('sqlite', $driver);

            return;
        }

        $this->assertSame(
            $wanted === 'pgsql' ? 'pgsql' : $wanted,
            $driver,
            "DB_CONNECTION asked for [{$wanted}] and the suite is on [{$driver}]; the claim tests would be proving nothing.",
        );

        // And it is a real server, not a file pretending to be one.
        $this->assertNotSame('sqlite', $driver);
    }
}
