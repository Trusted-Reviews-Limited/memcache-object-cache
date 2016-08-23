<?php

/*
Plugin Name: Memcached
Description: Memcached backend for the WP Object Cache.
Plugin URI: http://wordpress.org/extend/plugins/memcached/
Author: Ryan Boren, Denis de Bernardy, Matt Martz

Install this file to wp-content/object-cache.php
*/

/*
 * Users with setups where multiple installs share a common wp-config.php or $table_prefix
 * can use this to guarantee uniqueness for the keys generated by this object cache
 */
defined( 'WP_CACHE_KEY_SALT' ) or define( 'WP_CACHE_KEY_SALT', DB_NAME );

/**
 * Should the connection to memcache be persistent (default true)
 */
defined( 'WP_MEMCACHE_PERSISTENT' ) or define( 'WP_MEMCACHE_PERSISTENT', true );

/**
 * Value in seconds which will be used for connecting to the daemon. (default 1)
 */
defined( 'WP_MEMCACHE_TIMEOUT' ) or define( 'WP_MEMCACHE_TIMEOUT', 1 );

/**
 * Number of buckets to create for this server which in turn control its probability
 * of it being selected. The probability is relative to the total weight of all servers.
 * (default 1)
 */
defined( 'WP_MEMCACHE_WEIGHT' ) or define( 'WP_MEMCACHE_WEIGHT', 1 );

/**
 * Controls how often a failed server will be retried, the default value is 15 seconds
 */
defined( 'WP_MEMCACHE_RETRY' ) or define( 'WP_MEMCACHE_RETRY', 15 );

/**
 * Controls if memcache writes to log
 */
defined( 'WP_MEMCACHE_DISABLE_LOGGING' ) or define( 'WP_MEMCACHE_DISABLE_LOGGING', false );

/**
 * Disable / Enable memcache flushing
 */
defined( 'WP_MEMCACHE_DISABLE_FLUSHING' ) or define( 'WP_MEMCACHE_DISABLE_FLUSHING', true );



function wp_cache_add($key, $data, $group = '', $expire = 0) {
	global $wp_object_cache;

	return $wp_object_cache->add($key, $data, $group, $expire);
}

function wp_cache_incr($key, $n = 1, $group = '') {
	global $wp_object_cache;

	return $wp_object_cache->incr($key, $n, $group);
}

function wp_cache_decr($key, $n = 1, $group = '') {
	global $wp_object_cache;

	return $wp_object_cache->decr($key, $n, $group);
}

function wp_cache_close() {
	global $wp_object_cache;

	return $wp_object_cache->close();
}

function wp_cache_delete($key, $group = '') {
	global $wp_object_cache;

	return $wp_object_cache->delete($key, $group);
}

function wp_cache_flush() {
	global $wp_object_cache;

	return $wp_object_cache->flush();
}

function wp_cache_get($key, $group = '', $force = false) {
	global $wp_object_cache;

	return $wp_object_cache->get($key, $group, $force);
}

function wp_cache_get_multi($groups, $force = false) {
	global $wp_object_cache;

	return $wp_object_cache->get_multi($groups, $force);
}

function wp_cache_init() {
	global $wp_object_cache;

	$wp_object_cache = new WP_Object_Cache();
}

function wp_cache_replace($key, $data, $group = '', $expire = 0) {
	global $wp_object_cache;

	return $wp_object_cache->replace($key, $data, $group, $expire);
}

function wp_cache_set($key, $data, $group = '', $expire = 0) {
	global $wp_object_cache;

	if ( defined( 'WP_INSTALLING' ) == false ) {
		if ( 'notoptions' === $key && 'options' === $group && is_array( $data ) ) {
			if ( array_key_exists( 'home', $data ) ) {
				unset( $data['home'] );
				if ( extension_loaded( 'newrelic' ) ) {
					newrelic_notice_error( 'Illegal notoptions set for home' );
				}
				error_log( 'Tried to set home in notoptions, but we prevented it.' ); 
			}
			if ( array_key_exists( 'siteurl', $data ) ) {
				unset( $data['siteurl'] );
				if ( extension_loaded( 'newrelic' ) ) {
					newrelic_notice_error( 'Illegal notoptions set for siteurl' );
				}
				error_log( 'Tried to set siteurl in notoptions, but we prevented it.' );
			}
		}
		return $wp_object_cache->set( $key, $data, $group, $expire );
	} else {
		return $wp_object_cache->delete( $key, $group );
	}
}

function wp_cache_switch_to_blog( $blog_id ) {
	global $wp_object_cache;

	return $wp_object_cache->switch_to_blog( $blog_id );
}

