<?php

if (!defined ('ABSPATH')) die ('No direct access allowed');

if(!class_exists('WP_List_Table')) require_once( ABSPATH.'wp-admin/includes/class-wp-list-table.php');

# http://wpengineer.com/2426/wp_list_table-a-step-by-step-guide/#comment-9617

class UpdraftManager_Zips_Table extends WP_List_Table {

	private $plug_slug;
	private $ud_downloads = false;

	/**
	 * Constructor
	 *
	 * @param String $slug - the plugin slug
	 */
	public function __construct($slug) {
		// Not entirely sure why this double-save is needed; seems like somewhere after WP 4.1, $this->slug started getting over-written
		$this->plug_slug = $slug;
		$this->slug = $slug;
		parent::__construct();
	}

	public function get_columns() {
		$columns = array(
			'cb' => '<input type="checkbox" />',
			'filename' => __('Filename', 'simba-plugin-updates-manager'),
			'version' => __('Version', 'simba-plugin-updates-manager'),
			'downloads' => __('Downloads', 'simba-plugin-updates-manager'),
			'minwpver' => __('Minimum WP Version', 'simba-plugin-updates-manager'),
			'testedwpver' => __('Tested WP Version', 'simba-plugin-updates-manager'),
		);
		return $columns;
	}

	public function prepare_items() {
		$columns = $this->get_columns();
		$hidden = array();
		$sortable = $this->get_sortable_columns();
		$this->_column_headers = array($columns, $hidden, $sortable);
		$data = UpdraftManager_Options::get_options();
		$data = (empty($this->plug_slug) || empty($data[$this->plug_slug]['zips']) || !is_array($data[$this->plug_slug]['zips'])) ? array() : $data[$this->plug_slug]['zips'];
		usort($data, array( &$this, 'usort_reorder' ) );
		$this->items = $data;
	}

	public function no_items() {
		_e( 'No zips found.' );
		echo ' '.__('Users will not be able to obtain any updates.', 'simba-plugin-updates-manager');
	}

	public function usort_reorder( $a, $b ) {
		// If no sort, default to title
		$orderby = ( ! empty( $_GET['orderby'] ) ) ? $_GET['orderby'] : 'filename';
		// If no order, default to desc
		$order = ( ! empty($_GET['order'] ) ) ? $_GET['order'] : 'desc';
		if (empty($a[$orderby]) && empty($b[$orderby])) return $a;
		// Determine sort order
		$result = version_compare($a[$orderby], $b[$orderby]);
		// Send final sort direction to usort
		return ( $order === 'asc' ) ? $result : -$result;
	}

	public function get_sortable_columns() {
		$sortable_columns = array(
			'filename'  => array('filename', false),
			'version' => array('version', false),
			'downloads' => array('downloads', false),
			'minwpver' => array('minwpver', false),
			'testedwpver' => array('testedwpver', false)
		);
		return $sortable_columns;
	}

	public function column_default($item, $column_name) {
		switch( $column_name ) { 
		case 'filename':
		case 'minwpver':
		case 'testedwpver':
		case 'version':
			return $item[$column_name];
		case 'downloads':
			if (false === $this->ud_downloads) $this->ud_downloads = Updraft_Manager::db_get_all_downloads_by_slug_and_filename(UpdraftManager_Options::get_user_id_for_licences());
// 			= get_user_meta(UpdraftManager_Options::get_user_id_for_licences(), 'udmanager_downloads', true);
			if (!is_array($this->ud_downloads) || empty($this->ud_downloads[$this->plug_slug][$item['filename']])) return 0;
			$total = 0;
// 			foreach ($this->ud_downloads[$this->plug_slug][$item['filename']] as $dl) {
// 				$total += $dl;
// 			}
// 			return $total;
			return $this->ud_downloads[$this->plug_slug][$item['filename']];
		default:
			return print_r( $item, true ) ; //Show the whole array for troubleshooting purposes
		}
	}

	public function column_filename($item) {

		$nonce = wp_create_nonce('updraftmanager-action-nonce');
	
		static $manager_dir;
		if (empty($manager_dir)) $manager_dir = UpdraftManager_Options::get_manager_dir();
	
		$actions = array(
			'edit'      => sprintf('<a href="?page=%s&action=%s&oldfilename=%s&slug=%s&nonce=%s">'.__('Edit', 'simba-plugin-updates-manager').'</a>', htmlspecialchars($_REQUEST['page']), 'edit_zip', $item['filename'], $this->plug_slug, $nonce),
			'delete'    => sprintf('<a class="udmzip_delete" href="?page=%s&action=%s&filename=%s&slug=%s&nonce=%s">'.__('Delete (and associated rules)', 'simba-plugin-updates-manager').'</a>', htmlspecialchars($_REQUEST['page']), 'delete_zip', $item['filename'], $this->plug_slug, $nonce),
		);
		
		if (isset($item['filename']) && file_exists($manager_dir.'/'.$item['filename'])) {
			$actions['download'] = sprintf('<a class="udmzip_download" href="'.admin_url('admin-ajax.php').'?action=udmanager_ajax&subaction=download&filename=%s&slug=%s&nonce=%s">'.__('Download', 'simba-plugin-updates-manager').'</a>', $item['filename'], $this->plug_slug, wp_create_nonce('updraftmanager-ajax-nonce'));
		}
		
		return sprintf('%1$s %2$s', $item['filename'], $this->row_actions($actions) );
	}

	public function get_bulk_actions() {
		return array('delete_zip' => 'Delete');
	}

	public function column_cb($item) {
		return sprintf(
			'<input type="checkbox" name="filename[]" value="%s" />', $item['filename']
		);    
	}

}
