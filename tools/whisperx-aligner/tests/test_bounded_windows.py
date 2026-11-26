import unittest
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from align_bounded_windows import merge_window_results


class BoundedMergeTests(unittest.TestCase):
    def test_repeated_words_are_deduplicated_by_original_token_position(self):
        source = ["go", "go", "now."]
        windows = [
            {"token_start": 0, "token_end": 2, "audio_start": 0.0, "audio_end": 2.0},
            {"token_start": 1, "token_end": 3, "audio_start": 1.0, "audio_end": 3.0},
        ]
        results = [
            [{"word": "go", "start": 0.4, "end": 0.7}, {"word": "go", "start": 1.0, "end": 1.2}],
            [{"word": "go", "start": 1.02, "end": 1.21}, {"word": "now.", "start": 2.0, "end": 2.3}],
        ]
        words, errors, duplicate_candidates = merge_window_results(source, windows, results, 3.0)
        self.assertEqual([word["text"] for word in words], source)
        self.assertEqual([word["source_token_index"] for word in words], [0, 1, 2])
        self.assertEqual(duplicate_candidates, 1)
        self.assertEqual(errors, [])

    def test_boundary_token_uses_the_candidate_farther_from_crop_edge(self):
        words, errors, duplicates = merge_window_results(
            ["a", "crossing", "word"],
            [
                {"token_start": 0, "token_end": 2, "audio_start": 0.0, "audio_end": 2.0},
                {"token_start": 1, "token_end": 3, "audio_start": 1.0, "audio_end": 3.0},
            ],
            [
                [{"word": "a", "start": 0.2, "end": 0.4}, {"word": "crossing", "start": 1.8, "end": 1.95}],
                [{"word": "crossing", "start": 1.45, "end": 1.7}, {"word": "word", "start": 2.0, "end": 2.4}],
            ],
            3.0,
        )
        self.assertEqual(words[1]["start"], 1.45)
        self.assertEqual(errors, [])
        self.assertEqual(duplicates, 1)

    def test_failed_windows_leave_words_untimed_and_report_error(self):
        words, errors, _ = merge_window_results(
            ["one", "two"],
            [{"token_start": 0, "token_end": 2, "audio_start": 0.0, "audio_end": 2.0}],
            [None],
            2.0,
        )
        self.assertTrue(all(word["start"] is None for word in words))
        self.assertEqual(errors[0]["error_type"], "WindowAlignmentFailed")

    def test_punctuation_and_source_order_are_kept(self):
        words, _, _ = merge_window_results(
            ["Hello,", "hello!", "world."],
            [{"token_start": 0, "token_end": 3, "audio_start": 0.0, "audio_end": 3.0}],
            [[{"word": "Hello", "start": 0.1, "end": 0.3}, {"word": "hello", "start": 0.4, "end": 0.6}, {"word": "world", "start": 1.0, "end": 1.4}]],
            3.0,
        )
        self.assertEqual([word["text"] for word in words], ["Hello,", "hello!", "world."])
        self.assertLessEqual(words[0]["start"], words[1]["start"])
        self.assertLessEqual(words[1]["start"], words[2]["start"])


if __name__ == "__main__":
    unittest.main()