function wp_cache_add_global_groups( $groups ) {
	global $wp_object_cache;

	$wp_object_cache->add_global_groups($groups);
}

function wp_cache_add_non_persistent_groups( $groups ) {
	global $wp_object_cache;

	$wp_object_cache->add_non_persistent_groups($groups);
}

class WP_Object_Cache {
	var $global_groups = array();

	var $no_mc_groups = array();

	var $cache = array();
	var $mc = array();
	var $stats = array();
	var $group_ops = array();

	var $cache_enabled = true;
	var $default_expiration = 0;

	var $blog_prefix = 0;

	function add($id, $data, $group = 'default', $expire = 0) {
		$key = $this->key($id, $group);

		if ( is_object( $data ) ) {
			$data = clone $data;
		}

		if ( in_array($group, $this->no_mc_groups) ) {
			$this->cache[$key] = $data;
			return true;
		} elseif ( isset($this->cache[$key]) && $this->cache[$key] !== false ) {
			return false;
		}

		$mc =& $this->get_mc($group);
		$expire = ($expire == 0) ? $this->default_expiration : $expire;

		$time = microtime(true);
		$result = $mc->add($key, $data, false, $expire);
		$time_taken = microtime(true) - $time;

		if ( false !== $result ) {
			@ ++$this->stats['add'];
			$this->stats['add_time'] += $time_taken;
			$this->group_ops[$group][] = "add $id";
			$this->cache[$key] = $data;
		}

		return $result;
	}

	function add_global_groups($groups) {
		if ( ! is_array( $groups ) ) {
			$groups = (array) $groups;
		}

		$this->global_groups = array_merge($this->global_groups, $groups);
		$this->global_groups = array_unique($this->global_groups);
	}

	function add_non_persistent_groups($groups) {
		if ( ! is_array( $groups ) ) {
			$groups = (array) $groups;
		}

		$this->no_mc_groups = array_merge($this->no_mc_groups, $groups);
		$this->no_mc_groups = array_unique($this->no_mc_groups);
	}

	function incr($id, $n = 1, $group = 'default' ) {
		$key = $this->key($id, $group);
		$mc =& $this->get_mc($group);
		$this->cache[ $key ] = $mc->increment( $key, $n );
		return $this->cache[ $key ];
	}

	function decr($id, $n = 1, $group = 'default' ) {
		$key = $this->key($id, $group);
		$mc =& $this->get_mc($group);
		$this->cache[ $key ] = $mc->decrement( $key, $n );
		return $this->cache[ $key ];
	}

	function close() {

		foreach ( $this->mc as $bucket => $mc ) {
			$mc->close();
		}
	}

	function delete($id, $group = 'default') {
		$key = $this->key($id, $group);

		if ( in_array($group, $this->no_mc_groups) ) {
			unset($this->cache[$key]);
			return true;
		}

		$mc =& $this->get_mc($group);

		$time = microtime(true);
		$result = $mc->delete($key);
		$time_taken = microtime(true) - $time;

		@ ++$this->stats['delete'];
		$this->stats['delete_time'] += $time_taken;
		$this->group_ops[$group][] = "delete $id";

		if ( false !== $result ) {
			unset( $this->cache[ $key ] );
		}

		return $result;
	}

