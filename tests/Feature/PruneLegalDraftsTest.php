<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Models\Cancellation;
use Goldnead\StatamicPayments\Models\Withdrawal;
use Goldnead\StatamicPayments\Tests\TestCase;
use Illuminate\Support\Carbon;
use PHPUnit\Framework\Attributes\Test;

/**
 * Unbestätigte Erklärungen verschwinden nach sieben Tagen; bestätigte nie.
 */
class PruneLegalDraftsTest extends TestCase
{
    private function withdrawal(int $daysAgo, bool $confirmed): Withdrawal
    {
        return Withdrawal::create([
            'public_id' => 'W-'.strtoupper(bin2hex(random_bytes(4))), 'brand_id' => 0,
            'name' => 'Wer', 'email' => 'wer@example.com', 'order_reference' => '1', 'contact' => 'wer@example.com',
            'declared_at' => Carbon::now()->subDays($daysAgo),
            'confirmed_at' => $confirmed ? Carbon::now()->subDays($daysAgo) : null,
        ]);
    }

    private function cancellation(int $daysAgo, bool $confirmed): Cancellation
    {
        return Cancellation::create([
            'public_id' => 'K-'.strtoupper(bin2hex(random_bytes(4))), 'brand_id' => 0,
            'name' => 'Wer', 'email' => 'wer@example.com', 'identification' => 'sub_1', 'kind' => Cancellation::KIND_ORDINARY,
            'declared_at' => Carbon::now()->subDays($daysAgo),
            'confirmed_at' => $confirmed ? Carbon::now()->subDays($daysAgo) : null,
        ]);
    }

    #[Test]
    public function only_old_unconfirmed_declarations_are_deleted(): void
    {
        $oldDraft = $this->withdrawal(8, false);
        $freshDraft = $this->withdrawal(2, false);
        $oldConfirmed = $this->withdrawal(30, true);

        $oldDraftC = $this->cancellation(8, false);
        $oldConfirmedC = $this->cancellation(30, true);

        $this->artisan('payments:prune-legal-drafts', ['--dry-run' => true])->assertSuccessful();
        $this->assertSame(3, Withdrawal::count());

        $this->artisan('payments:prune-legal-drafts')->assertSuccessful();

        $this->assertNull($oldDraft->fresh());
        $this->assertNotNull($freshDraft->fresh());
        $this->assertNotNull($oldConfirmed->fresh());
        $this->assertNull($oldDraftC->fresh());
        $this->assertNotNull($oldConfirmedC->fresh());
    }

    #[Test]
    public function the_period_can_be_shortened(): void
    {
        $draft = $this->withdrawal(2, false);

        $this->artisan('payments:prune-legal-drafts', ['--days' => 1])->assertSuccessful();

        $this->assertNull($draft->fresh());
    }
}
