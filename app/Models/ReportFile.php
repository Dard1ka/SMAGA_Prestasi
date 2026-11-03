<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\ReportFileKind;
use Database\Factories\ReportFileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Evidence attached to a report (sertifikat, foto, …). */
class ReportFile extends Model
{
    /** @use HasFactory<ReportFileFactory> */
    use HasFactory, HasUlids;

    /** @var list<string> */
    protected $fillable = [
        'report_id',
        'kind',
        'file_id',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => ReportFileKind::class,
        ];
    }

    /** @return BelongsTo<Report, $this> */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /** @return BelongsTo<File, $this> */
    public function file(): BelongsTo
    {
        return $this->belongsTo(File::class);
    }
}
