from __future__ import annotations

import json
import sys
import tempfile
import unittest
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

from align_bounded_windows import Window, merge_window_results, read_windows, timestamp_order_violations, unmapped_token_positions


def aligned(word: str, start: float, end: float, score: float = 0.9) -> dict:
    return {"word": word, "start": start, "end": end, "score": score}


class BoundedWindowTests(unittest.TestCase):
    def test_repeated_words_are_merged_by_source_position_across_overlap(self) -> None:
        source = ["we", "said", "yes,", "we", "said", "yes."]
        windows = [Window(0, 4, 0.0, 4.5), Window(2, 6, 3.5, 8.0)]
        results = [
            [aligned("we", 0.2, 0.5), aligned("said", 0.6, 1.0), aligned("yes,", 1.1, 1.4), aligned("we", 4.0, 4.4)],
            [aligned("yes,", 3.8, 4.1), aligned("we", 4.2, 4.5), aligned("said", 4.6, 5.0), aligned("yes.", 5.1, 5.4)],
        ]

        words, failures, duplicates = merge_window_results(source, windows, results, 8.0)

        self.assertEqual([word["text"] for word in words], source)
        self.assertEqual(words[2]["text"], "yes,")
        self.assertEqual(words[3]["text"], "we")
        self.assertEqual(failures, [])
        self.assertEqual(duplicates, 2)
        self.assertEqual([word["start"] for word in words], [0.2, 0.6, 1.1, 4.2, 4.6, 5.1])

    def test_word_crossing_core_boundary_uses_overlapped_candidate(self) -> None:
        source = ["a", "word", "continues", "here."]
        # Both windows contain the boundary token and audio around its full span.
        windows = [Window(0, 3, 0.0, 5.5), Window(1, 4, 4.5, 9.0)]
        results = [
            [aligned("a", 3.8, 4.0), aligned("word", 4.2, 4.9), aligned("continues", 5.0, 5.4)],
            [aligned("word", 4.2, 4.9), aligned("continues", 5.0, 5.4), aligned("here.", 5.5, 5.9)],
        ]
        words, _, duplicates = merge_window_results(source, windows, results, 9.0)
        self.assertEqual(duplicates, 2)
        self.assertEqual((words[1]["start"], words[1]["end"]), (4.2, 4.9))
        self.assertEqual([word["text"] for word in words], source)

    def test_punctuation_order_and_relative_times_are_preserved(self) -> None:
        source = ["Wait—", "what?", "Yes!"]
        words, _, _ = merge_window_results(
            source,
            [Window(0, 3, 0.0, 5.0)],
            [[aligned("Wait—", 1.0, 1.3), aligned("what?", 1.4, 1.7), aligned("Yes!", 2.0, 2.3)]],
            5.0,
        )
        self.assertEqual([word["text"] for word in words], source)
        self.assertEqual([word["start"] for word in words], [1.0, 1.4, 2.0])
        self.assertEqual(timestamp_order_violations(words), [])
        self.assertEqual(words[0]["start"], 1.0)  # Python leaves lesson offset application to Laravel.

    def test_backwards_timestamps_are_reported_without_reordering_words(self) -> None:
        source = ["first", "second"]
        words, _, _ = merge_window_results(
            source,
            [Window(0, 2, 0.0, 5.0)],
            [[aligned("first", 2.0, 2.4), aligned("second", 1.0, 1.4)]],
            5.0,
        )
        self.assertEqual([word["text"] for word in words], source)
        self.assertEqual(timestamp_order_violations(words), [1])

    def test_low_score_and_missing_word_are_not_given_invented_times(self) -> None:
        words, _, _ = merge_window_results(
            ["clear", "weak", "unknown"],
            [Window(0, 3, 0.0, 5.0)],
            [[aligned("clear", 1.0, 1.3), aligned("weak", 1.4, 1.7, -0.1)]],
            5.0,
        )
        self.assertEqual(words[0]["status"], "aligned")
        self.assertEqual(words[1]["status"], "review_low_score")
        self.assertIsNone(words[2]["start"])
        self.assertIsNone(words[2]["end"])
        self.assertEqual(words[2]["status"], "missing_or_unreliable")

    def test_partial_window_failure_keeps_uncovered_tokens_untimed(self) -> None:
        source = ["one", "two", "three", "four"]
        windows = [Window(0, 3, 0.0, 3.0), Window(2, 4, 2.0, 4.0)]
        words, failures, _ = merge_window_results(
            source,
            windows,
            [[aligned("one", 0.1, 0.3), aligned("two", 0.4, 0.6), aligned("three", 0.7, 0.9)], None],
            4.0,
        )
        self.assertEqual(failures, [{"window_index": 1, "error": "alignment_failed"}])
        self.assertEqual(words[2]["status"], "aligned")  # covered by the successful overlap
        self.assertEqual(words[3]["status"], "missing_or_unreliable")
        self.assertIsNone(words[3]["start"])

    def test_all_failed_windows_leave_every_source_token_present_and_untimed(self) -> None:
        source = ["repeat", "repeat", "again."]
        words, failures, _ = merge_window_results(
            source,
            [Window(0, 3, 0.0, 5.0)],
            [None],
            5.0,
        )
        self.assertEqual([word["text"] for word in words], source)
        self.assertTrue(all(word["start"] is None and word["end"] is None for word in words))
        self.assertEqual(len(failures), 1)
        self.assertEqual(unmapped_token_positions(source, []), [0, 1, 2])

    def test_empty_and_incomplete_window_maps_are_rejected(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "windows.json"
            path.write_text(json.dumps({"windows": []}), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "non-empty windows"):
                read_windows(path, 2, 10.0)
            path.write_text(json.dumps({"windows": [{"token_start": 0, "token_end": 1, "audio_start": 0, "audio_end": 2}]}), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "does not cover"):
                read_windows(path, 2, 10.0)

    def test_window_map_requires_explicit_monotonic_audio_and_token_anchors(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "windows.json"
            path.write_text(json.dumps({"anchor_source": "reviewed timestamps", "windows": [
                {"token_start": 0, "token_end": 2, "audio_start": 0, "audio_end": 5},
                {"token_start": 1, "token_end": 3, "audio_start": 4, "audio_end": 8},
            ]}), encoding="utf-8")
            windows, source = read_windows(path, 3, 8.0)
            self.assertEqual(source, "reviewed timestamps")
            self.assertEqual(windows[1].token_start, 1)
            path.write_text(json.dumps({"windows": [
                {"token_start": 0, "token_end": 2, "audio_start": 3, "audio_end": 5},
                {"token_start": 1, "token_end": 3, "audio_start": 2, "audio_end": 8},
            ]}), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "ordered"):
                read_windows(path, 3, 8.0)
            path.write_text(json.dumps({"windows": [
                {"token_start": 0, "token_end": 1, "audio_start": 0, "audio_end": 2},
                {"token_start": 1, "token_end": 2, "audio_start": 2, "audio_end": 4},
            ]}), encoding="utf-8")
            with self.assertRaisesRegex(ValueError, "must overlap"):
                read_windows(path, 2, 4.0)

    def test_output_shape_matches_existing_executor_contract(self) -> None:
        words, _, _ = merge_window_results(
            ["Hello,", "world."],
            [Window(0, 2, 0.0, 2.0)],
            [[aligned("Hello,", 0.1, 0.4), aligned("world.", 0.5, 0.9)]],
            2.0,
        )
        self.assertEqual([word["text"] for word in words], ["Hello,", "world."])
        self.assertEqual(set(words[0]), {"text", "start", "end", "score", "status"})
        self.assertEqual(sum(word["status"] == "aligned" for word in words), 2)


if __name__ == "__main__":
    unittest.main()
