<?php
defined( 'ABSPATH' ) || die( 'Cheatin\' uh?' );

/**
 * Get the path to the backups directory (custom folders).
 *
 * @since  1.7
 * @author Grégory Viguier
 *
 * @return string Path to the backups directory.
 */
function imagify_get_files_backup_dir_path() {
	static $backup_dir;

	if ( isset( $backup_dir ) ) {
		return $backup_dir;
	}

	$backup_dir = imagify_get_abspath() . 'imagify-backup/';

	/**
	 * Filter the backup directory path (custom folders).
	 *
	 * @since  1.7
	 * @author Grégory Viguier
	 *
	 * @param string $backup_dir The backup directory path.
	*/
	$backup_dir = apply_filters( 'imagify_files_backup_directory', $backup_dir );
	$backup_dir = trailingslashit( wp_normalize_path( $backup_dir ) );

	return $backup_dir;
}

/**
 * Tell if the folder containing the backups is writable (custom folders).
 *
 * @since  1.7
 * @author Grégory Viguier
 *
 * @return bool
 */
function imagify_files_backup_dir_is_writable() {
	if ( ! get_imagify_backup_dir_path() ) {
		return false;
	}

	$filesystem     = imagify_get_filesystem();
	$has_backup_dir = wp_mkdir_p( imagify_get_files_backup_dir_path() );

	return $has_backup_dir && $filesystem->is_writable( imagify_get_files_backup_dir_path() );
}

/**
 * Get the backup path of a specific file (custom folders).
 *
 * @since  1.7
 * @author Grégory Viguier
 *
 * @param  string $file_path The file path.
 * @return string|bool       The backup path. False on failure.
 */
function imagify_get_file_backup_path( $file_path ) {
	$file_path  = wp_normalize_path( (string) $file_path );
	$abspath    = imagify_get_abspath();
	$backup_dir = imagify_get_files_backup_dir_path();

	if ( ! $file_path ) {
		return false;
	}

	return str_replace( $abspath, $backup_dir, $file_path );
}

/**
 * Get installed theme paths.
 *
 * @since  1.7
 * @author Grégory Viguier
 *
 * @return array A list of installed theme paths in the form of '{{THEMES}}/twentyseventeen/' => '/abspath/to/wp-content/themes/twentyseventeen/'.
 */
function imagify_get_theme_folders() {
	static $themes;

	if ( isset( $themes ) ) {
		return $themes;
	}

	$all_themes = wp_get_themes();
	$themes     = array();

	if ( ! $all_themes ) {
		return $themes;
	}

	foreach ( $all_themes as $stylesheet => $theme ) {
		if ( ! $theme->exists() ) {
			continue;
		}

		$path = trailingslashit( $theme->get_stylesheet_directory() );

		if ( Imagify_Files_Scan::is_path_forbidden( $path ) ) {
			continue;
		}

		$placeholder = Imagify_Files_Scan::add_placeholder( $path );

		$themes[ $placeholder ] = $path;
	}

	return $themes;
}

/**
 * Get installed plugin paths.
 *
 * @since  1.7
 * @author Grégory Viguier
 *
 * @return array A list of installed plugin paths in the form of '{{PLUGINS}}/imagify/' => '/abspath/to/wp-content/plugins/imagify/'.
 */
function imagify_get_plugin_folders() {
	static $plugins, $plugins_path;

	if ( isset( $plugins ) ) {
		return $plugins;
	}

	if ( ! isset( $plugins_path ) ) {
		$plugins_path = Imagify_Files_Scan::remove_placeholder( '{{PLUGINS}}/' );
	}

	$all_plugins = get_plugins();
	$plugins     = array();

	if ( ! $all_plugins ) {
		return $plugins;
	}

	$filesystem = imagify_get_filesystem();

	foreach ( $all_plugins as $plugin_file => $plugin_data ) {
		$path = $plugins_path . $plugin_file;

		if ( ! $filesystem->exists( $path ) || Imagify_Files_Scan::is_path_forbidden( $path ) ) {
			continue;
		}

		$plugin_file = dirname( $plugin_file ) . '/';
		$plugins[ '{{PLUGINS}}/' . $plugin_file ] = $plugins_path . $plugin_file;
	}

	return $plugins;
}