	function flush() {

		// Return true is flushing is disabled
		if ( ! WP_MEMCACHE_DISABLE_FLUSHING ) {
			return true;
		}

		// Did someone try and wipe our stats? >:(
		// This occurs during unit tests, where WP reaches in and resets the
		// stats array.
		if ( empty( $this->stats ) ) {
			$this->reset_stats();
		}

		if ( function_exists( 'is_main_site' ) && is_main_site() ) {
			if( ! $this->set_site_key( $this->global_prefix ) ) {
				return false;
			}
		}

		if( ! $this->set_site_key( $this->blog_prefix ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Flush the local (in-memory) object cache
	 *
	 * Forces all future requests to fetch from memcache. Can be used to
	 * alleviate memory pressure in long-running requests.
	 */
	public function flush_local() {
		$this->cache = array();
		$this->group_ops = array();
	}

	protected function reset_stats() {
		$this->stats = array( 'get' => 0, 'get_time' => 0, 'add' => 0, 'add_time' => 0, 'delete' => 0, 'delete_time' => 0, 'set' => 0, 'set_time' => 0 );
	}

	function get($id, $group = 'default', $force = false) {
		$key = $this->key($id, $group);
		$mc =& $this->get_mc($group);

		if ( isset($this->cache[$key]) && ( !$force || in_array($group, $this->no_mc_groups) ) ) {
			if ( is_object( $this->cache[ $key ] ) ) {
				$value = clone $this->cache[ $key ];
			} else {
				$value = $this->cache[ $key ];
			}
		} else if ( in_array($group, $this->no_mc_groups) ) {
			$this->cache[$key] = $value = false;
		} else {

			$time = microtime(true);

			$value = $mc->get($key);
	                if ( NULL === $value )
                        	$value = false;

            $time_taken = microtime(true) - $time;

			$this->cache[$key] = $value;
			@ ++$this->stats['get'];
			$this->stats['get_time'] += $time_taken;
			$this->group_ops[$group][] = "get $id";
		}

		if ( 'checkthedatabaseplease' === $value ) {
			unset( $this->cache[$key] );
			$value = false;
		}

		return $value;
	}

	function get_multi( $groups ) {
		/*
		format: $get['group-name'] = array( 'key1', 'key2' );
		*/
		$return = array();
		$to_get = array();

		foreach ( $groups as $group => $ids ) {
			$mc               =& $this->get_mc( $group );
			$return[ $group ] = array();

			foreach ( $ids as $id ) {
				$key = $this->key( $id, $group );
				if ( isset( $this->cache[ $key ] ) ) {
					if ( is_object( $this->cache[ $key ] ) ) {
						$return[ $group ][ $id ] = clone $this->cache[ $key ];
					} else {
						$return[ $group ][ $id ] = $this->cache[ $key ];
					}
					continue;
				} else if ( in_array( $group, $this->no_mc_groups ) ) {
					$return[ $group ][ $id ] = false;
					continue;
				} else {
					$to_get[ $key ] = array( $group, $id );
				}
			}
		}

		if ( $to_get ) {
			$vals = $mc->get( array_keys( $to_get ) );

			foreach ( $to_get as $key => $bits ) {
				if ( ! isset( $vals[ $key ] ) ) {
					continue;
				}

				list( $group, $id ) = $bits;

				$return[ $group ][ $id ] = $vals[ $key ];
				$this->cache[ $key ]     = $vals[ $key ];
			}
		}

		@ ++$this->stats['get_multi'];
		$this->group_ops[$group][] = "get_multi $id";
		return $return;
	}

	function key($key, $group) {
		if ( empty( $group ) ) {
			$group = 'default';
		}

		if ( false !== array_search( $group, $this->global_groups ) ) {
			$prefix = $this->global_prefix;
		} else {
			$prefix = $this->blog_prefix;
		}

		$site_key = $this->get_site_key( $prefix );

		return preg_replace('/\s+/', '', WP_CACHE_KEY_SALT . ":$site_key$prefix:$group:$key" );
	}

	private function build_site_key( $blog_id ) {

		$blog_id = empty( $blog_id ) ? 'global' : $blog_id;

		return preg_replace( '/\s+/', '', WP_CACHE_KEY_SALT . ":site_key:$blog_id" );

	}

	public function get_site_key( $blog_id = false ) {

		$key = $this->build_site_key( $blog_id );
		$mc =& $this->get_mc( 'site_keys' );

		if ( ! isset( $this->cache[ $key ] ) ) {

			$this->cache[ $key ] = $mc->get( $key );

			if ( false === $this->cache[ $key ] ) {

				$this->set_site_key( $blog_id );

			}

		}

		return $this->cache[ $key ];

	}

	public function set_site_key( $blog_id = false ) {

		$key = $this->build_site_key( $blog_id );
		$mc =& $this->get_mc( 'site_keys' );

		$value = (string) intval( microtime( true ) * 1e6 );

		$this->cache[ $key ] = $value;
		return $mc->set( $key, $value, false, 0 );

	}

	function replace( $id, $data, $group = 'default', $expire = 0 ) {
		$key    = $this->key( $id, $group );
		$expire = ( $expire == 0 ) ? $this->default_expiration : $expire;
		$mc     =& $this->get_mc( $group );

		if ( is_object( $data ) ) {
			$data = clone $data;
		}

		$result = $mc->replace( $key, $data, false, $expire );
		if ( false !== $result ) {
			$this->cache[ $key ] = $data;
		}

		return $result;
	}

	function set($id, $data, $group = 'default', $expire = 0) {
		$key = $this->key($id, $group);
		if ( isset( $this->cache[ $key ] ) && ( 'checkthedatabaseplease' === $this->cache[ $key ] ) ) {
			return false;
		}

		if ( is_object( $data ) ) {
			$data = clone $data;
		}

		$this->cache[ $key ] = $data;

		if ( in_array( $group, $this->no_mc_groups ) ) {
			return true;
		}

		$expire = ($expire == 0) ? $this->default_expiration : $expire;
		$mc =& $this->get_mc($group);

		$time = microtime(true);

		/**
		 * If the expiry exceeds 30 days, we have to sent the expire
		 * as a unix timestamp. This is because PHP Memcache extension
		 * uses this hueristic.
		 *
		 * To make this consistant, we always use absolute unix timestamps.
		 */
		if ( $expire ) {
			$expire += time();
		}

		$result = $mc->set($key, $data, false, $expire);
		$time_taken = microtime(true) - $time;

		@ ++$this->stats['set'];
		$this->stats['set_time'] += $time_taken;
		$this->group_ops[$group][] = "set $id";

		if ('alloptions' == $id && 'options' == $group)
			wp_cache_delete('alloptions', 'options');

		return $result;
	}

	function switch_to_blog( $blog_id ) {
		global $wpdb;
		$table_prefix      = $wpdb->prefix;
		$blog_id           = (int) $blog_id;
		$this->blog_prefix = ( is_multisite() ? $blog_id : $table_prefix );
	}

	function colorize_debug_line($line) {
		$colors = array(
			'get' => 'green',
			'set' => 'purple',
			'add' => 'blue',
			'delete' => 'red');

		$cmd = substr($line, 0, strpos($line, ' '));

		$cmd2 = "<span style='color:{$colors[$cmd]}'>$cmd</span>";

		return $cmd2 . substr($line, strlen($cmd)) . "\n";
	}

	function stats() {
		echo "<p>\n";
		foreach ( $this->stats as $stat => $n ) {

			if ( ! $n ) {
				continue;
			}

			echo "<strong>$stat</strong> $n";
			echo "<br/>\n";
		}
		echo "</p>\n";
		echo "<h3>Memcached:</h3>";
		foreach ( $this->group_ops as $group => $ops ) {
			if ( !isset($_GET['debug_queries']) && 500 < count($ops) ) {
				$ops = array_slice( $ops, 0, 500 );
				echo "<big>Too many to show! <a href='" . add_query_arg( 'debug_queries', 'true' ) . "'>Show them anyway</a>.</big>\n";
			}
			echo "<h4>$group commands</h4>";
			echo "<pre>\n";
			$lines = array();
			foreach ( $ops as $op ) {
				$lines[] = $this->colorize_debug_line($op);
			}
			print_r($lines);
			echo "</pre>\n";
		}
	}

	function &get_mc($group) {
		if ( isset( $this->mc[ $group ] ) ) {
			return $this->mc[ $group ];
		}

		return $this->mc['default'];
	}

	function failure_callback( $host, $port ) {
		if ( WP_MEMCACHE_DISABLE_LOGGING ) {
			if ( extension_loaded( 'newrelic' ) ) {
				newrelic_notice_error( "Memcache Connection failure for $host:$port" );
			}
			error_log( "Memcache Connection failure for $host:$port\n" );
		}
	}

	function WP_Object_Cache() {
		global $memcached_servers, $blog_id, $table_prefix;

		if ( isset( $memcached_servers ) ) {
			$buckets = $memcached_servers;
		} else {
			$buckets = array( '127.0.0.1:11211' );
		}

		reset( $buckets );
		if ( is_int( key( $buckets ) ) ) {
			$buckets = array( 'default' => $buckets );
		}

		foreach ( $buckets as $bucket => $servers) {
			$this->mc[$bucket] = new Memcache();
			foreach ( $servers as $server  ) {
				list ( $node, $port ) = explode(':', $server);
				if ( ! $port ) {
					$port = ini_get( 'memcache.default_port' );
				}
				$port = intval( $port );
				if ( ! $port ) {
					$port = 11211;
				}
				$this->mc[$bucket]->addServer($node, $port, WP_MEMCACHE_PERSISTENT, WP_MEMCACHE_WEIGHT, WP_MEMCACHE_TIMEOUT, WP_MEMCACHE_RETRY, true, array($this, 'failure_callback'));
				$this->mc[$bucket]->setCompressThreshold(20000, 0.2);
			}
		}


		$this->global_prefix = '';
		$this->blog_prefix = '';
		if ( function_exists( 'is_multisite' ) ) {
			$this->global_prefix = ( is_multisite() || defined('CUSTOM_USER_TABLE') && defined('CUSTOM_USER_META_TABLE') ) ? '' : $table_prefix;
			$this->blog_prefix = ( is_multisite() ? $blog_id : $table_prefix );
		}

		$this->reset_stats();
		$this->cache_hits =& $this->stats['get'];
		$this->cache_misses =& $this->stats['add'];
	}
}
