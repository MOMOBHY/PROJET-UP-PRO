<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrientationStudent extends Model
{
    use HasFactory;

    protected $fillable = ['user_id', 'student_number', 'name', 's1', 's2', 'choices', 'estimate', 'assignment'];

    protected function casts(): array
    {
        return ['s1' => 'array', 's2' => 'array', 'choices' => 'array'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
