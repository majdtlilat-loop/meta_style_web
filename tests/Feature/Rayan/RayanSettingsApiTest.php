<?php

declare(strict_types=1);

use App\Modules\Rayan\Application\RayanSettings;
use Illuminate\Support\Facades\DB;

/*
|--------------------------------------------------------------------------
| The assistant settings API goes through the same Action as the Manager
|--------------------------------------------------------------------------
|
| `ConfigureAssistant` checks the `rayan_ai` entitlement and `ai.manage`, keeps
| the approved-model rule and announces the change for the audit trail. The
| tenant API used to save the settings row directly — no entitlement check and
| no audit entry. It is not a way around either.
|
*/

afterEach(function (): void {
    $this->tearDownRegisteredCenters();
});

it('refuses the settings change without the assistant entitlement, and audits it with it', function (): void {
    $center = $this->registerCenter('Rayan Api Center', 'owner@rayan-api.test');
    $headers = $this->tokenHeaders($this->apiTokenFor($center['tenant']));
    $url = '/api/v1/tenant/rayan/settings';
    $body = ['enabled' => true, 'model' => null, 'custom_instruction' => 'Warm and brief.', 'takeover_on_request' => false];

    $this->asCenter($center['tenant'], fn () => $this->revokeEntitlement('rayan_ai'));
    $this->withHeaders($headers)->putJson($url, $body)->assertForbidden();

    $this->asCenter($center['tenant'], function (): void {
        expect(app(RayanSettings::class)->customInstruction())->not->toBe('Warm and brief.');
    });

    $this->asCenter($center['tenant'], fn () => $this->grantAssistant());
    $this->withHeaders($headers)->putJson($url, $body)->assertOk()
        ->assertJsonPath('data.settings.custom_instruction', 'Warm and brief.');

    $this->asCenter($center['tenant'], function (): void {
        $audit = DB::connection('tenant')->table('audit_logs')->where('action', 'rayan.settings.updated')->latest('id')->first();
        expect($audit)->not->toBeNull()
            // The tone is never copied into the audit trail.
            ->and((string) json_encode($audit))->not->toContain('Warm and brief.');
    });
});
