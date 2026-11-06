<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class StudentEnrollmentForm extends Model
{
    protected $fillable = ['student_id', 'revision', 'status', 'data', 'id_document_path', 'picture_path', 'privacy_acknowledged', 'privacy_acknowledged_at', 'privacy_version', 'submitted_at', 'reviewed_by', 'reviewed_at', 'revision_reason'];
    protected function casts(): array { return ['data' => 'array', 'privacy_acknowledged' => 'boolean', 'privacy_acknowledged_at' => 'datetime', 'submitted_at' => 'datetime', 'reviewed_at' => 'datetime']; }
    public function student() { return $this->belongsTo(User::class, 'student_id'); }
}
