<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\SettingKey;
use Database\Factories\SettingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

/**
 * Key/value settings (string PK `key`, JSON `value`). Use the typed helpers:
 *   Setting::get(SettingKey::ReportDeadlineDays)      → 45 (falls back to SettingKey::defaultValue())
 *   Setting::put(SettingKey::ReportDeadlineDays, 60)   (audited through LogsActivity)
 * Never store the initial-password pattern here (D-24).
 */
class Setting extends Model
{
    /** @use HasFactory<SettingFactory> */
    use HasFactory, LogsActivity;

    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $fillable = [
        'key',
        'value',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('setting')
            ->logOnly(['key', 'value'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public static function get(SettingKey|string $key, mixed $default = null): mixed
    {
        $enum = $key instanceof SettingKey ? $key : SettingKey::tryFrom($key);
        $row = static::query()->find($enum?->value ?? $key);

        if ($row !== null) {
            return $row->value;
        }

        return $default ?? $enum?->defaultValue();
    }

    public static function put(SettingKey|string $key, mixed $value): self
    {
        $key = $key instanceof SettingKey ? $key->value : $key;

        $setting = static::query()->find($key) ?? new static(['key' => $key]);
        $setting->value = $value;
        $setting->save();

        return $setting;
    }

    public static function int(SettingKey $key): int
    {
        return (int) static::get($key);
    }
}