/**
 * Get folders to be optimized from the DB, by folder type.
 *
 * @since  1.7
 * @author Grégory Viguier
 *
 * @param  string    $folder_type The folder type. Possible values are 'themes', 'plugins', and 'custom-folders'. Custom folder types can also be used.
 * @param  bool|null $to_optimize True to fetch only folders that must be optimized (checked in the settings). False to fetch only folders that must noe be optimized. Null for all.
 * @return array                  An array of arrays containing the following keys:
 *                                    - int    $folder_id   The folder ID.
 *                                    - string $path        The folder path, with placeholder.
 *                                    - int    $active      1 if the folder should be optimized. 0 otherwize.
 *                                    - string $folder_path The real absolute folder path.
 *                                Example:
 *                                    Array(
 *                                        [7] => Array(
 *                                            [folder_id] => 7
 *                                            [path] => {{ABSPATH}}/custom-path/
 *                                            [active] => 1
 *                                            [folder_path] => /absolute/path/to/custom-path/
 *                                        )
 *                                        [13] => Array(
 *                                            [folder_id] => 13
 *                                            [path] => {{CONTENT}}/another-custom-path/
 *                                            [active] => 1
 *                                            [folder_path] => /absolute/path/to/wp-content/another-custom-path/
 *                                        )
 *                                    )
 */
function imagify_get_folders_from_type( $folder_type, $to_optimize = true ) {
	global $wpdb;

	$folder_type   = strtolower( $folder_type );
	$folders_db    = Imagify_Folders_DB::get_instance();
	$folders_table = $folders_db->get_table_name();
	$primary_key   = esc_sql( $folders_db->get_primary_key() );
	$active        = '';

	if ( $to_optimize ) {
		$to_optimize = true;
		$active      = 'AND active = 1';
	} elseif ( false === $to_optimize ) {
		$active      = 'AND active = 0';
	}

	// Get the folders from the DB.
	if ( 'themes' === $folder_type || 'plugins' === $folder_type ) {
		/**
		 * Themes or plugins.
		 */
		if ( 'themes' === $folder_type ) {
			$folders = array_keys( imagify_get_theme_folders() );
		} else {
			$folders = array_keys( imagify_get_plugin_folders() );
		}

		$folder_values = Imagify_DB::prepare_values_list( $folders );
		$results       = $wpdb->get_results( "SELECT * FROM $folders_table WHERE path IN ( $folder_values ) $active;", ARRAY_A ); // WPCS: unprepared SQL ok.

	} elseif ( 'custom-folders' === $folder_type ) {
		/**
		 * Custom folders.
		 */
		$folders       = array_keys( array_merge( imagify_get_theme_folders(), imagify_get_plugin_folders() ) );
		$folder_values = Imagify_DB::prepare_values_list( $folders );
		$results       = $wpdb->get_results( "SELECT * FROM $folders_table WHERE path NOT IN ( $folder_values ) $active;", ARRAY_A ); // WPCS: unprepared SQL ok.

		if ( $results && is_array( $results ) ) {
			$filesystem = imagify_get_filesystem();

			foreach ( $results as $i => $result ) {
				$path = Imagify_Files_Scan::remove_placeholder( $result['path'] );

				if ( ! $filesystem->exists( $path ) || Imagify_Files_Scan::is_path_forbidden( $path ) ) {
					unset( $results[ $i ] );
				}
			}
		}
	} else {
		/**
		 * Provide folders for a custom folder type.
		 *
		 * @since  1.7
		 * @author Grégory Viguier
		 *
		 * @param array $results     An array of arrays containing the same keys as in the "imagify_folders" table in the DB.
		 * @param bool  $to_optimize True to fetch only folders that must be optimized.
		 */
		$results = apply_filters( 'imagify_get_folders_from_type_' . $folder_type, array(), $to_optimize );
	}

	if ( ! $results || ! is_array( $results ) ) {
		return array();
	}

	// Cast results, add absolute paths.
	$folders = array();

	foreach ( $results as $i => $row_fields ) {
		// Cast the row.
		$row_fields = $folders_db->cast_row( $row_fields );

		// Add the absolute path.
		$row_fields['folder_path'] = Imagify_Files_Scan::remove_placeholder( $row_fields['path'] );

		// Add the row to the list.
		$folders[ $row_fields[ $primary_key ] ] = $row_fields;
	}

	return $folders;
}

