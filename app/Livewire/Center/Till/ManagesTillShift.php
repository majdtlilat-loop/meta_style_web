<?php

declare(strict_types=1);

namespace App\Livewire\Center\Till;

use App\Kernel\Entitlements\Entitlements;
use App\Kernel\Money\Currency;
use App\Kernel\Money\Money;
use App\Modules\Finance\Application\Actions\CloseShiftWithCount;
use App\Modules\Finance\Application\FinanceQuery;
use App\Modules\Sales\Application\Actions\ManageCashierShift;
use App\Modules\Sales\Domain\Exceptions\SaleFailed;
use InvalidArgumentException;

/**
 * The till's shift bar: open a shift (with the opening float), close it.
 *
 * With `finance` the drawer is counted as the shift closes, BLIND — the
 * expected figure is shown only after the count is entered, in the outcome
 * message — and the variance is recorded by Finance. Without it the shift
 * closes the Phase 9 way (docs/20-FINANCE.md §33).
 */
trait ManagesTillShift
{
    public string $shiftNote = '';

    /** Typed major units; parsed by `Money::fromMajorString`, never a float. */
    public string $openingCash = '';

    public string $countedCash = '';

    public function openShift(ManageCashierShift $shifts): void
    {
        $this->attempt(function () use ($shifts): void {
            $opening = trim($this->openingCash) === '' ? null : $this->cash($this->openingCash);

            $shifts->open($this->branch, $this->user(), $this->shiftNote === '' ? null : $this->shiftNote, null, $opening);

            $this->reset(['shiftNote', 'openingCash']);
            $this->saved = (string) __('manager_pos.shift.opened');
        });
    }

    public function closeShift(string $uuid, ManageCashierShift $shifts, CloseShiftWithCount $count, FinanceQuery $finance, Entitlements $entitlements): void
    {
        $this->attempt(function () use ($uuid, $shifts, $count, $finance, $entitlements): void {
            // Your own shift, or — with the supervise permission — anyone's at
            // a branch you may work in. Resolved with that check, never raw.
            $shift = $finance->shift($uuid, $this->user());
            $note = $this->shiftNote === '' ? null : $this->shiftNote;

            if (! $entitlements->enabled('finance')) {
                $shifts->close($shift, $this->user(), $note);

                $this->reset(['shiftNote']);
                $this->saved = (string) __('Shift closed.');

                return;
            }

            if (trim($this->countedCash) === '') {
                throw SaleFailed::policy('Count the cash in the drawer before closing the shift.');
            }

            $result = $count($shift, $this->user(), $this->cash($this->countedCash), $note);
            $currency = Currency::tryFrom($result->currency) ?? Currency::default();

            $this->reset(['shiftNote', 'countedCash']);
            $this->saved = (string) __('Shift closed. Expected :expected, counted :counted, difference :variance.', [
                'expected' => Money::fromMinor($result->expected_cash_minor, $currency)->formatted(),
                'counted' => Money::fromMinor($result->counted_cash_minor, $currency)->formatted(),
                'variance' => Money::fromMinor($result->variance_minor, $currency)->formatted(),
            ]);
        });
    }

    /**
     * An amount a person typed, in the center's currency — never through a float.
     */
    private function cash(string $typed): int
    {
        try {
            $minor = Money::fromMajorString($typed, Currency::default())->minor;
        } catch (InvalidArgumentException) {
            throw SaleFailed::policy('Enter an amount like 25000.');
        }

        if ($minor < 0) {
            throw SaleFailed::policy('Enter an amount like 25000.');
        }

        return $minor;
    }
}
