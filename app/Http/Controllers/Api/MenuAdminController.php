<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Kernel\Authorization\Permission;
use App\Kernel\Http\ApiErrorCode;
use App\Kernel\Http\ApiResponse;
use App\Kernel\Identity\Models\User;
use App\Modules\Menu\Application\MenuPublisher;
use App\Modules\Menu\Domain\MenuPresentation;
use App\Modules\Menu\Domain\Models\MenuVersion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * Menu appearance: read the draft, save it, publish it, roll it back.
 *
 * Invalid presentation input surfaces as a 422 rather than a 500. The
 * validation itself lives in {@see MenuPresentation},
 * so the API, the Livewire editor and any future setup wizard all reject the
 * same things for the same reasons.
 */
final class MenuAdminController extends Controller
{
    public function show(Request $request, MenuPublisher $publisher): JsonResponse
    {
        $this->require($this->user($request), Permission::MenuView);

        $draft = $publisher->draft();
        $published = $publisher->published();

        return ApiResponse::data([
            'draft' => $this->present($draft),
            'published' => $published === null ? null : $this->present($published),
            'history' => MenuVersion::query()->archived()->limit(20)->get()
                ->map(fn (MenuVersion $v): array => [
                    'uuid' => $v->uuid,
                    'version' => $v->version,
                    'template' => $v->template_key,
                    'published_at' => $v->published_at?->toIso8601String(),
                ])->values()->all(),
            'catalog' => [
                'templates' => array_keys((array) config('menu.templates', [])),
                'sections' => array_keys((array) config('menu.sections', [])),
                'theme_options' => config('menu.theme.options', []),
            ],
        ]);
    }

    public function saveDraft(Request $request, MenuPublisher $publisher): JsonResponse
    {
        $user = $this->user($request);

        $input = $request->validate([
            'template_key' => ['required', 'string'],
            'theme' => ['array'],
            'sections' => ['required', 'array', 'min:1'],
            'sections.*.key' => ['required', 'string'],
            'sections.*.visible' => ['required', 'boolean'],
            'sections.*.config' => ['array'],
        ]);

        try {
            $draft = $publisher->saveDraft($input, $user);
        } catch (InvalidArgumentException $e) {
            // The presentation validator rejects unknown templates, unlisted
            // sections and malformed colours. That is a 422, not a crash.
            return ApiResponse::error(ApiErrorCode::ValidationFailed, $e->getMessage(), status: 422);
        }

        return ApiResponse::data($this->present($draft));
    }

    public function publish(Request $request, MenuPublisher $publisher): JsonResponse
    {
        return ApiResponse::data($this->present($publisher->publish($this->user($request))));
    }

    public function rollback(string $uuid, Request $request, MenuPublisher $publisher): JsonResponse
    {
        $user = $this->user($request);

        /** @var MenuVersion|null $target */
        $target = MenuVersion::query()->where('uuid', $uuid)->first();

        $restored = $publisher->rollbackTo($target ?? throw new ModelNotFoundException, $user);

        return ApiResponse::data($this->present($restored));
    }

    /**
     * @return array<string, mixed>
     */
    private function present(MenuVersion $version): array
    {
        return [
            'uuid' => $version->uuid,
            'version' => $version->version,
            'status' => $version->status->value,
            'template_key' => $version->template_key,
            'theme' => $version->theme,
            'sections' => $version->sections,
            'published_at' => $version->published_at?->toIso8601String(),
        ];
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new AuthorizationException('Not authenticated.');
    }

    private function require(User $user, Permission $permission): void
    {
        if (! $user->hasPermission($permission)) {
            throw new AuthorizationException('You may not view the menu settings.');
        }
    }
}
