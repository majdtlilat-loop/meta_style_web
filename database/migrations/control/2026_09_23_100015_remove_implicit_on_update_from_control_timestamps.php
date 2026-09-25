<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Stops MariaDB/MySQL from rewriting historical instants on every UPDATE.
 *
 * With `explicit_defaults_for_timestamp` OFF (MariaDB's default before 10.10),
 * the FIRST non-nullable TIMESTAMP column of a table silently becomes
 * `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`. So recording a
 * payment moved the invoice's `issued_at`, reversing a payment moved its
 * `received_at`, finishing an operation moved its `started_at`, and so on.
 *
 * This keeps each column's type, precision, nullability and insert default,
 * and removes only the implicit ON UPDATE. Where the server never added it
 * (explicit defaults ON, e.g. MySQL 8) nothing is altered. No data changes:
 * values already rewritten cannot be recovered from the schema.
 */
return new class extends Migration
{
    protected $connection = 'control';

    /** @var array<string, string> table => column */
    private const COLUMNS = [
        'saas_invoices' => 'issued_at',
        'saas_payments' => 'received_at',
        'plan_price_history' => 'effective_from',
        'platform_audit_logs' => 'occurred_at',
        'subscription_history' => 'occurred_at',
        'subscription_scheduled_changes' => 'effective_at',
        'support_tickets' => 'last_activity_at',
        'support_ticket_history' => 'occurred_at',
        'tenant_lifecycle_history' => 'occurred_at',
        'tenant_operations' => 'started_at',
    ];

    public function up(): void
    {
        $db = DB::connection($this->connection);
        if (! in_array($db->getDriverName(), ['mysql', 'mariadb'], true)) {
            return;
        }
        foreach (self::COLUMNS as $table => $column) {
            $info = $db->selectOne(
                'select datetime_precision as p, extra from information_schema.columns where table_schema = database() and table_name = ? and column_name = ? and data_type = ?',
                [$table, $column, 'timestamp'],
            );
            if ($info === null || ! str_contains(mb_strtolower((string) $info->extra), 'on update')) {
                continue;
            }
            $precision = (int) $info->p;
            $type = $precision > 0 ? 'TIMESTAMP('.$precision.')' : 'TIMESTAMP';
            $now = $precision > 0 ? 'CURRENT_TIMESTAMP('.$precision.')' : 'CURRENT_TIMESTAMP';
            $db->statement(sprintf('ALTER TABLE `%s` MODIFY `%s` %s NOT NULL DEFAULT %s', $table, $column, $type, $now));
        }
    }

    public function down(): void
    {
        // Re-adding an implicit ON UPDATE would re-introduce the bug; nothing to undo.
    }
};
