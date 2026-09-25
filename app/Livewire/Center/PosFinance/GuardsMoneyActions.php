<?php

declare(strict_types=1);

namespace App\Livewire\Center\PosFinance;

use App\Kernel\Entitlements\Exceptions\EntitlementRequired;
use App\Kernel\Http\ApiProblem;
use App\Kernel\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * One way for the till and the money screens to run an Action.
 *
 * The Action decides; the screen only reports. A domain refusal, a missing
 * permission, a missing entitlement or a record that is not there becomes the
 * component's `$error`, in the viewer's language ({@see Refusals}), and the
 * page stays usable. Anything else is a real fault and is not swallowed.
 *
 * Expects the component to declare `public string $error` and
 * `public string $saved`.
 */
trait GuardsMoneyActions
{
    /**
     * @return bool whether the work completed
     */
    protected function attempt(callable $work): bool
    {
        $this->reset(['error', 'saved']);

        try {
            $work();

            return true;
        } catch (NotFoundHttpException) {
            $this->error = (string) __('That could not be found.');
        } catch (ValidationException $invalid) {
            $this->error = Refusals::text($this->firstMessage($invalid));
        } catch (EntitlementRequired $missing) {
            $this->error = self::entitlementRefusal($missing);
        } catch (AuthorizationException $refused) {
            $this->error = Refusals::text($refused->getMessage());
        } catch (RuntimeException $failure) {
            if (! $failure instanceof ApiProblem) {
                throw $failure;
            }

            $this->error = Refusals::text($failure->getMessage());
        }

        return false;
    }

    protected static function entitlementRefusal(EntitlementRequired $missing): string
    {
        $key = 'manager_pos.entitlements.'.$missing->entitlement;
        $feature = __($key);

        return (string) __('manager_pos.errors.entitlement', ['feature' => $feature === $key ? $missing->entitlement : $feature]);
    }

    protected function user(): User
    {
        /** @var User $user */
        $user = auth('web')->user();

        return $user;
    }

    private function firstMessage(ValidationException $invalid): string
    {
        foreach ($invalid->errors() as $messages) {
            foreach ($messages as $message) {
                return (string) $message;
            }
        }

        return $invalid->getMessage();
    }
}
