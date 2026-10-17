<?php
/**
 * Content tools: posts, terms, comments, and media.
 *
 * Agent-facing strings are intentionally plain English (not translated).
 *
 * @package AiConnect
 */

namespace AiConnect\Tools;

use AiConnect\Registry;
use AiConnect\Tool_Error;
use AiConnect\Util;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the content tool group.
 */
final class Content {

	/**
	 * Register this group.
	 *
	 * @return void
	 */
	public static function register() {
		Registry::add(
			'get_posts',
			array(
				'title'       => 'Get posts',
				'description' => 'Search and list posts of any type with filters (post type, status, search term) and pagination. Returns compact summaries.',
				'risk'        => 'read',
				'group'       => 'content',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Post type (post, page, product, ...).',
							'default'     => 'post',
						),
						'status'    => array(
							'type'        => 'string',
							'description' => 'Post status or "any".',
							'default'     => 'any',
						),
						'search'    => array(
							'type'        => 'string',
							'description' => 'Search phrase.',
							'default'     => '',
						),
						'per_page'  => array(
							'type'        => 'integer',
							'description' => 'Results per page (1-50).',
							'default'     => 10,
							'minimum'     => 1,
							'maximum'     => 50,
						),
						'page'      => array(
							'type'        => 'integer',
							'description' => 'Page number.',
							'default'     => 1,
							'minimum'     => 1,
						),
						'orderby'   => array(
							'type'        => 'string',
							'description' => 'Sort field.',
							'default'     => 'date',
							'enum'        => array( 'date', 'modified', 'title', 'ID', 'menu_order' ),
						),
						'order'     => array(
							'type'        => 'string',
							'description' => 'Sort direction.',
							'default'     => 'DESC',
							'enum'        => array( 'ASC', 'DESC' ),
						),
					),
				),
				'callback'    => array( __CLASS__, 'get_posts' ),
			)
		);

		Registry::add(
			'get_post',
			array(
				'title'       => 'Get post',
				'description' => 'Full post record by ID: raw content, excerpt, status, author, taxonomy terms, permalink, and post meta (bounded).',
				'risk'        => 'read',
				'group'       => 'content',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'id'           => array(
							'type'        => 'integer',
							'description' => 'Post ID.',
							'minimum'     => 1,
						),
						'include_meta' => array(
							'type'        => 'boolean',
							'description' => 'Include post meta (up to 100 keys).',
							'default'     => true,
						),
					),
					'required'   => array( 'id' ),
				),
				'callback'    => array( __CLASS__, 'get_post' ),
			)
		);

		Registry::add(
			'create_post',
			array(
				'title'       => 'Create post',
				'description' => 'Create a post, page, or custom post type entry with optional meta and taxonomy terms. Defaults to draft status for safety.',
				'risk'        => 'write',
				'group'       => 'content',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'title'     => array(
							'type'        => 'string',
							'description' => 'Post title.',
							'default'     => '',
						),
						'content'   => array(
							'type'        => 'string',
							'description' => 'Post content (raw; blocks or classic HTML).',
							'default'     => '',
						),
						'post_type' => array(
							'type'        => 'string',
							'description' => 'Post type.',
							'default'     => 'post',
						),
						'status'    => array(
							'type'        => 'string',
							'description' => 'Post status.',
							'default'     => 'draft',
							'enum'        => array( 'draft', 'publish', 'pending', 'private' ),
						),
						'excerpt'   => array(
							'type'        => 'string',
							'description' => 'Excerpt.',
							'default'     => '',
						),
						'slug'      => array(
							'type'        => 'string',
							'description' => 'URL slug.',
							'default'     => '',
						),
						'author'    => array(
							'type'        => 'integer',
							'description' => 'Author user ID (defaults to the acting user).',
							'default'     => 0,
						),
						'parent'    => array(
							'type'        => 'integer',
							'description' => 'Parent post ID (for hierarchical types).',
							'default'     => 0,
						),
						'meta'      => array(
							'type'        => 'object',
							'description' => 'Post meta key => value pairs.',
							'default'     => array(),
						),
						'terms'     => array(
							'type'        => 'object',
							'description' => 'Taxonomy => list of term names or IDs to assign.',
							'default'     => array(),
						),
					),
				),
				'callback'    => array( __CLASS__, 'create_post' ),
			)
		);

		Registry::add(
			'update_post',
			array(
				'title'       => 'Update post',
				'description' => 'Update fields of an existing post. Meta keys in "meta" are set (merged); terms in "terms" replace the full term list for that taxonomy; keys in "delete_meta" are removed.',
				'risk'        => 'write',
				'group'       => 'content',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'id'          => array(
							'type'        => 'integer',
							'description' => 'Post ID.',
							'minimum'     => 1,
						),
						'title'       => array(
							'type'        => 'string',
							'description' => 'New title (omit to keep).',
						),
						'content'     => array(
							'type'        => 'string',
							'description' => 'New content (omit to keep).',
						),
						'excerpt'     => array(
							'type'        => 'string',
							'description' => 'New excerpt (omit to keep).',
						),
						'status'      => array(
							'type'        => 'string',
							'description' => 'New status (omit to keep).',
							'enum'        => array( 'draft', 'publish', 'pending', 'private', 'trash' ),
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => 'New slug (omit to keep).',
						),
						'author'      => array(
							'type'        => 'integer',
							'description' => 'New author user ID (omit to keep).',
						),
						'meta'        => array(
							'type'        => 'object',
							'description' => 'Meta key => value pairs to set/merge.',
							'default'     => array(),
						),
						'delete_meta' => array(
							'type'        => 'array',
							'description' => 'Meta keys to delete.',
							'items'       => array( 'type' => 'string' ),
							'default'     => array(),
						),
						'terms'       => array(
							'type'        => 'object',
							'description' => 'Taxonomy => list of term names or IDs (replaces the term list for each provided taxonomy).',
							'default'     => array(),
						),
					),
					'required'   => array( 'id' ),
				),
				'callback'    => array( __CLASS__, 'update_post' ),
			)
		);

		Registry::add(
			'delete_post',
			array(
				'title'       => 'Delete post',
				'description' => 'Move a post to the trash, or delete it permanently with force=true. Prefer trash unless the user asks for permanent deletion.',
				'risk'        => 'write',
				'group'       => 'content',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'id'    => array(
							'type'        => 'integer',
							'description' => 'Post ID.',
							'minimum'     => 1,
						),
						'force' => array(
							'type'        => 'boolean',
							'description' => 'Permanently delete instead of trashing.',
							'default'     => false,
						),
					),
					'required'   => array( 'id' ),
				),
				'callback'    => array( __CLASS__, 'delete_post' ),
			)
		);

		Registry::add(
			'list_terms',
			array(
				'title'       => 'List terms',
				'description' => 'List taxonomy terms (categories, tags, product categories, ...) with slug, count, and parent.',
				'risk'        => 'read',
				'group'       => 'content',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy' => array(
							'type'        => 'string',
							'description' => 'Taxonomy slug.',
							'default'     => 'category',
						),
						'search'   => array(
							'type'        => 'string',
							'description' => 'Search phrase.',
							'default'     => '',
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Terms per page (1-200).',
							'default'     => 100,
							'minimum'     => 1,
							'maximum'     => 200,
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => 'Page number.',
							'default'     => 1,
							'minimum'     => 1,
						),
					),
				),
				'callback'    => array( __CLASS__, 'list_terms' ),
			)
		);

		Registry::add(
			'create_term',
			array(
				'title'       => 'Create term',
				'description' => 'Create a taxonomy term. If a term with the same name/slug exists, returns the existing term instead of failing.',
				'risk'        => 'write',
				'group'       => 'content',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'taxonomy'    => array(
							'type'        => 'string',
							'description' => 'Taxonomy slug.',
							'minLength'   => 1,
						),
						'name'        => array(
							'type'        => 'string',
							'description' => 'Term name.',
							'minLength'   => 1,
						),
						'slug'        => array(
							'type'        => 'string',
							'description' => 'Term slug (optional).',
							'default'     => '',
						),
						'parent'      => array(
							'type'        => 'integer',
							'description' => 'Parent term ID (0 = top level).',
							'default'     => 0,
						),
						'description' => array(
							'type'        => 'string',
							'description' => 'Term description.',
							'default'     => '',
						),
					),
					'required'   => array( 'taxonomy', 'name' ),
				),
				'callback'    => array( __CLASS__, 'create_term' ),
			)
		);

		Registry::add(
			'list_comments',
			array(
				'title'       => 'List comments',
				'description' => 'List comments with filters by status and post, with pagination.',
				'risk'        => 'read',
				'group'       => 'content',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'status'   => array(
							'type'        => 'string',
							'description' => 'Comment status filter.',
							'default'     => 'all',
							'enum'        => array( 'all', 'approve', 'hold', 'spam', 'trash' ),
						),
						'post_id'  => array(
							'type'        => 'integer',
							'description' => 'Only comments on this post (0 = all posts).',
							'default'     => 0,
						),
						'per_page' => array(
							'type'        => 'integer',
							'description' => 'Comments per page (1-100).',
							'default'     => 50,
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'page'     => array(
							'type'        => 'integer',
							'description' => 'Page number.',
							'default'     => 1,
							'minimum'     => 1,
						),
					),
				),
				'callback'    => array( __CLASS__, 'list_comments' ),
			)
		);

		Registry::add(
			'moderate_comment',
			array(
				'title'       => 'Moderate comment',
				'description' => 'Approve, hold, spam, unspam, trash, untrash, or permanently delete one comment.',
				'risk'        => 'write',
				'group'       => 'content',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'id'     => array(
							'type'        => 'integer',
							'description' => 'Comment ID.',
							'minimum'     => 1,
						),
						'action' => array(
							'type'        => 'string',
							'description' => 'Moderation action.',
							'enum'        => array( 'approve', 'hold', 'spam', 'unspam', 'trash', 'untrash', 'delete' ),
						),
					),
					'required'   => array( 'id', 'action' ),
				),
				'callback'    => array( __CLASS__, 'moderate_comment' ),
			)
		);

		Registry::add(
			'list_media',
			array(
				'title'       => 'List media',
				'description' => 'List media library items with URL, mime type, size, and dimensions.',
				'risk'        => 'read',
				'group'       => 'media',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'per_page'  => array(
							'type'        => 'integer',
							'description' => 'Items per page (1-100).',
							'default'     => 20,
							'minimum'     => 1,
							'maximum'     => 100,
						),
						'page'      => array(
							'type'        => 'integer',
							'description' => 'Page number.',
							'default'     => 1,
							'minimum'     => 1,
						),
						'mime_type' => array(
							'type'        => 'string',
							'description' => 'Filter by mime type or prefix, e.g. "image" or "application/pdf".',
							'default'     => '',
						),
						'search'    => array(
							'type'        => 'string',
							'description' => 'Search phrase.',
							'default'     => '',
						),
					),
				),
				'callback'    => array( __CLASS__, 'list_media' ),
			)
		);

		Registry::add(
			'upload_media',
			array(
				'title'       => 'Upload media',
				'description' => 'Add a file to the media library from a URL (server downloads it) or from base64 content with a filename. Maximum 10 MB. Returns the attachment URL.',
				'risk'        => 'write',
				'group'       => 'media',
				'schema'      => array(
					'type'       => 'object',
					'properties' => array(
						'url'            => array(
							'type'        => 'string',
							'description' => 'Source URL to download (alternative to content_base64).',
							'default'     => '',
						),
						'content_base64' => array(
							'type'        => 'string',
							'description' => 'Base64 file content (with or without data: prefix). Requires filename.',
							'default'     => '',
						),
						'filename'       => array(
							'type'        => 'string',
							'description' => 'Target filename, e.g. hero.webp.',
							'default'     => '',
						),
						'title'          => array(
							'type'        => 'string',
							'description' => 'Attachment title (defaults to the filename).',
							'default'     => '',
						),
					),
				),
				'callback'    => array( __CLASS__, 'upload_media' ),
			)
		);
	}

	/**
	 * get_posts tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the post type does not exist.
	 */
	public static function get_posts( array $args ) {
		$post_type = (string) $args['post_type'];
		if ( ! post_type_exists( $post_type ) ) {
			throw new Tool_Error( 'Unknown post type: ' . $post_type, '', 'Valid types can be discovered with WP-CLI: wp post-type list.' );
		}

		$query = new \WP_Query(
			array(
				'post_type'           => $post_type,
				'post_status'         => (string) $args['status'],
				's'                   => (string) $args['search'],
				'posts_per_page'      => (int) $args['per_page'],
				'paged'               => (int) $args['page'],
				'orderby'             => (string) $args['orderby'],
				'order'               => (string) $args['order'],
				'ignore_sticky_posts' => true,
			)
		);

		$items = array();
		foreach ( $query->posts as $post ) {
			$items[] = array(
				'id'     => (int) $post->ID,
				'title'  => get_the_title( $post ),
				'type'   => $post->post_type,
				'status' => $post->post_status,
				'date'   => $post->post_date,
				'slug'   => $post->post_name,
				'author' => (int) $post->post_author,
				'link'   => get_permalink( $post ),
			);
		}

		return array(
			'total' => (int) $query->found_posts,
			'pages' => (int) $query->max_num_pages,
			'items' => $items,
		);
	}

	/**
	 * get_post tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the post does not exist.
	 */
	public static function get_post( array $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			throw new Tool_Error( 'Post not found: ' . (int) $args['id'] );
		}

		$terms = array();
		foreach ( get_object_taxonomies( $post->post_type, 'names' ) as $taxonomy ) {
			$assigned = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'names' ) );
			if ( ! is_wp_error( $assigned ) && ! empty( $assigned ) ) {
				$terms[ $taxonomy ] = array_values( $assigned );
			}
		}

		$result = array(
			'id'       => (int) $post->ID,
			'type'     => $post->post_type,
			'status'   => $post->post_status,
			'title'    => $post->post_title,
			'slug'     => $post->post_name,
			'date'     => $post->post_date,
			'modified' => $post->post_modified,
			'author'   => (int) $post->post_author,
			'parent'   => (int) $post->post_parent,
			'excerpt'  => $post->post_excerpt,
			'content'  => $post->post_content,
			'link'     => get_permalink( $post ),
			'terms'    => $terms,
		);

		if ( ! empty( $args['include_meta'] ) ) {
			$meta  = get_post_meta( $post->ID );
			$clean = array();
			$count = 0;
			foreach ( (array) $meta as $key => $values ) {
				if ( $count >= 100 ) {
					$clean['_ai_connect_note'] = 'Meta truncated at 100 keys.';
					break;
				}
				$clean[ $key ] = ( is_array( $values ) && 1 === count( $values ) ) ? $values[0] : $values;
				$count++;
			}
			$result['meta'] = $clean;
		}

		return $result;
	}

	/**
	 * create_post tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When creation fails.
	 */
	public static function create_post( array $args ) {
		$post_type = (string) $args['post_type'];
		if ( ! post_type_exists( $post_type ) ) {
			throw new Tool_Error( 'Unknown post type: ' . $post_type );
		}

		$data = array(
			'post_title'   => (string) $args['title'],
			'post_content' => (string) $args['content'],
			'post_excerpt' => (string) $args['excerpt'],
			'post_status'  => (string) $args['status'],
			'post_type'    => $post_type,
			'post_author'  => ! empty( $args['author'] ) ? (int) $args['author'] : get_current_user_id(),
			'post_parent'  => (int) $args['parent'],
		);
		if ( ! empty( $args['slug'] ) ) {
			$data['post_name'] = (string) $args['slug'];
		}

		$post_id = wp_insert_post( wp_slash( $data ), true );
		if ( is_wp_error( $post_id ) ) {
			throw new Tool_Error( 'Create failed: ' . $post_id->get_error_message() );
		}

		$terms_applied = self::apply_terms( $post_id, $post_type, isset( $args['terms'] ) ? $args['terms'] : array() );
		$meta_applied  = self::apply_meta( $post_id, isset( $args['meta'] ) ? $args['meta'] : array() );

		return array(
			'id'            => (int) $post_id,
			'status'        => get_post_status( $post_id ),
			'link'          => get_permalink( $post_id ),
			'edit_link'     => admin_url( 'post.php?post=' . (int) $post_id . '&action=edit' ),
			'terms_applied' => $terms_applied,
			'meta_applied'  => $meta_applied,
		);
	}

	/**
	 * update_post tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the update fails.
	 */
	public static function update_post( array $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			throw new Tool_Error( 'Post not found: ' . (int) $args['id'] );
		}

		$data    = array( 'ID' => (int) $post->ID );
		$changes = array();

		$map = array(
			'title'   => 'post_title',
			'content' => 'post_content',
			'excerpt' => 'post_excerpt',
			'status'  => 'post_status',
			'slug'    => 'post_name',
			'author'  => 'post_author',
		);
		foreach ( $map as $arg_key => $field ) {
			if ( array_key_exists( $arg_key, $args ) && null !== $args[ $arg_key ] ) {
				$data[ $field ] = $args[ $arg_key ];
				$changes[]      = $arg_key;
			}
		}

		if ( count( $data ) > 1 ) {
			$result = wp_update_post( wp_slash( $data ), true );
			if ( is_wp_error( $result ) ) {
				throw new Tool_Error( 'Update failed: ' . $result->get_error_message() );
			}
		}

		$deleted_meta  = array();
		foreach ( (array) ( isset( $args['delete_meta'] ) ? $args['delete_meta'] : array() ) as $key ) {
			if ( delete_post_meta( $post->ID, (string) $key ) ) {
				$deleted_meta[] = (string) $key;
			}
		}
		$meta_applied  = self::apply_meta( $post->ID, isset( $args['meta'] ) ? $args['meta'] : array() );
		$terms_applied = self::apply_terms( $post->ID, $post->post_type, isset( $args['terms'] ) ? $args['terms'] : array() );

		return array(
			'id'            => (int) $post->ID,
			'changed'       => $changes,
			'meta_applied'  => $meta_applied,
			'meta_deleted'  => $deleted_meta,
			'terms_applied' => $terms_applied,
			'status'        => get_post_status( $post->ID ),
			'link'          => get_permalink( $post->ID ),
		);
	}

	/**
	 * delete_post tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the post cannot be deleted.
	 */
	public static function delete_post( array $args ) {
		$post = get_post( (int) $args['id'] );
		if ( ! $post ) {
			throw new Tool_Error( 'Post not found: ' . (int) $args['id'] );
		}

		if ( ! empty( $args['force'] ) ) {
			$result = wp_delete_post( $post->ID, true );
			if ( ! $result ) {
				throw new Tool_Error( 'Permanent deletion failed for post ' . $post->ID . '.' );
			}
			return array(
				'id'       => (int) $post->ID,
				'deleted'  => true,
				'permanent' => true,
			);
		}

		$trashed = wp_trash_post( $post->ID );
		if ( ! $trashed ) {
			throw new Tool_Error(
				'Trash is not available for this post type or status.',
				'Post type: ' . $post->post_type . '; status: ' . $post->post_status . '.',
				'Pass force=true to delete permanently.'
			);
		}
		return array(
			'id'      => (int) $post->ID,
			'deleted' => true,
			'trashed' => true,
		);
	}

	/**
	 * list_terms tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the taxonomy does not exist.
	 */
	public static function list_terms( array $args ) {
		$taxonomy = (string) $args['taxonomy'];
		if ( ! taxonomy_exists( $taxonomy ) ) {
			throw new Tool_Error( 'Unknown taxonomy: ' . $taxonomy, '', 'Core taxonomies: category, post_tag.' );
		}

		$per_page = (int) $args['per_page'];
		$page     = (int) $args['page'];

		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => false,
				'number'     => $per_page,
				'offset'     => ( $page - 1 ) * $per_page,
				'search'     => (string) $args['search'],
				'orderby'    => 'name',
				'order'      => 'ASC',
			)
		);
		if ( is_wp_error( $terms ) ) {
			throw new Tool_Error( 'Could not list terms: ' . $terms->get_error_message() );
		}

		$total = wp_count_terms( array( 'taxonomy' => $taxonomy, 'hide_empty' => false ) );
		$items = array();
		foreach ( $terms as $term ) {
			$items[] = array(
				'term_id'     => (int) $term->term_id,
				'name'        => $term->name,
				'slug'        => $term->slug,
				'count'       => (int) $term->count,
				'parent'      => (int) $term->parent,
				'description' => Util::truncate( (string) $term->description, 300 ),
			);
		}

		return array(
			'taxonomy' => $taxonomy,
			'total'    => is_wp_error( $total ) ? null : (int) $total,
			'items'    => $items,
		);
	}

	/**
	 * create_term tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When creation fails.
	 */
	public static function create_term( array $args ) {
		$taxonomy = (string) $args['taxonomy'];
		if ( ! taxonomy_exists( $taxonomy ) ) {
			throw new Tool_Error( 'Unknown taxonomy: ' . $taxonomy );
		}

		$term_args = array(
			'parent'      => (int) $args['parent'],
			'description' => (string) $args['description'],
		);
		if ( ! empty( $args['slug'] ) ) {
			$term_args['slug'] = (string) $args['slug'];
		}

		$result = wp_insert_term( (string) $args['name'], $taxonomy, $term_args );
		if ( is_wp_error( $result ) ) {
			if ( 'term_exists' === $result->get_error_code() ) {
				$existing = get_term( (int) $result->get_error_data(), $taxonomy );
				return array(
					'term_id'  => $existing ? (int) $existing->term_id : null,
					'name'     => $existing ? $existing->name : (string) $args['name'],
					'slug'     => $existing ? $existing->slug : '',
					'existing' => true,
					'note'     => 'A term with this name/slug already exists; returned it instead of creating a duplicate.',
				);
			}
			throw new Tool_Error( 'Could not create term: ' . $result->get_error_message() );
		}

		$term = get_term( (int) $result['term_id'], $taxonomy );

		return array(
			'term_id'  => (int) $result['term_id'],
			'name'     => $term ? $term->name : (string) $args['name'],
			'slug'     => $term ? $term->slug : '',
			'existing' => false,
		);
	}

	/**
	 * list_comments tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 */
	public static function list_comments( array $args ) {
		$per_page = (int) $args['per_page'];
		$page     = (int) $args['page'];

		$base = array(
			'status' => (string) $args['status'],
			'order'  => 'DESC',
		);
		if ( ! empty( $args['post_id'] ) ) {
			$base['post_id'] = (int) $args['post_id'];
		}

		$count_query = new \WP_Comment_Query( array_merge( $base, array( 'count' => true ) ) );
		$total       = (int) $count_query->get_comments();

		$query = new \WP_Comment_Query(
			array_merge(
				$base,
				array(
					'number' => $per_page,
					'offset' => ( $page - 1 ) * $per_page,
				)
			)
		);

		$items = array();
		foreach ( $query->get_comments() as $comment ) {
			$items[] = array(
				'id'      => (int) $comment->comment_ID,
				'post_id' => (int) $comment->comment_post_ID,
				'author'  => $comment->comment_author,
				'date'    => $comment->comment_date,
				'status'  => $comment->comment_approved,
				'parent'  => (int) $comment->comment_parent,
				'content' => Util::truncate( $comment->comment_content, 1000 ),
			);
		}

		return array(
			'total' => $total,
			'items' => $items,
		);
	}

	/**
	 * moderate_comment tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When moderation fails.
	 */
	public static function moderate_comment( array $args ) {
		$id = (int) $args['id'];
		if ( ! get_comment( $id ) ) {
			throw new Tool_Error( 'Comment not found: ' . $id );
		}

		$action = (string) $args['action'];
		$ok     = false;

		switch ( $action ) {
			case 'approve':
				$ok = wp_set_comment_status( $id, 'approve' );
				break;
			case 'hold':
				$ok = wp_set_comment_status( $id, 'hold' );
				break;
			case 'spam':
				$ok = wp_spam_comment( $id );
				break;
			case 'unspam':
				$ok = wp_unspam_comment( $id );
				break;
			case 'trash':
				$ok = wp_trash_comment( $id );
				break;
			case 'untrash':
				$ok = wp_untrash_comment( $id );
				break;
			case 'delete':
				$ok = wp_delete_comment( $id, true );
				break;
		}

		if ( ! $ok ) {
			throw new Tool_Error( 'Moderation action "' . $action . '" failed for comment ' . $id . '.' );
		}

		$comment = get_comment( $id );
		return array(
			'id'          => $id,
			'action'      => $action,
			'gone'        => null === $comment,
			'new_status'  => $comment ? $comment->comment_approved : null,
		);
	}

	/**
	 * list_media tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 */
	public static function list_media( array $args ) {
		$query_args = array(
			'post_type'      => 'attachment',
			'post_status'    => 'inherit',
			'posts_per_page' => (int) $args['per_page'],
			'paged'          => (int) $args['page'],
			's'              => (string) $args['search'],
			'orderby'        => 'date',
			'order'          => 'DESC',
		);
		if ( ! empty( $args['mime_type'] ) ) {
			$query_args['post_mime_type'] = (string) $args['mime_type'];
		}

		$query = new \WP_Query( $query_args );
		$items = array();
		foreach ( $query->posts as $post ) {
			$file = get_attached_file( $post->ID );
			$meta = wp_get_attachment_metadata( $post->ID );
			$items[] = array(
				'id'        => (int) $post->ID,
				'title'     => $post->post_title,
				'filename'  => $file ? basename( $file ) : '',
				'mime'      => $post->post_mime_type,
				'url'       => wp_get_attachment_url( $post->ID ),
				'bytes'     => $file && file_exists( $file ) ? filesize( $file ) : null,
				'width'     => is_array( $meta ) && isset( $meta['width'] ) ? (int) $meta['width'] : null,
				'height'    => is_array( $meta ) && isset( $meta['height'] ) ? (int) $meta['height'] : null,
				'alt'       => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
				'date'      => $post->post_date,
			);
		}

		return array(
			'total' => (int) $query->found_posts,
			'items' => $items,
		);
	}

	/**
	 * upload_media tool.
	 *
	 * @param array $args Validated args.
	 * @return array
	 * @throws Tool_Error When the upload fails.
	 */
	public static function upload_media( array $args ) {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		require_once ABSPATH . 'wp-admin/includes/image.php';
		require_once ABSPATH . 'wp-admin/includes/media.php';

		$max_bytes = 10 * 1024 * 1024;
		$remote    = isset( $args['url'] ) ? trim( (string) $args['url'] ) : '';
		$base64    = isset( $args['content_base64'] ) ? trim( (string) $args['content_base64'] ) : '';
		$filename  = isset( $args['filename'] ) ? sanitize_file_name( (string) $args['filename'] ) : '';
		$title     = isset( $args['title'] ) ? (string) $args['title'] : '';

		if ( '' === $remote && '' === $base64 ) {
			throw new Tool_Error( 'Provide "url" (remote file) or "content_base64" + "filename".' );
		}

		if ( '' !== $remote ) {
			$tmp = download_url( $remote, 60 );
			if ( is_wp_error( $tmp ) ) {
				throw new Tool_Error( 'Could not download the file: ' . $tmp->get_error_message() );
			}
			if ( filesize( $tmp ) > $max_bytes ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				throw new Tool_Error( 'Remote file exceeds the 10 MB limit.' );
			}
			if ( '' === $filename ) {
				$from_url = basename( (string) wp_parse_url( $remote, PHP_URL_PATH ) );
				$filename = sanitize_file_name( '' !== $from_url ? $from_url : 'upload' );
			}
			$file_array = array(
				'name'     => $filename,
				'tmp_name' => $tmp,
			);
			$attachment_id = media_handle_sideload( $file_array, 0, '' !== $title ? $title : null );
			if ( is_wp_error( $attachment_id ) ) {
				@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				throw new Tool_Error( 'Could not add to the media library: ' . $attachment_id->get_error_message() );
			}
		} else {
			if ( '' === $filename ) {
				throw new Tool_Error( '"filename" is required when uploading base64 content.' );
			}
			$decoded = base64_decode( (string) preg_replace( '#^data:[^;]+;base64,#', '', $base64 ), true );
			if ( false === $decoded ) {
				throw new Tool_Error( 'The base64 content could not be decoded.' );
			}
			if ( strlen( $decoded ) > $max_bytes ) {
				throw new Tool_Error( 'Decoded content exceeds the 10 MB limit (' . Util::bytes( strlen( $decoded ) ) . ').' );
			}

			$upload = wp_upload_bits( $filename, null, $decoded );
			if ( ! empty( $upload['error'] ) ) {
				throw new Tool_Error( 'Upload failed: ' . $upload['error'] );
			}

			$filetype      = wp_check_filetype( $upload['file'] );
			$attachment_id = wp_insert_attachment(
				array(
					'post_mime_type' => $filetype['type'] ? $filetype['type'] : 'application/octet-stream',
					'post_title'     => '' !== $title ? $title : pathinfo( $upload['file'], PATHINFO_FILENAME ),
					'post_status'    => 'inherit',
				),
				$upload['file'],
				0,
				true
			);
			if ( is_wp_error( $attachment_id ) ) {
				throw new Tool_Error( 'Could not create the attachment: ' . $attachment_id->get_error_message() );
			}
			$metadata = wp_generate_attachment_metadata( $attachment_id, $upload['file'] );
			if ( is_array( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}
		}

		$file = get_attached_file( $attachment_id );

		return array(
			'id'       => (int) $attachment_id,
			'title'    => get_the_title( $attachment_id ),
			'url'      => wp_get_attachment_url( $attachment_id ),
			'mime'     => get_post_mime_type( $attachment_id ),
			'bytes'    => $file && file_exists( $file ) ? filesize( $file ) : null,
			'filename' => $file ? basename( $file ) : '',
		);
	}

	/**
	 * Assign taxonomy terms to a post.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type.
	 * @param array  $terms     Taxonomy => names/IDs.
	 * @return array Applied summary.
	 */
	private static function apply_terms( $post_id, $post_type, $terms ) {
		$applied = array();
		if ( ! is_array( $terms ) ) {
			return $applied;
		}
		foreach ( $terms as $taxonomy => $values ) {
			$taxonomy = (string) $taxonomy;
			if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post_type, $taxonomy ) ) {
				$applied[ $taxonomy ] = 'skipped: not a taxonomy of ' . $post_type;
				continue;
			}
			$values = is_array( $values ) ? $values : array( $values );
			$result = wp_set_object_terms( $post_id, $values, $taxonomy, false );
			$applied[ $taxonomy ] = is_wp_error( $result ) ? 'error: ' . $result->get_error_message() : count( $result ) . ' term(s) set';
		}
		return $applied;
	}

	/**
	 * Set post meta pairs.
	 *
	 * @param int   $post_id Post ID.
	 * @param array $meta    Key => value.
	 * @return array Applied keys.
	 */
	private static function apply_meta( $post_id, $meta ) {
		$applied = array();
		if ( ! is_array( $meta ) ) {
			return $applied;
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post_id, (string) $key, $value );
			$applied[] = (string) $key;
		}
		return $applied;
	}
}
