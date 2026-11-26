<?php

namespace App\Http\Controllers;

use App\Services\SubtitleProcessingMethod;
use Illuminate\Http\Request;

class AdminSubtitleProcessingMethodController extends Controller
{
    public function show()
    {
        return response()->json([
            'processing_method' => SubtitleProcessingMethod::current(),
            'default_method' => SubtitleProcessingMethod::DEFAULT,
            'options' => SubtitleProcessingMethod::OPTIONS,
        ], 200, ['Cache-Control' => 'private, no-store']);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'processing_method' => ['required', 'string', 'in:'.implode(',', array_keys(SubtitleProcessingMethod::OPTIONS))],
        ]);
        SubtitleProcessingMethod::save($data['processing_method']);
        return $this->show();
    }
}