/**
 * Get files belonging to the given folders.
 * Files are scanned from the folders, then:
 *     - If a file doesn't exist in the DB, it is added.
 *     - If a file is in the DB, but with a wrong folder_id, it is fixed.
 *     - If a file doesn't exist, it is not returned.
 *
 * @since  1.7
 * @see    imagify_get_folders_from_type()
 * @author Grégory Viguier
 *
 * @param  array $folders An array of arrays containing at least the key 'folder_path'. See imagify_get_folders_from_type() for the format.
 * @param  array $args    A list of arguments to tell more precisely what to fetch:
 *                            - int $optimization_level If set with an integer, only files that needs to be optimized to this level will be returned (the status is also checked).
 * @return array          A list of files in the following format:
 *                            Array(
 *                                [_2] => Array(
 *                                    [file_id] => 2
 *                                    [folder_id] => 7
 *                                    [path] => {{ABSPATH}}/custom-path/image-1.jpg
 *                                    [optimization_level] => null
 *                                    [status] => null
 *                                    [file_path] => /absolute/path/to/custom-path/image-1.jpg
 *                                )
 *                                [_3] => Array(
 *                                    [file_id] => 3
 *                                    [folder_id] => 7
 *                                    [path] => {{ABSPATH}}/custom-path/image-2.jpg
 *                                    [optimization_level] => 2
 *                                    [status] => success
 *                                    [file_path] => /absolute/path/to/custom-path/image-2.jpg
 *                                )
 *                                [_6] => Array(
 *                                    [file_id] => 6
 *                                    [folder_id] => 13
 *                                    [path] => {{CONTENT}}/another-custom-path/image-1.jpg
 *                                    [optimization_level] => 0
 *                                    [status] => error
 *                                    [file_path] => /absolute/path/to/wp-content/another-custom-path/image-1.jpg
 *                                )
 *                            )
 *                            The fields 'optimization_level' and 'status' are set only if the argument 'optimization_level' was set.
 */
