# Changelog

All notable changes to mod_aisafetyhazards are recorded here.

## [v1.0.10] - 2026-10-16

### Changed

- On screens with room, the risk has its own card under the hazard card, in the same style. It shows the gauges and the matrix in large, plus the risk rating, and is sized to the space beside the picture. On short screens the risk stays in the footer.
- Feedback shows under the answers whenever the hazard card has room for it. It moves over the step cards only when it would otherwise make the answers scroll.

## [v1.0.9] - 2026-10-16

### Changed

- New learner flow: one hazard at a time, in order. Name it, eliminate, reduce, then PPE, and only then "Next: Hazard 2". The four steps are cards under the picture. They tick off as they are done, and slide left to the next hazard's cards.
- The step being worked on is a coloured question with lettered answer buttons. Right answers get a drawn tick, wrong ones are crossed out, and in Practice dots show how many right answers are found.
- The hazard to work on bounces on the picture, sends out rings and shows "Hazard n is here". Only that hazard's name is shown on the picture.
- A start-up pop-up for each mode shows the four steps as icons and how the mode is marked.
- Game feel:
  - praise after a right answer, with a streak count;
  - a "Great job!" pop-up with the risk cut, stars and confetti when a hazard is done;
  - a "Scene complete!" pop-up at the end of a scene;
  - new sounds when a card moves on, when the cards slide to the next hazard, and when a hazard is done.
- A done hazard shows a summary of its four steps as small cards, with the risk cut and the Next button.
- The gauges, the matrix and "Risk removed" now sit in the slide's footer. The side panel is kept for the question and its answers.

### Fixed

- The slide never goes below the fold.
  - The picture's size is now measured from the screen, not guessed: the screen height less Moodle's top bar, the title, the step cards and the footer, with 16px kept free above and below.
  - Full screen uses the whole screen with 16px around it.
  - The layout follows window resizes and the course drawer opening or closing.
- The slide keeps the same height from answer to answer, so the page no longer jumps as feedback comes and goes.
- A short page could not scroll the slide up under the top bar. The player now adds the missing space.
- On short screens the step cards, the header and the question are compact. The feedback then shows over the step cards and closes after a while, or with ×.
- Long scene descriptions show two lines with a "More" button. Long answers get a wider side panel.
- If a list of answers is still too long, only that list scrolls. "Done with this row" and "Next: Hazard n" always stay in view.
- On narrow windows the slide stacks into one column, with nothing wider than the screen.

## [v1.0.8] - 2026-10-15

### Fixed

- Clicking several labels of a row quickly (for example two elimination labels) showed an error dialogue ("That label is already placed"): once the first right label finished the row, the others were still sent. Labels that a label before them has made unnecessary are no longer sent, and a label that cannot go there now gets a short note in the feedback instead of an error.
- The feedback under the picture is as wide as the picture; the side panel no longer pushes it down.

## [v1.0.7] - 2026-10-15

### Fixed

- New pictures appear on their scene card as soon as each one is made, with no page refresh: the card (with its hazard spots and buttons) and the step's Next button are redrawn in place. When every missing picture is made, the step says so.
- Scene titles with "&" showed as "&amp;" on the scene cards, the reports and the requests list.

## [v1.0.6] - 2026-10-15

### Fixed

- Pictures seemed to take minutes. While LMS Labs made a picture, the request kept the teacher's Moodle session locked, so every other request from the teacher (other pages, the next picture, "Check again") waited in line behind it. The session is now released before LMS Labs is asked, for pictures, drafts, voiceover and "Check again".
- "Create missing pictures" now makes two pictures at a time (each its own request), so a lesson's pictures are ready in about half the time.

## [v1.0.5] - 2026-10-15

### Fixed

- "Create picture" and "Create missing pictures" showed "TypeError: create(...).finally is not a function" as soon as the request was sent, and the button kept counting. The picture request itself was sent and handled normally; only the page lost track of it. The button now waits for the answer and shows it.

## [v1.0.4] - 2026-10-15

### Changed

- The label box now sits under the picture in every mode, so nothing covers the picture. The picture is a little smaller to make room, the scene title and task sit in one compact block, and the side panel with the gauges and the risk matrix takes the space left (the matrix grows up to 340 pixels, also in full screen).

### Fixed

- A label clicked or dragged while the previous one was still being marked was ignored, so some answers seemed slow or did nothing. Every label now shows "checking" at once and is marked in order.

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
