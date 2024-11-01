<?php

if (!defined ('ABSPATH')) die ('No direct access allowed');

if(!class_exists('WP_List_Table')) require_once( ABSPATH.'wp-admin/includes/class-wp-list-table.php');

# http://wpengineer.com/2426/wp_list_table-a-step-by-step-guide/#comment-9617

class UpdraftManager_List_Table extends WP_List_Table {

	private $ud_downloads = false;

	public function get_columns(){
	$columns = array(
		'cb' => '<input type="checkbox" />',
		'name' => __('Name', 'simba-plugin-updates-manager'),
		'slug' => __('Slug', 'simba-plugin-updates-manager'),
		'downloads' => __('Downloads', 'simba-plugin-updates-manager'),
		'description' => __('Description', 'simba-plugin-updates-manager')
	);
	return $columns;
	}

	public function prepare_items() {
		$columns = $this->get_columns();
		$hidden = array();
		$sortable = $this->get_sortable_columns();
		$this->_column_headers = array($columns, $hidden, $sortable);
		$data = UpdraftManager_Options::get_options();
		if (!is_array($data)) $data = array();
		usort($data, array( &$this, 'usort_reorder' ) );
		$this->items = $data;
	}

	public function usort_reorder( $a, $b ) {
		// If no sort, default to title
		$orderby = ( ! empty( $_GET['orderby'] ) ) ? $_GET['orderby'] : 'name';
		// If no order, default to asc
		$order = ( ! empty($_GET['order'] ) ) ? $_GET['order'] : 'asc';
		// Determine sort order
		$result = strcmp( $a[$orderby], $b[$orderby] );
		// Send final sort direction to usort
		return ( $order === 'asc' ) ? $result : -$result;
	}

	public function get_sortable_columns() {
		$sortable_columns = array(
			'name'  => array('name',false),
			'slug' => array('slug',false),
			'downloads' => array('downloads', false),
			'description' => array('description',false),
		);
		return $sortable_columns;
	}

	public function column_default( $item, $column_name ) {
		switch( $column_name ) { 
		case 'slug':
		case 'description':
			return $item[ $column_name ];
		case 'downloads':
			if ($this->ud_downloads === false) $this->ud_downloads = Updraft_Manager::db_get_all_downloads_by_slug_and_filename(UpdraftManager_Options::get_user_id_for_licences());
// 			get_user_meta(UpdraftManager_Options::get_user_id_for_licences(), 'udmanager_downloads', true);
			if (!is_array($this->ud_downloads) || empty($this->ud_downloads[$item['slug']])) return 0;
			$total = 0;
// 			foreach ($this->ud_downloads[$item['slug']] as $fn) {
// 				if (is_array($fn)) {
// 					foreach ($fn as $dl) {
// 						$total += $dl;
// 					}
// 				}
// 			}
			foreach ($this->ud_downloads[$item['slug']] as $fn => $dl) {
				$total += $dl;
			}
			return $total;
		default:
			return print_r( $item, true ) ; //Show the whole array for troubleshooting purposes
		}
	}

	public function column_name($item) {
		$isactive = (isset($item['active']) && false == $item['active']) ? false : true;
		
		$nonce = wp_create_nonce('updraftmanager-action-nonce');
		
		$actions = array(
			'edit'      => sprintf('<a href="?page=%s&action=%s&oldslug=%s&nonce=%s">Edit</a>',$_REQUEST['page'], 'edit', $item['slug'], $nonce),
			'managezips'      => sprintf('<a href="?page=%s&action=%s&slug=%s&nonce=%s">'.__('Manage Zips', 'simba-plugin-updates-manager').'</a>', $_REQUEST['page'], 'managezips', $item['slug'], $nonce),
			'delete'    => sprintf('<a class="udmplugin_delete" href="?page=%s&action=%s&slug=%s&nonce=%s">'.__('Delete').'</a>', $_REQUEST['page'], 'delete', $item['slug'], $nonce),
			'activation'    => sprintf('<a href="?page=%s&action=%s&slug=%s&nonce=%s">%s</a>', $_REQUEST['page'], ($isactive) ? 'deactivate' : 'activate', $item['slug'], $nonce, ($isactive) ? __('De-activate') : __('Activate')),
		);
		$name = $item['name'].(($isactive) ? '' : ' <strong>(inactive)</strong>');
		
		$name .= empty($item['zips']) ? ' <strong>'.__('(No zips)', 'simba-plugin-updates-manager').'</strong>' : ' '.sprintf(__('(%d zips)', 'simba-plugin-updates-manager'), count($item['zips']));

		if (!empty($item['zips']) && empty($item['rules'])) $name .= ' <strong>'.__('(No rules)', 'simba-plugin-updates-manager').'</strong>';

		return sprintf('%1$s %2$s', $name, $this->row_actions($actions) );
	}

	public function get_bulk_actions() {
		$actions = array(
			'delete' => __('Delete')
		);
		return $actions;
	}

	public function column_cb($item) {
		return sprintf(
			'<input type="checkbox" name="slug[]" value="%s" />', $item['slug']
		);    
	}

}
