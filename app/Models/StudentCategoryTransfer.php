<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentCategoryTransfer extends Model
{
    protected $fillable = ['student_id', 'from_category_id', 'to_category_id', 'transferred_by', 'transferred_at'];

    protected function casts(): array
    {
        return ['transferred_at' => 'datetime'];
    }

    public function student() { return $this->belongsTo(User::class, 'student_id'); }
    public function fromCategory() { return $this->belongsTo(Category::class, 'from_category_id')->withTrashed(); }
    public function toCategory() { return $this->belongsTo(Category::class, 'to_category_id')->withTrashed(); }
    public function admin() { return $this->belongsTo(User::class, 'transferred_by'); }
}
