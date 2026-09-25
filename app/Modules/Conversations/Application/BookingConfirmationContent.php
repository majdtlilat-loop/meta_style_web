<?php

declare(strict_types=1);

namespace App\Modules\Conversations\Application;

use App\Kernel\Localization\TranslatedText;
use App\Modules\Booking\Data\BookingConfirmationData;
use App\Modules\Booking\Data\BookingConfirmationLine;

/**
 * What a guest's booking confirmation says — from the booking record, in the
 * language chosen for it, and nothing else (docs/25-WHATSAPP.md §22).
 *
 * ## The template contract
 *
 * Business-initiated WhatsApp messages are pre-approved TEMPLATES (§14). The
 * approved `booking_confirmation` template must have exactly these body
 * variables, in this order:
 *
 *     {{1}} customer name       {{5}} time (start–end, branch clock)
 *     {{2}} center name         {{6}} every booked service
 *     {{3}} branch name         {{7}} booking number (B-000412)
 *     {{4}} date (branch clock) {{8}} branch contact number
 *
 * Every value is real, and comes from the Booking module's
 * `BookingConfirmationData` — never from its models: the SNAPSHOT service
 * names the booking holds (a later catalog rename never rewrites what was
 * booked), the branch-local times the customer was given, the public
 * reference. The employee appears only when the customer asked for that
 * person by name; Booking leaves the name out otherwise. Never an internal id
 * or uuid, never the verification code (a one-time secret, docs/24 §3), never
 * a price.
 *
 * A value the booking does not have is sent as a dash, because Meta refuses an
 * empty template parameter; nothing is invented to fill it.
 *
 * ## Two renderings, one source
 *
 * `parameters` go to the provider, which renders the approved template.
 * `body` is the same content rendered locally from `conversations.php`, and
 * is what the message row stores and staff read in the thread. Both are in
 * `$locale` — the app locale whose Meta template language code the message is
 * sent under, so the parameters match the template's language.
 */
final class BookingConfirmationContent
{
    /** Meta refuses a parameter carrying a newline, a tab or long space runs. */
    private const MAX_PARAMETER = 180;

    /** The service list is the one value that grows with the booking. */
    private const MAX_SERVICES = 480;

    /**
     * @return array{parameters: array<string, string>, body: string}
     */
    public function render(BookingConfirmationData $booking, string $centerName, string $locale): array
    {
        $none = (string) __('conversations.booking_confirmation.none', [], $locale);

        $start = $booking->localStart();
        $end = $booking->localEnd();

        $time = $end->greaterThan($start)
            ? (string) __('conversations.booking_confirmation.time_range', [
                'start' => $start->format('H:i'),
                'end' => $end->format('H:i'),
            ], $locale)
            : $start->format('H:i');

        $parameters = [
            'customer' => $this->clean($booking->customerName, $none),
            'center' => $this->clean($centerName, $none),
            'branch' => $this->clean($booking->branchName->get($locale), $none),
            'date' => $this->clean($start->locale($locale)->isoFormat('dddd D MMMM YYYY'), $none),
            'time' => $this->clean($time, $none),
            'services' => $this->clean($this->services($booking, $locale), $none, self::MAX_SERVICES),
            'reference' => $this->clean($booking->reference, $none),
            'contact' => $this->clean($this->contact($booking), $none),
        ];

        return [
            'parameters' => $parameters,
            'body' => (string) __('conversations.booking_confirmation.body', $parameters, $locale),
        ];
    }

    /**
     * Every booked service, in booked order: the snapshot name, its variation,
     * its add-ons and — only when the customer chose them — the employee.
     */
    private function services(BookingConfirmationData $booking, string $locale): string
    {
        $separator = (string) __('conversations.booking_confirmation.separator', [], $locale);

        $lines = array_map(function (BookingConfirmationLine $line) use ($locale, $separator): string {
            $label = $line->service->get($locale);

            $variation = $line->variation?->get($locale);

            if (is_string($variation) && trim($variation) !== '') {
                $label = (string) __('conversations.booking_confirmation.service_variation', [
                    'service' => $label,
                    'variation' => $variation,
                ], $locale);
            }

            $addons = array_values(array_filter(
                array_map(static fn (TranslatedText $addon): string => $addon->get($locale), $line->addons),
                static fn (string $name): bool => trim($name) !== '',
            ));

            if ($addons !== []) {
                $label = (string) __('conversations.booking_confirmation.service_addons', [
                    'service' => $label,
                    'addons' => implode($separator, $addons),
                ], $locale);
            }

            $employee = trim((string) $line->employee?->get($locale));

            if ($employee !== '') {
                $label = (string) __('conversations.booking_confirmation.service_employee', [
                    'service' => $label,
                    'employee' => $employee,
                ], $locale);
            }

            return $label;
        }, $booking->lines);

        return implode($separator, $lines);
    }

    /**
     * The branch's own public number — the one the public menu already shows —
     * or its WhatsApp number when it has no phone.
     */
    private function contact(BookingConfirmationData $booking): ?string
    {
        foreach ([$booking->branchPhone, $booking->branchWhatsapp] as $candidate) {
            if (is_string($candidate) && trim($candidate) !== '') {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * One line, no control characters, bounded — the shape Meta accepts.
     */
    private function clean(?string $value, string $none, int $max = self::MAX_PARAMETER): string
    {
        $value = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

        if ($value === '') {
            return $none;
        }

        return mb_strlen($value) > $max ? rtrim(mb_substr($value, 0, $max - 1)).'…' : $value;
    }
}
