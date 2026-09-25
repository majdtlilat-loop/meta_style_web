<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Same fix as the control migration of the same name: with
 * `explicit_defaults_for_timestamp` OFF, the first non-nullable TIMESTAMP of a
 * table gets an implicit ON UPDATE CURRENT_TIMESTAMP — so touching an
 * activation or reset token row moved its `expires_at` to "now", and an
 * update to an audit row would move `occurred_at`.
 *
 * Only the implicit ON UPDATE is removed; type, precision, nullability and the
 * insert default stay. A server that never added it is left untouched.
 */
return new class extends Migration
{
    /** @var array<string, string> table => column */
    private const COLUMNS = [
        'audit_logs' => 'occurred_at',
        'password_reset_tokens' => 'expires_at',
        'staff_activation_tokens' => 'expires_at',
    ];

    public function up(): void
    {
        $db = DB::connection(Schema::getConnection()->getName());
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
