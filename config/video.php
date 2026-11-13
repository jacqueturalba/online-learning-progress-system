<?php

return [
    // A playback session must last at least two hours; the default covers hour-long lessons with pauses.
    'playback_session_minutes' => max(120, (int) env('VIDEO_PLAYBACK_SESSION_MINUTES', 180)),
];
