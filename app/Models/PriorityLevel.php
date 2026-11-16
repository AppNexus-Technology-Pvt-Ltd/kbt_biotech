<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PriorityLevel extends Model
{
    protected $fillable = ['code', 'name', 'color', 'response_minutes', 'resolution_minutes', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'response_minutes' => 'integer', 'resolution_minutes' => 'integer'];
    }

    /** 30 => "30 Minutes", 240 => "4 Hours", 4320 => "3 Days". */
    public static function humanize(int $minutes): string
    {
        foreach ([['Day', 1440], ['Hour', 60]] as [$unit, $size]) {
            if ($minutes >= $size && $minutes % $size === 0) {
                $n = intdiv($minutes, $size);

                return $n.' '.$unit.($n === 1 ? '' : 's');
            }
        }

        return $minutes.' Minute'.($minutes === 1 ? '' : 's');
    }
}
