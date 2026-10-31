<?php

namespace ErnestDefoe\Flarumverse;

use Carbon\Carbon;
use Flarum\Database\AbstractModel;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int $partner_id
 * @property int $remote_id
 * @property string $title
 * @property string|null $excerpt
 * @property string|null $author
 * @property string|null $tag
 * @property int $replies
 * @property string $url
 * @property Carbon $posted_at
 * @property Partner $partner
 */
class FlarumverseThread extends AbstractModel
{
    protected $table = 'flarumverse_threads';

    protected $casts = [
        'posted_at' => 'datetime',
        'remote_id' => 'integer',
        'replies' => 'integer',
    ];

    public function partner(): BelongsTo
    {
        return $this->belongsTo(Partner::class, 'partner_id');
    }
}
