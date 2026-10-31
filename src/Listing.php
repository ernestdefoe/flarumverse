<?php

namespace ErnestDefoe\Flarumverse;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;

/**
 * A forum in the directory (directory host only).
 *
 * @property int $id
 * @property string $url
 * @property string $name
 * @property string|null $description
 * @property string|null $logo
 * @property string|null $icon
 * @property string|null $logo_bg
 * @property string $public_key
 * @property string $topic
 * @property string $language
 * @property string $status
 * @property int $members
 * @property int $discussions
 * @property Carbon|null $last_activity_at
 * @property string|null $latest JSON list of {title,url}
 * @property Carbon|null $checked_at
 * @property Carbon|null $unreachable_since
 * @property Carbon $created_at
 * @property Carbon $updated_at
 */
class Listing extends AbstractModel
{
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const REJECTED = 'rejected';

    protected $table = 'flarumverse_directory';

    public $timestamps = true;

    protected $casts = [
        'members' => 'integer',
        'discussions' => 'integer',
        'last_activity_at' => 'datetime',
        'checked_at' => 'datetime',
        'unreachable_since' => 'datetime',
    ];
}
