<?php
/*
	Plugin Name: Term Count Audit
	Plugin URI: https://github.com/alleyinteractive/term-count-audit
	Description: WP-CLI command to check that term counts are accurate and/or fix them.
	Version: 0.1
	Author: Alley Interactive
	Author URI: http://www.alleyinteractive.com/
*/
/*  This program is free software; you can redistribute it and/or modify
    it under the terms of the GNU General Public License as published by
    the Free Software Foundation; either version 2 of the License, or
    (at your option) any later version.

    This program is distributed in the hope that it will be useful,
    but WITHOUT ANY WARRANTY; without even the implied warranty of
    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
    GNU General Public License for more details.

    You should have received a copy of the GNU General Public License
    along with this program; if not, write to the Free Software
    Foundation, Inc., 59 Temple Place, Suite 330, Boston, MA  02111-1307  USA
*/

/**
 * WP-CLI Command for Term Count Audit.
 */
class Term_Count_Audit_CLI_Command {

	/**
	 * Audit and fix term counts.
	 *
	 * ## OPTIONS
	 *
	 * [--fix]
	 * : If present, term counts will be updated.
	 *
	 * [--format=<format>]
	 * : Accepted values: table, csv, json, count. Default: table
	 *
	 * [--verbose]
	 * : If present, all terms will be shown. Otherwise, only terms with
	 * mismatched counts will be returned.
	 *
	 * ## EXAMPLES
	 *
	 *     wp term-count-audit
	 *     wp term-count-audit --fix
	 *     wp term-count-audit --format=json
	 *     wp term-count-audit --verbose
	 *
	 * @synopsis [--fix] [--format=<format>] [--verbose]
	 */
	public function __invoke( $args, $assoc_args ) {
		$assoc_args = wp_parse_args( $assoc_args, array(
			'fix' => false,
			'format' => 'table',
			'verbose' => false,
		) );

		$rows = array();

		// Get all taxonomies
		$taxonomies = get_taxonomies();
		if ( empty( $taxonomies ) ) {
			\WP_CLI::error( 'No taxonomies found!' );
		}

		// If any taxonomies have `update_count_callback` set, we can't accurately
		// count them. In those cases, if we're not fixing them, we just skip them.
		if ( ! $assoc_args['fix'] ) {
			foreach ( $taxonomies as $i => $taxonomy ) {
				$tax_object = get_taxonomy( $taxonomy );

				if ( $tax_object->update_count_callback ) {
					\WP_CLI::warning( "Skipping the `{$taxonomy}` taxonomy, which has a custom count callback. Using `--fix` will still update this taxonomy and report on any deviations it finds" );
					unset( $taxonomies[ $i ] );
				}
			}
		}

		$total_terms = get_terms( array(
			'taxonomy' => $taxonomies,
			'fields' => 'count',
			'hide_empty' => 0,
		) );

		if ( ! $total_terms ) {
			\WP_CLI::error( 'No terms found!' );
		}

		// Build a progress bar.
		$progress = \WP_CLI\Utils\make_progress_bar( 'Calculating term counts', $total_terms );

		foreach ( $taxonomies as $taxonomy ) {

			// Get all terms for the taxonomy.
			$terms = get_terms( array(
				'taxonomy' => $taxonomy,
				'hide_empty' => 0,
			) );

			if ( is_array( $terms ) && ! empty( $terms ) ) {
				foreach ( $terms as $term ) {
					$row = array(
						'ID' => $term->term_id,
						'Taxonomy' => $term->taxonomy,
						'Slug' => $term->slug,
						'Cached Count' => $term->count,
					);

					if ( $assoc_args['fix'] ) {
						wp_update_term_count_now( array( $term->term_taxonomy_id ), $taxonomy );
						$term = get_term_by( 'term_taxonomy_id', $term->term_taxonomy_id );
						$row['Real Count'] = $term->count;
					} else {
						$row['Real Count'] = $this->count_term( $term->term_taxonomy_id, get_taxonomy( $taxonomy ) );
					}

					$row['Deviation'] = $row['Cached Count'] - $row['Real Count'];

					// To make it easier to read at a glance, deviations of 0
					// are removed unless the --verbose flag is set.
					if ( $assoc_args['verbose'] || $row['Deviation'] ) {
						$rows[] = $row;
					}

					$progress->tick();
				}
			}
		}
		$progress->finish();

		if ( empty( $rows ) ) {
			\WP_CLI::success( 'No mismatched counts found' );
		} else {
			\WP_CLI\Utils\format_items(
				$assoc_args['format'],
				$rows,
				array( 'ID', 'Taxonomy', 'Slug', 'Cached Count', 'Real Count', 'Deviation' )
			);
		}
	}

	/**
	 * Get a live term count for a given term_taxonomy_id and taxonomy.
	 *
	 * @param  int          $tt_id    Term Taxonomy ID.
	 * @param  \WP_Taxonomy $taxonomy Taxonomy object.
	 * @return int                    Term count.
	 */
	protected function count_term( $tt_id, $taxonomy ) {
		global $wpdb;

		$object_types = (array) $taxonomy->object_type;

		foreach ( $object_types as &$object_type ) {
			list( $object_type ) = explode( ':', $object_type );
		}

		$object_types = array_unique( $object_types );

		if ( false !== ( $check_attachments = array_search( 'attachment', $object_types ) ) ) {
			unset( $object_types[ $check_attachments ] );
			$check_attachments = true;
		}

		if ( $object_types ) {
			$object_types = esc_sql( array_filter( $object_types, 'post_type_exists' ) );
		}

		$count = 0;

		// Attachments can be 'inherit' status, we need to base count off the parent's status if so.
		if ( $check_attachments ) {
			$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->term_relationships, $wpdb->posts p1 WHERE p1.ID = $wpdb->term_relationships.object_id AND ( post_status = 'publish' OR ( post_status = 'inherit' AND post_parent = 0 ) ( post_status = 'inherit' AND post_parent > 0 AND ( SELECT post_status FROM $wpdb->posts WHERE ID = p1.post_parent ) = 'publish' ) ) AND post_type = 'attachment' AND term_taxonomy_id = %d", $tt_id ) );
		}

		if ( $object_types ) {
			$count += (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM $wpdb->term_relationships, $wpdb->posts WHERE $wpdb->posts.ID = $wpdb->term_relationships.object_id AND post_status = 'publish' AND post_type IN ('" . implode("', '", $object_types ) . "') AND term_taxonomy_id = %d", $tt_id ) );
		}

		return $count;
	}
}

// Only run this when WP_CLI is in use.
if ( defined( 'WP_CLI' ) && WP_CLI ) {
	\WP_CLI::add_command( 'term-count-audit', 'Term_Count_Audit_CLI_Command' );
}
