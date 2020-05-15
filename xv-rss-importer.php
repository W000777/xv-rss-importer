<?php
/*
Plugin Name: XV RSS Importer
Plugin URI: http://wordpress.org/extend/plugins/rss-importer/
Description: Import posts from an RSS feed.
Author: W000777
Author URI: http://wordpress.org/
Version: 0.1
Stable tag: 0.1
License: GPL version 2 or later - http://www.gnu.org/licenses/old-licenses/gpl-2.0.html
Text Domain: xv-rss-importer
*/

if ( !defined('WP_LOAD_IMPORTERS') )
	return;

// Load Importer API
require_once ABSPATH . 'wp-admin/includes/import.php';
require_once ABSPATH . 'wp-includes/post-thumbnail-template.php';

if ( !class_exists( 'WP_Importer' ) ) {
	$class_wp_importer = ABSPATH . 'wp-admin/includes/class-wp-importer.php';
	if ( file_exists( $class_wp_importer ) )
		require_once $class_wp_importer;
}

/**
 * XV RSS Importer
 *
 * @package WordPress
 * @subpackage Importer
 */

/**
 * XV RSS Importer
 *
 * Will process a RSS feed for importing posts into WordPress. This is a very
 * limited importer and should only be used as the last resort, when no other
 * importer is available.
 *
 * @since unknown
 */
