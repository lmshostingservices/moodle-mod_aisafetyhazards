# mod_aisafetyhazards architecture

## Data

| Table | Holds |
|---|---|
| `aisafetyhazards` | Activity settings: industry, sub-industry, task (and custom names), content language, scene count, picture style, modes, pass mark, shuffle, sounds, voiceover parts, grading, attempts, completion. |
| `aisafetyhazards_scene` | One step of the task on one picture: title, context, English picture description, teaching note. |
| `aisafetyhazards_hazard` | Three real hazards per scene (name, picture anchor, why, likelihood 1–5, consequence 1–5, spot x/y, placed 0 none / 1 teacher / 2 automatic) and up to two distractors. |
| `aisafetyhazards_control` | The labels of each hazard's rows: kind (eliminate, reduce, ppe), text, correct, eliminates, level, dl (likelihood steps), dc (consequence steps), reason. |
| `aisafetyhazards_attempt` | A learner's play-through: mode, scene order with label order (JSON), score, risk removed, times. |
| `aisafetyhazards_choice` | Every label dropped: hazard, kind (hazard, eliminate, reduce, ppe or done), the label, right or wrong. |
| `aisafetyhazards_ailog`, `aisafetyhazards_aireq` | AI rate limit log, and stored LMS Labs requests (key and exact body stored before sending). |

Pictures are in the `sceneimage` file area and clips in `voiceover`, both with the scene id as item id.

## Code

- `local\catalogue`: the task tree (with the administrator's extra tasks), the risk rating, the control levels and languages.
- `local\manager`: scenes, hazards and controls. `clean_control()` makes a wrong label lower nothing and a right one lower something; `hazards_ok()` requires three hazards, one right elimination each, and rows of 2–5 labels with right and wrong answers; `save_scene()` keeps ids so learners' labels still point at them.
- `local\learning`: start/resume, `drop()`, `done_row()` (Test), progress, marks, finish, summary. Practice and Test player data carry no answer key.
- `local\lesson`: the AI-assistant prompt, the picture prompt, draft parsing and import.
- `local\image_positions`: anchors sent with a picture request and the `X-Anchor-Positions` header applied after delivery.
- `local\voiceover`: narrator clips (situation, hazards, controls, feedback).
- `local\ai\lmslabs`, `local\ai\requests`: routes under `/api/moodle/ai-safetyhazards/`; one request per intentional action, never retried automatically.

## Marking

- Needed per hazard: find it, its right elimination and, unless it can be eliminated, every right reduce and PPE label.
- Score = right ÷ (needed + wrong). Risk removed = (start risk − end risk) ÷ (start risk − best-case risk), over all hazards.
