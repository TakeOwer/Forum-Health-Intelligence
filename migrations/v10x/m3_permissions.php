<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\migrations\v10x;

/**
 * Installs the extension permissions.
 *
 * Granular by design: viewing the dashboard is separate from changing settings,
 * and the integration and AI permissions are separate again so that somebody can
 * be given read access without any ability to trigger external requests or spend
 * an AI budget.
 *
 * Role assignments are made defensively. A board can rename or delete any of
 * phpBB's default roles, and `permission.permission_set` against a role that is
 * not there aborts the whole installation with an exception. Since assigning a
 * convenience default is worth much less than installing successfully, roles are
 * checked first and simply skipped when absent. Founders keep full access
 * regardless, because phpBB grants them everything by definition.
 */
class m3_permissions extends \phpbb\db\migration\migration
{
	/**
	 * Which permissions each default role should receive.
	 *
	 * @var array<string, string[]>
	 */
	protected static $role_grants = [
		// Full administrators get everything.
		'ROLE_ADMIN_FULL' => [
			'a_fh_view',
			'a_fh_manage',
			'a_fh_manage_content',
			'a_fh_manage_community',
			'a_fh_manage_integrations',
			'a_fh_manage_ai',
			'a_fh_manage_rules',
		],

		// Standard administrators get everything except integration binding and
		// AI spending: both are infrastructure decisions with costs attached.
		'ROLE_ADMIN_STANDARD' => [
			'a_fh_view',
			'a_fh_manage',
			'a_fh_manage_content',
			'a_fh_manage_community',
			'a_fh_manage_rules',
		],

		// User and group administrators get read access, since the community
		// reports are directly relevant to what they already do.
		'ROLE_ADMIN_USERGROUP' => [
			'a_fh_view',
		],
	];

	/**
	 * {@inheritdoc}
	 */
	public static function depends_on()
	{
		return ['\salvocortesiano\forumhealth\migrations\v10x\m2_config'];
	}

	/**
	 * {@inheritdoc}
	 */
	public function update_data()
	{
		$data = [
			// Read access to every Forum Health report.
			['permission.add', ['a_fh_view', true]],
			// Act on findings: acknowledge, dismiss, resolve, mark duplicates.
			['permission.add', ['a_fh_manage', true]],
			// Change content-analysis settings.
			['permission.add', ['a_fh_manage_content', true]],
			// Change community-analysis settings.
			['permission.add', ['a_fh_manage_community', true]],
			// Bind, enable and test optional integrations.
			['permission.add', ['a_fh_manage_integrations', true]],
			// Enable AI features and spend the AI budget.
			['permission.add', ['a_fh_manage_ai', true]],
			// Create and edit rules.
			['permission.add', ['a_fh_manage_rules', true]],
		];

		$existing = $this->existing_roles();

		foreach (self::$role_grants as $role => $permissions)
		{
			if (!isset($existing[$role]))
			{
				// The role is not on this board. Skipping is the correct
				// outcome: the permissions still exist and can be granted by
				// hand, and the installation completes.
				continue;
			}

			foreach ($permissions as $permission)
			{
				$data[] = ['permission.permission_set', [$role, $permission]];
			}
		}

		return $data;
	}

	/**
	 * The default roles that actually exist on this board.
	 *
	 * @return array<string, true> Role names, keyed for lookup.
	 */
	protected function existing_roles()
	{
		$roles = [];

		$sql = 'SELECT role_name
			FROM ' . ACL_ROLES_TABLE . "
			WHERE role_type = 'a_'";

		$result = $this->db->sql_query($sql);

		while ($row = $this->db->sql_fetchrow($result))
		{
			$roles[(string) $row['role_name']] = true;
		}

		$this->db->sql_freeresult($result);

		return $roles;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Removing a permission removes its role assignments with it, so the grants
	 * above need no separate reversal.
	 */
	public function revert_data()
	{
		return [
			['permission.remove', ['a_fh_view']],
			['permission.remove', ['a_fh_manage']],
			['permission.remove', ['a_fh_manage_content']],
			['permission.remove', ['a_fh_manage_community']],
			['permission.remove', ['a_fh_manage_integrations']],
			['permission.remove', ['a_fh_manage_ai']],
			['permission.remove', ['a_fh_manage_rules']],
		];
	}
}
