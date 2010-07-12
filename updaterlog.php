<?php
/*
 * Plugin Name: Log Updater Speed
 * Plugin URI: http://github.com/rmccue/Updater-Speed-Log
 * Description: Logs the timings of the item updater to help identify bottlenecks in the code, and/or to find generally slow feeds.
 * Version: 0.1
 * Author: Ryan McCue
 * Author URI: http://ryanmccue.info/
 */
class UpdaterSpeedLog {
	protected $log = array();
	protected $feed = '';
	public function __construct() {
		$this->log = get_option('updaterspeedlog-log', array());

		add_filter('subnavigation',        array( &$this, 'admin_menu' ));
		add_action('admin_page_usl-admin', array( &$this, 'admin_page' ));

		add_action('init',                 array( &$this, 'init' ));
		add_action('iu-feed-start',        array( &$this, 'feed_start' ), 10, 1);
		add_filter('simplepie-config',     array( &$this, 'pre_feed_load' ));
		add_filter('iu-load-feed',         array( &$this, 'load_feed' ));
		add_filter('item_data_precache',   array( &$this, 'item_precache' ));
		add_action('iu-item-add',          array( &$this, 'item_add' ));
		add_action('iu-item-noadd',          array( &$this, 'item_noadd' ));
		add_action('iu-feed-finish',       array( &$this, 'feed_finish' ));
	}
	public function admin_menu($menu) {
		$menu['settings.php'][] = array(
			'Updater Speed Log',
			'admin.php?page=usl-admin',
			'settings'
		);
		return $menu;
	}
	public function admin_header() {
?>
			<style type="text/css">
				#clear-form {
					float: right;
				}
				#full-log .skipped td:first-child, #full-log .added td:first-child {
					padding-left: 40px;
					color: #666;
				}
				#full-log .header {
					background: #0CA2FF;
					color: #fff;
					white-space: nowrap;
					border-bottom: none;
				}
			</style>
<?php
	}
	public function admin_page() {
		if(isset($_POST['clear'])) {
			$this->log = array();
			$this->save_log();
			
			header('HTTP/1.1 302 Found', true, 302);
			header('Location: ' . get_option('baseurl') . 'admin/admin.php?page=usl-admin&cleared=1');
			die();
		}
		$this->log = get_option('updaterspeedlog-log', array());

		// Adding it here to avoid putting on other pages
		add_action('admin_header', array( &$this, 'admin_header' ));
		admin_header('Updater Speed Log');

		if(!empty($_GET['cleared'])) {
			echo '<div class="message"><p>Cleared log.</p></div>';
		}

		$feeds = array();
		foreach($this->log as $item) {
			if($item['name'] == 'Starting feed processing') {
				$feeds[$item['feed']] = array(
					'start' => $item['time'],
					'processed' => 0,
					'added' => 0
				);
			}
			elseif($item['name'] == 'Loaded feed') {
				$feeds[$item['feed']]['loaded'] = $item['time'];
			}
			elseif($item['name'] == 'Finish feed processing') {
				echo '<tr></tr>';
				$feeds[$item['feed']]['finish'] = $item['time'];
			}
			elseif($item['name'] == 'Item about to be checked') {
				$feeds[$item['feed']]['processed']++;
			}
			elseif($item['name'] == 'Added item to database') {
				$feeds[$item['feed']]['added']++;
			}
		}
?>
		<h1>Updater Speed Log</h1>
<?php
		if(empty($this->log)):
?>
		<p>No logging data yet! Try updating your feeds.</p>
<?php
		else:
?>
		<form action="" method="POST" id="clear-form">
			<input type="hidden" name="clear" value="clear" />
			<button type="submit">Clear log</button>
		</form>
		<h2>Summary</h2>
		<table class="item-table">
			<thead>
				<tr>
					<th>Feed</th>
					<th>Load Time</th>
					<th>Time Processing</th>
					<th>Total Time</th>
					<th>Items Processed</th>
					<th><abbr title="Average">Avg</abbr> Processing Time Per Item</th>
				</tr>
			</thead>
			<tbody>
<?php
		foreach($feeds as $id => $feed) {
			$the_feed = Feeds::get_instance()->get($id);
			$avg_time = $feed['finish'] - $feed['loaded'];
			$avg_time = $avg_time / $feed['processed'];
?>
				<tr id="feed-<?php echo $id ?>">
					<td><?php echo $the_feed['name'] ?></td>
					<td><?php echo round($feed['loaded'] - $feed['start'], 3) ?></td>
					<td><?php echo round($feed['finish'] - $feed['loaded'], 3) ?></td>
					<td><?php echo round($feed['finish'] - $feed['start'], 3) ?></td>
					<td><?php echo $feed['added'] . ' / ' . $feed['processed'] ?></td>
					<td><?php echo round($avg_time, 3) ?></td>
				</tr>
<?php
		}
?>
			</tbody>
		</table>
		<h3>Full Log</h3>
		<table class="item-table" id="full-log">
			<thead>
				<tr>
					<th>Type</th>
					<th>Time Since Init</th>
					<th>Feed</th>
				</tr>
			</thead>
			<tbody>
<?php
		$start = 0;
		foreach($this->log as $item) {
			$class = '';

			if($item['name'] == 'Initialized Lilina') {
				$start = $item['time'];
				$class = ' init';
			}

			if($item['name'] == 'Skipped item') { $class = ' skipped'; }
			if($item['name'] == 'Added item to database') { $class = ' added'; }

			$feed = Feeds::get_instance()->get($item['feed']);
			$since = round($item['time'] - $start, 3);
			if(!$feed) {
				$feed = array('name' => 'n/a');
			}
?>
				<tr class="<?php echo $class ?>">
					<td><?php echo $item['name'] ?></td>
					<td><?php echo $since ?></td>
					<td><a href="#feed-<?php echo $item['feed'] ?>"><?php echo $feed['name'] ?></a></td>
				</tr>
<?php
			if($item['name'] == 'Finish feed processing') { echo '
				<tr class="header">
					<th>Type</th>
					<th>Time Since Init</th>
					<th>Feed</th>
				</tr>'; }
		}
?>
			</tbody>
		</table>
		<script type="text/javascript">
		jQuery(document).ready(function ($) {
			$("#full-log").hide();
			$("<p class='hideshow'><span>Show full log</span></p>").insertBefore("#full-log").click(function () {
				$("#full-log").show();
				$(this).hide();
			});
		});
		</script>
<?php
		endif;
		admin_footer();
	}
	public function init() {
		$this->log('Initialized Lilina');
	}
	public function feed_start($feed) {
		$this->feed = $feed['id'];
		$this->log('Starting feed processing');
	}
	public function pre_feed_load($config) {
		$this->log('Before loading feed');
		return $config;
	}
	public function &load_feed(&$feed) {
		$this->log('Loaded feed');
		return $feed;
	}
	public function item_precache($data) {
		$this->log('Item about to be checked');
		return $data;
	}
	public function item_add() {
		$this->log('Added item to database');
	}
	public function item_noadd() {
		$this->log('Skipped item');
	}
	public function feed_finish() {
		$this->log('Finish feed processing');
		$this->save_log();
	}
	protected function log($name) {
		$this->log[] = array(
			'name' => $name,
			'time' => microtime(true),
			'feed' => $this->feed
		);
	}
	protected function save_log() {
		update_option('updaterspeedlog-log', $this->log);
	}
}

$GLOBALS['updaterspeedlog'] = new UpdaterSpeedLog();