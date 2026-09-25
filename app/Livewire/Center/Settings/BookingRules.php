<?php

declare(strict_types=1);

namespace App\Livewire\Center\Settings;

use App\Kernel\Authorization\Permission;
use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Identity\Models\User;
use App\Livewire\Center\Concerns\RequiresFeature;
use App\Modules\Booking\Application\Actions\UpdateBookingSettings;
use App\Modules\Booking\Domain\BookingSettings;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\View\View;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

/**
 * Settings → Booking: the five rules the Booking Engine reads — slot interval,
 * how far ahead, minimum notice, the customer's cancellation notice and the
 * calendar range.
 *
 * Belongs to `booking`. A center that never owned it sees the upgrade offer;
 * the values stay readable either way and UpdateBookingSettings refuses a
 * change without it (and without `settings.manage`).
 */
final class BookingRules extends Component
{
    use RequiresFeature;

    /** @var array<string, int|string> */
    public array $rules = [];

    public string $notice = '';

    public string $noticeTone = 'success';

    public function mount(BookingSettings $settings): void
    {
        $this->viewer();
        $this->rules = $settings->all();
    }

    public function save(UpdateBookingSettings $update): void
    {
        $this->resetErrorBag();

        try {
            $this->rules = $update($this->viewer(), $this->rules);
        } catch (AuthorizationException) {
            $this->fail(__('manager_settings.errors.forbidden'));

            return;
        } catch (EntitlementRequired) {
            $this->fail(__('manager_settings.booking.locked'));

            return;
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                $this->addError('rules.'.$field, (string) ($messages[0] ?? ''));
            }

            return;
        }

        $this->notice = __('manager_settings.booking.saved');
        $this->noticeTone = 'success';
    }

    public function render(): View
    {
        $user = $this->viewer();
        $offer = $this->lockedFeature('booking');

        $fields = [];

        foreach (UpdateBookingSettings::BOUNDS as $key => [$min, $max]) {
            $fields[] = [
                'key' => $key,
                'label' => __('manager_settings.booking.fields.'.$key.'.label'),
                'help' => __('manager_settings.booking.fields.'.$key.'.help'),
                'unit' => __('manager_settings.booking.fields.'.$key.'.unit'),
                'min' => $min,
                'max' => $max,
            ];
        }

        return view('livewire.center.settings.booking-rules', [
            'canManage' => $user->hasPermission(Permission::SettingsManage) && $offer === null,
            'offer' => $offer,
            'fields' => $fields,
        ]);
    }

    private function fail(string $message): void
    {
        $this->notice = $message;
        $this->noticeTone = 'danger';
    }

    private function viewer(): User
    {
        $user = auth()->user();

        abort_unless($user instanceof User && $user->hasPermission(Permission::SettingsView), 403);

        return $user;
    }
}
