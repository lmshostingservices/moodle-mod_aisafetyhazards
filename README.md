# AI Safety Hazards (mod_aisafetyhazards)

A Moodle activity for hazard identification and risk control. The teacher picks a work task (industry › sub-industry › task, for example *Construction › Civil construction › Trenching*). Each scene is one picture of a step of that task with **three hazards** for the learner to find and control.

On the picture sits a see-through **label box** with four rows, each with an icon and hover help:

1. **Hazard** – drag each hazard label onto its spot in the picture (up to two extra labels are hazards that are *not* in the picture).
2. **Eliminate** – how to remove the hazard, or why it cannot reasonably be eliminated.
3. **Reduce the risk** – substitution, isolation, engineering and administrative controls.
4. **PPE required**.

Every control is tagged with its level in the hierarchy of controls and with how many steps it lowers the **likelihood** and/or the **consequence** on a 5 × 5 risk matrix. Two gauges (likelihood and consequence), the matrix, the risk rating (low, medium, high, extreme) and a "risk removed" bar show the effect of each right label: shoring lowers the likelihood of a collapse, a hard hat lowers the consequence, the right elimination removes the hazard. Wrong labels lower nothing.

## Modes

- **Study** – every hazard, control, reason and effect is shown, step by step. Counts in full where it is the graded mode.
- **Practice** – each label is marked straight away, with the reason and the new risk; a wrong label is crossed out and the learner tries again.
- **Test** – nothing is marked until the end. A hazard label dropped on a spot stays there, each row ends with "Done with this row", and a pass mark can complete the activity.

Score = right labels ÷ (right labels needed + wrong labels). The results show, for each hazard, the risk it started with and was left with, the right controls missed and the wrong ones chosen.

## Requirements

- Moodle 4.4 to 5.3 (`$plugin->requires = 2024042200`, `supported = [404, 503]`).
- PHP 8.1 or later, as your Moodle version requires.
- An LMS Labs account for activation and the optional AI features.

## Install

1. Upload `mod_aisafetyhazards_v1.0.0.zip` in *Site administration > Plugins > Install plugins*, or unzip it into `mod/aisafetyhazards` (`public/mod/aisafetyhazards` on Moodle 5.1 and later).
2. Complete the upgrade.
3. Activate the plugin in the AI Safety Hazards settings: "Check access", then "Unlock…" (50 LMS Labs credits, or free when LMS Labs has a record of a Moodle Marketplace purchase). Nothing can be set up or played until the site is unlocked.

Administrators can add their own work tasks under *Extra work tasks*, one per line: `Industry | Sub-industry | Task`. A line can join a built-in industry or make a new one.

## Setting up a lesson

"Set up the lesson" has seven steps and always reopens at the first one not done:

1. **Work task** – industry, then sub-industry, then task (each shown once the one above is chosen), or "Other" with your own names.
2. **Language and size** – the language of the scenes (20 languages), photo or illustration, and how many scenes.
3. **Create the scenes**:
   - LMS Labs AI drafts a scene from a short brief (5 credits per scene, when switched on).
   - Or **copy the prompt** into ChatGPT or another assistant, **paste its reply** into the box, preview it for free, and create the scenes (5 credits per scene, confirmed first; this needs the site's LMS Labs connection).
4. **Pictures and hazard spots** – an AI picture (5 credits) or your own upload for every scene. New AI pictures place the hazard spots where LMS Labs finds them; otherwise spots are spread over the picture and marked as guesses for you to drag into place.
5. **Voiceover** (optional) – a narrator reads the situation, the hazards and the controls (2 credits per clip, free remakes when LMS Labs offers them).
6. **Check the scenes** – the editor shows each hazard as a collapsible card with its three rows, tags and reasons, and the starting and best-case risk.
7. **Finish**.

## Privacy

Learners' attempts and labels are stored in Moodle and covered by the privacy provider. LMS Labs receives only the brief and the work task names for drafts, the picture prompt (with the hazards' picture descriptions) for pictures, the text of each clip for voiceover, and the scene count and titles for imports. Keys are sent only in request headers.

## Licence

GNU GPL v3 or later. © 2026 LMS Hosting Services.
