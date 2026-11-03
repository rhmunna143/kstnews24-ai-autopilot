<?php
/**
 * Internal Related News Linking Engine.
 *
 * @package KSTNews_AI_Autopilot
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class KSTNews_AI_Internal_Linker {

	/**
	 * Retrieve related recent posts in the same category.
	 *
	 * @param int $category_id
	 * @param int $exclude_post_id
	 * @param int $limit
	 * @return array
	 */
	public static function get_related_posts( int $category_id, int $exclude_post_id = 0, int $limit = 3 ): array {
		if ( $category_id <= 0 ) {
			return [];
		}

		$query_args = [
			'post_type'      => 'post',
			'post_status'    => [ 'publish', 'draft' ],
			'posts_per_page' => $limit,
			'cat'            => $category_id,
			'post__not_in'   => $exclude_post_id > 0 ? [ $exclude_post_id ] : [],
			'orderby'        => 'date',
			'order'          => 'DESC',
		];

		$posts = get_posts( $query_args );

		$results = [];
		if ( is_array( $posts ) ) {
			foreach ( $posts as $p ) {
				$pid = is_object( $p ) ? $p->ID : ( (int) $p );
				$results[] = [
					'id'    => $pid,
					'title' => get_the_title( $pid ),
					'url'   => get_permalink( $pid ),
				];
			}
		}

		return $results;
	}

	/**
	 * Generate Bengali "আরও পড়ুন" related news block.
	 *
	 * @param int $category_id
	 * @param int $exclude_post_id
	 * @param int $limit
	 * @return string HTML block or empty string.
	 */
	public static function generate_block( int $category_id, int $exclude_post_id = 0, int $limit = 3 ): string {
		$related = self::get_related_posts( $category_id, $exclude_post_id, $limit );

		if ( count( $related ) < 1 ) {
			return '';
		}

		$html  = "\n<!-- kstnews-related-articles -->\n";
		$html .= "<div class=\"kstnews-related-news\" style=\"margin-top: 25px; padding: 15px; border-left: 4px solid #2563eb; background: #f8fafc;\">\n";
		$html .= "  <h3 style=\"margin-top: 0; font-size: 18px; color: #1e293b;\">আরও পড়ুন</h3>\n";
		$html .= "  <ul style=\"margin: 0; padding-left: 20px; line-height: 1.8;\">\n";

		foreach ( $related as $item ) {
			$title = esc_html( $item['title'] );
			$url   = esc_url( $item['url'] );
			$html .= "    <li><a href=\"{$url}\" style=\"color: #2563eb; text-decoration: none;\">{$title}</a></li>\n";
		}

		$html .= "  </ul>\n";
		$html .= "</div>\n";

		return $html;
	}
}
