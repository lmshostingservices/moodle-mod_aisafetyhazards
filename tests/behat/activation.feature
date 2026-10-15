@mod @mod_aisafetyhazards
Feature: AI Safety Hazards activation is part of the plugin settings page
  In order to activate AI Safety Hazards where the other settings are
  As an administrator
  I need the activation status and actions on the plugin settings page

  Scenario: The settings page shows activation without calling LMS Labs or spending credits
    Given I log in as "admin"
    When I navigate to "Plugins > Activity modules > AI Safety Hazards" in site administration
    Then I should see "AI Safety Hazards activation"
    And I should see "Not checked"
    And I should see "Checked live with LMS Labs when you press Unlock"
    And the "Check access" "button" should be disabled
    And the "Unlock…" "button" should be disabled
    And I should see "AI scene pictures"
    And I should not see "Open AI Safety Hazards activation"
