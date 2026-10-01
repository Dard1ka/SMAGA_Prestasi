<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\FilePurpose;
use Database\Factories\FileFactory;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/**
 * A stored file on the private disk. Create rows only through App\Domain\Files (uploads) or the
 * letter generator; stream them only through the policy-checked file controller.
 * (Import it as `App\Models\File`; alias it if you also need the Illuminate `File` facade.)
 */
class File extends Model
{
    /** @use HasFactory<FileFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'disk',
        'path',
        'original_name',
        'mime',
        'size',
        'sha256',
        'purpose',
        'owner_user_id',
    ];

    /** @var list<string> */
    protected $hidden = [
        'disk',
        'path',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'purpose' => FilePurpose::class,
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function storage(): Filesystem
    {
        return Storage::disk($this->disk);
    }

    public function isImage(): bool
    {
        return str_starts_with($this->mime, 'image/');
    }
}
