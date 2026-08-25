<?php
/**
 *
 * Forum Health & Intelligence. An extension for the phpBB Forum Software package.
 *
 * @copyright (c) 2026 Salvo Cortesiano
 * @license GNU General Public License, version 2 (GPL-2.0)
 *
 */

namespace salvocortesiano\forumhealth\acp;

use salvocortesiano\forumhealth\constants;
use salvocortesiano\forumhealth\repository\rule_repository;

/**
 * The rule editor.
 *
 * A rule is built from dropdowns, not typed. The field list, the operator list
 * and the action list all come from the repository's whitelists and are rendered
 * as select elements, so the only values that can reach the form are values the
 * evaluator already knows.
 *
 * This is why there is no free-text condition box anywhere on this page. An
 * expression field would be friendlier and would also be a way to run code
 * through an administrator account, and the trade is not worth making for a
 * feature whose whole job is to say "alert me when views exceed 500".
 */
class rules_module extends base_module
{
	/**
	 * Render the rule list or the edit form.
	 *
	 * @param int    $id   Module id.
	 * @param string $mode Module mode.
	 * @return void
	 */
	public function main($id, $mode)
	{
		$this->boot();
		$this->require_permission('a_fh_manage_rules');

		$this->tpl_name = 'acp_fh_rules';
		$this->page_title = 'ACP_FH_RULES';
		$this->assign_common($mode);

		add_form_key('fh_rules');

		$action = $this->request->variable('action', '');
		$rule_id = $this->request->variable('rule_id', 0);

		switch ($action)
		{
			case 'add':
			case 'edit':
				$this->edit_form($rule_id);
				return;

			case 'save':
				$this->save($rule_id);
				return;

			case 'delete':
				$this->delete($rule_id);
				return;

			case 'toggle':
				$this->toggle($rule_id);
				return;
		}

		$this->listing();
	}

	/**
	 * List the configured rules.
	 *
	 * @return void
	 */
	protected function listing()
	{
		$rules = $this->service('repository.rules')->all();

		foreach ($rules as $rule)
		{
			$severity = $this->severity_display($rule['action_severity']);

			$this->template->assign_block_vars('fh_rule', [
				'ID'				=> (int) $rule['rule_id'],
				'NAME'				=> $rule['rule_name'],
				'S_ENABLED'			=> (bool) $rule['rule_enabled'],
				'STATE'				=> $this->language->lang($rule['rule_enabled'] ? 'FH_RULE_ENABLED' : 'FH_RULE_DISABLED'),
				'SEVERITY'			=> $severity['label'],
				'SEVERITY_CLASS'	=> $severity['class'],
				'ACTION'			=> $this->language->lang('FH_RULE_ACTION_' . strtoupper($rule['action_type'])),
				'SUMMARY'			=> $this->summarise($rule['rule_conditions']),
				'U_EDIT'			=> $this->u_action . '&amp;action=edit&amp;rule_id=' . (int) $rule['rule_id'],
				'U_DELETE'			=> $this->u_action . '&amp;action=delete&amp;rule_id=' . (int) $rule['rule_id'],
				'U_TOGGLE'			=> $this->u_action . '&amp;action=toggle&amp;rule_id=' . (int) $rule['rule_id'],
			]);
		}

		$this->template->assign_vars([
			'S_LIST'		=> true,
			'S_HAS_ITEMS'	=> !empty($rules),
			'S_RULES_ON'	=> $this->settings->feature_enabled('rules'),
			'U_ADD'			=> $this->u_action . '&amp;action=add',
		]);
	}