function imagify_get_files_from_folders( $folders, $args = array() ) {
	global $wpdb;

	if ( ! $folders ) {
		return array();
	}

	$filesystem   = imagify_get_filesystem();
	$files_db     = Imagify_Files_DB::get_instance();
	$files_table  = $files_db->get_table_name();
	$files_key    = esc_sql( $files_db->get_primary_key() );
	$optimization = isset( $args['optimization_level'] ) && is_numeric( $args['optimization_level'] );

	/**
	 * Scan folders for files. $files_from_scan will be in the following format:
	 * Array(
	 *     [7] => Array(
	 *         [/absolute/path/to/custom-path/image-1.jpg] => 0
	 *         [/absolute/path/to/custom-path/image-2.jpg] => 1
	 *     )
	 *     [13] => Array(
	 *         [/absolute/path/to/wp-content/another-custom-path/image-1.jpg] => 0
	 *         [/absolute/path/to/wp-content/another-custom-path/image-2.jpg] => 1
	 *         [/absolute/path/to/wp-content/another-custom-path/image-3.jpg] => 2
	 *     )
	 * )
	 */
	$files_from_scan = array();

	foreach ( $folders as $folder_id => $folder ) {
		$files_from_scan[ $folder_id ] = Imagify_Files_Scan::get_files_from_folder( $folder['folder_path'] );

		if ( is_wp_error( $files_from_scan[ $folder_id ] ) ) {
			unset( $files_from_scan[ $folder_id ] );
		}
	}

	$files_from_scan = array_map( 'array_flip', $files_from_scan );

	/**
	 * Get the files from DB. $files_from_db will be in the same format as the function output.
	 */
	$already_optimized = array();
	$folder_ids        = array_keys( $folders );
	$files_from_db     = array_fill_keys( $folder_ids, array() );
	$folder_ids        = Imagify_DB::prepare_values_list( $folder_ids );
	$select_fields     = "$files_key, folder_id, path" . ( $optimization ? ', optimization_level, status' : '' );

	$results = $wpdb->get_results( "SELECT $select_fields FROM $files_table WHERE folder_id IN ( $folder_ids ) ORDER BY folder_id, $files_key;", ARRAY_A ); // WPCS: unprepared SQL ok.

	if ( $results ) {
		$wpdb->flush();

		foreach ( $results as $i => $row_fields ) {
			// Cast the row.
			$row_fields = $files_db->cast_row( $row_fields );

			// Add the absolute path.
			$row_fields['file_path'] = Imagify_Files_Scan::remove_placeholder( $row_fields['path'] );

			// Remove the file from the scan.
			unset( $files_from_scan[ $row_fields['folder_id'] ][ $row_fields['file_path'] ] );

			if ( $optimization ) {
				if ( 'error' !== $row_fields['status'] && $row_fields['optimization_level'] === $args['optimization_level'] ) {
					// Try the same level only if the status is an error.
					continue;
				}

				if ( 'already_optimized' === $row_fields['status'] && $row_fields['optimization_level'] >= $args['optimization_level'] ) {
					// If the image is already compressed, optimize only if the requested level is higher.
					continue;
				}

				if ( 'success' === $row_fields['status'] && $args['optimization_level'] !== $row_fields['optimization_level'] ) {
					$file_backup_path = imagify_get_file_backup_path( $row_fields['file_path'] );

					if ( ! $file_backup_path || ! $filesystem->exists( $file_backup_path ) ) {
						// Don't try to re-optimize if there is no backup file.
						continue;
					}
				}
			}

			if ( ! $filesystem->exists( $row_fields['file_path'] ) ) {
				// If the file doesn't exist, bail out.
				continue;
			}

			if ( $optimization && 'already_optimized' === $row_fields['status'] ) {
				$already_optimized[ '_' . $row_fields[ $files_key ] ] = 1;
			}

			// Add the row to the list.
			$files_from_db[ $row_fields['folder_id'] ][ '_' . $row_fields[ $files_key ] ] = $row_fields;
		}
	}

	unset( $results );
	$files_from_scan = array_filter( $files_from_scan );

	// Make sure files from the scan are not already in the DB with another folder (shouldn't be possible, but, you know...).
	if ( $files_from_scan ) {
		$folders_by_placeholder = array();

		foreach ( $files_from_scan as $folder_id => $folder_files ) {
			foreach ( $folder_files as $file_path => $i ) {
				$placeholder = Imagify_Files_Scan::add_placeholder( $file_path );

				$folders_by_placeholder[ $placeholder ]      = $folder_id;
				$files_from_scan[ $folder_id ][ $file_path ] = $placeholder;
			}
		}

		$placeholders  = Imagify_DB::prepare_values_list( array_keys( $folders_by_placeholder ) );
		$select_fields = "$files_key, folder_id, path" . ( $optimization ? ', optimization_level, status' : '' );

		$results = $wpdb->get_results( "SELECT $select_fields FROM $files_table WHERE path IN ( $placeholders ) ORDER BY folder_id, $files_key;", ARRAY_A ); // WPCS: unprepared SQL ok.

		if ( $results ) {
			// Damn...
			$wpdb->flush();

			foreach ( $results as $i => $row_fields ) {
				// Cast the row.
				$row_fields    = $files_db->cast_row( $row_fields );
				$old_folder_id = $row_fields['folder_id'];

				// Add the absolute path.
				$row_fields['file_path'] = Imagify_Files_Scan::remove_placeholder( $row_fields['path'] );

				// Set the new folder ID.
				$row_fields['folder_id'] = $folders_by_placeholder[ $row_fields['path'] ];

				// Remove the file from everywhere.
				unset(
					$files_from_db[ $old_folder_id ][ '_' . $row_fields[ $files_key ] ],
					$files_from_scan[ $old_folder_id ][ $row_fields['file_path'] ],
					$files_from_scan[ $row_fields['folder_id'] ][ $row_fields['file_path'] ]
				);

				if ( $optimization ) {
					if ( 'error' !== $row_fields['status'] && $row_fields['optimization_level'] === $args['optimization_level'] ) {
						// Try the same level only if the status is an error.
						continue;
					}

					if ( 'already_optimized' === $row_fields['status'] && $row_fields['optimization_level'] >= $args['optimization_level'] ) {
						// If the image is already compressed, optimize only if the requested level is higher.
						continue;
					}

					if ( 'success' === $row_fields['status'] && $args['optimization_level'] !== $row_fields['optimization_level'] ) {
						$file_backup_path = imagify_get_file_backup_path( $row_fields['file_path'] );

						if ( ! $file_backup_path || ! $filesystem->exists( $file_backup_path ) ) {
							// Don't try to re-optimize if there is no backup file.
							continue;
						}
					}
				}

				if ( ! $filesystem->exists( $row_fields['file_path'] ) ) {
					// If the file doesn't exist, bail out.
					continue;
				}

				// Set the correct folder ID in the DB.
				$success = $files_db->update( $row_fields[ $files_key ], array(
					'folder_id' => $row_fields['folder_id'],
				) );

				if ( $success ) {
					if ( $optimization && 'already_optimized' === $row_fields['status'] ) {
						$already_optimized[ '_' . $row_fields[ $files_key ] ] = 1;
					}

					$files_from_db[ $row_fields['folder_id'] ][ '_' . $row_fields[ $files_key ] ] = $row_fields;
				}
			}
		}

		unset( $results, $folders_by_placeholder );
	}

	$files_from_scan = array_filter( $files_from_scan );

	// Insert the remaining files into the DB.
	if ( $files_from_scan ) {
		foreach ( $files_from_scan as $folder_id => $placeholders ) {
			foreach ( $placeholders as $file_path => $placeholder ) {
				$file_id = imagify_insert_custom_file( array(
					'folder_id' => $folder_id,
					'path'      => $placeholder,
					'file_path' => $file_path,
				) );

				if ( $file_id ) {
					$files_from_db[ $folder_id ][ '_' . $file_id ] = array(
						'file_id'            => $file_id,
						'folder_id'          => $folder_id,
						'path'               => $placeholder,
						'optimization_level' => null,
						'status'             => null,
						'file_path'          => $file_path,
					);
				}
			}

			unset( $files_from_scan[ $folder_id ] );
		}
	}

	$files_from_db = array_filter( $files_from_db );

	if ( ! $files_from_db ) {
		return array();
	}

	$files_from_db = call_user_func_array( 'array_merge', $files_from_db );

	if ( $already_optimized ) {
		// Put the files already optimized at the end of the list.
		$already_optimized = array_intersect_key( $files_from_db, $already_optimized );
		$files_from_db     = array_diff_key( $files_from_db, $already_optimized );
		$files_from_db     = array_merge( $files_from_db, $already_optimized );
	}

	return $files_from_db;
}

