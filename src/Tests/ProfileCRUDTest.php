<?php

/**
 * @file
 * Contains \Drupal\profile\Tests\ProfileCRUDTest.
 */

namespace Drupal\profile\Tests;

use Drupal\KernelTests\KernelTestBase;
use Drupal\profile\Entity\Profile;
use Drupal\profile\Entity\ProfileType;
use Drupal\user\Entity\User;

/**
 * Tests basic CRUD functionality of profiles.
 *
 * @group profile
 */
class ProfileCRUDTest extends KernelTestBase {

  public static $modules = ['system', 'field', 'entity_reference', 'field_sql_storage', 'user', 'profile'];

  /**
   * {@inheritdoc}
   */
  protected function setUp() {
    parent::setUp();
    $this->installSchema('system', 'url_alias');
    $this->installSchema('system', 'sequences');
    $this->installSchema('user', 'users_data');
    $this->installEntitySchema('user');
    $this->installEntitySchema('profile');
    $this->enableModules(['field', 'entity_reference', 'user', 'profile']);
  }

  /**
   * Tests CRUD operations.
   */
  public function testCRUD() {
    $types_data = [
      'profile_type_0' => ['label' => $this->randomMachineName()],
      'profile_type_1' => ['label' => $this->randomMachineName()],
    ];
    /** @var ProfileType[] $types */
    $types = [];
    foreach ($types_data as $id => $values) {
      $types[$id] = ProfileType::create(['id' => $id] + $values);
      $types[$id]->save();
    }
    $user1 = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
    ]);
    $user1->save();
    $user2 = User::create([
      'name' => $this->randomMachineName(),
      'mail' => $this->randomMachineName() . '@example.com',
    ]);
    $user2->save();

    // Create a new profile.
    $profile = Profile::create($expected = [
      'type' => $types['profile_type_0']->id(),
      'uid' => $user1->id(),
    ]);
    self::assertSame($profile->id(), NULL);
    self::assertTrue($profile->uuid());
    self::assertSame($profile->getType(), $expected['type']);
    self::assertSame($profile->label(), t('@type profile of @username (uid: @uid)',
      [
        '@type' => $types['profile_type_0']->label(),
        '@username' => $user1->getUsername(),
        '@uid' => $user1->id(),
      ])
    );
    self::assertSame($profile->getOwnerId(), $user1->id());
    self::assertSame($profile->getCreatedTime(), REQUEST_TIME);
    self::assertSame($profile->getChangedTime(), REQUEST_TIME);

    // Save the profile.
    $status = $profile->save();
    self::assertSame($status, SAVED_NEW);
    self::assertTrue($profile->id());
    self::assertSame($profile->getChangedTime(), REQUEST_TIME);

    $profileStorage = \Drupal::entityManager()->getStorage('profile');

    // List profiles for the user and verify that the new profile appears.
    $list = $profileStorage->loadByProperties([
      'uid' => $user1->id(),
    ]);
    $list_ids = array_keys($list);
    self::assertEquals($list_ids, [(int) $profile->id()]);

    // Reload and update the profile.
    /** @var Profile $profile */
    $profile = Profile::load($profile->id());
    $profile->setChangedTime($profile->getChangedTime() - 1000);
    $original = clone $profile;
    $status = $profile->save();
    self::assertSame($status, SAVED_UPDATED);
    self::assertSame($profile->id(), $original->id());
    self::assertEquals($profile->getCreatedTime(), REQUEST_TIME);
    self::assertEquals($original->getChangedTime(), REQUEST_TIME - 1000);
    self::assertEquals($profile->getChangedTime(), REQUEST_TIME);

    // Create a second profile.
    $user1_profile1 = $profile;
    $profile = Profile::create([
      'type' => $types['profile_type_0']->id(),
      'uid' => $user1->id(),
    ]);
    $status = $profile->save();
    self::assertSame($status, SAVED_NEW);
    $user1_profile = $profile;

    // List profiles for the user and verify that both profiles appear.
    $list = $profileStorage->loadByProperties([
      'uid' => $user1->id(),
    ]);
    $list_ids = array_keys($list);
    self::assertEquals($list_ids, [
      (int) $user1_profile1->id(),
      (int) $user1_profile->id(),
    ]);

    // Delete the second profile and verify that the first still exists.
    $user1_profile->delete();
    self::assertFalse(Profile::load($user1_profile->id()));
    $list = $profileStorage->loadByProperties([
      'uid' => (int) $user1->id(),
    ]);
    $list_ids = array_keys($list);
    self::assertEquals($list_ids, [(int) $user1_profile1->id()]);

    // Create a new second profile.
    $user1_profile = Profile::create([
      'type' => $types['profile_type_1']->id(),
      'uid' => $user1->id(),
    ]);
    $status = $user1_profile->save();
    self::assertSame($status, SAVED_NEW);

    // Create a profile for the second user.
    $user2_profile1 = Profile::create([
      'type' => $types['profile_type_0']->id(),
      'uid' => $user2->id(),
    ]);
    $status = $user2_profile1->save();
    self::assertSame($status, SAVED_NEW);

    // Delete the first user and verify that all of its profiles are deleted.
    $user1->delete();
    self::assertFalse(User::load($user1->id()));
    $list = $profileStorage->loadByProperties([
      'uid' => $user1->id(),
    ]);
    $list_ids = array_keys($list);
    self::assertEquals($list_ids, []);

    // List profiles for the second user and verify that they still exist.
    $list = $profileStorage->loadByProperties([
      'uid' => $user2->id(),
    ]);
    $list_ids = array_keys($list);
    self::assertEquals($list_ids, [(int) $user2_profile1->id()]);

    // @todo Rename a profile type; verify that existing profiles are updated.
  }

}
