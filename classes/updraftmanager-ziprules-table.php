<?php

if (!defined ('ABSPATH')) die ('No direct access allowed');

if(!class_exists('WP_List_Table')) require_once( ABSPATH.'wp-admin/includes/class-wp-list-table.php');

# http://wpengineer.com/2426/wp_list_table-a-step-by-step-guide/#comment-9617

class UpdraftManager_ZipRules_Table extends WP_List_Table {

	private $plug_slug;

	public function __construct($slug) {
		// Not entirely sure why this double-save is needed; seems like somewhere after WP 4.1, $this->slug started getting over-written
		$this->plug_slug = $slug;
		$this->slug = $slug;
		parent::__construct();
	}

	public function get_columns() {
		$columns = array(
			'cb' => '<input type="checkbox" />',
			'ruleno' => __('Rule No.', 'simba-plugin-updates-manager'),
			'rule' => __('Rule', 'simba-plugin-updates-manager')
		);
		return $columns;
	}

	public function prepare_items() {
		$columns = $this->get_columns();
		$hidden = array();
		$sortable = $this->get_sortable_columns();
		$this->_column_headers = array($columns, $hidden, $sortable);
		$data = UpdraftManager_Options::get_options();
		$data = (empty($this->plug_slug) || empty($data[$this->plug_slug]['rules']) || !is_array($data[$this->plug_slug]['rules'])) ? array() : $data[$this->plug_slug]['rules'];

		foreach ($data as $ind => $rule) {
			$data[$ind]['ruleno'] = $ind+1;
		}

		usort($data, array( &$this, 'usort_reorder' ) );
		$this->items = $data;
	}

	public function usort_reorder( $a, $b ) {
		// If no sort, default to title
		$orderby = ( ! empty( $_GET['orderby'] ) ) ? $_GET['orderby'] : 'ruleno';
		// If no order, default to asc
		$order = ( ! empty($_GET['order'] ) ) ? $_GET['order'] : 'asc';
		// Determine sort order
		if (empty($a[$orderby]) && empty($b[$orderby])) return $a;
		$result = strcmp( $a[$orderby], $b[$orderby] );
		// Send final sort direction to usort
		return ( $order === 'asc' ) ? $result : -$result;
	}

	public function get_sortable_columns() {
		$sortable_columns = array(
			'ruleno'  => array('ruleno', false),
			'rule' => array('rule', false)
		);
		return $sortable_columns;
	}

	public function column_default($item, $column_name) {
		switch( $column_name ) { 
		case 'ruleno':
		case 'rule':
			return $item[$column_name];
		default:
			return print_r( $item, true ) ; //Show the whole array for troubleshooting purposes
		}
	}

	public function no_items() {
		_e( 'No rules found.' );
		echo ' '.__('Users will not be able to obtain any updates.', 'simba-plugin-updates-manager');
	}

	public function column_rule($item) {
	
		$nonce = wp_create_nonce('updraftmanager-action-nonce');
	
		$actions = array(
			'edit'      => sprintf('<a href="?page=%s&action=%s&oldruleno=%s&slug=%s&nonce=%s">'.__('Edit').'</a>', $_REQUEST['page'],'edit_rule', $item['ruleno'], $this->plug_slug, $nonce),
			'delete'    => sprintf('<a class="udmrule_delete" href="?page=%s&action=%s&ruleno=%s&slug=%s&nonce=%s">'.__('Delete').'</a>', $_REQUEST['page'],'delete_rule', $item['ruleno'], $this->plug_slug, $nonce)
		);

		$combination = empty($item['combination']) ? '' : $item['combination'];
		$combination = ($combination != 'or') ? 'and' : $combination;

		$desc = '';

		$target = empty($item['filename']) ? __('Unknown', 'simba-plugin-updates-manager') : $item['filename'];

		$rules = empty($item['rules']) ? array() : $item['rules'];
		if (!is_array($rules)) $rules = array();

		foreach ($rules as $rule) {
			$desc .= ($desc) ? ' '.(('and' == $combination) ? __('and', 'simba-plugin-updates-manager') : __('or', 'simba-plugin-updates-manager')).' ' : '';

			$criteria = empty($rule['criteria']) ? '' : $rule['criteria'];
			$relationship = empty($rule['relationship']) ? '' : $rule['relationship'];

			if ('always' != $criteria) {
				switch ($relationship) {
					case 'gt':
						$vdes = htmlspecialchars(">=");
					break;
					case 'lt':
						$vdes = htmlspecialchars("<=");
					break;
					case 'range':
						$vdes = __('is between (inclusive)', 'simba-plugin-updates-manager');
					break;
					case 'eq':
					default:
						$vdes = '=';
					break;
				}
			}

			switch ($criteria) {
				case 'always':
					$desc .= __('always', 'simba-plugin-updates-manager');
				break;
				case 'installed':
					$desc .= sprintf(__('version %s %s', 'simba-plugin-updates-manager'), $vdes, $rule['value']);
				break;
				case 'wp':
					$desc .= sprintf(__('WordPress version %s %s', 'simba-plugin-updates-manager'), $vdes, $rule['value']);
				break;
				case 'php':
					$desc .= sprintf(__('PHP version %s %s', 'simba-plugin-updates-manager'), $vdes, $rule['value']);
				break;
				case 'username':
					$desc .= sprintf(__('the user account is %s', 'simba-plugin-updates-manager'), $rule['value']);
				break;
				case 'siteurl':
					$desc .= sprintf(__('the site URL is %s', 'simba-plugin-updates-manager'), $rule['value']);
				break;
				case 'random_percent':
					$desc .= sprintf(__('on %.2f %% of updates checks', 'simba-plugin-updates-manager'), (float)$rule['value']);
				break;
				default:
					$desc .= __('Unknown', 'simba-plugin-updates-manager');
				break;
			}
		}

		$desc = $target.'<br>'.__('Use if:', 'simba-plugin-updates-manager').' '.$desc;

		return sprintf('%1$s %2$s', $desc, $this->row_actions($actions) );
	}

	public function get_bulk_actions() {
		return array(
			'delete_rule' => __('Delete')
		);
	}

	public function column_cb($item) {
		return sprintf(
			'<input type="checkbox" name="ruleno[]" value="%s" />', $item['ruleno']
		);    
	}

}