/**
 * Insert a file into the DB.
 *
 * @since  1.7
 * @author Grégory Viguier
 *
 * @param  array $args An array of arguments to pass to Imagify_Files_DB::insert(). Required values are 'folder_id' and ( 'path' or 'file_path').
 * @return int         The file ID on success. 0 on failure.
 */
function imagify_insert_custom_file( $args = array() ) {
	if ( empty( $args['folder_id'] ) ) {
		return 0;
	}

	if ( empty( $args['path'] ) ) {
		if ( empty( $args['file_path'] ) ) {
			return 0;
		}

		$args['path'] = Imagify_Files_Scan::add_placeholder( $args['file_path'] );
	}

	if ( empty( $args['file_path'] ) ) {
		$args['file_path'] = Imagify_Files_Scan::remove_placeholder( $args['path'] );
	}

	$filesystem = imagify_get_filesystem();

	if ( ! $filesystem->is_readable( $args['file_path'] ) ) {
		return 0;
	}

	if ( empty( $args['width'] ) || empty( $args['height'] ) ) {
		$file_size      = @getimagesize( $args['file_path'] );
		$args['width']  = $file_size && isset( $file_size[0] ) ? $file_size[0] : 0;
		$args['height'] = $file_size && isset( $file_size[1] ) ? $file_size[1] : 0;
	}

	if ( empty( $args['hash'] ) ) {
		$args['hash'] = md5_file( $args['file_path'] );
	}

	if ( empty( $args['mime_type'] ) ) {
		$args['mime_type'] = imagify_get_mime_type_from_file( $args['file_path'] );
	}

	if ( empty( $args['original_size'] ) ) {
		$args['original_size'] = (int) $filesystem->size( $args['file_path'] );
	}

	$files_db    = Imagify_Files_DB::get_instance();
	$primary_key = $files_db->get_primary_key();
	unset( $args[ $primary_key ] );

	return $files_db->insert( $args );
}
