<?php

declare(strict_types=1);

namespace App\Kernel\Platform\Directory;

use Illuminate\Database\Eloquent\Model;

/**
 * A branch a projected center user may work in.
 *
 * @property int $id
 * @property int $entry_id
 * @property string $tenant_id
 * @property int $branch_id
 * @property array<string, string>|null $branch_name
 */
final class CenterUserBranch extends Model
{
    public $timestamps = false;

    protected $connection = 'control';

    protected $table = 'center_user_directory_branches';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['branch_name' => 'array', 'branch_id' => 'integer'];
    }
}
