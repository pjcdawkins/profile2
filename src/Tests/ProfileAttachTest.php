<?php

/**
 * @file
 * Contains \Drupal\profile\Tests\ProfileAttachTest.
 */

namespace Drupal\profile\Tests;

use Drupal\Component\Utility\SafeMarkup;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\profile\Entity\ProfileType;
use Drupal\simpletest\WebTestBase;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/**
 * Tests attaching of profile entity forms to other forms.
 *
 * @group profile
 */
class ProfileAttachTest extends WebTestBase {

  use StringTranslationTrait;

  public static $modules = ['profile', 'text'];

  /** @var \Drupal\profile\Entity\ProfileType */
  protected $profileType;

  /** @var \Drupal\Core\Entity\Display\EntityDisplayInterface */
  protected $display;

  /** @var \Drupal\Core\Entity\Display\EntityFormDisplayInterface */
  protected $formDisplay;

  /** @var \Drupal\field\Entity\FieldStorageConfig */
  protected $field;

  /** @var \Drupal\field\Entity\FieldConfig */
  protected $instance;

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();

    $this->profileType = ProfileType::create([
      'id' => 'test',
      'label' => 'Test profile',
      'weight' => 0,
      'registration' => TRUE,
    ]);
    $this->profileType->save();

    $fieldName = 'profile_fullname';
    $this->field = [
      'field_name' => $fieldName,
      'type' => 'text',
      'entity_type' => 'profile',
      'cardinality' => 1,
      'translatable' => FALSE,
    ];
    $this->field = FieldStorageConfig::create($this->field);
    $this->field->save();

    $this->instance = [
      'entity_type' => $this->field->getEntityType(),
      'field_name' => $fieldName,
      'bundle' => $this->profileType->id(),
      'label' => 'Full name',
      'required' => TRUE,
      'widget' => [
        'type' => 'text_textfield',
      ],
    ];
    $this->instance = FieldConfig::create($this->instance);
    $this->instance->save();

    $displayValues = [
      'targetEntityType' => 'profile',
      'bundle' => 'test',
      'mode' => 'default',
      'status' => TRUE,
    ];
    $this->display = \Drupal::entityManager()
      ->getStorage('entity_view_display')
      ->create($displayValues);
    $this->display->setComponent($fieldName, [
      'type' => 'text_default',
    ]);
    $this->display->save();

    $this->formDisplay = \Drupal::entityManager()
      ->getStorage('entity_form_display')
      ->create($displayValues);
    $this->formDisplay->setComponent($fieldName, [
        'type' => 'string_textfield',
      ]);
    $this->formDisplay->save();

    $this->checkPermissions([], TRUE);
  }

  /**
   * Test user registration integration.
   */
  function testUserRegisterForm() {
    $id = $this->profileType->id();
    $field_name = $this->field->getName();

    // Allow registration without administrative approval and log in user
    // directly after registering.
    \Drupal::configFactory()
      ->getEditable('user.settings')
      ->set('register', USER_REGISTER_VISITORS)
      ->set('verify_mail', 0)
      ->save();
    user_role_grant_permissions(AccountInterface::AUTHENTICATED_ROLE, ['view own test profile']);

    // Verify that the additional profile field is attached and required.
    $name = $this->randomMachineName();
    $pass_raw = $this->randomMachineName();
    $edit = [
      'name' => $name,
      'mail' => $this->randomMachineName() . '@example.com',
      'pass[pass1]' => $pass_raw,
      'pass[pass2]' => $pass_raw,
    ];
    $this->drupalPostForm('user/register', $edit, t('Create new account'));
    $this->assertRaw(SafeMarkup::format('@name field is required.', ['@name' => $this->instance->getLabel()]));

    // Verify that we can register.
    $edit["entity_" . $id . "[$field_name][0][value]"] = $this->randomMachineName();
    $this->drupalPostForm(NULL, $edit, t('Create new account'));
    $this->assertText($this->t('Registration successful. You are now logged in.'));

    $new_user = user_load_by_name($name);
    $this->assertTrue($new_user->isActive(), 'New account is active after registration.');

    // Verify that a new profile was created for the new user ID.
    $profiles = \Drupal::entityManager()
       ->getStorage('profile')
       ->loadByProperties([
      'uid' => $new_user->id(),
      'type' => $this->profileType->id(),
    ]);
    $profile = reset($profiles);
    $this->assertEqual($profile->get($field_name)->value, $edit["entity_" . $id . "[$field_name][0][value]"], 'Field value found in loaded profile.');

    // Verify that the profile field value appears on the user account page.
    $this->drupalGet('user');
    $this->assertText($edit["entity_" . $id . "[$field_name][0][value]"], 'Field value found on user account page.');
  }

}
