<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use LogicException;

class User extends Authenticatable
{
    use HasFactory, Notifiable;
    protected $fillable = ['name', 'email', 'password', 'role', 'pace_group', 'is_active'];
    protected $hidden = ['password', 'remember_token', 'pseudocode', 'pace_group', 'is_active'];
    protected function casts(): array { return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'is_active' => 'boolean']; }
    public function lessonProgress() { return $this->hasMany(StudentLessonProgress::class, 'student_id'); }
    public function categoryProgress() { return $this->hasMany(StudentCategoryProgress::class, 'student_id'); }
    public function zoomAssignment() { return $this->hasOne(StudentZoomAssignment::class, 'student_id'); }
    public function realizations() { return $this->hasMany(StudentRealization::class, 'student_id'); }
    public function paceGroup() { return $this->belongsTo(PaceGroup::class, 'pace_group', 'code'); }

    protected static function booted(): void
    {
        static::creating(function (User $user): void {
            if ($user->role === 'student') {
                do {
                    $user->pseudocode = 'STU-'.Str::upper(Str::random(10));
                } while (static::where('pseudocode', $user->pseudocode)->exists());
            }
        });

        static::updating(function (User $user): void {
            if ($user->isDirty('pseudocode')) {
                throw new LogicException('A student pseudocode is permanent and cannot be changed.');
            }
        });
    }
}
