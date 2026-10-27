<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WMUT_Scanner {

	const CACHE_KEY    = 'wmut_scan_data';
	const DATA_VERSION = 13;

	/**
	 * Get scan data (from cache or fresh scan)
	 *
	 * @param bool $force Force fresh scan.
	 * @return array
	 */
	public static function get_data( $force = false ) {
		$data = get_option( self::CACHE_KEY );

		if (
			! $force &&
			is_array( $data ) &&
			! empty( $data['scanned_at'] ) &&
			isset( $data['data_version'] ) &&
			self::DATA_VERSION === (int) $data['data_version']
		) {
			return $data;
		}

		$data = self::scan();
		update_option( self::CACHE_KEY, $data, false );

		return $data;
	}

	/**
	 * Clear cache
	 */
	public static function clear_cache() {
		delete_option( self::CACHE_KEY );
	}

	/**
	 * Invalidate cache conditionally on save_post
	 */
	public static function maybe_clear_cache( $post_id, $post, $update ) {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		self::clear_cache();
	}

	/**
	 * Get list of post types to exclude from scan
	 */
	public static function get_excluded_post_types() {
		$excluded = array(
			'attachment',
			'revision',
			'nav_menu_item',
			'custom_css',
			'customize_changeset',
			'oembed_cache',
			'user_request',
			'wp_template',
			'wp_template_part',
			'wp_global_styles',
			'wp_navigation',
			'wp_font_family',
			'wp_font_face',
			'acf-taxonomy',
			'acf-post-type',
			'acf-ui-options-page',
			'acf-field-group',
			'acf-field',
			'wpcf7_contact_form',
		);

		return (array) apply_filters( 'wmut_excluded_post_types', $excluded );
	}

	/**
	 * Get list of post statuses to scan
	 */
	public static function get_scanned_statuses() {
		$statuses = array( 'publish', 'future', 'draft', 'pending', 'private', 'trash' );
		return (array) apply_filters( 'wmut_scanned_post_statuses', $statuses );
	}

	/**
	 * Check if meta key is known non-media
	 */
	public static function is_non_media_meta_key( $meta_key ) {
		$key = strtolower( (string) $meta_key );

		if ( '_thumbnail_id' === $key ) {
			return true;
		}

		$ignored_prefixes = array(
			'_wp_',
			'_yoast_',
			'_menu_item_',
			'_edit_',
			'_oembed_',
			'lazyblocks_',
			'_acf_',
			'_elementor_controls_',
			'_elementor_css',
			'_elementor_version',
		);
		foreach ( $ignored_prefixes as $prefix ) {
			if ( 0 === strpos( $key, $prefix ) ) {
				return true;
			}
		}

		$ignored_exact = array(
			'post_views_count',
			'views',
			'_views',
			'rank_math_seo_score',
			'footnotes',
			'_additional_settings',
			'_config_validation',
			'_locale',
			'_mail',
			'_mail_2',
			'_messages',
		);
		if ( in_array( $key, $ignored_exact, true ) ) {
			return true;
		}

		$ignored_suffixes = array(
			'_score',
			'_count',
			'_price',
			'_order',
			'_date',
			'_time',
			'_status',
			'_rating',
			'_reading_time',
			'-reading-time',
		);
		foreach ( $ignored_suffixes as $suffix ) {
			$len = strlen( $suffix );
			if ( substr( $key, -$len ) === $suffix ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Clean and decode HTML entities from titles and labels (e.g. &amp; -> &)
	 */
	public static function clean_title( $title ) {
		if ( ! is_string( $title ) ) {
			return '';
		}
		$title = trim( $title );
		if ( '' === $title ) {
			return '';
		}
		$decoded = html_entity_decode( wp_specialchars_decode( $title, ENT_QUOTES ), ENT_QUOTES, 'UTF-8' );
		if ( strpos( $decoded, '&' ) !== false ) {
			$decoded = html_entity_decode( $decoded, ENT_QUOTES, 'UTF-8' );
		}
		return $decoded;
	}

	/**
	 * Check if meta key has media-related keywords
	 */
	public static function is_media_related_meta_key( $meta_key ) {
		$key = strtolower( (string) $meta_key );
		$keywords = array(
			'image',
			'img',
			'thumb',
			'photo',
			'picture',
			'gallery',
			'banner',
			'logo',
			'icon',
			'avatar',
			'poster',
			'media',
			'file',
			'download',
			'pdf',
			'doc',
			'attachment',
			'background',
			'hero',
			'graphic',
		);
		foreach ( $keywords as $kw ) {
			if ( strpos( $key, $kw ) !== false ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Perform full scan
	 *
	 * @return array
	 */
	public static function scan() {
		global $wpdb;

		// 1. Fetch all media attachments
		$attachments_query = "SELECT ID, post_title, post_name, post_mime_type, post_date, post_date_gmt, post_parent, guid
			FROM {$wpdb->posts}
			WHERE post_type = 'attachment'
			ORDER BY ID DESC";

		$raw_attachments = $wpdb->get_results( $attachments_query );

		$media_items    = array();
		$id_by_filename = array();
		$id_by_basename = array();
		$id_by_url_path = array();
		$all_media_ids  = array();

		foreach ( $raw_attachments as $att ) {
			$att_id = (int) $att->ID;
			$all_media_ids[ $att_id ] = true;

			$attached_file = get_attached_file( $att_id );
			$filename      = $attached_file ? basename( $attached_file ) : basename( $att->guid );
			$filesize      = ( $attached_file && file_exists( $attached_file ) ) ? filesize( $attached_file ) : 0;

			$metadata   = wp_get_attachment_metadata( $att_id );
			$dimensions = '';
			if ( is_array( $metadata ) && ! empty( $metadata['width'] ) && ! empty( $metadata['height'] ) ) {
				$dimensions = $metadata['width'] . ' &times; ' . $metadata['height'];
			}

			// Determine MIME group
			$mime = strtolower( (string) $att->post_mime_type );
			if ( 0 === strpos( $mime, 'image/' ) ) {
				$type_group = 'image';
			} elseif ( 0 === strpos( $mime, 'audio/' ) ) {
				$type_group = 'audio';
			} elseif ( 0 === strpos( $mime, 'video/' ) ) {
				$type_group = 'video';
			} elseif ( 'application/pdf' === $mime || 0 === strpos( $mime, 'text/' ) || strpos( $mime, 'document' ) !== false || strpos( $mime, 'msword' ) !== false ) {
				$type_group = 'document';
			} else {
				$type_group = 'other';
			}

			// Thumbnail URL
			$thumb_url = wp_get_attachment_image_url( $att_id, 'thumbnail' );
			if ( ! $thumb_url ) {
				$thumb_url = wp_mime_type_icon( $att_id );
			}

			$full_url = wp_get_attachment_url( $att_id );

			// Title fallback
			$display_title = self::clean_title( $att->post_title );
			if ( '' === $display_title ) {
				$display_title = $filename;
			}

			$media_items[ $att_id ] = array(
				'id'                 => $att_id,
				'title'              => $display_title,
				'filename'           => $filename,
				'mime_type'          => $att->post_mime_type,
				'type_group'         => $type_group,
				'upload_date'        => $att->post_date,
				'upload_formatted'   => get_date_from_gmt( $att->post_date_gmt ? $att->post_date_gmt : $att->post_date, 'M j, Y' ),
				'filesize'           => $filesize,
				'filesize_formatted' => size_format( $filesize, 1 ),
				'dimensions'         => $dimensions,
				'thumb_url'          => $thumb_url,
				'full_url'           => $full_url ? $full_url : '',
				'edit_url'           => admin_url( 'post.php?post=' . $att_id . '&action=edit' ),
				'parent_id'          => (int) $att->post_parent,
				'usages'             => array(),
				'usage_count'        => 0,
				'distinct_posts'     => 0,
			);

			// Populate lookup indexes
			if ( $filename ) {
				$id_by_filename[ strtolower( $filename ) ][] = $att_id;

				$basename_no_ext = pathinfo( $filename, PATHINFO_FILENAME );
				if ( $basename_no_ext ) {
					$id_by_basename[ strtolower( $basename_no_ext ) ][] = $att_id;
				}
			}

			if ( $attached_file ) {
				$clean_path = str_replace( '\\', '/', $attached_file );
				$upload_dir = wp_upload_dir();
				$rel_path   = ltrim( str_replace( str_replace( '\\', '/', $upload_dir['basedir'] ), '', $clean_path ), '/' );
				if ( $rel_path ) {
					$id_by_url_path[ strtolower( $rel_path ) ][] = $att_id;
				}
			}
		}

		// 2. Fetch all posts to scan
		$excluded_types   = self::get_excluded_post_types();
		$scanned_statuses = self::get_scanned_statuses();

		$all_post_types = get_post_types( array(), 'names' );
		$target_types   = array_values( array_diff( $all_post_types, $excluded_types ) );

		if ( empty( $target_types ) || empty( $media_items ) ) {
			return self::format_result( $media_items, $target_types, $scanned_statuses );
		}

		$post_type_placeholders = implode( ', ', array_fill( 0, count( $target_types ), '%s' ) );
		$status_placeholders    = implode( ', ', array_fill( 0, count( $scanned_statuses ), '%s' ) );

		$query_params = array_merge( $target_types, $scanned_statuses );

		$posts_sql = "SELECT ID, post_title, post_type, post_status, post_content, post_parent
			FROM {$wpdb->posts}
			WHERE post_type IN ({$post_type_placeholders})
			AND post_status IN ({$status_placeholders})";

		$posts = $wpdb->get_results( $wpdb->prepare( $posts_sql, $query_params ) );

		// Map post details
		$posts_map       = array();
		$target_post_ids = array();

		if ( ! empty( $posts ) ) {
			foreach ( $posts as $post ) {
				$p_id = (int) $post->ID;
				$target_post_ids[] = $p_id;

				$type_obj   = get_post_type_object( $post->post_type );
				$type_label = $type_obj ? $type_obj->labels->singular_name : ucfirst( $post->post_type );

				$status_labels = array(
					'publish' => __( 'Published', 'wp-media-usage-tracker' ),
					'draft'   => __( 'Draft', 'wp-media-usage-tracker' ),
					'trash'   => __( 'Trash', 'wp-media-usage-tracker' ),
					'future'  => __( 'Scheduled', 'wp-media-usage-tracker' ),
					'pending' => __( 'Pending', 'wp-media-usage-tracker' ),
					'private' => __( 'Private', 'wp-media-usage-tracker' ),
				);
				$status_label = isset( $status_labels[ $post->post_status ] ) ? $status_labels[ $post->post_status ] : ucfirst( $post->post_status );

				$title = self::clean_title( $post->post_title );
				if ( '' === $title ) {
					$title = sprintf( __( '(no title #%d)', 'wp-media-usage-tracker' ), $p_id );
				}

				$posts_map[ $p_id ] = array(
					'id'           => $p_id,
					'title'        => $title,
					'post_type'    => $post->post_type,
					'type_label'   => $type_label,
					'post_status'  => $post->post_status,
					'status_label' => $status_label,
					'edit_url'     => admin_url( 'post.php?post=' . $p_id . '&action=edit' ),
					'view_url'     => ( 'trash' !== $post->post_status ) ? get_permalink( $p_id ) : '',
				);
			}

			// 3. Scan Featured Images (_thumbnail_id)
			$post_id_chunks = array_chunk( $target_post_ids, 500 );
			foreach ( $post_id_chunks as $chunk ) {
				$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
				$thumb_sql    = "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_thumbnail_id' AND post_id IN ({$placeholders})";
				$thumb_rows   = $wpdb->get_results( $wpdb->prepare( $thumb_sql, $chunk ) );

				foreach ( $thumb_rows as $row ) {
					$thumb_att_id  = (int) $row->meta_value;
					$thumb_post_id = (int) $row->post_id;
					if ( isset( $media_items[ $thumb_att_id ] ) && isset( $posts_map[ $thumb_post_id ] ) ) {
						self::record_usage( $media_items, $thumb_att_id, $posts_map[ $thumb_post_id ], __( 'Featured Image', 'wp-media-usage-tracker' ) );
					}
				}
			}

			// 4. Scan Post Content (Gutenberg blocks, LazyBlocks, Shortcodes, <img> tags, direct URLs)
			foreach ( $posts as $post ) {
				$content = $post->post_content;
				if ( empty( $content ) ) {
					continue;
				}

				$p_id = (int) $post->ID;
				if ( ! isset( $posts_map[ $p_id ] ) ) {
					continue;
				}
				$post_meta_info = $posts_map[ $p_id ];

				// Track which media IDs are detected in this post's content via structured blocks/tags
				// so the fallback URL scanner does NOT create redundant "Direct Media Link" duplicates
				$post_detected_ids = array();

				// Gutenberg Standard Media Blocks
				if ( preg_match_all( '/<!--\s+wp:image\s+\{.*?"id":\s*(\d+)/s', $content, $matches ) ) {
					foreach ( $matches[1] as $matched_id ) {
						$matched_id = (int) $matched_id;
						if ( isset( $media_items[ $matched_id ] ) ) {
							$post_detected_ids[ $matched_id ] = true;
							self::record_usage( $media_items, $matched_id, $post_meta_info, __( 'Content: Image Block', 'wp-media-usage-tracker' ) );
						}
					}
				}

				if ( preg_match_all( '/<!--\s+wp:cover\s+\{.*?"id":\s*(\d+)/s', $content, $matches ) ) {
					foreach ( $matches[1] as $matched_id ) {
						$matched_id = (int) $matched_id;
						if ( isset( $media_items[ $matched_id ] ) ) {
							$post_detected_ids[ $matched_id ] = true;
							self::record_usage( $media_items, $matched_id, $post_meta_info, __( 'Content: Cover Block', 'wp-media-usage-tracker' ) );
						}
					}
				}

				if ( preg_match_all( '/<!--\s+wp:media-text\s+\{.*?"mediaId":\s*(\d+)/s', $content, $matches ) ) {
					foreach ( $matches[1] as $matched_id ) {
						$matched_id = (int) $matched_id;
						if ( isset( $media_items[ $matched_id ] ) ) {
							$post_detected_ids[ $matched_id ] = true;
							self::record_usage( $media_items, $matched_id, $post_meta_info, __( 'Content: Media & Text Block', 'wp-media-usage-tracker' ) );
						}
					}
				}

				if ( preg_match_all( '/<!--\s+wp:gallery\s+\{.*?"ids":\s*\[([0-9,\s]+)\]/s', $content, $matches ) ) {
					foreach ( $matches[1] as $ids_str ) {
						$ids = explode( ',', $ids_str );
						foreach ( $ids as $g_id ) {
							$g_id = (int) trim( $g_id );
							if ( isset( $media_items[ $g_id ] ) ) {
								$post_detected_ids[ $g_id ] = true;
								self::record_usage( $media_items, $g_id, $post_meta_info, __( 'Content: Gallery Block', 'wp-media-usage-tracker' ) );
							}
						}
					}
				}

				if ( preg_match_all( '/<!--\s+wp:(audio|video|file)\s+\{.*?"id":\s*(\d+)/s', $content, $matches ) ) {
					foreach ( $matches[2] as $matched_id ) {
						$matched_id = (int) $matched_id;
						if ( isset( $media_items[ $matched_id ] ) ) {
							$post_detected_ids[ $matched_id ] = true;
							self::record_usage( $media_items, $matched_id, $post_meta_info, __( 'Content: Media Block', 'wp-media-usage-tracker' ) );
						}
					}
				}

				// LazyBlocks Blocks Scanning (Distinguishes between Direct Field and Repeater)
				if ( strpos( $content, 'wp:lazyblock/' ) !== false && function_exists( 'parse_blocks' ) ) {
					$parsed_blocks = parse_blocks( $content );
					self::scan_lazyblocks_blocks( $parsed_blocks, $media_items, $id_by_filename, $post_detected_ids, $post_meta_info );
				}

				// Classic Editor wp-image-{ID} (Only for images not already captured by Gutenberg/LazyBlocks blocks)
				if ( preg_match_all( '/wp-image-(\d+)/', $content, $matches ) ) {
					foreach ( $matches[1] as $matched_id ) {
						$matched_id = (int) $matched_id;
						if ( isset( $post_detected_ids[ $matched_id ] ) ) {
							continue;
						}
						if ( isset( $media_items[ $matched_id ] ) ) {
							$post_detected_ids[ $matched_id ] = true;
							self::record_usage( $media_items, $matched_id, $post_meta_info, __( 'Content: <img> Tag', 'wp-media-usage-tracker' ) );
						}
					}
				}

				// Classic Shortcode [gallery ids="1,2,3"]
				if ( preg_match_all( '/\[gallery[^\]]*ids=["\']([0-9,\s]+)["\']/i', $content, $matches ) ) {
					foreach ( $matches[1] as $ids_str ) {
						$ids = explode( ',', $ids_str );
						foreach ( $ids as $g_id ) {
							$g_id = (int) trim( $g_id );
							if ( isset( $post_detected_ids[ $g_id ] ) ) {
								continue;
							}
							if ( isset( $media_items[ $g_id ] ) ) {
								$post_detected_ids[ $g_id ] = true;
								self::record_usage( $media_items, $g_id, $post_meta_info, __( 'Content: [gallery] Shortcode', 'wp-media-usage-tracker' ) );
							}
						}
					}
				}

				// Fallback URL & Filename scan for raw inline images/links (skips items already detected above to prevent duplicate counts)
				self::check_string_for_media( $content, $media_items, $id_by_filename, $post_meta_info, __( 'Content: Direct Media Link', 'wp-media-usage-tracker' ), $post_detected_ids );
			}

			// 5. Scan Post Meta / Custom Fields (ACF, SCF, WooCommerce, Custom Meta)
			foreach ( $post_id_chunks as $chunk ) {
				$placeholders = implode( ', ', array_fill( 0, count( $chunk ), '%d' ) );
				$meta_sql     = "SELECT post_id, meta_key, meta_value FROM {$wpdb->postmeta}
					WHERE post_id IN ({$placeholders})
					AND meta_value != ''";

				$meta_rows = $wpdb->get_results( $wpdb->prepare( $meta_sql, $chunk ) );

				foreach ( $meta_rows as $row ) {
					$meta_key = $row->meta_key;
					if ( self::is_non_media_meta_key( $meta_key ) ) {
						continue;
					}

					$p_id = (int) $row->post_id;
					if ( ! isset( $posts_map[ $p_id ] ) ) {
						continue;
					}
					$post_meta_info = $posts_map[ $p_id ];
					$meta_val       = $row->meta_value;

					// Case 1: Numeric ID matching attachment
					if ( is_numeric( $meta_val ) ) {
						$cand_id = (int) $meta_val;
						if ( isset( $media_items[ $cand_id ] ) ) {
							if ( self::is_media_related_meta_key( $meta_key ) || '_' !== substr( $meta_key, 0, 1 ) ) {
								self::record_usage( $media_items, $cand_id, $post_meta_info, sprintf( __( 'Custom Field: %s', 'wp-media-usage-tracker' ), $meta_key ) );
							}
						}
						continue;
					}

					// Case 2: WooCommerce product image gallery or comma separated IDs
					if ( '_product_image_gallery' === $meta_key || ( self::is_media_related_meta_key( $meta_key ) && strpos( $meta_val, ',' ) !== false ) ) {
						$candidate_ids = explode( ',', $meta_val );
						$has_valid_id  = false;
						foreach ( $candidate_ids as $c_id ) {
							$c_id = (int) trim( $c_id );
							if ( $c_id > 0 && isset( $media_items[ $c_id ] ) ) {
								$has_valid_id = true;
								$label        = ( '_product_image_gallery' === $meta_key )
									? __( 'WooCommerce Product Gallery', 'wp-media-usage-tracker' )
									: sprintf( __( 'Custom Field: %s', 'wp-media-usage-tracker' ), $meta_key );
								self::record_usage( $media_items, $c_id, $post_meta_info, $label );
							}
						}
						if ( $has_valid_id ) {
							continue;
						}
					}

					// Case 3: Serialized array (ACF image array, galleries, or repeater)
					if ( is_serialized( $meta_val ) ) {
						$unserialized = @unserialize( $meta_val );
						if ( is_array( $unserialized ) ) {
							self::scan_array_for_media_ids( $unserialized, $media_items, $post_meta_info, sprintf( __( 'Custom Field: %s', 'wp-media-usage-tracker' ), $meta_key ) );
						}
						continue;
					}

					// Case 4: JSON formatted field
					if ( ( 0 === strpos( $meta_val, '{' ) || 0 === strpos( $meta_val, '[' ) ) ) {
						$decoded = json_decode( $meta_val, true );
						if ( is_array( $decoded ) ) {
							self::scan_array_for_media_ids( $decoded, $media_items, $post_meta_info, sprintf( __( 'Custom Field: %s', 'wp-media-usage-tracker' ), $meta_key ) );
						}
					}
				}
			}
		}

		// 5.5. Scan Navigation Menu Items (ACF / SCF on Menu Items)
		self::scan_nav_menu_items( $media_items, $all_media_ids, $id_by_filename );

		// 5.6. Scan Taxonomy Terms (ACF / SCF on Categories, Tags, Taxonomies)
		self::scan_taxonomy_terms( $media_items, $all_media_ids, $id_by_filename );

		// 6. Scan ALL Theme Customizer Mods (get_theme_mods: footer_logo, custom_logo, header_image, etc.)
		$theme_mods = get_theme_mods();
		if ( is_array( $theme_mods ) ) {
			$customizer_info = array(
				'id'           => 0,
				'title'        => __( 'Theme Customizer', 'wp-media-usage-tracker' ),
				'post_type'    => 'theme_mod',
				'type_label'   => __( 'Theme Setting', 'wp-media-usage-tracker' ),
				'post_status'  => 'active',
				'status_label' => __( 'Active', 'wp-media-usage-tracker' ),
				'edit_url'     => admin_url( 'customize.php' ),
				'view_url'     => home_url( '/' ),
			);

			foreach ( $theme_mods as $mod_key => $mod_val ) {
				if ( empty( $mod_val ) || 'custom_css_post_id' === $mod_key || 'nav_menu_locations' === $mod_key || '0' === (string) $mod_key ) {
					continue;
				}

				$context_label = sprintf( __( 'Theme Customizer: %s', 'wp-media-usage-tracker' ), $mod_key );

				// Numeric ID (e.g. custom_logo => 8)
				if ( is_numeric( $mod_val ) ) {
					$cand_id = (int) $mod_val;
					if ( isset( $media_items[ $cand_id ] ) ) {
						self::record_usage( $media_items, $cand_id, $customizer_info, $context_label );
					}
					continue;
				}

				// String (e.g. footer_logo => 'http://.../sni-logo-white.svg')
				if ( is_string( $mod_val ) ) {
					self::check_string_for_media( $mod_val, $media_items, $id_by_filename, $customizer_info, $context_label );
					continue;
				}

				// Array
				if ( is_array( $mod_val ) ) {
					self::scan_array_for_media_ids( $mod_val, $media_items, $customizer_info, $context_label );
				}
			}
		}

		// 7. Scan Global Options & ACF Options Pages (wp_options)
		$options_sql = "SELECT option_name, option_value FROM {$wpdb->options}
			WHERE option_name NOT LIKE '_transient_%'
			AND option_name NOT LIKE '%_transient_%'
			AND option_name NOT LIKE 'wmut_%'
			AND option_name NOT LIKE 'wplt_%'
			AND option_name NOT LIKE 'theme_mods_%'
			AND option_name NOT IN ('cron', 'rewrite_rules', 'active_plugins', 'recently_activated', 'auto_updater.lock', 'core_updater.lock', 'can_compress_scripts', 'uninstall_plugins')
			AND option_value != ''";

		$raw_options = $wpdb->get_results( $options_sql );

		if ( ! empty( $raw_options ) ) {
			$site_options_info = array(
				'id'           => 0,
				'title'        => __( 'Site Options', 'wp-media-usage-tracker' ),
				'post_type'    => 'site_option',
				'type_label'   => __( 'Site Option', 'wp-media-usage-tracker' ),
				'post_status'  => 'active',
				'status_label' => __( 'Active', 'wp-media-usage-tracker' ),
				'edit_url'     => admin_url( 'options-general.php' ),
				'view_url'     => home_url( '/' ),
			);

			foreach ( $raw_options as $opt_row ) {
				$opt_name = $opt_row->option_name;
				$opt_val  = $opt_row->option_value;

				// Site Icon
				if ( 'site_icon' === $opt_name ) {
					$icon_id = (int) $opt_val;
					if ( isset( $media_items[ $icon_id ] ) ) {
						$icon_info = array(
							'id'           => 0,
							'title'        => __( 'Site Identity Settings', 'wp-media-usage-tracker' ),
							'post_type'    => 'site_option',
							'type_label'   => __( 'Site Setting', 'wp-media-usage-tracker' ),
							'post_status'  => 'active',
							'status_label' => __( 'Active', 'wp-media-usage-tracker' ),
							'edit_url'     => admin_url( 'options-general.php' ),
							'view_url'     => home_url( '/' ),
						);
						self::record_usage( $media_items, $icon_id, $icon_info, __( 'Site Icon (Favicon)', 'wp-media-usage-tracker' ) );
					}
					continue;
				}

				// ACF Options (options_...)
				if ( 0 === strpos( $opt_name, 'options_' ) ) {
					$clean_field = substr( $opt_name, 8 );
					// Skip ACF field key references like options__cta_image (field_...)
					if ( 0 === strpos( $clean_field, '_' ) ) {
						continue;
					}

					$context_label = sprintf( __( 'Theme Option: %s', 'wp-media-usage-tracker' ), $clean_field );

					$acf_opt_info = array(
						'id'           => 0,
						'title'        => sprintf( __( 'Theme Options (%s)', 'wp-media-usage-tracker' ), $clean_field ),
						'post_type'    => 'acf_option',
						'type_label'   => __( 'Theme Option', 'wp-media-usage-tracker' ),
						'post_status'  => 'active',
						'status_label' => __( 'Active', 'wp-media-usage-tracker' ),
						'edit_url'     => admin_url( 'admin.php?page=acf-options' ),
						'view_url'     => home_url( '/' ),
					);

					if ( is_numeric( $opt_val ) ) {
						$cand_id = (int) $opt_val;
						if ( isset( $media_items[ $cand_id ] ) ) {
							if ( self::is_media_related_meta_key( $clean_field ) || ! self::is_non_media_meta_key( $clean_field ) ) {
								self::record_usage( $media_items, $cand_id, $acf_opt_info, $context_label );
							}
						}
					} elseif ( is_serialized( $opt_val ) ) {
						$unserialized = @unserialize( $opt_val );
						if ( is_array( $unserialized ) ) {
							self::scan_array_for_media_ids( $unserialized, $media_items, $acf_opt_info, $context_label );
						}
					} elseif ( is_string( $opt_val ) && strpos( $opt_val, '/wp-content/uploads/' ) !== false ) {
						self::check_string_for_media( $opt_val, $media_items, $id_by_filename, $acf_opt_info, $context_label );
					}
					continue;
				}

				// Other options containing upload URLs or serialized image widgets
				if ( is_string( $opt_val ) && strpos( $opt_val, '/wp-content/uploads/' ) !== false ) {
					$context_label = sprintf( __( 'Site Option: %s', 'wp-media-usage-tracker' ), $opt_name );
					self::check_string_for_media( $opt_val, $media_items, $id_by_filename, $site_options_info, $context_label );
				} elseif ( is_serialized( $opt_val ) && 0 === strpos( $opt_name, 'widget_' ) ) {
					$unserialized = @unserialize( $opt_val );
					if ( is_array( $unserialized ) ) {
						$widget_info = array(
							'id'           => 0,
							'title'        => sprintf( __( 'Widget (%s)', 'wp-media-usage-tracker' ), $opt_name ),
							'post_type'    => 'widget',
							'type_label'   => __( 'Widget', 'wp-media-usage-tracker' ),
							'post_status'  => 'active',
							'status_label' => __( 'Active', 'wp-media-usage-tracker' ),
							'edit_url'     => admin_url( 'widgets.php' ),
							'view_url'     => home_url( '/' ),
						);
						self::scan_array_for_media_ids( $unserialized, $media_items, $widget_info, sprintf( __( 'Widget: %s', 'wp-media-usage-tracker' ), $opt_name ) );
					}
				}
			}
		}

		// 8. Scan Active Theme Files (Templates, functions.php, custom templates, CSS, JS)
		self::scan_theme_files( $media_items, $all_media_ids, $id_by_filename );

		return self::format_result( $media_items, $target_types, $scanned_statuses );
	}

	/**
	 * Scan active theme files (templates, CSS, JS, etc.) for direct media references
	 */
	private static function scan_theme_files( &$media_items, $all_media_ids, $id_by_filename ) {
		$theme_dirs = array();

		// Active stylesheet directory (child theme if active, or parent theme)
		$sheet_dir = get_stylesheet_directory();
		if ( ! empty( $sheet_dir ) && is_dir( $sheet_dir ) ) {
			$theme_dirs[ $sheet_dir ] = wp_get_theme();
		}

		// Parent template directory (if different from stylesheet directory)
		$tmpl_dir = get_template_directory();
		if ( ! empty( $tmpl_dir ) && is_dir( $tmpl_dir ) && ! isset( $theme_dirs[ $tmpl_dir ] ) ) {
			$theme_dirs[ $tmpl_dir ] = wp_get_theme( get_template() );
		}

		if ( empty( $theme_dirs ) ) {
			return;
		}

		$allowed_exts = array( 'php' => true, 'css' => true, 'js' => true, 'html' => true, 'inc' => true );
		$ignored_dirs = array( 'node_modules', 'vendor', '.git', '.svn', '.idea', '.vscode' );

		foreach ( $theme_dirs as $theme_path => $theme_obj ) {
			$theme_slug = $theme_obj->get_stylesheet();
			$theme_name = self::clean_title( $theme_obj->get( 'Name' ) );

			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator( $theme_path, RecursiveDirectoryIterator::SKIP_DOTS ),
					RecursiveIteratorIterator::LEAVES_ONLY
				);
			} catch ( Exception $e ) {
				continue;
			}

			foreach ( $iterator as $file ) {
				$sub_path = $iterator->getSubPath();
				$skip     = false;
				foreach ( $ignored_dirs as $ign ) {
					if ( $sub_path === $ign || strpos( $sub_path, $ign . DIRECTORY_SEPARATOR ) === 0 || strpos( $sub_path, DIRECTORY_SEPARATOR . $ign ) !== false ) {
						$skip = true;
						break;
					}
				}
				if ( $skip ) {
					continue;
				}

				$ext = strtolower( pathinfo( $file->getFilename(), PATHINFO_EXTENSION ) );
				if ( ! isset( $allowed_exts[ $ext ] ) ) {
					continue;
				}

				// Skip large files (> 1MB)
				if ( $file->getSize() > 1048576 ) {
					continue;
				}

				$real_path = $file->getRealPath();
				$content   = @file_get_contents( $real_path );
				if ( empty( $content ) ) {
					continue;
				}

				$rel_file = substr( $real_path, strlen( $theme_path ) + 1 );
				$rel_file = str_replace( '\\', '/', $rel_file );

				$theme_file_info = array(
					'id'           => 'theme_' . md5( $theme_slug . '/' . $rel_file ),
					'title'        => $theme_name . " \u{203A} " . $rel_file,
					'post_type'    => 'theme_file',
					'type_label'   => __( 'Theme File', 'wp-media-usage-tracker' ),
					'post_status'  => 'active',
					'status_label' => __( 'Active Theme', 'wp-media-usage-tracker' ),
					'edit_url'     => admin_url( 'theme-editor.php?file=' . rawurlencode( $rel_file ) . '&theme=' . rawurlencode( $theme_slug ) ),
					'view_url'     => '',
				);

				$context = sprintf( __( 'Theme File: %s', 'wp-media-usage-tracker' ), $rel_file );

				// 1. Scan for CSS / style url(...) references (e.g. background-image: url(../images/banner-1.jpg))
				if ( preg_match_all( '/url\s*\(\s*[\'"]?([^\'")]+)[\'"]?\s*\)/i', $content, $url_matches ) ) {
					foreach ( $url_matches[1] as $raw_url ) {
						$clean_url = trim( $raw_url );
						$clean_url = preg_replace( '/[\?#].*$/', '', $clean_url );
						$found_basename = strtolower( basename( $clean_url ) );
						if ( empty( $found_basename ) ) {
							continue;
						}

						if ( isset( $id_by_filename[ $found_basename ] ) ) {
							foreach ( $id_by_filename[ $found_basename ] as $matched_id ) {
								self::record_usage( $media_items, $matched_id, $theme_file_info, $context );
							}
							continue;
						}

						$stripped = preg_replace( '/-(\d+)x(\d+)(\.[a-zA-Z0-9]+)$/', '$3', $found_basename );
						if ( isset( $id_by_filename[ $stripped ] ) ) {
							foreach ( $id_by_filename[ $stripped ] as $matched_id ) {
								self::record_usage( $media_items, $matched_id, $theme_file_info, $context );
							}
						}
					}
				}

				// 2. Scan for HTML / template image attributes (src, data-src, data-bg, srcset)
				if ( preg_match_all( '/(?:src|data-src|data-bg|srcset)\s*=\s*[\'"]([^\'"]+)[\'"]/i', $content, $attr_matches ) ) {
					foreach ( $attr_matches[1] as $raw_val ) {
						$parts = explode( ',', $raw_val );
						foreach ( $parts as $part ) {
							$url_part = preg_split( '/\s+/', trim( $part ) )[0];
							$clean_url = preg_replace( '/[\?#].*$/', '', $url_part );
							$found_basename = strtolower( basename( $clean_url ) );
							if ( empty( $found_basename ) ) {
								continue;
							}

							if ( isset( $id_by_filename[ $found_basename ] ) ) {
								foreach ( $id_by_filename[ $found_basename ] as $matched_id ) {
									self::record_usage( $media_items, $matched_id, $theme_file_info, $context );
								}
								continue;
							}

							$stripped = preg_replace( '/-(\d+)x(\d+)(\.[a-zA-Z0-9]+)$/', '$3', $found_basename );
							if ( isset( $id_by_filename[ $stripped ] ) ) {
								foreach ( $id_by_filename[ $stripped ] as $matched_id ) {
									self::record_usage( $media_items, $matched_id, $theme_file_info, $context );
								}
							}
						}
					}
				}

				// 3. Scan for quoted strings ending in media extensions (JS, PHP variables, inline configs)
				if ( preg_match_all( '/[\'"]([^\'"\r\n]+\.(?:jpe?g|png|gif|webp|svg|pdf|mp4|webm))[\'"]/i', $content, $q_matches ) ) {
					foreach ( $q_matches[1] as $raw_q ) {
						$clean_url = preg_replace( '/[\?#].*$/', '', trim( $raw_q ) );
						$found_basename = strtolower( basename( $clean_url ) );
						if ( empty( $found_basename ) ) {
							continue;
						}

						if ( isset( $id_by_filename[ $found_basename ] ) ) {
							foreach ( $id_by_filename[ $found_basename ] as $matched_id ) {
								self::record_usage( $media_items, $matched_id, $theme_file_info, $context );
							}
							continue;
						}

						$stripped = preg_replace( '/-(\d+)x(\d+)(\.[a-zA-Z0-9]+)$/', '$3', $found_basename );
						if ( isset( $id_by_filename[ $stripped ] ) ) {
							foreach ( $id_by_filename[ $stripped ] as $matched_id ) {
								self::record_usage( $media_items, $matched_id, $theme_file_info, $context );
							}
						}
					}
				}

				// 4. Scan for upload URLs (/wp-content/uploads/...)
				if ( strpos( $content, '/wp-content/uploads/' ) !== false ) {
					if ( preg_match_all( '/\/wp-content\/uploads\/([0-9]{4}\/[0-9]{2}\/)?([a-zA-Z0-9_\-\.\%]+)/i', $content, $matches ) ) {
						foreach ( $matches[2] as $found_file ) {
							$found_basename = strtolower( basename( $found_file ) );
							if ( isset( $id_by_filename[ $found_basename ] ) ) {
								foreach ( $id_by_filename[ $found_basename ] as $matched_id ) {
									self::record_usage( $media_items, $matched_id, $theme_file_info, $context );
								}
								continue;
							}

							$stripped = preg_replace( '/-(\\d+)x(\\d+)(\\.[a-zA-Z0-9]+)$/', '$3', $found_basename );
							if ( isset( $id_by_filename[ $stripped ] ) ) {
								foreach ( $id_by_filename[ $stripped ] as $matched_id ) {
									self::record_usage( $media_items, $matched_id, $theme_file_info, $context );
								}
							}
						}
					}
				}

				// 5. Scan for direct PHP attachment functions referencing IDs
				if ( 'php' === $ext && preg_match_all( '/\b(wp_get_attachment_image|wp_get_attachment_url|wp_get_attachment_image_url|wp_get_attachment_image_src|image_render)\s*\(\s*(\d+)/i', $content, $fn_matches ) ) {
					foreach ( $fn_matches[2] as $idx => $cand_id ) {
						$cand_id = (int) $cand_id;
						if ( isset( $media_items[ $cand_id ] ) ) {
							$fn_name = $fn_matches[1][ $idx ];
							$fn_context = sprintf( __( 'Theme File: %1$s (%2$s)', 'wp-media-usage-tracker' ), $rel_file, $fn_name );
							self::record_usage( $media_items, $cand_id, $theme_file_info, $fn_context );
						}
					}
				}
			}
		}
	}

	/**
	 * Helper to record usage for a media item
	 */
	private static function record_usage( &$media_items, $att_id, $post_info, $context ) {
		if ( ! isset( $media_items[ $att_id ] ) ) {
			return;
		}

		$post_id = $post_info['id'];

		$found_idx = -1;
		foreach ( $media_items[ $att_id ]['usages'] as $idx => $usage ) {
			if ( $usage['id'] === $post_id && $usage['post_type'] === $post_info['post_type'] ) {
				$found_idx = $idx;
				break;
			}
		}

		if ( $found_idx >= 0 ) {
			if ( ! in_array( $context, $media_items[ $att_id ]['usages'][ $found_idx ]['contexts'], true ) ) {
				$media_items[ $att_id ]['usages'][ $found_idx ]['contexts'][] = $context;
				$media_items[ $att_id ]['usage_count']++;
			}
		} else {
			$media_items[ $att_id ]['usages'][] = array(
				'id'           => $post_id,
				'title'        => $post_info['title'],
				'post_type'    => $post_info['post_type'],
				'type_label'   => $post_info['type_label'],
				'post_status'  => $post_info['post_status'],
				'status_label' => $post_info['status_label'],
				'contexts'     => array( $context ),
				'edit_url'     => $post_info['edit_url'],
				'view_url'     => $post_info['view_url'],
			);
			$media_items[ $att_id ]['usage_count']++;
			$media_items[ $att_id ]['distinct_posts']++;
		}
	}

	/**
	 * Check a string (URL, HTML or content) for media file references
	 */
	private static function check_string_for_media( $str, &$media_items, $id_by_filename, $post_info, $context, $exclude_ids = array() ) {
		if ( ! is_string( $str ) || empty( $str ) ) {
			return;
		}

		// If string contains percent-encoded data (e.g. LazyBlocks, encoded URLs), decode and scan too
		if ( strpos( $str, '%' ) !== false ) {
			$decoded_str = rawurldecode( $str );
			if ( $decoded_str !== $str ) {
				self::check_string_for_media( $decoded_str, $media_items, $id_by_filename, $post_info, $context, $exclude_ids );
			}
		}

		// Try extracting filename directly if string is a single URL or file path
		$clean_url   = strtok( $str, '?#' );
		$filename    = basename( $clean_url );
		$exact_lower = strtolower( $filename );

		if ( isset( $id_by_filename[ $exact_lower ] ) ) {
			foreach ( $id_by_filename[ $exact_lower ] as $matched_id ) {
				if ( ! empty( $exclude_ids[ $matched_id ] ) ) {
					continue;
				}
				self::record_usage( $media_items, $matched_id, $post_info, $context );
			}
			return;
		}

		// Strip thumbnail dimensions (e.g. image-768x512.jpg -> image.jpg)
		$stripped_file = preg_replace( '/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $exact_lower );
		if ( isset( $id_by_filename[ $stripped_file ] ) ) {
			foreach ( $id_by_filename[ $stripped_file ] as $matched_id ) {
				if ( ! empty( $exclude_ids[ $matched_id ] ) ) {
					continue;
				}
				self::record_usage( $media_items, $matched_id, $post_info, $context );
			}
			return;
		}

		// Search for multiple upload URLs in HTML / block content
		if ( preg_match_all( '/\/wp-content\/uploads\/([0-9]{4}\/[0-9]{2}\/)?([a-zA-Z0-9_\-\.%]+)/i', $str, $matches ) ) {
			foreach ( $matches[2] as $found_file ) {
				$found_basename = strtolower( basename( $found_file ) );
				if ( isset( $id_by_filename[ $found_basename ] ) ) {
					foreach ( $id_by_filename[ $found_basename ] as $matched_id ) {
						if ( ! empty( $exclude_ids[ $matched_id ] ) ) {
							continue;
						}
						self::record_usage( $media_items, $matched_id, $post_info, $context );
					}
					continue;
				}

				$stripped = preg_replace( '/-\d+x\d+(\.[a-zA-Z0-9]+)$/', '$1', $found_basename );
				if ( isset( $id_by_filename[ $stripped ] ) ) {
					foreach ( $id_by_filename[ $stripped ] as $matched_id ) {
						if ( ! empty( $exclude_ids[ $matched_id ] ) ) {
							continue;
						}
						self::record_usage( $media_items, $matched_id, $post_info, $context );
					}
				}
			}
		}
	}

	/**
	 * Recursively scan arrays/objects for attachment IDs
	 */
	
	/**
	 * Recursively scan parsed LazyBlocks blocks for direct vs repeater image fields
	 */
	
	/**
	 * Scan Navigation Menu Items (ACF / Secure Custom Fields on Menus)
	 */
	private static function scan_nav_menu_items( &$media_items, $all_media_ids, $id_by_filename ) {
		global $wpdb;

		$menu_rows = $wpdb->get_results(
			"SELECT p.ID as item_id, pm.meta_key, pm.meta_value 
			FROM {$wpdb->posts} p 
			JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id 
			WHERE p.post_type = 'nav_menu_item' 
			AND pm.meta_key NOT LIKE '_menu_item_%'
			AND pm.meta_key NOT LIKE '_wp_%'
			AND pm.meta_key NOT LIKE '_yoast_%'
			AND pm.meta_value != ''"
		);

		if ( empty( $menu_rows ) ) {
			return;
		}

		$menu_items_cache = array();

		foreach ( $menu_rows as $row ) {
			$meta_key = $row->meta_key;
			if ( self::is_non_media_meta_key( $meta_key ) ) {
				continue;
			}

			$item_id  = (int) $row->item_id;
			$meta_val = $row->meta_value;

			if ( ! isset( $menu_items_cache[ $item_id ] ) ) {
				$p     = get_post( $item_id );
				$meta  = get_post_meta( $item_id );
				$terms = wp_get_post_terms( $item_id, 'nav_menu' );

				$menu_name = ! empty( $terms[0] ) ? self::clean_title( $terms[0]->name ) : __( 'Navigation Menu', 'wp-media-usage-tracker' );
				$menu_id   = ! empty( $terms[0] ) ? $terms[0]->term_id : 0;

				$label = $p ? self::clean_title( $p->post_title ) : '';
				if ( empty( $label ) ) {
					if ( ! empty( $meta['_menu_item_title'][0] ) ) {
						$label = self::clean_title( $meta['_menu_item_title'][0] );
					} elseif ( ! empty( $meta['_menu_item_object_id'][0] ) ) {
						$target_post = get_post( $meta['_menu_item_object_id'][0] );
						if ( $target_post ) {
							$label = self::clean_title( $target_post->post_title );
						}
					}
				}

				if ( empty( $label ) ) {
					$label = sprintf( __( 'Item #%d', 'wp-media-usage-tracker' ), $item_id );
				}

				$menu_display  = ( stripos( $menu_name, 'menu' ) === false ) ? ( $menu_name . ' Menu' ) : $menu_name;
				$display_title = $menu_display . " \u{203A} " . $label;

				$edit_url = admin_url( 'nav-menus.php?action=edit&menu=' . $menu_id );
				$view_url = ! empty( $meta['_menu_item_url'][0] ) ? $meta['_menu_item_url'][0] : '';
				if ( empty( $view_url ) && ! empty( $meta['_menu_item_object_id'][0] ) ) {
					$view_url = get_permalink( $meta['_menu_item_object_id'][0] );
				}

				$menu_items_cache[ $item_id ] = array(
					'id'           => $item_id,
					'title'        => $display_title,
					'post_type'    => 'nav_menu_item',
					'type_label'   => __( 'Menu Item', 'wp-media-usage-tracker' ),
					'post_status'  => 'publish',
					'status_label' => __( 'Published', 'wp-media-usage-tracker' ),
					'edit_url'     => $edit_url,
					'view_url'     => $view_url,
				);
			}

			$item_info = $menu_items_cache[ $item_id ];
			$context   = sprintf( __( 'Menu Item Field: %s', 'wp-media-usage-tracker' ), $meta_key );

			if ( is_numeric( $meta_val ) ) {
				$cand_id = (int) $meta_val;
				if ( isset( $media_items[ $cand_id ] ) ) {
					self::record_usage( $media_items, $cand_id, $item_info, $context );
				}
			} elseif ( is_serialized( $meta_val ) ) {
				$unserialized = @unserialize( $meta_val );
				if ( is_array( $unserialized ) ) {
					self::scan_array_for_media_ids( $unserialized, $media_items, $item_info, $context );
				}
			} elseif ( is_string( $meta_val ) && strpos( $meta_val, '/wp-content/uploads/' ) !== false ) {
				self::check_string_for_media( $meta_val, $media_items, $id_by_filename, $item_info, $context );
			}
		}
	}

	/**
	 * Scan Taxonomy Terms (Categories, Tags, Custom Taxonomies with image fields)
	 */
	private static function scan_taxonomy_terms( &$media_items, $all_media_ids, $id_by_filename ) {
		global $wpdb;

		$term_rows = $wpdb->get_results(
			"SELECT term_id, meta_key, meta_value 
			FROM {$wpdb->termmeta} 
			WHERE meta_value != ''"
		);

		if ( empty( $term_rows ) ) {
			return;
		}

		$terms_cache = array();

		foreach ( $term_rows as $row ) {
			$meta_key = $row->meta_key;
			if ( self::is_non_media_meta_key( $meta_key ) ) {
				continue;
			}

			$term_id  = (int) $row->term_id;
			$meta_val = $row->meta_value;

			if ( ! isset( $terms_cache[ $term_id ] ) ) {
				$t = get_term( $term_id );
				if ( ! $t || is_wp_error( $t ) ) {
					continue;
				}

				$tax_obj   = get_taxonomy( $t->taxonomy );
				$tax_label = $tax_obj ? $tax_obj->labels->singular_name : ucfirst( $t->taxonomy );

				$terms_cache[ $term_id ] = array(
					'id'           => $term_id,
					'title'        => sprintf( '%s: %s', $tax_label, self::clean_title( $t->name ) ),
					'post_type'    => 'term',
					'type_label'   => $tax_label,
					'post_status'  => 'publish',
					'status_label' => __( 'Active', 'wp-media-usage-tracker' ),
					'edit_url'     => admin_url( 'term.php?taxonomy=' . $t->taxonomy . '&tag_ID=' . $term_id ),
					'view_url'     => get_term_link( $t ),
				);
			}

			$term_info = $terms_cache[ $term_id ];
			$context   = sprintf( __( 'Taxonomy Field: %s', 'wp-media-usage-tracker' ), $meta_key );

			if ( is_numeric( $meta_val ) ) {
				$cand_id = (int) $meta_val;
				if ( isset( $media_items[ $cand_id ] ) ) {
					self::record_usage( $media_items, $cand_id, $term_info, $context );
				}
			} elseif ( is_serialized( $meta_val ) ) {
				$unserialized = @unserialize( $meta_val );
				if ( is_array( $unserialized ) ) {
					self::scan_array_for_media_ids( $unserialized, $media_items, $term_info, $context );
				}
			} elseif ( is_string( $meta_val ) && strpos( $meta_val, '/wp-content/uploads/' ) !== false ) {
				self::check_string_for_media( $meta_val, $media_items, $id_by_filename, $term_info, $context );
			}
		}
	}

	private static function scan_lazyblocks_blocks( $blocks, &$media_items, $id_by_filename, &$post_detected_ids, $post_meta_info ) {
		if ( ! is_array( $blocks ) ) {
			return;
		}

		foreach ( $blocks as $b ) {
			$block_name = ! empty( $b['blockName'] ) ? $b['blockName'] : '';

			if ( 0 === strpos( $block_name, 'lazyblock/' ) ) {
				$slug        = substr( $block_name, 10 );
				$clean_title = ucwords( str_replace( array( '-', '_' ), ' ', $slug ) );

				if ( ! empty( $b['attrs'] ) && is_array( $b['attrs'] ) ) {
					foreach ( $b['attrs'] as $attr_k => $attr_v ) {
						$data = null;
						if ( is_string( $attr_v ) && strpos( $attr_v, '%' ) !== false ) {
							$data = json_decode( rawurldecode( $attr_v ), true );
						} elseif ( is_array( $attr_v ) ) {
							$data = $attr_v;
						}

						if ( is_array( $data ) ) {
							// Check if indexed numeric array (Repeater)
							$is_repeater = ( array_keys( $data ) === range( 0, count( $data ) - 1 ) );

							if ( $is_repeater ) {
								foreach ( $data as $row ) {
									if ( is_array( $row ) ) {
										foreach ( $row as $sub_k => $sub_v ) {
											// Case 1: Image / File object array with ID
											if ( is_array( $sub_v ) && ! empty( $sub_v['id'] ) && ( ! empty( $sub_v['url'] ) || ! empty( $sub_v['sizes'] ) || ! empty( $sub_v['mime_type'] ) ) ) {
												$cand_id = (int) $sub_v['id'];
												if ( isset( $media_items[ $cand_id ] ) ) {
													$post_detected_ids[ $cand_id ] = true;
													$context = sprintf(
														__( 'Content: LazyBlocks (%1$s - Repeater: %2$s)', 'wp-media-usage-tracker' ),
														$clean_title,
														$attr_k
													);
													self::record_usage( $media_items, $cand_id, $post_meta_info, $context );
												}
											} elseif ( is_numeric( $sub_v ) && isset( $media_items[ (int) $sub_v ] ) ) {
												$cand_id = (int) $sub_v;
												$post_detected_ids[ $cand_id ] = true;
												$context = sprintf(
													__( 'Content: LazyBlocks (%1$s - Repeater: %2$s)', 'wp-media-usage-tracker' ),
													$clean_title,
													$attr_k
												);
												self::record_usage( $media_items, $cand_id, $post_meta_info, $context );
											} elseif ( is_string( $sub_v ) && ( strpos( $sub_v, '/wp-content/uploads/' ) !== false || strpos( $sub_v, 'wp-image-' ) !== false || strpos( rawurldecode( $sub_v ), '/wp-content/uploads/' ) !== false ) ) {
												// Case 2: Classic Editor / rich text / URL link inside repeater
												$context = sprintf(
													__( 'Content: LazyBlocks (%1$s - Repeater: %2$s)', 'wp-media-usage-tracker' ),
													$clean_title,
													$attr_k
												);
												self::scan_string_for_lazyblocks_media( $sub_v, $media_items, $id_by_filename, $post_meta_info, $context, $post_detected_ids );
											}
										}
									}
								}
							} else {
								// Direct field outside repeater
								if ( ! empty( $data['id'] ) && ( ! empty( $data['url'] ) || ! empty( $data['sizes'] ) || ! empty( $data['mime_type'] ) ) ) {
									$cand_id = (int) $data['id'];
									if ( isset( $media_items[ $cand_id ] ) ) {
										$post_detected_ids[ $cand_id ] = true;
										$context = sprintf(
											__( 'Content: LazyBlocks (%1$s - Direct Field: %2$s)', 'wp-media-usage-tracker' ),
											$clean_title,
											$attr_k
										);
										self::record_usage( $media_items, $cand_id, $post_meta_info, $context );
									}
								}
							}
						} elseif ( is_numeric( $attr_v ) && isset( $media_items[ (int) $attr_v ] ) ) {
							$cand_id = (int) $attr_v;
							$post_detected_ids[ $cand_id ] = true;
							$context = sprintf(
								__( 'Content: LazyBlocks (%1$s - Direct Field: %2$s)', 'wp-media-usage-tracker' ),
								$clean_title,
								$attr_k
							);
							self::record_usage( $media_items, $cand_id, $post_meta_info, $context );
						} elseif ( is_string( $attr_v ) && ( strpos( $attr_v, '/wp-content/uploads/' ) !== false || strpos( $attr_v, 'wp-image-' ) !== false || strpos( rawurldecode( $attr_v ), '/wp-content/uploads/' ) !== false ) ) {
							// Direct Classic Editor / rich text / URL link field outside repeater
							$context = sprintf(
								__( 'Content: LazyBlocks (%1$s - Direct Field: %2$s)', 'wp-media-usage-tracker' ),
								$clean_title,
								$attr_k
							);
							self::scan_string_for_lazyblocks_media( $attr_v, $media_items, $id_by_filename, $post_meta_info, $context, $post_detected_ids );
						}
					}
				}
			}

			if ( ! empty( $b['innerBlocks'] ) ) {
				self::scan_lazyblocks_blocks( $b['innerBlocks'], $media_items, $id_by_filename, $post_detected_ids, $post_meta_info );
			}
		}
	}

	/**
	 * Scan an HTML / text string within LazyBlocks (e.g. Classic Editor field) for media links or images
	 */
	private static function scan_string_for_lazyblocks_media( $str, &$media_items, $id_by_filename, $post_info, $context, &$post_detected_ids ) {
		if ( ! is_string( $str ) || empty( $str ) ) {
			return;
		}

		$decoded = ( strpos( $str, '%' ) !== false ) ? rawurldecode( $str ) : $str;

		// 1. Check for wp-image-{ID} class (if an image was inserted via Classic Editor media modal)
		if ( preg_match_all( '/wp-image-(\d+)/', $decoded, $matches ) ) {
			foreach ( $matches[1] as $matched_id ) {
				$matched_id = (int) $matched_id;
				if ( isset( $media_items[ $matched_id ] ) ) {
					$post_detected_ids[ $matched_id ] = true;
					self::record_usage( $media_items, $matched_id, $post_info, $context );
				}
			}
		}

		// 2. Check for uploaded files (PDFs, docs, images, zips, etc.) via /wp-content/uploads/
		if ( preg_match_all( '/\/wp-content\/uploads\/([0-9]{4}\/[0-9]{2}\/)?([a-zA-Z0-9_\-\.%]+)/i', $decoded, $matches ) ) {
			foreach ( $matches[2] as $found_file ) {
				$found_basename = strtolower( basename( $found_file ) );
				if ( isset( $id_by_filename[ $found_basename ] ) ) {
					foreach ( $id_by_filename[ $found_basename ] as $matched_id ) {
						$post_detected_ids[ $matched_id ] = true;
						self::record_usage( $media_items, $matched_id, $post_info, $context );
					}
					continue;
				}

				$stripped = preg_replace( '/-(\\d+)x(\\d+)(\\.[a-zA-Z0-9]+)$/', '$3', $found_basename );
				if ( isset( $id_by_filename[ $stripped ] ) ) {
					foreach ( $id_by_filename[ $stripped ] as $matched_id ) {
						$post_detected_ids[ $matched_id ] = true;
						self::record_usage( $media_items, $matched_id, $post_info, $context );
					}
				}
			}
		}
	}

	private static function scan_array_for_media_ids( $data, &$media_items, $post_info, $context ) {
		if ( ! is_array( $data ) ) {
			return;
		}

		foreach ( $data as $key => $val ) {
			if ( is_numeric( $val ) ) {
				$c_id = (int) $val;
				if ( isset( $media_items[ $c_id ] ) && ( 'ID' === $key || 'id' === $key || 'image' === $key || 'photo' === $key || 'media' === $key || 'file' === $key || 'doc' === $key || 'pdf' === $key || 'document' === $key || 'download' === $key || 'attachment' === $key || 'attachment_id' === $key || is_numeric( $key ) || self::is_media_related_meta_key( $key ) ) ) {
					self::record_usage( $media_items, $c_id, $post_info, $context );
				}
			} elseif ( is_string( $val ) && strpos( $val, '/wp-content/uploads/' ) !== false ) {
				$clean = basename( strtok( $val, '?#' ) );
				$cand_lower = strtolower( $clean );
				foreach ( $media_items as $att_id => $m ) {
					if ( strtolower( $m['filename'] ) === $cand_lower ) {
						self::record_usage( $media_items, $att_id, $post_info, $context );
						break;
					}
				}
			} elseif ( is_array( $val ) ) {
				if ( isset( $val['ID'] ) && is_numeric( $val['ID'] ) && isset( $media_items[ (int) $val['ID'] ] ) ) {
					self::record_usage( $media_items, (int) $val['ID'], $post_info, $context );
				} elseif ( isset( $val['id'] ) && is_numeric( $val['id'] ) && isset( $media_items[ (int) $val['id'] ] ) ) {
					self::record_usage( $media_items, (int) $val['id'], $post_info, $context );
				} else {
					self::scan_array_for_media_ids( $val, $media_items, $post_info, $context );
				}
			}
		}
	}

	/**
	 * Format scan result array with totals and statistics
	 */
	private static function format_result( $media_items, $target_types, $scanned_statuses ) {
		$total_media  = count( $media_items );
		$used_media   = 0;
		$unused_media = 0;
		$total_size   = 0;
		$unused_size  = 0;
		$total_usages = 0;

		$items_list = array_values( $media_items );

		foreach ( $items_list as $item ) {
			$total_size += $item['filesize'];
			if ( $item['usage_count'] > 0 ) {
				$used_media++;
				$total_usages += $item['usage_count'];
			} else {
				$unused_media++;
				$unused_size += $item['filesize'];
			}
		}

		return array(
			'data_version'       => self::DATA_VERSION,
			'scanned_at'         => current_time( 'timestamp' ),
			'scanned_formatted'  => current_time( 'M j, Y g:i A' ),
			'total_media'        => $total_media,
			'used_media'         => $used_media,
			'unused_media'       => $unused_media,
			'total_usages'       => $total_usages,
			'total_size'         => $total_size,
			'total_size_form'    => size_format( $total_size, 1 ),
			'unused_size'        => $unused_size,
			'unused_size_form'   => size_format( $unused_size, 1 ),
			'target_types'       => $target_types,
			'scanned_statuses'   => $scanned_statuses,
			'items'              => $items_list,
		);
	}
}
