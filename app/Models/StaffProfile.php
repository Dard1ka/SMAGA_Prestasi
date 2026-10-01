<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\StaffProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Staff details for letters (NIP, pangkat, jabatan) and the UI salutation (Bu/Pak/Mas). */
class StaffProfile extends Model
{
    /** @use HasFactory<StaffProfileFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'user_id',
        'honorific',
        'nip',
        'rank',
        'position',
    ];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
