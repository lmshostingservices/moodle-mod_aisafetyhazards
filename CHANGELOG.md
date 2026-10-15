# Changelog

All notable changes to mod_aisafetyhazards are recorded here.

## [v1.0.3] - 2026-10-15

### Fixed

- The scene went below the fold on wide screens: the picture is now never taller than the screen leaves room for, so the title, the task, the whole picture with its label box and the side panel fit on one screen. Starting a mode scrolls the player to just under Moodle's top bar.
- The hazard numbers were not centred in their circles.

## [v1.0.2] - 2026-10-15

### Changed

- Release pipeline: no raw web service parameters are left. The scene editor's data and the JSON the player, results and paste preview receive are now PARAM_TEXT (every field was already cleaned as plain text).
- Multi-line function calls now put each argument on its own line (coding style only).

## [v1.0.1] - 2026-10-15

### Fixed

- Creating a picture seemed to hang, then "aireq_busy" appeared. A picture with hazard positions can take LMS Labs longer than the 100 seconds the site waited, so the answer was cut off and the request was left unresolved; asking again for that scene was then refused. The site now waits up to 170 seconds (and keeps the page running for as long), and the button counts the seconds while it waits.
- Asking again for a scene whose picture is unresolved now shows that request with "Check again" and "Dismiss" instead of an error. Nothing is sent again with a new key, so nothing can be charged twice.
- The scene card says when its picture is waiting, and "Create missing pictures" skips that scene.

## [v1.0.0] - 2026-10-15

First release, built on AI Soft Skills.

### Added

- Work task picker: industry, then sub-industry, then task (10 industries, 30 sub-industries, 120 tasks), with "Other" at each level and extra tasks from the site settings.
- Scenes with three hazards and up to two distractor hazard labels, played on the picture with a see-through label box (Hazard, Eliminate, Reduce the risk, PPE required), each row with an icon and hover help. Drag and drop, or tap to pick and tap to drop.
- Tagged 5 × 5 risk model: each control has a hierarchy level and lowers the likelihood and/or the consequence; the right elimination removes the hazard, and "cannot be eliminated because…" can be the right answer.
- Likelihood and consequence gauges, the risk matrix, risk rating badges and a "risk removed" bar.
- Study, Practice and Test modes, with grading, attempts, pass mark and completion rules.
- Hazard spots placed automatically from LMS Labs picture positions (when advertised), never moving a spot the teacher placed; guessed spots otherwise.
- Copy-the-prompt and paste-the-reply creation with a free preview, and LMS Labs scene drafts.
- Narrator voiceover for the situation, the hazards and the controls.
- Scene editor with collapsible hazard cards, control tags, reasons and the spot picture.
- Reports, backup and restore, privacy provider.
