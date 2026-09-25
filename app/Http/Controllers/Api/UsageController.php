<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Kernel\Usage\Usage;
use App\Kernel\Usage\UsageSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * What this center has used of its AI and WhatsApp allowances, this period.
 *
 * ## `settings.view`, not a new permission
 *
 * Usage is a commercial read about the center as a whole, which is exactly what
 * `settings.view` already means. A `usage.view` code would be a
 * micro-permission nothing else ever distinguishes — the thing Phase 13 §61
 * explicitly asks to avoid — and every role that would be granted it already
 * holds this one.
 *
 * Note the asymmetry with the CONFIG permissions: `whatsapp.manage` and
 * `ai.manage` write credentials and change behaviour, and are deliberately not
 * what is checked here. A manager may read the numbers without being trusted
 * with the connection.
 *
 * ## Separate products, never one number
 *
 * AI and WhatsApp are reported separately. Merging them would make an inbound
 * message a customer sent indistinguishable from an AI run the center paid for
 * — not remotely the same cost or the same fact (docs/26-USAGE-QUOTAS.md §14).
 *
 * ## This center only
 *
 * The counters are in the center's own database, so cross-tenant reading is
 * impossible by construction rather than by a filter somebody has to remember.
 */
final class UsageController extends Controller
{
    public function __invoke(Request $request, Usage $usage): JsonResponse
    {
        $user = $request->user();

        abort_unless($user instanceof User, 401);

        if (! $user->hasPermission(Permission::SettingsView)) {
            abort(403, 'You may not view usage.');
        }

        return ApiResponse::data([
            'usage' => [
                'ai' => $this->group($usage, 'ai'),
                'advanced_reports' => $this->group($usage, 'advanced_reports'),
                'whatsapp' => $this->group($usage, 'whatsapp'),
            ],
            /*
             * Deliberately absent: any unit cost, any provider price, any Meta
             * Style margin. A center is shown what THEY used against what THEY
             * bought — the platform's economics are not theirs to read (§14).
             */
        ]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function group(Usage $usage, string $group): array
    {
        return array_map(
            static fn (UsageSummary $summary): array => $summary->toArray(),
            $usage->summaries($group),
        );
    }
}
