<?php

declare( strict_types=1 );

/**
 * Tests for the core/manage-settings ability shipped with the Abilities API.
 *
 * @covers wp_register_core_abilities
 * @covers WP_Settings_Abilities
 *
 * @group abilities-api
 */
class Tests_Abilities_API_WpRegisterCoreManageSettingsAbility extends WP_UnitTestCase {

	/**
	 * Set up before the class.
	 *
	 * The core settings are registered on `rest_api_init`, so register them up front to
	 * mirror the request context in which the ability builds its schema and runs.
	 *
	 * @since 7.1.0
	 */
	public static function set_up_before_class(): void {
		parent::set_up_before_class();

		register_initial_settings();

		// A non-core setting flagged for the Abilities API, to verify that any registered
		// setting (not just the core ones) is writable through the ability.
		register_setting(
			'general',
			'core_settings_ability_test_option',
			array(
				'type'              => 'integer',
				'label'             => 'Custom Ability Setting',
				'description'       => 'A custom setting exposed through the Abilities API.',
				'show_in_abilities' => true,
				'default'           => 42,
			)
		);

		// Temporarily remove the unhook functions so we can register core abilities.
		remove_action( 'wp_abilities_api_categories_init', '_unhook_core_ability_categories_registration', 1 );
		remove_action( 'wp_abilities_api_init', '_unhook_core_abilities_registration', 1 );

		add_action( 'wp_abilities_api_categories_init', 'wp_register_core_ability_categories' );
		add_action( 'wp_abilities_api_init', 'wp_register_core_abilities' );
		do_action( 'wp_abilities_api_categories_init' );
		do_action( 'wp_abilities_api_init' );
	}

	/**
	 * Tear down after the class.
	 *
	 * @since 7.1.0
	 */
	public static function tear_down_after_class(): void {
		add_action( 'wp_abilities_api_categories_init', '_unhook_core_ability_categories_registration', 1 );
		add_action( 'wp_abilities_api_init', '_unhook_core_abilities_registration', 1 );

		foreach ( wp_get_abilities() as $ability ) {
			wp_unregister_ability( $ability->get_name() );
		}
		foreach ( wp_get_ability_categories() as $ability_category ) {
			wp_unregister_ability_category( $ability_category->get_slug() );
		}

		unregister_setting( 'general', 'core_settings_ability_test_option' );

		parent::tear_down_after_class();
	}

	/**
	 * Logs in as an administrator so abilities gated behind `manage_options` can run.
	 */
	private function become_admin(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * The ability is registered in the `site` category and flagged writable.
	 *
	 * @ticket 64146
	 */
	public function test_core_manage_settings_ability_is_registered(): void {
		$ability = wp_get_ability( 'core/manage-settings' );

		$this->assertInstanceOf( WP_Ability::class, $ability );
		$this->assertSame( 'site', $ability->get_category() );
		$this->assertTrue( $ability->get_meta_item( 'show_in_rest', false ) );

		$annotations = $ability->get_meta_item( 'annotations', array() );
		$this->assertFalse( $annotations['readonly'] );
		$this->assertFalse( $annotations['destructive'] );
	}

	/**
	 * Every setting exposed for reading is writable: the input schema mirrors the exposed set
	 * and disallows unknown properties.
	 *
	 * @ticket 64146
	 */
	public function test_core_manage_settings_input_schema_mirrors_exposed_settings(): void {
		$schema = wp_get_ability( 'core/manage-settings' )->get_input_schema();

		$this->assertSame( 'object', $schema['type'] );
		$this->assertFalse( $schema['additionalProperties'] );
		$this->assertSame( 1, $schema['minProperties'] );
		$this->assertArrayHasKey( 'blogname', $schema['properties'] );
		$this->assertArrayHasKey( 'posts_per_page', $schema['properties'] );
	}

	/**
	 * The ability stores each provided setting and returns the updated, correctly typed values.
	 *
	 * @ticket 64146
	 */
	public function test_core_manage_settings_updates_and_returns_values(): void {
		$this->become_admin();

		$result = wp_get_ability( 'core/manage-settings' )->execute(
			array(
				'blogname'       => 'Renamed Site',
				'posts_per_page' => 9,
			)
		);

		$this->assertSame(
			array(
				'blogname'       => 'Renamed Site',
				'posts_per_page' => 9,
			),
			$result
		);
		// Persisted to the database.
		$this->assertSame( 'Renamed Site', get_option( 'blogname' ) );
		$this->assertSame( 9, (int) get_option( 'posts_per_page' ) );
	}

	/**
	 * An invalid value aborts the whole call before any option is written (all-or-nothing).
	 *
	 * @ticket 64146
	 */
	public function test_core_manage_settings_is_atomic_on_invalid_value(): void {
		$this->become_admin();

		update_option( 'blogname', 'Original Name' );

		// `default_ping_status` is constrained to the enum open|closed; `sometimes` is invalid, so
		// the whole call must fail and the valid sibling value must not be written.
		$result = wp_get_ability( 'core/manage-settings' )->execute(
			array(
				'blogname'            => 'Should Not Persist',
				'default_ping_status' => 'sometimes',
			)
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
		$this->assertSame( 'Original Name', get_option( 'blogname' ) );
	}

	/**
	 * Unknown setting names are rejected by `additionalProperties: false`.
	 *
	 * @ticket 64146
	 */
	public function test_core_manage_settings_rejects_unknown_setting(): void {
		$this->become_admin();

		$result = wp_get_ability( 'core/manage-settings' )->execute(
			array( 'not_a_registered_setting' => 'value' )
		);

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Empty input is rejected: at least one setting must be provided.
	 *
	 * @ticket 64146
	 */
	public function test_core_manage_settings_rejects_empty_input(): void {
		$this->become_admin();

		$result = wp_get_ability( 'core/manage-settings' )->execute( array() );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_input', $result->get_error_code() );
	}

	/**
	 * Users without `manage_options` cannot run the ability, and nothing is written.
	 *
	 * @ticket 64146
	 */
	public function test_core_manage_settings_requires_manage_options(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );

		update_option( 'blogname', 'Original Name' );

		$result = wp_get_ability( 'core/manage-settings' )->execute( array( 'blogname' => 'Nope' ) );

		$this->assertWPError( $result );
		$this->assertSame( 'ability_invalid_permissions', $result->get_error_code() );
		$this->assertSame( 'Original Name', get_option( 'blogname' ) );
	}

	/**
	 * A setting registered with `show_in_abilities` (for example by a plugin) is writable.
	 *
	 * @ticket 64146
	 */
	public function test_core_manage_settings_updates_a_custom_registered_setting(): void {
		$this->become_admin();

		$result = wp_get_ability( 'core/manage-settings' )->execute(
			array( 'core_settings_ability_test_option' => 100 )
		);

		$this->assertSame( array( 'core_settings_ability_test_option' => 100 ), $result );
		$this->assertSame( 100, (int) get_option( 'core_settings_ability_test_option' ) );
	}
}
