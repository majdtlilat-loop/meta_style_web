<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Booking\Application\Actions\IssueVerificationCode;
use App\Modules\Booking\Domain\VerificationCode;
use App\Modules\Customers\Domain\Models\CustomerAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use SensitiveParameter;

/**
 * Issuing a booking a new verification code — the two AUTHENTICATED paths.
 *
 * ## Two endpoints, deliberately, not one with a flag
 *
 * {@see staff()} runs on the staff guard and needs `appointment.update` plus
 * branch scope. {@see customer()} runs on the customer guard and works only on
 * that account's own booking. They are separate routes on separate guards
 * because merging them would mean one handler deciding, at runtime, which kind
 * of caller it has — and that decision is exactly the thing an authorization
 * bug is made of (docs/24-BOOKING-VERIFICATION.md §8).
 *
 * The third authorised path — a signature-verified WhatsApp sender — has no
 * HTTP route at all. It is reached through the conversation, where the
 * signature is what proves anything.
 *
 * ## There is NO public "forgot my booking code" endpoint
 *
 * Not here, not anywhere. A booking reference is public and enumerable, so an
 * endpoint that minted a code from one would BE the code. A reference plus a
 * typed phone number is no better: a phone number is printed on business cards.
 * A guest whose number does not match and who has no account is helped by
 * STAFF — a human confirming who they are talking to is the fallback, by
 * design (§8).
 *
 * ## Regenerating invalidates
 *
 * The previous code stops working the instant this returns. That is the point:
 * a customer asks for a new one precisely when they think somebody else has
 * seen the old one.
 */
final class BookingVerificationController extends Controller
{
    /**
     * Path B — a member of staff, at the counter or on the phone.
     */
    public function staff(string $uuid, Request $request, IssueVerificationCode $issue): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        $issued = $issue->forStaffByUuid($uuid, $user);

        if ($issued === null) {
            return ApiResponse::error(ApiErrorCode::NotFound, 'That booking was not found.');
        }

        return $this->issued($issued['reference'], $issued['code']);
    }

    /**
     * Path A — a signed-in customer, for a booking that is theirs.
     */
    public function customer(string $uuid, Request $request, IssueVerificationCode $issue): JsonResponse
    {
        $account = $request->user('customer-api');

        abort_unless($account instanceof CustomerAccount, 401);

        /*
         * Scoped to the account's own customer IN THE QUERY (inside the
         * Action), so somebody else's booking is NOT FOUND rather than
         * forbidden — and there is no path where a wrong appointment could be
         * returned.
         */
        $issued = $issue->forAccountByUuid($uuid, $account);

        if ($issued === null) {
            return ApiResponse::error(ApiErrorCode::NotFound, 'That booking was not found.');
        }

        return $this->issued($issued['reference'], $issued['code']);
    }

    /**
     * The ONE response that ever carries a raw code.
     *
     * A later GET of the same booking does not, and cannot: the raw value is
     * not stored anywhere to produce (§11).
     */
    private function issued(?string $reference, #[SensitiveParameter] string $code): JsonResponse
    {
        return ApiResponse::data([
            'reference' => $reference,
            // Grouped for reading aloud. The digest was taken over the
            // normalised form, so the hyphen changes nothing.
            'verification_code' => VerificationCode::format($code),
        ], 201);
    }
}