	/**
	 * Render the add or edit form.
	 *
	 * @param int $rule_id Rule id, 0 to create.
	 * @return void
	 */
	protected function edit_form($rule_id)
	{
		$rule = $rule_id > 0 ? $this->service('repository.rules')->get($rule_id) : null;

		if ($rule_id > 0 && $rule === null)
		{
			trigger_error($this->language->lang('FH_ERR_RULE_NOT_FOUND') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$conditions = $rule !== null ? $rule['rule_conditions'] : [];

		// One blank row is always offered so the form is usable on a new rule
		// and so an existing rule can gain a clause without a page round trip.
		$conditions[] = ['field' => '', 'operator' => 'gte', 'value' => 0];

		foreach ($conditions as $index => $condition)
		{
			$this->template->assign_block_vars('fh_condition', [
				'INDEX'				=> (int) $index,
				'S_FIELD_OPTIONS'	=> $this->field_options((string) ($condition['field'] ?? '')),
				'S_OPERATOR_OPTIONS'=> $this->operator_options((string) ($condition['operator'] ?? 'gte')),
				'VALUE'				=> (int) ($condition['value'] ?? 0),
			]);
		}

		$this->template->assign_vars([
			'S_EDIT'				=> true,
			'RULE_ID'				=> (int) $rule_id,
			'RULE_NAME'				=> $rule !== null ? $rule['rule_name'] : '',
			'S_RULE_ENABLED'		=> $rule !== null ? (bool) $rule['rule_enabled'] : false,
			'S_SEVERITY_OPTIONS'	=> $this->severity_options($rule !== null ? (int) $rule['action_severity'] : constants::SEVERITY_MEDIUM),
			'S_ACTION_OPTIONS'		=> $this->action_options($rule !== null ? (string) $rule['action_type'] : 'create_alert'),
			'U_SAVE'				=> $this->u_action . '&amp;action=save&amp;rule_id=' . (int) $rule_id,
			'U_BACK'				=> $this->u_action,
		]);
	}

	/**
	 * Validate and store a submitted rule.
	 *
	 * @param int $rule_id Rule id, 0 to create.
	 * @return void
	 */
	protected function save($rule_id)
	{
		$this->require_form_token('fh_rules');

		$fields = $this->request->variable('condition_field', ['']);
		$operators = $this->request->variable('condition_operator', ['']);
		$values = $this->request->variable('condition_value', [0]);

		$conditions = [];

		foreach ($fields as $index => $field)
		{
			if ((string) $field === '')
			{
				// An empty row is how a clause is removed, so it is skipped
				// rather than treated as an error.
				continue;
			}

			$conditions[] = [
				'field'		=> (string) $field,
				'operator'	=> isset($operators[$index]) ? (string) $operators[$index] : 'gte',
				'value'		=> isset($values[$index]) ? (int) $values[$index] : 0,
			];
		}

		list($ok, $error) = $this->service('repository.rules')->save($rule_id, [
			'rule_name'			=> $this->request->variable('rule_name', '', true),
			'rule_enabled'		=> $this->request->variable('rule_enabled', 0),
			'rule_conditions'	=> $conditions,
			'action_type'		=> $this->request->variable('action_type', 'create_alert'),
			'action_severity'	=> $this->request->variable('action_severity', constants::SEVERITY_MEDIUM),
		]);

		if (!$ok)
		{
			trigger_error($this->language->lang($error) . adm_back_link($this->u_action), E_USER_WARNING);
		}

		trigger_error($this->language->lang('FH_RULE_SAVED') . adm_back_link($this->u_action));
	}

	/**
	 * Delete a rule after confirmation.
	 *
	 * @param int $rule_id Rule id.
	 * @return void
	 */
	protected function delete($rule_id)
	{
		if ($rule_id <= 0)
		{
			trigger_error($this->language->lang('FH_ERR_RULE_NOT_FOUND') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		if (!confirm_box(true))
		{
			confirm_box(false, $this->language->lang('FH_RULE_CONFIRM_DELETE'), build_hidden_fields([
				'action'	=> 'delete',
				'rule_id'	=> $rule_id,
				'i'			=> $this->request->variable('i', ''),
				'mode'		=> $this->request->variable('mode', ''),
			]));

			return;
		}

		$this->service('repository.rules')->delete($rule_id);

		// Alerts raised by a rule that no longer exists would be unexplainable,
		// so they are closed with it.
		$this->service('repository.alerts')->resolve_entity(constants::ALERT_RULE, 'rule', $rule_id);

		trigger_error($this->language->lang('FH_RULE_DELETED') . adm_back_link($this->u_action));
	}

	/**
	 * Enable or disable a rule.
	 *
	 * @param int $rule_id Rule id.
	 * @return void
	 */
	protected function toggle($rule_id)
	{
		$repository = $this->service('repository.rules');
		$rule = $repository->get($rule_id);

		if ($rule === null)
		{
			trigger_error($this->language->lang('FH_ERR_RULE_NOT_FOUND') . adm_back_link($this->u_action), E_USER_WARNING);
		}

		$repository->set_enabled($rule_id, !(int) $rule['rule_enabled']);

		trigger_error($this->language->lang('FH_RULE_SAVED') . adm_back_link($this->u_action));
	}

	/**
	 * Describe a rule's conditions in one readable line.
	 *
	 * @param array $conditions Decoded conditions.
	 * @return string
	 */
	protected function summarise(array $conditions)
	{
		$parts = [];

		foreach ($conditions as $condition)
		{
			$parts[] = $this->language->lang('FH_RULE_FIELD_' . strtoupper((string) $condition['field']))
				. ' ' . $this->language->lang('FH_RULE_OP_' . strtoupper((string) $condition['operator']))
				. ' ' . (int) $condition['value'];
		}

		return implode(' ' . $this->language->lang('FH_RULE_AND') . ' ', $parts);
	}

	/**
	 * Select options for the testable fields.
	 *
	 * @param string $selected Currently selected field.
	 * @return string
	 */
	protected function field_options($selected)
	{
		$html = '<option value="">' . $this->language->lang('FH_RULE_FIELD_NONE') . '</option>';

		foreach (array_keys(rule_repository::fields()) as $field)
		{
			$html .= '<option value="' . $field . '"' . ($field === $selected ? ' selected="selected"' : '') . '>'
				. $this->language->lang('FH_RULE_FIELD_' . strtoupper($field)) . '</option>';
		}

		return $html;
	}

	/**
	 * Select options for the operators.
	 *
	 * @param string $selected Currently selected operator.
	 * @return string
	 */
	protected function operator_options($selected)
	{
		$html = '';

		foreach (rule_repository::operators() as $operator)
		{
			$html .= '<option value="' . $operator . '"' . ($operator === $selected ? ' selected="selected"' : '') . '>'
				. $this->language->lang('FH_RULE_OP_' . strtoupper($operator)) . '</option>';
		}

		return $html;
	}

	/**
	 * Select options for the action types.
	 *
	 * @param string $selected Currently selected action.
	 * @return string
	 */
	protected function action_options($selected)
	{
		$html = '';

		foreach (rule_repository::actions() as $action)
		{
			$html .= '<option value="' . $action . '"' . ($action === $selected ? ' selected="selected"' : '') . '>'
				. $this->language->lang('FH_RULE_ACTION_' . strtoupper($action)) . '</option>';
		}

		return $html;
	}

	/**
	 * Select options for the severities.
	 *
	 * @param int $selected Currently selected severity.
	 * @return string
	 */
	protected function severity_options($selected)
	{
		$html = '';

		foreach (constants::severity_map() as $value => $suffix)
		{
			$html .= '<option value="' . (int) $value . '"' . ((int) $value === (int) $selected ? ' selected="selected"' : '') . '>'
				. $this->language->lang('FH_SEVERITY_' . $suffix) . '</option>';
		}

		return $html;
	}
}