if ( class_exists( 'WP_Importer' ) ) {
class XVID_RSS_Import extends WP_Importer {

	var $posts = array ();
	var $file;

	var $keywords = array();
	var $kwfile;

	function header() {
		echo '<div class="wrap">';
		screen_icon();
		echo '<h2>'.__('Import XV RSS', 'rss-importer').'</h2>';
	}

	function footer() {
		echo '</div>';
	}

	function greet_kwfile() {
		echo '<div class="narrow">';
		echo '<p>'.__('Howdy! This importer allows you to extract posts from <a href="xvideos.com">xVideos</a> (an RSS 2.0 file) into your WordPress site. <br>First, pick an XML Keywords file to filter RSS and click Import.', 'rss-importer').'</p>';
		wp_import_upload_form("admin.php?import=xv-rss&amp;step=1");
		echo '</div>';
	}

    function import_keywords() {
        $file = wp_import_handle_upload();
        if ( isset($file['error']) ) {
            echo $file['error'];
            return;
        }

        $this->kwfile = $file['file'];

        $kwlines = file($this->kwfile); // Read the file into an array
        $importkw = implode('', $kwlines); // squish it
        $importkw = str_replace(array ("\r\n", "\r"), "\n", $importkw);

        preg_match_all('|<media:keywords>(.*?)</media:keywords>|is', $importkw, $this->keywords);

        if($this->keywords[1][0] != "") {
            $this->keywords = $this->keywords[1];
            $this->keywords = explode(",", $this->keywords[0]);
        } else {
            $this->keywords = array();
        }

        $this->greet_rssfile();
    }

    function greet_rssfile() {
        wp_import_cleanup($file['id']);
        do_action('import_done', 'rss');

        $kwsrt = '';
        if(count($this->keywords) > 0) {
            $kwsrt = http_build_query(array('keywords' => $this->keywords));
        }

        echo '<div class="narrow">';
        echo '<p>'.__('Howdy! This importer allows you to extract posts from <a href="xvideos.com">xVideos</a> (an RSS 2.0 file) into your WordPress site. <br>Pick an RSS file to upload and click Import.', 'rss-importer').'</p>';
        echo '<p> Keywords: ' . implode(", ", $this->keywords) . '</p>';
        wp_import_upload_form("admin.php?import=xv-rss&amp;step=2&amp;".$kwsrt);
    }


	function _normalize_tag( $matches ) {
		return '<' . strtolower( $matches[1] );
	}

	function get_posts() {
		global $wpdb;

		set_magic_quotes_runtime(0);
		$datalines = file($this->file); // Read the file into an array
		$importdata = implode('', $datalines); // squish it
		$importdata = str_replace(array ("\r\n", "\r"), "\n", $importdata);

		preg_match_all('|<item>(.*?)</item>|is', $importdata, $this->posts);
		$this->posts = $this->posts[1];
		$index = 0;
		foreach ($this->posts as $post) {
			preg_match('|<title>(.*?)</title>|is', $post, $post_title);
			$post_title = str_replace(array('<![CDATA[', ']]>'), '', $wpdb->escape( trim($post_title[1]) ));

			preg_match('|<pubdate>(.*?)</pubdate>|is', $post, $post_date_gmt);

			if ($post_date_gmt) {
				$post_date_gmt = strtotime($post_date_gmt[1]);
			} else {
				// if we don't already have something from pubDate, get the actual date.
                $post_date_gmt = time();
			}

			$post_date_gmt = gmdate('Y-m-d H:i:s', $post_date_gmt);
			$post_date = get_date_from_gmt( $post_date_gmt );

            preg_match_all('|<media:keywords>(.*?)</media:keywords>|is', $post, $categories);
            $categories = $categories[1];
            $categories = explode(",", $categories[0]);

			if (!$categories) {
                preg_match_all('|<dc:subject>(.*?)</dc:subject>|is', $post, $categories);
                $categories = $categories[1];
            }

			$cat_index = 0;
			foreach ($categories as $category) {
				$categories[$cat_index] = $wpdb->escape( html_entity_decode( $category ) );
				$cat_index++;
			}

            if ($this->keywords) {
                $haskeyword = false;
                foreach ($categories as $category) {
                    foreach ($this->keywords as $keyword) {
                        if ($category == $keyword) {
                            $haskeyword = true;
                            break;
                        }
                    }
                    if ($haskeyword == true) break;
                }
                if ($haskeyword == false) {
                    continue;
                }
            }

			preg_match('|<guid.*?>(.*?)</guid>|is', $post, $guid);
			if ($guid)
				$guid = $wpdb->escape(trim($guid[1]));
			else
				$guid = '';

            preg_match('|<thumb_medium>(.*?)</thumb_medium>|is', $post, $fifu_input_url);
            $fifu_input_url = $fifu_input_url[1];

			preg_match('|<content:encoded>(.*?)</content:encoded>|is', $post, $post_content);
			$post_content = str_replace(array ('<![CDATA[', ']]>'), '', $wpdb->escape(trim($post_content[1])));

			if (!$post_content) {
				// This is for feeds that put content in description
				preg_match('|<description>(.*?)</description>|is', $post, $post_content);
				$post_content = $wpdb->escape( html_entity_decode( trim( $post_content[1] ) ) );
			}

            $post_content = $post_content . "\n\n<img src=\"" . $fifu_input_url . "\" style=\"display:none;\"/>";
			// Clean up content
			$post_content = preg_replace_callback('|<(/?[A-Z]+)|', array( &$this, '_normalize_tag' ), $post_content);
			$post_content = str_replace('<br>', '<br />', $post_content);
			$post_content = str_replace('<hr>', '<hr />', $post_content);

			$post_author = 1;
			$post_status = 'publish';
			$this->posts[$index] = compact('post_author', 'post_date', 'post_date_gmt', 'post_content', 'post_title', 'post_status', 'guid', 'categories');
			$index++;
		}
	}

	function import_posts() {
		echo '<ol>';

		foreach ($this->posts as $post) {
			echo "<li>".__('Importing xVideos post...', 'rss-importer');

			extract($post);

			if ($post_id = post_exists($post_title, $post_content)) {
				//_e('Post already imported', 'rss-importer');
                _e("Post já importado ou não compatível com as tags", 'rss-importer');

			} else {
				$post_id = wp_insert_post($post);
				if ( is_wp_error( $post_id ) )
					return $post_id;
				if (!$post_id) {
					_e('Couldn&#8217;t get post ID', 'rss-importer');
					return;
				}

				if (0 != count($categories))
					wp_create_categories($categories, $post_id);
				_e('Done!', 'rss-importer');
			}
			echo '</li>';
		}

		echo '</ol>';

	}

	function import() {
		$file = wp_import_handle_upload();
		if ( isset($file['error']) ) {
			echo $file['error'];
			return;
		}

		$this->file = $file['file'];
		$this->get_posts();
		$result = $this->import_posts();
		if ( is_wp_error( $result ) )
			return $result;
		wp_import_cleanup($file['id']);
		do_action('import_done', 'rss');

		echo '<h3>';
		printf(__('All done. <a href="%s">Have fun!</a>', 'rss-importer'), get_option('home'));
		echo '</h3>';
	}


	function dispatch() {
		if (empty ($_GET['step']))
			$step = 0;
		else
			$step = (int) $_GET['step'];

        if (empty ($_GET['keywords']))
            $this->keywords = array();
        else
            $this->keywords = (array) $_GET['keywords'];

		$this->header();

		switch ($step) {
			case 0 :
				$this->greet_kwfile();
				break;
            case 1:
                check_admin_referer('import-upload');
                $result = $this->import_keywords();
                if ( is_wp_error( $result ) )
                    echo $result->get_error_message();
                break;
            case 2:
                check_admin_referer('import-upload');
                $result = $this->import();
                if ( is_wp_error( $result ) )
                    echo $result->get_error_message();
                break;
		}
		$this->footer();
	}

	function XVID_RSS_Import() {
		// Nothing.
	}
}

$xv_rss_import = new XVID_RSS_Import();

register_importer('xv-rss', __('XVid RSS', 'xv-rss-importer'), __('Import posts from an Xvideos RSS feed.', 'xv-rss-importer'), array ($xv_rss_import, 'dispatch'));

} // class_exists( 'WP_Importer' )

function xvid_rss_importer_init() {
    load_plugin_textdomain( 'xv-rss-importer', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
}
add_action( 'init', 'xvid_rss_importer_init' );
