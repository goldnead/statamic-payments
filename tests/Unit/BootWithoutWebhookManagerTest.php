<?php

namespace Goldnead\StatamicPayments\Tests\Unit;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

/**
 * A site without the webhook manager and without brand-context.
 *
 * In a separate PHP process, because this suite has both installed as dev
 * dependencies and would hide a provider that touches a missing class
 * (statamic-courses 0.2.0 crashed every such site at boot that way).
 */
class BootWithoutWebhookManagerTest extends TestCase
{
    #[Test]
    public function it_boots_and_stays_out_of_the_way_without_the_webhook_manager(): void
    {
        $process = new Process([PHP_BINARY, __DIR__.'/../Boot/boot-without-webhook-manager.php']);
        $process->setTimeout(120)->run();

        $output = $process->getOutput().$process->getErrorOutput();

        $this->assertSame(0, $process->getExitCode(), $output);
        $this->assertStringContainsString('booted, bridge off, available no', $output);
        $this->assertStringNotContainsString('not found', $output);
    }
}
