<?php

namespace Goldnead\StatamicPayments\Tests\Feature;

use Goldnead\StatamicPayments\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The guard over the copy.
 *
 * `tests/Fakes/insights-contracts.php` claims to be the analytics addon's
 * contract copied byte for byte, and until this file existed, nothing checked
 * that claim. The sibling is neither a `require` nor a `require-dev`, so its
 * `interface_exists` locks never engage: the copy is what the whole suite runs
 * against **and** what PHPStan analyses through `scanFiles`. A method added to
 * `Metric` upstream would therefore leave every test here green and fatal on
 * the first install that has both addons — a green suite over a contract that
 * no longer exists is worse than no suite, because it is believed.
 *
 * So the copy is held against the original wherever the original can be found:
 * installed in `vendor/`, or checked out beside this package, which is how the
 * family is developed. Where it cannot be found the test skips and says why —
 * a machine without the sibling cannot answer the question, and pretending
 * otherwise is the failure this file exists to prevent.
 *
 * The comparison runs through a second PHP process
 * (`tests/Support/insights-contract-probe.php`) because both sides declare the
 * same fully qualified names and one process can hold only one of them.
 *
 * The contract is semver-locked from the release onwards. This is the thing
 * that notices when it moves anyway.
 */
class InsightsContractsMatchTest extends TestCase
{
    /** The three interfaces a metric here implements or is read through. */
    protected const VERTRAEGE = ['Contracts\Metric', 'Contracts\HasBreakdowns', 'Contracts\HasFilterOptions'];

    /** The value objects the queries read. */
    protected const WERTOBJEKTE = ['Support\MetricQuery', 'Support\Period', 'Support\Unit'];

    /**
     * Constants whose **value** this package depends on, not just their name.
     *
     * `bucketExpression()` compares against `BUCKET_MONTH`, and `unit()` returns
     * these strings straight to a screen that formats by them. A renamed value
     * upstream would silently turn every monthly chart daily, or every money
     * figure into a plain count.
     */
    protected const TRAGENDE_KONSTANTEN = [
        'Support\MetricQuery' => ['BUCKET_DAY', 'BUCKET_MONTH'],
        'Support\Unit' => ['COUNT', 'CURRENCY', 'PERCENT'],
    ];

    /**
     * An addition upstream breaks the copy just as a removal does.
     *
     * A metric written against a stand-in that is missing a method does not
     * implement the real interface at all, so equality is the right test here
     * and a subset would not be: both directions are fatal.
     */
    #[Test]
    public function the_copied_contract_still_matches_the_real_one(): void
    {
        [$echt, $kopie] = $this->beideSeiten();

        foreach (self::VERTRAEGE as $name) {
            $this->assertNotNull($echt[$name], "The sibling no longer declares {$name}.");
            $this->assertNotNull($kopie[$name], "The stand-in does not declare {$name}.");

            $this->assertSame(
                $echt[$name]['methods'],
                $kopie[$name]['methods'],
                "The stand-in for {$name} has drifted from the real contract. Copy it across again — every metric in src/Integrations/Insights is written against this shape, and the whole suite runs on the copy.",
            );
        }
    }

    /**
     * The value objects, as a subset rather than an equality.
     *
     * Deliberately looser than the interfaces above, and for a reason that does
     * not apply to them: a field added to `MetricQuery` upstream breaks nothing
     * here — the queries read what they read. Demanding equality would paint
     * this test red on a purely additive release and teach whoever sees it to
     * ignore the file. What must hold is that everything the copy promises is
     * really there and really has that shape.
     */
    #[Test]
    public function the_copied_value_objects_still_carry_what_the_metrics_read(): void
    {
        [$echt, $kopie] = $this->beideSeiten();

        foreach (self::WERTOBJEKTE as $name) {
            $this->assertNotNull($echt[$name], "The sibling no longer declares {$name}.");
            $this->assertNotNull($kopie[$name], "The stand-in does not declare {$name}.");

            foreach ($kopie[$name]['methods'] as $methode => $form) {
                $this->assertArrayHasKey($methode, $echt[$name]['methods'], "{$name}::{$methode}() exists only in the stand-in.");
                $this->assertSame($echt[$name]['methods'][$methode], $form, "{$name}::{$methode}() has a different signature upstream.");
            }

            foreach ($kopie[$name]['properties'] as $eigenschaft => $form) {
                $this->assertArrayHasKey($eigenschaft, $echt[$name]['properties'], "{$name}::\${$eigenschaft} exists only in the stand-in.");
                $this->assertSame($echt[$name]['properties'][$eigenschaft], $form, "{$name}::\${$eigenschaft} is declared differently upstream.");
            }
        }

        foreach (self::TRAGENDE_KONSTANTEN as $name => $konstanten) {
            foreach ($konstanten as $konstante) {
                $this->assertArrayHasKey($konstante, $echt[$name]['constants'], "{$name}::{$konstante} is gone upstream.");
                $this->assertSame(
                    $echt[$name]['constants'][$konstante],
                    $kopie[$name]['constants'][$konstante] ?? null,
                    "{$name}::{$konstante} means something else upstream, and this package reads its value.",
                );
            }
        }
    }

    // -- Machinery ----------------------------------------------------------

    /** @return array{0: array<string, ?array<string, mixed>>, 1: array<string, ?array<string, mixed>>} */
    protected function beideSeiten(): array
    {
        $quelle = $this->geschwisterQuelle();

        if ($quelle === null) {
            $this->markTestSkipped(
                'goldnead/statamic-insights was not found — neither in vendor/ nor checked out beside this package. '
                .'It is a `suggest` and deliberately not installed, so this machine cannot say whether '
                .'tests/Fakes/insights-contracts.php still matches the real contract. Run this where the sibling exists.'
            );
        }

        $dateien = glob($quelle.'/Contracts/*.php') ?: [];

        foreach (['MetricQuery', 'Period', 'Unit'] as $wertobjekt) {
            $dateien[] = $quelle.'/Support/'.$wertobjekt.'.php';
        }

        return [
            $this->form($dateien),
            $this->form([__DIR__.'/../Fakes/insights-contracts.php']),
        ];
    }

    /** Where the real package is, if it is anywhere. */
    protected function geschwisterQuelle(): ?string
    {
        $wurzel = dirname(__DIR__, 2);

        $kandidaten = [
            $wurzel.'/vendor/goldnead/statamic-insights/src',
            dirname($wurzel).'/statamic-insights/src',
        ];

        foreach ($kandidaten as $kandidat) {
            if (is_dir($kandidat.'/Contracts')) {
                return $kandidat;
            }
        }

        return null;
    }

    /**
     * The shape of a contract, read in a process of its own.
     *
     * @param  array<int, string>  $dateien
     * @return array<string, ?array<string, mixed>>
     */
    protected function form(array $dateien): array
    {
        $sonde = dirname(__DIR__).'/Support/insights-contract-probe.php';

        $befehl = implode(' ', array_map(
            'escapeshellarg',
            array_merge([PHP_BINARY, $sonde], $dateien),
        ));

        $ausgabe = shell_exec($befehl.' 2>&1');
        $gelesen = json_decode((string) $ausgabe, true);

        $this->assertIsArray($gelesen, 'The contract probe returned nothing usable: '.$ausgabe);

        return $gelesen;
    }
}
