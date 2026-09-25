<?php

declare(strict_types=1);

namespace App\View;

/**
 * One mapping from a lifecycle state to a visual tone, so "suspended" is the
 * same red on the centers list, the center workspace and the audit drawer.
 *
 * Presentation only: it never decides what a state MEANS — the domain enums
 * and Actions own that. An unknown state is neutral rather than guessed.
 */
final class StatusTone
{
    private const MAP = [
        'success' => [
            'active', 'settled', 'paid', 'healthy', 'ok', 'published', 'completed', 'complete',
            'succeeded', 'success', 'resolved', 'ready', 'approved', 'confirmed', 'enabled',
            'finalized', 'issued', 'served', 'done', 'live', 'visible', 'current',
        ],
        'warning' => [
            'trial', 'trialing', 'pending', 'partially_paid', 'partial', 'due', 'warning', 'degraded',
            'waiting', 'waiting_on_center', 'waiting_on_customer', 'expiring', 'past_due', 'scheduled',
            'held', 'flagged', 'provisioning', 'in_progress', 'called', 'requested', 'human_requested', 'unknown',
        ],
        'danger' => [
            'suspended', 'overdue', 'failed', 'failure', 'error', 'critical', 'danger', 'cancelled',
            'canceled', 'void', 'voided', 'rejected', 'expired', 'unhealthy', 'down', 'blocked',
            'no_show', 'refunded', 'reversed', 'terminated', 'hidden',
        ],
        'info' => [
            'open', 'new', 'draft', 'booked', 'info', 'queued', 'serving', 'in_service', 'ai_active',
            'human_active', 'answered', 'processing', 'upcoming',
        ],
        'neutral' => [
            'archived', 'inactive', 'closed', 'ended', 'disabled', 'deactivated', 'skipped',
            'abandoned', 'not_configured', 'none', 'unset', 'deleted',
        ],
    ];

    public static function for(?string $status): string
    {
        $status = strtolower(trim((string) $status));

        foreach (self::MAP as $tone => $states) {
            if (in_array($status, $states, true)) {
                return $tone;
            }
        }

        return 'neutral';
    }
}
