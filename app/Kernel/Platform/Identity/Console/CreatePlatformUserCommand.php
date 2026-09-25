<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Identity\Console;

use App\Kernel\Platform\Identity\Actions\CreatePlatformUser;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

final class CreatePlatformUserCommand extends Command
{
    protected $signature = 'metastyle:platform-user:create {email} {--name=}';

    protected $description = 'Create a platform user who must enroll in MFA on first sign-in';

    public function handle(CreatePlatformUser $create): int
    {
        $email = (string) $this->argument('email');
        $name = (string) ($this->option('name') ?: $this->ask('Name'));
        $password = (string) $this->secret('Initial password (not stored in shell history)');

        $validator = Validator::make(compact('email', 'name', 'password'), [
            'email' => ['required', 'email:rfc', 'max:190', 'unique:control.platform_users,email'],
            'name' => ['required', 'string', 'min:2', 'max:190'],
            'password' => ['required', 'string', Password::min(12)->uncompromised()],
        ]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user = $create($name, $email, $password);
        $this->components->info("Platform user {$user->email} created. MFA enrollment is required at first sign-in.");

        return self::SUCCESS;
    }
}
