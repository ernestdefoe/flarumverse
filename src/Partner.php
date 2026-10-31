<?php

namespace ErnestDefoe\Flarumverse;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $url
 * @property string $name
 * @property string|null $description
 * @property string $public_key
 * @property string $status
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property Carbon|null $paired_at
 * @property Carbon|null $last_attempt_at
 * @property int $failures
 * @property string|null $last_error
 */
class Partner extends AbstractModel
{
    public const INCOMING = 'incoming';
    public const OUTGOING = 'outgoing';
    public const PAIRED = 'paired';
    public const DECLINED = 'declined';
    public const REFUSED = 'refused';

    protected $table = 'flarumverse_partners';

    public $timestamps = true;

    protected $casts = [
        'paired_at' => 'datetime',
        'last_attempt_at' => 'datetime',
        'failures' => 'integer',
    ];

    public function threads(): HasMany
    {
        return $this->hasMany(FlarumverseThread::class, 'partner_id');
    }

    public static function forUrl(string $url): ?self
    {
        return static::query()->where('url', $url)->first();
    }
}
