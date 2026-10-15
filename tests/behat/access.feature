@mod @mod_aisafetyhazards
Feature: Teachers set up AI Safety Hazards scenes and learners open them
  In order to practise spotting and controlling workplace hazards
  As a teacher
  I need to build scenes that my learners can open, while learners cannot reach the teacher pages

  Background:
    Given the following "courses" exist:
      | fullname          | shortname |
      | Site safety 101   | WHS101    |
    And the following "users" exist:
      | username | firstname | lastname |
      | teacher1 | Tina      | Teacher  |
      | student1 | Sam       | Student  |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | WHS101 | editingteacher |
      | student1 | WHS101 | student        |
    And the following "activities" exist:
      | activity        | course | idnumber | name           | intro                 | industry     | subindustry | task      |
      | aisafetyhazards | WHS101 | sh1      | Trench hazards | Spot the hazards.     | construction | civil       | trenching |
    And the following config values are set as admin:
      | name        | value                                         | plugin              |
      | unlockstate | {"status":"unlocked","checkedat":1790000000} | mod_aisafetyhazards |

  Scenario: A teacher opening an empty activity is taken to the set-up
    When I am on the "Trench hazards" "aisafetyhazards activity" page logged in as "teacher1"
    Then I should see "Set up the lesson"
    And I should see "Industry"
    And I should see "Construction"
    And I should see "Step 1 of 7"

  Scenario: The build step offers the prompt for the teacher's own AI assistant
    When I am on the "Trench hazards" "mod_aisafetyhazards > Build" page logged in as "teacher1"
    Then I should see "Copy prompt"
    And I should see "Construction"
    And I should see "Civil construction"
    And I should see "Trenching"
    And I should see "Creating scenes needs this site's LMS Labs connection"
    And I should see "Industry: Construction" in the "#sh-lessonprompt" "css_element"
    And I should see "Sub-industry: Civil construction" in the "#sh-lessonprompt" "css_element"

  Scenario: A learner opening an empty activity is told it is not ready
    When I am on the "Trench hazards" "aisafetyhazards activity" page logged in as "student1"
    Then I should see "Your teacher is still preparing this activity."
    And I should not see "Set up the lesson"

  Scenario: A learner can start once a scene is ready
    Given the following "mod_aisafetyhazards > scenes" exist:
      | activity | title              |
      | sh1      | Digging the trench |
    When I am on the "Trench hazards" "aisafetyhazards activity" page logged in as "student1"
    Then I should see "Trenching"
    And "Start studying" "button" should exist
    And "Start practising" "button" should exist
    And "Reports" "link" should not exist in current page administration

  Scenario: The set-up path opens at the step it is up to
    Given the following "mod_aisafetyhazards > scenes" exist:
      | activity | title              | picture |
      | sh1      | Digging the trench | none    |
    When I am on the "Trench hazards" "aisafetyhazards activity" page logged in as "teacher1"
    And I navigate to "Set up the lesson" in current page administration
    Then I should see "A picture for every scene, with its hazard spots"
    And I should see "Needs a picture"
    And I should see "Step 4 of 7"

  Scenario: The check step shows each scene's hazards
    Given the following "mod_aisafetyhazards > scenes" exist:
      | activity | title              |
      | sh1      | Digging the trench |
    When I am on the "Trench hazards" "mod_aisafetyhazards > Check" page logged in as "teacher1"
    Then I should see "Check each scene"
    And I should see "Digging the trench"
    And I should see "Hazards complete"

  Scenario: A teacher can open the reports
    Given the following "mod_aisafetyhazards > scenes" exist:
      | activity | title              |
      | sh1      | Digging the trench |
    When I am on the "Trench hazards" "mod_aisafetyhazards > Report" page logged in as "teacher1"
    Then I should see "Sam Student"
    And I should see "Best score"

  Scenario: Nothing can be set up or played until AI Safety Hazards is activated on the site
    Given the following config values are set as admin:
      | name        | value               | plugin              |
      | unlockstate | {"status":"locked"} | mod_aisafetyhazards |
    And the following "mod_aisafetyhazards > scenes" exist:
      | activity | title              |
      | sh1      | Digging the trench |
    When I am on the "Trench hazards" "aisafetyhazards activity" page logged in as "student1"
    Then I should see "This activity is not available yet."
    And "Start practising" "button" should not exist
    And I am on the "Trench hazards" "mod_aisafetyhazards > Builder" page logged in as "teacher1"
    And I should see "Ask your site administrator to activate it"
