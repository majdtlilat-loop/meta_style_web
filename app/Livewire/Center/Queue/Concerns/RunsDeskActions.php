<?php

declare(strict_types=1);

namespace App\Livewire\Center\Queue\Concerns;

use App\Kernel\Identity\Models\User;
use App\Livewire\Center\Queue\OperationalFailure;
use Throwable;

/**
 * The shared shape of an operational screen's buttons: run one Action, turn a
 * refusal into a translated notice, and say what happened.
 *
 * Nothing here decides anything — every rule, lock and audit entry is the
 * Action's. This only keeps a refusal (including a missing plan feature) from
 * surfacing as a 500 on a desk that is serving somebody.
 */
trait RunsDeskActions
{
    /** The outcome of the last action, shown once. */
    public string $notice = '';

    /** success | danger | warning | info */
    public string $noticeTone = 'success';

    public function dismissNotice(): void
    {
        $this->notice = '';
    }

    /**
     * @param  callable(): void  $work
     */
    protected function attempt(callable $work): bool
    {
        $this->notice = '';

        try {
            $work();

            return true;
        } catch (Throwable $failure) {
            if (! OperationalFailure::handles($failure)) {
                throw $failure;
            }

            $this->notice = OperationalFailure::message($failure);
            $this->noticeTone = 'danger';

            return false;
        }
    }

    protected function succeeded(string $message, string $tone = 'success'): void
    {
        $this->notice = $message;
        $this->noticeTone = $tone;
    }

    protected function viewer(): User
    {
        /** @var User $user */
        $user = auth('web')->user();

        return $user;
    }
}
