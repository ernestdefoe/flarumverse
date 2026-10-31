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
 * @property string|null $icon
 * @property string|null $logo
 * @property string|null $color
 * @property Carbon|null $look_checked_at
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
        'look_checked_at' => 'datetime',
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

    /**
     * Keep how the forum looks, from its about document. Images are shown as
     * <img> on the Flarumverse page, so only https images on the forum's own
     * address are kept (the directory's rule); the colour only as #rrggbb.
     *
     * @param array<string, mixed> $about
     */
    public function applyLook(array $about): void
    {
        $image = fn ($u) => is_string($u) && strlen($u) <= 400 && str_starts_with($u, 'https://') && Address::belongsTo($u, $this->url)
            && preg_match('#\.(png|jpe?g|gif|webp|avif|svg|ico)(\?[^/]*)?$#i', $u) ? $u : null;

        $this->icon = $image($about['icon'] ?? null);
        $this->logo = $image($about['logo'] ?? null);
        $color = is_string($about['color'] ?? null) ? strtolower($about['color']) : '';
        $this->color = preg_match('/^#[0-9a-f]{6}$/', $color) ? $color : null;
        $this->look_checked_at = Carbon::now();
    }
}
